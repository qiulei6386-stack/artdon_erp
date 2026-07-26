<?php
declare(strict_types=1);

require_once dirname(__DIR__,2).'/bootstrap.php';

use Artdon\CommercialCenter\Adapters\LegacyAuthAdapter;
use Artdon\CommercialCenter\Services\QuoteOutputService;
use Artdon\CommercialCenter\Support\Logger;

$auth=(new LegacyAuthAdapter())->currentUser();
if(!$auth['authenticated']||!is_array($auth['user'])){
    http_response_code(401);header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'status'=>'unauthenticated','message'=>'需要统一登录。'],JSON_UNESCAPED_UNICODE);exit;
}
$actor=$auth['user'];$service=new QuoteOutputService();
if(empty($_SESSION['cc_quote_output_csrf']))$_SESSION['cc_quote_output_csrf']=bin2hex(random_bytes(32));
$reply=static function(array $data,int $status=200):never{http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;};
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'){
        $action=(string)($_GET['action']??'csrf');
        if($action==='csrf')$reply(['ok'=>true,'csrf'=>$_SESSION['cc_quote_output_csrf']]);
        $snapshotId=max(0,(int)($_GET['snapshot_id']??0));
        if($action==='preview'||$action==='print'){
            header('Content-Type: text/html; charset=utf-8');header('Cache-Control: private, no-store');
            echo $service->html($snapshotId,$actor,$action==='print');exit;
        }
        if($action==='pdf'||$action==='excel'){
            $file=$service->artifact($snapshotId,$action,$actor);
            $path=dirname(__DIR__,2).'/'.$file['storage_path'];
            if(!is_file($path))throw new RuntimeException('输出文件不存在。');
            header('Content-Type: '.$file['mime_type']);header('Content-Length: '.filesize($path));
            header('Content-Disposition: attachment; filename*=UTF-8\'\''.rawurlencode((string)$file['file_name']));
            readfile($path);exit;
        }
        $reply(['ok'=>false,'message'=>'不支持的输出操作。'],400);
    }
    $input=json_decode((string)file_get_contents('php://input'),true);
    if(!is_array($input))$reply(['ok'=>false,'message'=>'JSON 请求无效。'],400);
    if(!hash_equals((string)$_SESSION['cc_quote_output_csrf'],(string)($input['csrf']??'')))$reply(['ok'=>false,'message'=>'请求校验失败，请刷新页面。'],419);
    $action=(string)($input['action']??'');
    if($action==='snapshot'){
        $snapshot=$service->snapshotForQuote((int)($input['quote_id']??0),$actor);
        $reply(['ok'=>true,'snapshot'=>['id'=>(int)$snapshot['id'],'hash'=>$snapshot['snapshot_hash'],'watermark'=>$snapshot['watermark']]]);
    }
    if($action==='send'){
        $result=$service->send((int)($input['snapshot_id']??0),trim((string)($input['to']??'')),trim((string)($input['cc']??'')),
            trim((string)($input['subject']??'Quotation')),trim((string)($input['body']??'')),$actor);
        $reply(['ok'=>true,'delivery'=>$result,'message'=>'报价邮件已发送。']);
    }
    $reply(['ok'=>false,'message'=>'不支持的操作。'],400);
}catch(InvalidArgumentException $e){$reply(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(RuntimeException $e){$reply(['ok'=>false,'message'=>$e->getMessage()],str_contains($e->getMessage(),'权限')?403:409);
}catch(Throwable $e){Logger::error('Quote output API failed',['type'=>get_class($e),'message'=>$e->getMessage()]);$reply(['ok'=>false,'message'=>'报价输出服务暂时不可用，错误已记录。'],500);}
