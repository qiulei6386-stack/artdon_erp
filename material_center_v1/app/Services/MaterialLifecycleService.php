<?php
declare(strict_types=1);
namespace Artdon\MaterialCenter\Services;
use PDO;use RuntimeException;
final class MaterialLifecycleService{
 public function __construct(private ?PDO$db=null){$this->db??=\db();}
 public function transition(int$id,string$action,int$userId,string$reason=''):void{
  $map=['submit'=>['draft','pending_review'],'approve'=>['pending_review','official'],'disable'=>['official','disabled'],'restore'=>['disabled','official'],'archive'=>['disabled','archived']];
  if(!isset($map[$action]))throw new RuntimeException('生命周期操作无效。');[$from,$to]=$map[$action];$this->db->beginTransaction();try{$s=$this->db->prepare('SELECT status FROM mc_materials WHERE id=? AND deleted_at IS NULL FOR UPDATE');$s->execute([$id]);$status=$s->fetchColumn();if($status!==$from)throw new RuntimeException("当前状态不能执行此操作。");$official=$to==='official'?1:0;$allowed=$to==='official'?1:0;$this->db->prepare('UPDATE mc_materials SET status=?,is_official=?,allow_bom=?,allow_quote=?,updated_by=?,updated_at=NOW() WHERE id=?')->execute([$to,$official,$allowed,$allowed,$userId,$id]);$this->db->prepare('INSERT INTO mc_material_lifecycle_events(material_id,from_status,to_status,action,reason,actor_id,created_at)VALUES(?,?,?,?,?,?,NOW())')->execute([$id,$from,$to,$action,$reason?:null,$userId]);$this->db->commit();}catch(\Throwable$e){if($this->db->inTransaction())$this->db->rollBack();throw$e;}
 }
}
