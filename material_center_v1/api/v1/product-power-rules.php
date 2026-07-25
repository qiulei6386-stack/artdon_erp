<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/bootstrap.php';
use Artdon\MaterialCenter\Adapters\LegacyAuthAdapter;use Artdon\MaterialCenter\Security\PermissionService;use Artdon\MaterialCenter\Services\ProductPowerRuleService;
header('Content-Type:application/json;charset=utf-8');header('Cache-Control:no-store');
try{$user=(new LegacyAuthAdapter())->current();$permissions=new PermissionService();$service=new ProductPowerRuleService();$action=(string)($_POST['action']??'list');
if($_SERVER['REQUEST_METHOD']==='GET'){$permissions->require($user,'material_center.power.rules.view');echo json_encode(['ok'=>true,'data'=>$service->rules()],JSON_UNESCAPED_UNICODE);exit;}
if(function_exists('verify_csrf')&&!verify_csrf((string)($_POST['csrf_token']??'')))throw new RuntimeException('安全令牌已过期。',419);
if($action==='simulate'){$permissions->require($user,'material_center.power.simulate');$data=$service->simulate((int)$_POST['rule_id'],$user->id);$message='模拟完成';}
else{$permissions->require($user,'material_center.power.rules.manage');$data=['id'=>$service->save($_POST,$user->id)];$message='规则已保存';}
echo json_encode(['ok'=>true,'message'=>$message,'data'=>$data],JSON_UNESCAPED_UNICODE);
}catch(Throwable$e){http_response_code($e->getCode()>=400&&$e->getCode()<600?$e->getCode():422);echo json_encode(['ok'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
