<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$mc = $root . '/material_center_v1';
$v2 = $mc . '/adaptation_v2';

function pa8_assert_true(bool $cond, string $message): void
{
    if (!$cond) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
    echo "[PASS] {$message}\n";
}

function pa8_file(string $path): string
{
    if (!is_file($path)) {
        fwrite(STDERR, "[FAIL] Missing file: {$path}\n");
        exit(1);
    }
    return (string)file_get_contents($path);
}

$index = pa8_file($v2 . '/index.php');
$api = pa8_file($v2 . '/api/index.php');
$foundation = pa8_file($v2 . '/lib/foundation.php');
$migration = pa8_file($v2 . '/database/migrations/20260801_007_phase8_packages.php');
$doc = pa8_file($v2 . '/docs/08_CONFIG_PACKAGE_CENTER.md');
$execution = pa8_file($v2 . '/docs/EXECUTION_LOG.md');
$context = pa8_file($root . '/WORK_CONTEXT.md');

foreach (['mc_pa2_config_packages','mc_pa2_config_package_versions','mc_pa2_config_package_groups','mc_pa2_config_package_options'] as $table) {
    pa8_assert_true(str_contains($migration, $table), "Phase 8 migration contains {$table}");
}
pa8_assert_true(!preg_match('/CREATE\s+TABLE\s+(?!IF\s+NOT\s+EXISTS\s+)?`?(mc_(?!pa2_|schema_migrations)[a-z0-9_]+)/i', $migration), 'Phase 8 migration only creates mc_pa2 tables');

foreach (['commercial_flexible','singapore_standard','singapore_dali','singapore_ready_stock'] as $packageCode) {
    pa8_assert_true(str_contains($migration . $index . $doc, $packageCode), "First package exists: {$packageCode}");
}

foreach ([
    'pa2_phase8_tables_ready',
    'pa2_fetch_packages',
    'pa2_fetch_package',
    'pa2_upsert_package',
    'pa2_prepare_package_version',
    'pa2_save_package_group',
    'pa2_save_package_option',
    'pa2_package_preview',
    'pa2_publish_package',
] as $fn) {
    pa8_assert_true(str_contains($foundation, 'function ' . $fn), "Foundation implements {$fn}");
}

foreach (['open','locked','range_limited','default_locked','price_rule_json','moq_rule_json','inventory_rule_json','lead_time_rule_json'] as $marker) {
    pa8_assert_true(str_contains($foundation . $migration . $index, $marker), "Package rule marker exists: {$marker}");
}

foreach (['packages','package_detail','package_save','package_version_prepare','package_group_save','package_option_save','package_preview','package_publish'] as $action) {
    pa8_assert_true(str_contains($api, $action), "API supports {$action}");
}
pa8_assert_true(str_contains($api, "'phase' => 8"), 'API status declares phase 8');

pa8_assert_true(str_contains($index, 'data-phase="8"'), 'V2 index declares phase 8');
pa8_assert_true(str_contains($index, '配置包中心') && str_contains($index, '发布配置包') && str_contains($foundation . $index, 'Ready Stock 关键物料全部锁定'), 'Packages page exposes package center and acceptance checks');
pa8_assert_true(!str_contains($index, '此入口已纳入 V2 路由蓝图，但当前阶段不开发业务功能。请按主说明继续进入对应阶段后再实现。</p>') || str_contains($index, "\$view === 'packages'"), 'Packages view is no longer only a placeholder');

foreach (['Ready Stock','Standard','DALI','版本可追溯','价格','MOQ','库存','交期'] as $marker) {
    pa8_assert_true(str_contains($doc . $execution . $foundation . $index, $marker), "Documented package capability: {$marker}");
}

foreach ([
    $mc . '/adaptation/index.php',
    $mc . '/api/v1/adaptation.php',
    $mc . '/app/Services/AdaptationService.php',
] as $legacyFile) {
    pa8_assert_true(is_file($legacyFile), 'Legacy adaptation file still exists: ' . basename($legacyFile));
}

pa8_assert_true(str_contains($context, '第 8 阶段配置包中心'), 'WORK_CONTEXT records phase 8');

echo "adaptation v2 phase 8 contract passed.\n";
