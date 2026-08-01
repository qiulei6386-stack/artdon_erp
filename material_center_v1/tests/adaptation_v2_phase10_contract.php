<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$mc = $root . '/material_center_v1';
$v2 = $mc . '/adaptation_v2';

function pa10_assert_true(bool $cond, string $message): void
{
    if (!$cond) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
    echo "[PASS] {$message}\n";
}

function pa10_file(string $path): string
{
    if (!is_file($path)) {
        fwrite(STDERR, "[FAIL] Missing file: {$path}\n");
        exit(1);
    }
    return (string)file_get_contents($path);
}

$index = pa10_file($v2 . '/index.php');
$api = pa10_file($v2 . '/api/index.php');
$foundation = pa10_file($v2 . '/lib/foundation.php');
$migration = pa10_file($v2 . '/database/migrations/20260801_009_phase10_cutover_readiness.php');
$doc = pa10_file($v2 . '/docs/10_CUTOVER_READINESS.md');
$execution = pa10_file($v2 . '/docs/EXECUTION_LOG.md');
$context = pa10_file($root . '/WORK_CONTEXT.md');

foreach (['mc_pa2_cutover_audits','mc_pa2_cutover_check_items'] as $table) {
    pa10_assert_true(str_contains($migration, $table), "Phase 10 migration contains {$table}");
}
pa10_assert_true(!preg_match('/CREATE\s+TABLE\s+(?!IF\s+NOT\s+EXISTS\s+)?`?(mc_(?!pa2_|schema_migrations)[a-z0-9_]+)/i', $migration), 'Phase 10 migration only creates mc_pa2 tables');

foreach (['pa2_phase10_tables_ready','pa2_cutover_readiness','pa2_record_cutover_audit'] as $fn) {
    pa10_assert_true(str_contains($foundation, 'function ' . $fn), "Foundation implements {$fn}");
}

foreach (['legacy_business_untouched','old_bom_untouched','formal_menu_not_switched','published_packages_exist','real_business_regression_required'] as $marker) {
    pa10_assert_true(str_contains($foundation . $doc . $index, $marker), "Cutover check marker exists: {$marker}");
}

pa10_assert_true(str_contains($foundation . $index . $doc, '不得切换正式菜单') || str_contains($foundation . $index . $doc, '不切换正式菜单'), 'Cutover gate prevents menu switching');
pa10_assert_true(str_contains($foundation, "'ready_to_switch'") && str_contains($foundation, "'blocked'"), 'Cutover readiness reports blocked state');

foreach (['cutover_readiness','cutover_audit_record'] as $action) {
    pa10_assert_true(str_contains($api, $action), "API supports {$action}");
}
pa10_assert_true(str_contains($api, "'phase' => 10"), 'API status declares phase 10');

pa10_assert_true(str_contains($index, 'data-phase="10"'), 'V2 index declares phase 10');
pa10_assert_true(str_contains($index, '$view === \'cutover\'') && str_contains($index, '最终验收 / 切换评估') && str_contains($index, '禁止切换'), 'Cutover page exposes readiness gate');
pa10_assert_true(str_contains($index, 'adaptation_v2/docs/10_CUTOVER_READINESS.md'), 'Logs page links phase 10 document');

foreach ([
    $mc . '/adaptation/index.php',
    $mc . '/api/v1/adaptation.php',
    $mc . '/app/Services/AdaptationService.php',
    $root . '/bom.php',
] as $legacyFile) {
    pa10_assert_true(is_file($legacyFile), 'Legacy/old BOM file still exists: ' . basename($legacyFile));
}

pa10_assert_true(str_contains($context, '第 10 阶段最终验收和切换评估'), 'WORK_CONTEXT records phase 10');
pa10_assert_true(str_contains($execution, '第 10 阶段：最终验收和切换评估'), 'Execution log records phase 10');

echo "adaptation v2 phase 10 contract passed.\n";
