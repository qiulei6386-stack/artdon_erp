<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bom_cost_publication.php';
function cpCheck($ok,$message){if(!$ok)throw new RuntimeException($message);}
function cpPublish(array &$map,string $uid,float $cost,bool $initial=false,string $model='52.12345'): void {
    bcp_add_publication($map,array('project_uid'=>$uid,'snapshot_id'=>$uid,'source'=>'approved_snapshot','payload_json'=>json_encode(array('model'=>$model,'initial_freeze'=>$initial,'linked_system'=>'NAMING','linked_id'=>'12345')),'cost'=>$cost,'updated_at'=>'2026-01-01'));
}
$map=array();cpPublish($map,'old',99,true);cpPublish($map,'zero',0);
[$key,$hit]=bcp_find(array('52.12345'),$map);cpCheck($hit['cost_rmb']===0.0,'approved zero overrides initial legacy cost');
cpPublish($map,'second',12);cpCheck(bcp_find(array('52.12345'),$map)[1]['cost_rmb']===12.0,'same-tier maximum preserved');
cpPublish($map,'old-high',200,true);cpCheck(bcp_find(array('52.12345'),$map)[1]['cost_rmb']===12.0,'legacy cannot override approval');
cpCheck(bcp_find(array('52.1234','52.123456'),$map)[1]===null,'exact models only');
$keys=bcp_product_keys(array('source'=>'naming','naming_id'=>'12345','model'=>'52.12345'));
cpCheck(bcp_find($keys,$map)[1]['cost_rmb']===12.0,'naming and model share winner');
$zero=array();cpPublish($zero,'initial-zero',0,true);cpCheck(count($zero)===0,'initial freeze does not add previously absent zero matches');
cpPublish($zero,'approved-zero',0);
// Exercise the actual quote product mutation without bootstrapping the API/database.
$src=file_get_contents(dirname(__DIR__).'/quote_api.php');
foreach(array('norm_key','bom_extract_model_codes_from_text','find_bom_cost_match','apply_bom_cost') as $fn){
    preg_match('/^function '.preg_quote($fn,'/').'\(/m',$src,$m,PREG_OFFSET_CAPTURE);$tail=substr($src,$m[0][1]);preg_match('/^function /m',substr($tail,1),$end,PREG_OFFSET_CAPTURE);eval(substr($tail,0,$end[0][1]+1));
}
$product=array('model'=>'52.12345','price_usd'=>88,'price_rmb'=>616,'cost_rmb'=>616,'cost_usd'=>88);
cpCheck(apply_bom_cost($product,$zero),'zero is a real match');
foreach(array('price_usd','price_rmb','cost_usd','cost_rmb') as $field)cpCheck((float)$product[$field]===0.0,'no old-currency fallback: '.$field);
echo "BOM shared publication matching: zero, initial freeze, multiple versions, exact matching, currency reset OK\n";
