<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/bootstrap.php';

use Artdon\MaterialCenter\Security\MaterialCenterUserContext;
use Artdon\MaterialCenter\Services\MaterialMasterService;
use Artdon\MaterialCenter\Services\PowerEditorService;

$db = db();
$context = new MaterialCenterUserContext(1, 'codex_test', 'Codex Test', 'super_admin', true);
$materialIds = [];
$jobUuid = '';

try {
    $categoryId = (int)$db->query(
        "SELECT id FROM mc_material_categories WHERE code='power_supply' AND status='active' LIMIT 1"
    )->fetchColumn();
    if (!$categoryId) {
        throw new RuntimeException('power category missing');
    }
    $service = new PowerEditorService($db);
    $created = $service->save(0, [
        'name' => 'CODEX-POWER-EDITOR-A-'.bin2hex(random_bytes(3)),
        'brand' => 'CODEX',
        'model' => 'PE-A',
        'unit' => 'PCS',
        'installation_type' => 'unknown',
        'output_type' => 'unknown',
        'currents' => [],
        'dimming_modes' => [],
    ], $context);
    $materialIds[] = (int)$created['material_id'];
    $materialIds[] = (new MaterialMasterService($db))->save([
        'category_id' => $categoryId,
        'name' => 'CODEX-POWER-EDITOR-B-'.bin2hex(random_bytes(3)),
        'brand' => 'CODEX',
        'model' => 'PE-B',
        'unit' => 'PCS',
    ], 1);
    $detail = $service->detail($materialIds[0], $context);
    $saved = $service->save($materialIds[0], [
        'lock_version' => $detail['lock_version'],
        'name' => $detail['name'],
        'brand' => 'CODEX-SAVED',
        'model' => $detail['model'],
        'unit' => 'PCS',
        'spec_summary' => 'integration',
        'nominal_power_w' => 20,
        'max_output_power_w' => 24,
        'installation_type' => 'internal',
        'output_type' => 'constant_current',
        'supplier_warranty_years' => 5,
        'currents' => [
            ['value' => 300, 'is_default' => false],
            ['value' => 350, 'is_default' => true],
        ],
        'dimming_modes' => ['dali', 'push'],
        'primary_dimming' => 'dali',
    ], $context);
    if ($saved['brand'] !== 'CODEX-SAVED' || count($saved['currents']) !== 2 || count($saved['dimming_modes']) !== 2) {
        throw new RuntimeException('single save did not persist all power fields');
    }

    $changes = [
        'installation_type' => 'external',
        'supplier_warranty_years' => 3,
        'currents' => [500, 700],
        'dimming_modes' => ['triac'],
        'primary_dimming' => 'triac',
    ];
    $preview = $service->batchPreview($materialIds, $changes, 'overwrite', $context);
    if ($preview['affected'] !== 2) {
        throw new RuntimeException('batch preview affected count mismatch');
    }
    $batch = $service->batchExecute($materialIds, $changes, 'overwrite', $context);
    $jobUuid = (string)$batch['job_uuid'];
    if ($batch['success'] !== 2) {
        throw new RuntimeException('batch execution failed');
    }
    foreach ($materialIds as $id) {
        $row = $service->detail($id, $context);
        if ($row['installation_type'] !== 'external' || count($row['currents']) !== 2 || ($row['dimming_modes'][0]['mode'] ?? '') !== 'triac') {
            throw new RuntimeException(
                'batch values were not persisted: '.json_encode([
                    'id' => $id,
                    'installation_type' => $row['installation_type'],
                    'currents' => $row['currents'],
                    'dimming_modes' => $row['dimming_modes'],
                ], JSON_UNESCAPED_UNICODE)
            );
        }
    }
    $rolledBack = $service->rollback($jobUuid, $context);
    if ($rolledBack['restored'] !== 2) {
        throw new RuntimeException('batch rollback count mismatch');
    }
    $restored = $service->detail($materialIds[0], $context);
    if ($restored['installation_type'] !== 'internal' || count($restored['currents']) !== 2) {
        throw new RuntimeException('batch rollback did not restore the original values');
    }
    echo "Power editor integration passed: single save, multi-value fields, batch preview/execute/rollback.\n";
} finally {
    if ($jobUuid !== '') {
        $stmt = $db->prepare('SELECT id FROM mc_batch_jobs WHERE job_uuid=?');
        $stmt->execute([$jobUuid]);
        $jobId = (int)$stmt->fetchColumn();
        if ($jobId) {
            $db->prepare('DELETE FROM mc_batch_job_items WHERE batch_job_id=?')->execute([$jobId]);
            $db->prepare('DELETE FROM mc_batch_jobs WHERE id=?')->execute([$jobId]);
        }
    }
    foreach ($materialIds as $id) {
        foreach ([
            'mc_power_supply_current_options', 'mc_power_supply_dimming_modes', 'mc_power_supply_specs',
            'mc_material_metadata', 'mc_material_versions', 'mc_material_lifecycle_events',
        ] as $table) {
            if (mc_table_exists($table)) {
                $db->prepare("DELETE FROM `$table` WHERE material_id=?")->execute([$id]);
            }
        }
        if (mc_table_exists('mc_activity_logs')) {
            $db->prepare("DELETE FROM mc_activity_logs WHERE entity_type='material' AND entity_id=?")->execute([$id]);
        }
        if (mc_table_exists('mc_operation_logs')) {
            $db->prepare("DELETE FROM mc_operation_logs WHERE object_type='material' AND object_id=?")->execute([$id]);
        }
        $db->prepare('DELETE FROM mc_materials WHERE id=?')->execute([$id]);
    }
}
