<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$mc = $root . '/material_center_v1';
$v2 = $mc . '/adaptation_v2';

function pa_accessory_assert_true(bool $cond, string $message): void
{
    if (!$cond) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
    echo "[PASS] {$message}\n";
}

function pa_accessory_file(string $path): string
{
    if (!is_file($path)) {
        fwrite(STDERR, "[FAIL] Missing file: {$path}\n");
        exit(1);
    }
    return (string)file_get_contents($path);
}

$migration = pa_accessory_file($v2 . '/database/migrations/20260801_010_accessory_group_definitions.php');
$execution = pa_accessory_file($v2 . '/docs/EXECUTION_LOG.md');
$context = pa_accessory_file($root . '/WORK_CONTEXT.md');

foreach ([
    'accessory' => '配件',
    'glass' => '玻璃',
    'honeycomb' => '蜂窝网',
    'four_leaf_louver' => '四叶片',
    'optical_film' => '光学膜',
] as $code => $name) {
    pa_accessory_assert_true(str_contains($migration, "'{$code}'"), "Migration contains group {$code}");
    pa_accessory_assert_true(str_contains($migration . $execution . $context, $name), "Chinese group name documented: {$name}");
}

foreach (['mc_pa2_group_definitions','mc_pa2_group_behavior_settings','official_material','accessory','material_select'] as $marker) {
    pa_accessory_assert_true(str_contains($migration, $marker), "Migration contains marker {$marker}");
}

pa_accessory_assert_true(str_contains($migration, "'multiple'") && str_contains($migration, "'single'"), 'Migration defines single and multiple selection modes');
pa_accessory_assert_true(str_contains($migration, "'keyword','玻璃'") && str_contains($migration, "'keyword','蜂'") && str_contains($migration, "'keyword','四叶片'") && str_contains($migration, "'keyword','膜'"), 'Migration defines candidate search keywords');
pa_accessory_assert_true(!preg_match('/CREATE\s+TABLE/i', $migration), 'Accessory group migration does not create tables');

foreach ([
    $mc . '/adaptation/index.php',
    $root . '/bom.php',
] as $legacyFile) {
    pa_accessory_assert_true(is_file($legacyFile), 'Legacy/old BOM file still exists: ' . basename($legacyFile));
}

echo "adaptation v2 accessory groups contract passed.\n";
