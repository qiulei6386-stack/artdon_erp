<?php
/** Pure delivery rules: no application bootstrap, database or network. */
if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI only');
require_once dirname(__DIR__) . '/crm_marketing_delivery.php';
function delivery_assert($condition, $message) { if (!$condition) throw new RuntimeException($message); }
function delivery_throws(callable $fn, string $message) { try {$fn();} catch (RuntimeException $e) {return;} throw new RuntimeException($message); }
$schedule = ['send_interval_minutes'=>3,'hourly_limit'=>50,'daily_limit'=>200];
$times=[];
for($i=0;$i<1000;$i++) crm_delivery_next_time($times,100000,$schedule);
for($i=1;$i<count($times);$i++) {
    delivery_assert($times[$i]-$times[$i-1]>=180,'Send spacing must never go backwards or overlap');
    if($i>=50) delivery_assert($times[$i]-$times[$i-50]>=3600,'Rolling hourly limit');
    if($i>=200) delivery_assert($times[$i]-$times[$i-200]>=86400,'Rolling daily limit');
}
delivery_assert($times[50]>$times[49],'Original 50th/51st regression');
$a=[];$b=[];delivery_assert(crm_delivery_next_time($a,1000,$schedule)===crm_delivery_next_time($b,1000,$schedule),'Independent sender plans');
delivery_assert(crm_delivery_channel('preference',[])==='unresolved','Missing channel must not default to email');
delivery_assert(crm_delivery_channel('preference',['email','phone'])==='unresolved','Ambiguous channel must not guess');
delivery_assert(crm_delivery_channel('email',['phone'])==='unresolved','Explicit channel must be in customer records');
delivery_assert(crm_delivery_channel('email',['email','phone'])==='email','Explicit supported choice');
delivery_assert(crm_delivery_channel('preference',['whatsapp'])==='whatsapp','Unique manual preference');
$row=['contact_name'=>'Alice & Bob','customer_name'=>'Example <Company>','country'=>'CN'];
$account=['sender_name'=>'Sender','owner_name'=>'Owner','email_address'=>'sender@example.invalid','user_phone'=>'123','user_position'=>'Sales'];
$html=crm_delivery_render('{contact_name} / {company_name} / {send_email}',$row,$account,true);
delivery_assert(strpos($html,'Alice &amp; Bob')!==false && strpos($html,'Example &lt;Company&gt;')!==false,'HTML-safe customer variables');
delivery_assert(crm_delivery_render('{company_name}',$row,$account,false)==='Example <Company>','Company variable must not become contact name');
delivery_throws(fn()=>crm_delivery_render('{missing}',$row,$account,true),'Unknown variable must block');
delivery_throws(fn()=>crm_delivery_render('{mail_user_position}',$row,array_merge($account,['user_position'=>'']),true),'Missing signature value must block');
$m=['items'=>[['receiver'=>'a@example.invalid','body'=>'one']],'attachments'=>[['sha256'=>'a']]];
$digest=crm_delivery_digest($m);
$changed=$m;$changed['items'][0]['body']='two';delivery_assert($digest!==crm_delivery_digest($changed),'Body edits invalidate preview');
$changed=$m;$changed['attachments'][0]['sha256']='b';delivery_assert($digest!==crm_delivery_digest($changed),'Attachment edits invalidate preview');
$js=file_get_contents(dirname(__DIR__).'/assets/crm/promotion-composer.js');
delivery_assert(strpos($js,"post('marketing_queue_build'")===false,'Composer must not call legacy queue builder');
delivery_assert(strpos($js,'marketing_delivery_confirm')!==false && strpos($js,'data-pc-consent')!==false,'Explicit final confirmation');
$php=file_get_contents(dirname(__DIR__).'/crm_marketing.php');
delivery_assert(strpos($php,'crm_delivery_verify_recipient($row,$deliveryMeta)')!==false,'Send-time recipient recheck');
delivery_assert(strpos($php,"'SELECT task_id FROM crm_marketing_delivery_requests")!==false,'Draft request identity');
echo "Promotion delivery: monotonic schedule (1000 recipients), strict channels, escaped variables, snapshot invalidation and safety wiring passed.\n";
