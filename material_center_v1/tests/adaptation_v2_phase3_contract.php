<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$mc = $root . '/material_center_v1';
$v2 = $mc . '/adaptation_v2';

function pa3_assert_true(bool $cond, string $message): void
{
    if (!$cond) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
    echo "[PASS] {$message}\n";
}

function pa3_file(string $path): string
{
    if (!is_file($path)) {
        fwrite(STDERR, "[FAIL] Missing file: {$path}\n");
        exit(1);
    }
    return (string)file_get_contents($path);
}

$index = pa3_file($v2 . '/index.php');
$api = pa3_file($v2 . '/api/index.php');
$foundation = pa3_file($v2 . '/lib/foundation.php');
$migration = pa3_file($v2 . '/database/migrations/20260801_002_phase3_templates.php');
$doc = pa3_file($v2 . '/docs/03_TEMPLATE_INHERITANCE.md');
$execution = pa3_file($v2 . '/docs/EXECUTION_LOG.md');

foreach (['mc_pa2_templates','mc_pa2_template_versions','mc_pa2_template_groups'] as $table) {
    pa3_assert_true(str_contains($migration, $table), "Phase 3 migration contains {$table}");
}
pa3_assert_true(!preg_match('/CREATE\s+TABLE\s+(?!IF\s+NOT\s+EXISTS\s+)?`?(mc_(?!pa2_|schema_migrations)[a-z0-9_]+)/i', $migration), 'Phase 3 migration only creates mc_pa2 tables');
pa3_assert_true(str_contains($migration, 'system_common') && str_contains($migration, 'track_light_base') && str_contains($migration, 'recessed_base') && str_contains($migration, 'magnetic_base'), 'Seed templates are present');
pa3_assert_true(str_contains($migration, 'inheritance_action') && str_contains($migration, 'group_code'), 'Template groups include inheritance action and group_code merge key');

foreach (['pa2_template_effective_groups','pa2_template_ancestry','pa2_publish_template','pa2_template_reference_check'] as $fn) {
    pa3_assert_true(str_contains($foundation, 'function ' . $fn), "Foundation implements {$fn}");
}
pa3_assert_true(str_contains($foundation, "'disable'") && str_contains($foundation, "'override'") && str_contains($foundation, "'add'"), 'Inheritance supports add, override and disable');
pa3_assert_true(str_contains($foundation, 'snapshot_json') && str_contains($foundation, 'active_version_id'), 'Publishing stores snapshot and active version');

foreach (['templates','template_detail','template_save','template_group_save','template_preview','template_publish','template_reference_check'] as $action) {
    pa3_assert_true(str_contains($api, $action), "API supports {$action}");
}

pa3_assert_true(str_contains($index, "data-phase=\"3\""), 'V2 index declares phase 3');
pa3_assert_true(str_contains($index, '模板中心') && str_contains($index, '模板编辑器') && str_contains($index, '继承预览'), 'Template pages are implemented, not placeholders');
pa3_assert_true(str_contains($index, 'pa2-template-shell') && str_contains($index, 'pa2-template-item'), 'Template UI uses softer workstation layout');
pa3_assert_true(str_contains($index, "\$view === 'templates'") && str_contains($index, "\$view === 'template_editor'"), 'Templates have dedicated routed views');
pa3_assert_true(str_contains($index, 'data-template-group-direct-edit') && str_contains($index, 'data-template-group-remove'), 'Template preview cards expose edit and remove entries');
pa3_assert_true(str_contains($index, 'edit_group') && str_contains($index, 'pa2-template-group-form'), 'Template edit links can prefill the group editor form');

pa3_assert_true(str_contains($doc, '父模板继承') && str_contains($doc, 'mc_pa2_templates'), 'Phase 3 document records inheritance model');
pa3_assert_true(str_contains($execution, '第 3 阶段：模板中心和继承引擎'), 'Execution log records phase 3');

foreach ([
    $mc . '/adaptation/index.php',
    $mc . '/api/v1/adaptation.php',
    $mc . '/app/Services/AdaptationService.php',
] as $legacyFile) {
    pa3_assert_true(is_file($legacyFile), 'Legacy adaptation file still exists: ' . basename($legacyFile));
}

echo "adaptation v2 phase 3 contract passed.\n";
