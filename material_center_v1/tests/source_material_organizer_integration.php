<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Artdon\MaterialCenter\Security\MaterialCenterUserContext;
use Artdon\MaterialCenter\Services\SourceMaterialOrganizerService;

$db = db();
$user = new MaterialCenterUserContext(1, 'codex_test', 'Codex Test', 'super_admin', true);
$service = new SourceMaterialOrganizerService($db);
$created = [];
$seed = (int) (date('ymdHis') . random_int(10, 99));
$cases = [
    'chip' => ['芯片', 'chip.package_type', 'COB'],
    'optical' => ['光学', 'optical.optical_type', '透镜'],
    'profile' => ['型材', 'profile.material_grade', '6063-T5'],
    'connector' => ['接头', 'connector.interface_type', '二线接口'],
    'accessory' => ['附件', 'accessory.accessory_type', '蜂巢网'],
    'packaging' => ['包装', 'packaging.packaging_type', '彩盒'],
];

try {
    foreach ($cases as $index => $case) {
        [$legacyCategory, $fieldCode, $fieldValue] = $case;
        $sourcePk = (string) ($seed + array_search($index, array_keys($cases), true));
        $snapshot = [
            'id' => (int) $sourcePk,
            'category' => $legacyCategory,
            'name' => "CODEX 来源整理 {$legacyCategory} {$sourcePk}",
            'brand' => 'CODEX',
            'model' => 'SRC-' . $sourcePk,
            'spec' => $legacyCategory === '芯片' ? 'COB 20W 500mA 3000K CRI90' : '20×30×40 mm',
            'unit' => 'PCS',
            'material_grade' => $legacyCategory === '型材' ? '6063-T5' : null,
        ];
        $snapshotJson = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $db->prepare(
            "INSERT INTO mc_source_records
             (source_system,source_table,source_pk,raw_text,snapshot_json,snapshot_hash,read_at,status)
             VALUES('guangzhou_bom','bom_materials',?,?,?,?,NOW(),'pending')"
        )->execute([$sourcePk, $snapshot['name'] . ' ' . $snapshot['spec'], $snapshotJson, hash('sha256', $snapshotJson)]);
        $sourceId = (int) $db->lastInsertId();
        $created[$sourceId] = ['material_id' => 0, 'category' => $index];

        $opened = $service->detail($sourceId, $index, $user);
        if (($opened['source']['source_id'] ?? '') !== $sourcePk || ($opened['defaults']['name'] ?? '') !== $snapshot['name']) {
            throw new RuntimeException("{$index} source detail failed");
        }
        $payload = [
            'name' => $snapshot['name'],
            'brand' => '人工品牌',
            'model' => $snapshot['model'],
            'unit' => 'PCS',
            'spec_summary' => $snapshot['spec'],
            'supplier_text' => '人工供应商',
            'remark' => '人工修正确认',
            'fields' => [$fieldCode => $fieldValue],
        ];
        $saved = $service->save($sourceId, $index, $payload, 'draft', $user);
        $materialId = (int) ($saved['material']['id'] ?? 0);
        $created[$sourceId]['material_id'] = $materialId;
        if (!$materialId || ($saved['material']['status'] ?? '') !== 'draft' || ($saved['values'][$fieldCode] ?? null) != $fieldValue) {
            throw new RuntimeException("{$index} source draft save failed");
        }

        $payload['lock_version'] = (int) $saved['material']['lock_version'];
        $second = $service->save($sourceId, $index, $payload, 'draft', $user);
        if ((int) $second['material']['id'] !== $materialId) {
            throw new RuntimeException("{$index} repeated organizing created another material");
        }
        $mappingCount = $db->prepare('SELECT COUNT(*) FROM mc_source_mappings WHERE source_record_id=?');
        $mappingCount->execute([$sourceId]);
        if ((int) $mappingCount->fetchColumn() !== 1) {
            throw new RuntimeException("{$index} source mapping is not unique");
        }

        $changedSnapshot = $snapshot;
        $changedSnapshot['spec'] .= ' 来源后来修改';
        $changedJson = json_encode($changedSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $db->prepare(
            "UPDATE mc_source_records SET snapshot_json=?,snapshot_hash=?,status='changed' WHERE id=?"
        )->execute([$changedJson, hash('sha256', $changedJson), $sourceId]);
        $changed = $service->detail($sourceId, $index, $user);
        if (empty($changed['source']['changed']) || ($changed['material']['brand'] ?? '') !== '人工品牌') {
            throw new RuntimeException("{$index} source change overwrote human correction or was not flagged");
        }
    }

    $firstSourceId = (int) array_key_first($created);
    $first = $service->detail($firstSourceId, 'chip', $user);
    $submitPayload = [
        'lock_version' => (int) $first['material']['lock_version'],
        'name' => $first['material']['name'],
        'brand' => $first['material']['brand'],
        'model' => $first['material']['model'],
        'unit' => $first['material']['unit'],
        'spec_summary' => $first['material']['spec_summary'],
        'supplier_text' => $first['material']['supplier_text'],
        'remark' => $first['material']['remark'],
        'fields' => $first['values'],
    ];
    $submitted = $service->save($firstSourceId, 'chip', $submitPayload, 'submit', $user);
    if (($submitted['material']['status'] ?? '') !== 'pending_review') {
        throw new RuntimeException('source draft submit failed');
    }
    $approved = $service->save($firstSourceId, 'chip', [], 'approve', $user);
    if (($approved['material']['status'] ?? '') !== 'official') {
        throw new RuntimeException('source approval failed');
    }

    echo "Source material organizer integration: six category drafts, unique mapping, reopen, change protection and lifecycle passed.\n";
} finally {
    foreach ($created as $sourceId => $item) {
        $materialId = (int) $item['material_id'];
        if ($materialId) {
            foreach ([
                'mc_material_chip', 'mc_material_optical', 'mc_material_profile',
                'mc_material_connector', 'mc_material_accessory', 'mc_material_packaging',
            ] as $table) {
                $db->prepare("DELETE FROM `$table` WHERE material_id=?")->execute([$materialId]);
            }
            $db->prepare('DELETE FROM mc_material_lifecycle_events WHERE material_id=?')->execute([$materialId]);
            $db->prepare("DELETE FROM mc_activity_logs WHERE entity_type='material' AND entity_id=?")->execute([$materialId]);
            $db->prepare("DELETE FROM mc_operation_logs WHERE (object_type='material' AND object_id=?) OR (object_type='source_record' AND object_id=?)")->execute([$materialId, $sourceId]);
            $db->prepare('DELETE FROM mc_source_mappings WHERE source_record_id=?')->execute([$sourceId]);
            $db->prepare('DELETE FROM mc_material_metadata WHERE material_id=?')->execute([$materialId]);
            $db->prepare('DELETE FROM mc_materials WHERE id=?')->execute([$materialId]);
        }
        $db->prepare('DELETE FROM mc_source_records WHERE id=?')->execute([$sourceId]);
    }
}
