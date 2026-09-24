<?php
// Actual shipment save/validation/relationship SQL; isolated disposable MySQL only.
$socket=(string)getenv('CRM_PHASE1_MYSQL_SOCKET');$schema=(string)getenv('CRM_PHASE1_MYSQL_SCHEMA');
if(PHP_SAPI!=='cli'||getenv('CRM_PHASE1_MYSQL_TEST')!=='1'||!preg_match('#^/tmp/crm-phase1-mysql-20260906-[A-Za-z0-9]{8}/mysql.sock$#D',$socket)||!preg_match('/^quote_money_[a-f0-9]{12}$/D',$schema))throw new RuntimeException('Refusing non-sandbox database');
$pdo=new PDO('mysql:unix_socket='.$socket.';dbname='.$schema.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$identity=$pdo->query('SELECT @@socket socket,@@datadir datadir,@@skip_networking isolated')->fetch();
if($identity['socket']!==$socket||$identity['datadir']!==dirname($socket).'/data/'||(int)$identity['isolated']!==1)throw new RuntimeException('Instance mismatch');
if((int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()!==0)throw new RuntimeException('Fresh schema required');
require __DIR__.'/issues7_regression.php';
require_once dirname(__DIR__).'/includes/quote_read_projection.php';
require_once dirname(__DIR__).'/includes/quote_shipment_document.php';
foreach(['qo_create_shipment_locked','qo_update_shipment_locked','qo_prepare_context','qo_prepare_combined_shipment','qo_prepare_items','qo_item_row_has_content','qo_order_items_table_has_content'] as $fn)i7_function('quote_order_api.php',$fn);
foreach(['qo_s','qo_rows','qo_row','qo_order_no_at','qo_order_ref','qo_customer_name_key','qo_customer_key','qo_load_orders','qo_validate_same_customer_orders','qo_sync_shipment_orders','qo_shipment_validate_multi_items','qo_shipment_order_ids','qo_create_shipment','qo_update_shipment','qo_shipment_can_edit','qo_shipment_require_editable','qo_today','qo_shipment_read_totals'] as $fn)i7_function('quote_order_api.php',$fn);
function qo_fail($message){throw new RuntimeException($message);}
function qo_ensure_schema($pdo){i7_check(!$pdo->inTransaction(),'No DDL inside shipment transaction');}
function qo_commission_schema($pdo){qo_ensure_schema($pdo);}
function qo_recalc_payment($pdo,$id){$GLOBALS['i7Pay'][]=$id;return ['balance_amount'=>0];}
function qo_recalc_orders($pdo,$ids){if($GLOBALS['i7FailRecalc']??false)throw new RuntimeException('Injected rollback');$GLOBALS['i7Recalced']=$ids;}
function qo_next_doc_numbers($pdo,$id,$date){return ['shipment_no'=>'ACCEPTANCE-'.$id,'packing_list_no'=>'PL-ACCEPTANCE','commercial_invoice_no'=>'CI-ACCEPTANCE','settings'=>[]];}
function qo_actor(){return '验收测试';}
foreach(['qo_json','qo_product','qo_nested_item','qo_virtual_item_text','qo_is_virtual_charge_text','qo_is_virtual_item'] as $fn)i7_function('quote_order_api.php',$fn);
function qo_pack_options($pdo,$product,$customer){return [];}
function qo_pack_match($pdo,$product,$customer){return null;}
function qo_push_quote_sys_notification($pdo,...$args){i7_check(!$pdo->inTransaction(),'Notification after commit only');}
// Types are explicit; no live schema or production configuration is loaded.
$pdo->exec("CREATE TABLE quote_sales_orders(id INT PRIMARY KEY,order_no VARCHAR(100),quote_no VARCHAR(100),customer_id VARCHAR(100),customer_name VARCHAR(100),currency VARCHAR(20)) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE quote_sales_order_items(id INT PRIMARY KEY,order_id INT,item_index INT,qty DECIMAL(12,2),unit_price DECIMAL(12,4),product_code VARCHAR(100),product_name VARCHAR(100),is_virtual INT DEFAULT 0) ENGINE=InnoDB");
$pdo->exec("INSERT INTO quote_sales_orders VALUES(1,'AT-TEST-1','Q-1','T','验收测试','USD'),(2,'AT-TEST-2','Q-2','T','验收测试','USD'),(3,'AT-OTHER','Q-3','X','验收不同客户','USD')");
$pdo->exec("INSERT INTO quote_sales_order_items(id,order_id,item_index,qty,unit_price,product_code,product_name) VALUES(11,1,1,20,2,'A','验收 A'),(21,2,1,20,3,'B','验收 B'),(31,3,1,20,4,'C','验收 C')");
$pdo->exec("ALTER TABLE quote_sales_orders ADD items_json LONGTEXT,ADD snapshot_json LONGTEXT,ADD status VARCHAR(80) DEFAULT '已确认',ADD amount DECIMAL(14,2) DEFAULT 100,ADD order_date DATE,ADD created_at DATETIME");
$pdo->exec("ALTER TABLE quote_sales_order_items ADD image LONGTEXT,ADD item_json LONGTEXT,ADD customer_code VARCHAR(120) DEFAULT '',ADD specification TEXT,ADD shipped_qty DECIMAL(14,3) DEFAULT 0");
$pdo->exec("CREATE TABLE quote_order_payments(order_id INT,amount DECIMAL(14,2),commission_deduct_amount DECIMAL(14,2),writeoff_amount DECIMAL(14,2))");
// All rows together exceed PHP's 128 MiB; the actual preparation and save must not fetch these.
$pdo->exec("UPDATE quote_sales_orders SET items_json=REPEAT('x',24*1024*1024),snapshot_json=REPEAT('s',24*1024*1024)");
$pdo->exec("UPDATE quote_sales_order_items SET image=CONCAT('data:image/png;base64,',REPEAT('a',8*1024*1024)),item_json=JSON_OBJECT('product',JSON_OBJECT('image',REPEAT('b',8*1024*1024)),'shippable',true)");
$api=file_get_contents(dirname(__DIR__).'/quote_order_api.php');
require_once dirname(__DIR__).'/includes/quote_order_paging.php';
op_install($pdo); // All actual shipment writes below must work with maintained classification.
foreach(['quote_shipments','quote_shipment_items','quote_shipment_orders','quote_shipment_cartons'] as $table){
    preg_match('/INSERT INTO '.preg_quote($table,'/').'\(([^)]+)\) VALUES/',$api,$match);
    i7_check(!empty($match[1]),'Schema column extraction '.$table);$cols=[];
    foreach(explode(',',$match[1]) as $col){$type=$col!=='customer_id' && preg_match('/(^|_)(id|index|order|count)$/',$col)?'INT':(in_array($col,['qty','pcs_per_ctn','cartons','nw','gw','cbm','unit_price','amount','total_qty','total_cartons','total_nw','total_gw','total_cbm'],true)?'DECIMAL(18,4)':'LONGTEXT');$cols[]='`'.$col.'` '.$type;}
    if(!in_array('updated_at',explode(',',$match[1]),true))$cols[]='updated_at DATETIME NULL';
    if($table==='quote_shipments'){$cols[]='pl_generated_at DATETIME NULL';$cols[]='ci_generated_at DATETIME NULL';}
    $pdo->exec('CREATE TABLE '.$table.'(id INT AUTO_INCREMENT PRIMARY KEY,'.implode(',',$cols).') ENGINE=InnoDB');
}
$input=['order_id'=>1,'order_ids'=>[1,2],'items'=>[['order_item_id'=>11,'qty'=>0],['order_item_id'=>21,'qty'=>12]],'cartons'=>[['qty'=>12,'carton_count'=>1,'items_text'=>'B 12PCS']]];
$pdo->exec('START TRANSACTION READ ONLY');
$prepared=qo_prepare_combined_shipment($pdo,1);
i7_check(count($prepared['orders'])===2 && count($prepared['items'])===2,'Large combined read returns both orders');
i7_check(strlen(json_encode($prepared))<20000 && !isset($prepared['order']['snapshot_json']),'Preparation excludes historical bulk payloads');
$summary=qo_prepare_combined_shipment($pdo,1,true);
i7_check(count($summary['orders'])===2 && $summary['items']===[],'Summary-only supports sequential single-order loading');
$pdo->rollBack();
foreach([['is_virtual_item'=>true],['item_type'=>'virtual'],['product_type'=>'virtual'],['shippable'=>false],['shippable'=>'0'],['shippable'=>0],['shippable'=>true],['product'=>['name'=>'Freight charge']]] as $meta){
 $pdo->beginTransaction();$pdo->prepare('UPDATE quote_sales_order_items SET item_json=? WHERE id=11')->execute([json_encode($meta)]);
 $full=$pdo->query('SELECT * FROM quote_sales_order_items WHERE id=11')->fetch();
 $light=$pdo->query('SELECT '.qr_item_columns($pdo).' FROM quote_sales_order_items WHERE id=11')->fetch();
 i7_check(qo_is_virtual_item($full)===qo_is_virtual_item($light),'Projection preserves virtual/shippable semantics');unset($full,$light);$pdo->rollBack();
}
$result=qo_create_shipment($pdo,$input);$id=$result['shipment_id'];
i7_check((int)$pdo->query('SELECT COUNT(*) FROM quote_shipment_items s JOIN quote_sales_order_items i ON i.id=s.order_item_id WHERE BINARY s.image=BINARY i.image AND BINARY s.item_json=BINARY i.item_json')->fetchColumn()===1,'Original image/snapshot copied byte-for-byte inside database');
$peer=new PDO('mysql:unix_socket='.$socket.';dbname='.$schema.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
try{qr_with_order_locks($pdo,[2,1],function()use($peer){
 i7_check((int)$peer->query("SELECT GET_LOCK('quote-shipment-order:1',0)")->fetchColumn()===0,'Other connection cannot enter the same shipment order');
 throw new RuntimeException('Injected lock failure');
});}catch(RuntimeException $e){i7_check($e->getMessage()==='Injected lock failure','Unexpected lock failure');}
i7_check((int)$peer->query("SELECT GET_LOCK('quote-shipment-order:1',0)")->fetchColumn()===1,'Failure releases order lock');
$peer->query("SELECT RELEASE_LOCK('quote-shipment-order:1')");$peer=null;
require_once dirname(__DIR__).'/includes/quote_document_template.php';
$docOrder=qr_document_order($pdo,2);
$docSource=$pdo->query('SELECT '.qr_item_columns($pdo,'quote_shipment_items','',true).' FROM quote_shipment_items WHERE shipment_id='.$id)->fetchAll();
$docItems=qd_build_document_items($pdo,$docOrder,$docSource);$ci=qd_build_ci_items($docItems);
i7_check($ci[0]['qty']==12 && $ci[0]['amount']==36 && strlen($ci[0]['image'])>8000000,'CI keeps shipment quantity, amount and original image');
i7_check(strlen($ci[0]['item_json'])<10000,'Document does not duplicate images in JSON snapshots');
unset($docOrder,$docSource,$docItems,$ci);
i7_check($result['order_ids']===[2] && $result['totals']['qty']==12,'Zero-quantity order excluded');
i7_check($GLOBALS['i7Pay']===[2],'No payment gate from excluded order');
i7_check((int)$pdo->query('SELECT order_id FROM quote_shipments WHERE id='.$id)->fetchColumn()===2,'Primary relationship follows actual order');
i7_check(array_map('intval',$pdo->query('SELECT order_id FROM quote_shipment_orders')->fetchAll(PDO::FETCH_COLUMN))===[2],'Only actual order linked');
i7_check((int)$pdo->query('SELECT SUM(qty) FROM quote_shipment_items')->fetchColumn()===12,'Carton contents not inserted as more goods');
try{qo_create_shipment($pdo,['order_id'=>1,'order_ids'=>[1],'items'=>[]]);throw new LogicException('Empty items silently autofilled');}catch(RuntimeException $e){}
try{qo_shipment_validate_multi_items($pdo,[1],0,[['order_item_id'=>21,'qty'=>1]]);throw new LogicException('Foreign item accepted');}catch(RuntimeException $e){}
try{qo_shipment_validate_multi_items($pdo,[1,3],0,[['order_item_id'=>11,'qty'=>1]]);throw new LogicException('Other customer accepted');}catch(RuntimeException $e){}
try{qo_shipment_validate_multi_items($pdo,[2],0,[['order_item_id'=>21,'qty'=>9]]);throw new LogicException('Overship accepted');}catch(RuntimeException $e){}
$GLOBALS['i7FailRecalc']=true;
try{qo_create_shipment($pdo,['order_id'=>1,'order_ids'=>[1],'items'=>[['order_item_id'=>11,'qty'=>5]]]);throw new LogicException('Rollback failed');}catch(RuntimeException $e){}
i7_check((int)$pdo->query('SELECT COUNT(*) FROM quote_shipments')->fetchColumn()===1,'Failed create rolls back entire shipment');
$GLOBALS['i7FailRecalc']=false;
$both=qo_create_shipment($pdo,['order_id'=>1,'order_ids'=>[1,2],'items'=>[['order_item_id'=>11,'qty'=>3],['order_item_id'=>21,'qty'=>4]]]);
qo_update_shipment($pdo,['shipment_id'=>$both['shipment_id'],'items'=>[['order_item_id'=>11,'qty'=>0],['order_item_id'=>21,'qty'=>4]],'cartons'=>[]]);
i7_check(array_map('intval',$pdo->query('SELECT order_id FROM quote_shipment_orders WHERE shipment_id='.(int)$both['shipment_id'])->fetchAll(PDO::FETCH_COLUMN))===[2],'Edit removes zero-quantity relationship');
$pdo->exec('UPDATE quote_shipments SET total_qty=24 WHERE id='.$id);
$display=qo_shipment_read_totals($pdo,$pdo->query('SELECT * FROM quote_shipments WHERE id='.$id)->fetchAll())[0];
i7_check($display['total_qty']==12 && (int)$pdo->query('SELECT total_qty FROM quote_shipments WHERE id='.$id)->fetchColumn()===24,'Historical summary corrected by read projection, no data rewrite');
echo "Shipment MySQL: create/edit/order selection, zero rows, ownership, overship, transaction rollback, packing and historical read projection passed.\n";
echo 'Peak memory bytes: '.memory_get_peak_usage(true)." (128 MiB limit)\n";
