<?php
declare(strict_types=1);require_once dirname(__DIR__).'/bootstrap.php';
$p=new Artdon\MaterialCenter\Security\PermissionService();$admin=new Artdon\MaterialCenter\Security\MaterialCenterUserContext(999999999,'test','test','test',true);
if(!$p->allows($admin,'material_center.view')||$p->fieldAccess($admin,'power_supply','purchase_price')!=='edit'){fwrite(STDERR,"admin permission failed\n");exit(1);}
$protected=$p->protectFields(null,'power_supply',['raw_price'=>'100'],['raw_price']);if(isset($protected['raw_price'])){fwrite(STDERR,"anonymous sensitive protection failed\n");exit(1);}
echo "Power workbench permission test passed.\n";
