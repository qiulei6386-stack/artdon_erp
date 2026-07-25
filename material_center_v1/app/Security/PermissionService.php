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
}
