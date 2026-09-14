<?php
// No app bootstrap or config: only a fresh, identity-checked, non-networked fixture database.
$socket=(string)getenv('CRM_PHASE1_MYSQL_SOCKET');$schema=(string)getenv('CRM_PHASE1_MYSQL_SCHEMA');
if(PHP_SAPI!=='cli'||getenv('CRM_PHASE1_MYSQL_TEST')!=='1'||!preg_match('#^/tmp/crm-phase1-mysql-20260906-[A-Za-z0-9]{8}/mysql.sock$#D',$socket)||!preg_match('/^quote_money_[a-f0-9]{12}$/D',$schema))throw new RuntimeException('Refusing non-sandbox database');
$pdo=new PDO('mysql:unix_socket='.$socket.';dbname='.$schema.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$identity=$pdo->query('SELECT @@socket socket,@@datadir datadir,@@skip_networking isolated')->fetch();
if($identity['socket']!==$socket||$identity['datadir']!==dirname($socket).'/data/'||(int)$identity['isolated']!==1)throw new RuntimeException('Instance mismatch');
if((int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()!==0)throw new RuntimeException('Fresh schema required');
require __DIR__.'/quote_mail_contract.php';
function db(){return $GLOBALS['pdo'];}
function crm_require($permission){}
function crm_mail_current_account($secret=false){return ['id'=>7,'user_id'=>9,'signature_html'=>'<p>Acceptance Signature</p>'];}
function crm_mail_render_signature_variables($html,$account=null){return $html;}
function crm_mail_uploaded_files($files){return [];}
function crm_mail_is_generated_temp_file($path){return false;}
function crm_log_event(...$args){if($GLOBALS['qfail']??false)throw new RuntimeException('Injected draft failure');$GLOBALS['qlogs'][]=$args;}
function crm_customer_timeline_add(...$args){$GLOBALS['qtimeline'][]=$args;}
function crm_customer_get($id,$section){if($id!==1)throw new RuntimeException('No scope');}
function qextract($name){$src=file_get_contents(dirname(__DIR__).'/crm_mail.php');if(!preg_match('/^function '.preg_quote($name,'/').'\b[\s\S]*?(?=^function |\z)/m',$src,$m))throw new RuntimeException('Missing function '.$name);eval(str_replace('__DIR__',var_export(dirname(__DIR__),true),$m[0]));}
foreach(['crm_mail_save_draft','crm_mail_queue_dir','crm_mail_queue_attachment_files','crm_mail_cleanup_queue_files','crm_mail_normalize_file_name_text','crm_mail_safe_file_name','crm_mail_draft_attachment_files'] as $fn)qextract($fn);
$pdo->exec('CREATE TABLE quote_orders(id INT PRIMARY KEY,quote_no VARCHAR(100),approval_status VARCHAR(20),approved_snapshot_json LONGTEXT) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE crm_mail_drafts(id INT AUTO_INCREMENT PRIMARY KEY,user_id INT,mail_account_id INT,reply_to_mail_id INT,mode VARCHAR(20),to_emails TEXT,cc_emails TEXT,bcc_emails TEXT,subject TEXT,body_html MEDIUMTEXT,attachments_json MEDIUMTEXT,draft_meta_json JSON,linked_customer_id INT,linked_contact_id INT,auto_saved INT,created_at DATETIME,updated_at DATETIME) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE crm_customers(id INT PRIMARY KEY,customer_name VARCHAR(100),email VARCHAR(100),deleted_at DATETIME)');
$pdo->exec('CREATE TABLE crm_contacts(id INT PRIMARY KEY,customer_id INT,name VARCHAR(100),email VARCHAR(100),is_primary INT,is_left INT,do_not_contact INT,unsubscribe_email INT,deleted_at DATETIME)');
$s=qfixture();$raw=json_encode($s);$pdo->prepare('INSERT INTO quote_orders VALUES(1,?,?,?)')->execute([$s['quote_no'],'approved',$raw]);
qtest(qmail_snapshot($pdo,1)['hash']===hash('sha256',$raw),'Approved snapshot hash exact');
$pdo->exec("UPDATE quote_orders SET approval_status='pending'");qreject(fn()=>qmail_snapshot($pdo,1),'Pending cannot generate');$pdo->exec("UPDATE quote_orders SET approval_status='approved'");
$bad=$s;$bad['amount']=100;$pdo->prepare('UPDATE quote_orders SET approved_snapshot_json=?')->execute([json_encode($bad)]);qreject(fn()=>qmail_snapshot($pdo,1),'Bad money rejected');
$bad=$s;$bad['id']=2;$pdo->prepare('UPDATE quote_orders SET approved_snapshot_json=?')->execute([json_encode($bad)]);qreject(fn()=>qmail_snapshot($pdo,1),'Wrong quote snapshot rejected');$pdo->prepare('UPDATE quote_orders SET approved_snapshot_json=?')->execute([$raw]);
$account=crm_mail_current_account();$input=['id'=>1,'token'=>bin2hex(random_bytes(24)),'formats'=>['excel'],'email'=>'acceptance@example.invalid'];
$p=qmail_create($input,$account);$files=json_decode($p['files_json'],true);$draft=$pdo->query('SELECT * FROM crm_mail_drafts WHERE id='.(int)$p['draft_id'])->fetch();
qtest(count($files)===1&&is_file($files[0]['path']),'Genuine Excel copied into scoped draft');
qtest(strpos($draft['body_html'],'Acceptance Signature')!==false&&$draft['to_emails']===$input['email'],'Draft carries explicit contact and account signature');
qtest(json_decode($draft['draft_meta_json'],true)['quote_mail_token']===$input['token'],'Draft carries stable metadata');
qtest(qmail_create($input,$account)['draft_id']===$p['draft_id']&&(int)$pdo->query('SELECT COUNT(*) FROM crm_mail_drafts')->fetchColumn()===1,'Retry same token creates only one draft');
$changed=$input;$changed['email']='';qreject(fn()=>qmail_create($changed,$account),'Retry cannot alter content');
qreject(fn()=>qmail_package($pdo,$input['token'],['id'=>8,'user_id'=>9]),'Wrong account blocked');qreject(fn()=>qmail_package($pdo,$input['token'],['id'=>7,'user_id'=>8]),'Wrong owner blocked');
$send=['draft_id'=>$p['draft_id']];$actual=crm_mail_draft_attachment_files($account,['attachments_json'=>$draft['attachments_json']]);
$guarded=qmail_send_guard($account,$send,$actual);qtest($guarded['quote_mail_token']===$input['token'],'Server recovers metadata even when client omits it');
qreject(fn()=>qmail_send_guard($account,$send,[]),'Removed attachment blocked');
$original=file_get_contents($files[0]['path']);file_put_contents($files[0]['path'],'tampered');qreject(fn()=>qmail_send_guard($account,$send,$actual),'Tampered file blocked');file_put_contents($files[0]['path'],$original);
$pdo->exec("UPDATE quote_orders SET approval_status='pending'");qreject(fn()=>qmail_send_guard($account,$send,$actual),'Reversal blocks queued send');$pdo->exec("UPDATE quote_orders SET approval_status='approved'");
$changed=$s;$changed['quote_date']='2026-09-15';$pdo->prepare('UPDATE quote_orders SET approved_snapshot_json=?')->execute([json_encode($changed)]);qreject(fn()=>qmail_send_guard($account,$send,$actual),'Reapproval changed version blocks queued send');$pdo->prepare('UPDATE quote_orders SET approved_snapshot_json=?')->execute([$raw]);
$pdo->beginTransaction();qmail_claim($account,$guarded,'jobA');$pdo->rollBack();qtest(qmail_package($pdo,$input['token'],$account)['job_id']===null,'Queue insert failure rolls back claim');
$pdo->beginTransaction();qmail_claim($account,$guarded,'jobA');$pdo->commit();qmail_claim($account,$guarded,'jobA');qreject(fn()=>qmail_claim($account,$guarded,'jobB'),'Different send token cannot double queue');
$pdo->beginTransaction();qmail_cancel_to_draft($account,$guarded,'jobA',(int)$p['draft_id']);$pdo->commit();qmail_claim($account,$guarded,'jobB');qtest(qmail_package($pdo,$input['token'],$account)['job_id']==='jobB','Explicit cancellation permits corrected resend');
qmail_sent($account,$guarded+['to_emails'=>$input['email']],101);qreject(fn()=>qmail_send_guard($account,$send,$actual),'Accepted mail cannot resend same package');qtest(qmail_package($pdo,$input['token'],$account)['sent_mail_id']==101,'Success stores mail record');
$GLOBALS['qfail']=true;$failed=$input;$failed['token']=bin2hex(random_bytes(24));qreject(fn()=>qmail_create($failed,$account),'Injected draft failure');$GLOBALS['qfail']=false;
qtest((int)$pdo->query('SELECT COUNT(*) FROM crm_mail_drafts')->fetchColumn()===1&&(int)$pdo->query('SELECT COUNT(*) FROM quote_mail_packages')->fetchColumn()===1,'Failure leaves no partial draft/package');
$pdo->exec("INSERT INTO crm_customers VALUES(1,'Test','blocked@example.invalid',NULL)");$pdo->exec("INSERT INTO crm_contacts VALUES(1,1,'Blocked','blocked@example.invalid',1,0,1,0,NULL),(2,1,'Left','left@example.invalid',0,1,0,0,NULL),(3,1,'Active','active@example.invalid',0,0,0,0,NULL)");
$s['customer_json']=json_encode(['crm_customer_id'=>1]);$contacts=qmail_contacts($s);qtest(count($contacts['contacts'])===1&&$contacts['contacts'][0]['id']===3,'Blocked/left contacts not reintroduced through company email');
crm_mail_cleanup_queue_files($files);
echo 'Quote mail isolated MySQL: actual draft/export, idempotency, wrong owner/account, revision, tampering, rollback, cancellation and sent record passed. Peak '.memory_get_peak_usage(true)." bytes\n";
