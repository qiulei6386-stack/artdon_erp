<?php
if(PHP_SAPI!=='cli')exit(2);
require_once dirname(__DIR__).'/includes/quote_shipment_batch.php';
function qo_num($v){return is_numeric($v)?(float)$v:0;}
function qo_s($v,$max=5000){return substr(trim((string)$v),0,$max);}
function bc_check($condition,$message){if(!$condition)throw new RuntimeException($message);}
function bc_reject($fn,$message){$caught=false;try{$fn();}catch(RuntimeException $e){$caught=true;}bc_check($caught,$message);}
$a=['customer_id'=>'A','customer_name'=>'Old Name','currency'=>'USD'];
bc_check(qb_compatible($a,['customer_id'=>'A','customer_name'=>'New Name','currency'=>'USD'])==='','Stable customer identity survives rename');
bc_check(qb_compatible($a,['customer_id'=>'B','customer_name'=>'Old Name','currency'=>'USD'])!=='','Identical names cannot merge distinct customers');
bc_check(qb_compatible($a,['customer_id'=>'A','currency'=>'RMB'])!=='','Different currencies cannot silently merge');
foreach([-1,INF,NAN,'bad'] as $bad)bc_reject(fn()=>qb_quantity($bad,'数量'),'Invalid quantity rejected');
$items=[['order_item_id'=>1,'qty'=>10],['order_item_id'=>2,'qty'=>20]];
$box=['carton_no'=>'1','nw'=>2,'gw'=>3,'cbm'=>.1,'items'=>[['order_item_id'=>1,'qty'=>10],['order_item_id'=>2,'qty'=>20]]];
$result=qb_cartons([$box],$items,true);bc_check($result['totals']['qty']==30&&$result['totals']['cartons']==1&&$result['totals']['gw']==3,'Mixed carton counted once');
$bad=$box;$bad['items'][0]['qty']=11;$bad['items'][1]['qty']=19;bc_reject(fn()=>qb_cartons([$bad],$items,true),'Equal total cannot hide per-product mismatch');
bc_reject(fn()=>qb_cartons([$box,$box],$items),'Duplicate carton number rejected');
bc_reject(fn()=>qb_cartons([],$items,true),'Unpacked products block issuing and dispatch');
bc_check(qb_cartons([],$items)['totals']['unpacked_qty']==30,'Incomplete packing may be saved as plan');
$group=$box;$group['carton_no']='1-3';$group['carton_count']=3;
bc_check(qb_cartons([$group],$items,true)['totals']['cartons']==3,'Carton groups count boxes without multiplying allocated goods or weight');
$badGroup=$group;$badGroup['carton_count']=2;bc_reject(fn()=>qb_cartons([$badGroup],$items),'Carton range must match count');
bc_reject(fn()=>qb_cartons([$group,$box],$items),'Overlapping carton numbers rejected');
$api=file_get_contents(dirname(__DIR__).'/quote_order_api.php');
foreach(["'reverse'=>'admin'","'issue'=>'export'","'dispatch'=>'edit'","if(!verify_csrf())","'force',[],'admin'"] as $guard)bc_check(strpos($api,$guard)!==false,'Permission/CSRF guard '.$guard);
$source=file_get_contents(dirname(__DIR__).'/includes/quote_shipment_batch.php');
foreach(['request_hash','GET_LOCK','version','inTransaction()','quote_shipment_reversed_items','source_hash'] as $guard)bc_check(strpos($source,$guard)!==false,'Lifecycle protection '.$guard);
echo "Shipment batch contract: stable customers, quantities, per-product carton conservation, incomplete plans, permission and CSRF guards passed.\n";
