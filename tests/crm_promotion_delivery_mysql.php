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
function crm_require($key){if(!in_array($key,['promotion.task_create','promotion.execute','mail.send','task.complete'],true))throw new RuntimeException('Unexpected permission');}
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
"CREATE TABLE crm_marketing_queue_bodies (id BIGINT AUTO_INCREMENT PRIMARY KEY,task_id BIGINT,body_hash CHAR(64),body_html MEDIUMTEXT,body_bytes INT,created_at DATETIME,updated_at DATETIME,UNIQUE KEY uk_body(task_id,body_hash)) ENGINE=InnoDB",
"CREATE TABLE crm_marketing_logs (id BIGINT AUTO_INCREMENT PRIMARY KEY,task_id BIGINT,customer_id INT,contact_id INT,channel_key VARCHAR(120),action_key VARCHAR(120),result_status VARCHAR(40),failure_reason VARCHAR(500),operator_id INT,detail_json JSON,touched_at DATETIME,created_at DATETIME) ENGINE=InnoDB"
] as $sql)db()->exec($sql);
db()->exec("CREATE TABLE crm_tasks (id BIGINT AUTO_INCREMENT PRIMARY KEY,task_type VARCHAR(60),title VARCHAR(255),description TEXT,source_type VARCHAR(60),source_id VARCHAR(80),customer_id INT,contact_id INT,assigned_user_id INT,priority VARCHAR(30),status VARCHAR(40),due_at DATETIME,reminder_at DATETIME,request_token VARCHAR(100),created_by INT,created_at DATETIME,updated_at DATETIME,completed_at DATETIME,completed_by INT,result VARCHAR(120),result_note TEXT,deleted_at DATETIME,UNIQUE KEY uk_request(created_by,request_token)) ENGINE=InnoDB");
db()->exec('ALTER TABLE crm_marketing_task_targets ADD manual_result TEXT, ADD manual_remark TEXT, ADD manual_attachment_json JSON, ADD manual_checked_by_user_id INT');
db()->exec('ALTER TABLE crm_marketing_tasks ADD success_count INT DEFAULT 0, ADD failed_count INT DEFAULT 0');
if (!function_exists('mb_substr')) { function mb_substr($s,$start,$length){return implode('',array_slice(preg_split('//u',$s,-1,PREG_SPLIT_NO_EMPTY),$start,$length));} }
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
mit_assert(!crm_delivery_status(['token'=>$preview['token']])['confirmed'],'Status before confirm is read-only and unconfirmed');
crm_delivery_confirm(['token'=>$preview['token']]);
mit_assert(crm_delivery_status(['token'=>$preview['token']])['confirmed'],'A lost response can be reconciled from durable confirmation');
$GLOBALS['pdUser']=9;
try{crm_delivery_status(['token'=>$preview['token']]);throw new LogicException('Foreign confirmation exposed');}catch(RuntimeException $e){}
$GLOBALS['pdUser']=1;
crm_delivery_confirm(['token'=>$preview['token']]);
mit_assert((int)db()->query('SELECT COUNT(*) FROM crm_marketing_send_queue')->fetchColumn()===1,'Confirmation retry must not duplicate queue');
$queue=db()->query('SELECT q.*,b.body_html queue_body_template FROM crm_marketing_send_queue q LEFT JOIN crm_marketing_queue_bodies b ON b.id=q.body_ref_id')->fetch();
mit_assert(crm_delivery_queue_body($queue)===$item['body_html'] && $queue['subject']===$item['subject'],'Queue exactly matches preview');
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
$qs=db()->query('SELECT q.*,b.body_html queue_body_template FROM crm_marketing_send_queue q LEFT JOIN crm_marketing_queue_bodies b ON b.id=q.body_ref_id WHERE q.task_id='.(int)$small['task_id'].' ORDER BY q.id')->fetchAll();
mit_assert(count($qs)===5,'Balanced confirmation must be idempotent');
foreach($qs as $i=>$q){$expected=crm_delivery_expand_item($sm,$sm['items'][$i]);mit_assert(crm_delivery_queue_body($q)===$expected['body_html'] && $q['sender_email']===$expected['sender_email'],'Every queued body and sender must equal preview');}
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

