<?php
// No application credentials. Require a verified disposable socket and empty schema.
$socket=(string)getenv('CRM_PHASE1_MYSQL_SOCKET');$schema=(string)getenv('CRM_PHASE1_MYSQL_SCHEMA');
if(PHP_SAPI!=='cli'||getenv('CRM_PHASE1_MYSQL_TEST')!=='1'||!preg_match('#^/tmp/crm-phase1-mysql-20260906-[A-Za-z0-9]{8}/mysql.sock$#D',$socket)||!preg_match('/^quote_money_[a-f0-9]{12}$/D',$schema))throw new RuntimeException('Sandbox required');
$pdo=new PDO('mysql:unix_socket='.$socket.';dbname='.$schema.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$i=$pdo->query('SELECT @@socket socket,@@datadir datadir,@@skip_networking isolated')->fetch();
if($i['socket']!==$socket||$i['datadir']!==dirname($socket).'/data/'||(int)$i['isolated']!==1||(int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()!==0)throw new RuntimeException('Fresh isolated schema required');
require dirname(__DIR__).'/includes/quote_order_paging.php';
function pc($ok,$message){if(!$ok)throw new RuntimeException($message);}
$source=file_get_contents(dirname(__DIR__).'/quote_order_api.php');
foreach(['qo_ensure_schema','qo_ensure_col','qo_col_exists','qo_columns','qo_table_exists','qo_rows','qo_row','qo_num','qo_virtual_item_sql_expr','qo_order_lifecycle_status','qo_order_no_at','qo_list_orders'] as $fn){$start=strpos($source,'function '.$fn.'(');$end=strpos($source,"\nfunction ",$start+1);pc($start!==false&&$end!==false,'Function extraction');eval(substr($source,$start,$end-$start));}
pc(op_virtual_expression()===qo_virtual_item_sql_expr(),'Exact old classification, not reinterpretation');
qo_ensure_schema($pdo);
$insert=$pdo->prepare("INSERT INTO quote_sales_orders(id,order_no,quote_no,customer_id,customer_name,qty,amount,currency,order_date,created_at,user_name,paid_amount,status,shipment_status) VALUES(?,?,?,?,?,10,100,?,'2026-09-15','2026-09-15 08:00:00',?,?,'已确认','未出货')");
for($n=1;$n<=107;$n++){
 $insert->execute([$n,'AT-TEST-'.str_pad($n,3,'0',STR_PAD_LEFT),'Q-'.$n,$n%2?'A':'B',$n%2?'验收 A':'验收 B',$n%3?'USD':'RMB',$n%2?'Amy':'Ben',$n===1?20:0]);
 $pdo->exec("INSERT INTO quote_sales_order_items(order_id,qty,shipped_qty,product_code,product_name,item_json) VALUES($n,10,".($n%4===0?10:($n%4===1?3:0)).",'LIGHT-$n','Lamp','{}')");
}
$pdo->exec("INSERT INTO quote_order_payments(order_id,amount,commission_deduct_amount,writeoff_amount) VALUES(2,25,10,5),(4,100,0,0),(5,0,100,0),(6,0,0,100)");
$pdo->exec("INSERT INTO quote_sales_order_items(order_id,qty,shipped_qty,product_name,item_json) VALUES(2,1,1,'Freight charge','{}'),(3,1,1,'Fee','{\"shippable\":false}')");
$pdo->exec("UPDATE quote_sales_orders SET status='已作废' WHERE id=7");
$pdo->exec("UPDATE quote_sales_orders SET status='已完结' WHERE id=8");
$pdo->exec("UPDATE quote_sales_orders SET amount=0 WHERE id=9");
$pdo->exec("UPDATE quote_sales_order_items SET image=REPEAT('p',8*1024*1024),item_json=CONCAT('{\"image\":\"',REPEAT('a',8*1024*1024),'\"}') WHERE order_id=1");
$digest=$pdo->query('SELECT id,SHA2(image,256) image,SHA2(item_json,256) json,qty,shipped_qty FROM quote_sales_order_items ORDER BY id')->fetchAll();
$old=qo_list_orders($pdo);op_install($pdo);op_install($pdo);
pc($digest===$pdo->query('SELECT id,SHA2(image,256) image,SHA2(item_json,256) json,qty,shipped_qty FROM quote_sales_order_items ORDER BY id')->fetchAll(),'Migration keeps all original bytes and quantities');
$pdo->exec('START TRANSACTION READ ONLY');
$start=microtime(true);$first=op_page($pdo,['overview'=>true]);$elapsed=microtime(true)-$start;
pc(count($first['orders'])===20&&$first['total']===107&&$first['pages']===6,'True page boundaries');
pc(strlen(json_encode($first))<45000,'Bounded page payload excludes large JSON/images');
pc($first['overview']['count']===107&&count($first['overview']['customers'])===2,'Global overview/dictionaries');
$seen=[];for($p=1;$p<=6;$p++){foreach(op_page($pdo,['page'=>$p])['orders'] as $r){pc(!isset($seen[$r['id']]),'No page duplicates');$seen[$r['id']]=$r;}}
pc(count($seen)===107,'No missing rows');
foreach($old as $o){$new=$seen[$o['id']];foreach(['qty','amount','paid_amount','balance_amount','status','shipment_status','payment_status','commission_deduct_amount','writeoff_amount'] as $k)pc((string)$new[$k]===(string)$o[$k]||is_numeric($new[$k])&&abs((float)$new[$k]-(float)$o[$k])<0.000001,'Legacy equivalence '.$o['id'].' '.$k);}
pc(op_page($pdo,['search'=>'AT-TEST-001'])['orders'][0]['id']==1,'Search beyond first page');
pc(op_page($pdo,['search'=>'Amy 验收 A'])['total']===54,'AND search plus owner');
pc(op_page($pdo,['search'=>"%' OR 1=1 --"])['total']===0,'Bound parameters and escaped wildcards');
pc(op_page($pdo,['customer'=>'验收 B','owner'=>'Ben'])['total']===53,'Full filters');
pc(op_page($pdo,['status'=>'已收齐'])['total']===4,'Payments, deductions, writeoffs and zero amounts');
pc(op_page($pdo,['page'=>999])['page']===6,'Clamp out-of-range page');
pc(count(op_page($pdo,['size'=>50])['orders'])===50,'50-row option');
foreach([5,10] as $size){$small=op_page($pdo,['size'=>$size]);pc(count($small['orders'])===$size&&$small['pages']===(int)ceil(107/$size),'Compact page size');}
pc(op_page($pdo,['from'=>'2026-09-16'])['total']===0,'Date boundaries');
$pdo->rollBack();
$pdo->exec("UPDATE quote_sales_order_items SET product_name='Shipping fee' WHERE order_id=10");
pc((int)$pdo->query('SELECT qo_virtual_line_v1 FROM quote_sales_order_items WHERE order_id=10')->fetchColumn()===1,'Future edits automatically maintain classification');
$pdo->exec("UPDATE quote_sales_order_items SET product_name='Lamp' WHERE order_id=10");
pc((int)$pdo->query('SELECT qo_virtual_line_v1 FROM quote_sales_order_items WHERE order_id=10')->fetchColumn()===0,'Reverting fee classification automatic');
echo 'Order paging MySQL: legacy totals/status, complete filters, 107 rows, page consistency, original bytes and generated maintenance passed; first-page '.round($elapsed*1000).'ms, peak '.memory_get_peak_usage(true)." bytes\n";
