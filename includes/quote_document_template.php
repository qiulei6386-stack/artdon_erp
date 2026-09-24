<?php
// Original PL/CI helpers. No bootstrap or side effects.
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
    qd_ensure_col($pdo,'quote_shipments','pl_status',"VARCHAR(30) DEFAULT 'active'");
    qd_ensure_col($pdo,'quote_shipments','ci_status',"VARCHAR(30) DEFAULT 'active'");
    qd_ensure_col($pdo,'quote_shipments','pl_voided_at','DATETIME NULL');
    qd_ensure_col($pdo,'quote_shipments','ci_voided_at','DATETIME NULL');
    qd_ensure_col($pdo,'quote_shipments','pl_voided_by',"VARCHAR(120) DEFAULT ''");
    qd_ensure_col($pdo,'quote_shipments','ci_voided_by',"VARCHAR(120) DEFAULT ''");
    qd_ensure_col($pdo,'quote_shipments','pl_void_reason',"VARCHAR(500) DEFAULT ''");
    qd_ensure_col($pdo,'quote_shipments','ci_void_reason',"VARCHAR(500) DEFAULT ''");
    qd_ensure_col($pdo,'quote_shipments','pl_deleted_at','DATETIME NULL');
    qd_ensure_col($pdo,'quote_shipments','ci_deleted_at','DATETIME NULL');
    qd_ensure_col($pdo,'quote_shipments','pl_deleted_by',"VARCHAR(120) DEFAULT ''");
    qd_ensure_col($pdo,'quote_shipments','ci_deleted_by',"VARCHAR(120) DEFAULT ''");
    qd_ensure_col($pdo,'quote_shipments','pl_delete_reason',"VARCHAR(500) DEFAULT ''");
    qd_ensure_col($pdo,'quote_shipments','ci_delete_reason',"VARCHAR(500) DEFAULT ''");
    qd_ensure_col($pdo,'quote_shipments','forwarder',"VARCHAR(255) DEFAULT ''");
  }
}
function qd_order_no_at($orderNo,$quoteNo=''){ $s=trim((string)($orderNo?:$quoteNo)); if($s==='')return ''; return preg_replace('/^SO-/i','AT-',$s); }
function qd_safe_file($s,$ext){
  $s=trim((string)$s);
  $s=preg_replace('#[\\/:*?"<>|]+#',' ', $s);
  $s=preg_replace('/\s+/',' ', $s);
  $s=trim($s, " ._-\t\n");
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
function qd_settings(PDO $pdo){$d=qd_default_doc_settings();if(qd_table_exists($pdo,'quote_document_settings')&&qd_col_exists($pdo,'quote_document_settings','settings_json')){$r=qd_row($pdo,'SELECT settings_json FROM quote_document_settings WHERE id=1 LIMIT 1');if($r){$x=qd_json($r['settings_json'],[]);if($x)$d=array_replace_recursive($d,$x);}}return $d;}
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
  $orderNo=qd_s($order['_shipment_order_refs']??''); if($orderNo==='') $orderNo=qd_order_no_at($order['order_no']??'',$order['quote_no']??'');
  if($type==='ci'){
    list($pay1,$pay2)=qd_payment_terms($order);
    $shippingMark=qd_s($ship['shipping_mark']??'');
    $forwarder=qd_s($ship['forwarder']??($settings['forwarder']??''));
    return [
      ['Date:', $ship['_document_date']??date('Y-m-d')],
      ['PI No.:', $orderNo],
      ['Payment:', $pay1],
      ['', $pay2],
      ['Price Terms:', $ship['_price_terms']??'', 'ci_price_terms', true],
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
  // The image has its own output field; never embed it again in nested JSON snapshots.
  unset($it['item_json'],$it['image'],$it['product_image'],$it['image_url']);
  if(isset($it['product'])&&is_array($it['product']))foreach(['image','image_display','product_image','main_image','image_url'] as $key)unset($it['product'][$key]);
  return [
    'id'=>$it['id']??0,'order_id'=>(int)($it['order_id']??0),'order_no'=>qd_order_no_at($it['order_no']??'',$it['quote_no']??''),'quote_no'=>qd_s($it['quote_no']??''),
    'order_item_id'=>$it['order_item_id']??($it['id']??0),'item_index'=>(int)($it['item_index']??$idx),
    'customer_code'=>$customerCode,'product_code'=>$productCode,'product_name'=>$productName,'specification'=>$spec,'size'=>$size,
    'color'=>qd_item_text($it,['color']) ?: qd_item_text($p,['color']),
    'hs_code'=>qd_item_text($it,['hs_code']) ?: qd_item_text($p,['hs_code']),
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
  $orderRef=qd_order_no_at($order['order_no']??'',$order['quote_no']??'');
  if($orderId>0 && qd_table_exists($pdo,'quote_sales_order_items')){
    $db=qd_rows($pdo,'SELECT '.qr_item_columns($pdo,'quote_sales_order_items','',true,false).' FROM quote_sales_order_items WHERE order_id=? ORDER BY item_index,id',[$orderId]);
    foreach($db as $i=>$r){ $r['order_no']=$orderRef; $r['quote_no']=$order['quote_no']??''; $rows[]=qd_payload_to_doc_row($r,$i+1); }
  }
  if(!$rows){
    // Only legacy orders without row records need their one original payload.
    if($orderId>0 && !isset($order['items_json'])){$legacy=qd_row($pdo,"SELECT items_json,snapshot_json FROM quote_sales_orders WHERE id=? AND OCTET_LENGTH(COALESCE(items_json,''))+OCTET_LENGTH(COALESCE(snapshot_json,''))<=8388608",[$orderId]);if(!$legacy)throw new RuntimeException('历史订单缺少结构化明细且快照过大，请先核对恢复明细；未修改历史数据');$order=array_merge($order,$legacy);}
    foreach(qd_order_payload_items($order) as $i=>$it){ $it['order_id']=$orderId; $it['order_no']=$orderRef; $it['quote_no']=$order['quote_no']??''; $rows[]=qd_payload_to_doc_row($it,$i+1); }
  }
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
  foreach(['qty','unit_price','amount'] as $k){ if(!array_key_exists($k,$shipRow) && array_key_exists($k,$base)) $shipRow[$k]=$base[$k]; }
  foreach(['order_id','order_no','quote_no'] as $k){ if(qd_s($shipRow[$k]??'')==='' && qd_s($base[$k]??'')!=='') $shipRow[$k]=$base[$k]; }
  if((int)($shipRow['item_index']??0)<=0) $shipRow['item_index']=$base['item_index']??($seqIndex+1);
  if((int)($shipRow['order_item_id']??0)<=0) $shipRow['order_item_id']=$base['id']??($base['order_item_id']??0);
  if(qd_s($shipRow['item_json']??'')==='') $shipRow['item_json']=$base['item_json']??'';
  return qd_payload_to_doc_row($shipRow,$seqIndex+1);
}
function qd_build_document_items(PDO $pdo,$order,$shipmentItems){
  $orderRows=qd_order_item_rows($pdo,$order); list($byId,$byIndex,$seq)=qd_order_item_maps($orderRows);
  $out=[];
  foreach($shipmentItems as $i=>$sr){
    if(array_key_exists('qty',$sr) && qd_num($sr['qty'])<=0) continue;
    $oid=(int)($sr['order_item_id']??0); $idx=(int)($sr['item_index']??0);
    $base=$oid>0 && isset($byId[$oid]) ? $byId[$oid] : (($idx>0 && isset($byIndex[$idx])) ? $byIndex[$idx] : ($seq[$i]??[]));
    $row=qd_merge_doc_item($sr,$base,$i);
    if(empty($row['image'])&&$oid>0)$row['image']=qsd_item_thumbnail($pdo,$oid,'quote_sales_order_items');
    if(qd_row_has_order_info($row)) $out[]=$row;
  }
  if(!$out && !$shipmentItems){ foreach($orderRows as $i=>$r){ $row=qd_merge_doc_item([], $r, $i); if(qd_row_has_order_info($row)) $out[]=$row; } }
  // 如果出货批次是旧版本生成的空快照，直接回退订单快照，避免 PL/CI 空表。
  $rich=0; foreach($out as $r){ if(qd_s($r['product_name']??'')!=='' || qd_s($r['product_code']??'')!=='' || qd_s($r['specification']??'')!=='' || qd_s($r['size']??'')!=='' || qd_s($r['image']??'')!=='') $rich++; }
  if($rich===0 && $orderRows && !$shipmentItems){ $out=[]; foreach($orderRows as $i=>$r){ $out[]=qd_merge_doc_item([], $r, $i); } }
  return $out;
}
function qd_ci_item_group_key($row,$seq=0){
  $oid=(int)($row['order_item_id']??0); if($oid>0) return 'order_item:'.$oid;
  $source=qd_s($row['order_id']??'') ?: qd_s($row['order_no']??'');
  $idx=(int)($row['item_index']??0); $customer=qd_s($row['customer_code']??''); $product=qd_s($row['product_code']??'');
  if($idx>0 && ($customer!=='' || $product!=='')) return 'source:'.$source.'|item_index:'.$idx.'|customer:'.$customer.'|product:'.$product;
  $name=qd_s($row['product_name']??''); $spec=qd_s($row['specification']??''); $color=qd_s($row['color']??'');
  if($customer!=='' || $product!=='' || $name!=='' || $spec!=='' || $color!=='') return 'source:'.$source.'|signature:'.$customer.'|'.$product.'|'.$name.'|'.$spec.'|'.$color;
  return 'row:'.$seq;
}
function qd_build_ci_items($items){
  $groups=[]; $order=[];
  foreach($items as $i=>$row){
    if(!is_array($row)) continue; $key=qd_ci_item_group_key($row,$i);
    if(!isset($groups[$key])){ $groups[$key]=$row; $groups[$key]['qty']=0; $groups[$key]['amount']=0; $groups[$key]['_ci_merged_count']=0; $order[]=$key; }
    $qty=qd_num($row['qty']??0); $unit=qd_num($row['unit_price']??0); $amount=qd_num($row['amount']??0); if($amount<=0 && $qty>0 && $unit>0) $amount=round($qty*$unit,2);
    $groups[$key]['qty']=qd_num($groups[$key]['qty']??0)+$qty; $groups[$key]['amount']=qd_num($groups[$key]['amount']??0)+$amount; $groups[$key]['_ci_merged_count']=(int)($groups[$key]['_ci_merged_count']??0)+1;
    foreach(['customer_code','product_code','product_name','specification','size','color','image','item_json','hs_code'] as $field){ if(qd_s($groups[$key][$field]??'')==='' && qd_s($row[$field]??'')!=='') $groups[$key][$field]=$row[$field]; }
  }
  $out=[];
  foreach($order as $idx=>$key){
    $row=$groups[$key]; $qty=qd_num($row['qty']??0); $amount=qd_num($row['amount']??0); if($qty>0 && $amount>0) $row['unit_price']=round($amount/$qty,4); $row['amount']=round($amount,2); $row['item_index']=$idx+1;
    foreach(['pcs_per_ctn','cartons','carton_size','nw','gw','cbm','_carton_group','_carton_rowspan','_carton_first','_carton_skip_pack'] as $field) unset($row[$field]);
    if(qd_row_has_order_info($row)) $out[]=$row;
  }
  return $out;
}
function qd_shipment_order_refs($items,$order){
  $seen=[]; $out=[];
  foreach($items as $it){
    $ref=qd_s($it['order_no']??''); if($ref==='') $ref=qd_order_no_at($it['order_no']??'',$it['quote_no']??'');
    if($ref!=='' && empty($seen[$ref])){ $seen[$ref]=1; $out[]=$ref; }
  }
  if(!$out){ $ref=qd_order_no_at($order['order_no']??'',$order['quote_no']??''); if($ref!=='') $out[]=$ref; }
  return implode(', ',$out);
}
function qd_total($rows,$key){$s=0;foreach($rows as $r)$s+=qd_num($r[$key]??0);return $s;}
function qd_carton_has_detail($c){foreach(['carton_no','carton_range','items_text','qty','carton_size','nw','gw','cbm','note'] as $k){if(trim((string)($c[$k]??''))!=='')return true;}return false;}
function qd_carton_count($c){$n=qd_num($c['carton_count']??1);return $n>0?$n:1;}
function qd_carton_count_total($cartons){$s=0;foreach($cartons as $c){if(qd_carton_has_detail($c))$s+=qd_carton_count($c);}return $s;}
function qd_packing_total($items,$cartons,$key){
  $base=qd_total($items,$key);
  if($key==='qty') return $base;
  if($key==='cartons') return $base+qd_carton_count_total($cartons);
  if(in_array($key,['nw','gw','cbm'],true)) return $base+qd_total($cartons,$key);
  return $base;
}
function qd_carton_qty_parts($text,$fallbackQty=0){
  $parts=[];
  if(preg_match('/((?:\d+(?:\.\d+)?\s*\+\s*)+\d+(?:\.\d+)?)\s*pcs\b/i',(string)$text,$m)){
    foreach(preg_split('/\s*\+\s*/',$m[1]) as $p){ $n=qd_num($p); if($n>0)$parts[]=$n; }
  }
  if(!$parts && qd_num($fallbackQty)>0) $parts[]=(float)$fallbackQty;
  return $parts;
}
function qd_carton_pl_rows($cartons,$items=[]){
  $rows=[];
  foreach($cartons as $ci=>$c){
    if(!qd_carton_has_detail($c)) continue;
    $ctns=qd_carton_count($c);$qty=qd_num($c['qty']??0);
    // Free-text carton descriptions cannot identify an order item reliably.
    // Keep the complete description, and never invent a product/model from its
    // first word or add its contents to the product quantities a second time.
    $text=qd_s($c['items_text']??'');
    $rows[]=[
      'customer_code'=>'','product_code'=>'','product_name'=>'',
      'specification'=>"Mixed carton — contents included in product rows above\n".$text,
      'color'=>'','qty'=>0,'pcs_per_ctn'=>$ctns>0?$qty/$ctns:0,
      'cartons'=>$ctns,'carton_size'=>qd_s($c['carton_size']??''),
      'nw'=>qd_num($c['nw']??0),'gw'=>qd_num($c['gw']??0),'cbm'=>qd_num($c['cbm']??0),
      'image'=>'','_carton_group'=>'packing_'.$ci,'_carton_rowspan'=>1,
      '_carton_first'=>true,'_carton_skip_pack'=>false,'_packing_only'=>true,
    ];
  }
  return $rows;
}
function qd_print_style(){return '<style>
@font-face{font-family:"ARS MaquetteTr";src:url("assets/fonts/ARSMaqLigTr.otf") format("opentype");font-weight:300 900;font-style:normal;font-display:swap}
html,body,.paper,.paper *,.quote-table,.quote-table *,.terms,.terms *,.bank,.bank *,.bank-terms,.bank-terms *,.final-summary,.final-summary *,.final-sign,.final-sign *,.doc-table,.doc-table *,.box,.box *{font-family:"ARS MaquetteTr","Microsoft YaHei",Arial,sans-serif!important;}@page{size:A4 portrait;margin:20mm 15mm 17mm}*{box-sizing:border-box}body{margin:0;background:#eef2f7;color:#000;font-family:"ARS MaquetteTr","Microsoft YaHei",Arial,sans-serif}.toolbar{position:sticky;top:0;background:#fff;border-bottom:1px solid #d8dee9;padding:9px 14px;z-index:10;display:flex;justify-content:space-between;font-family:"ARS MaquetteTr","Microsoft YaHei",Arial,sans-serif}.toolbar button,.toolbar a{background:#111827;color:#fff;border:0;border-radius:8px;padding:8px 12px;text-decoration:none}.toolbar .gray{background:#eef2f7;color:#111827;border:1px solid #d4dbe7}.paper{position:relative;width:210mm;min-height:297mm;background:#fff;margin:12px auto;padding:20mm 15mm 17mm;border:1px solid #cfd6e3;overflow:hidden}.doc-void-watermark{position:absolute;left:19mm;right:19mm;top:128mm;text-align:center;font-size:44pt;font-weight:800;letter-spacing:6px;color:rgba(220,38,38,.13);transform:rotate(-24deg);z-index:0;pointer-events:none}.doc-void-note{position:relative;z-index:1;margin:-8mm 0 5mm;border:1px solid #fecaca;background:#fff1f2;color:#991b1b;border-radius:8px;padding:2mm 3mm;font-size:8.4pt}.top,.doc-table,.summary,.box,.bank{position:relative;z-index:1}.top{display:grid;grid-template-columns:96mm 72mm;gap:12mm;align-items:start}.top>div:last-child{justify-self:end;width:72mm}.seller h1{font-size:16pt;margin:0 0 5mm;font-weight:400;line-height:1.12}.seller .txt,.buyer{font-size:9.2pt;line-height:1.25;white-space:pre-line}.buyer{margin-top:7mm}.brandstamp{text-align:center;color:#0000a0;font-weight:700;font-size:8.6pt;line-height:1.12;white-space:pre-line;min-height:9mm}.brandstamp:empty{min-height:9mm}.doc-title{text-align:center;font-size:15pt;font-weight:700;margin:2mm 0 4mm;line-height:1.15}.terms{width:72mm;border-collapse:collapse;font-size:8.2pt;table-layout:fixed}.terms td{border:1.25px solid #000;padding:1.1mm 2mm;height:4.9mm;vertical-align:middle}.terms td:first-child{font-weight:700;text-align:right;width:34mm}.terms td.doc-edit-cell{background:#fffdf7;outline:1px dashed #fbbf24;outline-offset:-3px}.terms td.doc-edit-cell:empty:before{content:attr(data-placeholder);color:#9ca3af}.doc-table{width:100%;border-collapse:collapse;table-layout:fixed;margin-top:10mm;font-size:6.4pt;line-height:1.05}.doc-table th,.doc-table td{border:1.25px solid #000;padding:.55mm .5mm;text-align:center;vertical-align:middle;overflow-wrap:anywhere}.doc-table th{font-weight:700;height:7.5mm}.doc-table .desc{text-align:left;white-space:pre-line;line-height:1.08}.doc-table td.pic-cell{overflow:hidden}.pl-img,.ci-img{display:block;margin:0 auto;width:auto;height:auto;max-width:12mm!important;max-height:12mm!important;object-fit:contain}.doc-table td.material-edit,.doc-table th.material-head-edit{background:#fffdf7;outline:1px dashed #fbbf24;outline-offset:-3px;min-height:7mm}.doc-table td.material-edit:empty:before{content:""}.summary{display:grid;grid-template-columns:1fr 1fr;gap:10mm;margin-top:8mm}.box{border:1.25px solid #000;padding:2.2mm;font-size:8.6pt;line-height:1.35;white-space:pre-line}.bank{background:#f2f2f2;padding:2.2mm;font-size:7.8pt;line-height:1.23;white-space:pre-line;margin-top:8mm}.nowrap{white-space:nowrap}@media print{.toolbar{display:none!important}body{background:#fff}.terms td.doc-edit-cell,.doc-table td.material-edit,.doc-table th.material-head-edit{background:#fff!important;outline:0!important}.terms td.doc-edit-cell:empty:before,.doc-table td.material-edit:empty:before{content:""!important}.paper{border:0;margin:0;padding:0;width:auto;min-height:0;page-break-after:auto}.summary,.box{break-inside:avoid}.doc-table thead{display:table-header-group}.doc-table tr{page-break-inside:avoid}}</style>';}
