<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$page = file_get_contents($root.'/adaptation/index.php');
$script = file_get_contents($root.'/assets/js/adaptation-shell.js');
$css = file_get_contents($root.'/assets/css/app.css');

foreach (['配置组工作区', '选择一个组后，中间区可维护关键范围、候选物料、默认项和条件。', 'data-candidate-discovery'] as $marker) {
    if (!str_contains($page, $marker)) {
        throw new RuntimeException("adaptation priority guidance missing: {$marker}");
    }
}
foreach ([
    "product.product_code || '未编号产品'} · 配置组工作区",
    'if (!state.workspace || !overview.length || selectedGroup())',
    'root.hidden = true;',
    'renderCandidateDiscovery',
    'loadCandidateDiscovery',
    '查看全部并选择',
] as $marker) {
    if (!str_contains($script, $marker)) {
        throw new RuntimeException("adaptation priority behavior missing: {$marker}");
    }
}
foreach ([
    '.mc-page--adaptation-v2:not([data-stage="products"]) .mc-adaptation-workspace{grid-template-columns:var(--mc-adaptation-products-width) 10px minmax(0,1fr) 10px var(--mc-adaptation-groups-width);gap:0}',
    '.mc-page--adaptation-v2 .mc-adaptation-rules .mc-selected-configuration__list{grid-template-columns:1fr}',
    '.mc-candidate-discovery__head',
] as $marker) {
    if (!str_contains($css, $marker)) {
        throw new RuntimeException("adaptation priority layout missing: {$marker}");
    }
}

echo "Product adaptation priority layout contract: OK\n";
