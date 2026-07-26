<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap.php';
use Artdon\MaterialCenter\Security\MaterialCenterUserContext;
use Artdon\MaterialCenter\Security\PermissionService;
$db=db();$tag='role_final_'.bin2hex(random_bytes(4));$testUser=900000000+random_int(1,999999);$roles=[
 'material'=>['material_center.view','material_center.material.edit','material_center.material.batch'],
 'engineering'=>['material_center.view','material_center.adaptation.manage'],
 'purchasing'=>['material_center.view','material_center.supplier.manage','material_center.purchase_price.view'],
 'business_readonly'=>['material_center.view'],
];
try{
 foreach($roles as$role=>$permissions)foreach($permissions as$permission)$db->prepare("INSERT INTO mc_permission_grants(subject_type,subject_id,permission_key,effect,created_at,updated_at)VALUES('role',?,?,'allow',NOW(),NOW())")->execute([$tag.'_'.$role,$permission]);
 $service=new PermissionService($db);$admin=new MaterialCenterUserContext(1,'admin','Admin','admin',true);if(!$service->allows($admin,'material_center.permissions.manage'))throw new RuntimeException('administrator permission failed');
 foreach($roles as$role=>$permissions){$context=new MaterialCenterUserContext($testUser,'test','Test',$tag.'_'.$role,false);foreach($permissions as$permission)if(!$service->allows($context,$permission))throw new RuntimeException("$role missing $permission");if($role==='business_readonly'&&$service->allows($context,'material_center.material.edit'))throw new RuntimeException('business readonly can edit');if($role==='engineering'&&$service->allows($context,'material_center.purchase_price.view'))throw new RuntimeException('engineering can view price');if($role==='purchasing'&&$service->allows($context,'material_center.permissions.manage'))throw new RuntimeException('purchasing can manage permissions');}
 $none=new MaterialCenterUserContext($testUser+1,'none','None',$tag.'_none',false);if($service->allows($none,'material_center.view'))throw new RuntimeException('no-access role can view');
 $db->prepare("INSERT INTO mc_permission_grants(subject_type,subject_id,permission_key,effect,created_at,updated_at)VALUES('user',?,'material_center.view','deny',NOW(),NOW())")->execute([(string)$testUser]);$denied=new MaterialCenterUserContext($testUser,'test','Test',$tag.'_material',false);if($service->allows($denied,'material_center.view'))throw new RuntimeException('explicit deny did not override role allow');
 echo "administrator/material/engineering/purchasing/readonly/no-access roles: OK\n";
}finally{$stmt=$db->prepare("DELETE FROM mc_permission_grants WHERE subject_id LIKE ? OR (subject_type='user' AND subject_id=? AND permission_key='material_center.view' AND effect='deny')");$stmt->execute([$tag.'%',(string)$testUser]);}
