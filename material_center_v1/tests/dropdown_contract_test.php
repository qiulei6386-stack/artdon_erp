<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$css = file_get_contents($root . '/assets/css/app.css');
$js = file_get_contents($root . '/assets/js/app.js');
$layout = file_get_contents($root . '/components/layout_top.php') . file_get_contents($root . '/components/layout_bottom.php');

if (!str_contains($css, '.mc-dropdown-wrap{position:relative}')) {
    throw new RuntimeException('dropdown has no positioning container');
}
if (!str_contains($js, 'if(!m)return')) {
    throw new RuntimeException('dropdown binding is not missing-target safe');
}
foreach (['assets/css/app.css', 'assets/js/app.js', 'assets/js/material-workspace-actions.js'] as $asset) {
    if (!str_contains($layout, "mc_ui_asset('$asset')")) {
        throw new RuntimeException("asset cache version missing: $asset");
    }
}
echo "Dropdown positioning and cache contract: OK\n";
