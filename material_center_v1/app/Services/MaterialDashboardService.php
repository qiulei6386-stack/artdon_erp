<?php
declare(strict_types=1);

namespace Artdon\MaterialCenter\Services;

use PDO;
use Throwable;

final class MaterialDashboardService
{
    public function operational(): array
    {
        $empty=['power_pending'=>0,'chip_pending'=>0,'conflicts'=>0,'price_changes'=>0,'activities'=>[]];
        if (!mc_current_user() || !mc_table_exists('mc_materials')) return $empty;
        try {
            $db=db();
            $scalar=static function(PDO $db,string $sql,array $params=[]):int{
                $statement=$db->prepare($sql);$statement->execute($params);
                return (int)$statement->fetchColumn();
            };
            $empty['power_pending']=$scalar($db,"SELECT COUNT(*) FROM mc_materials m JOIN mc_material_categories c ON c.id=m.category_id LEFT JOIN mc_power_supply_specs p ON p.material_id=m.id WHERE m.deleted_at IS NULL AND c.code='power_supply' AND (m.status IN ('draft','pending_review') OR p.power_band_id IS NULL OR p.supplier_warranty_years IS NULL)");
            $empty['chip_pending']=$scalar($db,"SELECT COUNT(*) FROM mc_materials m JOIN mc_material_categories c ON c.id=m.category_id LEFT JOIN mc_material_chip s ON s.material_id=m.id WHERE m.deleted_at IS NULL AND c.code='chip' AND (m.status IN ('draft','pending_review') OR s.cri IS NULL OR s.cct_min_k IS NULL OR s.cct_max_k IS NULL)");
            $empty['conflicts']=$scalar($db,"SELECT COUNT(*) FROM mc_adaptation_conflicts WHERE status='active'");
            $empty['price_changes']=$scalar($db,"SELECT COUNT(*) FROM mc_supplier_price_history WHERE created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)");
            $statement=$db->query("SELECT action,entity_type,entity_id,actor_id,created_at FROM mc_activity_logs ORDER BY created_at DESC,id DESC LIMIT 6");
            $empty['activities']=$statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {}
        return $empty;
    }

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
