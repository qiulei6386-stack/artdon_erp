<?php
declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../app/Repositories/LegacyCatalogReadRepository.php');
$errors = [];

foreach ([
    "mp.legacy_table='naming_models' AND mp.legacy_id=n.id" => '商务产品必须按旧产品永久 ID 关联物料中心产品',
    'pv.id=pc.active_published_version_id' => '商务产品必须读取当前发布版本',
    "pc.status='published' AND pv.status='published'" => '只有产品与版本均已发布才可映射为可报价',
    "THEN '可报价'" => '已发布产品必须显示为可报价',
    'ORDER BY (pv.published_at IS NULL),pv.published_at DESC' => '最新发布产品必须优先显示',
    "ps2.snapshot_type='published'" => '商务产品详情必须读取已发布快照',
    "'commercial_configuration'" => '仓库必须向商务产品详情提供结构化发布配置',
] as $needle => $message) {
    if (!str_contains((string)$source, $needle)) {
        $errors[] = $message;
    }
}

$view = file_get_contents(__DIR__ . '/../views/product_library_v2.php');
$script = file_get_contents(__DIR__ . '/../assets/js/app.js');
if (!str_contains((string)$view, 'data-product-config')) $errors[] = '产品卡片必须携带已发布配置';
foreach (['config.groups', 'config.schemes', 'technical.power', 'technical.beam_angle', '配置方案', 'drawer-scheme'] as $needle) {
    if (!str_contains((string)$script, $needle)) $errors[] = '产品抽屉缺少发布配置渲染标记：' . $needle;
}
foreach (['光源：—', '电源：—', '<dt>功率</dt><dd>—</dd>'] as $forbidden) {
    if (str_contains((string)$script, $forbidden)) $errors[] = '产品抽屉仍残留写死占位：' . $forbidden;
}

if ($errors) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

echo "PASS: published material-center products are promoted into the commercial catalog.\n";
