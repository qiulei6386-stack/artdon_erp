<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$repoRoot = dirname($root);

$read = static function (string $path): string {
    $content = file_get_contents($path);
    if ($content === false) {
        fwrite(STDERR, "cannot read $path\n");
        exit(1);
    }
    return $content;
};

$checks = [
    'V2 master spec is stored in material center docs'
        => is_file($root . '/docs/ARTDON_PRODUCT_ADAPTATION_V2_MASTER_IMPLEMENTATION_SPEC.md')
            && str_contains($read($root . '/docs/ARTDON_PRODUCT_ADAPTATION_V2_MASTER_IMPLEMENTATION_SPEC.md'), '产品适配 V2 的唯一主说明文件'),
    'legacy adaptation menu remains unchanged'
        => str_contains($read($root . '/components/sidebar.php'), "['adaptation','产品适配','branch','adaptation/index.php']")
            && !str_contains($read($root . '/components/sidebar.php'), 'adaptation_v2/index.php'),
    'V2 uses independent adaptation_v2 directory'
        => is_dir($root . '/adaptation_v2')
            && is_file($root . '/adaptation_v2/index.php')
            && is_file($root . '/adaptation_v2/api/index.php'),
    'V2 page connects existing layout without writing legacy business'
        => str_contains($read($root . '/adaptation_v2/index.php'), "include MC_ROOT . '/components/layout_top.php'")
            && str_contains($read($root . '/adaptation_v2/index.php'), "include MC_ROOT . '/components/layout_bottom.php'")
            && str_contains($read($root . '/adaptation_v2/index.php'), "\$activeMenu = 'adaptation'")
            && str_contains($read($root . '/adaptation_v2/index.php'), '返回旧版产品适配')
            && !preg_match('/\\b(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP)\\b/i', $read($root . '/adaptation_v2/index.php')),
    'V2 API exposes only phase-1 status with unified response'
        => str_contains($read($root . '/adaptation_v2/lib/response.php'), "'success' => \$success")
            && str_contains($read($root . '/adaptation_v2/lib/response.php'), "'request_id' => pa2_request_id()")
            && str_contains($read($root . '/adaptation_v2/api/index.php'), "'business_write_enabled' => false")
            && str_contains($read($root . '/adaptation_v2/api/index.php'), 'PHASE_1_BLUEPRINT_ONLY')
            && !preg_match('/\\b(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|CREATE\\s+TABLE)\\b/i', $read($root . '/adaptation_v2/api/index.php')),
    'V2 migration directory exists but has no business migration in phase 1'
        => is_dir($root . '/adaptation_v2/database/migrations')
            && is_file($root . '/adaptation_v2/database/migrations/.gitkeep')
            && count(glob($root . '/adaptation_v2/database/migrations/*.php') ?: []) === 0,
    'required phase-1 docs are present'
        => is_file($root . '/adaptation_v2/docs/01_CURRENT_AUDIT.md')
            && is_file($root . '/adaptation_v2/docs/01_ROUTE_MAP.md')
            && is_file($root . '/adaptation_v2/docs/01_DATABASE_AUDIT.md')
            && is_file($root . '/adaptation_v2/docs/EXECUTION_LOG.md'),
    'audit docs record backup and no legacy BOM mutation'
        => str_contains($read($root . '/adaptation_v2/docs/01_CURRENT_AUDIT.md'), 'adaptation_v2_phase1_20260731_223720')
            && str_contains($read($root . '/adaptation_v2/docs/01_DATABASE_AUDIT.md'), 'mc_pa2_')
            && str_contains($read($root . '/adaptation_v2/docs/EXECUTION_LOG.md'), '未修改旧 BOM'),
    'WORK_CONTEXT records phase-1 stop point'
        => str_contains($read($repoRoot . '/WORK_CONTEXT.md'), '产品适配 V2 第 1 阶段')
            && str_contains($read($repoRoot . '/WORK_CONTEXT.md'), '不进入第 2 阶段'),
];

$failed = [];
foreach ($checks as $label => $passed) {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$passed) $failed[] = $label;
}

if ($failed) {
    fwrite(STDERR, 'adaptation V2 phase 1 contract failed: ' . implode('; ', $failed) . PHP_EOL);
    exit(1);
}

echo "adaptation V2 phase 1 contract passed.\n";
