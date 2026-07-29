<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$page = file_get_contents($root.'/adaptation/index.php');
$script = file_get_contents($root.'/assets/js/adaptation-shell.js');
$css = file_get_contents($root.'/assets/css/app.css');

foreach (['data-product-summary', 'data-option-detail', 'data-candidate-discovery', 'data-selected-configuration', 'data-group-list', 'data-workbench-drawer-resizer', 'data-open-approval'] as $marker) {
    if (!str_contains($page, $marker)) throw new RuntimeException("adaptation workspace marker missing: {$marker}");
}
foreach ([
    'candidateDiscoveryGroupId',
    'candidateDiscoveryRows',
    'renderCandidateDiscovery',
    'loadCandidateDiscovery',
    'restoreDrawerWidth',
    'bindDrawerResize',
    'setWorkspaceUrl',
    'data-open-approval',
    "get('candidates', { group_id: group.id, status: 'official' })",
    'data-candidate-discovery-open',
    'state.tab = \'options\';',
] as $marker) {
    if (!str_contains($script, $marker)) throw new RuntimeException("candidate discovery interaction missing: {$marker}");
}
foreach ([
    '.mc-page--adaptation-workbench .mc-adaptation-options',
    '.mc-workbench-drawer-resizer',
    '.mc-page--adaptation-workbench .mc-selected-configuration',
    '.mc-candidate-discovery__row',
] as $marker) {
    if (!str_contains($css, $marker)) throw new RuntimeException("candidate discovery layout missing: {$marker}");
}

echo "Product adaptation workbench drawer and candidate discovery contract: OK\n";
