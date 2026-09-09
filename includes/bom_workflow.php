<?php
declare(strict_types=1);

// No bootstrap, database connection, migration or external IO on include.
class BomWorkflowError extends RuntimeException {
    public string $reason;
    public function __construct(string $reason,string $message){parent::__construct($message);$this->reason=$reason;}
}
function bw_json($value): string {return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
function bw_number($value): float {
    if(is_string($value))$value=str_replace(array(',','￥','¥','RMB','rmb','USD','usd','元'),'',$value);
    return is_numeric($value)&&is_finite((float)$value)?(float)$value:0.0;
}
function bw_valid_number($value,string $label,float $limit=10000000000.0): float {
    if(is_string($value))$value=trim(str_replace(array(',','￥','¥','RMB','rmb','USD','usd','元'),'',$value));
    if(!is_numeric($value)||!is_finite((float)$value)||(float)$value<0||(float)$value>=$limit)throw new BomWorkflowError('validation',$label.'必须是有效非负数字，且不能超过存储范围');
    return (float)$value;
}
function bw_pick(array $row,array $keys,$fallback=0){foreach($keys as $key)if(isset($row[$key])&&$row[$key]!=='')return $row[$key];return $fallback;}
function bw_rows($rows): array {
    if(is_string($rows))$rows=json_decode($rows,true,512,JSON_THROW_ON_ERROR);
    if(!is_array($rows))throw new BomWorkflowError('validation','物料明细格式错误，请重新读取');
    $out=array();
    foreach($rows as $row){
        if(!is_array($row))throw new BomWorkflowError('validation','物料行格式错误');
        $r=$row;
        foreach(array('qty'=>array('qty','quantity','num','数量'),'price'=>array('price','unit_price','unitCost','unit_cost','单价'),'process'=>array('process','processCost','process_cost','加工费'),'finishCost'=>array('finishCost','finish_cost','surfaceCost','surface_cost','表面处理费','处理费1'),'finishCost2'=>array('finishCost2','finish_cost2','surfaceCost2','surface_cost2','处理费2')) as $key=>$aliases)$r[$key]=bw_number(bw_pick($row,$aliases));
        foreach(array('category'=>array('category','type'),'name'=>array('name','material_name','materialName'),'spec'=>array('spec','remark','note'),'materialId'=>array('materialId','material_id'),'priceSource'=>array('priceSource','price_source'),'priceNote'=>array('priceNote','price_note')) as $key=>$aliases)$r[$key]=(string)bw_pick($row,$aliases,'');
        $r['priceStatus']=(string)bw_pick($row,array('priceStatus','price_status'),'estimated');
        if(!in_array($r['priceStatus'],array('estimated','confirmed','pending','historical'),true))$r['priceStatus']='estimated';
        $out[]=$r;
    }
    return $out;
}
function bw_totals(array $p): array {
    $mat=0.0;foreach(bw_rows($p['rows']??$p['rows_json']??array()) as $r)$mat+=$r['qty']*($r['price']+$r['process']+$r['finishCost']+$r['finishCost2']);
    $labor=bw_number($p['labor']??0);$other=bw_number($p['other']??0);$total=$mat+$labor+$other;$rate=bw_number($p['profit_rate']??30);
    $suggest=($p['quote_mode']??'markup')==='margin'?($rate>=100?0:$total/(1-$rate/100)):$total*(1+$rate/100);
    return array('material'=>$mat,'labor'=>$labor,'other'=>$other,'total'=>$total,'suggest'=>$suggest,'profit'=>$suggest-$total);
}
function bw_revision(?array $p): string {
    if(!$p)return '';
    // Hash persisted content, including legacy bindings/status. Never hash display enrichment.
    $keys=array('project_uid','name','customer','model','product_type','version_no','variant_label','currency','product_image','labor','other','profit_rate','quote_mode','exchange_rate','note','rows_json','review_status','review_note','submitted_by','submitted_at','approved_by','approved_at','updated_at','is_active','linked_system','linked_id','linked_title','linked_json');
    $keys[]='workflow_version';$values=array();foreach($keys as $key)$values[$key]=(string)($p[$key]??'');
    return hash('sha256',bw_json($values));
}
function bw_schema(PDO $pdo): void {
    $columns=$pdo->query('SHOW COLUMNS FROM bom_projects')->fetchAll(PDO::FETCH_COLUMN);
    if(!in_array('workflow_version',$columns,true))$pdo->exec('ALTER TABLE bom_projects ADD COLUMN workflow_version BIGINT NOT NULL DEFAULT 0');
    $pdo->exec("CREATE TABLE IF NOT EXISTS bom_workflow_requests(request_id VARCHAR(64) PRIMARY KEY,actor VARCHAR(160) NOT NULL,payload_hash CHAR(64) NOT NULL,result_json LONGTEXT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS bom_workflow_events(id BIGINT AUTO_INCREMENT PRIMARY KEY,project_uid VARCHAR(100) NOT NULL,action VARCHAR(40) NOT NULL,actor VARCHAR(160) NOT NULL,before_revision CHAR(64) NOT NULL,after_revision CHAR(64) NOT NULL,note TEXT NULL,changes_json LONGTEXT NULL,snapshot_id BIGINT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY idx_project(project_uid,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS bom_cost_publications(project_uid VARCHAR(100) PRIMARY KEY,snapshot_id BIGINT NULL,source VARCHAR(40) NOT NULL,payload_json LONGTEXT NOT NULL,cost DECIMAL(18,4) NOT NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS bom_workflow_meta(name VARCHAR(80) PRIMARY KEY,value VARCHAR(160) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function bw_ready(PDO $pdo): bool {
    try{return (bool)$pdo->query("SELECT value FROM bom_workflow_meta WHERE name='legacy_costs_frozen'")->fetchColumn();}catch(Throwable $e){return false;}
}
function bw_publication_payload(array $p): array {
    $out=array();foreach(array('project_uid','model','name','customer','version_no','variant_label','linked_system','linked_id','currency','exchange_rate') as $k)$out[$k]=$p[$k]??'';
    return $out;
}
function bw_publish(PDO $pdo,array $p,?int $snapshotId,string $source,bool $initialFreeze=false): void {
    $payload=bw_publication_payload($p);$payload['initial_freeze']=$initialFreeze;
    $pdo->prepare("INSERT INTO bom_cost_publications(project_uid,snapshot_id,source,payload_json,cost) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE snapshot_id=VALUES(snapshot_id),source=VALUES(source),payload_json=VALUES(payload_json),cost=VALUES(cost),updated_at=NOW()")
        ->execute(array($p['project_uid'],$snapshotId,$source,bw_json($payload),bw_totals($p)['total']));
}
function bw_initial_snapshot_matches(array $p,array $s): bool {
    if(($p['review_status']??'')!=='approved'||(int)($p['latest_snapshot_id']??0)!==(int)($s['id']??0))return false;
    foreach(array('model','customer','currency','version_no','variant_label','labor','other','profit_rate','quote_mode','exchange_rate') as $key)if((string)($p[$key]??'')!==(string)($s[$key]??''))return false;
    return bw_rows($p['rows_json']??'[]')===bw_rows($s['rows_json']??'[]');
}
function bw_freeze_legacy(PDO $pdo): array {
    // Explicit deployment step; never invoked by ordinary page reads/saves.
    bw_schema($pdo);$pdo->beginTransaction();
    try{
        $pdo->exec("INSERT IGNORE INTO bom_workflow_meta(name,value) VALUES('legacy_costs_frozen','')");
        $done=$pdo->query("SELECT value FROM bom_workflow_meta WHERE name='legacy_costs_frozen' FOR UPDATE")->fetchColumn();
        if($done){$pdo->commit();return array('already_initialized'=>true);}
        $last=0;$count=0;
        do{
            $st=$pdo->prepare("SELECT id,project_uid,name,model,customer,version_no,variant_label,linked_system,linked_id,currency,exchange_rate,rows_json,labor,other,profit_rate,quote_mode,review_status,latest_snapshot_id FROM bom_projects WHERE is_active=1 AND id>? ORDER BY id LIMIT 25 FOR UPDATE");$st->execute(array($last));$batch=$st->fetchAll(PDO::FETCH_ASSOC);
            foreach($batch as $p){
                $last=(int)$p['id'];
                $ss=$pdo->prepare("SELECT id,model,customer,currency,version_no,variant_label,labor,other,profit_rate,quote_mode,exchange_rate,rows_json FROM bom_snapshots WHERE project_uid=? ORDER BY id DESC LIMIT 1");$ss->execute(array($p['project_uid']));$s=$ss->fetch(PDO::FETCH_ASSOC);
                // Freeze what quoting currently uses, not a different old snapshot. Initialization
                // must not alter model precedence; a subsequent approval explicitly switches it.
                $approved=$s&&bw_initial_snapshot_matches($p,$s);
                bw_publish($pdo,$p,$approved?(int)$s['id']:null,$approved?'approved_snapshot':'legacy_unreviewed',true);
                $count++;
            }
        }while(count($batch)===25);
        $pdo->exec("UPDATE bom_workflow_meta SET value='frozen' WHERE name='legacy_costs_frozen'");$pdo->commit();return array('frozen'=>$count);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function bw_validate(array $d,bool $submission=false): array {
    if(!array_key_exists('rows',$d)&&!array_key_exists('rows_json',$d))throw new BomWorkflowError('validation','缺少完整物料明细，禁止保存空摘要');
    $input=$d['rows']??$d['rows_json'];
    if(is_string($input))$input=json_decode($input,true,512,JSON_THROW_ON_ERROR);
    if(!is_array($input)||array_keys($input)!==array_keys(array_values($input)))throw new BomWorkflowError('validation','物料明细必须是行列表');
    $rows=bw_rows($input);
    if($submission&&!count($rows))throw new BomWorkflowError('validation','BOM 至少需要一条物料明细才能提交审核');
    if(count($rows)>3000)throw new BomWorkflowError('validation','单份 BOM 最多支持 3000 行明细');
    foreach($rows as $i=>$r){
        foreach(array('qty'=>array('qty','quantity','num','数量'),'price'=>array('price','unit_price','unitCost','unit_cost','单价'),'process'=>array('process','processCost','process_cost','加工费'),'finishCost'=>array('finishCost','finish_cost','surfaceCost','surface_cost','表面处理费','处理费1'),'finishCost2'=>array('finishCost2','finish_cost2','surfaceCost2','surface_cost2','处理费2')) as $k=>$aliases)bw_valid_number(bw_pick($input[$i],$aliases), '第'.($i+1).'行'.$k);
        foreach(array('qty','price','process','finishCost','finishCost2') as $k)if($r[$k]<0)throw new BomWorkflowError('validation','第'.($i+1).'行数量/费用不能为负数');
        if($submission&&($r['qty']<=0||trim($r['name'])===''||$r['priceStatus']==='pending'))throw new BomWorkflowError('validation','第'.($i+1).'行需填写名称、正数数量并处理待确认价格');
    }
    if(trim((string)($d['name']??''))==='')throw new BomWorkflowError('validation','请填写 BOM 名称');
    foreach(array('name'=>255,'customer'=>160,'model'=>160,'product_type'=>160,'version_no'=>50,'variant_label'=>160,'currency'=>20) as $key=>$limit)if(!preg_match('/^.{0,'.$limit.'}$/us',(string)($d[$key]??'')))throw new BomWorkflowError('validation','名称、型号或版本信息过长，请缩短后保存');
    foreach(array('labor','other','profit_rate','exchange_rate') as $k){if(!isset($d[$k])||!is_numeric($d[$k])||!is_finite((float)$d[$k])||(float)$d[$k]<0)throw new BomWorkflowError('validation','费用、利润率和汇率必须为有效非负数字');}
    foreach(array('labor'=>10000000000.0,'other'=>10000000000.0,'profit_rate'=>1000000.0,'exchange_rate'=>100000000.0) as $key=>$limit)bw_valid_number($d[$key],$key,$limit);
    bw_valid_number(bw_totals(array_merge($d,array('rows'=>$rows)))['total'],'总成本',100000000000000.0);
    if((float)$d['exchange_rate']<=0)throw new BomWorkflowError('validation','汇率必须大于零');
    if(!in_array($d['quote_mode']??'',array('margin','markup'),true)||(($d['quote_mode']??'')==='margin'&&(float)$d['profit_rate']>=100))throw new BomWorkflowError('validation','毛利率必须小于 100%，并选择有效计价方式');
    if(trim((string)($d['currency']??''))==='')throw new BomWorkflowError('validation','请选择币种');
    if(strlen((string)($d['product_image']??''))>500)throw new BomWorkflowError('validation','图片请使用已上传的短地址，不要将整张图片文本写入 BOM');
    return $rows;
}
function bw_get(PDO $pdo,string $uid,bool $lock=false): ?array {
    $st=$pdo->prepare('SELECT * FROM bom_projects WHERE project_uid=? LIMIT 1'.($lock?' FOR UPDATE':''));$st->execute(array($uid));return $st->fetch(PDO::FETCH_ASSOC)?:null;
}
function bw_changes(?array $before,array $after): array {
    $out=array();foreach(array('name','customer','model','version_no','variant_label','currency','labor','other','profit_rate','quote_mode','exchange_rate','note','review_status') as $key)if((string)($before[$key]??'')!==(string)($after[$key]??''))$out[$key]=array('before'=>$before[$key]??null,'after'=>$after[$key]??null);
    if(($before['rows_json']??'')!==($after['rows_json']??''))$out['rows']=array('before_count'=>count(bw_rows($before['rows_json']??'[]')),'after_count'=>count(bw_rows($after['rows_json']??'[]')),'before_total'=>$before?bw_totals($before)['total']:0,'after_total'=>bw_totals($after)['total']);
    return $out;
}
function bw_execute(PDO $pdo,string $action,array $d,string $actor,bool $canCost,array $user=array()): array {
    if(!bw_ready($pdo))throw new BomWorkflowError('not_initialized','BOM 版本保护正在初始化，暂不可写入，请联系管理员');
    $uid=trim((string)($d['project_uid']??''));$request=(string)($d['request_id']??'');
    if($uid===''||strlen($uid)>100||!preg_match('/^[a-zA-Z0-9-]{16,64}$/',$request))throw new BomWorkflowError('validation','缺少单据或请求标识，请刷新页面');
    $owner=$user?hash('sha256',bw_json(array($user['_user_table']??'', $user['_user_id']??$user['id']??'', $user['username']??''))):$actor;
    $hash=hash('sha256',bw_json(array($action,$d)));$pdo->beginTransaction();
    try{
        // Serialize duplicate request before locking project, including new-document requests.
        $pdo->prepare('INSERT IGNORE INTO bom_workflow_requests(request_id,actor,payload_hash) VALUES(?,?,?)')->execute(array($request,$owner,$hash));
        $st=$pdo->prepare('SELECT * FROM bom_workflow_requests WHERE request_id=? FOR UPDATE');$st->execute(array($request));$req=$st->fetch(PDO::FETCH_ASSOC);
        if($req['actor']!==$owner||$req['payload_hash']!==$hash)throw new BomWorkflowError('request_conflict','重复请求内容不一致，请重新读取');
        if($req['result_json']){$result=json_decode($req['result_json'],true,512,JSON_THROW_ON_ERROR);$pdo->commit();return $result;}
        $p=bw_get($pdo,$uid,true);$before=$p;$status=$p['review_status']??'draft';
        if($p&&(int)$p['is_active']!==1)throw new BomWorkflowError('deleted','BOM 已删除，请返回总览');
        if(!array_key_exists('expected_revision',$d)||!hash_equals(bw_revision($p),(string)$d['expected_revision']))throw new BomWorkflowError('revision_conflict','这份 BOM 已被修改或审核。本次未覆盖任何内容，请保留当前输入，重新打开最新版本核对后再操作。');
        if(!$p&&$action!=='save_project')throw new BomWorkflowError('not_found','BOM 不存在，请先保存');
        $note=trim((string)($d['review_note']??''));$snapshot=null;$message='操作成功';
        if($action==='save_project'){
            if(!$canCost)throw new BomWorkflowError('permission','当前账号不能查看成本，禁止覆盖成本单；请联系有成本权限的人员保存');
            if(in_array($status,array('pending','approved'),true))throw new BomWorkflowError('locked','待审核/已审核 BOM 已锁定，请先驳回或退审');
            $rows=bw_validate($d);$values=array();
            foreach(array('name','customer','model','product_type','version_no','variant_label','currency','product_image','labor','other','profit_rate','quote_mode','exchange_rate','note') as $key)$values[$key]=$d[$key]??'';
            $values['version_no']=trim((string)$values['version_no'])?:'V1';$values['variant_label']=trim((string)$values['variant_label'])?:'通用版';
            $values['rows_json']=bw_json($rows);$values['row_count']=count($rows);$values['totals_json']=bw_json(bw_totals($values));
            $values['price_summary_json']=bw_json(bom_price_summary_snapshot(array('rows'=>$rows)));$values['updated_by']=$actor;
            if($p){$sql='UPDATE bom_projects SET '.implode(',',array_map(static fn($k)=>"`$k`=?",array_keys($values))).",summary_updated_at=NOW(),updated_at=NOW() WHERE project_uid=?";$args=array_values($values);$args[]=$uid;}
            else{$values['project_uid']=$uid;$values['created_by']=$actor;$values['review_status']='draft';$sql='INSERT INTO bom_projects (`'.implode('`,`',array_keys($values)).'`) VALUES('.implode(',',array_fill(0,count($values),'?')).')';$args=array_values($values);}
            $pdo->prepare($sql)->execute($args);$message='草稿已保存；尚未改变报价使用的成本。';
        }elseif(in_array($action,array('bind_naming_to_project','unbind_naming_from_project','naming_sync_apply'),true)){
            if(in_array($status,array('pending','approved'),true))throw new BomWorkflowError('locked','待审核/已审核 BOM 不能同步或更换型号，请先驳回或退审');
            if($action==='bind_naming_to_project')bom_v771_bind_project_to_naming($pdo,$user,$d);
            elseif($action==='unbind_naming_from_project')bom_v771_unbind_project_from_naming($pdo,$user,$d);
            else bom_v78_naming_sync_apply($pdo,$user,$p);
            $message='基础资料已更新，历史报价成本保持冻结；新版本审核后才更新报价。';
        }elseif($action==='delete_project'){
            if(in_array($status,array('pending','approved'),true))throw new BomWorkflowError('locked','待审核/已审核 BOM 不能删除');
            $pdo->prepare('UPDATE bom_projects SET is_active=0,updated_by=?,updated_at=NOW() WHERE project_uid=?')->execute(array($actor,$uid));$message='BOM 已移出总览，历史快照保留。';
        }elseif($action==='create_snapshot'){
            if($status!=='approved'||empty($p['latest_snapshot_id']))throw new BomWorkflowError('state','仅已审核 BOM 可查看正式快照');
            $snapshot=array('id'=>(int)$p['latest_snapshot_id']);$message='已返回该审核版本的快照，不重复创建。';
        }else{
            $allowed=array('submit_review'=>array('draft','rejected'),'approve_project'=>array('pending'),'reject_project'=>array('pending'),'unapprove_project'=>array('approved'));
            if(!isset($allowed[$action])||!in_array($status,$allowed[$action],true))throw new BomWorkflowError('state','状态已变化，当前不能执行此操作，请重新读取');
            if(in_array($action,array('reject_project','unapprove_project'),true)&&$note==='')throw new BomWorkflowError('validation','请填写驳回/退审原因');
            if(in_array($action,array('submit_review','approve_project'),true))bw_validate($p,true);
            $next=array('submit_review'=>'pending','approve_project'=>'approved','reject_project'=>'rejected','unapprove_project'=>'draft')[$action];
            $extra=$action==='submit_review'?',submitted_by=?,submitted_at=NOW()':($action==='approve_project'?',approved_by=?,approved_at=NOW()':($action==='unapprove_project'?",approved_by='',approved_at=NULL":''));
            $args=array($next,$note,$actor);if(in_array($action,array('submit_review','approve_project'),true))$args[]=$actor;$args[]=$uid;
            $pdo->prepare("UPDATE bom_projects SET review_status=?,review_note=?,updated_by=?,updated_at=NOW()$extra WHERE project_uid=?")->execute($args);
            $p=bw_get($pdo,$uid);
            if($action==='approve_project'){
                $snapshot=bom_insert_snapshot($pdo,$p,$actor,$note);bw_publish($pdo,$p,(int)$snapshot['id'],'approved_snapshot');
                // Publication and existing quotation policy cache commit together, or neither does.
                $policySync=bom_sync_quote_cost_snapshot($pdo,$uid,$actor);
                $message='审核成功，已封存快照 '.$snapshot['snapshot_uid'].'；报价取价已切换为此审核版本。';
                if(!empty($policySync['skipped']))$message.=' 价格策略缓存未同步，请管理员检查；审核快照已发布。';
            }else $message=array('submit_review'=>'已提交当前版本，等待审核。','reject_project'=>'已驳回，原因已记录。','unapprove_project'=>'已退审，可修改草稿；报价继续使用上一份已发布成本，重新审核后才切换。')[$action];
        }
        if($action!=='create_snapshot')$pdo->prepare('UPDATE bom_projects SET workflow_version=workflow_version+1 WHERE project_uid=?')->execute(array($uid));
        $p=bw_get($pdo,$uid);$revision=bw_revision($p);
        $pdo->prepare('INSERT INTO bom_workflow_events(project_uid,action,actor,before_revision,after_revision,note,changes_json,snapshot_id) VALUES(?,?,?,?,?,?,?,?)')->execute(array($uid,$action,$actor,bw_revision($before),$revision,$note,bw_json(bw_changes($before,$p)),$snapshot['id']??null));
        $result=array('ok'=>true,'project_uid'=>$uid,'revision'=>$revision,'snapshot'=>$snapshot,'message'=>$message);
        $pdo->prepare('UPDATE bom_workflow_requests SET result_json=? WHERE request_id=?')->execute(array(bw_json($result),$request));
        $pdo->commit();return $result;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
