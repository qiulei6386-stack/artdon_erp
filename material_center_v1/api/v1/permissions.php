<?php
declare(strict_types=1);require_once dirname(__DIR__,2).'/bootstrap.php';
use Artdon\MaterialCenter\Adapters\LegacyAuthAdapter;use Artdon\MaterialCenter\Security\PermissionService;use Artdon\MaterialCenter\Services\PermissionAdminService;
header('Content-Type:application/json;charset=utf-8');
try{$user=(new LegacyAuthAdapter())->current();(new PermissionService())->require($user,'material_center.permissions.manage');if($_SERVER['REQUEST_METHOD']!=='POST'||!verify_csrf((string)($_POST['csrf_token']??'')))throw new RuntimeException('安全令牌已过期。',419);(new PermissionAdminService())->save($_POST,$user->id);echo json_encode(['ok'=>true],JSON_UNESCAPED_UNICODE);}
catch(Throwable$e){http_response_code($e->getCode()>=400&&$e->getCode()<600?$e->getCode():422);echo json_encode(['ok'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
