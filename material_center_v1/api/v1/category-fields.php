<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/bootstrap.php';
use Artdon\MaterialCenter\Adapters\LegacyAuthAdapter;use Artdon\MaterialCenter\Security\PermissionService;use Artdon\MaterialCenter\Services\CategoryFieldService;
header('Content-Type:application/json;charset=utf-8');header('Cache-Control:no-store');
try{$user=(new LegacyAuthAdapter())->current();$permissions=new PermissionService();$permissions->require($user,'material_center.view');$category=preg_replace('/[^a-z_]/','',(string)($_GET['category']??''));$service=new CategoryFieldService();echo json_encode(['ok'=>true,'data'=>['fields'=>$service->definitions($category),'values'=>$service->values((int)($_GET['material_id']??0),$category),'can_approve'=>$permissions->allows($user,'material_center.approve')]],JSON_UNESCAPED_UNICODE);}
catch(Throwable$e){http_response_code($e->getCode()>=400&&$e->getCode()<600?$e->getCode():422);echo json_encode(['ok'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
