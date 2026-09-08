<?php
// Runs the actual save/approve route bodies, with a disposable database and no app bootstrap.
declare(strict_types=1);
require_once __DIR__.'/../includes/quote_money.php';
$socket=(string)getenv('CRM_PHASE1_MYSQL_SOCKET');$schema=(string)getenv('CRM_PHASE1_MYSQL_SCHEMA');
if(getenv('CRM_PHASE1_MYSQL_TEST')!=='1'||!preg_match('#^/tmp/crm-phase1-mysql-20260906-[A-Za-z0-9]{8}/mysql.sock$#D',$socket)||!preg_match('/^quote_money_[a-f0-9]{12}$/D',$schema))throw new RuntimeException('Refusing non-sandbox database');
function mq_connect(){global $socket,$schema;$p=new PDO('mysql:unix_socket='.$socket.';dbname='.$schema.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$i=$p->query('SELECT @@socket socket,@@datadir datadir,@@skip_networking isolated')->fetch(PDO::FETCH_ASSOC);if($i['socket']!==$socket||$i['datadir']!==dirname($socket).'/data/'||(int)$i['isolated']!==1)throw new RuntimeException('Instance mismatch');return $p;}
$pdo=mq_connect();$source=file_get_contents(__DIR__.'/../quote_api.php');
function mq_function($source,$name){
 $start=strpos($source,'function '.$name.'(');if($start===false)throw new RuntimeException('Missing function '.$name);
 $tokens=token_get_all('<?php '.substr($source,$start));$out='';$depth=0;$started=false;
 foreach(array_slice($tokens,1) as $token){$text=is_array($token)?$token[1]:$token;$out.=$text;
  if($token==='{'||(is_array($token)&&in_array($token[0],[T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES],true))){$depth++;$started=true;}
  if($token==='}'&&--$depth===0&&$started)return $out;
 }throw new RuntimeException('Unclosed function '.$name);
}
foreach(['row','quote_select_columns_except','save_row','quote_mutation_response_quote','quote_review_is_virtual_item','quote_review_virtual_type','quote_review_moq_value','quote_review_price_value','quote_review_qty_for_total','quote_review_normalize_virtual_meta','quote_merge_review_items','quote_decode_items_json','quote_item_name_for_log','quote_money_log','quote_review_item_changes','quote_append_approval_log','qlog_customer_name','quote_save_identity_norm','qlog_json'] as $fn){if(strpos($source,'function '.$fn.'(')!==false)eval(mq_function($source,$fn));}
class MQResult extends RuntimeException{public $data;function __construct($data){$this->data=$data;}}
function ok($data=[]){throw new MQResult($data);}
function fail($message){global $pdo;if($pdo->inTransaction())$pdo->rollBack();throw new RuntimeException($message);}
function input_json(){return $GLOBALS['mq_input'];}
function s($v,$len=5000){return substr((string)$v,0,$len);}
function quote_v640_doc_schema_fix($p){if($p->inTransaction())throw new RuntimeException('DDL in transaction');}
function quote_commission_schema($p){quote_v640_doc_schema_fix($p);}
function quote_v682_prepare_quote_save_data(&$d){}
function quote_approval_schema($p){}
function quote_normalize_doc_status($s){return $s;}
function quote_no_no_nested($s){return $s;}
function quote_approval_status_of($q){return $q['approval_status']??'pending';}
function quote_sales_owner_from_quote($q,$default){return $q['user_name']??$default;}
function quote_commission_customer_check($p,$d,$u){return [];}
function quote_require_approver($u,$p){}
function table_exists($p,$table){return $table==='quote_orders';}
function table_columns($p,$table){return $p->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_COLUMN);}
function quote_log_event($p,$arg=[]){if($p->inTransaction()&&($GLOBALS['mq_fail_log']??false))throw new RuntimeException('Injected log failure');}
function quote_push_crm_review_reminder($p,...$args){if($p->inTransaction())throw new RuntimeException('Notification before commit');throw new RuntimeException('Intentional notification failure');}
function quote_push_crm_approved_reminder($p,...$args){quote_push_crm_review_reminder($p,...$args);}
$routes=[];
foreach(['save_quote'=>'delete_quote','approve_quote'=>'reject_quote'] as $action=>$next){$start=strpos($source," if(\$action==='".$action."')");$end=strpos($source," if(\$action==='".$next."')",$start);if($start===false||$end===false)throw new RuntimeException('Route boundary missing');$routes[$action]=substr($source,$start,$end-$start);}
function mq_run($action,$data){global $pdo,$routes;$GLOBALS['mq_input']=$data;$__quote_user=['username'=>'验收测试'];$__quote_perms=[];try{eval($routes[$action]);}catch(MQResult $r){return $r->data;}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}throw new RuntimeException('No route response');}
function mq_check($ok,$why){if(!$ok)throw new RuntimeException($why);}
function mq_reject($action,$d,$why){try{mq_run($action,$d);}catch(RuntimeException $e){return $e->getMessage();}throw new RuntimeException('Accepted '.$why);}
function mq_payload($no,$price=4020){$it=['product'=>['id'=>'test'],'qty'=>1,'price'=>$price,'amount'=>$price,'currency'=>'RMB','unit_price'=>600,'approved_price'=>600,'price_multiplier'=>1,'moq'=>''];return ['quote_no'=>$no,'user_name'=>'验收测试','customer_id'=>'test','customer_name'=>'验收测试','customer_json'=>'{"company":"验收测试"}','items_json'=>json_encode([$it]),'currency'=>'RMB','exchange_rate'=>6.7,'qty'=>1,'price'=>$price,'amount'=>$price,'subtotal_amount'=>$price,'adjustment_amount'=>0,'adjustment_json'=>'{}'];}
function mq_approval($q,$price=null){$items=json_decode($q['items_json'],true);if($price!==null){$items[0]['price']=$price;$items[0]['amount']=$price;}$m=qm_calculate($items,$q['currency'],$q['adjustment_json']);return ['id'=>$q['id'],'money_revision'=>qm_revision($q),'items'=>$items,'amount'=>$m['amount'],'subtotal_amount'=>$m['subtotal_amount'],'adjustment_amount'=>$m['adjustment_amount'],'note'=>'','confirm_money_change'=>false];}
if(($argv[1]??'')==='--worker'){try{$q=row($pdo,'SELECT * FROM quote_orders WHERE id=?',[(int)$argv[2]]);$d=mq_approval($q);$d['money_revision']=$argv[3];usleep(100000);$r=mq_run('approve_quote',$d);echo 'APPROVED';}catch(Throwable $e){echo 'BLOCKED';}exit;}
preg_match('/\$id=save_row\(\$pdo,\x27quote_orders\x27,\$d,\[(.*?)\]\);/s',$routes['save_quote'],$match);
preg_match_all('/\x27([^\x27]+)\x27/',$match[1]??'',$fields);
$columns=[];foreach(array_unique(array_merge($fields[1],['approval_items_json','approval_log_json','approved_snapshot_json','locked_at'])) as $field){$columns[]='`'.$field.'` '.(in_array($field,['qty','price','amount','subtotal_amount','adjustment_amount','exchange_rate'],true)?'DECIMAL(18,4)':'LONGTEXT').' NULL';}
mq_check(count($columns)>40,'Schema extraction');
$pdo->exec('CREATE TABLE quote_orders(id INT AUTO_INCREMENT PRIMARY KEY,'.implode(',',$columns).') ENGINE=InnoDB');
$d=mq_payload('SYNTHETIC-ONE');$saved=mq_run('save_quote',$d);$id=$saved['id'];$q=row($pdo,'SELECT * FROM quote_orders WHERE id=?',[$id]);
mq_check((float)$q['amount']===4020.0 && json_decode($q['items_json'],true)[0]['unit_price']===4020,'Save aliases / amount');
mq_check($saved['money_revision']===qm_revision($q),'returned revision');
$wrong=mq_approval($q);$wrong['amount']=600;mq_reject('approve_quote',$wrong,'forged amount');
mq_check(qm_revision(row($pdo,'SELECT * FROM quote_orders WHERE id=?',[$id]))===qm_revision($q),'failed approval changed row');
$changed=mq_approval($q,4355);mq_reject('approve_quote',$changed,'missing reason');$changed['note']='验收测试改价';$changed['confirm_money_change']=true;
$approved=mq_run('approve_quote',$changed);$q2=row($pdo,'SELECT * FROM quote_orders WHERE id=?',[$id]);$snap=json_decode($q2['approved_snapshot_json'],true);
mq_check((float)$q2['amount']===4355.0&&(float)$snap['amount']===4355.0&&!isset($snap['approved_snapshot_json']),'atomic approval snapshot');qm_validate_snapshot($snap);
mq_reject('approve_quote',$changed,'duplicate approval');
$d['id']=$id;$d['money_revision']=qm_revision($q2);mq_reject('save_quote',$d,'approved quote immutable');
$logs=json_decode($q2['approval_log_json'],true);mq_check($logs[1]['before_money']['amount']==4020&&$logs[1]['after_money']['amount']==4355,'structured monetary logs');
$new=mq_run('save_quote',mq_payload('SYNTHETIC-EDIT'));$q=row($pdo,'SELECT * FROM quote_orders WHERE id=?',[$new['id']]);$oldReview=mq_approval($q);
$edit=mq_payload('SYNTHETIC-EDIT',123);$edit['id']=$q['id'];$edit['money_revision']=qm_revision($q);mq_run('save_quote',$edit);
mq_reject('approve_quote',$oldReview,'stale review');mq_reject('save_quote',$edit,'stale save');
$q=row($pdo,'SELECT * FROM quote_orders WHERE id=?',[$edit['id']]);$logs=json_decode($q['approval_log_json'],true);mq_check(count($logs[1]['changes'])===1,'resubmit changes');
// Core log failure after UPDATE must roll the transaction back.
$review=mq_approval($q);$review['note']='test';
$pdo->exec("CREATE TRIGGER mq_fail_snapshot BEFORE UPDATE ON quote_orders FOR EACH ROW BEGIN IF NEW.approved_snapshot_json IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected snapshot failure'; END IF; END");
mq_reject('approve_quote',$review,'snapshot failure rollback');$pdo->exec('DROP TRIGGER mq_fail_snapshot');
mq_check(qm_revision(row($pdo,'SELECT * FROM quote_orders WHERE id=?',[$q['id']]))===qm_revision($q),'partial approval survived failure');
// Separate PHP processes race using the same originally observed revision.
$token=qm_revision($q);$children=[];$pipes=[];$extensions=json_decode(getenv('CRM_PHASE1_MYSQL_PHP_EXTENSIONS_JSON')?:'[]',true);
for($i=0;$i<2;$i++){$cmd=[PHP_BINARY,'-n'];foreach($extensions as $ext){$cmd[]='-d';$cmd[]='extension='.$ext;}array_push($cmd,__FILE__,'--worker',(string)$q['id'],$token);$children[$i]=proc_open($cmd,[1=>['pipe','w'],2=>['pipe','w']],$pipes[$i]);}
$answers=[];foreach($children as $i=>$child){$answers[]=stream_get_contents($pipes[$i][1]);$err=stream_get_contents($pipes[$i][2]);fclose($pipes[$i][1]);fclose($pipes[$i][2]);mq_check(proc_close($child)===0,'Worker failure '.$err);}
sort($answers);mq_check($answers===['APPROVED','BLOCKED'],'concurrent approval');
// Zero is a legitimate saved/reviewed value, not a fallback signal.
$zero=mq_run('save_quote',mq_payload('SYNTHETIC-ZERO',0));$q=row($pdo,'SELECT * FROM quote_orders WHERE id=?',[$zero['id']]);$zeroApprove=mq_run('approve_quote',mq_approval($q));mq_check((float)$zeroApprove['quote']['amount']===0.0,'zero approval');
$large=mq_payload('SYNTHETIC-LARGE');$item=json_decode($large['items_json'],true);$item[0]['product']['image']='data:image/png;base64,'.str_repeat('A',7*1024*1024);$large['items_json']=json_encode($item);unset($item);
ini_set('memory_limit','128M');$result=mq_run('save_quote',$large);$largeQ=row($pdo,'SELECT * FROM quote_orders WHERE id=?',[$result['id']]);$review=mq_approval($largeQ);$approvedLarge=mq_run('approve_quote',$review);mq_check((float)$approvedLarge['quote']['amount']===4020.0,'large image approval');unset($large,$largeQ,$review,$approvedLarge,$result);
echo 'Large-image quote save/approval passed at 128 MiB; peak='.memory_get_peak_usage(true).PHP_EOL;
echo "Actual quote save/approve routes: save, aliases, totals, reason, snapshot, audit, stale save/review, rollback, concurrent approval, zero passed\n";
