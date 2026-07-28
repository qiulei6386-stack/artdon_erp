<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$page = file_get_contents($root.'/adaptation/index.php');
$script = file_get_contents($root.'/assets/js/adaptation-shell.js');
$css = file_get_contents($root.'/assets/css/app.css');

foreach ([
    'data-adaptation-view="overview"',
    'data-adaptation-view="guide"',
    'data-adaptation-view="batch"',
    'data-overview-dashboard',
    'data-overview-switch-product',
    'data-overview-submit',
    'data-overview-product-list',
    'data-option-title',
] as $marker) {
    if (!str_contains($page, $marker)) throw new RuntimeException("adaptation overview UI missing: {$marker}");
}

foreach ([
    'renderOverviewDashboard',
    'renderOverviewProductList',
    'data-overview-product-id',
    'data-overview-template',
    'data-overview-submit',
    'loadWorkspace(selectedProductId(), groupId)',
    "state.tab = 'approval'",
    'loadCandidateDiscovery',
] as $marker) {
    if (!str_contains($script, $marker)) throw new RuntimeException("adaptation overview real interaction missing: {$marker}");
}

foreach ([
    '.mc-page--adaptation-v2[data-view="overview"] .mc-adaptation-workspace',
    '.mc-overview-product-hero',
    '.mc-overview-groups',
    '.mc-page--adaptation-v2[data-view="overview"] .mc-candidate-discovery',
] as $marker) {
    if (!str_contains($css, $marker)) throw new RuntimeException("adaptation overview layout missing: {$marker}");
}

echo "Product adaptation overview dashboard contract: OK\n";
