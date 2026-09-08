<?php
/** Opt-in standalone MySQL only. Reuses the independently verified socket guard. No app bootstrap or SMTP. */
if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI only');
function pd_extract(string $source, string $name): string {
    if (!preg_match('/^function '.preg_quote($name,'/').'\(/m',$source,$m,PREG_OFFSET_CAPTURE)) throw new RuntimeException('Missing function '.$name);
    $start=$m[0][1];$end=strpos($source,"\n}",$start);if($end===false)throw new RuntimeException('Missing end');
    return substr($source,$start,$end+2-$start);
}
$guard=file_get_contents(__DIR__.'/crm_marketing_mysql_integration.php');
foreach(['mit_assert','mit_config','mit_connect'] as $name)eval(pd_extract($guard,$name));
$GLOBALS['pdDb']=mit_connect(mit_config());
function db(): PDO {return $GLOBALS['pdDb'];}
mit_assert((int)db()->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()===0,'Fresh isolated schema required');
date_default_timezone_set('Asia/Shanghai');
function current_user(){return ['id'=>$GLOBALS['pdUser'] ?? 1];}
function is_super_admin(){return false;}
function crm_can($key){return false;}
function crm_require($key){if(!in_array($key,['promotion.task_create','promotion.execute','mail.send'],true))throw new RuntimeException('Unexpected permission');}
function crm_marketing_ensure_tables(){}
function crm_mail_ensure_tables(){}
function crm_ensure_tables(){}
function crm_marketing_linkify_mail_html($html){return $html;}
function crm_mail_input_ids($value){return array_map('intval',is_array($value)?$value:(json_decode((string)$value,true) ?: []));}
function crm_marketing_decode_json_input($value){return is_array($value)?$value:(json_decode((string)$value,true) ?: []);}
function crm_marketing_resolve_audience_customers($filters,$ids){if(!$ids)return ['rows'=>[]];$s=db()->prepare('SELECT * FROM crm_customers WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).')');$s->execute($ids);return ['rows'=>$s->fetchAll()];}
function crm_marketing_tasks(){return [];}
function crm_log_event(...$args){}
function crm_marketing_notify_queue_build(...$args){}
function crm_marketing_task_row($id){$s=db()->prepare('SELECT * FROM crm_marketing_tasks WHERE id=?');$s->execute([$id]);return $s->fetch();}
function db_table_exists($table){return true;}
function crm_mail_current_account($required,$id,$uid){$s=db()->prepare('SELECT * FROM crm_user_mail_accounts WHERE id=? AND user_id=?');$s->execute([$id,$uid]);return $s->fetch();}
function crm_marketing_prepare_mail_inline_images($body,$account){return ['body_html'=>$body,'body_original'=>$body,'attachments'=>[]];}
function crm_mail_cleanup_generated_attachments($a){}
function crm_mail_execute_send_job($account,$input,$attachments,$jobId,$original){$GLOBALS['pdSent'][]=[$account,$input,$attachments];return ['sent_mail_id'=>0,'smtp_response'=>'synthetic'];}
function crm_mail_decrypt($v){return 'synthetic-no-network';}
function crm_marketing_update_target_from_queue(...$a){}
function crm_marketing_queue_update_task_status(...$a){}
$source=file_get_contents(dirname(__DIR__).'/crm_marketing.php');
eval(pd_extract($source,'crm_marketing_apply_audience_policy'));
foreach(['crm_marketing_json','crm_marketing_normalize_channel','crm_marketing_is_email_channel','crm_marketing_is_manual_channel','crm_marketing_pick_first','crm_marketing_manual_target_meta','crm_marketing_manual_schedule','crm_marketing_resolve_target_channel','crm_marketing_with_task_lock','crm_marketing_saved_task_status','crm_marketing_assert_targets_rebuildable','crm_marketing_email_suppression_sql','crm_marketing_task_create','crm_marketing_queue_skip_suppressed','crm_marketing_queue_claim','crm_marketing_queue_run_due'] as $name)eval(pd_extract($source,$name));
require dirname(__DIR__).'/crm_marketing_delivery.php';
foreach([
"CREATE TABLE crm_marketing_tasks (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,task_name VARCHAR(190),channel_key VARCHAR(120),campaign_type VARCHAR(60),mail_subject VARCHAR(255),mail_body_html MEDIUMTEXT,signature_key VARCHAR(120),attachment_config_json JSON,audience_config_json JSON,send_rule_json JSON,schedule_config_json JSON,failure_policy_json JSON,risk_summary_json JSON,task_status VARCHAR(40),schedule_type VARCHAR(40),scheduled_at DATETIME,remark VARCHAR(500),created_by INT,assigned_to INT,customer_count INT DEFAULT 0,contact_count INT DEFAULT 0,created_at DATETIME,updated_at DATETIME) ENGINE=InnoDB",
"CREATE TABLE crm_customers (id INT PRIMARY KEY,customer_name VARCHAR(190),country VARCHAR(120),owner_user_id INT,email VARCHAR(190),phone VARCHAR(80),whatsapp VARCHAR(80),address VARCHAR(500),do_not_contact INT DEFAULT 0,deleted_at DATETIME) ENGINE=InnoDB",
"CREATE TABLE crm_contacts (id INT PRIMARY KEY,customer_id INT,name VARCHAR(120),email VARCHAR(190),phone VARCHAR(80),whatsapp VARCHAR(80),wechat VARCHAR(120),linkedin VARCHAR(255),is_primary INT DEFAULT 0,is_left INT DEFAULT 0,do_not_contact INT DEFAULT 0,unsubscribe_email INT DEFAULT 0,deleted_at DATETIME) ENGINE=InnoDB",
"CREATE TABLE crm_customer_chat_groups (id INT PRIMARY KEY,customer_id INT,group_name VARCHAR(190),group_platform VARCHAR(60),status VARCHAR(40),use_for_promotion INT,deleted_at DATETIME) ENGINE=InnoDB",
"CREATE TABLE crm_customer_promotion_channels (id INT AUTO_INCREMENT PRIMARY KEY,customer_id INT,channel_key VARCHAR(120)) ENGINE=InnoDB",
"CREATE TABLE crm_contact_promotions (id INT AUTO_INCREMENT PRIMARY KEY,contact_id INT,channel VARCHAR(120),status VARCHAR(40)) ENGINE=InnoDB",
"CREATE TABLE crm_customer_promotion_status (customer_id INT PRIMARY KEY,status VARCHAR(40)) ENGINE=InnoDB",
"CREATE TABLE crm_marketing_task_targets (id BIGINT AUTO_INCREMENT PRIMARY KEY,task_id BIGINT,customer_id INT,contact_id INT,chat_group_id INT,channel_key VARCHAR(120),contact_method VARCHAR(500),manual_group_name VARCHAR(255),executor_user_id INT,planned_at DATETIME,due_at DATETIME,target_status VARCHAR(40),failure_reason VARCHAR(500),executed_at DATETIME,created_at DATETIME) ENGINE=InnoDB",
"CREATE TABLE crm_users (id INT PRIMARY KEY,real_name VARCHAR(120),username VARCHAR(120),phone VARCHAR(80),position VARCHAR(120),status VARCHAR(20) DEFAULT 'active') ENGINE=InnoDB",
"CREATE TABLE crm_user_mail_accounts (id INT PRIMARY KEY,user_id INT,email_address VARCHAR(190),email_username VARCHAR(190),sender_name VARCHAR(120),signature_html MEDIUMTEXT,is_default INT DEFAULT 1,is_enabled INT DEFAULT 1,email_password_encrypted TEXT,deleted_at DATETIME) ENGINE=InnoDB",
"CREATE TABLE crm_mail_signature_templates (id INT PRIMARY KEY,template_html MEDIUMTEXT,is_default INT) ENGINE=InnoDB",
"CREATE TABLE crm_marketing_send_queue (id BIGINT AUTO_INCREMENT PRIMARY KEY,task_id BIGINT,customer_id INT,contact_id INT,sender_user_id INT,sender_email VARCHAR(190),receiver_email VARCHAR(190),subject VARCHAR(500),body MEDIUMTEXT,body_ref_id BIGINT,attachment_json JSON,planned_server_time DATETIME,send_status VARCHAR(40),send_attempts INT,max_attempts INT,last_error TEXT,failure_reason TEXT,sent_at DATETIME,created_at DATETIME,updated_at DATETIME) ENGINE=InnoDB",
"CREATE TABLE crm_marketing_queue_bodies (id BIGINT PRIMARY KEY,body_html MEDIUMTEXT) ENGINE=InnoDB",
"CREATE TABLE crm_marketing_logs (id BIGINT AUTO_INCREMENT PRIMARY KEY,task_id BIGINT,customer_id INT,contact_id INT,channel_key VARCHAR(120),action_key VARCHAR(120),result_status VARCHAR(40),failure_reason VARCHAR(500),operator_id INT,detail_json JSON,touched_at DATETIME,created_at DATETIME) ENGINE=InnoDB"
] as $sql)db()->exec($sql);
crm_delivery_ensure();
db()->exec("INSERT INTO crm_users (id,real_name,username,phone,position) VALUES (1,'Sender','sender','123','Sales')");
db()->exec("INSERT INTO crm_user_mail_accounts (id,user_id,email_address,sender_name,signature_html) VALUES (1,1,'sender@example.invalid','Sender','<p>{mail_user_name} / {send_email}</p>')");
$signature=crm_delivery_signature_inspect('personal',1);
mit_assert($signature['ready'] && $signature['preview_html']==='<p>Sender / sender@example.invalid</p>','Read-only check renders actual mailbox signature');
$GLOBALS['pdUser']=9;
try {crm_delivery_signature_inspect('personal',1);throw new LogicException('Foreign signature exposed');}catch(RuntimeException $e){}
$GLOBALS['pdUser']=1;
try {crm_delivery_signature_inspect('personal',0);throw new LogicException('Unspecified account fell back');}catch(RuntimeException $e){}
db()->exec("UPDATE crm_user_mail_accounts SET signature_html='<p>{mail_user_name} {mail_user_position} {mail_user_mobile}</p>' WHERE id=1");
db()->exec("UPDATE crm_users SET phone='',position='' WHERE id=1");
$signature=crm_delivery_signature_inspect('personal',1);
mit_assert(!$signature['ready'] && count($signature['missing'])===2,'Existing blank profile reproduces unusable signature with actionable missing fields');
db()->exec("UPDATE crm_users SET phone='123',position='Sales' WHERE id=1");
mit_assert(crm_delivery_signature_inspect('personal',1)['ready'],'Fresh recheck sees repaired profile without stale signature cache');
db()->exec("INSERT INTO crm_mail_signature_templates (id,template_html,is_default) VALUES (1,'<p>Company {send_email}</p>',1)");
mit_assert(crm_delivery_signature_inspect('company',1)['preview_html']==='<p>Company sender@example.invalid</p>','Company signature uses selected sender');
db()->exec("UPDATE crm_user_mail_accounts SET signature_html='<p>{mail_user_name} / {send_email}</p>' WHERE id=1");
mit_assert((int)db()->query('SELECT COUNT(*) FROM crm_marketing_tasks')->fetchColumn()===0 && (int)db()->query('SELECT COUNT(*) FROM crm_marketing_send_queue')->fetchColumn()===0,'Signature check creates no tasks or queue');
db()->exec("INSERT INTO crm_customers (id,customer_name,country,owner_user_id,email) VALUES (1,'Example Company','CN',1,'company@example.invalid')");
db()->exec("INSERT INTO crm_contacts (id,customer_id,name,email,is_primary) VALUES (1,1,'Alice','alice@example.invalid',1),(2,1,'No Email','',0),(3,1,'Other Channel','phone@example.invalid',0),(4,1,'Duplicate','alice@example.invalid',0)");
db()->exec("INSERT INTO crm_customer_promotion_channels (customer_id,channel_key) VALUES (1,'email')");
db()->exec("INSERT INTO crm_contact_promotions (contact_id,channel,status) VALUES (3,'phone','active')");
$asset=str_repeat('a',32);$content='Synthetic attachment bytes';
db()->prepare('INSERT INTO crm_marketing_delivery_assets (id,user_id,file_name,mime_type,file_size,sha256,content) VALUES (?,1,?,?,?, ?,?)')->execute([$asset,'fixture.txt','text/plain',strlen($content),hash('sha256',$content),$content]);
$input=['client_request_id'=>'synthetic_delivery_request_001','task_status'=>'draft','task_name'=>'Synthetic delivery','channel_key'=>'email','campaign_type'=>'email','customer_ids'=>'[1]','contact_ids'=>'[]','mail_subject'=>'Hello {company_name}','mail_body_html'=>'<p>Hello {contact_name}</p>','signature_key'=>'personal','send_rule'=>['delivery_version'=>2,'mail_account_rule'=>'owner_mailbox'],'attachment_config'=>['asset_ids'=>[$asset]],'failure_policy'=>['retry_count'=>1,'retry_interval_minutes'=>17],'audience_config'=>['group_mode'=>'selected']];
$first=crm_marketing_task_create($input);$second=crm_marketing_task_create($input);
mit_assert($first['task_id']===$second['task_id'],'Same request must not create a duplicate draft');
mit_assert((int)db()->query('SELECT COUNT(*) FROM crm_marketing_tasks')->fetchColumn()===1,'One draft only');
$preview=crm_delivery_preview(['task_id'=>$first['task_id']]);
mit_assert(count($preview['manifest']['items'])===1 && count($preview['manifest']['excluded'])===3,'Exact contacts, no fallback, strict channel, duplicate exclusion');
mit_assert((int)db()->query('SELECT COUNT(*) FROM crm_marketing_send_queue')->fetchColumn()===0,'Preview must not queue');
$item=$preview['manifest']['current_item'];
mit_assert(strpos($item['subject'],'Example Company')!==false && strpos($item['body_html'],'Sender / sender@example.invalid')!==false,'Real company name and account signature');
$GLOBALS['pdUser']=2;
try {crm_delivery_load_preview(['token'=>$preview['token']]);throw new LogicException('Foreign preview accepted');}catch(RuntimeException $e){}
$GLOBALS['pdUser']=1;
db()->exec("UPDATE crm_contacts SET email='changed@example.invalid' WHERE id=4");
try {crm_delivery_confirm(['token'=>$preview['token']]);throw new LogicException('Stale preview accepted');}catch(RuntimeException $e){}
mit_assert((int)db()->query('SELECT COUNT(*) FROM crm_marketing_send_queue')->fetchColumn()===0,'Stale confirmation must roll back');
db()->exec("UPDATE crm_contacts SET email='alice@example.invalid' WHERE id=4");
$savedPreview=crm_delivery_load_preview(['token'=>$preview['token']]);
$expired=json_decode($savedPreview['manifest'],true);$expired['base']=time()-1;
db()->prepare('UPDATE crm_marketing_delivery_previews SET manifest=? WHERE token=?')->execute([json_encode($expired),$preview['token']]);
try {crm_delivery_confirm(['token'=>$preview['token']]);throw new LogicException('Expired preview accepted');}catch(RuntimeException $e){}
db()->prepare('UPDATE crm_marketing_delivery_previews SET manifest=? WHERE token=?')->execute([$savedPreview['manifest'],$preview['token']]);
crm_delivery_test(['token'=>$preview['token'],'index'=>0,'test_email'=>'test@example.invalid']);
mit_assert(count($GLOBALS['pdSent'])===1 && $GLOBALS['pdSent'][0][1]['to_emails']==='test@example.invalid','Test recipient only');
mit_assert($GLOBALS['pdSent'][0][2][0]['content']===$content,'Test attachment bytes');
crm_delivery_confirm(['token'=>$preview['token']]);
crm_delivery_confirm(['token'=>$preview['token']]);
mit_assert((int)db()->query('SELECT COUNT(*) FROM crm_marketing_send_queue')->fetchColumn()===1,'Confirmation retry must not duplicate queue');
$queue=db()->query('SELECT * FROM crm_marketing_send_queue')->fetch();
mit_assert($queue['body']===$item['body_html'] && $queue['subject']===$item['subject'],'Queue exactly matches preview');
db()->exec("UPDATE crm_marketing_send_queue SET planned_server_time=DATE_SUB(NOW(),INTERVAL 1 MINUTE)");
$result=crm_marketing_queue_run_due(10);
mit_assert($result['sent']===1,'Real worker must process confirmed snapshot using fake SMTP');
mit_assert(count($GLOBALS['pdSent'])===2 && $GLOBALS['pdSent'][1][2][0]['content']===$content,'Formal path preserves exact attachment bytes');
mit_assert($GLOBALS['pdSent'][1][1]['body_html']===$GLOBALS['pdSent'][0][1]['body_html'],'Test/formal bodies identical');
$result=crm_marketing_queue_run_due(10);mit_assert($result['sent']===0,'No re-send after successful delivery');
db()->exec("INSERT INTO crm_marketing_send_queue (task_id,customer_id,contact_id,sender_user_id,sender_email,receiver_email,subject,body,attachment_json,planned_server_time,send_status,send_attempts,max_attempts,created_at,updated_at) SELECT task_id,customer_id,contact_id,sender_user_id,sender_email,receiver_email,subject,body,attachment_json,DATE_SUB(NOW(),INTERVAL 1 MINUTE),'scheduled',0,max_attempts,NOW(),NOW() FROM crm_marketing_send_queue LIMIT 1");
$result=crm_marketing_queue_run_due(10);mit_assert($result['sent']===0,'Overdue jobs must still respect real sender spacing');
$deferred=db()->query('SELECT send_status,send_attempts FROM crm_marketing_send_queue ORDER BY id DESC LIMIT 1')->fetch();
mit_assert($deferred['send_status']==='scheduled' && (int)$deferred['send_attempts']===0,'Rate deferral must not consume retries');
db()->exec("INSERT INTO crm_contact_promotions (contact_id,channel,status) VALUES (1,'email','paused')");
mit_assert(crm_delivery_channels(1,1)===[],'Paused contact channel must not fall back to customer channel');
db()->exec("UPDATE crm_contacts SET phone='555-0100' WHERE id=3");
$manualInput=array_merge($input,['client_request_id'=>'synthetic_manual_request_001','channel_key'=>'phone','campaign_type'=>'phone','mail_subject'=>'','mail_body_html'=>'Call {contact_name}']);
$manual=crm_marketing_task_create($manualInput);$mp=crm_delivery_preview(['task_id'=>$manual['task_id']]);
mit_assert(count($mp['manifest']['items'])===1 && $mp['manifest']['items'][0]['mode']==='manual','Manual channel must not produce mail');
db()->exec("UPDATE crm_contacts SET phone='555-0200' WHERE id=3");
try {crm_delivery_confirm(['token'=>$mp['token']]);throw new LogicException('Changed manual contact accepted');}catch(RuntimeException $e){}
$mp=crm_delivery_preview(['task_id'=>$manual['task_id']]);crm_delivery_confirm(['token'=>$mp['token']]);
mit_assert((int)db()->query('SELECT COUNT(*) FROM crm_marketing_send_queue WHERE task_id='.(int)$manual['task_id'])->fetchColumn()===0,'Manual confirmation must never queue mail');
echo "Promotion delivery MySQL: draft identity, read-only preview, recipient policy, stale/foreign preview rejection, confirm retry, exact signature/body/attachment and worker passed; SMTP mocked.\n";

// Real persistence and pagination for a bulk list; no bulk SMTP or queue run.
$start=microtime(true);$customerIds=[];
$insertCustomer=db()->prepare('INSERT INTO crm_customers (id,customer_name,country,owner_user_id,email) VALUES (?,?,?,?,?)');
$insertChannel=db()->prepare("INSERT INTO crm_customer_promotion_channels (customer_id,channel_key) VALUES (?,'email')");
db()->beginTransaction();
for($id=2000;$id<5000;$id++){
    $customerIds[]=$id;$insertCustomer->execute([$id,'Synthetic '.$id,$id%2?'CN':'IN',1,'bulk'.$id.'@example.invalid']);$insertChannel->execute([$id]);
}
db()->commit();
db()->exec("INSERT INTO crm_user_mail_accounts (id,user_id,email_address,sender_name,signature_html) VALUES (2,1,'second@example.invalid','Second','<p>SECOND {send_email}</p>')");
$bulkInput=array_merge($input,['client_request_id'=>'synthetic_bulk_3000','customer_ids'=>json_encode($customerIds),'mail_body_html'=>'<p>{company_name}</p><p>'.str_repeat('x',100*1024).'</p>','send_rule'=>['delivery_version'=>2,'mail_account_rule'=>'balanced','mail_account_ids'=>[1,2]],'attachment_config'=>[]]);
$bulk=crm_marketing_task_create($bulkInput);$bp=crm_delivery_preview(['task_id'=>$bulk['task_id']]);
mit_assert($bp['manifest']['total']===3000 && count($bp['manifest']['items'])===20,'Bulk preview must return only one page');
mit_assert(array_column($bp['manifest']['senders'],'count')===[1500,1500],'Real target allocation must be even');
$last=crm_delivery_preview_read(['token'=>$bp['token'],'page'=>149,'index'=>2999]);
mit_assert($last['manifest']['current_item']['customer_id']===4999 && strpos($last['manifest']['current_item']['body_html'],'SECOND second@example.invalid')!==false,'Final page must expand exact customer and mailbox signature');
$stored=crm_delivery_load_preview(['token'=>$bp['token']]);
mit_assert(strlen($stored['manifest'])<4*1024*1024,'Persisted bulk snapshot must share templates');
mit_assert(strlen(json_encode($bp))<200*1024,'Network response must not contain 3000 bodies');
mit_assert((int)db()->query('SELECT COUNT(*) FROM crm_marketing_send_queue WHERE task_id='.(int)$bulk['task_id'])->fetchColumn()===0,'Bulk preview must never start queue');
db()->exec("UPDATE crm_user_mail_accounts SET is_enabled=0 WHERE id=2");
try{crm_delivery_confirm(['token'=>$bp['token']]);throw new LogicException('Disabled selected account accepted');}catch(RuntimeException $e){}
db()->exec("UPDATE crm_user_mail_accounts SET is_enabled=1 WHERE id=2");
echo 'MySQL bulk preview: 3000 x 100KiB; stored_bytes='.strlen($stored['manifest']).', response_bytes='.strlen(json_encode($bp)).', elapsed_seconds='.round(microtime(true)-$start,3)."; no bulk send.\n";

// Small multi-account confirmation validates queue expansion against every frozen preview.
$smallInput=array_merge($bulkInput,['client_request_id'=>'synthetic_balanced_confirm','customer_ids'=>'[2000,2001,2002,2003,2004]','mail_body_html'=>'<p>{company_name}</p>']);
$small=crm_marketing_task_create($smallInput);$sp=crm_delivery_preview(['task_id'=>$small['task_id']]);
$sm=json_decode(crm_delivery_load_preview(['token'=>$sp['token']])['manifest'],true);
crm_delivery_confirm(['token'=>$sp['token']]);crm_delivery_confirm(['token'=>$sp['token']]);
$qs=db()->query('SELECT * FROM crm_marketing_send_queue WHERE task_id='.(int)$small['task_id'].' ORDER BY id')->fetchAll();
mit_assert(count($qs)===5,'Balanced confirmation must be idempotent');
foreach($qs as $i=>$q){$expected=crm_delivery_expand_item($sm,$sm['items'][$i]);mit_assert($q['body']===$expected['body_html'] && $q['sender_email']===$expected['sender_email'],'Every queued body and sender must equal preview');}
echo "Multi-mailbox queue snapshots and repeated confirmation passed; no additional SMTP call.\n";

// The saved selection is not the executable list: suppressed customers stay excluded.
db()->exec("INSERT INTO crm_customers (id,customer_name,country,owner_user_id,email,do_not_contact) VALUES (9999,'Suppressed fixture','CN',1,'suppressed@example.invalid',1)");
$blockedInput=array_merge($input,['client_request_id'=>'synthetic_excluded_selection','customer_ids'=>'[9999]','contact_ids'=>'[]']);
$blocked=crm_marketing_task_create($blockedInput);
$blockedTask=crm_marketing_task_row($blocked['task_id']);
$blockedAudience=json_decode($blockedTask['audience_config_json'],true);
mit_assert($blockedAudience['selection']['customer_ids']===[9999] && $blockedAudience['selection']['contact_ids']===[],'Preserve original selection for draft reopening');
mit_assert(count($blockedAudience['excluded_customers'])===1,'Keep suppression explanation');
$blockedPreview=crm_delivery_preview(['task_id'=>$blocked['task_id']]);
mit_assert($blockedPreview['manifest']['total']===0 && $blockedPreview['manifest']['excluded_total']===1,'Saved selection cannot bypass suppression');
mit_assert((int)db()->query('SELECT COUNT(*) FROM crm_marketing_task_targets WHERE task_id='.(int)$blocked['task_id'])->fetchColumn()===0,'Suppressed selected customer must not become an execution target');
$blockedInput['task_id']=$blocked['task_id'];crm_marketing_task_create($blockedInput);
mit_assert(json_decode(crm_marketing_task_row($blocked['task_id'])['audience_config_json'],true)['selection']===$blockedAudience['selection'],'Selection survives re-saving');
echo "Excluded draft selection persistence and fail-closed preview passed.\n";
