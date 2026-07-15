<?php
/* ARTDON_QUOTE_ORDER_API_V6_8_5_31 CI/PL forwarder schema */
if (file_exists(__DIR__.'/includes/artdon_sso_core.php')) {
  require_once __DIR__.'/includes/artdon_sso_core.php';
  if (function_exists('artdon_sso_require_api')) artdon_sso_require_api('quote');
}
require_once __DIR__ . '/includes/bootstrap.php';
if (session_status() === PHP_SESSION_NONE) { @session_name('ARTDON_SYS'); @session_start(); }
header('Content-Type: application/json; charset=utf-8');

$pdo = db();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

function qo_ok($data=[]){ echo json_encode(['ok'=>true,'data'=>$data], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function qo_fail($msg){ echo json_encode(['ok'=>false,'msg'=>$msg], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function qo_input(){ static $j=null; if($j!==null) return $j; $raw=file_get_contents('php://input'); $a=json_decode((string)$raw,true); $j=is_array($a)?$a:($_POST?:[]); return $j; }
function qo_s($v,$max=5000){ $s=trim((string)($v ?? '')); if($max>0){ if(function_exists('mb_strlen') && mb_strlen($s,'UTF-8')>$max) $s=mb_substr($s,0,$max,'UTF-8'); elseif(!function_exists('mb_strlen') && strlen($s)>$max) $s=substr($s,0,$max); } return $s; }
function qo_json($v,$def=[]){ if(is_array($v)) return $v; $a=json_decode((string)$v,true); return is_array($a)?$a:$def; }
function qo_num($v){ return is_numeric($v)?(float)$v:0.0; }
function qo_table_exists(PDO $pdo,$t){ try{$s=$pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');$s->execute([$t]);return (bool)$s->fetchColumn();}catch(Throwable $e){return false;} }
function qo_columns(PDO $pdo,$t){ if(!qo_table_exists($pdo,$t))return []; try{$s=$pdo->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');$s->execute([$t]);return array_map(function($r){return $r['COLUMN_NAME'];},$s->fetchAll(PDO::FETCH_ASSOC));}catch(Throwable $e){return [];} }
function qo_col_exists(PDO $pdo,$t,$c){ return in_array($c,qo_columns($pdo,$t),true); }
function qo_ensure_col(PDO $pdo,$t,$c,$ddl){ if(!qo_table_exists($pdo,$t))return; if(qo_col_exists($pdo,$t,$c))return; try{$pdo->exec('ALTER TABLE `'.$t.'` ADD COLUMN `'.$c.'` '.$ddl);}catch(Throwable $e){ $m=$e->getMessage(); if(stripos($m,'Duplicate')===false && stripos($m,'1060')===false) throw $e; } }
function qo_rows(PDO $pdo,$sql,$p=[]){ $s=$pdo->prepare($sql);$s->execute($p);return $s->fetchAll(PDO::FETCH_ASSOC); }
function qo_row(PDO $pdo,$sql,$p=[]){ $s=$pdo->prepare($sql);$s->execute($p);$r=$s->fetch(PDO::FETCH_ASSOC);return $r?:null; }
function qo_today(){ return date('Y-m-d'); }
function qo_now(){ return date('Y-m-d H:i:s'); }
function qo_order_no_at($orderNo,$quoteNo=''){ $s=trim((string)($orderNo?:$quoteNo)); if($s==='')return ''; return preg_replace('/^SO-/i','AT-',$s); }
function qo_safe_no($s){ $s=qo_order_no_at($s); $s=preg_replace('/[^A-Za-z0-9_\-]+/','_',trim($s)); return $s ?: 'DOC'; }
function qo_actor(){ $u=$_SESSION['quote_user']['username'] ?? ($_SESSION['artdon_username'] ?? ($_SESSION['username'] ?? 'system')); return (string)$u; }
function qo_actor_user_id(): int { return (int)($_SESSION['artdon_user_id'] ?? $_SESSION['crm_user_id'] ?? 0); }
function qo_crm_user_ids_by_names(PDO $pdo, array $names): array {
  $names=array_values(array_unique(array_filter(array_map(function($v){ return trim((string)$v); },$names),function($v){ return $v!=='' && $v!=='0'; })));
  if(!$names || !qo_table_exists($pdo,'crm_users')) return [];
  $cols=qo_columns($pdo,'crm_users');
  if(!in_array('id',$cols,true)) return [];
  $matchCols=array_values(array_intersect(['username','user_name','name','real_name','english_name','email'], $cols));
  if(!$matchCols) return [];
  $where=[]; $params=[];
  foreach($matchCols as $c){
    $where[]='`'.$c.'` IN ('.implode(',',array_fill(0,count($names),'?')).')';
    foreach($names as $n) $params[]=$n;
  }
  try{
    $rs=qo_rows($pdo,'SELECT id FROM crm_users WHERE '.implode(' OR ',$where),$params);
    return array_values(array_unique(array_map('intval',array_column($rs,'id'))));
  }catch(Throwable $e){ return []; }
}
function qo_quote_notification_recipients(PDO $pdo, array $order): array {
  $names=[qo_actor(), $order['user_name']??'', $order['sales']??'', $order['owner']??'', $order['created_by']??'', $order['updated_by']??''];
  if(!empty($order['source_quote_id']) && qo_table_exists($pdo,'quote_orders')){
    try{
      $q=qo_row($pdo,'SELECT user_name,submitted_by,created_by FROM quote_orders WHERE id=? LIMIT 1',[(int)$order['source_quote_id']]);
      if($q) $names=array_merge($names,[(string)($q['user_name']??''),(string)($q['submitted_by']??''),(string)($q['created_by']??'')]);
    }catch(Throwable $e){}
  }
  $ids=qo_crm_user_ids_by_names($pdo,$names);
  $actorId=qo_actor_user_id();
  if(!$ids && $actorId>0) $ids=[$actorId];
  return array_values(array_unique(array_map('intval',$ids)));
}
function qo_push_quote_sys_notification(PDO $pdo, string $type, string $title, string $content, array $payload, array $order=[]): void {
  if(!function_exists('create_system_notification')) return;
  $payload=array_merge([
    'module'=>'quote',
    'source_module'=>(string)($payload['source_module']??'quote_order_api'),
    'target_module'=>'quote',
    'target_url'=>(string)($payload['target_url']??'quotation.php'),
    'created_by'=>qo_actor_user_id(),
  ],$payload);
  foreach(qo_quote_notification_recipients($pdo,$order) as $uid){
    if($uid>0) create_system_notification($uid,$type,$title,$content,$payload);
  }
}
function qo_complete_quote_followup_tasks(PDO $pdo, array $order): void {
  if(!qo_table_exists($pdo,'crm_tasks')) return;
  $sourceId=(string)((int)($order['source_quote_id']??0) ?: '');
  $quoteNo=trim((string)($order['quote_no']??''));
  $where=[]; $params=[];
  if($sourceId!==''){ $where[]="(source_type='quote' AND source_id=?)"; $params[]=$sourceId; }
  if($quoteNo!==''){ $where[]='quote_id=?'; $params[]=$quoteNo; }
  if(!$where) return;
  try{
    $pdo->prepare("UPDATE crm_tasks SET status='done', result='已转订单', result_note=CONCAT(COALESCE(result_note,''), IF(COALESCE(result_note,'')='', '', '\n'), '报价已转订单：', ?), completed_at=COALESCE(completed_at,NOW()), completed_by=?, updated_at=NOW() WHERE task_type='quote_followup' AND status NOT IN ('done','closed','cancelled') AND deleted_at IS NULL AND (".implode(' OR ',$where).")")
      ->execute(array_merge([(string)($order['order_no']??''), qo_actor_user_id() ?: null], $params));
  }catch(Throwable $e){}
}

function qo_default_doc_settings(){
  return [
    'seller_name'=>'Artdon Lighting Limited',
    'seller_text'=>'Artdon Lighting Limited'."\n".'Zhongshan, Guangdong, China',
    'buyer_label'=>'Buyer / Consignee','notify_party'=>'','signature_company'=>'Artdon Lighting Limited',
    'footer_note'=>'All information is generated from the confirmed shipment batch. Packing List and Commercial Invoice use the same shipment quantity.',
    'country_origin'=>'China','port_loading'=>'Zhongshan','ship_method'=>'','pl_prefix'=>'PL','ci_prefix'=>'CI','shipment_prefix'=>'SHP','date_format'=>'ymd',
    'show_bank_on_ci'=>1,'show_notify_party'=>0,'forwarder'=>'',
    'pl_columns'=>['carton_no'=>1,'customer_code'=>1,'model'=>1,'description'=>1,'qty'=>1,'pcs_per_ctn'=>1,'cartons'=>1,'carton_size'=>1,'nw'=>1,'gw'=>1,'cbm'=>1,'remark'=>0],
    'ci_columns'=>['customer_code'=>1,'model'=>1,'description'=>1,'qty'=>1,'unit_price'=>1,'amount'=>1,'hs_code'=>0,'origin'=>0]
  ];
}
function qo_doc_settings(PDO $pdo){
  qo_ensure_schema($pdo);
  $r=qo_row($pdo,'SELECT settings_json FROM quote_document_settings WHERE id=1 LIMIT 1');
  $d=$r?qo_json($r['settings_json'],[]):[];
  return array_replace_recursive(qo_default_doc_settings(),$d);
}
function qo_save_doc_settings(PDO $pdo,$d){
  qo_ensure_schema($pdo);
  $settings=array_replace_recursive(qo_default_doc_settings(),is_array($d)?$d:[]);
  $js=json_encode($settings,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  $st=$pdo->prepare('INSERT INTO quote_document_settings(id,settings_json,updated_by,updated_at) VALUES(1,?,?,NOW()) ON DUPLICATE KEY UPDATE settings_json=VALUES(settings_json),updated_by=VALUES(updated_by),updated_at=NOW()');
  $st->execute([$js,qo_actor()]);
  return $settings;
}
function qo_ensure_schema(PDO $pdo){
  static $schemaDone=false;
  $schemaKey='quote_order_schema_checked_v68544';
  if($schemaDone || !empty($_SESSION[$schemaKey])){ $schemaDone=true; return; }
  $pdo->exec("CREATE TABLE IF NOT EXISTS quote_sales_orders (id INT AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $orderCols=[
    'order_no'=>'VARCHAR(120) DEFAULT \'\'','quote_no'=>'VARCHAR(120) DEFAULT \'\'','source_quote_id'=>'INT DEFAULT 0','customer_id'=>'VARCHAR(120) DEFAULT \'\'','customer_name'=>'VARCHAR(255) DEFAULT \'\'','customer_json'=>'MEDIUMTEXT NULL',
    'header_json'=>'MEDIUMTEXT NULL','bank_json'=>'MEDIUMTEXT NULL','template_json'=>'MEDIUMTEXT NULL','items_json'=>'LONGTEXT NULL','snapshot_json'=>'LONGTEXT NULL',
    'qty'=>'DECIMAL(14,3) DEFAULT 0','amount'=>'DECIMAL(14,2) DEFAULT 0','currency'=>'VARCHAR(20) DEFAULT \'USD\'','exchange_rate'=>'DECIMAL(12,4) DEFAULT 1',
    'quote_date'=>'DATE NULL','order_date'=>'DATE NULL','status'=>'VARCHAR(80) DEFAULT \'待确认\'','shipment_status'=>'VARCHAR(80) DEFAULT \'未出货\'','payment_status'=>'VARCHAR(80) DEFAULT \'未收款\'',
    'paid_amount'=>'DECIMAL(14,2) DEFAULT 0','balance_amount'=>'DECIMAL(14,2) DEFAULT 0','order_doc_title'=>'VARCHAR(120) DEFAULT \'\'','contract_title'=>'VARCHAR(120) DEFAULT \'\'',
    'note'=>'TEXT NULL','user_name'=>'VARCHAR(120) DEFAULT \'\'','created_by'=>'VARCHAR(120) DEFAULT \'\'','updated_by'=>'VARCHAR(120) DEFAULT \'\'','created_at'=>'DATETIME DEFAULT CURRENT_TIMESTAMP','updated_at'=>'DATETIME NULL'
  ];
  foreach($orderCols as $c=>$ddl) qo_ensure_col($pdo,'quote_sales_orders',$c,$ddl);
  try{$pdo->exec('ALTER TABLE quote_sales_orders ADD KEY idx_order_no(order_no)');}catch(Throwable $e){}
  try{$pdo->exec('ALTER TABLE quote_sales_orders ADD KEY idx_quote_no(quote_no)');}catch(Throwable $e){}
  try{$pdo->exec('ALTER TABLE quote_sales_orders ADD KEY idx_order_date_id(order_date,id)');}catch(Throwable $e){}
  try{$pdo->exec('ALTER TABLE quote_sales_orders ADD KEY idx_order_status(status)');}catch(Throwable $e){}

  $pdo->exec("CREATE TABLE IF NOT EXISTS quote_sales_order_items (id INT AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $itemCols=[
    'order_id'=>'INT NOT NULL DEFAULT 0','item_index'=>'INT DEFAULT 0','customer_code'=>'VARCHAR(120) DEFAULT \'\'','product_code'=>'VARCHAR(160) DEFAULT \'\'','product_name'=>'VARCHAR(255) DEFAULT \'\'',
    'specification'=>'MEDIUMTEXT NULL','color'=>'VARCHAR(120) DEFAULT \'\'','qty'=>'DECIMAL(14,3) DEFAULT 0','unit_price'=>'DECIMAL(14,4) DEFAULT 0','amount'=>'DECIMAL(14,2) DEFAULT 0','shipped_qty'=>'DECIMAL(14,3) DEFAULT 0','image'=>'LONGTEXT NULL','item_json'=>'LONGTEXT NULL','created_at'=>'DATETIME DEFAULT CURRENT_TIMESTAMP'
  ];
  foreach($itemCols as $c=>$ddl) qo_ensure_col($pdo,'quote_sales_order_items',$c,$ddl);
  try{$pdo->exec('ALTER TABLE quote_sales_order_items ADD KEY idx_order_id(order_id)');}catch(Throwable $e){}

  $pdo->exec("CREATE TABLE IF NOT EXISTS quote_packaging_profiles (id INT AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $packCols=[
    'product_code'=>'VARCHAR(160) DEFAULT \'\'','product_name'=>'VARCHAR(255) DEFAULT \'\'','customer_code'=>'VARCHAR(120) DEFAULT \'\'','unit_nw'=>'DECIMAL(14,3) DEFAULT 0','unit_gw'=>'DECIMAL(14,3) DEFAULT 0','pcs_per_ctn'=>'DECIMAL(14,3) DEFAULT 0','carton_l'=>'DECIMAL(14,2) DEFAULT 0','carton_w'=>'DECIMAL(14,2) DEFAULT 0','carton_h'=>'DECIMAL(14,2) DEFAULT 0','carton_size'=>'VARCHAR(160) DEFAULT \'\'','carton_nw'=>'DECIMAL(14,3) DEFAULT 0','carton_gw'=>'DECIMAL(14,3) DEFAULT 0','carton_cbm'=>'DECIMAL(14,4) DEFAULT 0','packing_method'=>'VARCHAR(255) DEFAULT \'\'','note'=>'TEXT NULL','created_at'=>'DATETIME DEFAULT CURRENT_TIMESTAMP','updated_at'=>'DATETIME NULL'
  ];
  foreach($packCols as $c=>$ddl) qo_ensure_col($pdo,'quote_packaging_profiles',$c,$ddl);
  try{$pdo->exec('ALTER TABLE quote_packaging_profiles ADD KEY idx_pack_product(product_code,customer_code)');}catch(Throwable $e){}

  $pdo->exec("CREATE TABLE IF NOT EXISTS quote_order_payments (id INT AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $payCols=['order_id'=>'INT NOT NULL DEFAULT 0','payment_type'=>'VARCHAR(80) DEFAULT \'\'','payment_date'=>'DATE NULL','amount'=>'DECIMAL(14,2) DEFAULT 0','currency'=>'VARCHAR(20) DEFAULT \'\'','method'=>'VARCHAR(120) DEFAULT \'\'','bank_ref'=>'VARCHAR(160) DEFAULT \'\'','note'=>'TEXT NULL','created_by'=>'VARCHAR(120) DEFAULT \'\'','created_at'=>'DATETIME DEFAULT CURRENT_TIMESTAMP'];
  foreach($payCols as $c=>$ddl) qo_ensure_col($pdo,'quote_order_payments',$c,$ddl);
  try{$pdo->exec('ALTER TABLE quote_order_payments ADD KEY idx_payment_order(order_id)');}catch(Throwable $e){}

  $pdo->exec("CREATE TABLE IF NOT EXISTS quote_shipments (id INT AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $shipCols=['order_id'=>'INT NOT NULL DEFAULT 0','shipment_no'=>'VARCHAR(120) DEFAULT \'\'','ship_date'=>'DATE NULL','packing_list_no'=>'VARCHAR(120) DEFAULT \'\'','commercial_invoice_no'=>'VARCHAR(120) DEFAULT \'\'','shipping_mark'=>'VARCHAR(255) DEFAULT \'\'','forwarder'=>'VARCHAR(255) DEFAULT \'\'','ship_method'=>'VARCHAR(160) DEFAULT \'\'','port_loading'=>'VARCHAR(160) DEFAULT \'\'','port_destination'=>'VARCHAR(160) DEFAULT \'\'','country_origin'=>'VARCHAR(120) DEFAULT \'China\'','status'=>'VARCHAR(80) DEFAULT \'草稿\'','total_qty'=>'DECIMAL(14,3) DEFAULT 0','total_cartons'=>'DECIMAL(14,3) DEFAULT 0','total_nw'=>'DECIMAL(14,3) DEFAULT 0','total_gw'=>'DECIMAL(14,3) DEFAULT 0','total_cbm'=>'DECIMAL(14,4) DEFAULT 0','pl_generated_at'=>'DATETIME NULL','ci_generated_at'=>'DATETIME NULL','note'=>'TEXT NULL','created_by'=>'VARCHAR(120) DEFAULT \'\'','created_at'=>'DATETIME DEFAULT CURRENT_TIMESTAMP','updated_at'=>'DATETIME NULL'];
  foreach($shipCols as $c=>$ddl) qo_ensure_col($pdo,'quote_shipments',$c,$ddl);
  try{$pdo->exec('ALTER TABLE quote_shipments ADD KEY idx_ship_order(order_id)');}catch(Throwable $e){}

  $pdo->exec("CREATE TABLE IF NOT EXISTS quote_shipment_items (id INT AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $siCols=['shipment_id'=>'INT NOT NULL DEFAULT 0','order_id'=>'INT NOT NULL DEFAULT 0','order_item_id'=>'INT DEFAULT 0','item_index'=>'INT DEFAULT 0','customer_code'=>'VARCHAR(120) DEFAULT \'\'','product_code'=>'VARCHAR(160) DEFAULT \'\'','product_name'=>'VARCHAR(255) DEFAULT \'\'','specification'=>'MEDIUMTEXT NULL','color'=>'VARCHAR(120) DEFAULT \'\'','qty'=>'DECIMAL(14,3) DEFAULT 0','pcs_per_ctn'=>'DECIMAL(14,3) DEFAULT 0','cartons'=>'DECIMAL(14,3) DEFAULT 0','carton_size'=>'VARCHAR(160) DEFAULT \'\'','nw'=>'DECIMAL(14,3) DEFAULT 0','gw'=>'DECIMAL(14,3) DEFAULT 0','cbm'=>'DECIMAL(14,4) DEFAULT 0','unit_price'=>'DECIMAL(14,4) DEFAULT 0','amount'=>'DECIMAL(14,2) DEFAULT 0','image'=>'LONGTEXT NULL','note'=>'TEXT NULL','item_json'=>'LONGTEXT NULL'];
  foreach($siCols as $c=>$ddl) qo_ensure_col($pdo,'quote_shipment_items',$c,$ddl);
  try{$pdo->exec('ALTER TABLE quote_shipment_items ADD KEY idx_ship_item(shipment_id)');}catch(Throwable $e){}
  try{$pdo->exec('ALTER TABLE quote_shipment_items ADD KEY idx_ship_order(order_id)');}catch(Throwable $e){}
  try{$pdo->exec('ALTER TABLE quote_shipment_items ADD KEY idx_ship_order_item(order_item_id)');}catch(Throwable $e){}

  $pdo->exec("CREATE TABLE IF NOT EXISTS quote_shipment_cartons (id INT AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $cartCols=['shipment_id'=>'INT NOT NULL DEFAULT 0','order_id'=>'INT NOT NULL DEFAULT 0','carton_no'=>'VARCHAR(120) DEFAULT \'\'','carton_range'=>'VARCHAR(160) DEFAULT \'\'','items_text'=>'TEXT NULL','items_json'=>'MEDIUMTEXT NULL','qty'=>'DECIMAL(14,3) DEFAULT 0','carton_size'=>'VARCHAR(160) DEFAULT \'\'','nw'=>'DECIMAL(14,3) DEFAULT 0','gw'=>'DECIMAL(14,3) DEFAULT 0','cbm'=>'DECIMAL(14,4) DEFAULT 0','mark'=>'VARCHAR(255) DEFAULT \'\'','note'=>'TEXT NULL','carton_count'=>'DECIMAL(14,3) DEFAULT 1'];
  foreach($cartCols as $c=>$ddl) qo_ensure_col($pdo,'quote_shipment_cartons',$c,$ddl);
  try{$pdo->exec('ALTER TABLE quote_shipment_cartons ADD KEY idx_carton_ship(shipment_id)');}catch(Throwable $e){}

  $pdo->exec("CREATE TABLE IF NOT EXISTS quote_document_settings (id INT PRIMARY KEY, settings_json LONGTEXT NULL, updated_by VARCHAR(120) DEFAULT '', updated_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  // V6.8.5.26：兼容旧版本已经存在但字段不完整的 quote_document_settings 表。
  // CREATE TABLE IF NOT EXISTS 不会给旧表补字段，所以这里必须强制补齐，避免 PL/CI 弹窗报 Unknown column settings_json。
  qo_ensure_col($pdo,'quote_document_settings','settings_json','LONGTEXT NULL');
  qo_ensure_col($pdo,'quote_document_settings','updated_by',"VARCHAR(120) DEFAULT ''");
  qo_ensure_col($pdo,'quote_document_settings','updated_at','DATETIME NULL');
  $_SESSION[$schemaKey]=time();
  $schemaDone=true;
}
function qo_dim_cbm($size,$ctns=1){ $s=strtolower(str_replace(['×','x','cm',' '],['*','*','',''],(string)$size)); $a=array_values(array_filter(array_map('qo_num',explode('*',$s)),function($x){return $x>0;})); return count($a)>=3?round($a[0]*$a[1]*$a[2]*qo_num($ctns)/1000000,4):0; }
function qo_customer_name($json){ $c=qo_json($json,[]); foreach(['company','name','customer_name','client_name','contact'] as $k){ if(!empty($c[$k])) return trim((string)$c[$k]); } return ''; }
function qo_order_items_from_payload($itemsJson){
  $arr=qo_json($itemsJson,[]);
  if(!is_array($arr)) return [];
  foreach(['order_items','items','quote_items'] as $k){ if(isset($arr[$k]) && is_array($arr[$k])) return array_values($arr[$k]); }
  return array_values($arr);
}
function qo_order_items_from_order_record($order){
  $items=qo_order_items_from_payload($order['items_json']??'');
  if($items) return $items;
  $snap=qo_json($order['snapshot_json']??'',[]);
  if(is_array($snap)){
    foreach(['order_items','items','quote_items'] as $k){ if(isset($snap[$k]) && is_array($snap[$k]) && count($snap[$k])) return array_values($snap[$k]); }
  }
  return [];
}
function qo_item_val($it,$key,$def=''){ return isset($it[$key])?$it[$key]:$def; }
function qo_product($it){ return (isset($it['product']) && is_array($it['product']))?$it['product']:[]; }
function qo_nested_item($it){
  if(isset($it['item_json']) && is_string($it['item_json']) && trim($it['item_json'])!==''){
    $j=qo_json($it['item_json'],[]);
    if(is_array($j) && count($j)) return array_replace_recursive($j,$it);
  }
  return $it;
}
function qo_text_first($it,$keys,$def=''){
  foreach($keys as $k){ if(isset($it[$k]) && !is_array($it[$k]) && trim((string)$it[$k])!=='') return trim((string)$it[$k]); }
  return $def;
}
function qo_item_spec($it){ $it=qo_nested_item($it); $p=qo_product($it); return qo_s($it['specification'] ?? $it['description'] ?? $it['extra_spec'] ?? $p['specification'] ?? $p['description'] ?? $p['name'] ?? $p['product_name'] ?? '', 20000); }
function qo_item_row($it,$idx){
  $it=qo_nested_item(is_array($it)?$it:[]); $p=qo_product($it);
  $qty=qo_num($it['qty'] ?? 0); $price=qo_num($it['unit_price'] ?? $it['price'] ?? 0); $amount=qo_num($it['amount'] ?? ($qty*$price));
  $customerCode=qo_text_first($it,['customer_code','customer_model','customerModel']); if($customerCode==='') $customerCode=qo_text_first($p,['customer_code','customer_model']);
  $productCode=qo_text_first($it,['product_code','manufacturer_code','model','code']); if($productCode==='') $productCode=qo_text_first($p,['code','model','model_no','product_code']);
  $productName=qo_text_first($it,['product_name','name','title']); if($productName==='') $productName=qo_text_first($p,['name','product_name','title']);
  $image=qo_text_first($it,['image','product_image','image_url']); if($image==='') $image=qo_text_first($p,['image','image_display','product_image','main_image','image_url']);
  $color=qo_text_first($it,['color']); if($color==='') $color=qo_text_first($p,['color']);
  return [
    'item_index'=>$idx,'customer_code'=>qo_s($customerCode,120),'product_code'=>qo_s($productCode,160),'product_name'=>qo_s($productName,255),'specification'=>qo_item_spec($it),'color'=>qo_s($color,120),'qty'=>$qty,'unit_price'=>$price,'amount'=>$amount,'image'=>qo_s($image,0),'item_json'=>json_encode($it,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
  ];
}
function qo_item_row_has_content($r){ return trim((string)($r['customer_code']??''))!=='' || trim((string)($r['product_code']??''))!=='' || trim((string)($r['product_name']??''))!=='' || trim((string)($r['specification']??''))!=='' || trim((string)($r['image']??''))!=='' || qo_num($r['qty']??0)>0; }
function qo_order_items_table_has_content($items){ foreach($items as $r){ if(qo_item_row_has_content($r)) return true; } return false; }
function qo_rebuild_order_items_from_snapshot(PDO $pdo,$orderId){
  $order=qo_row($pdo,'SELECT * FROM quote_sales_orders WHERE id=? LIMIT 1',[$orderId]); if(!$order) return [];
  $payloadItems=qo_order_items_from_order_record($order); if(!$payloadItems) return [];
  $pdo->prepare('DELETE FROM quote_sales_order_items WHERE order_id=?')->execute([$orderId]);
  $ins=$pdo->prepare('INSERT INTO quote_sales_order_items(order_id,item_index,customer_code,product_code,product_name,specification,color,qty,unit_price,amount,shipped_qty,image,item_json) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
  $out=[];
  foreach($payloadItems as $i=>$it){ $row=qo_item_row($it,$i+1); if(!qo_item_row_has_content($row)) continue; $ins->execute([$orderId,$row['item_index'],$row['customer_code'],$row['product_code'],$row['product_name'],$row['specification'],$row['color'],$row['qty'],$row['unit_price'],$row['amount'],0,$row['image'],$row['item_json']]); $row['id']=(int)$pdo->lastInsertId(); $out[]=$row; }
  return qo_rows($pdo,'SELECT * FROM quote_sales_order_items WHERE order_id=? ORDER BY item_index,id',[$orderId]);
}
function qo_recalc_payment(PDO $pdo,$orderId){
  $order=qo_row($pdo,'SELECT * FROM quote_sales_orders WHERE id=? LIMIT 1',[$orderId]); if(!$order)return [];
  $paid=(float)qo_row($pdo,'SELECT COALESCE(SUM(amount),0) AS s FROM quote_order_payments WHERE order_id=?',[$orderId])['s'];
  $amount=qo_num($order['amount']??0); $bal=max(0,round($amount-$paid,2));
  $status=$paid<=0?'未收款':($bal<=0.00001?'已收齐':'部分收款');
  $pdo->prepare('UPDATE quote_sales_orders SET paid_amount=?,balance_amount=?,payment_status=?,updated_at=NOW() WHERE id=?')->execute([$paid,$bal,$status,$orderId]);
  return ['paid_amount'=>$paid,'balance_amount'=>$bal,'payment_status'=>$status,'currency'=>$order['currency']??'USD'];
}
function qo_update_item_shipped(PDO $pdo,$orderId){
  $items=qo_rows($pdo,'SELECT id,qty FROM quote_sales_order_items WHERE order_id=?',[$orderId]);
  $totalQty=0; $totalShip=0;
  foreach($items as $it){
    $sh=(float)qo_row($pdo,'SELECT COALESCE(SUM(qty),0) AS s FROM quote_shipment_items WHERE order_item_id=?',[$it['id']])['s'];
    $pdo->prepare('UPDATE quote_sales_order_items SET shipped_qty=? WHERE id=?')->execute([$sh,$it['id']]);
    $totalQty+=qo_num($it['qty']); $totalShip+=$sh;
  }
  $status='未出货';
  if($totalShip>0 && $totalShip+0.00001<$totalQty) $status='部分出货';
  elseif($totalQty>0 && $totalShip+0.00001>=$totalQty) $status='已出货';
  $pdo->prepare('UPDATE quote_sales_orders SET shipment_status=?,updated_at=NOW() WHERE id=?')->execute([$status,$orderId]);
  return $status;
}
function qo_pack_match(PDO $pdo,$productCode,$customerCode=''){
  if($productCode==='') return null;
  $r=null;
  if($customerCode!=='') $r=qo_row($pdo,'SELECT * FROM quote_packaging_profiles WHERE product_code=? AND customer_code=? ORDER BY id DESC LIMIT 1',[$productCode,$customerCode]);
  if(!$r) $r=qo_row($pdo,'SELECT * FROM quote_packaging_profiles WHERE product_code=? ORDER BY customer_code DESC,id DESC LIMIT 1',[$productCode]);
  return $r?:null;
}
function qo_next_doc_numbers(PDO $pdo,$orderId,$shipDate=''){
  $settings=qo_doc_settings($pdo); $order=qo_row($pdo,'SELECT * FROM quote_sales_orders WHERE id=? LIMIT 1',[$orderId]);
  $base=qo_safe_no(qo_order_no_at($order['order_no']??'', $order['quote_no']??'')); $base=preg_replace('/^AT-/i','',$base);
  $shipDate=$shipDate?:qo_today(); $cnt=(int)qo_row($pdo,'SELECT COUNT(*) AS c FROM quote_shipments WHERE order_id=?',[$orderId])['c']+1;
  $suffix=$cnt>1?('-'.str_pad((string)$cnt,2,'0',STR_PAD_LEFT)):'';
  return [
    'shipment_no'=>($settings['shipment_prefix']??'SHP').'-'.$base.$suffix,
    'packing_list_no'=>($settings['pl_prefix']??'PL').'-'.$base.$suffix,
    'commercial_invoice_no'=>($settings['ci_prefix']??'CI').'-'.$base.$suffix,
    'settings'=>$settings
  ];
}
function qo_prepare_items(PDO $pdo,$orderId){
  $items=qo_rows($pdo,'SELECT * FROM quote_sales_order_items WHERE order_id=? ORDER BY item_index,id',[$orderId]);
  if(!$items || !qo_order_items_table_has_content($items)){
    $items=qo_rebuild_order_items_from_snapshot($pdo,$orderId);
  }
  qo_update_item_shipped($pdo,$orderId);
  $items=qo_rows($pdo,'SELECT * FROM quote_sales_order_items WHERE order_id=? ORDER BY item_index,id',[$orderId]);
  if(!$items || !qo_order_items_table_has_content($items)){
    $items=qo_rebuild_order_items_from_snapshot($pdo,$orderId);
  }
  foreach($items as &$it){
    $it['remain_qty']=max(0,qo_num($it['qty'])-qo_num($it['shipped_qty']));
    $it['packaging_profile']=qo_pack_match($pdo,$it['product_code']??'',$it['customer_code']??'') ?: new stdClass();
  }
  unset($it);
  return $items;
}
function qo_create_shipment(PDO $pdo,$d,$quick=false){
  qo_ensure_schema($pdo); $orderId=(int)($d['order_id']??0); if($orderId<=0) qo_fail('缺少订单ID');
  $order=qo_row($pdo,'SELECT * FROM quote_sales_orders WHERE id=? LIMIT 1',[$orderId]); if(!$order) qo_fail('订单不存在');
  $pay=qo_recalc_payment($pdo,$orderId);
  if(!$quick && empty($d['force_shipment']) && qo_num($pay['balance_amount']??0)>0.01) qo_fail('尾款未收齐，当前未收：'.($order['currency']??'USD').' '.number_format(qo_num($pay['balance_amount']),2));
  $shipDate=qo_s($d['ship_date']??qo_today(),20) ?: qo_today();
  $nums=qo_next_doc_numbers($pdo,$orderId,$shipDate);
  $shipmentNo=qo_s($d['shipment_no']??$nums['shipment_no'],120) ?: $nums['shipment_no'];
  $plNo=qo_s($d['packing_list_no']??$nums['packing_list_no'],120) ?: $nums['packing_list_no'];
  $ciNo=qo_s($d['commercial_invoice_no']??$nums['commercial_invoice_no'],120) ?: $nums['commercial_invoice_no'];
  $settings=$nums['settings'];
  $itemsIn=is_array($d['items']??null)?$d['items']:[];
  if(!$itemsIn){
    foreach(qo_prepare_items($pdo,$orderId) as $it){ if(qo_num($it['remain_qty'])<=0) continue; $p=is_array($it['packaging_profile']??null)?$it['packaging_profile']:[]; $qty=qo_num($it['remain_qty']); $pcs=qo_num($p['pcs_per_ctn']??0); if($pcs<=0)$pcs=$qty>0?$qty:1; $ctns=$pcs>0?ceil($qty/$pcs):1; $size=(string)($p['carton_size']??''); $nw=qo_num($p['carton_nw']??0)>0?qo_num($p['carton_nw'])*$ctns:qo_num($p['unit_nw']??0)*$qty; $gw=qo_num($p['carton_gw']??0)>0?qo_num($p['carton_gw'])*$ctns:qo_num($p['unit_gw']??0)*$qty; $cbm=qo_num($p['carton_cbm']??0)>0?qo_num($p['carton_cbm'])*$ctns:qo_dim_cbm($size,$ctns); $itemsIn[]=['order_item_id'=>(int)$it['id'],'qty'=>$qty,'pcs_per_ctn'=>$pcs,'cartons'=>$ctns,'carton_size'=>$size,'nw'=>$nw,'gw'=>$gw,'cbm'=>$cbm,'note'=>'']; }
  }
  if(!$itemsIn) qo_fail('没有可生成出货的产品');
  $tot=['qty'=>0,'cartons'=>0,'nw'=>0,'gw'=>0,'cbm'=>0];
  foreach($itemsIn as $x){ $tot['qty']+=qo_num($x['qty']??0); $tot['cartons']+=qo_num($x['cartons']??0); $tot['nw']+=qo_num($x['nw']??0); $tot['gw']+=qo_num($x['gw']??0); $tot['cbm']+=qo_num($x['cbm']??0); }
  $st=$pdo->prepare('INSERT INTO quote_shipments(order_id,shipment_no,ship_date,packing_list_no,commercial_invoice_no,shipping_mark,ship_method,port_loading,port_destination,country_origin,status,total_qty,total_cartons,total_nw,total_gw,total_cbm,note,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
  $st->execute([$orderId,$shipmentNo,$shipDate,$plNo,$ciNo,qo_s($d['shipping_mark']??($order['customer_name']??''),255),qo_s($d['ship_method']??($settings['ship_method']??''),160),qo_s($d['port_loading']??($settings['port_loading']??'Zhongshan'),160),qo_s($d['port_destination']??'',160),qo_s($d['country_origin']??($settings['country_origin']??'China'),120),qo_s($d['status']??'草稿',80),$tot['qty'],$tot['cartons'],$tot['nw'],$tot['gw'],$tot['cbm'],qo_s($d['note']??'',5000),qo_actor()]);
  $shipmentId=(int)$pdo->lastInsertId();
  $orderItems=[]; foreach(qo_rows($pdo,'SELECT * FROM quote_sales_order_items WHERE order_id=?',[$orderId]) as $it){ $orderItems[(int)$it['id']]=$it; }
  $ins=$pdo->prepare('INSERT INTO quote_shipment_items(shipment_id,order_id,order_item_id,item_index,customer_code,product_code,product_name,specification,color,qty,pcs_per_ctn,cartons,carton_size,nw,gw,cbm,unit_price,amount,image,note,item_json) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
  foreach($itemsIn as $x){ $oid=(int)($x['order_item_id']??0); $it=$orderItems[$oid]??[]; $qty=qo_num($x['qty']??0); if($qty<=0) continue; $unit=qo_num($it['unit_price']??0); $amount=round($qty*$unit,2); $ins->execute([$shipmentId,$orderId,$oid,(int)($it['item_index']??0),$it['customer_code']??'', $it['product_code']??'', $it['product_name']??'', $it['specification']??'', $it['color']??'', $qty, qo_num($x['pcs_per_ctn']??0), qo_num($x['cartons']??0), qo_s($x['carton_size']??'',160), qo_num($x['nw']??0), qo_num($x['gw']??0), qo_num($x['cbm']??0), $unit, $amount, $it['image']??'', qo_s($x['note']??'',5000), $it['item_json']??'']); }
  $cartons=is_array($d['cartons']??null)?$d['cartons']:[];
  if($cartons){ $ci=$pdo->prepare('INSERT INTO quote_shipment_cartons(shipment_id,order_id,carton_no,carton_range,items_text,items_json,qty,carton_size,nw,gw,cbm,mark,note,carton_count) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)'); foreach($cartons as $c){ $ci->execute([$shipmentId,$orderId,qo_s($c['carton_no']??'',120),qo_s($c['carton_range']??'',160),qo_s($c['items_text']??'',5000),json_encode($c['items']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),qo_num($c['qty']??0),qo_s($c['carton_size']??'',160),qo_num($c['nw']??0),qo_num($c['gw']??0),qo_num($c['cbm']??0),qo_s($c['mark']??'',255),qo_s($c['note']??'',5000),qo_num($c['carton_count']??1)]); } }
  qo_update_item_shipped($pdo,$orderId); qo_recalc_payment($pdo,$orderId);
  qo_push_quote_sys_notification($pdo,'quote_shipment_created','订单新增出货：'.$shipmentNo,trim('客户：'.($order['customer_name']??'')."\n订单号：".qo_order_no_at($order['order_no']??'',$order['quote_no']??'')."\n来源报价：".($order['quote_no']??'')."\n出货日期：".$shipDate."\n出货数量：".$tot['qty']."\n单证号：".$plNo.' / '.$ciNo),[
    'source_module'=>'quote_shipments',
    'source_id'=>(string)$shipmentId,
    'target_id'=>(string)$shipmentId,
    'target_url'=>'quotation.php?shipment_id='.$shipmentId,
    'related_quote_id'=>(string)($order['quote_no']??''),
    'related_customer_id'=>(int)($order['customer_id']??0),
    'order_id'=>$orderId,
    'shipment_id'=>$shipmentId,
    'shipment_no'=>$shipmentNo,
    'order_no'=>qo_order_no_at($order['order_no']??'',$order['quote_no']??''),
    'quote_no'=>(string)($order['quote_no']??''),
    'customer_name'=>(string)($order['customer_name']??''),
    'dedupe_key'=>'quote:quote_shipment_created:'.$shipmentId,
  ],$order);
  return ['shipment_id'=>$shipmentId,'shipment_no'=>$shipmentNo,'packing_list_no'=>$plNo,'commercial_invoice_no'=>$ciNo,'totals'=>$tot];
}
function qo_push_document_notification(PDO $pdo, int $shipmentId, string $type): void {
  if($shipmentId<=0) return;
  $detail=qo_shipment_detail($pdo,$shipmentId);
  $shipment=is_array($detail['shipment']??null)?$detail['shipment']:[];
  $order=is_array($detail['order']??null)?$detail['order']:[];
  $docType=$type==='ci'?'CI 商业发票':'PL 装箱单';
  $docNo=$type==='ci'?($shipment['commercial_invoice_no']??''):($shipment['packing_list_no']??'');
  $shipmentNo=(string)($shipment['shipment_no']??('出货#'.$shipmentId));
  $orderNo=qo_order_no_at($order['order_no']??'',$order['quote_no']??'');
  qo_push_quote_sys_notification($pdo,'quote_document_generated','单证已生成：'.$docType.' '.$docNo,trim('客户：'.($order['customer_name']??'')."\n订单号：".$orderNo."\n来源报价：".($order['quote_no']??'')."\n出货批次：".$shipmentNo."\n单证类型：".$docType."\n单证编号：".$docNo),[
    'source_module'=>'quote_shipments',
    'source_id'=>(string)$shipmentId,
    'target_id'=>(string)$shipmentId,
    'target_url'=>'quotation.php?shipment_id='.$shipmentId.'&document='.$type,
    'related_quote_id'=>(string)($order['quote_no']??''),
    'related_customer_id'=>(int)($order['customer_id']??0),
    'order_id'=>(int)($order['id']??0),
    'shipment_id'=>$shipmentId,
    'shipment_no'=>$shipmentNo,
    'document_type'=>$type,
    'document_no'=>(string)$docNo,
    'order_no'=>$orderNo,
    'quote_no'=>(string)($order['quote_no']??''),
    'customer_name'=>(string)($order['customer_name']??''),
    'dedupe_key'=>'quote:quote_document_generated:'.$type.':'.$shipmentId,
  ],$order);
}
function qo_shipment_items_have_content(PDO $pdo,$shipmentId){
  $rows=qo_rows($pdo,'SELECT * FROM quote_shipment_items WHERE shipment_id=? ORDER BY item_index,id',[$shipmentId]);
  foreach($rows as $r){ if(qo_item_row_has_content($r)) return true; }
  return false;
}
function qo_rebuild_shipment_items_from_order(PDO $pdo,$shipment){
  $shipmentId=(int)($shipment['id']??0); $orderId=(int)($shipment['order_id']??0); if($shipmentId<=0||$orderId<=0)return;
  $items=[];
  foreach(qo_prepare_items($pdo,$orderId) as $it){
    $qty=qo_num($it['remain_qty']??0); if($qty<=0) $qty=qo_num($it['qty']??0); if($qty<=0) continue;
    $p=is_array($it['packaging_profile']??null)?$it['packaging_profile']:[];
    $pcs=qo_num($p['pcs_per_ctn']??0); if($pcs<=0)$pcs=$qty>0?$qty:1;
    $ctns=$pcs>0?ceil($qty/$pcs):1; $size=(string)($p['carton_size']??'');
    $nw=qo_num($p['carton_nw']??0)>0?qo_num($p['carton_nw'])*$ctns:qo_num($p['unit_nw']??0)*$qty;
    $gw=qo_num($p['carton_gw']??0)>0?qo_num($p['carton_gw'])*$ctns:qo_num($p['unit_gw']??0)*$qty;
    $cbm=qo_num($p['carton_cbm']??0)>0?qo_num($p['carton_cbm'])*$ctns:qo_dim_cbm($size,$ctns);
    $items[]=['it'=>$it,'qty'=>$qty,'pcs'=>$pcs,'ctns'=>$ctns,'size'=>$size,'nw'=>$nw,'gw'=>$gw,'cbm'=>$cbm];
  }
  if(!$items)return;
  $pdo->prepare('DELETE FROM quote_shipment_items WHERE shipment_id=?')->execute([$shipmentId]);
  $tot=['qty'=>0,'cartons'=>0,'nw'=>0,'gw'=>0,'cbm'=>0];
  $ins=$pdo->prepare('INSERT INTO quote_shipment_items(shipment_id,order_id,order_item_id,item_index,customer_code,product_code,product_name,specification,color,qty,pcs_per_ctn,cartons,carton_size,nw,gw,cbm,unit_price,amount,image,note,item_json) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
  foreach($items as $x){ $it=$x['it']; $unit=qo_num($it['unit_price']??0); $amount=round($x['qty']*$unit,2); $ins->execute([$shipmentId,$orderId,(int)($it['id']??0),(int)($it['item_index']??0),$it['customer_code']??'', $it['product_code']??'', $it['product_name']??'', $it['specification']??'', $it['color']??'', $x['qty'], $x['pcs'], $x['ctns'], qo_s($x['size'],160), $x['nw'], $x['gw'], $x['cbm'], $unit, $amount, $it['image']??'', '', $it['item_json']??'']); $tot['qty']+=$x['qty'];$tot['cartons']+=$x['ctns'];$tot['nw']+=$x['nw'];$tot['gw']+=$x['gw'];$tot['cbm']+=$x['cbm']; }
  $pdo->prepare('UPDATE quote_shipments SET total_qty=?,total_cartons=?,total_nw=?,total_gw=?,total_cbm=?,updated_at=NOW() WHERE id=?')->execute([$tot['qty'],$tot['cartons'],$tot['nw'],$tot['gw'],$tot['cbm'],$shipmentId]);
}
function qo_existing_or_quick_shipment(PDO $pdo,$orderId,$type='pl'){
  $r=qo_row($pdo,"SELECT * FROM quote_shipments WHERE order_id=? AND COALESCE(status,'') NOT IN ('已作废','取消') ORDER BY id DESC LIMIT 1",[$orderId]);
  if($r){ if(!qo_shipment_items_have_content($pdo,(int)$r['id'])) qo_rebuild_shipment_items_from_order($pdo,$r); return qo_row($pdo,'SELECT * FROM quote_shipments WHERE id=? LIMIT 1',[(int)$r['id']]) ?: $r; }
  qo_create_shipment($pdo,['order_id'=>$orderId,'ship_date'=>qo_today(),'force_shipment'=>1,'status'=>'草稿'],true);
  return qo_row($pdo,'SELECT * FROM quote_shipments WHERE order_id=? ORDER BY id DESC LIMIT 1',[$orderId]);
}
function qo_shipment_detail(PDO $pdo,$shipmentId){
  $shipment=qo_row($pdo,'SELECT * FROM quote_shipments WHERE id=? LIMIT 1',[$shipmentId]); if(!$shipment) qo_fail('出货批次不存在');
  $order=qo_row($pdo,'SELECT * FROM quote_sales_orders WHERE id=? LIMIT 1',[(int)$shipment['order_id']]);
  $items=qo_rows($pdo,'SELECT * FROM quote_shipment_items WHERE shipment_id=? ORDER BY item_index,id',[$shipmentId]);
  $cartons=qo_rows($pdo,'SELECT * FROM quote_shipment_cartons WHERE shipment_id=? ORDER BY id',[$shipmentId]);
  return ['shipment'=>$shipment,'order'=>$order,'items'=>$items,'cartons'=>$cartons,'settings'=>qo_doc_settings($pdo)];
}
function qo_list_orders(PDO $pdo){
  // V6.8.5.44：订单中心列表轻量读取。
  // 旧版每打开一次订单中心，会对每个订单逐个重算出货/收款并 UPDATE 数据库；
  // 订单只有几张也会慢，订单越多越明显。列表页只需要摘要，明细/重算放到“详情”按需执行。
  qo_ensure_schema($pdo);
  $sql = "SELECT
      o.id,o.order_no,o.quote_no,o.source_quote_id,o.customer_id,o.customer_name,
      o.qty,o.amount,o.currency,o.exchange_rate,o.quote_date,o.order_date,o.status,
      o.shipment_status,o.payment_status,o.paid_amount,o.balance_amount,
      o.user_name,o.created_by,o.updated_by,o.created_at,o.updated_at,
      COALESCE(pay.paid_amount,0) AS paid_calc,
      COALESCE(ship.shipped_qty,0) AS shipped_calc
    FROM quote_sales_orders o
    LEFT JOIN (SELECT order_id, SUM(amount) AS paid_amount FROM quote_order_payments GROUP BY order_id) pay ON pay.order_id=o.id
    LEFT JOIN (SELECT order_id, SUM(qty) AS shipped_qty FROM quote_shipment_items GROUP BY order_id) ship ON ship.order_id=o.id
    ORDER BY COALESCE(o.order_date,o.created_at) DESC,o.id DESC
    LIMIT 2000";
  $orders = qo_rows($pdo,$sql);
  foreach($orders as &$o){
    $o['order_no']=qo_order_no_at($o['order_no']??'',$o['quote_no']??'');
    $amount=qo_num($o['amount']??0);
    $paid=qo_num($o['paid_calc']??0);
    if($paid<=0 && qo_num($o['paid_amount']??0)>0) $paid=qo_num($o['paid_amount']);
    $bal=max(0,round($amount-$paid,2));
    $o['paid_amount']=$paid;
    $o['balance_amount']=$bal;
    $o['payment_status']=$paid<=0?'未收款':($bal<=0.00001?'已收齐':'部分收款');
    $ship=qo_num($o['shipped_calc']??0); $qty=qo_num($o['qty']??0);
    if($ship>0 && $ship+0.00001<$qty) $o['shipment_status']='部分出货';
    elseif($qty>0 && $ship+0.00001>=$qty) $o['shipment_status']='已出货';
    elseif(trim((string)($o['shipment_status']??''))==='') $o['shipment_status']='未出货';
    unset($o['paid_calc'],$o['shipped_calc']);
  }
  unset($o);
  return $orders;
}
function qo_order_detail(PDO $pdo,$id){
  qo_ensure_schema($pdo); $order=qo_row($pdo,'SELECT * FROM quote_sales_orders WHERE id=? LIMIT 1',[(int)$id]); if(!$order) qo_fail('订单不存在');
  $order['order_no']=qo_order_no_at($order['order_no']??'',$order['quote_no']??''); qo_update_item_shipped($pdo,(int)$id); $pay=qo_recalc_payment($pdo,(int)$id); $order=qo_row($pdo,'SELECT * FROM quote_sales_orders WHERE id=? LIMIT 1',[(int)$id]);
  return ['order'=>$order,'items'=>qo_rows($pdo,'SELECT * FROM quote_sales_order_items WHERE order_id=? ORDER BY item_index,id',[(int)$id]),'shipments'=>qo_rows($pdo,'SELECT * FROM quote_shipments WHERE order_id=? ORDER BY id DESC',[(int)$id]),'payments'=>qo_rows($pdo,'SELECT * FROM quote_order_payments WHERE order_id=? ORDER BY payment_date DESC,id DESC',[(int)$id]),'payment_summary'=>$pay];
}

try{
  qo_ensure_schema($pdo);
  if($action==='get_document_settings') qo_ok(['settings'=>qo_doc_settings($pdo)]);
  if($action==='save_document_settings') qo_ok(['settings'=>qo_save_doc_settings($pdo,qo_input())]);
  if($action==='next_doc_numbers'){ $d=qo_input(); qo_ok(qo_next_doc_numbers($pdo,(int)($d['order_id']??0),qo_s($d['ship_date']??'',20))); }
  if($action==='list') qo_ok(['orders'=>qo_list_orders($pdo)]);
  if($action==='detail'){ $d=qo_input(); qo_ok(qo_order_detail($pdo,(int)($d['id']??0))); }
  if($action==='convert'){
    $d=qo_input(); $orderNo=qo_order_no_at(qo_s($d['order_no']??($d['quote_no']??''),120), qo_s($d['quote_no']??'',120)); if($orderNo==='') qo_fail('缺少订单号');
    $items=qo_order_items_from_payload($d['items_json']??'[]'); if(!$items) qo_fail('订单没有产品明细');
    $customerJson=(string)($d['customer_json']??'{}'); $custName=qo_s($d['customer_name']??qo_customer_name($customerJson),255);
    $qty=qo_num($d['qty']??0); if($qty<=0){ foreach($items as $it)$qty+=qo_num($it['qty']??0); }
    $amount=qo_num($d['amount']??0); if($amount<=0){ foreach($items as $it){ $amount+=qo_num($it['amount']??(qo_num($it['qty']??0)*qo_num($it['price']??$it['unit_price']??0))); } }
    $exist=qo_row($pdo,"SELECT * FROM quote_sales_orders WHERE order_no=? AND COALESCE(status,'') NOT IN ('已作废','取消') ORDER BY id DESC LIMIT 1",[$orderNo]);
    if($exist){ $id=(int)$exist['id']; $pdo->prepare('DELETE FROM quote_sales_order_items WHERE order_id=?')->execute([$id]); $sql='UPDATE quote_sales_orders SET quote_no=?,source_quote_id=?,customer_id=?,customer_name=?,customer_json=?,header_json=?,bank_json=?,template_json=?,items_json=?,snapshot_json=?,qty=?,amount=?,currency=?,exchange_rate=?,quote_date=?,order_date=?,status=?,shipment_status=?,payment_status=?,balance_amount=?,order_doc_title=?,contract_title=?,note=?,user_name=?,updated_by=?,updated_at=NOW() WHERE id=?'; $pdo->prepare($sql)->execute([qo_s($d['quote_no']??'',120),(int)($d['quote_id']??$d['source_quote_id']??0),qo_s($d['customer_id']??'',120),$custName,$customerJson,(string)($d['header_json']??''),(string)($d['bank_json']??''),(string)($d['template_json']??''),json_encode($items,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(string)($d['snapshot_json']??''),$qty,$amount,qo_s($d['currency']??'USD',20),qo_num($d['exchange_rate']??1),qo_s($d['quote_date']??'',20)?:null,qo_s($d['order_date']??qo_today(),20)?:qo_today(),qo_s($d['status']??'待确认',80),'未出货','未收款',$amount,qo_s($d['order_doc_title']??$d['quote_status']??'',120),qo_s($d['contract_title']??$d['quote_status']??'',120),qo_s($d['note']??'',5000),qo_actor(),qo_actor(),$id]); }
    else { $sql='INSERT INTO quote_sales_orders(order_no,quote_no,source_quote_id,customer_id,customer_name,customer_json,header_json,bank_json,template_json,items_json,snapshot_json,qty,amount,currency,exchange_rate,quote_date,order_date,status,shipment_status,payment_status,paid_amount,balance_amount,order_doc_title,contract_title,note,user_name,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())'; $pdo->prepare($sql)->execute([$orderNo,qo_s($d['quote_no']??'',120),(int)($d['quote_id']??$d['source_quote_id']??0),qo_s($d['customer_id']??'',120),$custName,$customerJson,(string)($d['header_json']??''),(string)($d['bank_json']??''),(string)($d['template_json']??''),json_encode($items,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(string)($d['snapshot_json']??''),$qty,$amount,qo_s($d['currency']??'USD',20),qo_num($d['exchange_rate']??1),qo_s($d['quote_date']??'',20)?:null,qo_s($d['order_date']??qo_today(),20)?:qo_today(),qo_s($d['status']??'待确认',80),'未出货','未收款',0,$amount,qo_s($d['order_doc_title']??$d['quote_status']??'',120),qo_s($d['contract_title']??$d['quote_status']??'',120),qo_s($d['note']??'',5000),qo_actor(),qo_actor()]); $id=(int)$pdo->lastInsertId(); }
    $ins=$pdo->prepare('INSERT INTO quote_sales_order_items(order_id,item_index,customer_code,product_code,product_name,specification,color,qty,unit_price,amount,shipped_qty,image,item_json) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach($items as $i=>$it){ $row=qo_item_row($it,$i+1); $ins->execute([$id,$row['item_index'],$row['customer_code'],$row['product_code'],$row['product_name'],$row['specification'],$row['color'],$row['qty'],$row['unit_price'],$row['amount'],0,$row['image'],$row['item_json']]); }
    qo_recalc_payment($pdo,$id);
    $orderRow=qo_row($pdo,'SELECT * FROM quote_sales_orders WHERE id=? LIMIT 1',[$id]) ?: [];
    qo_push_quote_sys_notification($pdo,'quote_order_converted','报价已转订单：'.$orderNo,trim('客户：'.$custName."\n订单号：".$orderNo."\n来源报价：".qo_s($d['quote_no']??'',120)."\n金额：".qo_s($d['currency']??'USD',20).' '.number_format($amount,2)."\n数量：".$qty),[
      'source_module'=>'quote_sales_orders',
      'source_id'=>(string)$id,
      'target_id'=>(string)$id,
      'target_url'=>'quotation.php?order_id='.$id,
      'related_quote_id'=>(string)(qo_s($d['quote_no']??'',120) ?: ($d['quote_id']??'')),
      'related_customer_id'=>(int)($d['customer_id']??0),
      'order_id'=>$id,
      'order_no'=>$orderNo,
      'quote_no'=>qo_s($d['quote_no']??'',120),
      'customer_name'=>$custName,
      'currency'=>qo_s($d['currency']??'USD',20),
      'amount'=>$amount,
      'dedupe_key'=>'quote:quote_order_converted:'.$id,
    ],$orderRow);
    qo_complete_quote_followup_tasks($pdo,$orderRow);
    if(qo_table_exists($pdo,'quote_orders')){ try{ qo_ensure_col($pdo,'quote_orders','converted_order_id','INT DEFAULT 0'); qo_ensure_col($pdo,'quote_orders','converted_order_no','VARCHAR(120) DEFAULT \'\''); if(!empty($d['quote_no'])) $pdo->prepare('UPDATE quote_orders SET converted_order_id=?,converted_order_no=? WHERE quote_no=?')->execute([$id,$orderNo,qo_s($d['quote_no'],120)]); }catch(Throwable $e){} }
    qo_ok(['id'=>$id,'order_no'=>$orderNo]);
  }
  if($action==='update_status'){ $d=qo_input(); $id=(int)($d['id']??0); $status=qo_s($d['status']??'',80); if(!$id||$status==='') qo_fail('缺少订单ID或状态'); $pdo->prepare('UPDATE quote_sales_orders SET status=?,updated_by=?,updated_at=NOW() WHERE id=?')->execute([$status,qo_actor(),$id]); qo_ok(['order'=>qo_row($pdo,'SELECT * FROM quote_sales_orders WHERE id=?',[$id])]); }
  if($action==='prepare_shipment'){ $d=qo_input(); $id=(int)($d['order_id']??0); $detail=qo_order_detail($pdo,$id); $detail['items']=array_values(array_filter(qo_prepare_items($pdo,$id),function($x){return qo_num($x['remain_qty']??0)>0;})); qo_ok($detail); }
  if($action==='create_shipment'){ qo_ok(qo_create_shipment($pdo,qo_input(),false)); }
  if($action==='quick_document'){ $d=qo_input(); $id=(int)($d['order_id']??0); if(!$id) qo_fail('缺少订单ID'); $type=($d['type']??'pl')==='ci'?'ci':'pl'; $ship=qo_existing_or_quick_shipment($pdo,$id,$type); if($type==='ci') $pdo->prepare('UPDATE quote_shipments SET ci_generated_at=COALESCE(ci_generated_at,NOW()) WHERE id=?')->execute([(int)$ship['id']]); else $pdo->prepare('UPDATE quote_shipments SET pl_generated_at=COALESCE(pl_generated_at,NOW()) WHERE id=?')->execute([(int)$ship['id']]); qo_push_document_notification($pdo,(int)$ship['id'],$type); qo_ok(['shipment_id'=>(int)$ship['id'],'shipment'=>$ship,'type'=>$type]); }
  if($action==='shipment_detail'){ $d=qo_input(); qo_ok(qo_shipment_detail($pdo,(int)($d['id']??0))); }
  if($action==='list_documents'){ $sql="SELECT s.*,o.order_no,o.quote_no,o.customer_name,o.currency,o.amount FROM quote_shipments s LEFT JOIN quote_sales_orders o ON o.id=s.order_id ORDER BY s.id DESC LIMIT 1000"; qo_ok(['documents'=>qo_rows($pdo,$sql)]); }
  if($action==='mark_document_generated'){ $d=qo_input(); $sid=(int)($d['shipment_id']??0); $type=($d['type']??'pl')==='ci'?'ci':'pl'; if($sid){ $col=$type==='ci'?'ci_generated_at':'pl_generated_at'; $pdo->prepare('UPDATE quote_shipments SET '.$col.'=COALESCE('.$col.',NOW()) WHERE id=?')->execute([$sid]); qo_push_document_notification($pdo,$sid,$type); } qo_ok(['marked'=>1]); }
  if($action==='packaging_list'){ $d=qo_input(); $kw='%'.qo_s($d['kw']??'',120).'%'; $rows=$kw==='%%'?qo_rows($pdo,'SELECT * FROM quote_packaging_profiles ORDER BY id DESC LIMIT 1000'):qo_rows($pdo,'SELECT * FROM quote_packaging_profiles WHERE product_code LIKE ? OR product_name LIKE ? OR customer_code LIKE ? OR packing_method LIKE ? ORDER BY id DESC LIMIT 1000',[$kw,$kw,$kw,$kw]); qo_ok(['profiles'=>$rows]); }
  if($action==='save_packaging'){ $d=qo_input(); $id=(int)($d['id']??0); $data=[qo_s($d['product_code']??'',160),qo_s($d['product_name']??'',255),qo_s($d['customer_code']??'',120),qo_num($d['unit_nw']??0),qo_num($d['unit_gw']??0),qo_num($d['pcs_per_ctn']??0),qo_num($d['carton_l']??0),qo_num($d['carton_w']??0),qo_num($d['carton_h']??0),qo_s($d['carton_size']??'',160),qo_num($d['carton_nw']??0),qo_num($d['carton_gw']??0),qo_num($d['carton_cbm']??0),qo_s($d['packing_method']??'',255),qo_s($d['note']??'',5000)]; if($id){ $pdo->prepare('UPDATE quote_packaging_profiles SET product_code=?,product_name=?,customer_code=?,unit_nw=?,unit_gw=?,pcs_per_ctn=?,carton_l=?,carton_w=?,carton_h=?,carton_size=?,carton_nw=?,carton_gw=?,carton_cbm=?,packing_method=?,note=?,updated_at=NOW() WHERE id=?')->execute(array_merge($data,[$id])); } else { $pdo->prepare('INSERT INTO quote_packaging_profiles(product_code,product_name,customer_code,unit_nw,unit_gw,pcs_per_ctn,carton_l,carton_w,carton_h,carton_size,carton_nw,carton_gw,carton_cbm,packing_method,note,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())')->execute($data); $id=(int)$pdo->lastInsertId(); } qo_ok(['profile'=>qo_row($pdo,'SELECT * FROM quote_packaging_profiles WHERE id=?',[$id]),'profiles'=>qo_rows($pdo,'SELECT * FROM quote_packaging_profiles ORDER BY id DESC LIMIT 1000')]); }
  if($action==='delete_packaging'){ $d=qo_input(); $pdo->prepare('DELETE FROM quote_packaging_profiles WHERE id=?')->execute([(int)($d['id']??0)]); qo_ok(['profiles'=>qo_rows($pdo,'SELECT * FROM quote_packaging_profiles ORDER BY id DESC LIMIT 1000')]); }
  if($action==='save_payment'){ $d=qo_input(); $id=(int)($d['order_id']??0); if(!$id) qo_fail('缺少订单ID'); $pdo->prepare('INSERT INTO quote_order_payments(order_id,payment_type,payment_date,amount,currency,method,bank_ref,note,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?,NOW())')->execute([$id,qo_s($d['payment_type']??'',80),qo_s($d['payment_date']??qo_today(),20)?:qo_today(),qo_num($d['amount']??0),qo_s($d['currency']??'',20),qo_s($d['method']??'',120),qo_s($d['bank_ref']??'',160),qo_s($d['note']??'',5000),qo_actor()]); qo_ok(qo_recalc_payment($pdo,$id)); }
  if($action==='delete_payment'){ $d=qo_input(); $pid=(int)($d['id']??0); $r=qo_row($pdo,'SELECT order_id FROM quote_order_payments WHERE id=?',[$pid]); $pdo->prepare('DELETE FROM quote_order_payments WHERE id=?')->execute([$pid]); qo_ok($r?qo_recalc_payment($pdo,(int)$r['order_id']):[]); }
  if($action==='clear_quote_order_test_data'){ $d=qo_input(); if(($d['confirm']??'')!=='CLEAR_TEST_DATA') qo_fail('确认码错误'); $tables=['quote_shipment_cartons','quote_shipment_items','quote_shipments','quote_order_payments','quote_sales_order_items','quote_sales_orders','quote_orders']; $del=[]; foreach($tables as $t){ if(qo_table_exists($pdo,$t)){ $del[$t]=(int)$pdo->query('SELECT COUNT(*) FROM `'.$t.'`')->fetchColumn(); $pdo->exec('DELETE FROM `'.$t.'`'); } } qo_ok(['deleted'=>$del]); }
  qo_fail('未知订单接口 action：'.$action);
}catch(Throwable $e){ qo_fail('订单接口异常：'.$e->getMessage()); }
