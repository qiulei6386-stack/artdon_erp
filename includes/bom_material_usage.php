<?php
// Read-only projections of an indexed BOM row. No material prices or BOM rows are rewritten.
function bmu_rows(PDO $pdo,array $ids): Generator {
    $marks=implode(',',array_fill(0,count($ids),'?'));
    $sql="SELECT u.material_id,u.row_no,u.row_hash,u.binding_status,p.project_uid,p.name,p.model,p.version_no,p.review_status,p.currency,
        s.identity_status,s.confirmed_price,s.price_version
        FROM bom_material_usages u JOIN bom_projects p ON p.project_uid=u.project_uid
        LEFT JOIN bom_material_state s ON s.material_id=u.material_id
        WHERE p.is_active=1 AND u.material_id IN ($marks)
        ORDER BY u.project_uid,u.row_no,u.material_id";
    $st=$pdo->prepare($sql);$st->execute(array_values($ids));
    $projectRows=$pdo->prepare('SELECT IF(OCTET_LENGTH(rows_json)<=2097152,rows_json,NULL) FROM bom_projects WHERE project_uid=?');
    $uid=null;$rows=[];
    // Keep only one BOM's decoded rows. Preserve original JSON key order for legacy row hashes.
    // MySQL JSON_EXTRACT reorders object keys and cannot reproduce those persisted hashes.
    while($u=$st->fetch(PDO::FETCH_ASSOC)){
        if($uid!==$u['project_uid']){$uid=$u['project_uid'];$projectRows->execute([$uid]);$json=$projectRows->fetchColumn();$rows=$json===null?[]:bw_rows($json);}
        $u['row']=$rows[(int)$u['row_no']]??null;yield $u;
    }
}
function bmu_match(array $u,array $m): ?array {
    $r=$u['row']??null;if(!is_array($r))return null;
    if(!hash_equals((string)$u['row_hash'],bl_row_hash($r))||!bl_identity_matches($r,$m))return null;
    // A shared name/specification does not override a known conflicting brand.
    if(!empty($r['brand'])&&!empty($m['brand'])&&mb_strtolower(trim($r['brand']))!==mb_strtolower(trim($m['brand'])))return null;
    return $r;
}
function bmu_view(array $u,array $r,bool $canCost): array {
    $out=array_intersect_key($u,array_flip(['project_uid','name','model','version_no','review_status','binding_status','currency','material_id']));
    $out['row_no']=(int)$u['row_no']+1;
    $out['material_name']=$r['name'];$out['material_model']=$r['model']??'';$out['spec']=$r['spec'];
    $out['unit']=$r['unit']??'';$out['qty']=$r['qty'];
    if($canCost){
        $out['stored_price']=$r['price'];$out['price_source']='bom_line';
        if($u['binding_status']!=='needs_identity'&&$u['identity_status']==='confirmed'&&$u['confirmed_price']!==null&&($r['priceMode']??'library')!=='override'){
            $r['price']=(float)$u['confirmed_price'];$out['price_source']='material_standard';
        }
        foreach(['price','process','finishCost','finishCost2'] as $f)$out[$f]=(float)$r[$f];
        $out['finish']=(string)($r['finish']??'');$out['finish2']=(string)($r['finish2']??'');
        $out['unit_cost']=round($r['price']+$r['process']+$r['finishCost']+$r['finishCost2'],4);
        $out['subtotal']=round($r['qty']*($r['price']+$r['process']+$r['finishCost']+$r['finishCost2']),4);
    }
    return $out;
}
function bmu_add_profile(array &$profiles,array $row): void {
    $p=array_intersect_key($row,array_flip(['currency','unit','price','process','finish','finishCost','finish2','finishCost2','unit_cost','price_source']));
    $key=hash('sha256',bw_json($p));
    if(!isset($profiles[$key]))$profiles[$key]=$p+['row_count'=>0,'projects'=>[]];
    $profiles[$key]['row_count']++;
    $profiles[$key]['projects'][$row['project_uid']]=true;
}
function bmu_profiles(array $profiles): array {
    $out=[];
    foreach($profiles as $p){$p['bom_count']=count($p['projects']);unset($p['projects']);$out[]=$p;}
    return $out;
}
function bmu_material_costs(PDO $pdo,array $materials): array {
    $index=array_column($materials,null,'id');if(!$index)return [];
    $groups=[];$counts=[];$excluded=[];$st=bmu_rows($pdo,array_keys($index));
    foreach($st as $u){
        $id=(int)$u['material_id'];$r=bmu_match($u,$index[$id]);
        if(!$r){$excluded[$id]=($excluded[$id]??0)+1;continue;}
        $counts[$id]=($counts[$id]??0)+1;
        if(!isset($groups[$id]))$groups[$id]=[];
        bmu_add_profile($groups[$id],bmu_view($u,$r,true));
    }
    $out=[];
    foreach($index as $id=>$m){$profiles=bmu_profiles($groups[$id]??[]);
        $out[$id]=['profiles'=>array_slice($profiles,0,3),'profile_count'=>count($profiles),'usage_count'=>$counts[$id]??0,'excluded_count'=>$excluded[$id]??0];
    }
    return $out;
}
function bmu_where_used(PDO $pdo,int $id,bool $canCost): array {
    $st=$pdo->prepare('SELECT id,brand,name,model,spec,unit FROM bom_materials WHERE id=? AND is_active=1');$st->execute([$id]);
    $m=$st->fetch(PDO::FETCH_ASSOC);if(!$m)throw new RuntimeException('物料不存在或已停用');
    $st=bmu_rows($pdo,[$id]);$out=[];$excluded=0;$count=0;$profiles=[];
    foreach($st as $u){
        $r=bmu_match($u,$m);if(!$r){$excluded++;continue;}
        $v=bmu_view($u,$r,$canCost);$count++;
        if(count($out)<2000)$out[]=$v;
        if($canCost)bmu_add_profile($profiles,$v);
    }
    return ['ok'=>true,'material'=>$m,'usages'=>$out,'text_candidates'=>[],'total'=>$count,'excluded_count'=>$excluded,'cost_profiles'=>$canCost?bmu_profiles($profiles):[],'limit'=>2000];
}
