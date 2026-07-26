<?php
declare(strict_types=1);
namespace Artdon\MaterialCenter\Services;
use PDO;use RuntimeException;
final class PermissionAdminService{
 public function __construct(private ?PDO$db=null){$this->db??=\db();}
 public function definitions():array{return$this->db->query('SELECT permission_key,name,level FROM mc_permissions ORDER BY level,permission_key')->fetchAll(PDO::FETCH_ASSOC);}
 public function grants():array{return$this->db->query('SELECT * FROM mc_permission_grants ORDER BY subject_type,subject_id,permission_key')->fetchAll(PDO::FETCH_ASSOC);}
 public function save(array$d,int$userId):void{$type=(string)($d['subject_type']??'role');$id=trim((string)($d['subject_id']??''));$key=(string)($d['permission_key']??'');$effect=(string)($d['effect']??'allow');if(!in_array($type,['user','role'],true)||$id===''||!in_array($effect,['allow','deny'],true))throw new RuntimeException('授权对象或效果无效。');$check=$this->db->prepare('SELECT 1 FROM mc_permissions WHERE permission_key=?');$check->execute([$key]);if(!$check->fetchColumn())throw new RuntimeException('权限项不存在。');$scope=$d['data_scope_json']??null;if(is_string($scope)&&$scope!==''){json_decode($scope,true,512,JSON_THROW_ON_ERROR);}else$scope=null;$this->db->beginTransaction();try{$this->db->prepare('INSERT INTO mc_permission_grants(subject_type,subject_id,permission_key,effect,data_scope_json,granted_by,created_at,updated_at)VALUES(?,?,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE effect=VALUES(effect),data_scope_json=VALUES(data_scope_json),granted_by=VALUES(granted_by),updated_at=NOW()')->execute([$type,$id,$key,$effect,$scope,$userId]);$this->db->prepare('INSERT INTO mc_permission_audit_logs(subject_type,subject_id,permission_key,action,actor_id,created_at)VALUES(?,?,?,?,?,NOW())')->execute([$type,$id,$key,$effect,$userId]);$this->db->commit();}catch(\Throwable$e){if($this->db->inTransaction())$this->db->rollBack();throw$e;}}
}
