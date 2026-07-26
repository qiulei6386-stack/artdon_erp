<?php
declare(strict_types=1);
namespace Artdon\MaterialCenter\Services;
use PDO;use RuntimeException;
final class CategoryFieldService{
 private const TARGETS=[
  'power.nominal_power_w'=>['power_supply','mc_power_supply_specs','nominal_power_w'],
  'power.output_current_ma'=>['power_supply','mc_power_supply_specs','output_current_ma'],
  'chip.cri'=>['chip','mc_material_chip','cri'],
  'optical.beam_angle'=>['optical','mc_material_optical','beam_angle_min'],
  'profile.material_grade'=>['profile','mc_material_profile','material_grade'],
  'connector.interface_type'=>['connector','mc_material_connector','interface_type'],
  'accessory.accessory_type'=>['accessory','mc_material_accessory','accessory_type'],
  'packaging.packaging_type'=>['packaging','mc_material_packaging','packaging_type'],
 ];
 public function __construct(private ?PDO$db=null){$this->db??=\db();}
 public function definitions(string$category):array{$keys=[];foreach(self::TARGETS as$key=>[$owner])if($owner===$category)$keys[]=$key;if(!$keys)return[];$marks=implode(',',array_fill(0,count($keys),'?'));$stmt=$this->db->prepare("SELECT f.field_code,f.field_name,f.data_type,f.unit,m.is_required,f.validation_json,f.default_json,m.sort_order,(SELECT JSON_ARRAYAGG(JSON_OBJECT('value',o.option_value,'label',o.option_label)) FROM mc_field_options o WHERE o.field_id=f.id AND o.status='active') options_json FROM mc_field_registry f JOIN mc_category_field_map m ON m.field_id=f.id AND m.category_code=? WHERE f.status='active' AND f.field_code IN($marks) ORDER BY m.sort_order,f.id");$stmt->execute(array_merge([$category],$keys));$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);foreach($rows as&$row){$row['validation']=json_decode((string)($row['validation_json']??''),true)?:[];$row['default']=json_decode((string)($row['default_json']??''),true);$row['options']=json_decode((string)($row['options_json']??''),true)?:[];unset($row['validation_json'],$row['default_json'],$row['options_json']);}return$rows;}
 public function values(int$materialId,string$category):array{$out=[];foreach(self::TARGETS as$key=>[$owner,$table,$column]){if($owner!==$category)continue;$stmt=$this->db->prepare("SELECT `$column` FROM `$table` WHERE material_id=?");$stmt->execute([$materialId]);$value=$stmt->fetchColumn();if($value!==false&&$value!==null)$out[$key]=$value;}return$out;}
 public function save(int$materialId,string$category,array$values):void{if(!$values)return;$definitions=[];foreach($this->definitions($category)as$field)$definitions[$field['field_code']]=$field;foreach($values as$key=>$raw){if(!isset($definitions[$key],self::TARGETS[$key])||self::TARGETS[$key][0]!==$category)throw new RuntimeException("字段 {$key} 不属于当前类别。");$field=$definitions[$key];$value=$this->validate($raw,$field);if($value===null)continue;[,$table,$column]=self::TARGETS[$key];if($table==='mc_power_supply_specs'){$stmt=$this->db->prepare("UPDATE `$table` SET `$column`=?,updated_at=NOW() WHERE material_id=?");$stmt->execute([$value,$materialId]);if(!$stmt->rowCount())throw new RuntimeException('电源扩展记录不存在。');}else{$stmt=$this->db->prepare("INSERT INTO `$table`(material_id,`$column`,updated_at)VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE `$column`=VALUES(`$column`),updated_at=NOW()");$stmt->execute([$materialId,$value]);}}}
 private function validate(mixed$raw,array$field):mixed{$value=is_string($raw)?trim($raw):$raw;if($value===''||$value===null){if((int)$field['is_required'])throw new RuntimeException($field['field_name'].'不能为空。');return null;}$rules=$field['validation'];if($field['data_type']==='decimal'){if(!is_numeric($value))throw new RuntimeException($field['field_name'].'必须为数字。');$value=(float)$value;if(isset($rules['min'])&&$value<$rules['min']||isset($rules['max'])&&$value>$rules['max'])throw new RuntimeException($field['field_name'].'超出允许范围。');return$value;}if($field['data_type']==='enum'&&!in_array($value,array_column($field['options'],'value'),true))throw new RuntimeException($field['field_name'].'选项无效。');if(isset($rules['maxLength'])&&mb_strlen((string)$value)>(int)$rules['maxLength'])throw new RuntimeException($field['field_name'].'过长。');return mb_substr((string)$value,0,500);}
}
