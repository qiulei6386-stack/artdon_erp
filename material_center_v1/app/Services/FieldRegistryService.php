<?php
declare(strict_types=1);
namespace Artdon\MaterialCenter\Services;
use Artdon\MaterialCenter\Security\MaterialCenterUserContext;
use PDO;
final class FieldRegistryService{
 public function __construct(private ?PDO$db=null){$this->db??=\db();}
 public function editable(string$category,MaterialCenterUserContext$user):array{
  $stmt=$this->db->prepare("SELECT * FROM mc_field_definitions WHERE status='active' AND is_batch_editable=1 AND category_code IN('all',?) ORDER BY sort_order");$stmt->execute([$category]);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
  if($user->isSuperAdmin)return$rows;
  $permissions=$this->db->prepare("SELECT field_key,access_level FROM mc_field_permission_rules WHERE category_code IN('all',?) AND ((subject_type='user' AND subject_id=?) OR (subject_type='role' AND subject_id=?))");$permissions->execute([$category,(string)$user->id,$user->roleKey]);$map=[];foreach($permissions->fetchAll(PDO::FETCH_ASSOC)as$r)$map[$r['field_key']]=$r['access_level'];
  return array_values(array_filter($rows,fn($r)=>($map[$r['field_key']]??($r['is_sensitive']?'none':'edit'))==='edit'));
 }
}
