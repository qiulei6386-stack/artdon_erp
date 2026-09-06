<?php
declare(strict_types=1);
namespace CrmSmtpFixture;
// Namespace-local stream/SMTP spies execute the unmodified production function.
// No network, real streams, credentials, app configuration or customer data.
if (PHP_SAPI !== 'cli') exit(2);
use RuntimeException;
use Throwable;
class CrmMailDeliveryUncertain extends RuntimeException {}
function check(bool $ok,string $message): void { if(!$ok)throw new RuntimeException($message); }
$source=file_get_contents(dirname(__DIR__).'/crm_mail.php');
preg_match('/^function crm_mail_smtp_send\(/m',$source,$match,PREG_OFFSET_CAPTURE);$start=$match[0][1];$end=strpos($source,"\nfunction ",$start+1);
eval('namespace CrmSmtpFixture; use RuntimeException; use Throwable; '.substr($source,$start,$end-$start));
function crm_mail_parse_addresses($v): array { return $v===''?[]:[$v]; }
function crm_mail_generate_message_id($a): string { return 'fixture-id'; }
function crm_mail_smtp_connect($a) { return 'fake-handle'; }
function crm_mail_login_email($a): string { return 'sender@example.invalid'; }
function crm_mail_build_message(...$args): string { if($GLOBALS['mode']==='build')throw new RuntimeException('build failed');return "Subject: Fixture\r\n\r\nbody\r\n.dot"; }
function crm_mail_smtp_cmd($fp,$command,$codes): void {
 if(($GLOBALS['mode']==='rcpt' && strpos($command,'RCPT')===0) || ($GLOBALS['mode']==='data' && $command==='DATA') || ($GLOBALS['mode']==='quit' && $command==='QUIT'))throw new RuntimeException('command failed');
}
function fwrite($fp,$data) {
 if($data==="QUIT\r\n") {if($GLOBALS['mode']==='cleanup')throw new RuntimeException('cleanup failed');return strlen($data);}
 if($GLOBALS['mode']==='write')return false;
 $bytes=min(7,strlen($data));$GLOBALS['wire'].=substr($data,0,$bytes);return $bytes;
}
function fclose($fp): void { if($GLOBALS['mode']==='cleanup')throw new RuntimeException('close failed'); }
function crm_mail_smtp_expect($fp,$codes): string {if(in_array($GLOBALS['mode'],['ack','cleanup'],true))throw new RuntimeException('ack lost');return '250 accepted';}
$input=['to_emails'=>'recipient@example.invalid'];
foreach(['success','quit','rcpt','data','build','write','ack','cleanup'] as $mode){
 $wire='';$error=null;$result=null;
 try{$result=crm_mail_smtp_send([], $input);}catch(Throwable $e){$error=$e;}
 if(in_array($mode,['success','quit'],true)){
  check(!$error && $result['response']==='250 accepted','QUIT failure cannot unsend accepted DATA');
  check($wire==="Subject: Fixture\r\n\r\nbody\r\n..dot\r\n.\r\n",'Partial writes must transmit the entire dot-stuffed message');
 }elseif(in_array($mode,['write','ack','cleanup'],true))check($error instanceof CrmMailDeliveryUncertain,'After DATA starts, including cleanup errors, preserve uncertain delivery');
 else check($error instanceof RuntimeException && !$error instanceof CrmMailDeliveryUncertain,'Before DATA transmission is a confirmed failure');
}
echo "crm_mail_smtp_boundary_isolated: 8 original SMTP boundary cases passed; no network\n";
