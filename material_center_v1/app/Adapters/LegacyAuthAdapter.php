<?php
declare(strict_types=1);
namespace Artdon\MaterialCenter\Adapters;

use Artdon\MaterialCenter\Security\MaterialCenterUserContext;

final class LegacyAuthAdapter
{
    public function current(): ?MaterialCenterUserContext
    {
        $user = \mc_current_user();
        if (!$user) return null;
        $role = (string)($user['role'] ?? $user['role_key'] ?? '');
        $super = function_exists('is_super_admin') ? (bool)\is_super_admin() : in_array($role, ['superadmin','admin'], true);
        return new MaterialCenterUserContext(
            (int)$user['id'],
            (string)($user['username'] ?? ''),
            (string)($user['real_name'] ?? $user['name'] ?? $user['username'] ?? ''),
            $role,
            $super
        );
    }
}
