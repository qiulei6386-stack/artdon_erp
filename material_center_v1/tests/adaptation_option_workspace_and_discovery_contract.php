<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$page = file_get_contents($root.'/adaptation/index.php');
$script = file_get_contents($root.'/assets/js/adaptation-shell.js');
$css = file_get_contents($root.'/assets/css/app.css');

$optionsPosition = strpos($page, 'mc-adaptation-column mc-adaptation-options');
$rulesPosition = strpos($page, 'mc-adaptation-column mc-adaptation-rules');
if ($optionsPosition === false || $rulesPosition === false || $optionsPosition > $rulesPosition) {
    throw new RuntimeException('option detail must occupy the middle work area before the right configuration navigator');
}
foreach (['data-product-summary', 'data-option-detail', 'data-candidate-discovery', 'data-selected-configuration', 'data-group-list'] as $marker) {
    if (!str_contains($page, $marker)) throw new RuntimeException("adaptation workspace marker missing: {$marker}");
}
foreach ([
    'candidateDiscoveryGroupId',
    'candidateDiscoveryRows',
    'renderCandidateDiscovery',
    'loadCandidateDiscovery',
    "get('candidates', { group_id: group.id, status: 'official' })",
    'data-candidate-discovery-open',
    'state.tab = \'options\';',
] as $marker) {
    if (!str_contains($script, $marker)) throw new RuntimeException("candidate discovery interaction missing: {$marker}");
}
foreach ([
    '.mc-page--adaptation-v2 .mc-adaptation-workspace{grid-template-columns:250px minmax(620px,1fr) minmax(310px,360px)}',
    '.mc-page--adaptation-v2 .mc-adaptation-rules .mc-adaptation-group-card__main',
    '.mc-candidate-discovery__row',
] as $marker) {
    if (!str_contains($css, $marker)) throw new RuntimeException("candidate discovery layout missing: {$marker}");
}

echo "Product adaptation option workspace and discovery contract: OK\n";
