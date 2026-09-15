<?php
if(PHP_SAPI!=='cli')exit(2);
require dirname(__DIR__).'/includes/quote_order_paging.php';
function op_check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$sql=op_source();op_check(strpos($sql,'item_json')===false&&strpos($sql,'snapshot_json')===false&&strpos($sql,'image')===false,'Read projection never loads LOBs');
op_check(strpos($sql,'qo_virtual_line_v1')!==false,'Uses maintained classification');
$args=[];$where=op_where(['search'=>"x%_! 王",'customer'=>'Test','status'=>'已收齐','from'=>'2026-09-01'],$args);
op_check(strpos($where,'Test')===false&&in_array('%x!%!_!!%',$args,true),'Bound and escaped search');
try{op_where(['to'=>'2026-02-30'],$args);throw new LogicException('Invalid date allowed');}catch(InvalidArgumentException $e){}
$api=file_get_contents(dirname(__DIR__).'/quote_order_api.php');$route=substr($api,strpos($api,"if(in_array(\$action,['list_page'"),450);
op_check(strpos($route,'qo_ensure_schema')===false,'Read route must not create/alter tables');
op_check(strpos($route,"artdon_perm_require_action('quote'")!==false,'Quote read permission retained');
echo "Order page contracts: bounded numeric projection, escaped search, date validation, read-only route and permissions passed.\n";
