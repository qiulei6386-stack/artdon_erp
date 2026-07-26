<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap.php';
use Artdon\MaterialCenter\Services\CategoryFieldService;
use Artdon\MaterialCenter\Services\MaterialMasterService;
$db=db();$master=new MaterialMasterService($db);$fields=new CategoryFieldService($db);$ids=[];$tag='CAT-FINAL-'.bin2hex(random_bytes(3));
$cases=[
 'power_supply'=>[['power.nominal_power_w'=>22.5],'mc_power_supply_specs','nominal_power_w'],
 'chip'=>[['chip.cri'=>90],'mc_material_chip','cri'],
 'optical'=>[['optical.beam_angle'=>36],'mc_material_optical','beam_angle_min'],
 'profile'=>[['profile.material_grade'=>'6063-T5'],'mc_material_profile','material_grade'],
 'connector'=>[['connector.interface_type'=>'track-4-wire'],'mc_material_connector','interface_type'],
 'accessory'=>[['accessory.accessory_type'=>'optical_accessory'],'mc_material_accessory','accessory_type'],
 'packaging'=>[['packaging.packaging_type'=>'color_box'],'mc_material_packaging','packaging_type'],
];
try{
 $categories=$db->query("SELECT code,id FROM mc_material_categories WHERE status='active'")->fetchAll(PDO::FETCH_KEY_PAIR);
 foreach($cases as$category=>$case){[$values,$table,$column]=$case;$value=reset($values);if(!$fields->definitions($category))throw new RuntimeException("$category field registry empty");$id=$master->save(['category_id'=>$categories[$category],'name'=>"$tag $category",'unit'=>'PCS','fields'=>$values],1);$ids[]=$id;$stmt=$db->prepare("SELECT `$column` FROM `$table` WHERE material_id=?");$stmt->execute([$id]);$actual=$stmt->fetchColumn();$same=is_numeric($value)?abs((float)$actual-(float)$value)<0.000001:(string)$actual===(string)$value;if(!$same)throw new RuntimeException("$category extension write failed");}
 $copy=$master->copy($ids[1],1);$ids[]=$copy;$stmt=$db->prepare('SELECT cri FROM mc_material_chip WHERE material_id=?');$stmt->execute([$copy]);if((float)$stmt->fetchColumn()!==90.0)throw new RuntimeException('category extension copy failed');
 try{$master->save(['category_id'=>$categories['chip'],'name'=>"$tag invalid",'unit'=>'PCS','fields'=>['chip.cri'=>101]],1);throw new RuntimeException('invalid category value accepted');}catch(RuntimeException$e){if($e->getMessage()==='invalid category value accepted')throw$e;}
 echo "seven category dynamic fields: OK\n";
}finally{
 if($ids){$list=implode(',',array_map('intval',$ids));foreach(['mc_power_supply_specs','mc_material_chip','mc_material_optical','mc_material_profile','mc_material_connector','mc_material_accessory','mc_material_packaging','mc_material_metadata']as$table)$db->exec("DELETE FROM $table WHERE material_id IN($list)");$db->exec("DELETE FROM mc_activity_logs WHERE entity_type='material' AND entity_id IN($list)");$db->exec("DELETE FROM mc_operation_logs WHERE object_type='material' AND object_id IN($list)");$db->exec("DELETE FROM mc_materials WHERE id IN($list)");}
 $orphans=$db->query("SELECT id FROM mc_materials WHERE name LIKE ".$db->quote($tag.'%'))->fetchAll(PDO::FETCH_COLUMN);if($orphans){$list=implode(',',array_map('intval',$orphans));$db->exec("DELETE FROM mc_material_metadata WHERE material_id IN($list)");$db->exec("DELETE FROM mc_materials WHERE id IN($list)");}
}
