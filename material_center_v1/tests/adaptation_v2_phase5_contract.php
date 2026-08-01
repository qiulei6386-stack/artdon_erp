<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$mc = $root . '/material_center_v1';
$v2 = $mc . '/adaptation_v2';

function pa5_assert_true(bool $cond, string $message): void
{
    if (!$cond) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
    echo "[PASS] {$message}\n";
}

function pa5_file(string $path): string
{
    if (!is_file($path)) {
        fwrite(STDERR, "[FAIL] Missing file: {$path}\n");
        exit(1);
    }
    return (string)file_get_contents($path);
}

$index = pa5_file($v2 . '/index.php');
$api = pa5_file($v2 . '/api/index.php');
$foundation = pa5_file($v2 . '/lib/foundation.php');
$migration = pa5_file($v2 . '/database/migrations/20260801_004_phase5_workspace.php');
$doc = pa5_file($v2 . '/docs/05_PRODUCT_WORKSPACE.md');
$execution = pa5_file($v2 . '/docs/EXECUTION_LOG.md');

foreach (['mc_pa2_product_configs','mc_pa2_product_config_versions','mc_pa2_product_group_configs','mc_pa2_product_selected_options'] as $table) {
    pa5_assert_true(str_contains($migration, $table), "Phase 5 migration contains {$table}");
}
pa5_assert_true(!preg_match('/CREATE\s+TABLE\s+(?!IF\s+NOT\s+EXISTS\s+)?`?(mc_(?!pa2_|schema_migrations)[a-z0-9_]+)/i', $migration), 'Phase 5 migration only creates mc_pa2 tables');

foreach (['pa2_workspace_tables_ready','pa2_fetch_product','pa2_template_for_product','pa2_prepare_workspace','pa2_workspace_detail','pa2_material_candidates','pa2_save_product_group_selection','pa2_workspace_check_summary'] as $fn) {
    pa5_assert_true(str_contains($foundation, 'function ' . $fn), "Foundation implements {$fn}");
}
pa5_assert_true(str_contains($foundation, 'pa2_template_effective_groups'), 'Workspace is template inheritance driven');
pa5_assert_true(str_contains($foundation, 'mc_materials') && str_contains($foundation, 'mc_material_categories'), 'Candidate materials are read from existing material master');
pa5_assert_true(str_contains($foundation, 'check_summary_json'), 'Workspace writes check summary');

foreach (['workspace','workspace_prepare','product_group_save','material_candidates'] as $action) {
    pa5_assert_true(str_contains($api, $action), "API supports {$action}");
}
pa5_assert_true(str_contains($api, "'phase' => 5"), 'API status declares phase 5');

pa5_assert_true(str_contains($index, 'data-phase="5"'), 'V2 index declares phase 5');
pa5_assert_true(str_contains($index, "\$view === 'workspace'") && str_contains($index, '单产品配置工作台'), 'Workspace route is implemented');
pa5_assert_true(str_contains($index, 'pa2-work-grid') && str_contains($index, 'pa2-config-card'), 'Workspace uses one-screen card grid');
pa5_assert_true(str_contains($index, 'pa2-material-dialog') && str_contains($index, 'data-open-material-picker'), 'Workspace has wide material picker dialog');
pa5_assert_true(str_contains($index, '需要补充') && str_contains($index, '检查配置'), 'Workspace shows missing summary and check action');
pa5_assert_true(!str_contains($index, '空白右栏'), 'Workspace does not intentionally keep blank right column');

pa5_assert_true(str_contains($doc, '默认流程') && str_contains($doc, 'mc_pa2_product_configs'), 'Phase 5 document records workspace model');
pa5_assert_true(str_contains($execution, '第 5 阶段：单产品配置工作台'), 'Execution log records phase 5');

foreach ([
    $mc . '/adaptation/index.php',
    $mc . '/api/v1/adaptation.php',
    $mc . '/app/Services/AdaptationService.php',
] as $legacyFile) {
    pa5_assert_true(is_file($legacyFile), 'Legacy adaptation file still exists: ' . basename($legacyFile));
}

echo "adaptation v2 phase 5 contract passed.\n";
