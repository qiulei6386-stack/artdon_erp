<?php
declare(strict_types=1);

namespace Artdon\Centers\Base;

/**
 * 权限判断，只看数据库里的授权数据，判断顺序：
 *   1. 超级管理员（crm_users.is_super_admin = 1）：全部允许
 *   2. 个人拒绝（crm_user_permissions.effect = 'deny'）：不允许
 *   3. 有效的临时授权（crm_permission_grants：status = active、已开始、未过期）：允许
 *   4. 个人允许（crm_user_permissions.effect = 'allow'）：允许
 *   5. 所属角色有此权限（crm_role_permissions）：允许
 *   6. 其他：不允许
 * 只读，不修改任何授权数据（过期的临时授权按时间判断为无效，不去改它的状态）。
 */
final class Permission
{
    /** @var array<string, bool> 本次请求内的判断缓存 */
    private static $cache = [];

    public static function allows(array $user, string $permission): bool
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0 || $permission === '') {
            return false;
        }
        if ((int) ($user['is_super_admin'] ?? 0) === 1) {
            return true;
        }
        $cacheKey = $userId . '|' . (int) ($user['role_id'] ?? 0) . '|' . $permission;
        if (!array_key_exists($cacheKey, self::$cache)) {
            self::$cache[$cacheKey] = self::evaluate($userId, (int) ($user['role_id'] ?? 0), $permission);
        }
        return self::$cache[$cacheKey];
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }

    private static function evaluate(int $userId, int $roleId, string $permission): bool
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare('SELECT effect FROM crm_user_permissions WHERE user_id = ? AND permission_key = ? LIMIT 1');
        $stmt->execute([$userId, $permission]);
        $effect = (string) $stmt->fetchColumn();
        if ($effect === 'deny') {
            return false;
        }
        if (Db::tableExists('crm_permission_grants')) {
            $now = Db::now();
            $stmt = $pdo->prepare("SELECT 1 FROM crm_permission_grants WHERE user_id = ? AND permission_key = ? AND effect = 'allow' AND status = 'active' AND starts_at <= ? AND (expires_at IS NULL OR expires_at > ?) LIMIT 1");
            $stmt->execute([$userId, $permission, $now, $now]);
            if ($stmt->fetchColumn()) {
                return true;
            }
        }
        if ($effect === 'allow') {
            return true;
        }
        if ($roleId > 0) {
            $stmt = $pdo->prepare('SELECT 1 FROM crm_role_permissions WHERE role_id = ? AND permission_key = ? LIMIT 1');
            $stmt->execute([$roleId, $permission]);
            if ($stmt->fetchColumn()) {
                return true;
            }
        }
        return false;
    }

    /**
     * 数据范围（crm_user_scopes）。超级管理员为 all；没有设置时为 self（只看自己的）。
     *
     * @return array{scope_type: string, scope_value: string}
     */
    public static function scope(array $user, string $module): array
    {
        if ((int) ($user['is_super_admin'] ?? 0) === 1) {
            return ['scope_type' => 'all', 'scope_value' => ''];
        }
        $stmt = Db::pdo()->prepare('SELECT scope_type, scope_value FROM crm_user_scopes WHERE user_id = ? AND module = ? LIMIT 1');
        $stmt->execute([(int) ($user['id'] ?? 0), $module]);
        $row = $stmt->fetch();
        return $row ? ['scope_type' => (string) $row['scope_type'], 'scope_value' => (string) ($row['scope_value'] ?? '')] : ['scope_type' => 'self', 'scope_value' => ''];
    }
}
