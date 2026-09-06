<?php
declare(strict_types=1);
// Opt-in, fresh no-network MySQL instance only. Never loads application config.
if (PHP_SAPI !== 'cli') {http_response_code(404);exit;}
function sent_assert(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
sent_assert(getenv('CRM_PHASE1_MYSQL_TEST')==='1' && !getenv('CRM_PHASE1_MYSQL_DSN'),'Explicit isolated test required');
$socket=(string)getenv('CRM_PHASE1_MYSQL_SOCKET');$schema=(string)getenv('CRM_PHASE1_MYSQL_SCHEMA');
sent_assert((bool)preg_match('/^crm_phase1_mail_[a-f0-9]{12}$/D',$schema),'Dedicated mail schema required');
sent_assert($socket!=='' && $socket[0]==='/' && basename($socket)==='mysql.sock' && strpos($socket,';')===false,'Dedicated socket required');
$dir=realpath(dirname($socket));
sent_assert($dir!==false && in_array(dirname($dir),['/tmp','/private/tmp'],true) && (bool)preg_match('/^crm-phase1-mysql-[0-9]{8}-[A-Za-z0-9_-]{6,64}$/D',basename($dir)),'Isolated directory required');
sent_assert(is_file($dir.'/.crm-phase1-mysql') && !is_link($dir.'/.crm-phase1-mysql') && trim(file_get_contents($dir.'/.crm-phase1-mysql'))==='isolated-crm-phase1-mysql-v1','Missing isolation marker');
sent_assert(!is_link($socket) && filetype($socket)==='socket' && realpath($dir.'/data')===$dir.'/data','Invalid socket/data directory');
$pdo=new PDO('mysql:unix_socket='.$socket.';dbname='.$schema.';charset=utf8mb4',getenv('CRM_PHASE1_MYSQL_USER')?:'root',(string)getenv('CRM_PHASE1_MYSQL_PASSWORD'),[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>false]);
$identity=$pdo->query('SELECT @@socket AS socket,@@datadir AS datadir,@@skip_networking AS no_network,DATABASE() AS db')->fetch();
sent_assert(realpath(dirname($identity['socket']))===$dir && basename($identity['socket'])==='mysql.sock' && rtrim($identity['datadir'],'/')===$dir.'/data' && (int)$identity['no_network']===1 && $identity['db']===$schema,'Refuse unverified database');
sent_assert($pdo->query('SHOW TABLES')->fetchAll()===[],'Fresh empty schema required');
function db(): PDO {return $GLOBALS['pdo'];}
require_once dirname(__DIR__).'/crm_mail_sent_visibility.php';
$pdo->exec("CREATE TABLE crm_mails(id INT PRIMARY KEY,user_id INT,mail_account_id INT,folder VARCHAR(20),is_deleted TINYINT,message_uid VARCHAR(190),message_id_header VARCHAR(255),crm_send_id VARCHAR(80),mail_source VARCHAR(40),subject VARCHAR(255),to_emails VARCHAR(255),body_hash VARCHAR(80),sent_at DATETIME,created_at DATETIME,body_text MEDIUMTEXT,KEY scope(user_id,mail_account_id,folder,is_deleted)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$base=['user_id'=>7,'mail_account_id'=>3,'folder'=>'sent','is_deleted'=>0,'message_uid'=>'imap_base','message_id_header'=>null,'crm_send_id'=>null,'mail_source'=>'imap_sent','subject'=>'Fixture','to_emails'=>'recipient@example.invalid','body_hash'=>null,'sent_at'=>'2026-09-06 10:00:00','created_at'=>'2026-09-06 10:00:00','body_text'=>str_repeat('fixture ',1024)];
$cases=[
 ['message_id_header'=>'<Same-ID>'],['message_id_header'=>'same-id'],['message_id_header'=>'<SAME-ID>','message_uid'=>'send_fixture'],
 ['message_id_header'=>'<>','mail_source'=>'crm_sent'],['message_id_header'=>'< >'],['message_id_header'=>'   '],
 ['subject'=>'same to','to_emails'=>'same@example.invalid','message_uid'=>'send_to'],['subject'=>'SAME TO','to_emails'=>'SAME@example.invalid','sent_at'=>'2026-09-06 10:10:59'],['subject'=>'same to','to_emails'=>'same@example.invalid','sent_at'=>'2026-09-06 10:11:00'],
 ['subject'=>'hash','body_hash'=>'hash1','crm_send_id'=>'0'],['subject'=>'HASH','body_hash'=>'HASH1','to_emails'=>'other@example.invalid'],
 ['subject'=>'hash','body_hash'=>'hash1','sent_at'=>null,'created_at'=>null],
 ['message_id_header'=>'<needle>','message_uid'=>'send_needle','subject'=>'different'],['message_id_header'=>'<prefix-needle-suffix>'],
 ['message_id_header'=>'<Étiquette>'],['message_id_header'=>'<etiquette>'],
 ['message_id_header'=>'<trailing >'],['message_id_header'=>'trailing'],
 ['message_id_header'=>'scope','mail_account_id'=>4,'mail_source'=>'crm_sent'],['message_id_header'=>'scope','user_id'=>8,'mail_source'=>'crm_sent'],['message_id_header'=>'scope'],
 ['message_id_header'=>'deleted','is_deleted'=>1,'mail_source'=>'crm_sent'],['message_id_header'=>'deleted'],
 ['message_id_header'=>'folder','folder'=>'inbox','mail_source'=>'crm_sent'],['message_id_header'=>'folder'],
 ['message_uid'=>null,'subject'=>'same to','to_emails'=>'same@example.invalid'],['message_uid'=>'sender_not_literal_underscore','subject'=>'same to','to_emails'=>'same@example.invalid'],
];
mt_srand(16);
for($i=0;$i<160;$i++)$cases[]=['message_id_header'=>($i%4===0?null:'<random-'.mt_rand(0,60).'>'),'subject'=>'subject-'.mt_rand(0,20),'to_emails'=>'r'.mt_rand(0,20).'@example.invalid','body_hash'=>$i%3===0?null:'hash-'.mt_rand(0,20),'message_uid'=>$i%9===0?'send_'.$i:'imap_'.$i,'sent_at'=>'2026-09-06 10:'.str_pad((string)mt_rand(0,59),2,'0',STR_PAD_LEFT).':00'];
$id=0;
foreach($cases as $case){$row=array_replace($base,$case);$row=['id'=>++$id]+$row;$q=$pdo->prepare('INSERT INTO crm_mails ('.implode(',',array_keys($row)).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')');$q->execute(array_values($row));}
$legacy=trim(file_get_contents(__DIR__.'/fixtures/crm-mail-sent-legacy-filter.sql'));
$modes=['','NO_BACKSLASH_ESCAPES'];$checked=0;
foreach(['utf8mb4_general_ci','utf8mb4_unicode_ci','utf8mb4_bin'] as $collation){
 $pdo->exec('ALTER TABLE crm_mails CONVERT TO CHARACTER SET utf8mb4 COLLATE '.$collation);
 foreach([0,1] as $emptySenderDeleted){
 $pdo->exec('UPDATE crm_mails SET is_deleted='.$emptySenderDeleted.' WHERE id=4');
 foreach($modes as $mode){$pdo->exec('SET SESSION sql_mode='.$pdo->quote($mode));
  foreach([[7,3],[7,4],[8,3]] as [$owner,$account]){
   $q=$pdo->prepare("SELECT m.id FROM crm_mails m WHERE m.user_id=? AND m.mail_account_id=? AND m.folder='sent' AND m.is_deleted=0 AND ".$legacy.' ORDER BY m.id');$q->execute([$owner,$account]);$before=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
   $duplicates=crm_mail_sent_duplicate_ids(['id'=>$account,'user_id'=>$owner]);
   $where=$duplicates?' AND id NOT IN ('.implode(',',$duplicates).')':'';
   $q=$pdo->prepare("SELECT id FROM crm_mails WHERE user_id=? AND mail_account_id=? AND folder='sent' AND is_deleted=0".$where.' ORDER BY id');$q->execute([$owner,$account]);$after=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
   sent_assert($before===$after,'Legacy visibility mismatch for '.$collation.' / '.$mode.' / '.$owner.' / '.$account);$checked++;
  }
 }
 }
}
foreach([['id'=>0,'user_id'=>7],['id'=>3,'user_id'=>0]] as $account){$thrown=false;try{crm_mail_sent_duplicate_ids($account);}catch(RuntimeException $e){$thrown=true;}sent_assert($thrown,'Invalid scope must be rejected');}
echo 'crm_mail_sent_mysql_integration: '.$checked.' full-set equivalence cases passed; '.$id.' synthetic messages retained'.PHP_EOL;

// Real independent connections exercise the production compare-and-claim SQL.
// Delivery is a local INSERT spy, never SMTP; no application bootstrap is loaded.
class CrmMailDeliveryUncertain extends RuntimeException {}
$mailSource=file_get_contents(dirname(__DIR__).'/crm_mail.php');
preg_match('/^function crm_mail_send_due_jobs\(/m',$mailSource,$match,PREG_OFFSET_CAPTURE);
$start=$match[0][1];$end=strpos($mailSource,"\nfunction ",$start+1);eval(substr($mailSource,$start,$end-$start));
function crm_mail_ensure_tables(): void {}
function cleanupCrmMailTempFiles(): array {return [];}
function crm_mail_decrypt($value): string {return 'synthetic-only';}
function crm_mail_cleanup_queue_files($files): void {}
function crm_log_event(...$args): void {}
function crm_mail_execute_send_job($account,$input,$attachments,$job,$body): array {
 sent_assert($account['id']==3 && $account['user_id']==7 && !$attachments,'Only synthetic sender and no files');
 db()->prepare('INSERT INTO fixture_deliveries(job_id) VALUES(?)')->execute([$job]);
 usleep(100000);
 return ['sent_mail_id'=>901];
}
$pdo->exec('CREATE TABLE crm_mail_send_jobs(id INT PRIMARY KEY,user_id INT,mail_account_id INT,job_id VARCHAR(80) UNIQUE,status VARCHAR(40),stage VARCHAR(80),percent INT,scheduled_at DATETIME,payload_json JSON,attachments_json JSON,sent_mail_id INT,error_message TEXT,finished_at DATETIME,updated_at DATETIME) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE crm_user_mail_accounts(id INT PRIMARY KEY,user_id INT,deleted_at DATETIME,email_password_encrypted VARCHAR(50)) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE fixture_deliveries(id INT AUTO_INCREMENT PRIMARY KEY,job_id VARCHAR(80)) ENGINE=InnoDB');
$pdo->exec("INSERT INTO crm_user_mail_accounts VALUES(3,7,NULL,'synthetic')");
$pdo->exec("INSERT INTO crm_mail_send_jobs(id,user_id,mail_account_id,job_id,status,stage,percent,scheduled_at,payload_json,attachments_json,updated_at) VALUES(1,7,3,'fixture_send_claim','scheduled','waiting',5,NOW(),'{}','[]',NOW())");
function mail_fixture_connect(): PDO {
 $db=new PDO('mysql:unix_socket='.$GLOBALS['socket'].';dbname='.$GLOBALS['schema'].';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
 $identity=$db->query('SELECT @@socket AS socket,@@datadir AS datadir,@@skip_networking AS no_network,DATABASE() AS db')->fetch();
 sent_assert($identity['socket']===$GLOBALS['socket'] && rtrim($identity['datadir'],'/')===$GLOBALS['dir'].'/data' && (int)$identity['no_network']===1 && $identity['db']===$GLOBALS['schema'],'Reconnected sandbox identity mismatch');
 return $db;
}
$q=null;$pdo=null;$children=[];
for($worker=0;$worker<3;$worker++){
 $pid=pcntl_fork();sent_assert($pid>=0,'Could not start isolated claim worker');
 if($pid===0){try{$pdo=mail_fixture_connect();crm_mail_send_due_jobs(1,'fixture_send_claim');exit(0);}catch(Throwable $e){fwrite(STDERR,'Claim fixture failed: '.$e->getMessage().PHP_EOL);exit(1);}}
 $children[]=$pid;
}
foreach($children as $pid){pcntl_waitpid($pid,$status);sent_assert(pcntl_wifexited($status) && pcntl_wexitstatus($status)===0,'Claim worker failed');}
$pdo=mail_fixture_connect();
sent_assert((int)$pdo->query('SELECT COUNT(*) FROM fixture_deliveries')->fetchColumn()===1,'Concurrent workers must not duplicate delivery');
sent_assert($pdo->query('SELECT status FROM crm_mail_send_jobs WHERE id=1')->fetchColumn()==='success','Persisted successful job state');
crm_mail_send_due_jobs(1,'fixture_send_claim');
sent_assert((int)$pdo->query('SELECT COUNT(*) FROM fixture_deliveries')->fetchColumn()===1,'Completed task cannot be reclaimed');
echo 'Mail queue: three real concurrent workers claimed once; delivery was a local database spy only'.PHP_EOL;
