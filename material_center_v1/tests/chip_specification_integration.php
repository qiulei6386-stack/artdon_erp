<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/bootstrap.php';
require_once dirname(__DIR__, 2).'/commercial_center_v1/bootstrap.php';

use Artdon\CommercialCenter\Repositories\ConfigurationRepository;
use Artdon\CommercialCenter\Services\ConfigurationEngineService;
use Artdon\MaterialCenter\Services\AdaptationService;
use Artdon\MaterialCenter\Services\ChipSpecificationService;

$db = db();
$chip = new ChipSpecificationService($db);
$adaptation = new AdaptationService($db);
$stamp = date('YmdHis').'-'.bin2hex(random_bytes(3));
$materialId = 0;
$templateId = 0;
$productIds = [];
$syncLogFloor = (int) $db->query('SELECT COALESCE(MAX(id),0) FROM mc_chip_template_sync_logs')->fetchColumn();

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

try {
    $categoryId = (int) $db->query("SELECT id FROM mc_material_categories WHERE code='chip' LIMIT 1")->fetchColumn();
    $assert($categoryId > 0, 'chip material category is missing');
    $legacyId = (int) $db->query("SELECT n.id FROM naming_models n
        LEFT JOIN mc_products p ON p.legacy_table='naming_models' AND p.legacy_id=n.id
        WHERE p.id IS NULL AND n.website_deleted=0
        ORDER BY n.updated_at DESC,n.id DESC LIMIT 1")->fetchColumn();
    $assert($legacyId > 0, 'no unused naming product is available for quote bridge test');

    $db->prepare("INSERT INTO mc_materials
        (material_uuid,material_code,category_id,brand,model,name,unit,status,source,is_official,allow_bom,allow_quote,allow_customer_display,is_pilot,created_by,updated_by,created_at,updated_at)
        VALUES(?,?,?,?,?,?,'PCS','official','codex_test',1,1,1,1,1,1,1,NOW(),NOW())")
        ->execute([
            sprintf('%08s-%04s-4%03s-a%03s-%012s', substr(md5($stamp), 0, 8), substr(md5($stamp), 8, 4), substr(md5($stamp), 12, 3), substr(md5($stamp), 15, 3), substr(md5($stamp), 18, 12)),
            'CODEX-CHIP-'.$stamp,
            $categoryId,
            'CODEX',
            '1507-'.$stamp,
            '芯片规格集成测试',
        ]);
    $materialId = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO mc_material_metadata(material_id,spec_summary,source_type,lock_version) VALUES(?,'integration','manual',1)")
        ->execute([$materialId]);
    $db->prepare("INSERT INTO mc_material_chip(material_id,package_type,updated_at) VALUES(?,'1507',NOW())")
        ->execute([$materialId]);

    $template = $chip->saveTemplate([
        'template_name' => 'CODEX 规格模板 '.$stamp,
        'selection' => ['cct' => [3000, 4000], 'cri' => [90], 'sdcm' => [3]],
        'combinations' => [
            ['cct_k' => 3000, 'cri' => 90, 'sdcm' => 3],
            ['cct_k' => 4000, 'cri' => 90, 'sdcm' => 3],
        ],
        'change_note' => 'integration v1',
    ], 1);
    $templateId = (int) $template['template_id'];
    $assert($template['version_no'] === 1 && $template['combination_count'] === 2, 'template v1 was not created');

    $version2 = $chip->saveTemplate([
        'template_id' => $templateId,
        'template_name' => 'CODEX 规格模板 '.$stamp,
        'selection' => ['cct' => [3000, 4000], 'cri' => [90], 'sdcm' => [3]],
        'combinations' => [
            ['cct_k' => 3000, 'cri' => 90, 'sdcm' => 3],
            ['cct_k' => 4000, 'cri' => 90, 'sdcm' => 3],
        ],
        'change_note' => 'integration v2',
    ], 1);
    $assert($version2['version_no'] === 2, 'template edit did not create a new version');
    $assert((int) $db->query("SELECT COUNT(*) FROM mc_chip_spec_variants WHERE material_id={$materialId}")->fetchColumn() === 0, 'template edit silently mutated a chip');

    $preview = $chip->previewApply([$templateId], [$materialId], 'fill_missing');
    $assert($preview['create_count'] === 2 && $preview['combination_count'] === 2, 'template preview count mismatch');
    $apply = $chip->applyTemplates([$templateId], [$materialId], 'fill_missing', 1);
    $assert($apply['created'] === 2, 'template application did not create both variants');
    $variants = $chip->variants($materialId);
    $assert(count($variants) === 2, 'chip variant count mismatch');

    $snapshot = json_encode(['series_name' => 'CODEX-'.$stamp], JSON_UNESCAPED_UNICODE);
    $db->prepare("INSERT INTO mc_products
        (legacy_table,legacy_id,product_code,product_name,snapshot_json,snapshot_hash,synced_at,status)
        VALUES('naming_models',?,?,?, ?,?,NOW(),'active')")
        ->execute([$legacyId, 'CODEX-PRODUCT-'.$stamp, '芯片报价桥接测试', $snapshot, hash('sha256', $snapshot)]);
    $productId = (int) $db->lastInsertId();
    $productIds[] = $productId;
    $groupId = $adaptation->saveGroup([
        'product_id' => $productId,
        'group_name' => '芯片 / 光源',
        'business_type' => 'chip',
        'selection_mode' => 'single',
        'is_required' => 1,
        'sort_order' => 10,
    ], 1);
    $optionId = $adaptation->saveOption([
        'group_id' => $groupId,
        'material_id' => $materialId,
        'option_type' => 'required',
        'is_default' => 1,
        'sort_order' => 10,
    ], 1);
    $optionSpecs = $chip->optionVariants($optionId);
    $selected = array_values(array_filter($optionSpecs['variants'], static fn(array $row): bool => (bool) $row['is_selected']));
    $assert(count($selected) === 2, 'new chip option did not inherit all active chip capabilities');
    $defaultId = (int) $selected[0]['id'];
    $chip->saveOptionVariants($optionId, array_map('intval', array_column($selected, 'id')), $defaultId, 1);

    $overview = $adaptation->configurationOverview($productId);
    $assert(count($overview) === 1 && count($overview[0]['options'][0]['chip_variants']) === 2, 'product configuration overview omits chip variants');
    $completion = $adaptation->completion($productId);
    $assert(!array_filter($completion['issues'], static fn(string $issue): bool => str_contains($issue, '尚未选择具体')), 'saved chip variants still fail the concrete-spec check');

    $db->prepare("UPDATE mc_adaptation_groups SET status='approved',is_enabled=1 WHERE id=?")->execute([$groupId]);
    $db->prepare("UPDATE mc_adaptation_options SET status='approved' WHERE id=?")->execute([$optionId]);
    $catalog = (new ConfigurationRepository())->materialCenterAdaptations();
    $groupCode = 'mc_'.$db->query("SELECT group_code FROM mc_adaptation_groups WHERE id={$groupId}")->fetchColumn();
    $commercialGroup = null;
    foreach ($catalog[(string) $legacyId] ?? [] as $row) if ($row['group_code'] === $groupCode) $commercialGroup = $row;
    $assert($commercialGroup !== null, 'commercial center did not load the approved adaptation group');
    $assert(count($commercialGroup['options']) === 2, 'commercial center did not expand concrete chip variants');
    $assert(!empty($commercialGroup['options'][0]['chip_variant']['label']), 'commercial chip option snapshot is missing');

    $defaultOption = null;
    foreach ($commercialGroup['options'] as $option) if ((int) $option['is_default']) $defaultOption = $option;
    $assert($defaultOption !== null, 'commercial chip variants have no default');
    $evaluation = (new ConfigurationEngineService())->evaluate([
        'product_key' => 'standard:'.$legacyId,
        'values' => [$groupCode => $defaultOption['option_code']],
        'mode' => 'professional',
    ], 1);
    $selectedMaterials = $evaluation['adaptation']['selected_materials'] ?? [];
    $assert(!empty($selectedMaterials[0]['chip_variant']['variant_id']), 'quote passport omitted the selected concrete chip variant');
    $assert(str_contains($evaluation['summary'], '3000K') || str_contains($evaluation['summary'], '4000K'), 'quote summary omitted chip specification text');

    echo "Chip specification templates, adaptation and quote bridge integration: OK\n";
} finally {
    foreach ($productIds as $productId) {
        $groupIds = $db->query("SELECT id FROM mc_adaptation_groups WHERE product_id=".(int) $productId)->fetchAll(PDO::FETCH_COLUMN);
        foreach ($groupIds as $groupId) {
            $optionIds = $db->query("SELECT id FROM mc_adaptation_options WHERE group_id=".(int) $groupId)->fetchAll(PDO::FETCH_COLUMN);
            foreach ($optionIds as $optionId) {
                $db->prepare('DELETE FROM mc_adaptation_option_chip_variants WHERE option_id=?')->execute([$optionId]);
                $db->prepare('DELETE FROM mc_adaptation_conditions WHERE option_id=?')->execute([$optionId]);
                $db->prepare('DELETE FROM mc_adaptation_defaults WHERE option_id=?')->execute([$optionId]);
            }
            $db->prepare('DELETE FROM mc_adaptation_options WHERE group_id=?')->execute([$groupId]);
        }
        $db->prepare('DELETE FROM mc_adaptation_groups WHERE product_id=?')->execute([$productId]);
        $db->prepare('DELETE FROM mc_adaptation_logs WHERE product_id=?')->execute([$productId]);
        $db->prepare('DELETE FROM mc_products WHERE id=?')->execute([$productId]);
    }
    if ($materialId) {
        $db->prepare('DELETE FROM mc_chip_material_templates WHERE material_id=?')->execute([$materialId]);
        $db->prepare('DELETE FROM mc_chip_spec_variants WHERE material_id=?')->execute([$materialId]);
        $db->prepare('DELETE FROM mc_material_chip WHERE material_id=?')->execute([$materialId]);
        $db->prepare('DELETE FROM mc_material_metadata WHERE material_id=?')->execute([$materialId]);
        $db->prepare('DELETE FROM mc_materials WHERE id=?')->execute([$materialId]);
    }
    if ($templateId) {
        $db->prepare('DELETE FROM mc_chip_template_sync_logs WHERE id>?')->execute([$syncLogFloor]);
        $db->prepare('DELETE FROM mc_chip_spec_templates WHERE id=?')->execute([$templateId]);
    }
}
