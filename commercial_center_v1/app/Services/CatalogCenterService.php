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
        if (!$authentication['authenticated']) {
            return ['status' => 'unauthenticated', 'rows' => [], 'categories' => [], 'permission' => 'commercial_center.view'];
        }
        try {
            $repository = new LegacyCatalogReadRepository();
            $rows = $repository->products($search, $category);
            $costs = $repository->bomCostsForModels(array_column($rows, 'model_no'));
            foreach ($rows as &$row) { $cost = $costs[(string)$row['model_no']] ?? null; $row['bom_cost'] = $cost; $row['product_name'] = trim((string)$row['product_name']) . ' · BOM成本 ' . ($cost === null ? '—' : number_format((float)$cost, 4)); }
            unset($row);
            return ['status'=>'available','permission'=>'commercial_center.view','rows'=>$rows,'categories'=>$repository->productCategories()];
        } catch (\Throwable $error) {
            return ['status'=>'unavailable','permission'=>'commercial_center.view','rows'=>[],'categories'=>[]];
        }
        /*
        return $this->load($authentication, 'commercial_center.view', static function (LegacyCatalogReadRepository $repository) use ($search, $category): array {
            $rows = $repository->products($search, $category);
            $costs = $repository->bomCostsForModels(array_column($rows, 'model_no'));
            foreach ($rows as &$row) {
                $cost = $costs[(string)$row['model_no']] ?? null;
                $row['bom_cost'] = $cost;
                $row['product_name'] = trim((string)$row['product_name']) . ' · BOM成本 ' . ($cost === null ? '—' : number_format((float)$cost, 4));
            }
            unset($row);
            return ['rows' => $rows, 'categories' => $repository->productCategories()];
        }); */
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