// Regression for #30: selected contacts on a group channel expand real groups once.
db()->exec("INSERT INTO crm_customers (id,customer_name,country,owner_user_id,email) VALUES (6000,'Group customer','CN',1,'group@example.invalid'),(6001,'Missing group','CN',1,'missing@example.invalid')");
db()->exec("INSERT INTO crm_contacts (id,customer_id,name,email) VALUES (6000,6000,'Group One','one@example.invalid'),(6001,6000,'Group Two','two@example.invalid'),(6002,6000,'Explicit email','override@example.invalid'),(6003,6001,'No Group','nogroup@example.invalid')");
db()->exec("INSERT INTO crm_customer_promotion_channels(customer_id,channel_key) VALUES(6000,'whatsapp_group'),(6001,'wechat_group')");
db()->exec("INSERT INTO crm_contact_promotions(contact_id,channel,status) VALUES(6000,'whatsapp_group','active'),(6000,'email','paused'),(6002,'email','active')");
db()->exec("INSERT INTO crm_customer_chat_groups(id,customer_id,group_name,group_platform,status,use_for_promotion) VALUES(60,6000,'Actual WA Group','whatsapp_group','active',1),(61,6000,'Stopped','whatsapp_group','paused',1),(62,6000,'Not for promotion','whatsapp_group','active',0),(63,6000,'Other platform','wechat_group','active',1)");
$gi=array_merge($input,['client_request_id'=>'group_preference_regression_30','channel_key'=>'preference','campaign_type'=>'mixed','customer_ids'=>'[6000,6001]','contact_ids'=>'[6000,6001,6002,6003]','audience_config'=>['contact_filter'=>'selected','group_mode'=>'selected'],'attachment_config'=>[]]);
$g=crm_marketing_task_create($gi);$gp=crm_delivery_preview(['task_id'=>$g['task_id']]);
mit_assert($gp['manifest']['total']===2 && $gp['manifest']['manual_count']===1 && $gp['manifest']['email_count']===1,'Selected contacts expand exactly one group and one explicitly overriding email');
mit_assert($gp['manifest']['excluded_total']===1 && strpos($gp['manifest']['excluded'][0]['reason'],'客户群')!==false,'Missing group has specific actionable explanation');
$gm=json_decode(crm_delivery_load_preview(['token'=>$gp['token']])['manifest'],true);
$groupItem=array_values(array_filter($gm['items'],fn($i)=>$i['mode']==='manual'))[0];
mit_assert($groupItem['chat_group_id']===60 && strpos(crm_delivery_expand_item($gm,$groupItem)['body_html'],'Actual WA Group')!==false,'Group is actual valid group, not a contact placeholder; greeting uses group name');
$emailItem=array_values(array_filter($gm['items'],fn($i)=>$i['mode']==='email'))[0];
mit_assert(strpos($emailItem['channel_basis'],'覆盖')!==false,'Explicit contact override is explained and frozen');
crm_delivery_confirm(['token'=>$gp['token']]);crm_delivery_confirm(['token'=>$gp['token']]);
mit_assert((int)db()->query("SELECT COUNT(*) FROM crm_tasks WHERE source_type='marketing_target' AND source_id=".$groupItem['target_id'])->fetchColumn()===1,'Confirmation creates exactly one deduplicated personal task');
$personal=db()->query("SELECT * FROM crm_tasks WHERE source_type='marketing_target' AND source_id=".$groupItem['target_id'])->fetch();
mit_assert((int)$personal['assigned_user_id']===1 && $personal['reminder_at']===$groupItem['planned_at'] && strpos($personal['description'],'Actual WA Group')!==false,'Assignee, reminder and frozen content are present');
mit_assert(strpos(crm_promotion_manual_content(['target_id'=>$groupItem['target_id']])['content'],'Actual WA Group')!==false,'Manual recipient sees exact confirmed content');
// Frozen account signatures and recipients cannot be replaced by later profile changes.
$queue=db()->query('SELECT q.*,b.body_html queue_body_template FROM crm_marketing_send_queue q LEFT JOIN crm_marketing_queue_bodies b ON b.id=q.body_ref_id WHERE q.task_id='.$g['task_id'])->fetch();
$frozen=crm_delivery_queue_body($queue);
db()->exec("UPDATE crm_contacts SET name='CHANGED' WHERE id=6002");
db()->exec("UPDATE crm_user_mail_accounts SET signature_html='<p>CHANGED</p>' WHERE id=1");
mit_assert(crm_delivery_queue_body($queue)===$frozen && strpos($frozen,'Explicit email')!==false,'Frozen vars do not re-read mutable customer/sender');
$corrupt=$queue;$corrupt['queue_body_template'].='changed';
try{crm_delivery_queue_body($corrupt);throw new LogicException('Corrupted template accepted');}catch(RuntimeException $e){}
// Reproduce manually checked failed email: retain SMTP failure and separate human remedy.
db()->exec("UPDATE crm_marketing_send_queue SET send_status='failed',last_error='synthetic 550' WHERE task_id=".$g['task_id']);
db()->exec("UPDATE crm_marketing_task_targets SET target_status='success',manual_result='contacted by phone',manual_checked_by_user_id=1 WHERE id=".$emailItem['target_id']);
$facts=crm_promotion_execution_summaries([crm_marketing_task_row($g['task_id'])])[0];
mit_assert($facts['mail_sent']===0 && $facts['mail_failed']===1 && $facts['manual_remediated_count']===1 && $facts['manual_pending_count']===1,'SMTP failure remains failure after manual remedy; no skipped email in manual pending');
crm_promotion_refresh_status($g['task_id']);
mit_assert((int)crm_marketing_task_row($g['task_id'])['failed_count']===1,'Stored task failure agrees with read facts');
// A shared big signature is stored once per template, not once per recipient.
db()->exec("UPDATE crm_user_mail_accounts SET signature_html='<p>Sender</p>' WHERE id=1");
db()->prepare('UPDATE crm_user_mail_accounts SET signature_html=? WHERE id=1')->execute(['<p>Sender</p><img src="data:image/png;base64,'.str_repeat('A',1300000).'">']);
$big=array_merge($input,['client_request_id'=>'shared_large_signature_30','customer_ids'=>'[2000,2001,2002,2003,2004]','contact_ids'=>'[]','attachment_config'=>[],'mail_body_html'=>'<p>{company_name}</p>']);
$bigTask=crm_marketing_task_create($big);$bigPreview=crm_delivery_preview(['task_id'=>$bigTask['task_id']]);crm_delivery_confirm(['token'=>$bigPreview['token']]);
$stored=db()->query('SELECT COUNT(*) n,COUNT(DISTINCT body_ref_id) refs,SUM(OCTET_LENGTH(body)) raw_bytes FROM crm_marketing_send_queue WHERE task_id='.$bigTask['task_id'])->fetch();
mit_assert((int)$stored['n']===5 && (int)$stored['refs']===1 && (int)$stored['raw_bytes']===0,'Big signature stored once across recipients');
$bad=$big;$bad['client_request_id']='blocked_company_signature_30';$bad['signature_key']='company';
$badTask=crm_marketing_task_create($bad);
try{crm_delivery_preview(['task_id'=>$badTask['task_id']]);throw new LogicException('Company signature allowed for execution');}catch(RuntimeException $e){}
db()->exec("UPDATE crm_user_mail_accounts SET signature_html='' WHERE id=1");
$bad['client_request_id']='blocked_missing_signature_30';$bad['signature_key']='personal';$bad['send_rule']=['delivery_version'=>2,'mail_account_rule'=>'selected_mailbox','mail_account_ids'=>[1]];$badTask=crm_marketing_task_create($bad);
try{crm_delivery_preview(['task_id'=>$badTask['task_id']]);throw new LogicException('Missing account signature allowed');}catch(RuntimeException $e){}
echo "Task30 regressions passed: actual groups, overrides, suppression boundaries, manual task idempotence, frozen content, SMTP/manual separation, shared large signature and missing signature blocking.\n";

