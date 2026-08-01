<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$mc = $root . '/material_center_v1';
$v2 = $mc . '/adaptation_v2';

function pa9_assert_true(bool $cond, string $message): void
{
    if (!$cond) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
    echo "[PASS] {$message}\n";
}

function pa9_file(string $path): string
{
    if (!is_file($path)) {
        fwrite(STDERR, "[FAIL] Missing file: {$path}\n");
        exit(1);
    }
    return (string)file_get_contents($path);
}

$index = pa9_file($v2 . '/index.php');
$api = pa9_file($v2 . '/api/index.php');
$foundation = pa9_file($v2 . '/lib/foundation.php');
$migration = pa9_file($v2 . '/database/migrations/20260801_008_phase9_channel_api.php');
$doc = pa9_file($v2 . '/docs/09_CHANNEL_API.md');
$execution = pa9_file($v2 . '/docs/EXECUTION_LOG.md');
$context = pa9_file($root . '/WORK_CONTEXT.md');

foreach (['mc_pa2_channel_clients','mc_pa2_channel_package_snapshots','mc_pa2_channel_cache','mc_pa2_channel_access_logs','mc_pa2_channel_order_snapshots'] as $table) {
    pa9_assert_true(str_contains($migration, $table), "Phase 9 migration contains {$table}");
}
pa9_assert_true(!preg_match('/CREATE\s+TABLE\s+(?!IF\s+NOT\s+EXISTS\s+)?`?(mc_(?!pa2_|schema_migrations)[a-z0-9_]+)/i', $migration), 'Phase 9 migration only creates mc_pa2 tables');

foreach (['commercial_center','singapore_site','PA2_CHANNEL_SECRET_COMMERCIAL_CENTER','PA2_CHANNEL_SECRET_SINGAPORE_SITE'] as $marker) {
    pa9_assert_true(str_contains($migration . $doc . $index, $marker), "Channel client marker exists: {$marker}");
}

foreach ([
    'pa2_phase9_tables_ready',
    'pa2_channel_clients',
    'pa2_channel_auth_context',
    'pa2_channel_log',
    'pa2_channel_published_packages',
    'pa2_channel_published_package_detail',
    'pa2_channel_cache_get',
    'pa2_channel_cache_put',
    'pa2_store_channel_package_snapshot',
    'pa2_channel_order_snapshot',
] as $fn) {
    pa9_assert_true(str_contains($foundation, 'function ' . $fn), "Foundation implements {$fn}");
}

foreach (['hash_hmac', 'X-PA2-Client', 'X-PA2-Timestamp', 'X-PA2-Signature', '300'] as $marker) {
    pa9_assert_true(str_contains($foundation . $doc . $index, $marker), "Signature marker exists: {$marker}");
}

pa9_assert_true(str_contains($foundation, "p.status='published'") && str_contains($foundation, "v.status='published'"), 'Channel package queries expose published versions only');
pa9_assert_true(str_contains($foundation . $doc . $index, '草稿') && str_contains($foundation . $doc . $index, '不会暴露'), 'Draft non-exposure is documented and enforced');

foreach (['channel_clients','channel_packages','channel_package_detail','channel_order_snapshot'] as $action) {
    pa9_assert_true(str_contains($api, $action), "API supports {$action}");
}
pa9_assert_true(str_contains($api, "'phase' => 9"), 'API status declares phase 9');

pa9_assert_true(str_contains($index, 'data-phase="9"'), 'V2 index declares phase 9');
pa9_assert_true(str_contains($index, '$view === \'publish\'') && str_contains($index, '渠道发布 / 下游接口') && str_contains($index, '下游可见'), 'Publish page exposes channel API status');
pa9_assert_true(str_contains($index, 'adaptation_v2/docs/09_CHANNEL_API.md'), 'Logs page links phase 9 document');

foreach (['缓存','快照','订单配置快照','访问日志','商务中心','新加坡网站'] as $marker) {
    pa9_assert_true(str_contains($doc . $execution . $foundation . $index, $marker), "Documented channel capability: {$marker}");
}

foreach ([
    $mc . '/adaptation/index.php',
    $mc . '/api/v1/adaptation.php',
    $mc . '/app/Services/AdaptationService.php',
] as $legacyFile) {
    pa9_assert_true(is_file($legacyFile), 'Legacy adaptation file still exists: ' . basename($legacyFile));
}

pa9_assert_true(str_contains($context, '第 9 阶段下游渠道接口'), 'WORK_CONTEXT records phase 9');

echo "adaptation v2 phase 9 contract passed.\n";
