<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$page = file_get_contents($root.'/adaptation/index.php');
$script = file_get_contents($root.'/assets/js/adaptation-shell.js');
$service = file_get_contents($root.'/app/Services/AdaptationService.php');
$api = file_get_contents($root.'/api/v1/adaptation.php');
$migration = file_get_contents($root.'/database/migrations/20260726_015_adaptation_workflow_v2.php');

foreach ([
    '产品列表', '配置规则', '选项详情', '生成标准配置', '从物料库添加选项',
    '选项列表', '默认设置', '替代关系', '适用条件', '价格 / 交期', '审批',
] as $label) {
    if (!str_contains($page, $label)) throw new RuntimeException("adaptation UI missing: {$label}");
}

foreach ([
    'data-product-id', 'loadWorkspace', 'data-group-id', 'draggable="true"', 'reorder_groups',
    'match_label', 'conflict_reasons', 'data-candidate-filter', 'material_choice',
    'data-condition-rows', 'boolean_connector', 'data-default-form', 'data-approve-product',
    'beforeunload', '当前修改尚未保存',
] as $marker) {
    if (!str_contains($script, $marker)) throw new RuntimeException("adaptation interaction missing: {$marker}");
}

foreach ([
    'STANDARD_GROUPS', 'assertMeaningfulName', 'candidateMaterials', 'candidateMatch',
    'completion', 'saveConditions', 'setDefault', 'deleteGroup', 'commercialGroupReferenced',
    "g.status='approved' AND g.is_enabled=1",
] as $marker) {
    if (!str_contains($service, $marker)) throw new RuntimeException("adaptation service guard missing: {$marker}");
}

foreach ([
    "'workspace'", "'candidates'", "'apply_template'", "'add_options'", "'set_default'",
    "'save_conditions'", "'reorder_groups'", "'delete_group'",
] as $marker) {
    if (!str_contains($api, $marker)) throw new RuntimeException("adaptation API missing: {$marker}");
}

foreach ([
    'business_type', 'material_category_code', 'selection_mode', 'is_enabled',
    'match_level', 'requires_approval', 'exception_approved', 'condition_group_no',
    'boolean_connector', "'down'",
] as $marker) {
    if (!str_contains($migration, $marker)) throw new RuntimeException("adaptation migration missing: {$marker}");
}

if (!str_contains($service, "preg_match('/^\\d+$/u'")) throw new RuntimeException('numeric-only group name guard missing');
if (substr_count($service, "['light_source',") !== 1) throw new RuntimeException('standard template must be declared exactly once');

echo "Product adaptation workflow v2 contract: OK\n";
