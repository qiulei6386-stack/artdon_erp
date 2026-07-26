<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Artdon\MaterialCenter\Services\PowerStandardizationService;

$db = db();
$service = new PowerStandardizationService($db);
$source = $db->query("SELECT r.* FROM mc_source_records r
    WHERE r.source_system='guangzhou_bom' AND r.source_table='bom_materials'
      AND r.matched_material_id IS NULL
      AND JSON_UNQUOTE(JSON_EXTRACT(r.snapshot_json,'$.category')) IN ('驱动','电源')
      AND NOT EXISTS(SELECT 1 FROM mc_material_import_staging s WHERE s.source_table='bom_materials' AND s.source_id=CAST(r.source_pk AS UNSIGNED))
    ORDER BY r.id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$source) {
    throw new RuntimeException('no untouched power source record available');
}

$stagingId = 0;
$materialId = 0;
$sourceMappingId = 0;
$original = [
    'parse_result_json' => $source['parse_result_json'],
    'confidence_score' => $source['confidence_score'],
    'status' => $source['status'],
    'matched_material_id' => $source['matched_material_id'],
    'confirmed_by' => $source['confirmed_by'],
    'confirmed_at' => $source['confirmed_at'],
];

try {
    $staged = $service->stageSourceRecord((int)$source['id'], 1);
    $stagingId = (int)$staged['staging_id'];
    if (!$stagingId || $staged['review_url'] !== 'power_standardization.php?review=' . $stagingId) {
        throw new RuntimeException('stage source response mismatch');
    }
    $detail = $service->detail($stagingId);
    if ((int)$detail['staging']['source_id'] !== (int)$source['source_pk'] || !$detail['parse_results']) {
        throw new RuntimeException('staged detail mismatch');
    }
    $bandId = (int)$db->query("SELECT id FROM mc_power_bands WHERE status='active' ORDER BY sort_order,id LIMIT 1")->fetchColumn();
    $materialId = $service->confirmAndCreate($stagingId, [
        'installation_type' => 'internal',
        'output_type' => 'constant_current',
        'power_band_id' => $bandId,
        'output_current_ma' => '500',
        'current_options_ma' => ['500', '700'],
        'dimming_modes' => ['none'],
        'supplier_warranty_years' => '3',
    ], 1);
    $material = $db->query('SELECT status,is_official,source FROM mc_materials WHERE id=' . $materialId)->fetch(PDO::FETCH_ASSOC);
    $mapped = $db->query('SELECT matched_material_id,status FROM mc_source_records WHERE id=' . (int)$source['id'])->fetch(PDO::FETCH_ASSOC);
    $sourceMapping = $db->query('SELECT id,status FROM mc_source_mappings WHERE source_record_id=' . (int)$source['id'] . ' AND material_id=' . $materialId)->fetch(PDO::FETCH_ASSOC);
    $sourceMappingId = (int)($sourceMapping['id'] ?? 0);
    if (!$material || $material['status'] !== 'draft' || (int)$material['is_official'] !== 0 || $material['source'] !== 'legacy_bom') {
        throw new RuntimeException('confirmed source did not create a safe draft');
    }
    if ((int)$mapped['matched_material_id'] !== $materialId || $mapped['status'] !== 'confirmed' || ($sourceMapping['status'] ?? '') !== 'confirmed') {
        throw new RuntimeException('source mapping was not confirmed');
    }
    if ((int)$db->query('SELECT COUNT(*) FROM mc_power_supply_current_options WHERE material_id=' . $materialId)->fetchColumn() !== 2) {
        throw new RuntimeException('confirmed current options missing');
    }
    echo "Power source standardization integration: OK source={$source['id']} staging=$stagingId material=$materialId\n";
} finally {
    if ($sourceMappingId) {
        $db->exec('DELETE FROM mc_source_mappings WHERE id=' . $sourceMappingId);
    }
    $restore = $db->prepare('UPDATE mc_source_records SET parse_result_json=?,confidence_score=?,status=?,matched_material_id=?,confirmed_by=?,confirmed_at=? WHERE id=?');
    $restore->execute([$original['parse_result_json'], $original['confidence_score'], $original['status'], $original['matched_material_id'], $original['confirmed_by'], $original['confirmed_at'], $source['id']]);
    if ($materialId) {
        $db->exec('DELETE FROM mc_power_supply_dimming_modes WHERE material_id=' . $materialId);
        $db->exec('DELETE FROM mc_power_supply_current_options WHERE material_id=' . $materialId);
        $db->exec('DELETE FROM mc_power_supply_specs WHERE material_id=' . $materialId);
        $db->exec('DELETE FROM mc_legacy_links WHERE material_id=' . $materialId);
        $db->exec("DELETE FROM mc_activity_logs WHERE entity_type='material' AND entity_id=" . $materialId);
        $db->exec('DELETE FROM mc_material_metadata WHERE material_id=' . $materialId);
        $db->exec('DELETE FROM mc_materials WHERE id=' . $materialId);
    }
    if ($stagingId) {
        $db->exec('DELETE FROM mc_duplicate_candidates WHERE staging_id=' . $stagingId);
        $db->exec('DELETE FROM mc_material_parse_results WHERE staging_id=' . $stagingId);
        $db->exec('DELETE FROM mc_material_import_staging WHERE id=' . $stagingId);
    }
    $db->exec("DELETE FROM mc_operation_logs WHERE object_type='source_record' AND object_id=" . (int)$source['id'] . " AND action='stage_power'");
}
