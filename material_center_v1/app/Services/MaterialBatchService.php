<?php
declare(strict_types=1);
namespace Artdon\MaterialCenter\Services;
use Artdon\MaterialCenter\Security\MaterialCenterUserContext;use PDO;use RuntimeException;
final class MaterialBatchService{
 public function __construct(private ?PDO$db=null){$this->db??=\db();}
 public function preview(array$ids,array$changes,string$policy,MaterialCenterUserContext$user):array{
  $ids=array_values(array_unique(array_filter(array_map('intval',$ids))));if(!$ids||count($ids)>500)throw new RuntimeException('请选择1–500条正式物料。');
  if(!in_array($policy,['fill_empty','overwrite'],true))throw new RuntimeException('覆盖策略无效。');
  $allowed=[];foreach((new FieldRegistryService($this->db))->editable('power_supply',$user)as$f)$allowed[$f['field_key']]=$f;
  $clean=[];foreach($changes as$key=>$value){if(!isset($allowed[$key]))throw new RuntimeException("字段 {$key} 不允许批量编辑。");$clean[$key]=$this->validate($value,$allowed[$key]);}
  if(!$clean)throw new RuntimeException('请至少添加一个字段。');
  $marks=implode(',',array_fill(0,count($ids),'?'));$q=$this->db->prepare("SELECT m.id,m.material_code,m.brand,m.status,p.power_band_id,p.installation_type,p.output_type,p.nominal_power_w,p.max_output_power_w,p.supplier_warranty_years,p.purchase_price FROM mc_materials m JOIN mc_power_supply_specs p ON p.material_id=m.id WHERE m.deleted_at IS NULL AND m.id IN($marks)");$q->execute($ids);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
  return['ids'=>$ids,'changes'=>$clean,'policy'=>$policy,'affected'=>count($rows),'preview'=>array_map(fn($r)=>['id'=>$r['id'],'code'=>$r['material_code'],'changes'=>array_filter($clean,fn($v,$k)=>$policy==='overwrite'||$r[$k]===null||$r[$k]==='',ARRAY_FILTER_USE_BOTH)],$rows)];
 }
 public function execute(array$ids,array$changes,string$policy,MaterialCenterUserContext$user):array{
  $preview=$this->preview($ids,$changes,$policy,$user);$uuid=$this->uuid();$this->db->beginTransaction();try{$this->db->prepare("INSERT INTO mc_batch_jobs(job_uuid,entity_type,selection_scope,selection_json,changes_json,overwrite_policy,status,total_count,created_by,created_at)VALUES(?,'material','selected',?,?,?,'running',?,?,NOW())")->execute([$uuid,json_encode($preview['ids']),json_encode($preview['changes'],JSON_UNESCAPED_UNICODE),$policy,$preview['affected'],$user->id]);$job=(int)$this->db->lastInsertId();$success=0;$skip=0;
   foreach($preview['preview']as$item){$actual=$item['changes'];if(!$actual){$skip++;continue;}$mid=(int)$item['id'];$before=[];foreach($actual as$key=>$value){$target=$this->target($key);[$table,$column]=$target;$stmt=$this->db->prepare("SELECT `$column` FROM `$table` WHERE ".($table==='mc_materials'?'id':'material_id')."=?");$stmt->execute([$mid]);$before[$key]=$stmt->fetchColumn();$stmt=$this->db->prepare("UPDATE `$table` SET `$column`=?".($table==='mc_materials'?',updated_by=?,updated_at=NOW()':',updated_at=NOW()')." WHERE ".($table==='mc_materials'?'id':'material_id')."=?");$stmt->execute($table==='mc_materials'?[$value,$user->id,$mid]:[$value,$mid]);}$this->db->prepare("INSERT INTO mc_batch_job_items(batch_job_id,entity_id,before_json,after_json,result)VALUES(?,?,?,?,'success')")->execute([$job,$mid,json_encode($before),json_encode($actual)]);$success++;}
   $this->db->prepare("UPDATE mc_batch_jobs SET status='completed',success_count=?,skipped_count=?,executed_at=NOW() WHERE id=?")->execute([$success,$skip,$job]);$this->db->commit();return['job_uuid'=>$uuid,'success'=>$success,'skipped'=>$skip,'failed'=>0];
  }catch(\Throwable$e){if($this->db->inTransaction())$this->db->rollBack();throw$e;}
 }
 private function target(string$key):array{return match($key){'brand','status'=>['mc_materials',$key],default=>['mc_power_supply_specs',$key]};}
 private function validate(mixed$value,array$field):mixed{$type=$field['data_type'];if($type==='decimal'&&$value!==''){if(!is_numeric($value))throw new RuntimeException($field['label'].'必须为数字。');return(float)$value;}if($type==='enum'){if(!in_array($value,json_decode((string)$field['options_json'],true)?:[],true))throw new RuntimeException($field['label'].'选项无效。');}return mb_substr(trim((string)$value),0,255);}
 private function uuid():string{$d=random_bytes(16);$d[6]=chr((ord($d[6])&15)|64);$d[8]=chr((ord($d[8])&63)|128);return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4));}
}
