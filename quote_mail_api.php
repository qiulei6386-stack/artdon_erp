<?php
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/artdon_sso_core.php';
require_once __DIR__.'/crm_config.php';
require_once __DIR__.'/crm_auth.php';
require_once __DIR__.'/crm_log.php';
require_once __DIR__.'/crm_customer.php';
require_once __DIR__.'/crm_mail.php';
require_once __DIR__.'/includes/quote_mail.php';
require_login();
header('Cache-Control: no-store');header('Content-Type: application/json; charset=UTF-8');
try {
    if(!artdon_sso_can('quote','export')||!crm_can('mail.send')||!crm_can('mail.view'))throw new RuntimeException('需要报价导出及CRM邮件读写权限。');
    $action=(string)($_GET['action']??'info');
    $input=json_decode(file_get_contents('php://input'),true)?:[];
    $account=crm_mail_current_account(false);if(!$account)throw new RuntimeException('请先在CRM邮箱绑定并选择发件账号。');
    qmail_schema(db());
    if($action==='info'){
        $id=(int)($_GET['id']??0);$s=qmail_snapshot(db(),$id);$data=qmail_contacts($s['snapshot']);
        $data+=['quote_no'=>$s['snapshot']['quote_no'],'revision'=>substr($s['hash'],0,12),'sender'=>$account['email_address'],'account_id'=>(int)$account['id']];
        $data['history']=qmail_history(db(),$id,(int)$account['user_id']);
        $data['test_recipient']=$account['email_address'];
    } elseif($action==='history'){
        $id=(int)($_GET['id']??0);$data=qmail_history(db(),$id,(int)$account['user_id'],(int)($_GET['offset']??0));
    } elseif($action==='create'){
        if($_SERVER['REQUEST_METHOD']!=='POST'||!verify_csrf())throw new RuntimeException('安全校验失败，请刷新报价页面。');
        if((int)($input['account_id']??0)!==(int)$account['id'])throw new RuntimeException('当前发件账号已改变，请重新打开发送窗口。');
        // Keep the session values for permission checks, but do not block other tabs during rendering.
        if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
        $p=qmail_create($input,$account);$data=['token'=>$p['token'],'draft_id'=>(int)$p['draft_id'],'url'=>'crm.php?quote_mail='.rawurlencode($p['token']).'#mail'];
    } elseif($action==='preview'){
        if($_SERVER['REQUEST_METHOD']!=='POST'||!verify_csrf())throw new RuntimeException('安全校验失败，请刷新后重试。');
        $p=qmail_package(db(),(string)($input['token']??''),$account);qmail_assert_current(db(),$p);
        $draft=crm_mail_draft_get((int)$p['draft_id'])['draft'];
        $fields=array_flip(['subject','to_emails','cc_emails','bcc_emails','body_html','attachments_json']);
        $current=array_intersect_key($draft,$fields);
        if(isset($input['current'])&&is_array($input['current']))$current=array_replace($current,array_intersect_key($input['current'],$fields));
        foreach($current as $value)if(!is_string($value)&&$value!==null)throw new RuntimeException('预览内容格式无效。');
        if(strlen((string)($current['body_html']??''))>8*1024*1024)throw new RuntimeException('正文图片超过预览上限，请先压缩后重新预览。');
        $files=crm_mail_draft_attachment_files($account,$current);
        qmail_send_guard($account,$current+['draft_id'=>$p['draft_id'],'quote_mail_token'=>$p['token']],$files);
        $context=qmail_context(db(),$p['token']);$manifest=json_decode($p['files_json'],true)?:[];
        $data=array_intersect_key($current,array_flip(['subject','to_emails','cc_emails','bcc_emails']));
        $data+=['body_html'=>crm_mail_render_signature_variables((string)($current['body_html']??''),$account),'sender'=>$account['email_address'],'mail_kind'=>$context['mail_kind'],'revision'=>substr($p['snapshot_hash'],0,12),'files'=>[]];
        foreach($files as $file){$item=['name'=>$file['name'],'size'=>$file['size']??0];foreach($manifest as $index=>$original)if($original['name']===$file['name']&&hash_equals($original['sha256'],hash_file('sha256',$file['tmp_name']??$file['path'])))$item['url']='quote_mail_api.php?action=file&token='.rawurlencode($p['token']).'&index='.$index;$data['files'][]=$item;}
    } elseif($action==='file'){
        $p=qmail_package(db(),(string)($_GET['token']??''),$account);qmail_assert_current(db(),$p);
        $files=json_decode($p['files_json'],true,64,JSON_THROW_ON_ERROR);$index=filter_var($_GET['index']??null,FILTER_VALIDATE_INT);
        if($index===false||$index===null||!isset($files[$index]))throw new RuntimeException('附件不存在。');
        $file=$files[$index];$valid=crm_mail_draft_attachment_files($account,['attachments_json'=>json_encode([$file])]);
        $path=$valid[0]['path']??$valid[0]['tmp_name']??'';
        if(!$path||!is_file($path)||!hash_equals($file['sha256'],hash_file('sha256',$path)))throw new RuntimeException('附件已失效，请重新生成报价邮件。');
        header('Content-Type: '.$file['type']);header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: '.($file['type']==='application/pdf'?'inline':'attachment').'; filename="quotation.'.($file['type']==='application/pdf'?'pdf':'xlsx').'"; filename*=UTF-8\'\''.rawurlencode($file['name']));
        header('Content-Length: '.filesize($path));if(session_status()===PHP_SESSION_ACTIVE)session_write_close();readfile($path);exit;
    } elseif($action==='get'){
        $p=qmail_package(db(),(string)($_GET['token']??''),$account);qmail_assert_current(db(),$p);
        if($p['status']!=='draft')throw new RuntimeException('该报价邮件已发送，请在报价中查看发送记录。');
        $data=crm_mail_draft_get((int)$p['draft_id']);$data['quote_no']=$p['quote_no'];$data['revision']=substr($p['snapshot_hash'],0,12);
        $context=qmail_context(db(),$p['token']);$meta=json_decode((string)($data['draft']['draft_meta_json']??'{}'),true)?:[];
        $meta['quote_mail_kind']=$context['mail_kind'];$meta['quote_test_recipient']=$context['test_recipient'];$data['draft']['draft_meta_json']=json_encode($meta,JSON_UNESCAPED_UNICODE);
    } else throw new RuntimeException('未知操作。');
    echo json_encode(['success'=>true,'data'=>$data],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
} catch(Throwable $e){http_response_code(400);echo json_encode(['success'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
