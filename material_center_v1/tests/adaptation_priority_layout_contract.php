<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$page = file_get_contents($root.'/adaptation/index.php');
$script = file_get_contents($root.'/assets/js/adaptation-shell.js');
$css = file_get_contents($root.'/assets/css/app.css');

foreach (['配置组工作区', '已选物料会持续显示在右侧'] as $marker) {
    if (!str_contains($page, $marker)) {
        throw new RuntimeException("adaptation priority guidance missing: {$marker}");
    }
}
foreach ([
    "product.product_code || '未编号产品'} · 配置组工作区",
    'if (!state.workspace || !overview.length || selectedGroup())',
    'root.hidden = true;',
] as $marker) {
    if (!str_contains($script, $marker)) {
        throw new RuntimeException("adaptation priority behavior missing: {$marker}");
    }
}
foreach ([
    '.mc-page--adaptation-v2[data-stage="options"] .mc-adaptation-progress-card{display:grid;',
    '.mc-page--adaptation-v2[data-stage="options"] .mc-config-group-guide{display:flex;',
    '.mc-page--adaptation-v2[data-stage="options"] .mc-adaptation-groups{padding-top:5px;background:#f8fafc}',
] as $marker) {
    if (!str_contains($css, $marker)) {
        throw new RuntimeException("adaptation priority layout missing: {$marker}");
    }
}

echo "Product adaptation priority layout contract: OK\n";
