<?php
declare(strict_types=1);
namespace Artdon\MaterialCenter\Services;
use PDO;use RuntimeException;
final class MaterialLifecycleService{
 public function __construct(private ?PDO$db=null){$this->db??=\db();}
 public function transition(int$id,string$action,int$userId,string$reason=''):void{
  $map=['submit'=>['draft','pending_review'],'approve'=>['pending_review','official'],'disable'=>['official','disabled'],'restore'=>['disabled','official'],'archive'=>['disabled','archived']];
  if(!isset($map[$action]))throw new RuntimeException('生命周期操作无效。');[$from,$to]=$map[$action];$this->db->beginTransaction();try{$s=$this->db->prepare('SELECT m.status,c.code category_code FROM mc_materials m JOIN mc_material_categories c ON c.id=m.category_id WHERE m.id=? AND m.deleted_at IS NULL FOR UPDATE');$s->execute([$id]);$row=$s->fetch(PDO::FETCH_ASSOC);if(!$row)throw new RuntimeException('物料不存在。',404);$status=(string)$row['status'];if($status!==$from)throw new RuntimeException("当前状态不能执行此操作。");$official=$to==='official'?1:0;$allowed=$to==='official'?1:0;$this->db->prepare('UPDATE mc_materials SET status=?,is_official=?,allow_bom=?,allow_quote=?,updated_by=?,updated_at=NOW() WHERE id=?')->execute([$to,$official,$allowed,$allowed,$userId,$id]);$this->syncCategoryStatus($id,(string)$row['category_code'],$to);$this->db->prepare('INSERT INTO mc_material_lifecycle_events(material_id,from_status,to_status,action,reason,actor_id,created_at)VALUES(?,?,?,?,?,?,NOW())')->execute([$id,$from,$to,$action,$reason?:null,$userId]);$this->db->commit();}catch(\Throwable$e){if($this->db->inTransaction())$this->db->rollBack();throw$e;}
 }
 private function syncCategoryStatus(int$id,string$category,string$status):void{
  $table=match($category){'power_supply'=>'mc_power_supply_specs',default=>null};
  if(!$table||!\mc_table_exists($table))return;
  $q=$this->db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=\'status\' LIMIT 1');
  $q->execute([$table]);
  if(!$q->fetchColumn())return;
  $this->db->prepare("UPDATE `$table` SET status=?,updated_at=NOW() WHERE material_id=?")->execute([$status,$id]);
 }
}
