<?php
// Disposable socket-only database. Does not include bootstrap or production config.
declare(strict_types=1);
$socket=(string)getenv('CRM_PHASE1_MYSQL_SOCKET');
$schema=(string)getenv('CRM_PHASE1_MYSQL_SCHEMA');
if(getenv('CRM_PHASE1_MYSQL_TEST')!=='1' || !preg_match('#^/tmp/crm-phase1-mysql-20260906-[A-Za-z0-9]{8}/mysql.sock$#D',$socket) || !preg_match('/^quote_conversion_[a-f0-9]{12}$/D',$schema)) throw new RuntimeException('Refusing non-sandbox database');
$pdo=new PDO('mysql:unix_socket='.$socket.';dbname='.$schema.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$identity=$pdo->query('SELECT @@socket socket,@@datadir datadir,@@skip_networking isolated')->fetch(PDO::FETCH_ASSOC);
if($identity['socket']!==$socket || $identity['datadir']!==dirname($socket).'/data/' || (int)$identity['isolated']!==1) throw new RuntimeException('Sandbox identity mismatch');
$source=file_get_contents(__DIR__.'/../quote_order_api.php');
$start=strpos($source,'function qo_ok(');
$end=strpos($source,"try{\n  \$commissionActions=");
if($start===false || $end===false) throw new RuntimeException('API function boundary missing');
eval(substr($source,$start,$end-$start));
unset($source);
require __DIR__.'/../includes/quote_order_conversion.php';
$_SESSION=['username'=>'验收测试'];
function check($ok,$why){if(!$ok) throw new RuntimeException($why);}
function payload($number){return ['order_no'=>$number,'quote_no'=>'验收测试来源','customer_name'=>'验收测试客户','customer_json'=>'{"name":"验收测试客户"}','items_json'=>'[{"product":{"code":"TEST","name":"Test"},"qty":2,"price":3,"amount":6}]','snapshot_json'=>'{"test":true}','currency'=>'USD','amount'=>6,'qty'=>2,'commission_choice'=>'none'];}
if(($argv[1]??'')==='--worker'){
  $_SESSION['quote_order_schema_checked_v68555']=time();
  $d=payload($argv[2]);
  try{$r=qo_convert_order($pdo,$d);echo json_encode(['ok'=>true,'id'=>$r['id']]);}
  catch(Throwable $e){echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);}
  exit;
}
qo_ensure_schema($pdo);qo_commission_schema($pdo);
$pdo->exec('CREATE TABLE quote_orders(id INT AUTO_INCREMENT PRIMARY KEY,quote_no VARCHAR(120),converted_order_id INT DEFAULT 0,converted_order_no VARCHAR(120) DEFAULT "") ENGINE=InnoDB');
$pdo->exec("INSERT INTO quote_orders(quote_no) VALUES('验收测试来源')");

