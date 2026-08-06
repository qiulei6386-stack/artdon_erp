<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$catalogRepository = file_get_contents($root . '/app/Repositories/LegacyCatalogReadRepository.php');
$configurationRepository = file_get_contents($root . '/app/Repositories/ConfigurationRepository.php');
$view = file_get_contents($root . '/views/product_library_v2.php');
$script = file_get_contents($root . '/assets/js/app.js');

foreach ([
    'mp.snapshot_json AS material_center_snapshot_json',
    "'commercial_product_parameters'",
    "'product_parameters' => \$productParameters",
    'productParameterTechnical',
    'cutout_size_text',
    'luminous_flux_text',
    'dimming_method_text',
    'protection_class',
    'mp.snapshot_json)',
] as $marker) {
    if (!str_contains($catalogRepository, $marker)) {
        throw new RuntimeException("commercial catalog product-parameter bridge missing: {$marker}");
    }
}

foreach ([
    'product_parameters',
    'material_center_snapshot_json',
    "LEFT JOIN mc_products mp ON mp.legacy_table='naming_models'",
] as $marker) {
    if (!str_contains($configurationRepository, $marker)) {
        throw new RuntimeException("configuration catalog product-parameter bridge missing: {$marker}");
    }
}

foreach ([
    'product-power',
    "\$ccTechnical(\$row,'dimensions')",
    "\$ccTechnical(\$row,'cutout')",
    "\$ccTechnical(\$row,'power')",
] as $marker) {
    if (!str_contains($view, $marker)) {
        throw new RuntimeException("product library view parameter marker missing: {$marker}");
    }
}

foreach ([
    'technical.luminous_flux',
    'technical.ugr',
    'technical.dimming_method',
    'technical.protection_class',
    'technical.tilt',
    'technical.rotation',
    'technical.best_for',
] as $marker) {
    if (!str_contains($script, $marker)) {
        throw new RuntimeException("product detail drawer parameter marker missing: {$marker}");
    }
}

echo "Commercial product-parameter bridge contract: OK\n";
