<?php
// Included only from the guarded, isolated shipment fixture.
$pdo->exec("INSERT INTO quote_packaging_profiles VALUES(1,'SAME','WH',16,'40x36x30',11.6,12.6,0,0,0.0432,'test'),(2,'SAME','',8,'30x20x20',4,5,0,0,0.012,'generic')");
$options=qsl_pack_options($pdo,['product_code'=>'SAME','customer_code'=>'WH']);i7_check($options[0]['match_kind']==='exact'&&$options[1]['match_kind']==='generic','Exact profile precedes generic');
$options=qsl_pack_options($pdo,['product_code'=>'SAME','customer_code'=>'BK']);i7_check($options[0]['match_kind']==='generic'&&$options[1]['match_kind']==='alternative','Other variant not auto-matched');
$pdo->exec("UPDATE quote_sales_orders SET status='已完结',shipment_status='已出货' WHERE id=4");
i7_check(!in_array(4,array_column(qb_candidates($pdo,['order_id'=>5])['orders'],'id')),'Closed shipped order absent from candidates');
i7_check(qb_items($pdo,4)['items'][0]['available_qty']==0,'Direct item route also blocks closed order');
$pdo->exec("UPDATE quote_sales_orders SET status='已确认',shipment_status='未出货' WHERE id=4");
$pdo->exec('UPDATE quote_sales_order_items SET shipped_qty=100 WHERE id=41');
i7_check(!in_array(4,array_column(qb_candidates($pdo,['order_id'=>5])['orders'],'id')),'Conflicting shipment cache excluded');
i7_check(strpos(qb_items($pdo,4)['blocked_reason'],'不一致')!==false,'Conflict reason returned');
$pdo->exec('UPDATE quote_sales_order_items SET shipped_qty=0 WHERE id=41');
$all=['base_order_id'=>4,'ship_date'=>'2026-09-24','consignee'=>'Acceptance','items'=>[btest_item($pdo,4,41,100)],'cartons'=>[]];
$full=qb_mutate($pdo,'save',btest_request()+['data'=>$all]);
i7_check(!in_array(4,array_column(qb_candidates($pdo,['order_id'=>5])['orders'],'id')),'Fully reserved order excluded');
i7_check(in_array(4,array_column(qb_candidates($pdo,['order_id'=>4,'plan_id'=>$full['id']])['orders'],'id')),'Own plan reservation excluded while editing');
$list=qsl_batches($pdo,4);i7_check(in_array($full['id'],array_column($list['batches'],'id')),'Planning batch visible in order details');
qb_mutate($pdo,'cancel',btest_request($full['id'],1));
$box=['carton_no'=>'Tail','carton_count'=>1,'nw'=>1,'gw'=>2,'weight_estimated'=>true,'weight_confirmed'=>false,'items'=>[['order_item_id'=>41,'qty'=>3]]];
btest_reject(fn()=>qb_cartons([$box],[['order_item_id'=>41,'qty'=>3]],true),'Unconfirmed estimated tail weight cannot issue');
$box['weight_confirmed']=true;i7_check(qb_cartons([$box],[['order_item_id'=>41,'qty'=>3]],true)['totals']['qty']==3,'Confirmed tail accepted');
// Read catalog never changes documents, plans or historical shipment quantities.
$before=$pdo->query('SELECT SUM(qty) FROM quote_shipment_items')->fetchColumn();$a=qsl_batches($pdo,4);$b=qsl_batches($pdo,5);i7_check($before===$pdo->query('SELECT SUM(qty) FROM quote_shipment_items')->fetchColumn(),'Document lookup read-only');
echo "Shipment fixes: closed/conflicting/reserved candidates, direct guards, packaging specificity, plan visibility and tail confirmation passed\n";
require_once dirname(__DIR__).'/includes/quote_shipment_document.php';
$pdo->exec("INSERT INTO quote_shipment_items(shipment_id,order_id,order_item_id,image,item_json) VALUES(999,999,999,'',JSON_OBJECT('product',JSON_OBJECT('image','uploads/synthetic.png')))");
$fixtureId=(int)$pdo->lastInsertId();i7_check(qsd_item_thumbnail($pdo,$fixtureId)==='uploads/synthetic.png','Document image uses nested historical fallback without reading full snapshots');
$projection=$pdo->query('SELECT '.qr_item_columns($pdo,'quote_shipment_items','',true,false).' FROM quote_shipment_items WHERE id='.$fixtureId)->fetch();i7_check(!isset($projection['image']),'Document text projection excludes original image');
$pdo->exec('UPDATE quote_shipment_items SET image=REPEAT("x",16777217) WHERE id='.$fixtureId);btest_reject(fn()=>qsd_item_thumbnail($pdo,$fixtureId),'Oversized image rejected in SQL before loading it into PHP');
