<?php
// Read-only dashboard queries. Never load all project details or the naming payload catalogue.
function bom_dashboard_keyword($value) {
    if (!is_string($value)) throw new InvalidArgumentException('请输入搜索文字');
    $value = trim($value);
    if (strlen($value) > 480) throw new InvalidArgumentException('搜索文字过长');
    return $value;
}
function bom_dashboard_search(PDO $pdo, $value) {
    $keyword = bom_dashboard_keyword($value);
    if ($keyword === '') return array();
    $like = '%'.strtr($keyword, array('!'=>'!!', '%'=>'!%', '_'=>'!_')).'%';
    $parts = array(); $args = array();
    foreach (array('name','customer','model','product_type') as $column) {
        $parts[] = '`'.$column.'` LIKE ? ESCAPE \'!\'';
        $args[] = $like;
    }
    // Search only descriptive material fields, not supplier/cost/image/hidden JSON data.
    $parts[] = "JSON_SEARCH(IF(JSON_VALID(rows_json), LOWER(rows_json), '[]'), 'one', LOWER(?), '!', '$[*].name', '$[*].material_name', '$[*].materialname', '$[*].spec', '$[*].category', '$[*].model', '$[*].material_model') IS NOT NULL";
    $args[] = $like;
    $st = $pdo->prepare('SELECT /*+ MAX_EXECUTION_TIME(2000) */ project_uid FROM bom_projects WHERE is_active=1 AND ('.implode(' OR ', $parts).')');
    $st->execute($args);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}
function bom_dashboard_image_url($raw) {
    $raw = trim((string)$raw);
    if ($raw === '' || strlen($raw) > 2048 || preg_match('/^(?:data|blob|javascript):/i', $raw)) return '';
    $url = bom_v777_normalize_media_url($raw);
    if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $url) && !preg_match('#^https?://#i', $url)) return '';
    return $url;
}
function bom_dashboard_images(PDO $pdo, $ids) {
    if (!is_array($ids) || count($ids) > 18) throw new InvalidArgumentException('每次最多读取当前页的 18 张图片');
    foreach ($ids as $id) if (!is_string($id) || strlen($id)>120 || $id==='') throw new InvalidArgumentException('BOM 编号无效');
    $ids = array_values(array_unique($ids));
    if (!$ids) return array();
    $marks = implode(',', array_fill(0,count($ids),'?'));
    // SQL bounds image text before transfer, even if a legacy row contains a huge data URI.
    $st=$pdo->prepare("SELECT project_uid,model,linked_system,linked_id,CASE WHEN OCTET_LENGTH(product_image)<=2048 THEN product_image ELSE '' END AS image, OCTET_LENGTH(product_image)>2048 AS oversized FROM bom_projects WHERE is_active=1 AND project_uid IN ($marks)");
    $st->execute($ids); $projects=$st->fetchAll(PDO::FETCH_ASSOC); $out=array();
    foreach($projects as $p){
        $url=bom_dashboard_image_url($p['image']);
        if($url==='' && !$p['oversized'] && table_exists($pdo,'naming_models')){
            // Exact indexed relation only: no fuzzy image match to another product.
            $nid=strtoupper((string)$p['linked_system'])==='NAMING' ? (int)$p['linked_id'] : 0;
            if(!$nid && preg_match('/^BOM-NAMING-(\d+)-/', $p['project_uid'],$m))$nid=(int)$m[1];
            $fields=array_intersect(array('source_system','image_path','web_image_url','source_image_url','drawing_path','source_drawing_url','web_dimension_url'),cols($pdo,'naming_models'));
            if($fields && ($nid>0 || trim((string)$p['model'])!=='')){
                $select=implode(',',array_map(function($f){return 'CASE WHEN OCTET_LENGTH(`'.$f.'`)<=2048 THEN `'.$f.'` ELSE \'\' END AS `'.$f.'`';},$fields));
                $ns=$pdo->prepare('SELECT '.$select.' FROM naming_models WHERE '.($nid>0?'id=?':'model_no=?').' LIMIT 1');
                $ns->execute(array($nid>0?$nid:$p['model'])); $n=$ns->fetch(PDO::FETCH_ASSOC);
                if($n)$url=bom_dashboard_image_url(bom_v777_pick_media($n,'image') ?: bom_v777_pick_media($n,'drawing'));
            }
        }
        $out[]=array('project_uid'=>$p['project_uid'],'url'=>$url,'status'=>$url!==''?'ready':($p['oversized']?'oversized':'missing'));
    }
    return $out;
}