// Match the legacy PI shape: images in item_json, row image, original items and order_items.
ini_set('memory_limit','512M');
$items=[];$original=[];
foreach([2017830,966890,3666362,442398] as $i=>$bytes){
  $image='data:image/png;base64,'.str_repeat(chr(65+$i),$bytes-22);
  $base=['product'=>['code'=>'TEST-'.$i,'name'=>'验收测试','image'=>$image],'qty'=>2,'price'=>3,'amount'=>6];
  $original[]=$base;
  $items[]=['product_code'=>'TEST-'.$i,'image'=>$image,'item_json'=>json_encode($base),'qty'=>2,'price'=>3,'amount'=>6];
}
$d=payload('AT-TEST-LARGE');$d['items_json']=json_encode($items);$d['snapshot_json']=json_encode(['items'=>$original,'order_items'=>$items]);$d['amount']=24;$d['qty']=8;
$itemsHash=hash('sha256',json_encode($items,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
$snapshotHash=hash('sha256',$d['snapshot_json']);$snapshotBytes=strlen($d['snapshot_json']);
unset($items,$original,$image,$base);
ini_set('memory_limit','128M');
if(function_exists('memory_reset_peak_usage')) memory_reset_peak_usage();
$wire=json_encode($d);unset($d);$d=json_decode($wire,true);unset($wire);
$started=microtime(true);$r=qo_convert_order($pdo,$d);$elapsed=microtime(true)-$started;$peak=memory_get_peak_usage(true);
$saved=qo_row($pdo,'SELECT SHA2(items_json,256) items_hash,SHA2(snapshot_json,256) snapshot_hash,OCTET_LENGTH(snapshot_json) snapshot_bytes,amount,qty FROM quote_sales_orders WHERE id=?',[$r['id']]);
check($saved['items_hash']===$itemsHash && $saved['snapshot_hash']===$snapshotHash && (int)$saved['snapshot_bytes']===$snapshotBytes,'Images/snapshot changed');
check((float)$saved['amount']===24.0 && (float)$saved['qty']===8.0,'Totals changed');
check((int)qo_row($pdo,'SELECT COUNT(*) n FROM quote_sales_order_items WHERE order_id=?',[$r['id']])['n']===4,'Missing rows');
check((int)qo_row($pdo,'SELECT converted_order_id FROM quote_orders LIMIT 1')['converted_order_id']===$r['id'],'Source not linked');
echo '128 MiB request decode + conversion passed; snapshot_bytes='.$snapshotBytes.' peak_bytes='.$peak.' conversion_seconds='.round($elapsed,3).PHP_EOL;
$detail=qo_order_detail($pdo,$r['id']);
$responseHash=hash_init('sha256');$responseBytes=0;
qo_write_json(['ok'=>true,'data'=>$detail],function($part)use($responseHash,&$responseBytes){hash_update($responseHash,$part);$responseBytes+=strlen($part);});
$actualHash=hash_final($responseHash);
// Reference encoding only: intentionally outside the 128 MiB runtime under test.
ini_set('memory_limit','512M');
check($actualHash===hash('sha256',json_encode(['ok'=>true,'data'=>$detail],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),'Streamed JSON differs from existing protocol');
unset($detail);ini_set('memory_limit','128M');
echo 'Large order detail streaming response passed; bytes='.$responseBytes.PHP_EOL;

$d=payload('AT-TEST-LARGE');
try{qo_convert_order($pdo,$d);throw new RuntimeException('Duplicate was accepted');}catch(RuntimeException $e){check(strpos($e->getMessage(),'订单号已存在')!==false,'Unexpected duplicate result');}
check((float)qo_row($pdo,'SELECT amount FROM quote_sales_orders WHERE id=?',[$r['id']])['amount']===24.0,'Duplicate overwrote order');
$pdo->exec("CREATE TRIGGER fail_conversion_item BEFORE INSERT ON quote_sales_order_items FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test injected item failure'");
$d=payload('AT-TEST-ROLLBACK');
try{qo_convert_order($pdo,$d);throw new RuntimeException('Failure was ignored');}catch(PDOException $e){check(strpos($e->getMessage(),'test injected')!==false,'Wrong injected error');}
check(!$pdo->inTransaction(),'Transaction left open');
check((int)qo_row($pdo,"SELECT COUNT(*) n FROM quote_sales_orders WHERE order_no='AT-TEST-ROLLBACK'")['n']===0,'Partial parent persisted');
check((int)qo_row($pdo,'SELECT converted_order_id FROM quote_orders LIMIT 1')['converted_order_id']===$r['id'],'Failed conversion altered source');
$pdo->exec('DROP TRIGGER fail_conversion_item');
$d=payload('AT-TEST-ROLLBACK');$retried=qo_convert_order($pdo,$d);check($retried['id']>0,'Retry failed');

$d=payload('AT-TEST-COMMISSION');$d['commission_choice']='apply';$d['commission_apply_json']=json_encode(['commission_mode'=>'percent','commission_value'=>10,'target_name'=>'验收测试','receivable_effect'=>'none']);
$commission=qo_convert_order($pdo,$d);
check((float)qo_row($pdo,'SELECT commission_amount FROM quote_commission_snapshots WHERE order_id=?',[$commission['id']])['commission_amount']===0.6,'Commission changed');
$d=payload('AT-TEST-INVALID-COMMISSION');$d['commission_choice']='apply';$d['commission_apply_json']='{}';
try{qo_convert_order($pdo,$d);throw new RuntimeException('Invalid commission accepted');}catch(RuntimeException $e){check(strpos($e->getMessage(),'佣金信息无效')!==false,'Unexpected commission error');}
check((int)qo_row($pdo,"SELECT COUNT(*) n FROM quote_sales_orders WHERE order_no='AT-TEST-INVALID-COMMISSION'")['n']===0,'Commission failure left order');

$jobs=[];
for($i=0;$i<2;$i++){
  $command=[PHP_BINARY,'-n','-d','extension=mysqlnd','-d','extension=pdo','-d','extension=pdo_mysql',__FILE__,'--worker','AT-TEST-CONCURRENT'];
  $pipes=[];$process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
  check(is_resource($process),'Worker did not start');fclose($pipes[0]);$jobs[]=[$process,$pipes];
}
$success=0;
foreach($jobs as [$process,$pipes]){$result=json_decode(stream_get_contents($pipes[1]),true);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($process)===0 && $result!==null,'Worker failed: '.$errors);$success+=!empty($result['ok'])?1:0;}
check($success===1,'Concurrent create count incorrect');
check((int)qo_row($pdo,"SELECT COUNT(*) n FROM quote_sales_orders WHERE order_no='AT-TEST-CONCURRENT'")['n']===1,'Duplicate concurrent rows');
// Chunk boundaries through Chinese/emoji must preserve valid UTF-8 byte-for-byte.
$d=payload('AT-TEST-UTF8');$d['snapshot_json']=json_encode(['text'=>str_repeat('中文😀',500000)],JSON_UNESCAPED_UNICODE);
$expected=hash('sha256',$d['snapshot_json']);$utf=qo_convert_order($pdo,$d);
check(qo_row($pdo,'SELECT SHA2(snapshot_json,256) h FROM quote_sales_orders WHERE id=?',[$utf['id']])['h']===$expected,'UTF-8 chunk boundary corrupted');
echo 'Rollback, retry, duplicate preservation, commission and concurrent conversion passed.'.PHP_EOL;
