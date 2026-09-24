<?php
function qsd_h($v): string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function qsd_title(array $doc,string $type): string{return 'QB-'.(int)$doc['id'].'-'.strtoupper($type).'-R'.(int)$doc['version'];}
function qsd_state(array $doc): string {
    if($doc['state']==='cancelled')return 'CANCELLED / 已取消';
    if(empty($doc['issued']))return 'DRAFT / 草稿 - 未签发';
    return (int)$doc['version']!==(int)$doc['current_version']?'SUPERSEDED / 历史签发版 - 已替代':'ISSUED / 已签发';
}
function qsd_rows(array $doc,string $type): array {
    $d=$doc['data'];$out=[];
    if($type==='ci'){foreach($d['items']??[] as $i)$out[]=[$i['order_no']??'',$i['product_code']??'',trim(($i['product_name']??'')."\n".($i['specification']??'').' '.($i['color']??'').' '.($i['customer_code']??'')),(float)$i['qty'],number_format((float)$i['unit_price'],4,'.',''),number_format((float)$i['amount'],2,'.','')];}
    else{$map=[];foreach($d['items']??[] as $i)$map[(int)$i['order_item_id']]=$i;foreach($d['cartons']??[] as $c){$names=[];$qty=0;foreach($c['items']??[] as $part){$i=$map[(int)$part['order_item_id']]??[];$qty+=(float)$part['qty'];$names[]=($i['order_no']??'').' / '.($i['product_code']??'').' '.($i['customer_code']??'').' '.($i['color']??'').' : '.$part['qty'].' PCS';}$out[]=[$c['carton_no']??'',implode("\n",$names),(int)($c['carton_count']??1),$qty,$c['carton_size']??'',(float)($c['nw']??0),(float)($c['gw']??0),(float)($c['cbm']??0)];}}
    return $out;
}
function qsd_columns(array $doc,string $type): array{return $type==='ci'?['Order','Model','Description','Qty (PCS)','Price '.($doc['data']['currency']??''),'Amount']:['Carton','Order / Model / Qty','CTNS','PCS','Size (cm)','N.W. kg','G.W. kg','CBM'];}
function qsd_html(array $doc,string $type): string {
    $d=$doc['data'];$title=$type==='ci'?'COMMERCIAL INVOICE':'PACKING LIST';$rows=qsd_rows($doc,$type);$widths=$type==='ci'?[14,16,35,10,12,13]:[7,30,6,7,20,10,10,10];$table='<table><colgroup>';foreach($widths as $width)$table.='<col style="width:'.$width.'%">';$table.='</colgroup><thead><tr>';foreach(qsd_columns($doc,$type) as $c)$table.='<th>'.qsd_h($c).'</th>';$table.='</tr></thead><tbody>';foreach($rows as $row){$table.='<tr>';foreach($row as $cell)$table.='<td>'.nl2br(qsd_h($cell)).'</td>';$table.='</tr>';}$table.='</tbody></table>';
    $total=$type==='ci'?($d['currency']??'').' '.number_format(array_sum(array_column($d['items']??[],'amount')),2,'.',''):($d['totals']['cartons']??0).' CTNS · '.($d['totals']['gw']??0).' KG · '.($d['totals']['cbm']??0).' CBM';
    return '<!doctype html><html lang="zh"><meta charset="utf-8"><title>'.qsd_h(qsd_title($doc,$type)).'</title><style>@page{size:A4;margin:13mm}body{font:12px/1.5 Arial,"Noto Sans CJK SC",sans-serif;color:#152238;margin:0}h1{font-size:23px;margin:4px 0}h2{font-size:18px}.state{padding:8px;background:#eef3f8;border:1px solid #bacadd}p{white-space:pre-wrap;overflow-wrap:anywhere}table{border-collapse:collapse;width:100%;font-size:10px;margin:18px 0;table-layout:fixed}th,td{border:1px solid #8a9aaf;padding:3px;overflow-wrap:anywhere;vertical-align:top}th{background:#edf2f7}thead{display:table-header-group}tr{break-inside:avoid}.footer{break-inside:avoid}.total{font-size:14px;text-align:right;font-weight:bold}.toolbar{padding:12px;display:flex;gap:12px}@media print{.toolbar{display:none}}</style><body><h1>'.qsd_h($d['seller_name']??'').'</h1><p>'.qsd_h($d['seller_text']??'').'</p><h2>'.$title.'</h2><div class="state">'.qsd_h(qsd_title($doc,$type).' · '.qsd_state($doc)).'</div><p>Date: '.qsd_h($d['ship_date']??'').'<br>Buyer / Consignee: '.qsd_h($d['consignee']??'').'</p><p>Shipping: '.qsd_h(($d['ship_method']??'').' / '.($d['port_loading']??'').' → '.($d['port_destination']??'')).'</p>'.$table.'<section class="footer"><p class="total">Total: '.qsd_h($d['totals']['qty']??0).' PCS / '.qsd_h($total).'</p><p>'.qsd_h($type==='ci'?($d['bank_text']??''):($d['shipping_mark']??'')).'</p><p>'.qsd_h($d['note']??'').'</p></section></body></html>';
}
function qsd_excel(array $doc,string $type): string {
    $rows=[[qsd_title($doc,$type)],[$type==='ci'?'COMMERCIAL INVOICE':'PACKING LIST'],[qsd_state($doc)],[$doc['data']['seller_name']??''],[$doc['data']['consignee']??''],qsd_columns($doc,$type)];$rows=array_merge($rows,qsd_rows($doc,$type));$rows[]=['Total PCS',$doc['data']['totals']['qty']??0];
    if($type==='ci')$rows[]=['Total '.($doc['data']['currency']??''),array_sum(array_column($doc['data']['items']??[],'amount'))];
    else foreach(['cartons'=>'Total CTNS','nw'=>'Total N.W. kg','gw'=>'Total G.W. kg','cbm'=>'Total CBM'] as $key=>$label)$rows[]=[$label,$doc['data']['totals'][$key]??0];
    $rows[]=['Ship date',$doc['data']['ship_date']??''];$rows[]=['Shipping mark',$doc['data']['shipping_mark']??''];$rows[]=['Note',$doc['data']['note']??''];
    $xml='<?xml version="1.0" encoding="UTF-8"?><Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"><Worksheet ss:Name="'.strtoupper($type).'"><Table>';
    foreach($rows as $row){$xml.='<Row>';foreach($row as $v)$xml.='<Cell><Data ss:Type="'.(is_int($v)||is_float($v)?'Number':'String').'">'.qsd_h($v).'</Data></Cell>';$xml.='</Row>';}return $xml.'</Table></Worksheet></Workbook>';
}
// Export-only thumbnails: load one source at a time, never rewrite archived images.
function qsd_item_thumbnail(PDO $pdo,int $id,string $table='quote_shipment_items'): string {
    if(!in_array($table,['quote_shipment_items','quote_sales_order_items'],true))throw new InvalidArgumentException('Invalid image source');
    $json="IF(JSON_VALID(item_json),item_json,'{}')";$images=["NULLIF(image,'')"];
    foreach(['image','product_image','image_url','product.image','product.image_display','product.product_image','product.main_image','product.image_url'] as $path)$images[]="NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT($json,'$.$path')),'null'),'')";
    $expr='COALESCE('.implode(',',$images).",'')";
    $st=$pdo->prepare("SELECT IF(OCTET_LENGTH($expr)>16777216,'__OVERSIZE__',$expr) FROM $table WHERE id=?");$st->execute([$id]);$src=(string)$st->fetchColumn();
    if($src==='__OVERSIZE__')throw new RuntimeException('产品图片过大，超过安全导出上限（明细 '.$id.'）；原图未改动');
    return qsd_thumbnail($src);
}
function qsd_thumbnail(string $src): string {
    if(stripos($src,'data:')!==0)return $src;
    if(!preg_match('#^data:image/(?:png|jpe?g|gif|webp);base64,#i',$src,$m))throw new RuntimeException('不支持的单证图片格式');
    $bytes=base64_decode(substr($src,strlen($m[0])),true);$info=$bytes!==false?@getimagesizefromstring($bytes):false;
    if(!$info||$info[0]*$info[1]>12000000||strlen($bytes)>12582912)throw new RuntimeException('单证图片异常或尺寸过大；原图未改动');
    if(!function_exists('imagecreatefromstring'))throw new RuntimeException('缺少图片缩略图组件');
    $source=@imagecreatefromstring($bytes);if(!$source)throw new RuntimeException('单证图片解码失败');
    try{$scale=min(1,256/max($info[0],$info[1]));$w=max(1,(int)round($info[0]*$scale));$h=max(1,(int)round($info[1]*$scale));$thumb=imagecreatetruecolor($w,$h);imagefill($thumb,0,0,imagecolorallocate($thumb,255,255,255));imagecopyresampled($thumb,$source,0,0,0,0,$w,$h,$info[0],$info[1]);ob_start();imagejpeg($thumb,null,88);$out=ob_get_clean();imagedestroy($thumb);return 'data:image/jpeg;base64,'.base64_encode($out);}finally{imagedestroy($source);}
}
function qsd_pdf(string $html): string {
    require_once __DIR__.'/quote_mail.php';$chrome='';foreach(['/usr/bin/google-chrome','/usr/bin/google-chrome-stable','/usr/bin/chromium','/usr/bin/chromium-browser'] as $bin)if(is_executable($bin)){$chrome=$bin;break;}
    if(!$chrome||!is_executable('/usr/bin/timeout')||!function_exists('proc_open'))throw new RuntimeException('PDF生成器不可用，请使用预览页打印/保存PDF并联系管理员');
    if(strlen($html)>24*1024*1024)throw new RuntimeException('单证过大，请分批下载');
    $dir=sys_get_temp_dir().'/quote-mail-'.bin2hex(random_bytes(12));if(!mkdir($dir,0700))throw new RuntimeException('无法创建单证临时目录');
    try{
        // No scripts, remote requests or credentials are passed to the renderer.
        $html=preg_replace('#<script\b[^>]*>.*?</script>#is','',$html);
        $html=preg_replace_callback('#<img\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>#i',function($m){$src=html_entity_decode($m[1],ENT_QUOTES,'UTF-8');if(preg_match('#^data:image/(png|jpeg|jpg|gif|webp);base64,#i',$src))return '<img style="max-width:12mm;max-height:12mm" src="'.qsd_h($src).'">';$host=parse_url($src,PHP_URL_HOST);if($host&&!in_array($host,['novlight.com','www.novlight.com'],true))throw new RuntimeException('单证含外部图片，请先修正');$path=preg_replace('#^/artdon_erp/#','/',parse_url($src,PHP_URL_PATH)??'');$root=dirname(__DIR__);$file=realpath($root.'/'.ltrim($path,'/'));if(!$file||!preg_match('#^'.preg_quote($root,'#').'/(uploads|storage|assets)/#',$file)||!is_file($file)||filesize($file)>8*1024*1024)throw new RuntimeException('单证图片不可读取，未生成不完整PDF');$bytes=file_get_contents($file);$info=@getimagesizefromstring($bytes);if(!$info||$info[0]*$info[1]>12000000)throw new RuntimeException('单证图片异常');return '<img style="max-width:12mm;max-height:12mm" src="data:'.$info['mime'].';base64,'.base64_encode($bytes).'">';},$html);
        $csp='<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src data:; style-src \'unsafe-inline\'; font-src data:;">';$html=preg_replace('#<html\b[^>]*>#i','$0'.$csp,$html,1);
        file_put_contents($dir.'/doc.html',$html);file_put_contents($dir.'/input','');
        qmail_run(['/usr/bin/timeout','35',$chrome,'--headless','--disable-gpu','--disable-dev-shm-usage','--disable-background-networking','--no-first-run','--no-pdf-header-footer','--user-data-dir='.$dir.'/chrome','--print-to-pdf='.$dir.'/doc.pdf','file://'.$dir.'/doc.html'],$dir.'/input',$dir.'/stdout',$dir.'/stderr');
        $bytes=is_file($dir.'/doc.pdf')?file_get_contents($dir.'/doc.pdf'):'';if(strncmp($bytes,'%PDF-',5)!==0||strlen($bytes)<100||strlen($bytes)>20*1024*1024)throw new RuntimeException('PDF生成未完成，请重试');return $bytes;
    }finally{qmail_remove_temp($dir);}
}
function qsd_download_pdf(PDO $pdo,string $html,string $name): void {
    $s=$pdo->query("SELECT GET_LOCK('quote_shipment_pdf_render',0)");if(!(int)$s->fetchColumn())throw new RuntimeException('其他单证正在生成，请稍后重试');
    try{$pdf=qsd_pdf($html);}finally{$pdo->query("SELECT RELEASE_LOCK('quote_shipment_pdf_render')");}
    header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="'.preg_replace('/[^A-Za-z0-9_.-]/','_',$name).'.pdf"');header('Content-Length: '.strlen($pdf));echo $pdf;
}
