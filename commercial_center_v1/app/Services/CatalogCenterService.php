<?php
declare(strict_types=1);

namespace Artdon\CommercialCenter\Services;

use Artdon\CommercialCenter\Adapters\LegacyPermissionAdapter;
use Artdon\CommercialCenter\Repositories\LegacyCatalogReadRepository;
use Artdon\CommercialCenter\Support\Logger;
use Throwable;

final class CatalogCenterService
{
    public function products(array $authentication, string $search, string $category): array
    {
        return $this->load($authentication, 'naming.view', static function (LegacyCatalogReadRepository $repository) use ($search, $category): array {
            return [
                'rows' => $repository->products($search, $category),
                'categories' => $repository->productCategories(),
            ];
        });
    }

    public function materials(array $authentication, string $search, string $category): array
    {
        return $this->load($authentication, 'bom.view', static function (LegacyCatalogReadRepository $repository) use ($search, $category): array {
            return [
                'rows' => $repository->materials($search, $category),
                'categories' => $repository->materialCategories(),
            ];
        });
    }

    private function load(array $authentication, string $permission, callable $loader): array
    {
        if (!$authentication['authenticated']) {
            return ['status' => 'unauthenticated', 'rows' => [], 'categories' => [], 'permission' => $permission];
        }
        $access = (new LegacyPermissionAdapter())->check($permission);
        if (!$access['allowed']) {
            return ['status' => 'forbidden', 'rows' => [], 'categories' => [], 'permission' => $permission];
        }
        try {
            $data = $loader(new LegacyCatalogReadRepository());
            return array_merge(['status' => 'available', 'permission' => $permission], $data);
        } catch (Throwable $error) {
            Logger::error('Catalog center read failed', [
                'permission' => $permission,
                'type' => get_class($error),
                'message' => $error->getMessage(),
            ]);
            return ['status' => 'unavailable', 'rows' => [], 'categories' => [], 'permission' => $permission];
        }
    }
}
