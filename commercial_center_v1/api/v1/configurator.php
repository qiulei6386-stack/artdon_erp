<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/bootstrap.php';
use Artdon\CommercialCenter\Adapters\LegacyAuthAdapter;
use Artdon\CommercialCenter\Repositories\ConfigurationRepository;
use Artdon\CommercialCenter\Services\ConfigurationEngineService;
use Artdon\CommercialCenter\Support\Logger;
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
$reply=static function(array $data,int $code=200):never{http_response_code($code);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;};
$auth=(new LegacyAuthAdapter())->currentUser();if(!$auth['authenticated']||!$auth['user'])$reply(['ok'=>false,'status'=>'unauthenticated','message'=>'需要统一登录。'],401);
$userId=(int)$auth['user']['id'];if(!isset($_SESSION['cc_csrf']))$_SESSION['cc_csrf']=bin2hex(random_bytes(24));
$engine=new ConfigurationEngineService();$repo=new ConfigurationRepository();
try{
 if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'){$customerId=isset($_GET['customer_id'])?(int)$_GET['customer_id']:null;$reply(['ok'=>true,'csrf'=>$_SESSION['cc_csrf'],'catalog'=>$engine->catalog($userId,$customerId)]);}
 $input=json_decode((string)file_get_contents('php://input'),true);if(!is_array($input))$reply(['ok'=>false,'message'=>'JSON请求无效。'],400);
 if(!hash_equals((string)$_SESSION['cc_csrf'],(string)($input['csrf']??'')))$reply(['ok'=>false,'message'=>'请求校验失败。'],419);
 $action=(string)($input['action']??'evaluate');
 if($action==='evaluate')$reply(['ok'=>true,'configuration'=>$engine->evaluate($input,$userId)]);
 if($action==='save_preset'){$vm=$engine->evaluate($input,$userId);if($vm['status']==='blocked')$reply(['ok'=>false,'message'=>'存在禁止配置，不能保存预设。','configuration'=>$vm],422);$id=$repo->savePreset(['scope'=>$input['scope']??'personal','name'=>$input['name']??'我的配置预设','legacy_product_id'=>$vm['product']['legacy_product_id'],'values'=>$vm['values']],$userId,isset($input['customer_id'])?(int)$input['customer_id']:null);$reply(['ok'=>true,'status'=>'saved','preset_id'=>$id,'message'=>'预设已保存。']);}
 if($action==='add_to_quote'){$vm=$engine->evaluate($input,$userId);if($vm['status']==='blocked')$reply(['ok'=>false,'message'=>'存在禁止配置，不能加入报价。','configuration'=>$vm],422);$saved=$repo->saveConfiguration($vm,$userId);$quote=$repo->addToQuote($saved,max(.001,(float)($input['quantity']??1)),$userId);$reply(['ok'=>true,'status'=>'saved','configuration'=>$vm,'saved'=>$saved,'quote'=>$quote,'message'=>'配置快照已加入报价草稿。']);}
 $reply(['ok'=>false,'message'=>'不支持的操作。'],400);
}catch(Throwable $e){Logger::error('Configurator API failed',['type'=>get_class($e),'message'=>$e->getMessage()]);$reply(['ok'=>false,'status'=>'error','message'=>'配置服务暂时不可用，详细错误已记录。'],500);}
