<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$page = file_get_contents($root.'/adaptation/index.php');
$script = file_get_contents($root.'/assets/js/adaptation-shell.js');
$css = file_get_contents($root.'/assets/css/app.css');
$service = file_get_contents($root.'/app/Services/AdaptationService.php');

foreach ([
    '产品配置工作台',
    'data-overview-switch-product',
    'data-overview-submit',
    'overview-product-modal',
    'data-workbench-drawer-close',
    'data-workbench-drawer-resizer',
    'data-adaptation-tab="approval"',
] as $marker) {
    if (!str_contains($page, $marker)) throw new RuntimeException("adaptation workbench UI missing: {$marker}");
}

foreach ([
    'renderOverviewDashboard',
    'setOptionSubtitle',
    'mc-workbench-steps',
    'mc-workbench-group-section',
    'setWorkspaceUrl',
    "historyMode === 'push'",
    'window.addEventListener(\'popstate\'',
    'restoreDrawerWidth',
    'bindDrawerResize',
    'force_exception_reason',
    'published_versions',
] as $marker) {
    if (!str_contains($script, $marker)) throw new RuntimeException("adaptation workbench interaction missing: {$marker}");
}

if (str_contains($script, '[data-rule-subtitle]')) {
    throw new RuntimeException('adaptation workbench still references a removed subtitle node');
}
if (!str_contains($script, '工作台暂时无法加载')) {
    throw new RuntimeException('adaptation workbench is missing its initialization fallback');
}

foreach ([
    '.mc-page--adaptation-workbench .mc-adaptation-options',
    '--workbench-drawer-width',
    '.mc-workbench-groups--core',
    '.mc-workbench-groups--extension',
    '.mc-workbench-welcome',
    '.mc-published-version-list',
] as $marker) {
    if (!str_contains($css, $marker)) throw new RuntimeException("adaptation workbench layout missing: {$marker}");
}

foreach ([
    'mc_adaptation_published_versions',
    'publishedVersions',
    'commercialRowsForProduct',
    'forceExceptionReason',
    '强制添加说明',
] as $marker) {
    if (!str_contains($service, $marker)) throw new RuntimeException("adaptation publication control missing: {$marker}");
}

echo "Product adaptation workbench contract: OK\n";
