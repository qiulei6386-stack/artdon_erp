<?php
declare(strict_types=1);
$root=dirname(__DIR__);
require $root.'/includes/bom_dashboard_read.php';
function bom_v777_normalize_media_url($raw){return $raw;}
function bd_check($ok,$message){if(!$ok)throw new RuntimeException($message);}
bd_check(bom_dashboard_keyword('  中文 型号  ')==='中文 型号','Trim Unicode search');
foreach(array(array(),str_repeat('x',481)) as $bad){try{bom_dashboard_keyword($bad);throw new LogicException('Invalid keyword accepted');}catch(InvalidArgumentException $e){}}
bd_check(bom_dashboard_image_url('javascript:alert(1)')==='', 'Reject unsafe protocol');
bd_check(bom_dashboard_image_url('data:image/png;base64,'.str_repeat('A',3000))==='', 'Never transfer inline payload');
bd_check(bom_dashboard_image_url('/uploads/product.png')==='/uploads/product.png','Relative image preserved');
$helper=file_get_contents($root.'/includes/bom_dashboard_read.php');$api=file_get_contents($root.'/bom_api.php');
bd_check(strpos($helper,'MAX_EXECUTION_TIME(2000)')!==false,'Bounded search time');
bd_check(strpos($helper,'SELECT *')===false,'No full project/naming selects');
bd_check(strpos($helper,"count(\$ids) > 18")!==false,'Visible page bounded');
bd_check(strpos($helper,'OCTET_LENGTH(product_image)<=2048')!==false,'Image response bounded before transfer');
bd_check(strpos($api,"'dashboard_search'=>'view_dashboard'")!==false && strpos($api,"'dashboard_images'=>'view_dashboard'")!==false,'Both endpoints permission mapped');
bd_check(strpos($api,"if(!in_array(\$action,array('dashboard_search','dashboard_images'),true))")!==false,'Enrichment skips schema migrations');
bd_check(strpos($helper,'UPDATE ')===false && strpos($helper,'INSERT ')===false,'Read-only helper');
echo "BOM dashboard validation, permission and query-budget contracts passed.\n";
