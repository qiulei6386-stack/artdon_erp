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
    '"tools tools"',
];
foreach ($forbidden as $needle) {
    if (strpos($page, $needle) !== false) {
        fwrite(STDERR, "Forbidden customer center title strip remains\n");
        exit(1);
    }
    if (strpos($css, $needle) !== false) {
        fwrite(STDERR, "Forbidden third customer filter row remains: {$needle}\n");
        exit(1);
    }
}

$markers = [
    [$page, '<section class="crm-module" data-crm-module="customers">', '客户模块入口'],
    [$page, '<section class="crm-panel customer-filterbar">', '客户筛选条仍存在'],
    [$page, '<div class="customer-search-tools">', '常用工具回到搜索行'],
    [$page, '<div class="customer-filter-line">', '快捷筛选独立成第二行'],
    [$page, '<details class="customer-more-tools">', '低频工具收进更多'],
    [$css, '.customer-search-row {', '客户筛选默认重排'],
    [$css, 'grid-template-areas:', '小屏筛选重排为区域布局'],
    [$css, '"search tools meta"', '搜索、常用工具、数量同在第一行'],
    [$css, '.customer-filter-line { display: flex;', '第二行快捷筛选带'],
    [$css, 'overflow: visible; padding-bottom: 1px;', '快捷筛选默认不靠横向滚动'],
    [$css, '.customer-more-tools[open] .customer-more-menu { display: inline-flex; }', '更多打开不新增竖向行'],
    [$css, '.customer-search-main label { display: none !important; }', '小屏隐藏搜索标签节省宽度'],
    [$css, '.customer-search-tools {', '筛选工具样式存在'],
    [$css, 'grid-template-columns: minmax(260px, 420px) minmax(0, 1fr) auto !important;', '小屏/窄屏搜索框不再占满整行'],
    [$css, 'overflow-x: visible !important;', '小屏快捷筛选不强制左右滚动'],
];
foreach ($markers as [$source, $needle, $label]) {
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, "Missing customer mobile filter marker: {$label}\n");
        exit(1);
    }
}

echo "crm_customer_mobile_filter_layout_contract ok\n";
