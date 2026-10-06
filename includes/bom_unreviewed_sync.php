<?php
declare(strict_types=1);
require_once __DIR__.'/bom_workflow.php';

// Explicit, additive repair. Existing publications, approvals and quotes are immutable here.
function bus_candidates(PDO $pdo,bool $lock=false): array {
    $sql="SELECT b.id,b.project_uid,b.name,b.customer,b.model,b.version_no,b.variant_label,b.linked_system,b.linked_id,b.currency,b.exchange_rate,b.labor,b.other,b.profit_rate,b.quote_mode,b.review_status,b.updated_at,b.workflow_version,OCTET_LENGTH(b.rows_json) AS row_bytes,IF(OCTET_LENGTH(b.rows_json)<=2097152,b.rows_json,NULL) AS rows_json FROM bom_projects b LEFT JOIN bom_cost_publications c ON c.project_uid=b.project_uid WHERE b.is_active=1 AND b.review_status IN ('draft','pending') AND c.project_uid IS NULL ORDER BY b.id LIMIT 501".($lock?' FOR UPDATE':'');
    $rows=$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    if(count($rows)>500)throw new RuntimeException('本次超过500份，请分批核对后同步');
    return $rows;
}
function bus_plan_rows(array $rows): array {
    $entries=[];$skipped=[];
    foreach($rows as $p){
        $uid=(string)$p['project_uid'];$reason='';$cost=0.0;
        try{
            if((int)$p['row_bytes']>2097152)throw new RuntimeException('物料明细超过安全读取上限');
            if(strtoupper(trim((string)$p['currency']))!=='RMB')throw new RuntimeException('非人民币成本需单独核对换算');
            $keys=bcp_models($p['model']);if($p['linked_id']&&(stripos($p['linked_system'],'NAMING')!==false||strpos($p['linked_system'],'命名')!==false))$keys[]='NID'.$p['linked_id'];
            if(!$keys)throw new RuntimeException('缺少可匹配报价产品的型号或命名绑定');
            $raw=json_decode($p['rows_json']??'[]',true,512,JSON_THROW_ON_ERROR);
            // Validate aliases before normalization can turn an invalid value into zero.
            $details=bw_rows($raw);if(!$details)throw new RuntimeException('缺少物料明细');
            foreach($raw as $row)foreach(['qty'=>['qty','quantity','num','数量'],'price'=>['price','unit_price','unitCost','unit_cost','单价'],'process'=>['process','processCost','process_cost','加工费'],'finishCost'=>['finishCost','finish_cost','surfaceCost','surface_cost','表面处理费','处理费1'],'finishCost2'=>['finishCost2','finish_cost2','surfaceCost2','surface_cost2','处理费2']] as $key=>$aliases)bw_valid_number(bw_pick($row,$aliases),$key);
            bw_valid_number($p['labor'],'人工费');bw_valid_number($p['other'],'其他费');
            $cost=round(bw_totals($p)['total'],4);bw_valid_number($cost,'成本',100000000000000.0);
            if($cost<=0)throw new RuntimeException('零成本须审核确认，不作为未审核成本发布');
        }catch(Throwable $e){$reason=$e->getMessage();}
        $revision=hash('sha256',bw_json($p));
        if($reason!==''){$skipped[]=['project_uid'=>$uid,'model'=>$p['model'],'reason'=>$reason,'revision'=>$revision];continue;}
        $entries[]=['project_uid'=>$uid,'model'=>$p['model'],'name'=>$p['name'],'review_status'=>$p['review_status'],'cost'=>$cost,'revision'=>$revision];
    }
    return ['entries'=>$entries,'skipped'=>$skipped,'hash'=>hash('sha256',bw_json([$entries,$skipped]))];
}
// Must run inside the caller's transaction, after the shared publication lock.
function bus_freeze_saved(PDO $pdo,array $p): array {
    $st=$pdo->prepare('SELECT source FROM bom_cost_publications WHERE project_uid=? FOR UPDATE');$st->execute([$p['project_uid']]);
    if($st->fetchColumn()!==false)return ['inserted'=>false,'reason'=>'existing_publication'];
    $p['row_bytes']=strlen($p['rows_json']??'[]');$plan=bus_plan_rows([$p]);
    if(!$plan['entries'])return ['inserted'=>false,'reason'=>$plan['skipped'][0]['reason']];
    $entry=$plan['entries'][0];$payload=bw_publication_payload($p);$payload['initial_freeze']=true;$payload['unreviewed_sync']=true;$payload['frozen_revision']=$entry['revision'];
    $pdo->prepare("INSERT INTO bom_cost_publications(project_uid,snapshot_id,source,payload_json,cost) VALUES(?,NULL,'legacy_unreviewed',?,?)")->execute([$p['project_uid'],bw_json($payload),$entry['cost']]);
    return ['inserted'=>true,'cost'=>$entry['cost']];
}
// Update only policies touched by the newly frozen models, using the same winner as quoting.
// Does not change user multipliers, quote documents or order amounts.
function bus_sync_policies(PDO $pdo,array $projects,string $actor): int {
    $st=$pdo->query("SHOW TABLES LIKE 'quote_price_policies'");if(!$st->fetchColumn())return 0;
    $keys=[];foreach($projects as $p){$keys=array_merge($keys,bcp_models($p['model']??''));if(!empty($p['linked_id'])&&(stripos($p['linked_system']??'','NAMING')!==false||strpos($p['linked_system']??'','命名')!==false))$keys[]='NID'.$p['linked_id'];}
    $map=bcp_map($pdo,true);$levels=[];$default=1.35;$hasDefault=false;
    if($pdo->query("SHOW TABLES LIKE 'quote_price_policy_levels'")->fetchColumn())foreach($pdo->query('SELECT id,base_multiplier,is_default,is_active FROM quote_price_policy_levels ORDER BY sort_order,id')->fetchAll(PDO::FETCH_ASSOC) as $r){
        $levels[(int)$r['id']]=(float)$r['base_multiplier'];if(!$hasDefault&&!empty($r['is_default'])&&!empty($r['is_active'])&&(float)$r['base_multiplier']>0){$default=(float)$r['base_multiplier'];$hasDefault=true;}
    }
    $updated=0;$st=$pdo->query('SELECT id,product_source,naming_id,product_model,level_id,bom_cost_rmb,estimated_sale_price_rmb,bom_cost_source,bom_match_key,bom_cost_updated_at FROM quote_price_policies ORDER BY id FOR UPDATE');
    $update=$pdo->prepare('UPDATE quote_price_policies SET bom_cost_rmb=?,estimated_sale_price_rmb=?,bom_cost_source=?,bom_match_key=?,bom_cost_updated_at=?,updated_by=?,updated_at=NOW() WHERE id=?');
    while($p=$st->fetch(PDO::FETCH_ASSOC)){
        $pk=bcp_product_keys(['source'=>$p['product_source']?:'naming','naming_id'=>$p['naming_id'],'model'=>$p['product_model']]);if(!array_intersect($pk,$keys))continue;
        [$key,$hit]=bcp_find($pk,$map);if(!$hit){if(!bl_ready($pdo))continue;$key='';$hit=['cost_rmb'=>0,'source_table'=>'BOM未发布，请选择有效版本','updated_at'=>''];}
        $cost=(float)$hit['cost_rmb'];$multiplier=$levels[(int)$p['level_id']]??0;if($multiplier<=0)$multiplier=$default;$sale=round($cost*$multiplier,4);
        if(abs((float)$p['bom_cost_rmb']-$cost)<0.0001&&abs((float)$p['estimated_sale_price_rmb']-$sale)<0.0001&&$p['bom_cost_source']===$hit['source_table']&&$p['bom_match_key']===$key&&$p['bom_cost_updated_at']===$hit['updated_at'])continue;
        $update->execute([$cost,$sale,$hit['source_table'],$key,$hit['updated_at'],$actor,$p['id']]);$updated++;
    }
    return $updated;
}
function bus_plan(PDO $pdo): array {
    if(!bw_ready($pdo))throw new RuntimeException('BOM成本版本尚未初始化');
    return bus_plan_rows(bus_candidates($pdo));
}
function bus_apply(PDO $pdo,string $expected,string $actor): array {
    if(!preg_match('/^[a-f0-9]{64}$/D',$expected)||trim($actor)==='')throw new RuntimeException('缺少扫描校验值或操作者');
    if(!bw_ready($pdo))throw new RuntimeException('BOM成本版本尚未初始化');
    $pdo->beginTransaction();
    try{
        // Same lock as approval/publication; cost precedence cannot race with an approval.
        $pdo->query("SELECT value FROM bom_workflow_meta WHERE name='legacy_costs_frozen' FOR UPDATE")->fetchColumn();
        $rows=bus_candidates($pdo,true);$plan=bus_plan_rows($rows);
        if(!hash_equals($expected,$plan['hash']))throw new RuntimeException('BOM或发布状态已变化，请重新扫描；未写入');
        $allowed=array_column($plan['entries'],null,'project_uid');$inserted=0;
        foreach($rows as $p){
            if(!isset($allowed[$p['project_uid']]))continue;
            $payload=bw_publication_payload($p);$payload['initial_freeze']=true;$payload['unreviewed_sync']=true;$payload['frozen_revision']=$allowed[$p['project_uid']]['revision'];
            $st=$pdo->prepare("INSERT INTO bom_cost_publications(project_uid,snapshot_id,source,payload_json,cost) VALUES(?,NULL,'legacy_unreviewed',?,?)");
            $st->execute([$p['project_uid'],bw_json($payload),$allowed[$p['project_uid']]['cost']]);
            $pdo->prepare("INSERT INTO bom_workflow_events(project_uid,action,actor,before_revision,after_revision,note,changes_json,snapshot_id) VALUES(?,'sync_unreviewed_cost',?,?,?, ?,?,NULL)")
                ->execute([$p['project_uid'],$actor,$allowed[$p['project_uid']]['revision'],$allowed[$p['project_uid']]['revision'],'补齐缺失的未审核冻结成本；未审核、未改历史报价',bw_json(['cost'=>$allowed[$p['project_uid']]['cost'],'plan_hash'=>$expected])]);
            $inserted++;
        }
        $projects=array_values(array_filter($rows,static fn($p)=>isset($allowed[$p['project_uid']])));
        $policies=bus_sync_policies($pdo,$projects,$actor);
        $pdo->commit();return ['inserted'=>$inserted,'policies_updated'=>$policies,'skipped'=>$plan['skipped'],'hash'=>$expected];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
