<?php
/** Pure delivery rules: no application bootstrap, database or network. */
if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI only');
require_once dirname(__DIR__) . '/crm_marketing_delivery.php';
function current_user(){return ['id'=>1];}
function crm_can($key){return false;}
function is_super_admin(){return false;}
function crm_marketing_json($value){return is_array($value)?$value:(json_decode($value,true) ?: []);}
function delivery_assert($condition, $message) { if (!$condition) throw new RuntimeException($message); }
function delivery_throws(callable $fn, string $message) { try {$fn();} catch (RuntimeException $e) {return;} throw new RuntimeException($message); }
$mime=crm_delivery_attachment_mime('Acceptance attachment without business data.');
delivery_assert(in_array($mime,['text/plain','application/octet-stream'],true),'Attachment MIME must work with and without optional fileinfo');
if (!class_exists('finfo')) delivery_assert($mime==='application/octet-stream','Missing fileinfo must use opaque download MIME');
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
delivery_assert(crm_delivery_render('{contact_name}',$row,$account,false)==='Alice & Bob','Subject uses the real contact without HTML escaping');
delivery_throws(fn()=>crm_delivery_render('{contact_name}',['contact_name'=>'','customer_name'=>'Only Company'],$account,true),'Missing contact must not silently become company name');
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

foreach ([2,3,5] as $size) {
    $accounts=[];for($i=1;$i<=$size;$i++)$accounts[]=['id'=>$i,'user_id'=>1];
    $pool=crm_delivery_account_pool($accounts,['mail_account_ids'=>range(1,$size)]);
    $cursor=0;$countries=[];$counts=array_fill(1,$size,0);
    for($i=0;$i<3000;$i++){$a=crm_delivery_pick_account($accounts,$pool,'balanced',['owner_user_id'=>1,'country'=>'CN'],$cursor,$countries);$counts[$a['id']]++;}
    delivery_assert(max($counts)-min($counts)<=1,'Balanced allocation must differ by at most one');
    $cursor=0;$countries=[];
    $a=crm_delivery_pick_account($accounts,$pool,'group_by_country',['country'=>'CN'],$cursor,$countries);
    crm_delivery_pick_account($accounts,$pool,'group_by_country',['country'=>'IN'],$cursor,$countries);
    $b=crm_delivery_pick_account($accounts,$pool,'group_by_country',['country'=>'CN'],$cursor,$countries);
    delivery_assert($a['id']===$b['id'],'One country stays on the same account');
}
delivery_throws(fn()=>crm_delivery_account_pool([['id'=>8,'user_id'=>2]],['mail_account_ids'=>[8]]),'Foreign mailbox must not be accepted');
delivery_throws(fn()=>crm_delivery_account_pool([['id'=>1,'user_id'=>1]],['mail_account_ids'=>[99]]),'Unavailable mailbox must not fall back');
delivery_throws(fn()=>crm_delivery_account_pool([['id'=>1,'user_id'=>1]],[]),'Empty pool must not expand');
$start=microtime(true);$contents=[];$rows=[];
$body='<p>{company_name}</p><p>'.str_repeat('x',100*1024).'</p>';
for($i=0;$i<3000;$i++){
    $vars=['customer_name'=>'Synthetic '.$i,'contact_name'=>'Person','country'=>'CN'];
    $account=['email_address'=>'sender'.($i%3).'@example.invalid','sender_name'=>'Sender'];
    $rows[]=['mode'=>'email','account_id'=>$i%3,'sender_email'=>$account['email_address'],'planned_at'=>'2026-09-08 09:00:00','customer_name'=>$vars['customer_name']]+crm_delivery_pack_content($contents,$body,'<p>{send_email}</p>',$vars,$account);
}
$manifest=['contents'=>$contents,'items'=>$rows,'excluded'=>array_fill(0,225,['reason'=>'synthetic']),'attachments'=>[],'timezone'=>'Asia/Shanghai','inputs'=>[]];
delivery_assert(count($contents)===2,'Shared body and signature templates stored once');
delivery_assert(strlen(json_encode($manifest))<3*1024*1024,'3000 large bodies must not multiply snapshot storage');
$page=crm_delivery_preview_page($manifest,'synthetic',['page'=>149,'index'=>2999,'excluded_page'=>11])['manifest'];
delivery_assert(count($page['items'])===20 && $page['selected_index']===2999 && $page['total']===3000,'Last page/global index');
delivery_assert(count($page['excluded'])===5 && $page['excluded_total']===225,'Exclusions must be fully pageable');
delivery_assert(!isset($page['items'][0]['body_html']) && !isset($page['contents']),'Metadata pages must not transmit shared bodies');
delivery_assert(strpos($page['current_item']['body_html'],'Synthetic 2999')!==false && strpos($page['current_item']['body_html'],'sender2@example.invalid')!==false,'Exact frozen customer and sender signature');
delivery_assert(strlen(json_encode($page))<200*1024,'Only one expanded body per response');
echo 'Bulk preview: 3000 x 100KiB, bytes='.strlen(json_encode($manifest)).', page_bytes='.strlen(json_encode($page)).', seconds='.round(microtime(true)-$start,3).', peak_memory='.memory_get_peak_usage(true)."; 2/3/5 mailbox balancing and access guards passed.\n";
