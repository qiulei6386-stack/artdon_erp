<?php
/* ARTDON_QUOTE_ORDER_DOC_V6_8_5_54 PL uppercase title */
if (file_exists(__DIR__.'/includes/artdon_sso_core.php')) {
  require_once __DIR__.'/includes/artdon_sso_core.php';
  if (function_exists('artdon_sso_require_page')) artdon_sso_require_page('quote');
}
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/quote_read_projection.php';
require_once __DIR__ . '/includes/quote_shipment_document.php';
if(function_exists('artdon_sso_can_feature')&&!artdon_sso_can_feature('quote','export'))artdon_sso_forbidden_page('quote');
if (session_status() === PHP_SESSION_NONE) { @session_name('ARTDON_SYS'); @session_start(); }
$pdo=db();
// New adjustable batches always use their immutable signed document version.
try{
  $qbId=(int)($_GET['shipment_id']??$_GET['id']??0);
  if($qbId>0){
    $qbStmt=$pdo->prepare('SELECT p.id,MAX(d.version) version FROM quote_shipment_plans p JOIN quote_shipment_plan_documents d ON d.plan_id=p.id WHERE p.shipment_id=? GROUP BY p.id');
    $qbStmt->execute([$qbId]);$qbDoc=$qbStmt->fetch(PDO::FETCH_ASSOC);
    if($qbDoc){
      header('Location: quote_batch_document.php?id='.(int)$qbDoc['id'].'&version='.(int)$qbDoc['version'].'&type='.(($_GET['type']??'pl')==='ci'?'ci':'pl').'&format='.rawurlencode((string)($_GET['format']??'html')));exit;
    }
  }
}catch(PDOException $e){if($e->getCode()!=='42S02')throw $e;}
require_once __DIR__.'/includes/quote_document_template.php';

$shipmentId=(int)($_GET['shipment_id']??0);$type=($_GET['type']??'pl')==='ci'?'ci':'pl';$format=strtolower((string)($_GET['format']??'html'));if($shipmentId<=0){http_response_code(400);echo 'Missing shipment_id';exit;}
$ship=qd_row($pdo,'SELECT * FROM quote_shipments WHERE id=? LIMIT 1',[$shipmentId]);if(!$ship){http_response_code(404);echo 'Shipment not found';exit;}
$docStatus=qd_s($ship[$type.'_status']??'');if($docStatus==='')$docStatus='active';
if($docStatus==='deleted'){
  http_response_code(410);
  header('Content-Type: text/html; charset=utf-8');
  $deletedAt=qd_h($ship[$type.'_deleted_at']??'');$deletedBy=qd_h($ship[$type.'_deleted_by']??'');$deleteReason=qd_h($ship[$type.'_delete_reason']??'');
  echo '<!doctype html><html><head><meta charset="utf-8"><title>单证已删除</title><style>body{font-family:Arial,"Microsoft YaHei",sans-serif;background:#f6f8fb;color:#111827}.box{max-width:760px;margin:96px auto;background:#fff;border:1px solid #dbe4f0;border-radius:16px;padding:28px;box-shadow:0 18px 50px rgba(15,23,42,.08)}h1{margin:0 0 12px}.meta{color:#64748b;line-height:1.8}.btn{display:inline-block;margin-top:18px;padding:10px 14px;border-radius:10px;background:#2563eb;color:#fff;text-decoration:none}</style></head><body><div class="box"><h1>该单证已删除</h1><div class="meta">单证类型：'.qd_h($type==='ci'?'Commercial Invoice':'Packing List').'<br>删除人：'.$deletedBy.'<br>删除时间：'.$deletedAt.'<br>原因：'.$deleteReason.'</div><a class="btn" href="javascript:history.back()">返回</a></div></body></html>';
  exit;
}
$order=qr_document_order($pdo,(int)$ship['order_id']);if(!$order){http_response_code(404);echo 'Order not found';exit;}
$shipmentItems=qd_rows($pdo,'SELECT '.qr_item_columns($pdo,'quote_shipment_items','si',true,false).',o.order_no,o.quote_no,o.customer_name FROM quote_shipment_items si LEFT JOIN quote_sales_orders o ON o.id=si.order_id WHERE si.shipment_id=? ORDER BY si.order_id,si.item_index,si.id',[$shipmentId]);
try{foreach($shipmentItems as &$documentItem)$documentItem['image']=qsd_item_thumbnail($pdo,(int)$documentItem['id']);unset($documentItem);}catch(Throwable $e){http_response_code(503);header('Content-Type: text/html; charset=utf-8');exit('单证图片处理失败：'.qsd_h($e->getMessage()));}
try{$items=qd_build_document_items($pdo,$order,$shipmentItems);}catch(Throwable $e){http_response_code(503);header('Content-Type: text/html; charset=utf-8');exit('单证明细处理失败：'.qsd_h($e->getMessage()));}
if(isset($qbFrozen)&&is_array($qbFrozen)){
  $order['customer_json']=json_encode(['company'=>$qbFrozen['customer_name']??'','address'=>$qbFrozen['consignee']??''],JSON_UNESCAPED_UNICODE);
  $order['header_json']=json_encode(['company'=>$qbFrozen['seller_name']??'','from_text'=>$qbFrozen['seller_text']??''],JSON_UNESCAPED_UNICODE);
  $order['bank_json']=json_encode(['text'=>$qbFrozen['bank_text']??''],JSON_UNESCAPED_UNICODE);
}
$ciItems=qd_build_ci_items($items);
$cartons=qd_rows($pdo,'SELECT * FROM quote_shipment_cartons WHERE shipment_id=? ORDER BY id',[$shipmentId]);
$plItems=$type==='pl'?array_merge($items,qd_carton_pl_rows($cartons,$items)):$items;
$order['_shipment_order_refs']=qd_shipment_order_refs($items,$order);
$settings=qd_settings($pdo);$customer=qd_customer_from_order($order);list($sellerName,$sellerText)=qd_header_seller($order,$settings);$docTitle=$type==='ci'?'COMMERCIAL INVOICE':'PACKING LIST';$docFileTitle=qd_doc_file_title($type,$order);$docNo=$type==='ci'?($ship['commercial_invoice_no']??''):($ship['packing_list_no']??'');if($docNo==='')$docNo=$docTitle.'-'.$shipmentId;$docVoided=$docStatus==='voided';$voidText='作废人：'.qd_s($ship[$type.'_voided_by']??'').' ｜ 作废时间：'.qd_s($ship[$type.'_voided_at']??'').' ｜ 原因：'.qd_s($ship[$type.'_void_reason']??'');
// Preview/download never issues documents or changes shipment facts.
if($format==='xls'||$format==='xlsx'||$format==='excel'){
  require_once __DIR__.'/quote_order_excel.php';
  if(function_exists('qoe_export_document_xlsx')){
    qoe_export_document_xlsx($type,$order,$ship,$type==='ci'?$ciItems:$items,$cartons,$settings,$customer,$sellerName,$sellerText,$docTitle,$docFileTitle);
  }
  exit;
}else{ header('Content-Type: text/html; charset=utf-8'); }
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
if($format==='pdf')ob_start();
require __DIR__.'/includes/quote_document_page.php';
if($format==='pdf'){$rendered=ob_get_clean();try{qsd_download_pdf($pdo,$rendered,'SHIP-'.$shipmentId.'-'.strtoupper($type));}catch(Throwable $e){http_response_code(503);header('Content-Type: text/html; charset=utf-8');echo '<meta charset="utf-8">单证生成失败：'.qsd_h($e->getMessage());}} ?>
