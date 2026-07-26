<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/bootstrap.php';
use Artdon\CommercialCenter\Adapters\LegacyAuthAdapter;use Artdon\CommercialCenter\Services\ApprovalCenterService;use Artdon\CommercialCenter\Services\LegacyOrderConversionService;
$reply=static function(array $x,int $s=200):never{http_response_code($s);header('Content-Type: application/json; charset=utf-8');echo json_encode($x,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;};
$a=(new LegacyAuthAdapter())->currentUser();if(!$a['authenticated']||!is_array($a['user']))$reply(['ok'=>false,'message'=>'需要统一登录。'],401);$actor=$a['user'];
if(empty($_SESSION['cc_approval_csrf']))$_SESSION['cc_approval_csrf']=bin2hex(random_bytes(32));$service=new ApprovalCenterService();
try{if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'){$id=(int)($_GET['quote_id']??0);$reply(['ok'=>true,'csrf'=>$_SESSION['cc_approval_csrf'],'rows'=>$id?[]:$service->queue($_GET,$actor),'quote'=>$id?$service->detail($id,$actor):null]);}
$d=json_decode((string)file_get_contents('php://input'),true);if(!is_array($d))$reply(['ok'=>false,'message'=>'JSON无效'],400);if(!hash_equals($_SESSION['cc_approval_csrf'],(string)($d['csrf']??'')))$reply(['ok'=>false,'message'=>'请求校验失败'],419);
if(($d['action']??'')==='review')$reply(['ok'=>true,'quote'=>$service->act((int)$d['quote_id'],(string)$d['decision'],(string)($d['opinion']??''),(string)($d['target']??''),$actor)]);
if(($d['action']??'')==='convert')$reply(['ok'=>true,'order'=>(new LegacyOrderConversionService())->convert((int)$d['quote_id'],$actor)]);
$reply(['ok'=>false,'message'=>'不支持的操作'],400);
}catch(InvalidArgumentException $e){$reply(['ok'=>false,'message'=>$e->getMessage()],422);}catch(RuntimeException $e){$reply(['ok'=>false,'message'=>$e->getMessage()],409);}catch(Throwable $e){$reply(['ok'=>false,'message'=>'审核服务异常：'.$e->getMessage()],500);}
