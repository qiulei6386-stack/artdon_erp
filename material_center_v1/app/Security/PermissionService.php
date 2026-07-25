<?php
declare(strict_types=1);
namespace Artdon\MaterialCenter\Security;

use PDO;
use RuntimeException;

final class PermissionService
{
    public function __construct(private ?PDO $db = null) { $this->db ??= \db(); }

    public function allows(?MaterialCenterUserContext $user, string $permission): bool
    {
        if (!$user) return false;
        if ($user->isSuperAdmin) return true;
        if (\mc_table_exists('mc_permission_grants')) {
            $stmt = $this->db->prepare("SELECT effect FROM mc_permission_grants WHERE permission_key=? AND (expires_at IS NULL OR expires_at>NOW()) AND ((subject_type='user' AND subject_id=?) OR (subject_type='role' AND subject_id=?)) ORDER BY FIELD(effect,'deny','allow') LIMIT 1");
            $stmt->execute([$permission, (string)$user->id, $user->roleKey]);
            $effect = $stmt->fetchColumn();
            if ($effect !== false) return $effect === 'allow';
        }
        $legacy = str_contains($permission, '.view') ? 'bom.view' : 'bom.edit';
        return function_exists('has_permission') && (bool)\has_permission($legacy);
    }

    public function require(?MaterialCenterUserContext $user, string $permission): void
    {
        if (!$user) throw new RuntimeException('请先登录。', 401);
        if (!$this->allows($user, $permission)) throw new RuntimeException('没有执行此操作的权限。', 403);
    }

    public function fieldAccess(?MaterialCenterUserContext $user,string $category,string $field,string $default='read'):string
    {
        if(!$user)return'none';if($user->isSuperAdmin)return'edit';
        if(\mc_table_exists('mc_field_permission_rules')){$stmt=$this->db->prepare("SELECT access_level FROM mc_field_permission_rules WHERE category_code IN('all',?) AND field_key=? AND ((subject_type='user' AND subject_id=?) OR (subject_type='role' AND subject_id=?)) ORDER BY FIELD(subject_type,'user','role'),FIELD(category_code,?,'all') LIMIT 1");$stmt->execute([$category,$field,(string)$user->id,$user->roleKey,$category]);$access=$stmt->fetchColumn();if($access!==false)return(string)$access;}
        return$default;
    }

    public function protectFields(?MaterialCenterUserContext$user,string$category,array$row,array$sensitive):array
    {
        foreach($sensitive as$field){$access=$this->fieldAccess($user,$category,$field,'none');if($access==='none')unset($row[$field]);elseif($access==='mask'&&array_key_exists($field,$row))$row[$field]='***';}
        return$row;
    }

    public function dataScope(?MaterialCenterUserContext$user,string$permission):array
    {
        if(!$user)return['type'=>'none'];if($user->isSuperAdmin)return['type'=>'all'];
        if(!\mc_table_exists('mc_permission_grants'))return['type'=>'legacy'];
        $stmt=$this->db->prepare("SELECT data_scope_json FROM mc_permission_grants WHERE permission_key=? AND effect='allow' AND ((subject_type='user' AND subject_id=?) OR (subject_type='role' AND subject_id=?)) ORDER BY FIELD(subject_type,'user','role') LIMIT 1");$stmt->execute([$permission,(string)$user->id,$user->roleKey]);$json=$stmt->fetchColumn();return$json?json_decode((string)$json,true):['type'=>'legacy'];
    }
}
