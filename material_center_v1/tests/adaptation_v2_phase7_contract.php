<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$mc = $root . '/material_center_v1';
$v2 = $mc . '/adaptation_v2';

function pa7_assert_true(bool $cond, string $message): void
{
    if (!$cond) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
    echo "[PASS] {$message}\n";
}

function pa7_file(string $path): string
{
    if (!is_file($path)) {
        fwrite(STDERR, "[FAIL] Missing file: {$path}\n");
        exit(1);
    }
    return (string)file_get_contents($path);
}

$index = pa7_file($v2 . '/index.php');
$api = pa7_file($v2 . '/api/index.php');
$foundation = pa7_file($v2 . '/lib/foundation.php');
$migration = pa7_file($v2 . '/database/migrations/20260801_006_phase7_versions.php');
$doc = pa7_file($v2 . '/docs/07_VERSION_APPROVAL.md');
$execution = pa7_file($v2 . '/docs/EXECUTION_LOG.md');

foreach (['mc_pa2_product_version_events','mc_pa2_product_version_snapshots','mc_pa2_product_version_diffs'] as $table) {
    pa7_assert_true(str_contains($migration, $table), "Phase 7 migration contains {$table}");
}
pa7_assert_true(!preg_match('/CREATE\s+TABLE\s+(?!IF\s+NOT\s+EXISTS\s+)?`?(mc_(?!pa2_|schema_migrations)[a-z0-9_]+)/i', $migration), 'Phase 7 migration only creates mc_pa2 tables');

foreach ([
    'pa2_phase7_tables_ready',
    'pa2_product_versions',
    'pa2_build_version_snapshot',
    'pa2_store_version_snapshot',
    'pa2_compare_version_snapshots',
    'pa2_store_version_diff',
    'pa2_clone_version_as_draft',
    'pa2_product_version_submit',
    'pa2_product_version_approve',
    'pa2_product_version_reject',
    'pa2_product_version_publish',
    'pa2_product_version_rollback',
] as $fn) {
    pa7_assert_true(str_contains($foundation, 'function ' . $fn), "Foundation implements {$fn}");
}

foreach (['draft','submitted','approved','rejected','published','rollback','edit_after_publish'] as $marker) {
    pa7_assert_true(str_contains($foundation . $index . $doc, $marker), "Version lifecycle marker exists: {$marker}");
}
pa7_assert_true(str_contains($foundation, '当前版本已提交、审批或发布，请先生成新的草稿再修改。'), 'Locked version write protection exists');
pa7_assert_true(str_contains($foundation, 'active_published_version_id') && str_contains($foundation, 'active_draft_version_id=NULL'), 'Publish protects active published pointer and clears active draft');

foreach (['product_versions','product_version_diff','product_version_submit','product_version_approve','product_version_reject','product_version_publish','product_version_rollback'] as $action) {
    pa7_assert_true(str_contains($api, $action), "API supports {$action}");
}
pa7_assert_true(preg_match("/'phase'\\s*=>\\s*(?:[7-9]|[1-9][0-9]+)/", $api) === 1, 'API status declares phase 7 or later');

pa7_assert_true(preg_match('/data-phase="(?:[7-9]|[1-9][0-9]+)"/', $index) === 1, 'V2 index declares phase 7 or later');
pa7_assert_true(str_contains($index, '提交审批') && str_contains($index, '审批通过') && str_contains($index, '发布版本') && str_contains($index, '回滚到此版本'), 'Workspace exposes approval lifecycle actions');
pa7_assert_true(str_contains($index, '生成下一版草稿'), 'Workspace exposes edit-after-publish draft creation');
pa7_assert_true(str_contains($index, '当前版本已锁定'), 'Workspace prevents editing locked versions');
pa7_assert_true(str_contains($index, '$pa2ResultRank') && str_contains($index, 'pa2-selected-row'), 'Workspace shows worst group status and per-selected adaptation results');
pa7_assert_true(!str_contains($index, '$primaryResult = $groupResults[0] ?? null'), 'Workspace no longer hides later incompatible selected materials behind the first result');
pa7_assert_true(str_contains($foundation, 'candidate_label') && str_contains($foundation, '$blocked[]'), 'Submit approval error includes concrete incompatible material details');

pa7_assert_true(str_contains($doc, '旧发布版本保护') && str_contains($doc, '产品级覆盖'), 'Phase 7 document records version protection and product overrides');
pa7_assert_true(str_contains($execution, '第 7 阶段：产品差异、审批和版本'), 'Execution log records phase 7');

foreach ([
    $mc . '/adaptation/index.php',
    $mc . '/api/v1/adaptation.php',
    $mc . '/app/Services/AdaptationService.php',
] as $legacyFile) {
    pa7_assert_true(is_file($legacyFile), 'Legacy adaptation file still exists: ' . basename($legacyFile));
}

echo "adaptation v2 phase 7 contract passed.\n";
