<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$page = file_get_contents($root.'/power_standardization.php');
$css = file_get_contents($root.'/assets/css/app.css');

foreach ([
    'id="standardization-drawer"',
    'id="standard-form"',
    'standard-review-section',
    '功率与电压',
    '尺寸与性能',
    'current-options-fieldset',
    'data-duplicates',
] as $marker) {
    if (!str_contains($page, $marker)) throw new RuntimeException("standardization structure missing: $marker");
}

foreach ([
    '#standardization-drawer .standard-review-grid',
    'grid-template-columns:repeat(2,minmax(0,1fr))',
    '#standardization-drawer .standard-fieldset',
    '#standardization-drawer .current-option-row',
    '@media(max-width:700px)',
    'grid-template-columns:1fr',
] as $marker) {
    if (!str_contains($css, $marker)) throw new RuntimeException("compact review layout missing: $marker");
}

if (str_contains($css, '#standardization-drawer{width:')) {
    throw new RuntimeException('standardization drawer width must keep the original shared drawer width');
}

if (str_contains($css, '#standardization-drawer .ui-drawer-body{overflow:hidden')) {
    throw new RuntimeException('short screens must keep scrolling as a safety fallback');
}

echo "Power standardization compact two-column layout contract passed.\n";
