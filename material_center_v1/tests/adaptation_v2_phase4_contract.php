<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$mc = $root . '/material_center_v1';
$v2 = $mc . '/adaptation_v2';

function pa4_assert_true(bool $cond, string $message): void
{
    if (!$cond) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
    echo "[PASS] {$message}\n";
}

function pa4_file(string $path): string
{
    if (!is_file($path)) {
        fwrite(STDERR, "[FAIL] Missing file: {$path}\n");
        exit(1);
    }
    return (string)file_get_contents($path);
}

$index = pa4_file($v2 . '/index.php');
$api = pa4_file($v2 . '/api/index.php');
$foundation = pa4_file($v2 . '/lib/foundation.php');
$migration = pa4_file($v2 . '/database/migrations/20260801_003_phase4_group_rules.php');
$doc = pa4_file($v2 . '/docs/04_GROUP_RULE_EDITOR.md');
$execution = pa4_file($v2 . '/docs/EXECUTION_LOG.md');

foreach (['mc_pa2_group_behavior_settings','mc_pa2_rule_definitions'] as $table) {
    pa4_assert_true(str_contains($migration, $table), "Phase 4 migration contains {$table}");
}
pa4_assert_true(!preg_match('/CREATE\s+TABLE\s+(?!IF\s+NOT\s+EXISTS\s+)?`?(mc_(?!pa2_|schema_migrations)[a-z0-9_]+)/i', $migration), 'Phase 4 migration only creates mc_pa2 tables');
pa4_assert_true(str_contains($migration, "'track_system'") && str_contains($migration, "'intrack'") && str_contains($migration, "'standard_track'"), 'Track system group and options are seeded');

foreach ([
    'track_intrack_show_connector',
    'track_intrack_show_driver',
    'track_intrack_hide_standard_connector',
    'track_intrack_hide_standard_driver',
    'magnetic_short_filter_head',
] as $ruleCode) {
    pa4_assert_true(str_contains($migration, $ruleCode), "Seed rule exists: {$ruleCode}");
}

foreach (['material','attribute','hybrid','number','text'] as $kind) {
    pa4_assert_true(str_contains($migration, "'{$kind}'"), "Selection kind supported: {$kind}");
}
foreach (['show','hide','require','optional','material_filter','set_default','limit_options'] as $action) {
    pa4_assert_true(str_contains($foundation, "'{$action}'") || str_contains($migration, "'{$action}'"), "Rule action supported: {$action}");
}

foreach (['pa2_upsert_group_behavior','pa2_fetch_rules','pa2_upsert_rule','pa2_detect_rule_cycles','pa2_phase4_tables_ready'] as $fn) {
    pa4_assert_true(str_contains($foundation, 'function ' . $fn), "Foundation implements {$fn}");
}
pa4_assert_true(str_contains($foundation, 'throw new RuntimeException') && str_contains($foundation, '循环依赖'), 'Rule save blocks circular dependency');

foreach (['group_behavior_save','rules','rule_save','rule_cycle_check'] as $action) {
    pa4_assert_true(str_contains($api, $action), "API supports {$action}");
}
pa4_assert_true(str_contains($api, "'phase' => 4"), 'API status declares phase 4');

pa4_assert_true(str_contains($index, 'data-phase="4"'), 'V2 index declares phase 4');
pa4_assert_true(str_contains($index, "\$view === 'rules'") && str_contains($index, '规则编辑器'), 'Rules editor route is implemented');
pa4_assert_true(str_contains($index, 'group_behavior_save') && str_contains($index, 'rule_save'), 'UI can save behavior settings and rules');
pa4_assert_true(str_contains($index, 'pa2-rule-board') && str_contains($index, 'pa2-rule-card'), 'Rules editor uses visual rule cards');

pa4_assert_true(str_contains($doc, '规则循环检测') && str_contains($doc, 'mc_pa2_rule_definitions'), 'Phase 4 document records rule model');
pa4_assert_true(str_contains($execution, '第 4 阶段：配置组选项、物料来源和规则编辑器'), 'Execution log records phase 4');

foreach ([
    $mc . '/adaptation/index.php',
    $mc . '/api/v1/adaptation.php',
    $mc . '/app/Services/AdaptationService.php',
] as $legacyFile) {
    pa4_assert_true(is_file($legacyFile), 'Legacy adaptation file still exists: ' . basename($legacyFile));
}

echo "adaptation v2 phase 4 contract passed.\n";
