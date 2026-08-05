<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$indexFile = $root . '/material_center_v1/adaptation_v2/index.php';
$foundationFile = $root . '/material_center_v1/adaptation_v2/lib/foundation.php';

function logic_dialog_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
    echo "[PASS] {$message}\n";
}

function logic_dialog_read(string $file): string
{
    if (!is_file($file)) {
        fwrite(STDERR, "[FAIL] Missing file: {$file}\n");
        exit(1);
    }
    return (string)file_get_contents($file);
}

$index = logic_dialog_read($indexFile);
$foundation = logic_dialog_read($foundationFile);

logic_dialog_assert(str_contains($index, '[data-logic-zone].is-hidden{display:none!important}'), 'All logic-zone fields, not only section titles, are hidden when inactive');
logic_dialog_assert(str_contains($index, "field.disabled=!visible"), 'Hidden logic fields are disabled before submit');

foreach ([
    'chip' => ['chip_brand_keyword', 'chip_series_keyword', 'cct_k', 'cri_min'],
    'driver' => ['driver_type', 'power_min_w', 'power_max_w', 'dimming_mode'],
    'optical' => ['beam_angle', 'optical_type', 'lens_material', 'optical_diameter_mm', 'optical_height_mm', 'optical_keyword'],
    'general' => ['part_size_keyword', 'part_material_keyword', 'part_color_keyword', 'part_usage_keyword'],
] as $zone => $fields) {
    foreach ($fields as $field) {
        logic_dialog_assert(str_contains($index, 'data-logic-zone="' . $zone . '"') && str_contains($index, 'name="' . $field . '"'), "{$zone} dialog owns field {$field}");
    }
}

logic_dialog_assert(str_contains($foundation, "'chip' => ['current_min_ma','current_max_ma','voltage_min_v','voltage_max_v','cct_k','cri_min','chip_brand_keyword','chip_series_keyword','note']"), 'Server sanitizer keeps only chip/source logic fields');
logic_dialog_assert(str_contains($foundation, "'driver' => ['power_min_w','power_max_w','current_min_ma','current_max_ma','voltage_min_v','voltage_max_v','dimming_mode','note']"), 'Server sanitizer keeps only driver logic fields');
logic_dialog_assert(str_contains($foundation, "'optical' => ['beam_angle','optical_type','lens_material','optical_diameter_mm','optical_height_mm','optical_size_keyword','optical_keyword','note']"), 'Server sanitizer keeps only optical logic fields');

echo "adaptation v2 logic dialog contract passed.\n";
