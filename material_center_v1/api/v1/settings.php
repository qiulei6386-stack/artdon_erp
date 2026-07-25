<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/bootstrap.php';
use Artdon\MaterialCenter\Adapters\LegacyAuthAdapter;
use Artdon\MaterialCenter\Security\PermissionService;
use Artdon\MaterialCenter\Services\SettingsService;
header('Content-Type:application/json;charset=utf-8');header('Cache-Control:no-store');
try {
    $context=(new LegacyAuthAdapter())->current();$permissions=new PermissionService();$service=new SettingsService();
    $permissions->require($context,'material_center.settings.view');
    if($_SERVER['REQUEST_METHOD']==='GET'){echo json_encode(['ok'=>true,'data'=>$service->resolved($context)]);exit;}
    if(function_exists('verify_csrf')&&!verify_csrf((string)($_POST['csrf_token']??'')))throw new RuntimeException('安全令牌已过期，请刷新页面。',419);
    $action=(string)($_POST['action']??'save');$scopeType='user';$scopeId=(string)$context->id;
    $permissions->require($context,'material_center.settings.manage_self');
    if($action==='reset'){$service->reset($context,$scopeType,$scopeId);$data=$service->resolved($context);}
    else{$values=json_decode((string)($_POST['values']??'{}'),true);if(!is_array($values))throw new RuntimeException('设置数据无效。');$data=$service->save($context,$scopeType,$scopeId,$values);}
    echo json_encode(['ok'=>true,'message'=>$action==='reset'?'个人设置已恢复':'设置已保存','data'=>$data],JSON_UNESCAPED_UNICODE);
} catch(Throwable $e){http_response_code($e->getCode()>=400&&$e->getCode()<600?$e->getCode():422);echo json_encode(['ok'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
