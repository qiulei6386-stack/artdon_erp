<?php
declare(strict_types=1);

namespace Artdon\PromotionCenter;

/**
 * 统一权限适配器。不读取角色名做判断，也不改 permission_alias_keys()。
 */
class LegacyPermissionAdapter implements PmAccess
{
    public function userId(): int
    {
        $user = current_user();
        return (int) ($user['id'] ?? 0);
    }

    public function displayName(): string
    {
        $user = current_user() ?: [];
        $name = trim((string) ($user['real_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        return (string) ($user['username'] ?? '');
    }

    public function roleName(): string
    {
        $user = current_user() ?: [];
        return (string) ($user['role_name'] ?? '');
    }

    public function departmentName(): string
    {
        $user = current_user() ?: [];
        return trim((string) ($user['department_name'] ?? ''));
    }

    public function isSuperAdmin(): bool
    {
        return function_exists('is_super_admin') && is_super_admin();
    }

    public function hasPermission(string $permission): bool
    {
        return has_permission($permission);
    }

    public function scope(string $module): array
    {
        $row = get_user_scope($module);
        return [
            'scope_type' => (string) ($row['scope_type'] ?? 'none'),
            'scope_value' => (string) ($row['scope_value'] ?? ''),
        ];
    }
}