// Real manual result handler with isolated DB; only unrelated timeline/upload adapters are fake.
db()->exec('ALTER TABLE crm_customer_chat_groups ADD last_promoted_at DATETIME NULL, ADD updated_by INT NULL, ADD updated_at DATETIME NULL');
function crm_marketing_manual_upload($files){return [];}
function crm_marketing_write_manual_followup(...$args){$GLOBALS['manualFollowups'][]=$args;}
function crm_customer_timeline_add(...$args){}
function crm_marketing_logs(...$args){return [];}
function crm_marketing_task_targets($input){$s=db()->prepare('SELECT * FROM crm_marketing_task_targets WHERE task_id=?');$s->execute([$input['task_id']??0]);return $s->fetchAll();}
foreach(['crm_marketing_manual_execute','crm_marketing_manual_execute_locked','crm_marketing_manual_unexecute','crm_marketing_manual_unexecute_locked'] as $name)eval(pd_extract($source,$name));
try{crm_marketing_manual_execute(['task_id'=>$g['task_id'],'target_ids'=>[$groupItem['target_id']]]);throw new LogicException('Blank result accepted');}catch(RuntimeException $e){}
$done=['task_id'=>$g['task_id'],'target_ids'=>[$groupItem['target_id']],'manual_result'=>'Posted in verified WhatsApp group; customer asks for quote.'];
$GLOBALS['pdUser']=9;
try{crm_marketing_manual_execute($done);throw new LogicException('Foreign operator accepted');}catch(RuntimeException $e){}
$GLOBALS['pdUser']=1;
crm_marketing_manual_execute($done);crm_marketing_manual_execute($done);
mit_assert((int)db()->query("SELECT COUNT(*) FROM crm_marketing_logs WHERE task_id=".$g['task_id']." AND action_key='manual_execute'")->fetchColumn()===1,'Duplicate manual submit is idempotent');
$personal=db()->query('SELECT * FROM crm_tasks WHERE id='.$personal['id'])->fetch();
mit_assert($personal['status']==='done' && $personal['result']!=='' && (int)$personal['completed_by']===1,'Actual manual result completes linked personal task');
$facts=crm_promotion_execution_summaries([crm_marketing_task_row($g['task_id'])])[0];
mit_assert($facts['mail_sent']===0 && $facts['mail_failed']===1 && $facts['manual_success']===1 && $facts['manual_pending_count']===0,'Manual completion cannot convert SMTP failure into success');
crm_marketing_manual_unexecute(['task_id'=>$g['task_id'],'target_id'=>$groupItem['target_id']]);
mit_assert(db()->query('SELECT status FROM crm_tasks WHERE id='.$personal['id'])->fetchColumn()==='pending','Explicit undo reopens linked work only');
$sentTarget=(int)db()->query('SELECT id FROM crm_marketing_task_targets WHERE task_id='.$first['task_id'].' AND contact_id=1')->fetchColumn();
db()->exec("UPDATE crm_marketing_task_targets SET target_status='success' WHERE id=".$sentTarget);
try{crm_marketing_manual_unexecute(['task_id'=>$first['task_id'],'target_id'=>$sentTarget]);throw new LogicException('Actual sent mail undone');}catch(RuntimeException $e){}
echo "Manual completion integration: missing-result and scope rejection, duplicate submit, task-center status sync, explicit undo, SMTP truth preservation passed.\n";

