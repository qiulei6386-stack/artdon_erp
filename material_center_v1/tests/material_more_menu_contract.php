<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$workspace = file_get_contents($root . '/components/material_workspace.php');
$export = file_get_contents($root . '/api/v1/export.php');

foreach (['电源整理工作台', '功率档管理', '批量导入', '导出当前清单', '操作日志'] as $label) {
    if (!str_contains($workspace, $label)) {
        throw new RuntimeException("usable menu item missing: $label");
    }
}
foreach (['power_workbench.php?panel=fields', 'power_workbench.php?panel=mappings', 'power_workbench.php?tab=exception'] as $legacy) {
    if (str_contains($workspace, $legacy)) {
        throw new RuntimeException("legacy workbench item remains: $legacy");
    }
}
foreach (['include_sources', 'SourceSyncService', '旧 BOM（只读）'] as $marker) {
    if (!str_contains($export, $marker)) {
        throw new RuntimeException("source export missing: $marker");
    }
}
echo "Material more-menu business contract: OK\n";
