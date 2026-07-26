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
        $sessionUser = function_exists('current_user') ? \current_user() : null;
        if (is_array($sessionUser) && (int) ($sessionUser['id'] ?? 0) === $user->id && function_exists('has_permission')) {
            return (bool) \has_permission($permission);
        }
        $deny = $this->db->prepare("SELECT 1 FROM crm_user_permissions WHERE user_id=? AND permission_key=? AND effect='deny' LIMIT 1");
        $deny->execute([$user->id, $permission]);
        if ($deny->fetchColumn()) return false;
        $allow = $this->db->prepare("SELECT 1 FROM crm_user_permissions WHERE user_id=? AND permission_key=? AND effect='allow' LIMIT 1");
        $allow->execute([$user->id, $permission]);
        if ($allow->fetchColumn()) return true;
        $role = $this->db->prepare('SELECT 1 FROM crm_roles r JOIN crm_role_permissions rp ON rp.role_id=r.id WHERE r.role_key=? AND rp.permission_key=? LIMIT 1');
        $role->execute([$user->roleKey, $permission]);
        return (bool) $role->fetchColumn();
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
        return $this->allows($user,$permission)?['type'=>'unified_permission_center']:['type'=>'none'];
    }
}
