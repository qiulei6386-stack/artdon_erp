<?php
declare(strict_types=1);

namespace Artdon\MaterialCenter\Services;

use Artdon\MaterialCenter\Repositories\MaterialReadRepository;
use Throwable;

final class PowerSupplyReadService
{
    public function view(string $search): array
    {
        $user = mc_current_user();
        if (!$user) {
            return ['status' => 'unauthenticated', 'user' => null, 'rows' => []];
        }
        if (!mc_table_exists('bom_materials')) {
            return ['status' => 'unavailable', 'user' => $user, 'rows' => []];
        }
        try {
            return [
                'status' => 'available',
                'user' => $user,
                'rows' => (new MaterialReadRepository())->powerSupplyRows($search),
            ];
        } catch (Throwable) {
            return ['status' => 'unavailable', 'user' => $user, 'rows' => []];
        }
    }
}
