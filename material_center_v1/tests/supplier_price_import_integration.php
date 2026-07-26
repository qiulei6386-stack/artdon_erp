<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Artdon\MaterialCenter\Services\ImportService;
use Artdon\MaterialCenter\Services\MaterialMasterService;
use Artdon\MaterialCenter\Services\SupplierService;

$db = db();
$userId = 1;
$stamp = date('His') . random_int(100, 999);
$supplierId = 0;
$materialId = 0;
$taskId = 0;
$file = tempnam(sys_get_temp_dir(), 'mc-price-');

try {
    $categoryId = (int)$db->query("SELECT id FROM mc_material_categories WHERE code='accessory' LIMIT 1")->fetchColumn();
    if (!$categoryId) {
        throw new RuntimeException('accessory category unavailable');
    }
    $materialId = (new MaterialMasterService($db))->save([
        'category_id' => $categoryId,
        'name' => 'PRICE IMPORT TEST ' . $stamp,
        'unit' => 'PCS',
    ], $userId);
    $supplierId = (new SupplierService($db))->saveSupplier([
        'supplier_code' => 'PIT-' . $stamp,
        'name' => 'Price Import Test ' . $stamp,
        'default_currency' => 'CNY',
    ], $userId);
    $materialCode = (string)$db->query('SELECT material_code FROM mc_materials WHERE id=' . $materialId)->fetchColumn();
    file_put_contents($file, "\xEF\xBB\xBF供应商代码,物料代码,供应商型号,采购价,币种,起订量,交期\nPIT-{$stamp},{$materialCode},PIT-MODEL,12.3456,CNY,50,7\n");

    $service = new ImportService($db);
    $precheck = $service->uploadPrices([
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($file),
        'name' => 'prices.csv',
        'tmp_name' => $file,
    ], $userId);
    if ($precheck['valid'] !== 1 || $precheck['errors'] !== 0) {
        throw new RuntimeException('price precheck failed');
    }
    $task = $db->prepare('SELECT id,status FROM mc_import_tasks WHERE task_uuid=?');
    $task->execute([$precheck['task_uuid']]);
    $taskRow = $task->fetch(PDO::FETCH_ASSOC);
    $taskId = (int)$taskRow['id'];
    $result = $service->executePrices($precheck['task_uuid'], $userId);
    if ($result['success'] !== 1 || $result['errors'] !== 0) {
        throw new RuntimeException('price execution failed');
    }
    $check = $db->prepare("SELECT p.approval_status,p.purchase_price,sm.moq,sm.lead_time_days
        FROM mc_supplier_prices p JOIN mc_supplier_materials sm ON sm.id=p.supplier_material_id
        WHERE sm.supplier_id=? AND sm.material_id=? ORDER BY p.id DESC LIMIT 1");
    $check->execute([$supplierId, $materialId]);
    $price = $check->fetch(PDO::FETCH_ASSOC);
    if (!$price || $price['approval_status'] !== 'pending' || (float)$price['purchase_price'] !== 12.3456 || (float)$price['moq'] !== 50.0 || (int)$price['lead_time_days'] !== 7) {
        throw new RuntimeException('imported price workflow mismatch');
    }
    echo "Supplier price import integration: OK\n";
} finally {
    if ($supplierId) {
        $sm = $db->query('SELECT id FROM mc_supplier_materials WHERE supplier_id=' . $supplierId)->fetchAll(PDO::FETCH_COLUMN);
        if ($sm) {
            $smList = implode(',', array_map('intval', $sm));
            $prices = $db->query("SELECT id,approval_id FROM mc_supplier_prices WHERE supplier_material_id IN($smList)")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($prices as $price) {
                $pid = (int)$price['id'];
                $db->exec("DELETE FROM mc_supplier_price_tiers WHERE supplier_price_id=$pid");
                $db->exec("DELETE FROM mc_supplier_price_history WHERE supplier_price_id=$pid");
                $db->exec("DELETE FROM mc_supplier_prices WHERE id=$pid");
                if ($price['approval_id']) {
                    $aid = (int)$price['approval_id'];
                    $db->exec("DELETE FROM mc_approval_logs WHERE approval_id=$aid");
                    $db->exec("DELETE FROM mc_approvals WHERE id=$aid");
                }
            }
            $db->exec("DELETE FROM mc_supplier_moq WHERE supplier_material_id IN($smList)");
            $db->exec("DELETE FROM mc_supplier_lead_times WHERE supplier_material_id IN($smList)");
            $db->exec("DELETE FROM mc_supplier_materials WHERE id IN($smList)");
        }
        $db->exec('DELETE FROM mc_supplier_profiles WHERE supplier_id=' . $supplierId);
        $db->exec('DELETE FROM mc_suppliers WHERE id=' . $supplierId);
    }
    if ($materialId) {
        $db->exec("DELETE FROM mc_operation_logs WHERE object_type IN('material','supplier','supplier_price') AND (object_id=$materialId OR object_id=$supplierId)");
        $db->exec('DELETE FROM mc_material_accessory WHERE material_id=' . $materialId);
        $db->exec('DELETE FROM mc_material_metadata WHERE material_id=' . $materialId);
        $db->exec('DELETE FROM mc_material_versions WHERE material_id=' . $materialId);
        $db->exec('DELETE FROM mc_materials WHERE id=' . $materialId);
    }
    if ($taskId) {
        $db->exec('DELETE FROM mc_import_errors WHERE task_id=' . $taskId);
        $db->exec('DELETE FROM mc_import_rows WHERE task_id=' . $taskId);
        $path = $db->query('SELECT file_path FROM mc_import_tasks WHERE id=' . $taskId)->fetchColumn();
        $db->exec('DELETE FROM mc_import_tasks WHERE id=' . $taskId);
        if (is_string($path) && $path !== '') {
            @unlink($path);
        }
    }
    if (is_file($file)) {
        @unlink($file);
    }
}
