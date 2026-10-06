<?php
// Read-only material summaries. Original images never enter a list response.
function bmr_columns(): string {
    return "id,category,brand,name,model,spec,price,unit,supplier,keyword,weight_kg_per_m,raw_bar_length_m,material_grade,created_at,updated_at,(COALESCE(image,'')<>'') AS has_image";
}
function bmr_read(PDO $pdo,array $d,bool $canCost,bool $canSupplier): array {
    $where=['is_active=1'];$args=[];
    $kw=mb_substr(trim((string)($d['keyword']??'')),0,500);
    if($kw!==''){
        $fields=['category','brand','name','model','spec','keyword'];if($canSupplier)$fields[]='supplier';
        $where[]='LOCATE(?,CONCAT_WS(\' \','.implode(',',$fields).'))>0';$args[]=$kw;
    }
    foreach(['category','brand','supplier'] as $field){
        $v=trim((string)($d[$field]??''));
        if($field==='supplier'&&!$canSupplier){if($v!=='')throw new RuntimeException('当前账号不能按供应商筛选');continue;}
        if($v!==''){$where[]="`$field`=?";$args[]=$v;}
    }
    $date=(string)($d['date']??'');
    if(in_array($date,['today','yesterday','3','7'],true)){
        $start=new DateTimeImmutable('today');if($date==='yesterday')$start=$start->modify('-1 day');elseif(is_numeric($date))$start=$start->modify('-'.((int)$date-1).' days');
        $where[]='created_at>=?';$args[]=$start->format('Y-m-d H:i:s');
        if($date==='yesterday'){$where[]='created_at<?';$args[]=$start->modify('+1 day')->format('Y-m-d H:i:s');}
    }
    $state=(string)($d['state']??'');if($state==='needs_unit')$where[]="TRIM(unit)=''";elseif(in_array($state,['pending','historical','confirmed','estimated'],true)&&function_exists('bl_ready')&&bl_ready($pdo)){$where[]='id IN (SELECT material_id FROM bom_material_state WHERE price_status=?)';$args[]=$state;}
    $sql=implode(' AND ',$where);$st=$pdo->prepare('SELECT COUNT(*) FROM bom_materials WHERE '.$sql);$st->execute($args);$total=(int)$st->fetchColumn();
    $size=max(1,min(500,(int)($d['page_size']??50)));$pages=max(1,(int)ceil($total/$size));$page=max(1,min($pages,(int)($d['page']??1)));
    $st=$pdo->prepare('SELECT '.bmr_columns().' FROM bom_materials WHERE '.$sql.' ORDER BY updated_at DESC,id DESC LIMIT '.$size.' OFFSET '.(($page-1)*$size));$st->execute($args);
    $rows=[];while($r=$st->fetch(PDO::FETCH_ASSOC)){
        if(!$canCost)$r['price']='';if(!$canSupplier)$r['supplier']='';
        $r['has_image']=(bool)$r['has_image'];$rows[]=$r;
    }
    if(function_exists('bl_ready')&&bl_ready($pdo)&&$rows){
        $ids=array_column($rows,'id');$st=$pdo->prepare('SELECT material_id,identity_status,price_status,confirmed_price,price_version,revision,origin FROM bom_material_state WHERE material_id IN ('.implode(',',array_fill(0,count($ids),'?')).')');$st->execute($ids);$states=[];foreach($st->fetchAll(PDO::FETCH_ASSOC) as $v)$states[(int)$v['material_id']]=$v;
        foreach($rows as &$r){$v=$states[(int)$r['id']]??[];if(!$canCost)unset($v['confirmed_price'],$v['price_version']);$r=array_merge($r,$v);}unset($r);
    }
    $facets=[];foreach(['category','brand','supplier'] as $field){
        $facets[$field]=[];if($field==='supplier'&&!$canSupplier)continue;
        $facets[$field]=$pdo->query("SELECT DISTINCT `$field` FROM bom_materials WHERE is_active=1 AND `$field`<>'' ORDER BY `$field`")->fetchAll(PDO::FETCH_COLUMN);
    }
    return ['ok'=>true,'materials'=>$rows,'total'=>$total,'page'=>$page,'page_size'=>$size,'pages'=>$pages,'facets'=>$facets];
}
function bmr_thumbnail(string $src): string {
    if($src==='')return '';
    if(strlen($src)>8*1024*1024)throw new RuntimeException('图片过大，请通过编辑窗口检查原图');
    if(stripos($src,'data:image/')!==0){
        // Browser may load existing short local/HTTP image addresses. Never fetch URLs here.
        if(strlen($src)<=2048&&preg_match('~^(?:https?://|/?(?:uploads|assets|files)/)~i',$src))return $src;
        throw new RuntimeException('图片地址不支持缩略预览');
    }
    $comma=strpos($src,',');if($comma===false||strpos(substr($src,0,$comma),';base64')===false)throw new RuntimeException('图片格式无效');
    $bytes=base64_decode(substr($src,$comma+1),true);$info=$bytes!==false?@getimagesizefromstring($bytes):false;
    if(!$info||!in_array($info[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_GIF,IMAGETYPE_WEBP],true)||$info[0]*$info[1]>8000000)throw new RuntimeException('图片尺寸过大或格式不支持');
    if(!function_exists('imagecreatefromstring'))throw new RuntimeException('缩略图组件不可用');
    $im=@imagecreatefromstring($bytes);if(!$im)throw new RuntimeException('图片解码失败');unset($bytes);
    $scale=min(1,160/$info[0],160/$info[1]);$w=max(1,(int)round($info[0]*$scale));$h=max(1,(int)round($info[1]*$scale));$out=imagecreatetruecolor($w,$h);
    try {imagefill($out,0,0,imagecolorallocate($out,255,255,255));imagecopyresampled($out,$im,0,0,0,0,$w,$h,$info[0],$info[1]);ob_start();imagejpeg($out,null,75);$jpeg=ob_get_clean();return 'data:image/jpeg;base64,'.base64_encode($jpeg);}
    finally {imagedestroy($im);imagedestroy($out);}
}
function bmr_image(PDO $pdo,int $id,bool $original=false): array {
    $st=$pdo->prepare('SELECT OCTET_LENGTH(image) FROM bom_materials WHERE id=? AND is_active=1');$st->execute([$id]);$size=$st->fetchColumn();
    if($size===false)throw new RuntimeException('物料已删除或不存在');
    if((int)$size>8*1024*1024)throw new RuntimeException('图片超过安全读取上限');
    $st=$pdo->prepare('SELECT image FROM bom_materials WHERE id=? AND is_active=1');$st->execute([$id]);$src=(string)$st->fetchColumn();
    return ['ok'=>true,'id'=>$id,'image'=>$original?$src:bmr_thumbnail($src)];
}
