<?php
declare(strict_types=1);
namespace Artdon\MaterialCenter\Services;
use PDO;use RuntimeException;
final class CategoryFieldService{
 private const TABLES=['power_supply'=>'mc_power_supply_specs','chip'=>'mc_material_chip','optical'=>'mc_material_optical','profile'=>'mc_material_profile','connector'=>'mc_material_connector','accessory'=>'mc_material_accessory','packaging'=>'mc_material_packaging'];
 public function __construct(private ?PDO$db=null){$this->db??=\db();}
 public function definitions(string$category):array{
  if(!isset(self::TABLES[$category]))return[];
  $stmt=$this->db->prepare("SELECT f.field_code,f.field_name,f.data_type,f.unit,m.is_required,f.validation_json,f.default_json,m.sort_order,f.storage_target,(SELECT JSON_ARRAYAGG(JSON_OBJECT('value',o.option_value,'label',o.option_label)) FROM mc_field_options o WHERE o.field_id=f.id AND o.status='active') options_json FROM mc_field_registry f JOIN mc_category_field_map m ON m.field_id=f.id AND m.category_code=? WHERE f.status='active' AND f.storage_target IS NOT NULL ORDER BY m.sort_order,f.id");$stmt->execute([$category]);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
  foreach($rows as&$row){$this->target($category,(string)$row['storage_target']);$row['validation']=json_decode((string)($row['validation_json']??''),true)?:[];$row['default']=json_decode((string)($row['default_json']??''),true);$row['options']=json_decode((string)($row['options_json']??''),true)?:[];unset($row['validation_json'],$row['default_json'],$row['options_json']);}return$rows;
 }
 public function values(int$materialId,string$category):array{$out=[];foreach($this->definitions($category)as$field){[$table,$column]=$this->target($category,(string)$field['storage_target']);$stmt=$this->db->prepare("SELECT `$column` FROM `$table` WHERE material_id=?");$stmt->execute([$materialId]);$value=$stmt->fetchColumn();if($value!==false&&$value!==null)$out[$field['field_code']]=$value;}return$category==='chip'?$this->legacyChipRangeValues($materialId,$out):$out;}
 public function save(int$materialId,string$category,array$values):void{if(!$values)return;$definitions=[];foreach($this->definitions($category)as$field)$definitions[$field['field_code']]=$field;foreach($values as$key=>$raw){if(!isset($definitions[$key]))throw new RuntimeException("字段 {$key} 不属于当前类别。");$field=$definitions[$key];$value=$this->validate($raw,$field);[$table,$column]=$this->target($category,(string)$field['storage_target']);if($table==='mc_power_supply_specs'){$stmt=$this->db->prepare("UPDATE `$table` SET `$column`=?,updated_at=NOW() WHERE material_id=?");$stmt->execute([$value,$materialId]);if(!$stmt->rowCount()&&!$this->exists($table,$materialId))throw new RuntimeException('电源扩展记录不存在。');}else{$stmt=$this->db->prepare("INSERT INTO `$table`(material_id,`$column`,updated_at)VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE `$column`=VALUES(`$column`),updated_at=NOW()");$stmt->execute([$materialId,$value]);}}}
 private function target(string$category,string$target):array{if(!preg_match('/^(mc_[a-z0-9_]+)\\.([a-z0-9_]+)$/',$target,$match)||($match[1]??'')!==(self::TABLES[$category]??''))throw new RuntimeException('字段存储目标无效。');$stmt=$this->db->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$stmt->execute([$match[1],$match[2]]);if(!$stmt->fetchColumn())throw new RuntimeException('字段存储列不存在。');return[$match[1],$match[2]];}
 private function exists(string$table,int$id):bool{$stmt=$this->db->prepare("SELECT 1 FROM `$table` WHERE material_id=?");$stmt->execute([$id]);return(bool)$stmt->fetchColumn();}
 private function legacyChipRangeValues(int$materialId,array$out):array{
  $stmt=$this->db->prepare('SELECT c.rated_power_w,c.max_power_w,c.current_ma,c.cct_min_k,c.cct_max_k,md.spec_summary FROM mc_material_chip c LEFT JOIN mc_material_metadata md ON md.material_id=c.material_id WHERE c.material_id=? LIMIT 1');$stmt->execute([$materialId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);if(!$row)return$out;
  $put=static function(string$code,mixed$value,bool$force=false)use(&$out):void{if(($force||!array_key_exists($code,$out))&&$value!==false&&$value!==null&&$value!=='')$out[$code]=$value;};
  $same=static fn(mixed$a,mixed$b):bool=>$a!==null&&$a!==''&&$b!==null&&$b!==''&&abs((float)$a-(float)$b)<0.0001;
  $summary=(string)($row['spec_summary']??'');
  $put('chip.min_power_w',$row['rated_power_w']??null);$put('chip.max_power_w',$row['max_power_w']??null);
  if(preg_match('/(\\d+(?:\\.\\d+)?)\\s*[-~至–—]\\s*(\\d+(?:\\.\\d+)?)\\s*W\\b/iu',$summary,$m)){
   $put('chip.min_power_w',(float)$m[1],!array_key_exists('chip.min_power_w',$out)||$same($out['chip.min_power_w']??null,$row['rated_power_w']??null));
   $put('chip.max_power_w',(float)$m[2],!array_key_exists('chip.max_power_w',$out)||$same($out['chip.max_power_w']??null,$row['rated_power_w']??null));
  }
  if(preg_match('/(\\d+(?:\\.\\d+)?)\\s*[-~至–—]\\s*(\\d+(?:\\.\\d+)?)\\s*mA\\b/iu',$summary,$m)){
   $put('chip.current_min_ma',(float)$m[1],!array_key_exists('chip.current_min_ma',$out));
   $put('chip.current_max_ma',(float)$m[2],!array_key_exists('chip.current_max_ma',$out)||$same($out['chip.current_max_ma']??null,$row['current_ma']??null));
  }else{$put('chip.current_max_ma',$row['current_ma']??null);}
  if(($row['cct_min_k']??null)!==null&&($row['cct_min_k']??null)===($row['cct_max_k']??null))$put('chip.cct_k',$row['cct_min_k']);
  if(!array_key_exists('chip.cct_k',$out)&&!preg_match('/\\d+\\s*[-~至–—]\\s*\\d+\\s*K\\b/iu',$summary)&&preg_match('/(\\d{3,5})\\s*K\\b/iu',$summary,$m))$put('chip.cct_k',(int)$m[1]);
  return$out;
 }
 private function validate(mixed$raw,array$field):mixed{$value=is_string($raw)?trim($raw):$raw;if($value===''||$value===null){if((int)$field['is_required'])throw new RuntimeException($field['field_name'].'不能为空。');return null;}$rules=$field['validation'];if(in_array($field['data_type'],['decimal','integer','boolean'],true)){if(!is_numeric($value))throw new RuntimeException($field['field_name'].'必须为数字。');$value=$field['data_type']==='integer'?(int)$value:(float)$value;if(isset($rules['min'])&&$value<$rules['min']||isset($rules['max'])&&$value>$rules['max'])throw new RuntimeException($field['field_name'].'超出允许范围。');return$value;}if($field['data_type']==='enum'&&!in_array($value,array_column($field['options'],'value'),true))throw new RuntimeException($field['field_name'].'选项无效。');if(isset($rules['maxLength'])&&mb_strlen((string)$value)>(int)$rules['maxLength'])throw new RuntimeException($field['field_name'].'过长。');return mb_substr((string)$value,0,2000);}
}
