<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/quote_money.php';
set_error_handler(function($n,$s){throw new RuntimeException($s);});
$tests=0;
function mq_check($ok,$why){global $tests;$tests++;if(!$ok)throw new RuntimeException($why);}
function mq_reject(callable $fn,$why){try{$fn();}catch(RuntimeException $e){mq_check(true,$why);return;}throw new RuntimeException('Accepted: '.$why);}
function mq_item($price=4020,$qty=1){return ['product'=>['id'=>'test'],'currency'=>'RMB','qty'=>$qty,'price'=>$price,'amount'=>$qty*$price,'unit_price'=>600,'approved_price'=>600];}
$m=qm_calculate([mq_item()],'RMB');
mq_check($m['amount']===4020 && $m['items'][0]['unit_price']===4020.0 && $m['items'][0]['approved_price']===4020.0,'stale aliases');
foreach(['RMB','USD','EUR'] as $cur){$it=mq_item(0,0);$it['currency']=$cur;mq_check(qm_calculate([$it],$cur)['amount']===0,'zero '.$cur);}
mq_check(qm_row_cents(3,0.335)===101,'row half-up');
mq_check(qm_row_cents(1,-0.005)===-1,'negative half-up');
mq_check(qm_row_cents(1.125,2.1234)===239,'fractional quantity and price');
$m=qm_calculate([mq_item(100)],'RMB',['type'=>'discount_percent','value'=>12.5]);
mq_check($m['amount']===87.5 && $m['adjustment_amount']===-12.5,'percentage');
mq_check(qm_calculate([mq_item(100)],'RMB',['type'=>'discount_amount','value'=>200])['amount']===0,'discount capped');
mq_check(qm_calculate([mq_item(100)],'RMB',['type'=>'surcharge_amount','value'=>5])['amount']===105,'surcharge');
$discount=mq_item(-5);$discount['item_type']='virtual';$discount['virtual_type']='discount';
$m=qm_calculate([mq_item(100),$discount],'RMB');mq_check($m['amount']===95 && $m['qty']===1.0,'virtual discount and qty');
mq_reject(fn()=>qm_calculate([mq_item(-1)],'RMB'),'negative physical price');
mq_reject(fn()=>qm_calculate([mq_item(1,-1)],'RMB'),'negative qty');
mq_reject(fn()=>qm_calculate([mq_item(INF)],'RMB'),'infinite price');
mq_reject(fn()=>qm_calculate([mq_item()],'USD'),'mixed currency');
mq_reject(fn()=>qm_calculate([['qty'=>1,'unit_price'=>600]],'RMB'),'missing authoritative price');
mq_reject(fn()=>qm_assert_totals(['amount'=>600],qm_calculate([mq_item()],'RMB')),'forged total');
$d=['id'=>10,'quote_no'=>'SYNTHETIC','currency'=>'RMB','exchange_rate'=>6.7,'items_json'=>json_encode([mq_item()]),'subtotal_amount'=>4020,'adjustment_amount'=>0,'adjustment_json'=>'{}','amount'=>4020,'approval_status'=>'pending'];
qm_prepare_save($d);mq_check(json_decode($d['items_json'],true)[0]['unit_price']===4020,'save synchronizes aliases');
$token=qm_revision($d);qm_require_revision($d,$token);mq_check(true,'same revision accepted');
foreach(['currency'=>'USD','exchange_rate'=>7,'amount'=>600,'items_json'=>'[]','approval_status'=>'approved','approval_log_json'=>'[{}]'] as $k=>$v){$changed=$d;$changed[$k]=$v;mq_reject(fn()=>qm_require_revision($changed,$token),'stale '.$k);}
mq_reject(fn()=>qm_require_revision($d,null),'old client');
qm_validate_snapshot($d);mq_check(true,'valid snapshot');
$bad=$d;$bad['amount']=600;mq_reject(fn()=>qm_validate_snapshot($bad),'invalid snapshot');
$bad=$d;$it=mq_item();$it['amount']=600;$bad['items_json']=json_encode([$it]);mq_reject(fn()=>qm_validate_snapshot($bad),'invalid snapshot row');
$big=mq_item();$big['product']['image']='data:image/png;base64,'.str_repeat('A',2000000);$d['items_json']=json_encode([$big]);$brief=json_encode(qm_audit_summary($d));mq_check(strlen($brief)<2000 && strpos($brief,'4020')!==false && strpos($brief,'data:image')===false,'monetary audit ignores images');
$order=['currency'=>'RMB','amount'=>4020,'qty'=>1];qm_order_totals([mq_item()],$order);mq_check(true,'order totals');
$order['amount']=600;mq_reject(fn()=>qm_order_totals([mq_item()],$order),'order mismatch');
echo "Quote monetary contract: $tests passed\n";
