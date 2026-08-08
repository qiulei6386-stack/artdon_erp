<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$page = file_get_contents($root . '/crm.php');
$css = file_get_contents($root . '/assets/crm/crm.css');
if ($page === false || $css === false) {
    fwrite(STDERR, "Cannot read CRM customer layout sources\n");
    exit(1);
}

$forbidden = [
    '<div class="crm-module-head"><div><span>客户中心</span><h1>客户中心</h1>',
];
foreach ($forbidden as $needle) {
    if (strpos($page, $needle) !== false) {
        fwrite(STDERR, "Forbidden customer center title strip remains\n");
        exit(1);
    }
}

$markers = [
    [$page, '<section class="crm-module" data-crm-module="customers">', '客户模块入口'],
    [$page, '<section class="crm-panel customer-filterbar">', '客户筛选条仍存在'],
    [$css, 'grid-template-areas:', '小屏筛选重排为区域布局'],
    [$css, '"search meta"', '小屏搜索与数量第一行'],
    [$css, '"tools tools"', '小屏工具独占一行'],
    [$css, '.customer-search-main label { display: none !important; }', '小屏隐藏搜索标签节省宽度'],
    [$css, '.customer-search-tools {', '筛选工具样式存在'],
    [$css, 'overflow-x: auto !important;', '小屏筛选横向滚动兜底'],
];
foreach ($markers as [$source, $needle, $label]) {
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, "Missing customer mobile filter marker: {$label}\n");
        exit(1);
    }
}

echo "crm_customer_mobile_filter_layout_contract ok\n";
