<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$page = file_get_contents($root.'/adaptation/index.php');
$script = file_get_contents($root.'/assets/js/adaptation-v3.js');
$css = file_get_contents($root.'/assets/css/app.css');
$api = file_get_contents($root.'/api/v1/adaptation.php');
$service = file_get_contents($root.'/app/Services/AdaptationService.php');

foreach ([
    '基础页面修复中',
    '现有配置数据不会被修改',
    'repairMode',
    'mc-page--adaptation-baseline',
    'renderPausedStep',
    '基础页面止损修复',
] as $marker) {
    if (str_contains($page.$script.$css.$api.$service, $marker)) {
        throw new RuntimeException("adaptation rollback still contains placeholder repair marker: {$marker}");
    }
}

foreach ([
    'mc-page--adaptation-v3',
    'data-v3-products',
    'data-v3-select-product',
    '产品配置工作台',
    '全部产品',
    '选择产品',
] as $marker) {
    if (!str_contains($page, $marker)) {
        throw new RuntimeException("adaptation entry page missing restored marker: {$marker}");
    }
}

foreach ([
    '产品适配工作台',
    '最近产品',
    '全部产品配置管理',
    '打开工作台',
    'renderHome',
    'renderProducts',
    'renderWorkbench',
    'renderTemplate',
    'renderBatch',
] as $marker) {
    if (!str_contains($script, $marker)) {
        throw new RuntimeException("adaptation v3 script missing restored marker: {$marker}");
    }
}

foreach ([
    '.mc-page--adaptation-v3',
    '.mc-v3-metrics',
    '.mc-v3-recent',
    '.mc-v3-product-table',
    '.mc-v3-product-summary',
    '.mc-v3-workarea',
] as $marker) {
    if (!str_contains($css, $marker)) {
        throw new RuntimeException("adaptation v3 layout missing restored marker: {$marker}");
    }
}

foreach ([
    "'home'",
    "'products'",
    "'workspace'",
    "'overview'",
] as $marker) {
    if (!str_contains($api, $marker)) {
        throw new RuntimeException("adaptation API missing restored endpoint marker: {$marker}");
    }
}

foreach ([
    'technicalProfile',
    'candidateMaterials',
    'publishedVersions',
    'saveTechnicalProfile',
] as $marker) {
    if (!str_contains($service, $marker)) {
        throw new RuntimeException("adaptation service missing restored marker: {$marker}");
    }
}

echo "Product adaptation rollback contract: OK\n";
