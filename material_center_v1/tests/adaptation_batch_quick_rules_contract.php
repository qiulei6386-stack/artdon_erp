<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$page = file_get_contents($root.'/adaptation/index.php');
$script = file_get_contents($root.'/assets/js/adaptation-shell.js');
$service = file_get_contents($root.'/app/Services/AdaptationService.php');
$api = file_get_contents($root.'/api/v1/adaptation.php');
$migration = file_get_contents($root.'/database/migrations/20260727_017_adaptation_quick_rules_batch.php');

foreach ([
    '批量套用', '只补空白（推荐）', '覆盖同名配置组', '同时套用电源范围',
    '选择同系列', '全选当前结果', '预览影响', 'data-adaptation-tab="quick_rules"',
] as $label) {
    if (!str_contains($page, $label)) throw new RuntimeException("batch adaptation UI missing: {$label}");
}

foreach ([
    'batchSelected: new Set()', 'openBatch', 'visibleBatchProducts', 'previewBatch',
    "'preview_batch'", "'batch_apply'", "'save_quick_rules'", '一次最多选择 1000 个产品',
    'data-quick-rule-form', '候选物料会立即按新范围筛选', 'index += 100',
] as $marker) {
    if (!str_contains($script, $marker)) throw new RuntimeException("batch adaptation interaction missing: {$marker}");
}

foreach ([
    'QUICK_RULE_FIELDS', "'honeycomb'", "'glass'", 'saveQuickRules', 'componentCandidateMatch',
    'previewBatchApply', 'batchApply', 'copyProductConfiguration', 'copyPowerRule',
    '一次最多处理 1000 个目标产品', 'LIMIT 2000', "match_level='incompatible'",
    '不能用例外审批绕过', 'allow_with_glass', 'allow_with_honeycomb',
    '当前产品设置为蜂巢网与玻璃不能同时安装',
] as $marker) {
    if (!str_contains($service, $marker)) throw new RuntimeException("batch adaptation service missing: {$marker}");
}

foreach ([
    "'save_quick_rules'", "'preview_batch'", "'batch_apply'", 'material_center.power.rules.manage',
] as $marker) {
    if (!str_contains($api, $marker)) throw new RuntimeException("batch adaptation API guard missing: {$marker}");
}

foreach ([
    'rule_json JSON', 'accessory.diameter_mm', 'accessory.thickness_mm',
    'mc_material_accessory.diameter_mm', 'use_for_adaptation=1', "'down'",
] as $marker) {
    if (!str_contains($migration, $marker)) throw new RuntimeException("batch adaptation migration missing: {$marker}");
}

echo "Product adaptation batch and quick rules contract: OK\n";
