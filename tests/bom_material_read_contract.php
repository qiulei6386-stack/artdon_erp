<?php
$root=dirname(__DIR__);require $root.'/includes/bom_material_read.php';
function bmr_check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$api=file_get_contents($root.'/bom_api.php');$helper=file_get_contents($root.'/includes/bom_material_read.php');$page=file_get_contents($root.'/bom.php');
bmr_check(strpos(bmr_columns(),',image,')===false,'No original image projection');
bmr_check(strpos($helper,'SELECT *')===false,'No full material rows');
bmr_check(!preg_match('/\b(?:INSERT|UPDATE|DELETE|ALTER)\b/',$helper),'Read helpers cannot write');
bmr_check(strpos($helper,'min(500')!==false&&strpos($helper,' OFFSET ')!==false,'Bounded SQL pagination');
$list=explode("if(\$action === 'materials_list'){",$api,2)[1]??'';$list=explode("if(\$action === 'material_image'){",$list,2)[0];
bmr_check($list!==''&&strpos($list,'bom_sync_weight')===false,'List does not synchronize weights');
bmr_check(strpos($api,"\$replaceImage=array_key_exists('image',\$d)&&empty(\$d['image_unchanged'])")!==false,'Missing image preserves original');
bmr_check(strpos($page,'seq!==materialReadSeq')!==false,'Late page responses ignored');
bmr_check(strpos($page,'materialThumbActive<3')!==false,'Image request concurrency bounded');
bmr_check(strpos($page,'image_unchanged:true')!==false,'Inline edits preserve original');
echo "BOM material query, pagination, image preservation and read-only contracts passed\n";
