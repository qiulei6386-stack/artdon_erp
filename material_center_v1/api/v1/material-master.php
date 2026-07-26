<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/bootstrap.php';
use Artdon\MaterialCenter\Adapters\LegacyAuthAdapter;use Artdon\MaterialCenter\Security\PermissionService;use Artdon\MaterialCenter\Services\MaterialMasterService;
header('Content-Type:application/json;charset=utf-8');header('Cache-Control:no-store');
try{$user=(new LegacyAuthAdapter())->current();$p=new PermissionService();if($_SERVER['REQUEST_METHOD']!=='POST'||!function_exists('verify_csrf')||!verify_csrf((string)($_POST['csrf_token']??'')))throw new RuntimeException('安全令牌已过期。',419);$action=(string)($_POST['action']??'save');$s=new MaterialMasterService();
 if($action==='save'){$p->require($user,(int)($_POST['id']??0)?'material_center.material.edit':'material_center.material.create');$data=['id'=>$s->save($_POST,$user->id)];$message='草稿物料已保存';}
 elseif($action==='copy'){$p->require($user,'material_center.material.create');$data=['id'=>$s->copy((int)$_POST['material_id'],$user->id)];$message='已复制为新草稿';}
 elseif($action==='delete_draft'){$p->require($user,'material_center.material.lifecycle');$s->deleteDraft((int)$_POST['material_id'],$user->id);$data=[];$message='草稿已删除';}
 elseif($action==='references'){$p->require($user,'material_center.view');$data=$s->references((int)$_POST['material_id']);$message='引用检查完成';}
 else{$p->require($user,$action==='approve'?'material_center.approve':'material_center.material.lifecycle');$s->transition((int)$_POST['material_id'],$action,$user->id,(string)($_POST['reason']??''));$data=[];$message='物料状态已更新';}
 echo json_encode(['ok'=>true,'message'=>$message,'data'=>$data],JSON_UNESCAPED_UNICODE);
}catch(Throwable$e){http_response_code($e->getCode()>=400&&$e->getCode()<600?$e->getCode():422);echo json_encode(['ok'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
