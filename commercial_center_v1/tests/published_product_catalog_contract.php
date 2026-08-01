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
] as $needle => $message) {
    if (!str_contains((string)$source, $needle)) {
        $errors[] = $message;
    }
}

if ($errors) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

echo "PASS: published material-center products are promoted into the commercial catalog.\n";
