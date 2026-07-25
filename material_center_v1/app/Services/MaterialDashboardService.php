<?php
declare(strict_types=1);

namespace Artdon\MaterialCenter\Services;

use Artdon\MaterialCenter\Repositories\MaterialReadRepository;
use Throwable;

final class MaterialDashboardService
{
    public function view(string $search, string $category): array
    {
        $user = mc_current_user();
        if (!$user) {
            return [
                'status' => 'unauthenticated',
                'user' => null,
                'summary' => ['total'=>0, 'categories'=>0, 'updated_today'=>0, 'last_updated_at'=>''],
                'categories' => [],
                'rows' => [],
            ];
        }
        if (!mc_table_exists('bom_materials')) {
            return [
                'status' => 'unavailable',
                'user' => $user,
                'summary' => ['total'=>0, 'categories'=>0, 'updated_today'=>0, 'last_updated_at'=>''],
                'categories' => [],
                'rows' => [],
            ];
        }
        try {
            $repository = new MaterialReadRepository();
            return [
                'status' => 'available',
                'user' => $user,
                'summary' => $repository->summary(),
                'categories' => $repository->categories(),
                'rows' => $repository->rows($search, $category),
            ];
        } catch (Throwable $error) {
            return [
                'status' => 'unavailable',
                'user' => $user,
                'summary' => ['total'=>0, 'categories'=>0, 'updated_today'=>0, 'last_updated_at'=>''],
                'categories' => [],
                'rows' => [],
                'error' => $error->getMessage(),
            ];
        }
    }
}
