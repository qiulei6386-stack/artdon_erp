<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/bootstrap.php';
use Artdon\MaterialCenter\Adapters\LegacyAuthAdapter;use Artdon\MaterialCenter\Security\PermissionService;use Artdon\MaterialCenter\Services\FieldRegistryService;use Artdon\MaterialCenter\Services\MaterialBatchService;use Artdon\MaterialCenter\Services\MaterialLifecycleService;
header('Content-Type:application/json;charset=utf-8');header('Cache-Control:no-store');
try{$user=(new LegacyAuthAdapter())->current();$permission=new PermissionService();$action=(string)($_GET['action']??$_POST['action']??'fields');
 if($_SERVER['REQUEST_METHOD']==='GET'&&$action==='fields'){$permission->require($user,'material_center.material.batch');echo json_encode(['ok'=>true,'data'=>(new FieldRegistryService())->editable((string)($_GET['category']??'power_supply'),$user)],JSON_UNESCAPED_UNICODE);exit;}
 if($_SERVER['REQUEST_METHOD']!=='POST'||!function_exists('verify_csrf')||!verify_csrf((string)($_POST['csrf_token']??'')))throw new RuntimeException('安全令牌已过期。',419);
 if($action==='lifecycle'){$permission->requireMaterialTransition($user,(string)$_POST['transition']);(new MaterialLifecycleService())->transition((int)$_POST['material_id'],(string)$_POST['transition'],$user->id,(string)($_POST['reason']??''));$data=[];$message='物料状态已更新';}
 else{$permission->require($user,'material_center.material.batch');$ids=json_decode((string)($_POST['ids']??'[]'),true);$changes=json_decode((string)($_POST['changes']??'{}'),true);$service=new MaterialBatchService();$data=$action==='batch_execute'?$service->execute($ids,$changes,(string)$_POST['policy'],$user):$service->preview($ids,$changes,(string)$_POST['policy'],$user);$message=$action==='batch_execute'?'批量设置完成':'预览已生成';}
 echo json_encode(['ok'=>true,'message'=>$message,'data'=>$data],JSON_UNESCAPED_UNICODE);
}catch(Throwable$e){http_response_code($e->getCode()>=400&&$e->getCode()<600?$e->getCode():422);echo json_encode(['ok'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
