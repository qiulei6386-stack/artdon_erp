<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$mc = $root . '/material_center_v1';
$v2 = $mc . '/adaptation_v2';

function pa2_assert_true(bool $cond, string $message): void
{
    if (!$cond) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
    echo "[PASS] {$message}\n";
}

function pa2_file(string $path): string
{
    if (!is_file($path)) {
        fwrite(STDERR, "[FAIL] Missing file: {$path}\n");
        exit(1);
    }
    return (string)file_get_contents($path);
}

$index = pa2_file($v2 . '/index.php');
$api = pa2_file($v2 . '/api/index.php');
$foundation = pa2_file($v2 . '/lib/foundation.php');
$migrationRunner = pa2_file($v2 . '/lib/migration_runner.php');
$tool = pa2_file($v2 . '/tools/migrate.php');
$migration = pa2_file($v2 . '/database/migrations/20260801_001_phase2_foundation.php');
$doc = pa2_file($v2 . '/docs/02_FOUNDATION_MODEL.md');
$execution = pa2_file($v2 . '/docs/EXECUTION_LOG.md');
$bootstrap = pa2_file($mc . '/bootstrap.php');

pa2_assert_true(str_contains($index, "data-phase=\"2\""), 'V2 index declares phase 2');
pa2_assert_true(str_contains($index, '产品分类中心') && str_contains($index, '配置组定义中心'), 'Phase 2 pages expose category and group centers');
pa2_assert_true(str_contains($index, 'adaptation/index.php') && !str_contains($index, 'sidebar.php'), 'V2 page links back to legacy but does not edit legacy sidebar/menu');

$requiredTables = [
    'mc_pa2_product_categories',
    'mc_pa2_product_category_mappings',
    'mc_pa2_group_definitions',
    'mc_pa2_group_option_definitions',
];
foreach ($requiredTables as $table) {
    pa2_assert_true(str_contains($migration, $table), "Migration contains {$table}");
}
pa2_assert_true(!preg_match('/CREATE\s+TABLE\s+(?!IF\s+NOT\s+EXISTS\s+)?`?(mc_(?!pa2_|schema_migrations)[a-z0-9_]+)/i', $migration), 'Phase 2 migration only creates mc_pa2 tables');
pa2_assert_true(str_contains($migration, 'adaptation_v2.view') && str_contains($migration, 'crm_permissions'), 'V2 permissions are inserted into unified permission center');
pa2_assert_true(str_contains($migration, '导轨灯') && str_contains($migration, '嵌入式') && str_contains($migration, '磁吸式'), 'Seed product categories are present');
pa2_assert_true(str_contains($migration, '芯片 / 光源') && str_contains($migration, '电源 / 驱动') && str_contains($migration, '特殊要求'), 'Seed group definitions are present');

pa2_assert_true(str_contains($api, 'category_save') && str_contains($api, 'group_save') && str_contains($api, 'product_map_save'), 'API supports phase 2 save actions');
pa2_assert_true(str_contains($foundation, 'pa2_require_any') && str_contains($foundation, 'adaptation_v2.manage_category') && str_contains($foundation, 'adaptation_v2.manage_group_definition'), 'API write paths enforce server permissions');
pa2_assert_true(str_contains($foundation, 'mc_operation_logs') && str_contains($foundation, "module,object_type"), 'Phase 2 writes operation logs through existing log table');
pa2_assert_true(str_contains($migrationRunner, 'mc_pa2_schema_migrations') && str_contains($tool, 'Pa2MigrationRunner'), 'V2 has an independent migration runner and ledger');
pa2_assert_true(str_contains($bootstrap, '/material_center_v1/adaptation_v2/api/'), 'V2 API is recognized as JSON API by material center bootstrap');

pa2_assert_true(str_contains($doc, '不修改旧版') && str_contains($doc, 'mc_pa2_') && str_contains($doc, '不切换正式菜单'), 'Phase 2 document records boundaries');
pa2_assert_true(str_contains($execution, '第 1 阶段'), 'Execution log preserves phase 1 history');

foreach ([
    $mc . '/adaptation/index.php',
    $mc . '/api/v1/adaptation.php',
    $mc . '/app/Services/AdaptationService.php',
] as $legacyFile) {
    pa2_assert_true(is_file($legacyFile), 'Legacy adaptation file still exists: ' . basename($legacyFile));
}

echo "adaptation v2 phase 2 contract passed.\n";
