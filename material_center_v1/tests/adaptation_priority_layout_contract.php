<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$page = file_get_contents($root.'/adaptation/index.php');
$script = file_get_contents($root.'/assets/js/adaptation-shell.js');
$css = file_get_contents($root.'/assets/css/app.css');

foreach (['产品配置工作台', 'data-overview-dashboard', 'data-workbench-drawer-resizer', 'data-candidate-discovery'] as $marker) {
    if (!str_contains($page, $marker)) {
        throw new RuntimeException("adaptation priority guidance missing: {$marker}");
    }
}
foreach ([
    'mc-workbench-group-section',
    'mc-workbench-steps',
    'setWorkspaceUrl',
    'renderCandidateDiscovery',
    'loadCandidateDiscovery',
    'nextGroup',
] as $marker) {
    if (!str_contains($script, $marker)) {
        throw new RuntimeException("adaptation priority behavior missing: {$marker}");
    }
}
foreach ([
    '.mc-workbench-groups--core',
    '.mc-workbench-groups--extension',
    '.mc-workbench-steps',
    '.mc-page--adaptation-workbench .mc-adaptation-options',
] as $marker) {
    if (!str_contains($css, $marker)) {
        throw new RuntimeException("adaptation priority layout missing: {$marker}");
    }
}

echo "Product adaptation workbench priority layout contract: OK\n";
