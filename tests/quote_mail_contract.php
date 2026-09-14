<?php
require dirname(__DIR__).'/includes/quote_mail.php';
function qtest(bool $ok,string $label): void {if(!$ok)throw new RuntimeException($label);}
function qreject(callable $fn,string $label): void {$failed=false;try{$fn();}catch(Throwable $e){$failed=true;}qtest($failed,$label);}
function qfixture(): array {
    $items=[['qty'=>3,'price'=>12.5,'currency'=>'USD','amount'=>37.5,'product'=>['code'=>'QA-LAMP','name'=>'Acceptance LED lamp','image'=>'','description'=>'Acceptance fixture only']]];
    $m=qm_calculate($items,'USD',['type'=>'discount_amount','value'=>2.5]);
    return ['id'=>1,'quote_no'=>'QA-MAIL-001','quote_date'=>'2026-09-14','quote_status'=>'Quotation sheet','customer_json'=>json_encode(['company'=>'Acceptance Test Customer','primary_contact'=>'Test Contact','primary_contact_email'=>'acceptance@example.invalid']),'header_json'=>json_encode(['company'=>'Acceptance Lighting','from'=>'Acceptance Team','address'=>'Test Address']),'bank_json'=>'{}','template_json'=>'{}','items_json'=>json_encode($items),'exchange_rate'=>1]+$m;
}
$s=qfixture();$p=qmail_safe_images(qmail_payload($s));
qtest((float)$p['total']['amount']===35.0&&(float)$p['quote_adjustment']['value']===2.5,'Same approved money and adjustment in export');
qreject(function()use($p){$p['items'][0]['product']['image']='https://evil.example/image.png';qmail_safe_images($p);},'External images fail closed');
qreject(function()use($p){$p['items'][0]['product']['image']='../../etc/passwd';qmail_safe_images($p);},'Local path traversal blocked');
qreject(function()use($p){$p['items'][0]['product']['image']='data:image/png;base64,YmFk';qmail_safe_images($p);},'Invalid inline image blocked');
$api=file_get_contents(dirname(__DIR__).'/quote_mail_api.php');$mail=file_get_contents(dirname(__DIR__).'/crm_mail.php');$renderer=file_get_contents(dirname(__DIR__).'/includes/quote_mail_render.php');
foreach(['verify_csrf()','artdon_sso_can','qmail_package','qmail_assert_current','crm_mail_draft_attachment_files','hash_file'] as $guard)qtest(strpos($api,$guard)!==false,'Endpoint guard '.$guard);
qtest(substr_count($mail,'qmail_send_guard(')===2,'Admission and worker both check approved snapshot');
qtest(strpos($mail,'qmail_cancel_to_draft')!==false&&strpos($mail,'qmail_sent')!==false,'Cancellation and successful send preserve tracking');
qtest(strpos($renderer,"PHP_SAPI !== 'cli'")!==false,'Worker rejects browser requests');
qtest(strpos(file_get_contents(dirname(__DIR__).'/includes/quote_mail.php'),'is_readable($renderer)')!==false,'Application renderer readability checked before process launch');
qtest(strpos(file_get_contents(dirname(__DIR__).'/includes/quote_mail.php'),'--no-sandbox')===false,'PDF renderer retains Chrome sandbox');
qtest(strpos(file_get_contents(dirname(__DIR__).'/includes/quote_mail.php'),"function_exists('proc_close')")!==false,'Restricted FPM process lifecycle supported');
echo "Quote mail contract: export snapshot, image boundaries, scoped endpoints, send guards and sandbox passed.\n";
