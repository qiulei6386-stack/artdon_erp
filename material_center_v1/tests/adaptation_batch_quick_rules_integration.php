<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/bootstrap.php';

use Artdon\MaterialCenter\Services\AdaptationService;

$db = db();
$service = new AdaptationService($db);
$stamp = date('YmdHis').'-'.bin2hex(random_bytes(3));
$productIds = [];

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

try {
    $insertProduct = $db->prepare("INSERT INTO mc_products
        (legacy_table,legacy_id,product_code,product_name,snapshot_json,snapshot_hash,synced_at,status)
        VALUES('codex_adaptation_test',?,?,?,?,?,NOW(),'active')");
    foreach (['SOURCE', 'TARGET', 'GROUP'] as $index => $suffix) {
        $snapshot = json_encode(['series_name' => 'CODEX-BATCH-'.$stamp], JSON_UNESCAPED_UNICODE);
        $insertProduct->execute([
            990000000 + random_int(1, 999999) + $index,
            'CODEX-'.$suffix.'-'.$stamp,
            '批量适配集成测试'.$suffix,
            $snapshot,
            hash('sha256', $snapshot),
        ]);
        $productIds[] = (int) $db->lastInsertId();
    }
    [$sourceId, $targetId, $groupTestProductId] = $productIds;

    $mappedGroupId = $service->saveGroup([
        'product_id' => $groupTestProductId,
        'group_name' => '芯片 / 光源',
        'business_type' => 'chip',
        'material_category_code' => 'accessory',
        'selection_mode' => 'single',
        'is_required' => 1,
    ], 1);
    $mappedCategory = $db->prepare('SELECT material_category_code FROM mc_adaptation_groups WHERE id=?');
    $mappedCategory->execute([$mappedGroupId]);
    $assert($mappedCategory->fetchColumn() === 'chip', 'standard group purpose must enforce the matching material category');

    $selectiveTemplate = $service->initializeGroups($groupTestProductId, 1, ['installation']);
    $assert($selectiveTemplate['total'] === 1 && $selectiveTemplate['created'] === 1, 'selective standard template did not create exactly one group');
    $templateCount = $db->prepare("SELECT COUNT(*) FROM mc_adaptation_groups WHERE product_id=? AND template_key IN('installation','power_driver')");
    $templateCount->execute([$groupTestProductId]);
    $assert((int) $templateCount->fetchColumn() === 1, 'unselected standard template group was unexpectedly created');
    $batchTemplate = $service->batchInitializeGroups([$targetId], ['finish_color'], 1);
    $assert($batchTemplate['succeeded'] === 1 && $batchTemplate['created'] === 1, 'batch standard template generation failed');

    $db->prepare("INSERT INTO mc_adaptation_groups
        (product_id,group_code,group_name,group_type,business_type,material_category_code,is_required,
        selection_mode,min_select,max_select,template_key,rule_json,status,is_enabled,sort_order,
        created_by,updated_by,created_at,updated_at)
        VALUES(?,'honeycomb','蜂巢网','accessory','honeycomb','accessory',0,
        'single',0,1,'honeycomb',NULL,'draft',0,10,1,1,NOW(),NOW())")
        ->execute([$sourceId]);
    $sourceGroupId = (int) $db->lastInsertId();

    $saved = $service->saveQuickRules($sourceGroupId, [
        'availability' => 'allowed',
        'diameter_min_mm' => 49,
        'diameter_max_mm' => 51,
        'thickness_max_mm' => 4,
        'allow_with_glass' => 'no',
    ], 1);
    $assert($saved['saved'] === 4, 'quick rule saved-field count mismatch');

    $service->initializeGroups($sourceId, 1, ['installation']);
    $preview = $service->previewBatchApply($sourceId, [$targetId], 'fill_missing', false, [$sourceGroupId]);
    $assert($preview['targets'] === 1, 'batch preview target count mismatch');
    $assert($preview['groups']['source'] === 1, 'selected source-group count mismatch');
    $assert($preview['groups']['created'] === 1, 'batch preview created-group count mismatch');

    $result = $service->batchApply($sourceId, [$targetId], 'fill_missing', false, 1, [$sourceGroupId]);
    $assert($result['succeeded'] === 1 && $result['failed'] === 0, 'batch fill-missing execution failed');
    $unexpectedGroup = $db->prepare("SELECT COUNT(*) FROM mc_adaptation_groups WHERE product_id=? AND group_code='installation'");
    $unexpectedGroup->execute([$targetId]);
    $assert((int) $unexpectedGroup->fetchColumn() === 0, 'unselected source group was copied');
    $target = $db->prepare("SELECT rule_json,status,is_enabled FROM mc_adaptation_groups WHERE product_id=? AND group_code='honeycomb'");
    $target->execute([$targetId]);
    $targetGroup = $target->fetch(PDO::FETCH_ASSOC);
    $targetRules = json_decode((string) ($targetGroup['rule_json'] ?? '{}'), true) ?: [];
    $assert((float) ($targetRules['diameter_min_mm'] ?? 0) === 49.0, 'quick rules were not copied');
    $assert(($targetRules['allow_with_glass'] ?? '') === 'no', 'combination rule was not copied');
    $assert(($targetGroup['status'] ?? '') === 'draft' && (int) ($targetGroup['is_enabled'] ?? 1) === 0, 'target must require reapproval');

    $service->saveQuickRules($sourceGroupId, [
        'availability' => 'forbidden',
        'allow_with_glass' => 'no',
    ], 1);
    $replace = $service->batchApply($sourceId, [$targetId], 'replace_matching', false, 1, [$sourceGroupId]);
    $assert($replace['groups_overwritten'] === 1, 'replace-matching did not overwrite the target group');
    $target->execute([$targetId]);
    $replacedRules = json_decode((string) ($target->fetchColumn() ?: '{}'), true) ?: [];
    $assert(($replacedRules['availability'] ?? '') === 'forbidden', 'replacement quick rules were not copied');

    echo "Product adaptation batch and quick rules integration: OK\n";
} finally {
    if ($productIds) {
        $marks = implode(',', array_fill(0, count($productIds), '?'));
        $groupStmt = $db->prepare("SELECT id FROM mc_adaptation_groups WHERE product_id IN($marks)");
        $groupStmt->execute($productIds);
        $groupIds = array_map('intval', $groupStmt->fetchAll(PDO::FETCH_COLUMN));
        if ($groupIds) {
            $groupMarks = implode(',', array_fill(0, count($groupIds), '?'));
            $optionStmt = $db->prepare("SELECT id FROM mc_adaptation_options WHERE group_id IN($groupMarks)");
            $optionStmt->execute($groupIds);
            $optionIds = array_map('intval', $optionStmt->fetchAll(PDO::FETCH_COLUMN));
            if ($optionIds) {
                $optionMarks = implode(',', array_fill(0, count($optionIds), '?'));
                $db->prepare("DELETE FROM mc_adaptation_conditions WHERE option_id IN($optionMarks)")->execute($optionIds);
                $db->prepare("DELETE FROM mc_adaptation_defaults WHERE option_id IN($optionMarks)")->execute($optionIds);
                $db->prepare("DELETE FROM mc_adaptation_options WHERE id IN($optionMarks)")->execute($optionIds);
            }
            $db->prepare("DELETE FROM mc_adaptation_groups WHERE id IN($groupMarks)")->execute($groupIds);
        }
        $db->prepare("DELETE FROM mc_adaptation_conflicts WHERE product_id IN($marks)")->execute($productIds);
        $db->prepare("DELETE FROM mc_adaptation_logs WHERE product_id IN($marks)")->execute($productIds);
        $db->prepare("DELETE FROM mc_products WHERE id IN($marks)")->execute($productIds);
    }
}
