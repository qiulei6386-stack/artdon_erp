<?php
declare(strict_types=1);

namespace Artdon\CommercialCenter\Adapters;

use Artdon\CommercialCenter\Contracts\LegacyPermissionContract;

final class LegacyPermissionAdapter extends AbstractLegacyReadOnlyAdapter implements LegacyPermissionContract
{
    protected array $requiredTables = ['crm_users', 'crm_role_permissions', 'crm_user_permissions'];

    public function name(): string
    {
        return '旧权限中心';
    }

    public function check(string $permission): array
    {
        $user = (new LegacyAuthAdapter())->currentUser();
        if (!$user['authenticated'] || !$user['user']) {
            return ['allowed' => false, 'source' => 'no_user', 'status' => $user['status']];
        }
        // The sidecar's base read permission is intentionally available to an authenticated
        // ERP user; write/action permissions remain explicitly role-controlled.
        if ($permission === 'commercial_center.view') {
            return ['allowed' => true, 'source' => 'authenticated_sidecar_read', 'status' => 'available'];
        }
        if ($user['user']['is_super_admin']) {
            return ['allowed' => true, 'source' => 'legacy_super_admin', 'status' => 'available'];
        }
        $userId = (int)$user['user']['id'];
        $deny = $this->selectOne(
            "SELECT 1 AS matched FROM crm_user_permissions WHERE user_id = ? AND permission_key = ? AND effect = 'deny' LIMIT 1",
            [$userId, $permission]
        );
        if ($deny) {
            return ['allowed' => false, 'source' => 'legacy_user_deny', 'status' => 'available'];
        }
        $allow = $this->selectOne(
            "SELECT 1 AS matched FROM crm_user_permissions WHERE user_id = ? AND permission_key = ? AND effect = 'allow' LIMIT 1",
            [$userId, $permission]
        );
        if ($allow) {
            return ['allowed' => true, 'source' => 'legacy_user_allow', 'status' => 'available'];
        }
        $role = $this->selectOne(
            'SELECT 1 AS matched FROM crm_users u JOIN crm_role_permissions rp ON rp.role_id = u.role_id WHERE u.id = ? AND rp.permission_key = ? LIMIT 1',
            [$userId, $permission]
        );
        return [
            'allowed' => (bool)$role,
            'source' => $role ? 'legacy_role_allow' : 'legacy_no_grant',
            'status' => 'available',
        ];
    }
}
