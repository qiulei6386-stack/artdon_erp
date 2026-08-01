<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$mc = $root . '/material_center_v1';
$v2 = $mc . '/adaptation_v2';

function pa6_assert_true(bool $cond, string $message): void
{
    if (!$cond) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
    echo "[PASS] {$message}\n";
}

function pa6_file(string $path): string
{
    if (!is_file($path)) {
        fwrite(STDERR, "[FAIL] Missing file: {$path}\n");
        exit(1);
    }
    return (string)file_get_contents($path);
}

$index = pa6_file($v2 . '/index.php');
$api = pa6_file($v2 . '/api/index.php');
$foundation = pa6_file($v2 . '/lib/foundation.php');
$migration = pa6_file($v2 . '/database/migrations/20260801_005_phase6_engine.php');
$doc = pa6_file($v2 . '/docs/06_ADAPTATION_ENGINE.md');
$execution = pa6_file($v2 . '/docs/EXECUTION_LOG.md');

foreach (['mc_pa2_adaptation_result_cache','mc_pa2_adaptation_conflicts','mc_pa2_adaptation_recalc_jobs'] as $table) {
    pa6_assert_true(str_contains($migration, $table), "Phase 6 migration contains {$table}");
}
pa6_assert_true(!preg_match('/CREATE\s+TABLE\s+(?!IF\s+NOT\s+EXISTS\s+)?`?(mc_(?!pa2_|schema_migrations)[a-z0-9_]+)/i', $migration), 'Phase 6 migration only creates mc_pa2 tables');

foreach ([
    'pa2_engine_tables_ready',
    'pa2_material_detail',
    'pa2_extract_product_technical_range',
    'pa2_candidate_status_for_group',
    'pa2_calculate_workspace',
    'pa2_recalculate_workspace',
    'pa2_cached_results_for_version',
    'pa2_evaluate_material_candidate_for_group',
] as $fn) {
    pa6_assert_true(str_contains($foundation, 'function ' . $fn), "Foundation implements {$fn}");
}

foreach (['mc_power_supply_specs','mc_material_chip','mc_material_optical','mc_material_connector','mc_material_accessory'] as $table) {
    pa6_assert_true(str_contains($foundation, $table), "Engine reads {$table}");
}
foreach (['full_match','conditional_match','approval_required','incompatible'] as $status) {
    pa6_assert_true(str_contains($foundation, $status), "Engine emits {$status}");
    pa6_assert_true(str_contains($index, $status) || str_contains($index, ['full_match'=>'完全适配','conditional_match'=>'条件适配','approval_required'=>'需要审批','incompatible'=>'不适配'][$status]), "UI displays {$status}");
}
foreach (['match_score','reason_json','conflict_fields_json','rule_trace_json','calculated_hash'] as $field) {
    pa6_assert_true(str_contains($migration . $foundation, $field), "Engine persists {$field}");
}

foreach (['workspace_recalculate','adaptation_results','material_candidates'] as $action) {
    pa6_assert_true(str_contains($api, $action), "API supports {$action}");
}
pa6_assert_true(str_contains($api, "'phase' => 6"), 'API status declares phase 6');

pa6_assert_true(str_contains($index, 'data-phase="6"'), 'V2 index declares phase 6');
pa6_assert_true(str_contains($index, 'pa2-engine-summary') && str_contains($index, '重新计算'), 'Workspace exposes recalculation summary and action');
pa6_assert_true(str_contains($index, 'statusLabel') && str_contains($index, 'statusClass'), 'Candidate dialog maps engine status to Chinese labels and badges');
pa6_assert_true(str_contains($index, "url.searchParams.set('product_id'") && str_contains($index, "url.searchParams.set('product_group_config_id'"), 'Candidate dialog requests context-aware fit results');

pa6_assert_true(str_contains($doc, '适配计算和冲突引擎') && str_contains($doc, 'mc_pa2_adaptation_result_cache'), 'Phase 6 document records engine model');
pa6_assert_true(str_contains($execution, '第 6 阶段：适配计算和冲突引擎'), 'Execution log records phase 6');

foreach ([
    $mc . '/adaptation/index.php',
    $mc . '/api/v1/adaptation.php',
    $mc . '/app/Services/AdaptationService.php',
] as $legacyFile) {
    pa6_assert_true(is_file($legacyFile), 'Legacy adaptation file still exists: ' . basename($legacyFile));
}

echo "adaptation v2 phase 6 contract passed.\n";
