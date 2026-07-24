<?php
declare(strict_types=1);

namespace Artdon\CommercialCenter\Adapters;

use Artdon\CommercialCenter\Contracts\LegacyAuthContract;
use Throwable;

final class LegacyAuthAdapter extends AbstractLegacyReadOnlyAdapter implements LegacyAuthContract
{
    protected array $requiredTables = ['crm_users', 'crm_roles'];

    public function name(): string
    {
        return '统一登录';
    }

    public function currentUser(): array
    {
        try {
            $user = function_exists('current_user') ? current_user() : null;
            if (!$user) {
                return ['authenticated' => false, 'user' => null, 'status' => 'unauthenticated'];
            }
            return [
                'authenticated' => true,
                'status' => 'authenticated',
                'user' => [
                    'id' => (int)$user['id'],
                    'username' => (string)$user['username'],
                    'display_name' => (string)($user['real_name'] ?: $user['username']),
                    'role_key' => (string)($user['role_key'] ?? ''),
                    'is_super_admin' => (int)($user['is_super_admin'] ?? 0) === 1,
                ],
            ];
        } catch (Throwable $error) {
            return ['authenticated' => false, 'user' => null, 'status' => 'unavailable'];
        }
    }
}
