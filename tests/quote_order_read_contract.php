<?php
if(PHP_SAPI!=='cli')exit(2);
$root=dirname(__DIR__);$api=file_get_contents($root.'/quote_order_api.php');$read=file_get_contents($root.'/includes/quote_read_projection.php');$doc=file_get_contents($root.'/quote_order_doc.php');
function qr_test_body($source,$name){$start=strpos($source,'function '.$name.'(');$end=strpos($source,"\nfunction ",$start+1);if($start===false||$end===false)throw new RuntimeException('Missing function '.$name);return substr($source,$start,$end-$start);}
$checks=[
 'order projection excludes both full payloads'=>strpos($read,"['items_json','snapshot_json']")!==false,
 'item projection excludes raw snapshots and images'=>strpos($read,"['image','item_json']")!==false,
 'prepare is read only'=>!preg_match('/qo_recalc_payment|qo_update_item_shipped|qo_rebuild_order_items|UPDATE |DELETE |INSERT /',qr_test_body($api,'qo_prepare_items').qr_test_body($api,'qo_prepare_context').qr_test_body($api,'qo_prepare_combined_shipment')),
 'combined supports summary only'=>strpos(qr_test_body($api,'qo_prepare_combined_shipment'),'$summaryOnly')!==false,
 'snapshot copied in database transaction'=>strpos($read,'$pdo->inTransaction()')!==false && strpos($read,'SET s.image=i.image,s.item_json=i.item_json')!==false,
 'shipment changes share locks'=>strpos(qr_test_body($api,'qo_create_shipment'),'qr_with_order_locks')!==false && strpos(qr_test_body($api,'qo_update_shipment'),'qr_with_order_locks')!==false && strpos(qr_test_body($api,'qo_delete_shipment'),'qr_with_order_locks')!==false,
 'image action follows existing authentication'=>strpos($api,'artdon_sso_require_api')<strpos($api,"if(\$action==='item_image')"),
 'image streaming uses bounded chunks'=>strpos($read,'?,65536)')!==false && strpos($read,'base64_decode($encoded,true)')!==false,
 'documents use metadata only for order header'=>strpos($doc,'$order=qr_document_order(')!==false,
 'documents do not duplicate images in JSON'=>strpos($doc,"unset(\$it['item_json'],\$it['image']")!==false,
 'statement paths use projected items'=>substr_count($api,'"SELECT ".qr_item_columns($pdo)." FROM quote_sales_order_items WHERE order_id IN')===2,
];
foreach($checks as $label=>$ok)if(!$ok)throw new RuntimeException($label);
echo 'Order read/memory contracts: '.count($checks)." passed\n";
