<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$layout = file_get_contents($root.'/components/layout_bottom.php');
$script = file_get_contents($root.'/assets/js/power-editor.js');
$service = file_get_contents($root.'/app/Services/PowerEditorService.php');
$api = file_get_contents($root.'/api/v1/power-editor.php');

foreach ([
    '基本资料', '功率与输入', '输出', '安装与尺寸', '调光与认证', '采购与供应商质保',
    'data-current-list', 'data-dimming-choices', 'data-power-batch-cards',
] as $marker) {
    if (!str_contains($layout, $marker)) {
        throw new RuntimeException("power drawer section missing: {$marker}");
    }
}
foreach ([
    'openMaterial', 'openSource', 'collectBatchChanges', 'batch_preview', 'batch_execute', 'rollback',
    '有未保存修改', '只修改你明确启用的项目', '正在转正式…', "event.stopPropagation();",
] as $marker) {
    if (!str_contains($script.$layout, $marker)) {
        throw new RuntimeException("power interaction missing: {$marker}");
    }
}
if (str_contains($script, "confirm('确认字段无误并将电源转为正式")) {
    throw new RuntimeException('power approval must immediately call the lifecycle API instead of relying on a native confirm dialog');
}
foreach ([
    'mc_power_supply_current_options', 'mc_power_supply_dimming_modes', 'mc_batch_jobs',
    'beginTransaction', 'rollBack', 'lock_version', 'material_center.field.sensitive',
] as $marker) {
    if (!str_contains($service, $marker)) {
        throw new RuntimeException("power service safety missing: {$marker}");
    }
}
foreach (['material_center.view', 'material_center.material.edit', 'material_center.material.batch', 'verify_csrf'] as $marker) {
    if (!str_contains($api, $marker)) {
        throw new RuntimeException("power API guard missing: {$marker}");
    }
}
if (str_contains($layout, '<table') && str_contains($layout, 'data-power-batch')) {
    throw new RuntimeException('power batch drawer must not be a table editor');
}

echo "Power unified editor contract test passed.\n";
