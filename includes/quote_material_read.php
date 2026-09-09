<?php
// Quotation initialization needs material identifiers/prices, not every original image.
function quote_material_light_columns(array $columns): string {
    $wanted=array('id','mid','category','type','brand','name','material_name','model','code','spec','remark','price','unit_price','unit','supplier','keyword');
    $select=array();foreach(array_intersect($wanted,$columns) as $name)$select[]='`'.$name.'`';
    if(in_array('image',$columns,true))$select[]="CASE WHEN COALESCE(image,'')<>'' THEN 1 ELSE 0 END AS image_deferred";
    return implode(',',$select);
}
function quote_material_defer_image(array $material): array {
    $deferred=!empty($material['image_deferred'])||trim((string)($material['image']??''))!=='';
    $material['image']='';$result=norm_material($material);$result['image_deferred']=$deferred;
    return $result;
}
function quote_material_image(PDO $pdo,$id): string {
    if(!is_scalar($id)||!preg_match('/^[0-9]{1,18}$/D',(string)$id)||(int)$id<=0)throw new InvalidArgumentException('物料编号无效');
    foreach(array('bom_materials','materials') as $table){
        if(!table_exists($pdo,$table))continue;
        $columns=table_columns($pdo,$table);$key=in_array('id',$columns,true)?'id':(in_array('mid',$columns,true)?'mid':'');
        if(!$key||!in_array('image',$columns,true))return '';
        $active=in_array('is_active',$columns,true)?' AND is_active=1':'';
        $st=$pdo->prepare("SELECT CASE WHEN OCTET_LENGTH(image)<=16777216 THEN image ELSE '' END AS image,OCTET_LENGTH(image) AS bytes FROM `$table` WHERE `$key`=?$active LIMIT 1");$st->execute(array($id));$row=$st->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new RuntimeException('物料不存在或已停用，请刷新后重新选择');
        if((int)$row['bytes']>16777216)throw new RuntimeException('该物料原图超过16MB，需要先处理图片后重试');
        return (string)($row['image']??'');
    }
    if(table_exists($pdo,'bom_kv')){
        $st=$pdo->query("SELECT data_json FROM bom_kv WHERE data_key='materials' LIMIT 1");$raw=$st->fetchColumn();
        foreach((json_decode((string)$raw,true)?:array()) as $row)if((string)($row['id']??$row['mid']??'')===(string)$id)return (string)($row['image']??'');
    }
    throw new RuntimeException('没有找到该物料图片，请刷新后重试');
}
