<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/quote_material_read.php';
function norm_material($m){return array('id'=>$m['id']??'','name'=>$m['name']??'','price'=>$m['price']??0,'image'=>$m['image']??'');}
function qi_check($v,$m){if(!$v)throw new RuntimeException($m);}
$select=quote_material_light_columns(array('id','name','price','image','private_payload','updated_at'));
qi_check(strpos($select,'`image`')===false&&strpos($select,'CASE WHEN')!==false,'No image bytes selected');
qi_check(strpos($select,'private_payload')===false,'No unrelated blobs selected');
$m=quote_material_defer_image(array('id'=>1,'name'=>'Synthetic','price'=>0,'image'=>'data:image/png;base64,'.str_repeat('A',1000000)));
qi_check($m['image']===''&&$m['image_deferred']===true&&$m['price']===0,'Image deferred with zero price preserved');
qi_check(quote_material_defer_image(array('id'=>2,'image'=>''))['image_deferred']===false,'No invented image');
$list=array();for($i=0;$i<1104;$i++)$list[]=quote_material_defer_image(array('id'=>$i,'name'=>'Synthetic '.$i,'price'=>12.5,'image_deferred'=>1));
$json=json_encode($list);qi_check(strlen($json)<250000,'Light payload budget');
$api=file_get_contents(dirname(__DIR__).'/quote_api.php');qi_check(strpos($api,"if(\$action==='get_material_image')return 'can_access'")!==false,'Authenticated permission path');
echo 'Quote material initialization: '.strlen($json).' synthetic bytes; zero values, projection and permission checks OK'.PHP_EOL;