// Repair old confirmed work only, no generated recipients and no queue mutation.
$queueBefore=db()->query('SELECT id,send_status,send_attempts,body_ref_id FROM crm_marketing_send_queue ORDER BY id')->fetchAll();
db()->exec('DELETE FROM crm_tasks WHERE id='.(int)$personal['id']);
$tasksBefore=(int)db()->query('SELECT COUNT(*) FROM crm_tasks')->fetchColumn();
$dry=crm_promotion_repair_manual_tasks((int)$g['task_id']);
mit_assert($dry['missing_count']===1 && (int)db()->query('SELECT COUNT(*) FROM crm_tasks')->fetchColumn()===$tasksBefore,'Backfill dry-run never writes');
$repair=crm_promotion_repair_manual_tasks((int)$g['task_id'],true);
$again=crm_promotion_repair_manual_tasks((int)$g['task_id'],true);
mit_assert($repair['missing_count']===1 && $again['missing_count']===0 && $again['existing_count']===1,'Backfill uses confirmed manual items only and is idempotent');
mit_assert($queueBefore===db()->query('SELECT id,send_status,send_attempts,body_ref_id FROM crm_marketing_send_queue ORDER BY id')->fetchAll(),'Backfill cannot mutate SMTP queue');
echo "Confirmed-only manual backfill dry-run and retry passed, no queue mutation.\n";

