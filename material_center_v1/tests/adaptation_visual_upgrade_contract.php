<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$script = file_get_contents($root.'/assets/js/adaptation-v3.js');
$css = file_get_contents($root.'/assets/css/app.css');

foreach ([
    '基础页面修复中',
    '现有配置数据不会被修改',
    'repairMode',
    'renderPausedStep',
    '已保留入口，后续按真实业务接入',
] as $marker) {
    if (str_contains($script.$css, $marker)) {
        throw new RuntimeException("visual upgrade still contains placeholder marker: {$marker}");
    }
}

foreach ([
    '全部产品配置',
    '全部产品配置管理',
    '产品适配工作台',
    '最近产品',
    '产品配置工作台',
    '配置检查 / 提交审批',
    '核心必配',
    '扩展可配',
    '条件规则',
    '检查发布',
    '芯片 / 光源',
    '电源 / 驱动',
    '光学 / 透镜',
    '安装方式',
    'renderGroupDrawer',
    'renderCandidates',
    'exportProductsCsv',
    'data-v3-filter-keyword',
    'data-v3-tab-status',
    'data-v3-open-product',
] as $marker) {
    if (!str_contains($script, $marker)) {
        throw new RuntimeException("visual upgrade script missing marker: {$marker}");
    }
}

foreach ([
    'mc-v3-page-shell',
    'mc-v3-filter-card',
    'mc-v3-status-tabs',
    'mc-v3-recent-strip',
    'mc-v3-product-table--spec',
    'mc-v3-workbench-layout',
    'mc-v3-product-hero',
    'mc-v3-flow',
    'mc-v3-module-card',
    'mc-v3-config-drawer',
    'mc-v3-candidate-list',
] as $marker) {
    if (!str_contains($css, $marker)) {
        throw new RuntimeException("visual upgrade stylesheet missing marker: {$marker}");
    }
}

echo "Product adaptation visual upgrade contract: OK\n";
