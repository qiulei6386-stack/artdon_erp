<?php
$root=dirname(__DIR__);
$api=file_get_contents($root.'/quote_order_api.php');
$doc=file_get_contents($root.'/quote_order_doc.php');
$excel=file_get_contents($root.'/quote_order_excel.php');
$ui=file_get_contents($root.'/quotation.php');

$checks=[
  'shipment-order relation table is ensured'=>strpos($api,'CREATE TABLE IF NOT EXISTS quote_shipment_orders')!==false,
  'combined shipment prepare action exists'=>strpos($api,"prepare_combined_shipment")!==false && strpos($api,'function qo_prepare_combined_shipment')!==false,
  'combined shipments are limited to same customer'=>strpos($api,'function qo_validate_same_customer_orders')!==false && strpos($api,'只能合并同一客户的订单出货')!==false,
  'multi-order item validation exists'=>strpos($api,'function qo_shipment_validate_multi_items')!==false,
  'create accepts order_ids'=>strpos($api,'function qo_requested_order_ids')!==false && strpos($api,'order_ids')!==false,
  'create writes shipment order relation'=>strpos($api,'qo_sync_shipment_orders($pdo,$shipmentId,$orders)')!==false,
  'edit/delete recalc all related orders'=>strpos($api,'qo_shipment_order_ids($pdo,$shipmentId,$shipment)')!==false && strpos($api,'qo_recalc_orders($pdo,$orderIds)')!==false,
  'order detail includes joined combined shipments'=>strpos($api,'quote_shipment_orders so ON so.shipment_id=s.id')!==false,
  'document items join source order no'=>strpos($doc,'SELECT si.*,o.order_no,o.quote_no,o.customer_name FROM quote_shipment_items si LEFT JOIN quote_sales_orders o ON o.id=si.order_id')!==false,
  'CI fallback key includes order source'=>strpos($doc,"'source:'.\$source.'|signature:'.\$customer")!==false,
  'HTML CI renders Order No column'=>strpos($doc,'<th>Order No.</th>')!==false && strpos($doc,"qd_h(\$it['order_no']??'')")!==false,
  'Excel CI renders Order No column'=>strpos($excel,"['Picture','Order No.','Size'")!==false,
  'Excel PL renders Order No column'=>strpos($excel,"['Picture','Order No.','Customer'")!==false,
  'UI has combined shipment entry'=>strpos($ui,'openCombinedShipmentModal')!==false && strpos($ui,'合并出货')!==false,
  'UI submits order ids and row order id'=>strpos($ui,'order_ids:orderIds')!==false && strpos($ui,'data-order-id')!==false && strpos($ui,'order_id:Number(tr.dataset.orderId||0)')!==false,
];

$failed=[];
foreach($checks as $label=>$ok){ if(!$ok)$failed[]=$label; }
if($failed){
  fwrite(STDERR,"Quote combined shipment contract failed:\n- ".implode("\n- ",$failed)."\n");
  exit(1);
}
echo "Quote combined shipment contract passed (".count($checks)." checks)\n";
