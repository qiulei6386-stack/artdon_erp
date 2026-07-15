<?php
/* ARTDON_QUOTE_ORDER_DOC_V6_8_5_54 PL uppercase title */
if (file_exists(__DIR__.'/includes/artdon_sso_core.php')) {
  require_once __DIR__.'/includes/artdon_sso_core.php';
  if (function_exists('artdon_sso_require_page')) artdon_sso_require_page('quote');
}
require_once __DIR__ . '/includes/bootstrap.php';
if (session_status() === PHP_SESSION_NONE) { @session_name('ARTDON_SYS'); @session_start(); }
$pdo=db();
function qd_h($v){return htmlspecialchars((string)($v??''),ENT_QUOTES,'UTF-8');}
function qd_s($v){return trim((string)($v??''));}
function qd_num($v){return is_numeric($v)?(float)$v:0.0;}
function qd_money($v){return number_format((float)$v,2,'.','');}
function qd_qty($v,$d=2){$s=number_format((float)$v,$d,'.','');$s=preg_replace('/\.0+$/','',$s);$s=preg_replace('/(\.\d*?)0+$/','$1',$s);return $s;}
function qd_json($v,$def=[]){if(is_array($v))return $v;$a=json_decode((string)$v,true);return is_array($a)?$a:$def;}
function qd_row(PDO $pdo,$sql,$p=[]){$s=$pdo->prepare($sql);$s->execute($p);$r=$s->fetch(PDO::FETCH_ASSOC);return $r?:null;}
function qd_rows(PDO $pdo,$sql,$p=[]){$s=$pdo->prepare($sql);$s->execute($p);return $s->fetchAll(PDO::FETCH_ASSOC);}
function qd_table_exists(PDO $pdo,$t){try{$s=$pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');$s->execute([$t]);return (bool)$s->fetchColumn();}catch(Throwable $e){return false;}}
function qd_columns(PDO $pdo,$t){if(!qd_table_exists($pdo,$t))return [];try{$s=$pdo->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');$s->execute([$t]);return array_map(function($r){return $r['COLUMN_NAME'];},$s->fetchAll(PDO::FETCH_ASSOC));}catch(Throwable $e){return [];}}
function qd_col_exists(PDO $pdo,$t,$c){return in_array($c,qd_columns($pdo,$t),true);}
function qd_ensure_col(PDO $pdo,$t,$c,$ddl){if(!qd_table_exists($pdo,$t))return;if(qd_col_exists($pdo,$t,$c))return;try{$pdo->exec('ALTER TABLE `'.$t.'` ADD COLUMN `'.$c.'` '.$ddl);}catch(Throwable $e){$m=$e->getMessage();if(stripos($m,'Duplicate')===false&&stripos($m,'1060')===false)throw $e;}}
function qd_ensure_doc_schema(PDO $pdo){
  // V6.8.5.26：打开 PL/CI 预览页时也自动补齐单证设置表字段，避免旧表没有 settings_json 直接 fatal。
  if(!qd_table_exists($pdo,'quote_document_settings')){
    $pdo->exec("CREATE TABLE IF NOT EXISTS quote_document_settings (id INT PRIMARY KEY, settings_json LONGTEXT NULL, updated_by VARCHAR(120) DEFAULT '', updated_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  }else{
    qd_ensure_col($pdo,'quote_document_settings','settings_json','LONGTEXT NULL');
    qd_ensure_col($pdo,'quote_document_settings','updated_by',"VARCHAR(120) DEFAULT ''");
    qd_ensure_col($pdo,'quote_document_settings','updated_at','DATETIME NULL');
  }
  if(qd_table_exists($pdo,'quote_shipments')){
    qd_ensure_col($pdo,'quote_shipments','pl_generated_at','DATETIME NULL');
    qd_ensure_col($pdo,'quote_shipments','ci_generated_at','DATETIME NULL');
    qd_ensure_col($pdo,'quote_shipments','forwarder',"VARCHAR(255) DEFAULT ''");
  }
}
function qd_order_no_at($orderNo,$quoteNo=''){ $s=trim((string)($orderNo?:$quoteNo)); if($s==='')return ''; return preg_replace('/^SO-/i','AT-',$s); }
function qd_safe_file($s,$ext){
  $s=trim((string)$s);
  $s=preg_replace('#[\\/:*?"<>|]+#',' ', $s);
  $s=preg_replace('/\s+/',' ', $s);
  $s=trim($s, " ._-	
");
  return ($s!==''?$s:'document').$ext;
}
function qd_content_disposition_filename($filename){
  $safe=str_replace(['\\','"'],['_','\"'],$filename);
  return 'attachment; filename="'.$safe.'"; filename*=UTF-8\'\''.rawurlencode($filename);
}
function qd_doc_file_title($type,$order){
  $orderNo=qd_order_no_at($order['order_no']??'', $order['quote_no']??'');
  if($orderNo==='') $orderNo='Order';
  $label=$type==='ci' ? 'Commercial Invoice' : 'Packing List';
  return trim($orderNo.' '.$label);
}
function qd_default_doc_settings(){return ['seller_name'=>'Artdon Lighting Limited','seller_text'=>'Artdon Lighting Limited'."\n".'Zhongshan, Guangdong, China','buyer_label'=>'Buyer / Consignee','notify_party'=>'','signature_company'=>'Artdon Lighting Limited','footer_note'=>'All information is generated from the confirmed shipment batch. Packing List and Commercial Invoice use the same shipment quantity.','country_origin'=>'China','port_loading'=>'Zhongshan','ship_method'=>'','show_bank_on_ci'=>1,'show_notify_party'=>0,'pl_blank_label'=>'自定义输入:','forwarder'=>''];}
function qd_settings(PDO $pdo){$d=qd_default_doc_settings();qd_ensure_doc_schema($pdo);if(qd_table_exists($pdo,'quote_document_settings')&&qd_col_exists($pdo,'quote_document_settings','settings_json')){$r=qd_row($pdo,'SELECT settings_json FROM quote_document_settings WHERE id=1 LIMIT 1');if($r){$x=qd_json($r['settings_json'],[]);if($x)$d=array_replace_recursive($d,$x);}}return $d;}
function qd_first($arr,$keys,$def=''){foreach($keys as $k){if(isset($arr[$k]) && trim((string)$arr[$k])!=='')return trim((string)$arr[$k]);}return $def;}
function qd_customer_text($customer,$label='Buyer / Consignee'){
  if(!is_array($customer))$customer=[];$lines=[];$company=qd_first($customer,['company','name','customer_name','client_name']);$contact=qd_first($customer,['primary_contact','contact','contact_name','person','linkman']);$phone=qd_first($customer,['primary_contact_phone','contact_phone','phone','mobile','tel','whatsapp']);$email=qd_first($customer,['primary_contact_email','contact_email','email','mail']);
  if($company!=='')$lines[]=$company;
  if($contact!==''||$phone!=='')$lines[]='Contact: '.trim($contact.($contact!==''&&$phone!==''?'  ':'').$phone);
  foreach(['address1','office_address','address','company_address','address2','factory_address','delivery_address'] as $k){if(!empty($customer[$k]))$lines[]=($k==='address1'||$k==='office_address'||$k==='address'||$k==='company_address'?'Address: ':'').trim((string)$customer[$k]);}
  if($email!=='')$lines[]='Email: '.$email;
  return $lines?implode("\n",$lines):'Please select customer';
}
function qd_order_snapshot($order){$snap=qd_json($order['snapshot_json']??'',[]);return is_array($snap)?$snap:[];}
function qd_order_header($order){$snap=qd_order_snapshot($order);$header=qd_json($order['header_json']??'',[]);if(!$header && isset($snap['header']) && is_array($snap['header']))$header=$snap['header'];return is_array($header)?$header:[];}
function qd_header_seller($order,$settings){$header=qd_order_header($order);$company=qd_s($header['company']??'') ?: qd_s($settings['seller_name']??'') ?: 'Artdon Lighting Limited';$text=qd_s($header['from_text']??'') ?: qd_s($settings['seller_text']??'');return [$company,$text];}
function qd_header_stamp($order){return ''; /* V6.8.5.30: CI/PL 不显示右上角蓝色章，保留右侧标题/表格原位置 */}
function qd_customer_from_order($order){$customer=qd_json($order['customer_json']??'',[]);$snap=qd_order_snapshot($order);if((!is_array($customer)||!count($customer)) && isset($snap['customer']) && is_array($snap['customer']))$customer=$snap['customer'];return is_array($customer)?$customer:[];}
function qd_bank_text($order){$bank=qd_json($order['bank_json']??'',[]);$snap=qd_order_snapshot($order);if(!$bank && isset($snap['bank']) && is_array($snap['bank']))$bank=$snap['bank'];return qd_s($bank['text']??'');}
function qd_desc($it){$s=qd_s($it['specification']??''); if($s==='')$s=qd_s($it['description']??''); if($s==='')$s=trim(($it['product_name']??'').' '.($it['product_code']??'')); return $s;}
function qd_img_src($it){return qd_s($it['image']??($it['product_image']??''));}
function qd_carton_dims($v){$s=qd_s($v);$s=str_replace(['×','X','x','*','，',',','/'], '*', $s);$s=preg_replace('/cm|CM|厘米/u','',$s);$s=preg_replace('/[^0-9\.\*]+/','',$s);$parts=array_values(array_filter(explode('*',$s),function($x){return $x!=='';}));return [$parts[0]??'', $parts[1]??'', $parts[2]??''];}
function qd_order_template($order){$tpl=qd_json($order['template_json']??'',[]);$snap=qd_order_snapshot($order);if((!is_array($tpl)||!count($tpl)) && isset($snap['template']) && is_array($snap['template']))$tpl=$snap['template'];return is_array($tpl)?$tpl:[];}
function qd_order_terms($order){
  $tpl=qd_order_template($order); $terms=qd_json($tpl['terms_json']??'',[]);
  if(!$terms) return [];
  $quoteNo=qd_order_no_at($order['order_no']??'',$order['quote_no']??''); $date=qd_s($order['order_date']??($order['quote_date']??date('Y-m-d')));
  $out=[];
  foreach($terms as $r){
    if(!is_array($r)) continue;
    $a=str_replace(['QTNO','DATE'],[$quoteNo,$date],(string)($r[0]??''));
    $b=str_replace(['QTNO','DATE'],[$quoteNo,$date],(string)($r[1]??''));
    $out[]=[$a,$b];
  }
  return $out;
}
function qd_payment_terms($order){
  $terms=qd_order_terms($order); $payments=[]; $afterPayment=false;
  foreach($terms as $r){
    $label=qd_s($r[0]??''); $val=qd_s($r[1]??''); if($val==='') continue;
    if(preg_match('/payment|付款|付款方式/iu',$label)){$payments[]=$val;$afterPayment=true;continue;}
    if($afterPayment && $label===''){$payments[]=$val;continue;}
    $afterPayment=false;
  }
  return [($payments[0]??''),($payments[1]??'')];
}
function qd_price_terms_from_order($order,$def='EXWORK'){
  foreach(qd_order_terms($order) as $r){ if(preg_match('/price\s*terms|贸易|价格条款/iu',(string)($r[0]??'')) && qd_s($r[1]??'')!=='') return qd_s($r[1]); }
  return $def;
}

function qd_pl_custom_label($settings){
  $label=qd_s($settings['pl_blank_label']??'');
  if($label==='' || preg_match('/^custom\s*:?$/i',$label)) return '自定义输入:';
  return $label;
}

function qd_doc_terms($type,$order,$ship,$settings){
  $orderNo=qd_order_no_at($order['order_no']??'',$order['quote_no']??'');
  if($type==='ci'){
    list($pay1,$pay2)=qd_payment_terms($order);
    $shippingMark=qd_s($ship['shipping_mark']??'');
    $forwarder=qd_s($ship['forwarder']??($settings['forwarder']??''));
    return [
      ['Date:', date('Y-m-d')],
      ['PI No.:', $orderNo],
      ['Payment:', $pay1],
      ['', $pay2],
      ['Price Terms:', '', 'ci_price_terms', true],
      ['Country of Origin:', $ship['country_origin']?:($settings['country_origin']??'China')],
      ['Shipping Mark:', $shippingMark, 'shipping_mark', true],
      ['Forwarder:', $forwarder, 'forwarder', true]
    ];
  }
  return [
    ['Date:', $ship['ship_date']?:date('Y-m-d')],
    ['PI No.:', $orderNo],
    ['', '', 'pl_custom', true, 'pl_custom_label', true]
  ];
}
function qd_item_text($row,$keys,$def=''){
  foreach($keys as $k){ if(isset($row[$k]) && !is_array($row[$k]) && qd_s($row[$k])!=='') return qd_s($row[$k]); }
  return $def;
}
function qd_parse_item_json($row){
  $j=qd_json($row['item_json']??'',[]);
  return is_array($j)?$j:[];
}
function qd_product_from_item($item){
  return (isset($item['product']) && is_array($item['product'])) ? $item['product'] : [];
}
function qd_extract_size_from_spec($spec){
  $s=(string)$spec;
  if($s==='') return '';
  // 从订单快照 Specification 里回退提取尺寸，例如：3. Size: Φ55*140 / Size: 87*125。
  if(preg_match('/(?:^|\n|\r|\s)(?:\d+\.\s*)?(?:Size|SIZE|尺寸|Dimension)\s*[:：]\s*([^\r\n;，,]+)/u',$s,$m)){
    $v=trim((string)$m[1]);
    $v=preg_replace('/\s*(?:mm|MM|毫米)\s*$/u','',$v);
    return trim($v);
  }
  return '';
}
function qd_order_payload_items($order){
  $arr=qd_json($order['items_json']??'',[]);
  if(is_array($arr) && count($arr)) return array_values($arr);
  $snap=qd_order_snapshot($order);
  foreach(['order_items','items','quote_items'] as $k){ if(isset($snap[$k]) && is_array($snap[$k]) && count($snap[$k])) return array_values($snap[$k]); }
  return [];
}
function qd_payload_to_doc_row($it,$idx=0){
  $it=is_array($it)?$it:[];
  $nested=qd_json($it['item_json']??'',[]);
  if(is_array($nested) && count($nested)) $it=array_replace_recursive($nested,$it);
  $p=qd_product_from_item($it);
  $qty=qd_num($it['qty']??0); $price=qd_num($it['unit_price']??($it['price']??0));
  $spec=qd_item_text($it,['specification','description','extra_spec']);
  if($spec==='') $spec=qd_item_text($p,['specification','description','name','product_name','title']);
  $productCode=qd_item_text($it,['product_code','manufacturer_code','factory_model','factoryModel','model','code']); if($productCode==='') $productCode=qd_item_text($p,['code','model','model_no','product_code','factory_model']);
  $productName=qd_item_text($it,['product_name','name','title']); if($productName==='') $productName=qd_item_text($p,['name','product_name','title']);
  $customerCode=qd_item_text($it,['customer_code','customer_model','customerModel','client_model','clientModel']); if($customerCode==='') $customerCode=qd_item_text($p,['customer_code','customer_model','client_model']);
  $size=qd_item_text($it,['size','dimension','dimensions','quote_display_size','drawing_size','dim_size','product_size']); if($size==='') $size=qd_item_text($p,['quote_display_size','size','dimension','dimensions','dim_size','product_size']); if($size==='') $size=qd_extract_size_from_spec($spec);
  $img=qd_item_text($it,['image','product_image','image_url']); if($img==='') $img=qd_item_text($p,['image','image_display','product_image','main_image','image_url']);
  return [
    'id'=>$it['id']??0,'order_item_id'=>$it['order_item_id']??($it['id']??0),'item_index'=>(int)($it['item_index']??$idx),
    'customer_code'=>$customerCode,'product_code'=>$productCode,'product_name'=>$productName,'specification'=>$spec,'size'=>$size,
    'color'=>qd_item_text($it,['color']) ?: qd_item_text($p,['color']),
    'qty'=>$qty,'unit_price'=>$price,'amount'=>qd_num($it['amount']??($qty*$price)),'image'=>$img,
    'pcs_per_ctn'=>qd_num($it['pcs_per_ctn']??0),'cartons'=>qd_num($it['cartons']??0),'carton_size'=>qd_s($it['carton_size']??''),'nw'=>qd_num($it['nw']??0),'gw'=>qd_num($it['gw']??0),'cbm'=>qd_num($it['cbm']??0),
    'item_json'=>json_encode($it,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
  ];
}
function qd_row_has_order_info($r){
  return qd_s($r['customer_code']??'')!=='' || qd_s($r['product_code']??'')!=='' || qd_s($r['product_name']??'')!=='' || qd_s($r['specification']??'')!=='' || qd_s($r['size']??'')!=='' || qd_s($r['image']??'')!=='' || qd_num($r['qty']??0)>0;
}
function qd_order_item_rows(PDO $pdo,$order){
  $orderId=(int)($order['id']??0); $rows=[];
  if($orderId>0 && qd_table_exists($pdo,'quote_sales_order_items')){
    $db=qd_rows($pdo,'SELECT * FROM quote_sales_order_items WHERE order_id=? ORDER BY item_index,id',[$orderId]);
    foreach($db as $i=>$r){ $rows[]=qd_payload_to_doc_row($r,$i+1); }
  }
  if(!$rows){ foreach(qd_order_payload_items($order) as $i=>$it){ $rows[]=qd_payload_to_doc_row($it,$i+1); } }
  return $rows;
}
function qd_order_item_maps($orderRows){
  $byId=[];$byIndex=[];$seq=[];
  foreach($orderRows as $i=>$r){
    $seq[$i]=$r; $id=(int)($r['id']??0); if($id>0)$byId[$id]=$r; $oid=(int)($r['order_item_id']??0); if($oid>0)$byId[$oid]=$r; $idx=(int)($r['item_index']??($i+1)); if($idx>0)$byIndex[$idx]=$r;
  }
  return [$byId,$byIndex,$seq];
}
function qd_merge_doc_item($shipRow,$orderRow,$seqIndex=0){
  $shipRow=is_array($shipRow)?$shipRow:[]; $orderRow=is_array($orderRow)?$orderRow:[];
  $nested=qd_parse_item_json($shipRow); if($nested) $shipRow=array_replace_recursive($nested,$shipRow);
  $base=$orderRow;
  foreach(['customer_code','product_code','product_name','specification','size','color','image'] as $k){
    if(qd_s($shipRow[$k]??'')==='') $shipRow[$k]=$base[$k]??'';
  }
  foreach(['qty','unit_price','amount'] as $k){ if(qd_num($shipRow[$k]??0)<=0 && qd_num($base[$k]??0)>0) $shipRow[$k]=$base[$k]; }
  if((int)($shipRow['item_index']??0)<=0) $shipRow['item_index']=$base['item_index']??($seqIndex+1);
  if((int)($shipRow['order_item_id']??0)<=0) $shipRow['order_item_id']=$base['id']??($base['order_item_id']??0);
  if(qd_s($shipRow['item_json']??'')==='') $shipRow['item_json']=$base['item_json']??'';
  return qd_payload_to_doc_row($shipRow,$seqIndex+1);
}
function qd_build_document_items(PDO $pdo,$order,$shipmentItems){
  $orderRows=qd_order_item_rows($pdo,$order); list($byId,$byIndex,$seq)=qd_order_item_maps($orderRows);
  $out=[];
  foreach($shipmentItems as $i=>$sr){
    $oid=(int)($sr['order_item_id']??0); $idx=(int)($sr['item_index']??0);
    $base=$oid>0 && isset($byId[$oid]) ? $byId[$oid] : (($idx>0 && isset($byIndex[$idx])) ? $byIndex[$idx] : ($seq[$i]??[]));
    $row=qd_merge_doc_item($sr,$base,$i);
    if(qd_row_has_order_info($row)) $out[]=$row;
  }
  if(!$out){ foreach($orderRows as $i=>$r){ $row=qd_merge_doc_item([], $r, $i); if(qd_row_has_order_info($row)) $out[]=$row; } }
  // 如果出货批次是旧版本生成的空快照，直接回退订单快照，避免 PL/CI 空表。
  $rich=0; foreach($out as $r){ if(qd_s($r['product_name']??'')!=='' || qd_s($r['product_code']??'')!=='' || qd_s($r['specification']??'')!=='' || qd_s($r['size']??'')!=='' || qd_s($r['image']??'')!=='') $rich++; }
  if($rich===0 && $orderRows){ $out=[]; foreach($orderRows as $i=>$r){ $out[]=qd_merge_doc_item([], $r, $i); } }
  return $out;
}
function qd_total($rows,$key){$s=0;foreach($rows as $r)$s+=qd_num($r[$key]??0);return $s;}
function qd_print_style(){return '<style>
@font-face{font-family:"ARS MaquetteTr";src:url("assets/fonts/ARSMaqLigTr.otf") format("opentype");font-weight:300 900;font-style:normal;font-display:swap}
html,body,.paper,.paper *,.quote-table,.quote-table *,.terms,.terms *,.bank,.bank *,.bank-terms,.bank-terms *,.final-summary,.final-summary *,.final-sign,.final-sign *,.doc-table,.doc-table *,.box,.box *{font-family:"ARS MaquetteTr","Microsoft YaHei",Arial,sans-serif!important;}@page{size:A4 portrait;margin:0}*{box-sizing:border-box}body{margin:0;background:#eef2f7;color:#000;font-family:"ARS MaquetteTr","Microsoft YaHei",Arial,sans-serif}.toolbar{position:sticky;top:0;background:#fff;border-bottom:1px solid #d8dee9;padding:9px 14px;z-index:10;display:flex;justify-content:space-between;font-family:"ARS MaquetteTr","Microsoft YaHei",Arial,sans-serif}.toolbar button,.toolbar a{background:#111827;color:#fff;border:0;border-radius:8px;padding:8px 12px;text-decoration:none}.toolbar .gray{background:#eef2f7;color:#111827;border:1px solid #d4dbe7}.paper{width:210mm;min-height:297mm;background:#fff;margin:12px auto;padding:20mm 15mm 17mm;border:1px solid #cfd6e3;overflow:hidden}.top{display:grid;grid-template-columns:96mm 72mm;gap:12mm;align-items:start}.top>div:last-child{justify-self:end;width:72mm}.seller h1{font-size:16pt;margin:0 0 5mm;font-weight:400;line-height:1.12}.seller .txt,.buyer{font-size:9.2pt;line-height:1.25;white-space:pre-line}.buyer{margin-top:7mm}.brandstamp{text-align:center;color:#0000a0;font-weight:700;font-size:8.6pt;line-height:1.12;white-space:pre-line;min-height:9mm}.brandstamp:empty{min-height:9mm}.doc-title{text-align:center;font-size:15pt;font-weight:700;margin:2mm 0 4mm;line-height:1.15}.terms{width:72mm;border-collapse:collapse;font-size:8.2pt;table-layout:fixed}.terms td{border:1.25px solid #000;padding:1.1mm 2mm;height:4.9mm;vertical-align:middle}.terms td:first-child{font-weight:700;text-align:right;width:34mm}.terms td.doc-edit-cell{background:#fffdf7;outline:1px dashed #fbbf24;outline-offset:-3px}.terms td.doc-edit-cell:empty:before{content:attr(data-placeholder);color:#9ca3af}.doc-table{width:100%;border-collapse:collapse;table-layout:fixed;margin-top:10mm;font-size:6.4pt;line-height:1.05}.doc-table th,.doc-table td{border:1.25px solid #000;padding:.55mm .5mm;text-align:center;vertical-align:middle;overflow-wrap:anywhere}.doc-table th{font-weight:700;height:7.5mm}.doc-table .desc{text-align:left;white-space:pre-line;line-height:1.08}.doc-table td.pic-cell{overflow:hidden}.pl-img,.ci-img{display:block;margin:0 auto;width:auto;height:auto;max-width:12mm!important;max-height:12mm!important;object-fit:contain}.doc-table td.material-edit,.doc-table th.material-head-edit{background:#fffdf7;outline:1px dashed #fbbf24;outline-offset:-3px;min-height:7mm}.doc-table td.material-edit:empty:before{content:""}.summary{display:grid;grid-template-columns:1fr 1fr;gap:10mm;margin-top:8mm}.box{border:1.25px solid #000;padding:2.2mm;font-size:8.6pt;line-height:1.35;white-space:pre-line}.bank{background:#f2f2f2;padding:2.2mm;font-size:7.8pt;line-height:1.23;white-space:pre-line;margin-top:8mm}.nowrap{white-space:nowrap}@media print{.toolbar{display:none!important}body{background:#fff}.terms td.doc-edit-cell,.doc-table td.material-edit,.doc-table th.material-head-edit{background:#fff!important;outline:0!important}.terms td.doc-edit-cell:empty:before,.doc-table td.material-edit:empty:before{content:""!important}.paper{border:0;margin:0;width:210mm;min-height:297mm;page-break-after:auto}.doc-table thead{display:table-header-group}.doc-table tr{page-break-inside:avoid}}</style>';}

$shipmentId=(int)($_GET['shipment_id']??0);$type=($_GET['type']??'pl')==='ci'?'ci':'pl';$format=strtolower((string)($_GET['format']??'html'));if($shipmentId<=0){http_response_code(400);echo 'Missing shipment_id';exit;}
$ship=qd_row($pdo,'SELECT * FROM quote_shipments WHERE id=? LIMIT 1',[$shipmentId]);if(!$ship){http_response_code(404);echo 'Shipment not found';exit;}
$order=qd_row($pdo,'SELECT * FROM quote_sales_orders WHERE id=? LIMIT 1',[(int)$ship['order_id']]);if(!$order){http_response_code(404);echo 'Order not found';exit;}
$shipmentItems=qd_rows($pdo,'SELECT * FROM quote_shipment_items WHERE shipment_id=? ORDER BY item_index,id',[$shipmentId]);
$items=qd_build_document_items($pdo,$order,$shipmentItems);
$cartons=qd_rows($pdo,'SELECT * FROM quote_shipment_cartons WHERE shipment_id=? ORDER BY id',[$shipmentId]);
$settings=qd_settings($pdo);$customer=qd_customer_from_order($order);list($sellerName,$sellerText)=qd_header_seller($order,$settings);$docTitle=$type==='ci'?'COMMERCIAL INVOICE':'PACKING LIST';$docFileTitle=qd_doc_file_title($type,$order);$docNo=$type==='ci'?($ship['commercial_invoice_no']??''):($ship['packing_list_no']??'');if($docNo==='')$docNo=$docTitle.'-'.$shipmentId;
if($type==='ci' && qd_col_exists($pdo,'quote_shipments','ci_generated_at')){$pdo->prepare('UPDATE quote_shipments SET ci_generated_at=COALESCE(ci_generated_at,NOW()) WHERE id=?')->execute([$shipmentId]);}else if($type!=='ci' && qd_col_exists($pdo,'quote_shipments','pl_generated_at')){$pdo->prepare('UPDATE quote_shipments SET pl_generated_at=COALESCE(pl_generated_at,NOW()) WHERE id=?')->execute([$shipmentId]);}
if($format==='xls'||$format==='xlsx'||$format==='excel'){
  require_once __DIR__.'/quote_order_excel.php';
  if(function_exists('qoe_export_document_xlsx')){
    qoe_export_document_xlsx($type,$order,$ship,$items,$cartons,$settings,$customer,$sellerName,$sellerText,$docTitle,$docFileTitle);
  }
  exit;
}else{ header('Content-Type: text/html; charset=utf-8'); }
?><!doctype html><html><head><meta charset="utf-8"><title><?=qd_h($docFileTitle)?></title><?=qd_print_style()?></head><body>
<?php if(!($format==='xls'||$format==='xlsx'||$format==='excel')): ?><div class="toolbar"><b><?=qd_h($docFileTitle)?></b><div><button class="gray" onclick="history.back()">返回</button><button onclick="window.print()">打印 / 保存PDF</button></div></div><?php endif; ?>
<div class="paper">
  <div class="top">
    <div class="seller"><h1><?=qd_h($sellerName)?></h1><div class="txt"><?=qd_h($sellerText)?></div><div class="buyer"><b><?=qd_h($settings['buyer_label']??'Buyer / Consignee')?>:</b>
<?=qd_h(qd_customer_text($customer,$settings['buyer_label']??''))?></div><?php if(!empty($settings['show_notify_party']) && qd_s($settings['notify_party']??'')!==''): ?><div class="buyer"><b>Notify Party:</b>
<?=qd_h($settings['notify_party'])?></div><?php endif; ?></div>
    <div><div class="brandstamp"><?=qd_h(qd_header_stamp($order))?></div><div class="doc-title"><?=qd_h($docTitle)?></div><table class="terms"><?php foreach(qd_doc_terms($type,$order,$ship,$settings) as $r): $field=$r[2]??''; $editable=!empty($r[3]); $labelField=$r[4]??''; $labelEditable=!empty($r[5]); ?><tr><td<?=($labelEditable&&!($format==='xls'||$format==='xlsx'||$format==='excel'))?' class="doc-edit-cell" contenteditable="true" data-doc-edit="'.qd_h($labelField).'" data-placeholder="点击填写"':''?>><?=qd_h($r[0])?></td><td<?=($editable&&!($format==='xls'||$format==='xlsx'||$format==='excel'))?' class="doc-edit-cell" contenteditable="true" data-doc-edit="'.qd_h($field).'" data-placeholder="点击填写"':''?>><?=qd_h($r[1])?></td></tr><?php endforeach; ?></table></div>
  </div>
<?php if($type==='ci'): ?>
  <table class="doc-table"><colgroup><col style="width:8%"><col style="width:10%"><col style="width:11%"><col style="width:11%"><col style="width:33%"><col style="width:7%"><col style="width:6%"><col style="width:7%"><col style="width:7%"><col style="width:8%"></colgroup><thead><tr><th>Picture</th><th>Size</th><th>Customer<br>Model</th><th>Manufacturer<br>Code</th><th>Description</th><th>Color</th><th>QTY<br>(pcs)</th><th>Unit Price<br>(<?=qd_h($order['currency']??'USD')?>)</th><th>Amount<br>(<?=qd_h($order['currency']??'USD')?>)</th><th>HS Code</th></tr></thead><tbody>
  <?php foreach($items as $i=>$it): $img=qd_img_src($it); ?><tr><td class="pic-cell"><?php if($img!==''): ?><img class="ci-img" src="<?=qd_h($img)?>"><?php endif; ?></td><td><?=qd_h($it['size']??'')?></td><td><?=qd_h($it['customer_code']??'')?></td><td><?=qd_h($it['product_code']??'')?></td><td class="desc"><?=qd_h(qd_desc($it))?></td><td><?=qd_h($it['color']??'')?></td><td><?=qd_qty($it['qty']??0)?></td><td><?=qd_money($it['unit_price']??0)?></td><td><?=qd_money($it['amount']??0)?></td><td<?=($format==='xls'||$format==='xlsx'||$format==='excel')?'':' class="material-edit" contenteditable="true" data-doc-edit="hs_code_'.qd_h($i).'"'?>><?=qd_h($it['hs_code']??'')?></td></tr><?php endforeach; ?>
  <tr><td colspan="6"></td><td><b><?=qd_qty(qd_total($items,'qty'))?></b></td><td><b>Total:</b></td><td><b><?=qd_money(qd_total($items,'amount'))?></b></td><td></td></tr></tbody></table>
  <div class="summary"><div class="box"><b>Total Qty:</b> <?=qd_qty(qd_total($items,'qty'))?> PCS<br><b>Total Amount:</b> <?=qd_h($order['currency']??'USD')?> <?=qd_money(qd_total($items,'amount'))?><br><b>Currency:</b> <?=qd_h($order['currency']??'USD')?></div><div class="box"><b>Invoice No:</b> <?=qd_h($ship['commercial_invoice_no']??'')?><br><b>PI No.:</b> <?=qd_h(qd_order_no_at($order['order_no']??'',$order['quote_no']??''))?><br><b>Date:</b> <?=qd_h($ship['ship_date']??date('Y-m-d'))?></div></div>
  <?php /* V6.8.5.53: CI 不显示银行信息区 */ ?>
<?php else: ?>
  <table class="doc-table"><colgroup><col style="width:7%"><col style="width:10%"><col style="width:11%"><col style="width:31%"><col style="width:7%"><col style="width:6%"><col style="width:6%"><col style="width:4.8%"><col style="width:4.8%"><col style="width:4.8%"><col style="width:5.5%"><col style="width:5.5%"><col style="width:5.5%"><col style="width:5%"></colgroup><thead><tr><th>Picture</th><th>Customer<br>Model</th><th>Manufacturer<br>Code</th><th>Description</th><th>Color</th><th>QTY<br>(pcs)</th><th>PCS/<br>CTN</th><th>L<br>(cm)</th><th>W<br>(cm)</th><th>H<br>(cm)</th><th>CTNS</th><th>N.W.<br>(KG)</th><th>G.W.<br>(KG)</th><th>CBM</th></tr></thead><tbody>
  <?php foreach($items as $i=>$it): list($cl,$cw,$ch)=qd_carton_dims($it['carton_size']??''); $img=qd_img_src($it); ?><tr><td class="pic-cell"><?php if($img!==''): ?><img class="pl-img" src="<?=qd_h($img)?>"><?php endif; ?></td><td><?=qd_h($it['customer_code']??'')?></td><td><?=qd_h($it['product_code']??'')?></td><td class="desc"><?=qd_h(qd_desc($it))?></td><td><?=qd_h($it['color']??'')?></td><td><?=qd_qty($it['qty']??0)?></td><td><?=qd_qty($it['pcs_per_ctn']??0)?></td><td><?=qd_h($cl)?></td><td><?=qd_h($cw)?></td><td><?=qd_h($ch)?></td><td><?=qd_qty($it['cartons']??0)?></td><td><?=qd_qty($it['nw']??0,3)?></td><td><?=qd_qty($it['gw']??0,3)?></td><td><?=qd_qty($it['cbm']??0,4)?></td></tr><?php endforeach; ?>
  <tr><td colspan="5"></td><td><b><?=qd_qty(qd_total($items,'qty'))?></b></td><td></td><td colspan="3"><b>Total:</b></td><td><b><?=qd_qty(qd_total($items,'cartons'))?></b></td><td><b><?=qd_qty(qd_total($items,'nw'),3)?></b></td><td><b><?=qd_qty(qd_total($items,'gw'),3)?></b></td><td><b><?=qd_qty(qd_total($items,'cbm'),4)?></b></td></tr></tbody></table>
  <?php if($cartons): ?><div class="box" style="margin-top:8mm"><b>Carton Detail</b><br><?php foreach($cartons as $c): ?><?=qd_h(($c['carton_no']?:$c['carton_range']).'  '.$c['items_text'].'  QTY '.qd_qty($c['qty']).'  '.$c['carton_size'].'  N.W. '.qd_qty($c['nw'],3).'  G.W. '.qd_qty($c['gw'],3).'  CBM '.qd_qty($c['cbm'],4))?><br><?php endforeach; ?></div><?php endif; ?>
  <div class="summary"><div class="box"><b>Total Qty:</b> <?=qd_qty(qd_total($items,'qty'))?> PCS<br><b>Total Cartons:</b> <?=qd_qty(qd_total($items,'cartons'))?> CTNS<br><b>Total N.W.:</b> <?=qd_qty(qd_total($items,'nw'),3)?> KG<br><b>Total G.W.:</b> <?=qd_qty(qd_total($items,'gw'),3)?> KG<br><b>Total CBM:</b> <?=qd_qty(qd_total($items,'cbm'),4)?></div><div class="box"><b>Packing List No:</b> <?=qd_h($ship['packing_list_no']??'')?><br><b>PI No.:</b> <?=qd_h(qd_order_no_at($order['order_no']??'',$order['quote_no']??''))?><br><b>Date:</b> <?=qd_h($ship['ship_date']??date('Y-m-d'))?></div></div>
<?php endif; ?>
</div>

<?php if(!($format==='xls'||$format==='xlsx'||$format==='excel')): ?><script>
(function(){
  var prefix='artdon_ci_pl_edit_'+<?=json_encode((int)$shipmentId)?>+'_'+<?=json_encode($type)?>+'_';
  document.querySelectorAll('[data-doc-edit]').forEach(function(el){
    var key=prefix+el.getAttribute('data-doc-edit');
    try{var saved=localStorage.getItem(key); if(saved!==null) el.textContent=saved;}catch(e){}
    el.addEventListener('input',function(){try{localStorage.setItem(key,el.textContent.trim());}catch(e){}});
  });
})();
</script><?php endif; ?>
<?php if($format==='pdf'): ?><script>window.addEventListener('load',function(){setTimeout(function(){try{window.focus();window.print();}catch(e){}},450);});</script><?php endif; ?>
</body></html>
