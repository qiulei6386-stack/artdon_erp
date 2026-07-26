<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$workspace = file_get_contents($root . '/components/material_workspace.php');
$layout = file_get_contents($root . '/components/layout_bottom.php');
$export = file_get_contents($root . '/api/v1/export.php');

foreach (['进入电源整理', '>导入</a>', '>导出</a>', '>日志</a>'] as $label) {
    if (!str_contains($workspace, $label)) {
        throw new RuntimeException("explicit power action missing: $label");
    }
}
foreach (['电源整理工作台', '功率档管理', 'power_workbench.php?panel=fields', 'power_workbench.php?panel=mappings', 'power_workbench.php?tab=exception'] as $legacy) {
    if (str_contains($workspace, $legacy)) {
        throw new RuntimeException("legacy power more-menu item remains: $legacy");
    }
}
foreach (['data-power-editor', 'data-power-batch', 'data-power-save', 'data-power-batch-preview-button', '回滚本批次'] as $marker) {
    if (!str_contains($layout, $marker) && !str_contains(file_get_contents($root . '/assets/js/power-editor.js'), $marker)) {
        throw new RuntimeException("unified power drawer contract missing: $marker");
    }
}
foreach (['include_sources', 'SourceSyncService', '旧 BOM（只读）'] as $marker) {
    if (!str_contains($export, $marker)) {
        throw new RuntimeException("source export missing: $marker");
    }
}
echo "Power explicit-actions and unified-drawer contract: OK\n";
