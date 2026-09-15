<?php
require_once __DIR__.'/quote_money.php';

function qmail_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS quote_mail_packages (
      token CHAR(48) PRIMARY KEY, user_id INT UNSIGNED NOT NULL, account_id INT UNSIGNED NOT NULL,
      quote_id BIGINT UNSIGNED NOT NULL, quote_no VARCHAR(180) NOT NULL, snapshot_hash CHAR(64) NOT NULL,
      request_hash CHAR(64) NOT NULL, draft_id BIGINT UNSIGNED NULL, customer_id INT UNSIGNED NULL,
      files_json MEDIUMTEXT NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'draft',
      job_id VARCHAR(100) NULL, sent_mail_id BIGINT UNSIGNED NULL, sent_to TEXT NULL, created_at DATETIME NOT NULL,
      sent_at DATETIME NULL, KEY idx_qmail_draft(draft_id), KEY idx_qmail_quote(quote_id,user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS quote_mail_context (
      token CHAR(48) PRIMARY KEY, mail_kind VARCHAR(16) NOT NULL, test_recipient VARCHAR(254) NOT NULL DEFAULT ''
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS quote_mail_attempts (
      job_id VARCHAR(100) PRIMARY KEY, token CHAR(48) NOT NULL,
      sender_email VARCHAR(254) NOT NULL, sender_name VARCHAR(190) NOT NULL,
      to_emails TEXT NOT NULL, cc_emails TEXT NOT NULL, bcc_emails TEXT NOT NULL,
      subject VARCHAR(500) NOT NULL, queued_at DATETIME NOT NULL,
      sent_at DATETIME NULL, sent_mail_id BIGINT UNSIGNED NULL,
      KEY idx_qmail_attempt_token(token,queued_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function qmail_context(PDO $pdo,string $token): array {
    $st=$pdo->prepare('SELECT mail_kind,test_recipient FROM quote_mail_context WHERE token=?');$st->execute([$token]);
    return $st->fetch(PDO::FETCH_ASSOC)?:['mail_kind'=>'legacy','test_recipient'=>''];
}
function qmail_options(array $input): array {
    $kind=(string)($input['mail_kind']??'formal');if(!in_array($kind,['formal','test'],true))throw new RuntimeException('请选择正式邮件或测试邮件。');
    $email=strtolower(trim((string)($input['email']??'')));
    if($kind==='test'&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('请填写一个有效的测试邮箱，不支持多个地址。');
    $text=trim((string)($input['body_text']??''));if(preg_match('/^.{0,2000}$/usD',$text)!==1)throw new RuntimeException('简短正文最多2000字，长内容可进入CRM后编辑。');
    return [$kind,$email,$text];
}
function qmail_history(PDO $pdo,int $id,int $userId,int $offset=0): array {
    $offset=max(0,min(10000,$offset));
    // Snapshot recipient fields at queue admission; cancellations retain each earlier attempt.
    $sql="SELECT * FROM (
      SELECT a.job_id,a.sender_email,a.sender_name,a.to_emails,a.cc_emails,a.bcc_emails,a.subject,a.queued_at,
        COALESCE(a.sent_at,CASE WHEN j.status='success' THEN j.finished_at END) AS sent_at,
        j.finished_at,j.scheduled_at,COALESCE(a.sent_mail_id,j.sent_mail_id) AS sent_mail_id,
        CASE WHEN a.sent_at IS NOT NULL THEN 'success' WHEN j.status='running' AND j.updated_at<DATE_SUB(NOW(),INTERVAL 15 MINUTE) THEN 'unknown' ELSE COALESCE(j.status,'unknown') END AS status,
        j.error_message,p.snapshot_hash,COALESCE(c.mail_kind,'legacy') AS mail_kind
      FROM quote_mail_attempts a JOIN quote_mail_packages p ON p.token=a.token
      LEFT JOIN quote_mail_context c ON c.token=p.token
      LEFT JOIN crm_mail_send_jobs j ON j.job_id=a.job_id COLLATE utf8mb4_unicode_ci AND j.user_id=p.user_id AND j.mail_account_id=p.account_id
      WHERE p.quote_id=? AND p.user_id=?
      UNION ALL
      SELECT NULL,'','',COALESCE(p.sent_to,''),'','','',p.created_at,p.sent_at,p.sent_at,NULL,p.sent_mail_id,'success',NULL,p.snapshot_hash,COALESCE(c.mail_kind,'legacy')
      FROM quote_mail_packages p LEFT JOIN quote_mail_context c ON c.token=p.token
      WHERE p.quote_id=? AND p.user_id=? AND p.status='sent' AND NOT EXISTS(SELECT 1 FROM quote_mail_attempts a WHERE a.token=p.token)
    ) h ORDER BY queued_at DESC,job_id DESC LIMIT 21 OFFSET {$offset}";
    $st=$pdo->prepare($sql);$st->execute([$id,$userId,$id,$userId]);$rows=$st->fetchAll(PDO::FETCH_ASSOC);
    return ['rows'=>array_slice($rows,0,20),'has_more'=>count($rows)>20,'offset'=>$offset];
}
function qmail_snapshot(PDO $pdo, int $id): array {
    $size=$pdo->prepare('SELECT OCTET_LENGTH(approved_snapshot_json) FROM quote_orders WHERE id=?');$size->execute([$id]);if((int)$size->fetchColumn()>24*1024*1024)throw new RuntimeException('审核快照超过24MB，请另存压缩图片版本并重新审核；原报价未改动。');
    $st=$pdo->prepare('SELECT id,quote_no,approval_status,approved_snapshot_json FROM quote_orders WHERE id=?');$st->execute([$id]);$q=$st->fetch(PDO::FETCH_ASSOC);
    if (!$q || $q['approval_status']!=='approved') throw new RuntimeException('报价尚未审核通过，不能发送报价附件。');
    if (strlen((string)$q['approved_snapshot_json'])>24*1024*1024) throw new RuntimeException('审核快照图片较大，请先另存压缩图片版本并重新审核；原报价未改动。');
    $snap=json_decode((string)$q['approved_snapshot_json'],true,64,JSON_THROW_ON_ERROR);
    if (!is_array($snap)||(int)($snap['id']??0)!==$id||($snap['quote_no']??'')!==$q['quote_no']) throw new RuntimeException('审核快照不存在或归属不一致，请重新核对审核。');
    qm_validate_snapshot($snap);
    return ['snapshot'=>$snap,'hash'=>hash('sha256',$q['approved_snapshot_json'])];
}
function qmail_assert_current(PDO $pdo, array $package): void {
    $st=$pdo->prepare('SELECT approval_status,SHA2(approved_snapshot_json,256) AS revision FROM quote_orders WHERE id=?');$st->execute([(int)$package['quote_id']]);$q=$st->fetch(PDO::FETCH_ASSOC);
    if (!$q||$q['approval_status']!=='approved'||!hash_equals($package['snapshot_hash'],(string)$q['revision'])) throw new RuntimeException('报价已反审或审核版本已变化。请回报价重新生成附件，不会发送旧版。');
}
function qmail_payload(array $snap): array {
    $decode=static fn($key)=>json_decode((string)($snap[$key]??'{}'),true)?:[];
    $payload=['quote_id'=>(int)$snap['id'],'quote_no'=>$snap['quote_no'],'quote_date'=>$snap['quote_date']??'',
      'quote_status'=>$snap['quote_status']??'Quotation sheet','currency'=>$snap['currency'],'exchange_rate'=>$snap['exchange_rate']??1,
      'customer'=>$decode('customer_json'),'header'=>$decode('header_json'),'bank'=>$decode('bank_json'),'template'=>$decode('template_json'),
      'items'=>$decode('items_json'),'total'=>['qty'=>$snap['qty'],'amount'=>$snap['amount']],
      'subtotal_amount'=>$snap['subtotal_amount']??0,'adjustment_amount'=>$snap['adjustment_amount']??0,'quote_adjustment'=>$decode('adjustment_json')];
    return $payload;
}
function qmail_safe_images(array $payload): array {
    $root=realpath(dirname(__DIR__));
    foreach ($payload['items'] as &$item) {
        $src=trim((string)($item['product']['image']??''));if($src==='')continue;
        $inline=(bool)preg_match('#^data:image/(png|jpeg|jpg|gif|webp);base64,#i',$src);
        if ($inline) {
            $bytes=base64_decode(substr($src,strpos($src,',')+1),true);
        } else {
            $path=parse_url($src,PHP_URL_PATH);$host=parse_url($src,PHP_URL_HOST);
            if ($host && !in_array(strtolower($host),['novlight.com','www.novlight.com'],true)) throw new RuntimeException('附件包含外部图片，请先将图片保存到系统再重新审核。');
            $path=preg_replace('#^/artdon_erp/#','/',(string)$path);
            $real=realpath($root.'/'.ltrim($path,'/'));
            if (!$real||!preg_match('#^'.preg_quote($root,'#').'/(uploads|storage|assets)/#',$real)||!is_file($real)||filesize($real)>8*1024*1024) throw new RuntimeException('报价图片缺失或过大，已停止生成附件。');
            $bytes=file_get_contents($real);
        }
        if (!is_string($bytes)||strlen($bytes)>8*1024*1024||!($meta=@getimagesizefromstring($bytes))||$meta[0]*$meta[1]>12000000) throw new RuntimeException('报价图片无效或尺寸过大，已停止生成附件。');
        if(!$inline)$item['product']['image']='data:'.$meta['mime'].';base64,'.base64_encode($bytes);
        unset($bytes);
    } unset($item);
    return $payload;
}
function qmail_run(array $command, string $input, string $output, string $error): void {
    $process=proc_open($command,[0=>['file',$input,'r'],1=>['file',$output,'w'],2=>['file',$error,'w']],$pipes,dirname(__DIR__));
    if (!is_resource($process)) throw new RuntimeException('附件生成进程无法启动。');
    // PHP-FPM may disable proc_close while retaining proc_open/get_status. Do not weaken its policy.
    $deadline=microtime(true)+40;
    do {$status=proc_get_status($process);if(!$status['running'])break;usleep(50000);}while(microtime(true)<$deadline);
    if($status['running']){if(function_exists('proc_terminate'))proc_terminate($process);throw new RuntimeException('附件生成超时，请稍后重试。');}
    $code=(int)$status['exitcode'];
    if(function_exists('proc_close')){$closed=proc_close($process);if($code<0)$code=$closed;}
    if ($code!==0) throw new RuntimeException('附件生成失败或超时，请稍后重试；不会创建不完整邮件。');
}
function qmail_files(array $snap, array $formats, string $dir): array {
    $payload=qmail_safe_images(qmail_payload($snap));
    $input=$dir.'/payload.json';file_put_contents($input,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));unset($payload);
    $php=PHP_BINDIR.'/php';$timeout='/usr/bin/timeout';
    if (!is_executable($php)||!is_executable($timeout)||!function_exists('proc_open')) throw new RuntimeException('服务器尚未配置附件生成运行环境。');
    $files=[];$base=preg_replace('/[^\pL\pN._-]/u','_',$snap['quote_no']);
    foreach($formats as $format) {
        $out=$dir.($format==='pdf'?'/quote.html':'/quote.xlsx');
        $renderer=__DIR__.'/quote_mail_render.php';
        if(!is_readable($renderer))throw new RuntimeException('附件生成脚本不可读，请联系管理员检查发布权限。');
        qmail_run([$timeout,'35',$php,'-d','memory_limit=128M',$renderer,$format],$input,$out,$dir.'/render.err');
        if ($format==='pdf') {
            $chrome=crm_mail_datasheet_chrome_bin();if($chrome==='')throw new RuntimeException('PDF生成器不可用，请联系管理员。');
            $html=file_get_contents($out);$html=preg_replace('#<script\b[^>]*>.*?</script>#is','',$html);
            // Preserve the export's data/layout, but keep narrow column labels intact in server printing.
            $html=str_replace('Manufacturer<br>Code','Mfr.<br>Code',$html);
            $html=preg_replace('#Price\(([A-Z]{3})\)#','Price<br>($1)',$html);
            $html=str_replace('<td colspan="5"></td><td><b>','<td colspan="4"></td><td colspan="2" style="text-align:right"><b>',$html);
            $html=str_replace('</head>','<style>@page{size:A4;margin:12mm}@media print{html,body{background:#fff!important}.paper,.paper.quote-one-item{width:186mm!important;padding:8mm 0 5mm!important;min-height:0!important;height:auto!important}.final-summary,.final-sign{break-inside:avoid;page-break-inside:avoid}}</style></head>',$html);
            $font=dirname(__DIR__).'/assets/fonts/ARSMaqLigTr.otf';
            if(is_file($font))$html=str_replace('assets/fonts/ARSMaqLigTr.otf','data:font/otf;base64,'.base64_encode(file_get_contents($font)),$html);
            $html=preg_replace('#<head>#i','<head><meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src data:; style-src \'unsafe-inline\'; font-src data:;">',$html,1);
            file_put_contents($out,$html);unset($html);$pdf=$dir.'/quote.pdf';
            qmail_run([$timeout,'35',$chrome,'--headless','--disable-gpu','--disable-dev-shm-usage','--disable-background-networking','--no-first-run','--no-pdf-header-footer','--user-data-dir='.$dir.'/chrome','--print-to-pdf='.$pdf,'file://'.$out],$input,$dir.'/chrome.out',$dir.'/chrome.err');
            $out=$pdf;
        }
        $size=is_file($out)?filesize($out):0;$f=$size?fopen($out,'rb'):false;$magic=$f?fread($f,5):'';if($f)fclose($f);
        if ($size<100||$size>20*1024*1024||($format==='pdf'?strpos($magic,'%PDF-')!==0:strpos($magic,'PK')!==0)) throw new RuntimeException('生成的附件格式或大小不正确，未创建草稿。');
        $files[]=['name'=>$base.($format==='pdf'?'.pdf':'.xlsx'),'tmp_name'=>$out,'size'=>$size,'type'=>$format==='pdf'?'application/pdf':'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
    }
    if(array_sum(array_column($files,'size'))>20*1024*1024)throw new RuntimeException('报价附件合计超过20MB，请减小图片后重新审核。');
    return $files;
}
function qmail_remove_temp(string $dir): void {
    if(!preg_match('#^'.preg_quote(sys_get_temp_dir(),'#').'/quote-mail-[a-f0-9]{24}$#',$dir)||is_link($dir))return;
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $file){if($file->isDir()&&!$file->isLink())@rmdir($file->getPathname());else @unlink($file->getPathname());}@rmdir($dir);
}
function qmail_contacts(array $snap): array {
    $c=json_decode((string)($snap['customer_json']??'{}'),true)?:[];$id=(int)($c['crm_customer_id']??0);$rows=[];
    if($id>0){
        crm_customer_get($id,'overview');
        $st=db()->prepare('SELECT id,name,email FROM crm_contacts WHERE customer_id=? AND deleted_at IS NULL AND COALESCE(is_left,0)=0 AND COALESCE(do_not_contact,0)=0 AND COALESCE(unsubscribe_email,0)=0 ORDER BY is_primary DESC,id LIMIT 200');$st->execute([$id]);$rows=$st->fetchAll(PDO::FETCH_ASSOC);
        $st=db()->prepare('SELECT email,customer_name AS name FROM crm_customers c WHERE id=? AND deleted_at IS NULL AND NOT EXISTS (SELECT 1 FROM crm_contacts p WHERE p.customer_id=c.id AND LOWER(TRIM(p.email))=LOWER(TRIM(c.email)) AND (p.deleted_at IS NOT NULL OR COALESCE(p.is_left,0)=1 OR COALESCE(p.do_not_contact,0)=1 OR COALESCE(p.unsubscribe_email,0)=1))');$st->execute([$id]);$company=$st->fetch(PDO::FETCH_ASSOC);if($company)$rows[]=['id'=>0]+$company;
    } else {
        foreach(['primary_contact_email','email'] as $key)if(!empty($c[$key]))$rows[]=['id'=>0,'name'=>$c['primary_contact']??$c['contact']??'报价联系人','email'=>$c[$key]];
    }
    $out=[];$unavailable=[];foreach($rows as $r){$email=strtolower(trim((string)$r['email']));if(filter_var($email,FILTER_VALIDATE_EMAIL)){if(!isset($out[$email]))$out[$email]=['id'=>(int)$r['id'],'name'=>$r['name'],'email'=>$email];}elseif((int)$r['id']>0){$unavailable[]=['name'=>$r['name'],'reason'=>$email===''?'未填写邮箱':'邮箱格式无效'];}}
    return ['customer_id'=>$id,'customer_name'=>$c['company']??$c['customer_name']??'','contacts'=>array_values($out),'unavailable_contacts'=>$unavailable];
}
function qmail_package(PDO $pdo, string $token, array $account): array {
    $st=$pdo->prepare('SELECT * FROM quote_mail_packages WHERE token=? AND user_id=? AND account_id=?');$st->execute([$token,$account['user_id'],$account['id']]);$p=$st->fetch(PDO::FETCH_ASSOC);
    if(!$p)throw new RuntimeException('报价邮件不存在或不属于当前发件账号。');return $p;
}
function qmail_recipients(array $input,string $kind,string $email): array {
    // Test mode never carries customer selection into the envelope.
    if($kind==='test')return [[$email],[]];
    $parse=function($value){
        if(!is_array($value)||count($value)>200)throw new RuntimeException('联系人名单格式不正确或超过200个邮箱。');
        $out=[];foreach($value as $v){if(!is_string($v))throw new RuntimeException('联系人邮箱格式不正确。');$v=strtolower(trim($v));if(!filter_var($v,FILTER_VALIDATE_EMAIL))throw new RuntimeException('联系人邮箱无效，请重新选择。');$out[$v]=$v;}return array_values($out);
    };
    $to=$parse($input['recipients']??($email!==''?[$email]:[]));$cc=$parse($input['cc_recipients']??[]);
    $cc=array_values(array_diff($cc,$to));
    if(array_key_exists('recipients',$input)&&!$to)throw new RuntimeException('请至少选择一位收件人，不能只有抄送。');
    if(count($to)+count($cc)>200)throw new RuntimeException('一次最多选择200个邮箱。');
    return [$to,$cc];
}
function qmail_create(array $input, array $account): array {
    $pdo=db();qmail_schema($pdo);crm_ensure_tables();$id=(int)($input['id']??0);$token=(string)($input['token']??'');
    if(!preg_match('/^[a-f0-9]{48}$/D',$token))throw new RuntimeException('请求标识无效，请重新打开发送窗口。');
    $formats=array_values(array_intersect(['pdf','excel'],(array)($input['formats']??[])));if(!$formats)throw new RuntimeException('至少选择一种报价附件。');
    [$kind,$email,$bodyText]=qmail_options($input);
    [$to,$cc]=qmail_recipients($input,$kind,$email);$email=implode(', ',$to);$ccEmail=implode(', ',$cc);
    $requestData=[$id,$formats,$email,$kind,$bodyText];if($cc)$requestData[]=$cc;
    $request=hash('sha256',json_encode($requestData));
    $lock=$pdo->prepare('SELECT GET_LOCK(?,0)');$lock->execute(['quote_mail_render']);if(!(int)$lock->fetchColumn())throw new RuntimeException('附件生成器正在处理其他请求，请稍后重试。');
    $dir='';$queued=[];
    try {
        $st=$pdo->prepare('SELECT token FROM quote_mail_packages WHERE token=?');$st->execute([$token]);if($st->fetchColumn()){$prior=qmail_package($pdo,$token,$account);if(!hash_equals($prior['request_hash'],$request))throw new RuntimeException('同一请求的内容已改变，请重新打开窗口。');return $prior;}
        $s=qmail_snapshot($pdo,$id);$snap=$s['snapshot'];$contacts=qmail_contacts($snap);$chosen=null;
        $selectedIds=[];
        if($kind==='formal'){
            $allowed=array_column($contacts['contacts'],null,'email');
            foreach(array_merge($to,$cc) as $address){if(!isset($allowed[$address]))throw new RuntimeException('所选联系人邮箱已变化、不可联系或不属于本客户，请重新选择。');if($allowed[$address]['id'])$selectedIds[]=$allowed[$address]['id'];}
            // A multi-contact draft must not pretend to be linked to just one person.
            if(count($to)===1&&!$cc)$chosen=$allowed[$to[0]]??null;
        }
        if($kind==='test')$chosen=null;
        $dir=sys_get_temp_dir().'/quote-mail-'.bin2hex(random_bytes(12));if(!mkdir($dir,0700))throw new RuntimeException('无法建立附件临时目录。');
        $files=qmail_files($snap,$formats,$dir);$queued=crm_mail_queue_attachment_files((int)$account['user_id'],'quote_'.$token,$files);
        foreach($queued as &$file)$file['sha256']=hash_file('sha256',$file['path']);unset($file);
        $p=['quote_id'=>$id,'snapshot_hash'=>$s['hash']];qmail_assert_current($pdo,$p);
        if($bodyText==='')$bodyText='Please find our quotation '.$snap['quote_no'].' attached for your review.';
        $body='<p>'.nl2br(htmlspecialchars($bodyText,ENT_QUOTES,'UTF-8')).'</p>';
        $signature=crm_mail_render_signature_variables((string)($account['signature_html']??''),$account);if($signature!=='')$body.='<div class="mail-compose-signature" data-mail-compose-signature="1" contenteditable="false"><div class="mail-compose-signature-body" contenteditable="true">'.$signature.'</div></div>';
        $pdo->beginTransaction();
        // Optimistic revision checked again under row lock before publishing the draft.
        $st=$pdo->prepare('SELECT id FROM quote_orders WHERE id=? FOR UPDATE');$st->execute([$id]);qmail_assert_current($pdo,$p);
        $draft=crm_mail_save_draft(['to_emails'=>$email,'cc_emails'=>$ccEmail,'subject'=>($kind==='test'?'[测试] ':'').'Quotation '.$snap['quote_no'],'body_html'=>$body,'attachments_json'=>json_encode($queued),'customer_id'=>$kind==='test'?0:$contacts['customer_id'],'contact_id'=>$chosen['id']??0,'draft_meta_json'=>json_encode(['auto_signature'=>true,'quote_mail_token'=>$token,'quote_no'=>$snap['quote_no'],'quote_revision'=>substr($s['hash'],0,12),'quote_files'=>array_column($queued,'name'),'quote_mail_kind'=>$kind,'quote_contact_ids'=>array_values(array_unique($selectedIds)),'quote_test_recipient'=>$kind==='test'?$email:''])]);
        $pdo->prepare('INSERT INTO quote_mail_packages(token,user_id,account_id,quote_id,quote_no,snapshot_hash,request_hash,draft_id,customer_id,files_json,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,NOW())')->execute([$token,$account['user_id'],$account['id'],$id,$snap['quote_no'],$s['hash'],$request,$draft['draft_id'],$contacts['customer_id']?:null,json_encode($queued,JSON_THROW_ON_ERROR)]);
        $pdo->prepare('INSERT INTO quote_mail_context(token,mail_kind,test_recipient) VALUES(?,?,?)')->execute([$token,$kind,$kind==='test'?$email:'']);
        $pdo->commit();$queued=[];return qmail_package($pdo,$token,$account);
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();crm_mail_cleanup_queue_files($queued);throw $e;}
    finally {if($dir!==''&&is_dir($dir))qmail_remove_temp($dir);$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute(['quote_mail_render']);}
}
function qmail_send_guard(array $account, array $input, array $files): array {
    $token=(string)($input['quote_mail_token']??'');
    if($token===''&&!empty($input['draft_id'])){
        // Older installations without the additive table keep ordinary mail behavior.
        $st=db()->query("SHOW TABLES LIKE 'quote_mail_packages'");if(!$st->fetchColumn())return $input;
        $st=db()->prepare('SELECT token FROM quote_mail_packages WHERE draft_id=? AND user_id=?');$st->execute([(int)$input['draft_id'],$account['user_id']]);$token=(string)($st->fetchColumn()?:'');
    }
    if($token==='')return $input;
    qmail_schema(db()); // Must run before the caller's queue transaction, never inside claim().
    $p=qmail_package(db(),$token,$account);if($p['status']==='sent')throw new RuntimeException('这封报价邮件已发送，请勿重复发送；重新发送须从报价新建邮件。');
    $context=qmail_context(db(),$token);
    if($context['mail_kind']==='test'){
        if(strtolower(trim((string)($input['to_emails']??'')))!==$context['test_recipient']||trim((string)($input['cc_emails']??''))!==''||trim((string)($input['bcc_emails']??''))!=='')throw new RuntimeException('测试邮件只能发送到创建时指定的测试邮箱，不能追加客户、抄送或密送；更换测试邮箱请重新生成。');
        if(strpos((string)($input['subject']??''),'[测试]')!==0)throw new RuntimeException('请保留测试邮件主题开头的[测试]标识。');
    }
    qmail_assert_current(db(),$p);$expected=json_decode($p['files_json'],true,64,JSON_THROW_ON_ERROR);
    foreach($expected as $file){$found=false;foreach($files as $actual){$path=$actual['path']??$actual['tmp_name']??'';if(($actual['name']??'')===$file['name']&&is_file($path)&&hash_equals($file['sha256'],hash_file('sha256',$path)))$found=true;}if(!$found)throw new RuntimeException('已审核报价附件缺失或被替换：'.$file['name'].'。请重新生成报价邮件。');}
    if(array_sum(array_column($files,'size'))>20*1024*1024)throw new RuntimeException('邮件附件合计超过20MB，请减少其他附件。');
    $input['quote_mail_token']=$token;$input['customer_id']=$context['mail_kind']==='test'?0:(int)$p['customer_id'];if($context['mail_kind']==='test')$input['contact_id']=0;return $input;
}
function qmail_sent(array $account, array $input, int $mailId): void {
    $token=(string)($input['quote_mail_token']??'');if($token==='')return;
    $p=qmail_package(db(),$token,$account);
    db()->prepare("UPDATE quote_mail_packages SET status='sent',sent_mail_id=?,sent_to=?,sent_at=NOW() WHERE token=? AND status<>'sent'")->execute([$mailId,(string)($input['to_emails']??''),$token]);
    db()->prepare('UPDATE quote_mail_attempts SET sent_at=COALESCE(sent_at,NOW()),sent_mail_id=? WHERE job_id=? AND token=?')->execute([$mailId,$p['job_id'],$token]);
    $context=qmail_context(db(),$token);
    $files=json_decode($p['files_json'],true)?:[];$detail=$p['quote_no'].' · 审核版本 '.substr($p['snapshot_hash'],0,12).' · '.implode(', ',array_column($files,'name')).' · 收件人 '.($input['to_emails']??'');
    crm_log_event('quote','quote_mail_sent','quote',(string)$p['quote_id'],null,['mail_id'=>$mailId,'mail_kind'=>$context['mail_kind'],'detail'=>$detail]);
    if($p['customer_id']&&$context['mail_kind']!=='test')crm_customer_timeline_add((int)$p['customer_id'],'quote_mail_sent','发送已审核报价',$detail,'mail',(string)$mailId);
}
function qmail_claim(array $account,array $input,string $job): void {
    if(empty($input['quote_mail_token']))return;
    $st=db()->prepare("UPDATE quote_mail_packages SET job_id=? WHERE token=? AND user_id=? AND account_id=? AND status='draft' AND (job_id IS NULL OR job_id=?)");
    $st->execute([$job,$input['quote_mail_token'],$account['user_id'],$account['id'],$job]);
    $p=qmail_package(db(),$input['quote_mail_token'],$account);
    if($p['job_id']!==$job||$p['status']!=='draft')throw new RuntimeException('此报价草稿已加入发送队列或已发送，请到待发送查看，勿重复发送。');
    $sql='INSERT INTO quote_mail_attempts(job_id,token,sender_email,sender_name,to_emails,cc_emails,bcc_emails,subject,queued_at) VALUES(?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE job_id=job_id';
    db()->prepare($sql)->execute([$job,$p['token'],$account['email_address']??'',$account['sender_name']??'',(string)($input['to_emails']??''),(string)($input['cc_emails']??''),(string)($input['bcc_emails']??''),(string)($input['subject']??'')]);
}
function qmail_cancel_to_draft(array $account,array $input,string $job,int $draftId): void {
    // Called inside the existing cancellation transaction, after scheduled -> cancelled wins.
    $p=qmail_package(db(),$input['quote_mail_token'],$account);
    if($p['job_id']!==$job||$p['status']!=='draft')throw new RuntimeException('报价邮件状态已改变，请刷新后再操作。');
    $meta=['auto_signature'=>true,'quote_mail_token'=>$p['token'],'quote_no'=>$p['quote_no'],'quote_revision'=>substr($p['snapshot_hash'],0,12),'quote_files'=>array_column(json_decode($p['files_json'],true)?:[],'name')];
    $context=qmail_context(db(),$p['token']);$meta['quote_mail_kind']=$context['mail_kind'];$meta['quote_test_recipient']=$context['test_recipient'];
    db()->prepare('UPDATE crm_mail_drafts SET draft_meta_json=? WHERE id=? AND user_id=? AND mail_account_id=?')->execute([json_encode($meta),$draftId,$account['user_id'],$account['id']]);
    db()->prepare('UPDATE quote_mail_packages SET job_id=NULL,draft_id=? WHERE token=? AND job_id=?')->execute([$draftId,$p['token'],$job]);
}
