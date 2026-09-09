<?php
declare(strict_types=1);
// Shared by quotation reads and transactional approval/cache writes. No bootstrap IO.
function bcp_norm($value): string {return preg_replace('/[^A-Z0-9\.]+/','',strtoupper(trim((string)$value)));}
function bcp_models($value): array {
    preg_match_all('/(?:[A-Z]{0,4})?\d{1,3}\.\d{3,6}(?:[A-Z0-9\-]{0,12})?/u',strtoupper((string)$value),$m);
    return array_values(array_unique(array_filter(array_map('bcp_norm',$m[0]))));
}
function bcp_product_keys(array $p): array {
    $keys=array();
    if(strtolower(trim((string)($p['source']??'')))==='naming'&&trim((string)($p['naming_id']??''))!=='')$keys[]='NID'.trim((string)$p['naming_id']);
    foreach(array('code','model','model_no','naming_model_no') as $field)$keys=array_merge($keys,bcp_models($p[$field]??''));
    return array_values(array_unique($keys));
}
function bcp_add(array &$map,array $keys,float $cost,string $source,string $updated,int $quality,bool $allowZero=false): void {
    if(!is_finite($cost)||$cost<0||($cost==0&&!$allowZero))return;
    foreach($keys as $key){
        $key=bcp_norm($key);if($key==='')continue;$old=$map[$key]??null;
        // Preserve historical same-tier maximum. A legitimate approved zero is not missing.
        if(!$old||$quality>(int)$old['quality']||($quality===(int)$old['quality']&&$cost>(float)$old['cost_rmb']))
            $map[$key]=array('cost_rmb'=>$cost,'source_table'=>$source,'updated_at'=>$updated,'match_key'=>$key,'quality'=>$quality,'published_zero'=>$allowZero);
    }
}
function bcp_add_publication(array &$map,array $publication): void {
    $p=json_decode((string)$publication['payload_json'],true,512,JSON_THROW_ON_ERROR);
    $keys=bcp_models($p['model']??'');
    $system=strtoupper(trim((string)($p['linked_system']??'')));$id=trim((string)($p['linked_id']??''));
    if($id!==''&&(strpos($system,'NAMING')!==false||strpos($system,'命名')!==false))$keys[]='NID'.$id;
    $approved=$publication['source']==='approved_snapshot';$released=$approved&&empty($p['initial_freeze']);
    bcp_add($map,$keys,(float)$publication['cost'],$approved?'BOM审核快照 #'.$publication['snapshot_id']:'BOM历史未审核（冻结）',(string)$publication['updated_at'],$released?130:120,$released);
}
function bcp_map(PDO $pdo,bool $lock=false): array {
    $map=array();
    // Lightweight metadata only; never load original images, draft rows or snapshot bodies.
    $st=$pdo->query('SELECT project_uid,snapshot_id,source,payload_json,cost,updated_at FROM bom_cost_publications ORDER BY updated_at DESC,project_uid'.($lock?' FOR UPDATE':''));
    while($p=$st->fetch(PDO::FETCH_ASSOC))bcp_add_publication($map,$p);
    return $map;
}
function bcp_find(array $keys,array $map): array {
    $best=array(null,null,null,-1,0);
    foreach(array_unique(array_map('bcp_norm',$keys)) as $key){
        $nid=strpos($key,'NID')===0;
        if(strlen($key)<5||(!$nid&&!preg_match('/\d+\.\d+/',$key))||!isset($map[$key]))continue;
        $row=$map[$key];$cost=(float)$row['cost_rmb'];$quality=(int)($row['quality']??50);
        if(($cost>0||($cost==0&&!empty($row['published_zero'])))&&($quality>$best[3]||($quality===$best[3]&&$cost>$best[4])))$best=array($key,$row,$nid?'exact_naming_bind':'exact_model',$quality,$cost);
    }
    return array($best[0],$best[1],$best[2]);
}
function bcp_cost_hash(array $map): string {
    $costs=array();foreach($map as $key=>$row)$costs[$key]=number_format((float)$row['cost_rmb'],4,'.','');ksort($costs);
    return hash('sha256',json_encode($costs,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
}