function crm_task_center_ensure_tables(){}
function crm_task_row($id){$s=db()->prepare('SELECT * FROM crm_tasks WHERE id=?');$s->execute([$id]);return $s->fetch();}
$taskHandler=pd_extract(file_get_contents(dirname(__DIR__).'/crm_task_center.php'),'crm_task_update_status');
// The actual marketing handler is already loaded; don't load application/bootstrap in this fixture.
$taskHandler=str_replace("require_once __DIR__.'/crm_marketing.php';",'',$taskHandler);
eval($taskHandler);
$linked=(int)db()->query("SELECT id FROM crm_tasks WHERE source_type='marketing_target' AND source_id=".$groupItem['target_id'])->fetchColumn();
$taskDone=crm_task_update_status(['task_id'=>$linked,'status'=>'done','result'=>'Posted via task center']);
mit_assert($taskDone['task']['status']==='done' && db()->query('SELECT manual_result FROM crm_marketing_task_targets WHERE id='.$groupItem['target_id'])->fetchColumn()==='Posted via task center','Task-center completion writes the real promotion result');
echo "Actual task-center completion routes to promotion result passed.\n";

// Whole-customer selection must also honor contact overrides; a blocked first contact cannot swallow its group.
db()->exec("UPDATE crm_user_mail_accounts SET signature_html='<p>Sender</p>' WHERE id=1");
db()->exec("INSERT INTO crm_contacts(id,customer_id,name,email) VALUES(6004,6000,'Blocked first','blocked@example.invalid')");
db()->exec("INSERT INTO crm_contact_promotions(contact_id,channel,status) VALUES(6004,'whatsapp_group','active'),(6004,'no_promotion','active')");
$allGroup=$gi;$allGroup['client_request_id']='all_contacts_preference_guard';$allGroup['contact_ids']='[]';$allGroup['customer_ids']='[6000]';$allGroup['audience_config']=['contact_filter'=>'all','group_mode'=>'selected'];
$allTask=crm_marketing_task_create($allGroup);$allPreview=crm_delivery_preview(['task_id'=>$allTask['task_id']]);
mit_assert($allPreview['manifest']['email_count']===1 && $allPreview['manifest']['manual_count']===1 && $allPreview['manifest']['excluded_total']===1,'Whole-customer selection respects overrides and blocked first contact cannot deduplicate away eligible group');
echo "Whole-customer mixed preference and blocked-contact group dedup passed.\n";

// Actual notification selection: due-start reminder, owner scope and paused parent.
db()->exec('ALTER TABLE crm_tasks ADD opportunity_id INT NULL, ADD quote_id VARCHAR(80) NULL');
function create_system_notification(...$args){$GLOBALS['pdNotifications'][]=$args;}
eval(pd_extract(file_get_contents(dirname(__DIR__).'/notification_service.php'),'notification_sync_task_sources'));
db()->exec("UPDATE crm_tasks SET status='done'");
db()->exec("UPDATE crm_tasks SET status='pending',reminder_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE),due_at=DATE_ADD(NOW(),INTERVAL 1 DAY) WHERE id=".$linked);
db()->exec("UPDATE crm_marketing_task_targets SET target_status='pending' WHERE id=".$groupItem['target_id']);
db()->exec("UPDATE crm_marketing_tasks SET task_status='manual_pending' WHERE id=".$g['task_id']);
$GLOBALS['pdNotifications']=[];notification_sync_task_sources(1);
mit_assert(count($GLOBALS['pdNotifications'])===1 && $GLOBALS['pdNotifications'][0][2]==='推广已到执行时间','Plan start generates reminder before next-day deadline');
$GLOBALS['pdNotifications']=[];notification_sync_task_sources(9);
mit_assert(!$GLOBALS['pdNotifications'],'No reminder to another owner');
db()->exec("UPDATE crm_marketing_tasks SET task_status='paused' WHERE id=".$g['task_id']);
notification_sync_task_sources(1);
mit_assert(!$GLOBALS['pdNotifications'],'Paused project cannot remind execution');
echo "Notification start-time, owner and parent-state integration passed.\n";
function crm_marketing_reconcile_task_targets_from_queue($id){}
eval(pd_extract($source,'crm_marketing_task_execution_summary'));
$report=crm_marketing_task_execution_summary((int)$g['task_id']);
mit_assert($report['mail']['success']===0 && $report['mail']['failed']===1,'Detailed execution report also preserves actual SMTP failure after manual remedy');
echo "Detailed report SMTP truth passed.\n";
