<?php
declare(strict_types=1);
// Executes queue admission/claim/progress against a strict memory statement model.
// No real PDO, filesystem attachments, application config or SMTP connections.
if(PHP_SAPI!=='cli')exit(2);
set_error_handler(static function($severity,$message,$file,$line){throw new ErrorException($message,0,$severity,$file,$line);});
function async_assert(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
$source=file_get_contents(dirname(__DIR__).'/crm_mail.php');
class CrmMailDeliveryUncertain extends RuntimeException {}
foreach(['crm_mail_existing_send_result','crm_mail_send_start','crm_mail_send_due_jobs','crm_mail_send_progress'] as $name){
 preg_match('/^function '.preg_quote($name,'/').'\(/m',$source,$m,PREG_OFFSET_CAPTURE);$start=$m[0][1];$end=strpos($source,"\nfunction ",$start+1);eval(substr($source,$start,$end-$start));
}
class AsyncDb {
 public array $rows=[];public array $queries=[];public bool $loseClaim=false;
 function prepare($sql){return new AsyncStatement($this,$sql);}
}
class AsyncStatement {
 private AsyncDb $db;private string $sql;private array $result=[];private int $affected=0;
 function __construct($db,$sql){$this->db=$db;$this->sql=$sql;}
 function execute($p=[]){
  $s=$this->sql;$this->db->queries[]=[$s,$p];$this->result=[];$this->affected=0;
  if(strpos($s,'SELECT job_id,')===0){
   async_assert(strpos($s,'AND mail_account_id = ?')!==false && count($p)===3,'Scoped admission/progress');
   foreach($this->db->rows as $row)if($row['job_id']===$p[0] && $row['user_id']===$p[1] && $row['mail_account_id']===$p[2])$this->result[]=$row;
  }elseif(strpos($s,'INSERT INTO crm_mail_send_jobs')===0){
   async_assert(strpos($s,'"scheduled", "waiting", 5')!==false,'Never send SMTP during admission');
   $id=count($this->db->rows)+1;
   $this->db->rows[$id]=['id'=>$id,'user_id'=>$p[0],'mail_account_id'=>$p[1],'job_id'=>$p[2],'to_emails'=>$p[3],'subject'=>$p[4],'status'=>'scheduled','stage'=>'waiting','percent'=>5,'scheduled_at'=>$p[5],'payload_json'=>$p[6],'attachments_json'=>$p[7]];
  }elseif(strpos($s,"SELECT * FROM crm_mail_send_jobs WHERE status = 'scheduled'")===0){
   foreach($this->db->rows as $row)if($row['status']==='scheduled' && strtotime($row['scheduled_at'])<=time() && (!$p || $row['job_id']===$p[0]))$this->result[]=$row;
  }elseif(strpos($s,"UPDATE crm_mail_send_jobs SET status = 'running'")===0){
   async_assert(strpos($s,"AND status = 'scheduled'")!==false,'Claim must compare expected state');
   if(!$this->db->loseClaim && $this->db->rows[$p[0]]['status']==='scheduled'){$this->db->rows[$p[0]]['status']='running';$this->affected=1;}
  }elseif(strpos($s,'SELECT * FROM crm_user_mail_accounts')===0){
   async_assert($p===[3,7],'Worker account must match persisted job owner');$this->result=[['id'=>3,'user_id'=>7,'email_password_encrypted'=>'synthetic','email_address'=>'sender@example.invalid']];
  }elseif(strpos($s,"UPDATE crm_mail_send_jobs SET status = 'success'")===0){$this->db->rows[$p[1]]['status']='success';$this->db->rows[$p[1]]['sent_mail_id']=$p[0];
  }elseif(strpos($s,'UPDATE crm_mail_send_jobs SET status = ?, stage = ?')===0){async_assert(count($p)===4,'Failure parameter contract');$this->db->rows[$p[3]]['status']=$p[0];$this->db->rows[$p[3]]['error_message']=$p[2];
  }else throw new RuntimeException('Unexpected SQL: '.$s);
 }
 function fetch(){return $this->result[0]??false;}function fetchAll(){return $this->result;}function rowCount(){return $this->affected;}
}
$db=new AsyncDb();$account=['id'=>3,'user_id'=>7,'delay_send_minutes'=>0];$deliveries=0;$deliveryMode='success';
function db(){return $GLOBALS['db'];}
function cleanupCrmMailTempFiles(){return [];}
function crm_require($p){async_assert(in_array($p,['mail.send','mail.view'],true),'Explicit permission');if(($GLOBALS['deniedPermission']??'')===$p)throw new RuntimeException('Denied');}
function crm_mail_current_account($secret){return $GLOBALS['account'];}
function crm_mail_render_signature_variables($body,$account){return $body;}
function crm_mail_extract_embedded_attachment_images($body,$account){return ['html'=>$body,'attachments'=>[]];}
function crm_mail_extract_inline_data_attachments($body){return ['html'=>$body,'attachments'=>[]];}
function crm_mail_uploaded_files($files){return [];}
function crm_mail_draft_attachment_files($a,$i){return [];}
function crm_mail_original_attachments($a,$i){return [];}
function crm_mail_datasheet_attachments($i){return [];}
function crm_mail_cleanup_generated_attachments($a){}
function crm_mail_queue_attachment_files($u,$job,$a){return [];}
function crm_mail_cleanup_queue_files($a){}
function crm_log_event(...$args){}
function crm_mail_ensure_tables(){}
function crm_mail_decrypt($v){return 'synthetic';}
function crm_mail_execute_send_job($account,$input,$attachments,$job,$body){
 $GLOBALS['deliveries']++;
 if($GLOBALS['deliveryMode']==='failed')throw new RuntimeException('fixture before DATA failure');
 if($GLOBALS['deliveryMode']==='unknown')throw new CrmMailDeliveryUncertain('fixture lost acknowledgement');
 return ['sent_mail_id'=>81];
}
$input=['mode'=>'compose','to_emails'=>'recipient@example.invalid','subject'=>'Fixture','body_html'=>'<p>Fixture</p>','request_token'=>'fixture_same_submission_0001'];
$deniedPermission='mail.send';$rejected=false;try{crm_mail_send_start(array_replace($input,['mode'=>'reply']));}catch(RuntimeException $e){$rejected=true;}async_assert($rejected && !$db->rows && !$deliveries,'Read-only reply cannot queue or send');$deniedPermission='';
$first=crm_mail_send_start($input);$second=crm_mail_send_start($input);
async_assert($deliveries===0 && count($db->rows)===1 && $first['job_id']===$second['job_id'],'Immediate send must queue and retry must not duplicate');
async_assert(!isset($second['payload_json']),'Replayed acknowledgement cannot expose stored payload');
$rejected=false;try{crm_mail_send_start(array_replace($input,['body_html'=>'Changed after acceptance']));}catch(RuntimeException $e){$rejected=true;}async_assert($rejected && count($db->rows)===1,'Changed content cannot silently reuse an accepted request');
async_assert($first['status']==='scheduled' && strtotime($first['scheduled_at'])<=time(),'Zero-delay is due immediately');
crm_mail_send_due_jobs(1,'send_not_this_job');async_assert($deliveries===0,'Post-response worker may only claim this accepted job');
$db->loseClaim=true;crm_mail_send_due_jobs(1,$first['job_id']);async_assert($deliveries===0,'Losing claim cannot send');$db->loseClaim=false;
crm_mail_send_due_jobs(1,$first['job_id']);crm_mail_send_due_jobs(1,$first['job_id']);async_assert($deliveries===1 && $db->rows[1]['status']==='success','Only one successful claim');
$progress=crm_mail_send_progress($first['job_id']);async_assert($progress['sent_mail_id']===81,'Real result reported');
$account['id']=4;$rejected=false;try{crm_mail_send_progress($first['job_id']);}catch(RuntimeException $e){$rejected=true;}async_assert($rejected,'Another account cannot read progress');$account['id']=3;
foreach(['failed','unknown'] as $mode){$deliveryMode=$mode;$input['request_token']='fixture_'.$mode.'_submission_0002';$job=crm_mail_send_start($input);crm_mail_send_due_jobs(1,$job['job_id']);$n=$deliveries;crm_mail_send_due_jobs(1,$job['job_id']);async_assert($deliveries===$n && end($db->rows)['status']===$mode,'No automatic retry for '.$mode);}
$account['delay_send_minutes']=10;$input['request_token']='fixture_delayed_submission_0003';$job=crm_mail_send_start($input);$n=$deliveries;crm_mail_send_due_jobs(1,$job['job_id']);async_assert($deliveries===$n && strtotime($job['scheduled_at'])>=time()+599,'Delay remains enforced');
$api=file_get_contents(dirname(__DIR__).'/crm_api.php');$start=strpos($api,"\$sendJob = crm_mail_send_start");$slice=substr($api,$start,1500);
async_assert(strpos($slice,'crm_api_release_session_lock();')<strpos($slice,'register_shutdown_function'),'Release session before background work');
async_assert(strpos($slice,'fastcgi_finish_request();')<strpos($slice,'crm_mail_send_due_jobs(1,'),'Complete HTTP response before delivery');
async_assert(strpos($source,'SELECT job_id, status, stage, percent, scheduled_at, sent_mail_id, error_message, subject, to_emails, created_at, updated_at FROM crm_mail_send_jobs')!==false,'Progress must not expose body/payload or attachment paths');
echo 'crm_mail_async_isolated: admission, replay, claims, scope, delay, failure and unknown-result checks passed'.PHP_EOL;
