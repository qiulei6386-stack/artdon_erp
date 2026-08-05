<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$file = $root . '/material_center_v1/product_parameters.php';
$apiFile = $root . '/material_center_v1/api/v1/product-parameters.php';

function mc_param_modal_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
    echo "[PASS] {$message}\n";
}

if (!is_file($file)) {
    fwrite(STDERR, "[FAIL] Missing product parameters page: {$file}\n");
    exit(1);
}

$source = (string)file_get_contents($file);
$apiSource = is_file($apiFile) ? (string)file_get_contents($apiFile) : '';

mc_param_modal_assert(str_contains($source, '.mc-param-modal{width:min(1280px,calc(100vw - 48px))'), 'Product parameter modal uses the unified wide V2 dialog shell');
mc_param_modal_assert(str_contains($source, 'mc-param-close') && str_contains($source, '>关闭</button>'), 'Product parameter modal uses text close button like V2 logic dialogs');
mc_param_modal_assert(str_contains($source, '.mc-param-form-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}'), 'Product parameter form uses the unified four-column dialog grid');
mc_param_modal_assert(str_contains($source, 'mc-param-guide') && str_contains($source, '建议先填会影响适配判断的硬条件'), 'Product parameter modal keeps the green dashed guidance block');
mc_param_modal_assert(str_contains($source, 'mc-param-mode-note') && str_contains($source, '供芯片、电源、光学适配共同使用'), 'Product parameter modal explains it is shared product master data');
foreach (['规格表参数', '电气参数', '光学与外观', '结构尺寸', '自定义参数'] as $section) {
    mc_param_modal_assert(str_contains($source, $section), "Product parameter modal contains section {$section}");
}
foreach (['product_type', 'cutout_size_text', 'dimensions_text', 'power_text', 'luminous_flux_text', 'tilt_angle', 'rotation_angle', 'beam_angle_text', 'cct_text', 'cri_text', 'ugr_text', 'dimming_method_text', 'best_for', 'power_min_w', 'current_min_ma', 'voltage_min_v', 'beam_angle', 'optical_size', 'length_mm', 'notes'] as $field) {
    mc_param_modal_assert(str_contains($source, 'name="' . $field . '"'), "Product parameter modal keeps field {$field}");
}
foreach (['recessed', 'track', 'magnetic', 'surface', 'linear', 'custom'] as $type) {
    mc_param_modal_assert(str_contains($source, 'value="' . $type . '"'), "Product parameter modal supports product type {$type}");
}
foreach (['custom_label[]', 'custom_value[]', 'custom_unit[]', 'custom_group[]'] as $field) {
    mc_param_modal_assert(str_contains($source, 'name="' . $field . '"'), "Product parameter modal supports custom field input {$field}");
}
mc_param_modal_assert(str_contains($source, 'presetFields') && str_contains($source, 'data-param-preset="track"') && str_contains($source, 'data-add-custom-param'), 'Product parameter modal supports type presets and blank custom fields');
mc_param_modal_assert(str_contains($apiSource, 'function mc_pp_custom_fields') && str_contains($apiSource, "'custom_fields'"), 'Product parameter API stores sanitized custom fields');
foreach (['cutout_size_text', 'dimensions_text', 'luminous_flux_text', 'ugr_text', 'best_for', 'dimming_method_text'] as $field) {
    mc_param_modal_assert(str_contains($apiSource, "'" . $field . "' => mc_pp_text"), "Product parameter API accepts field {$field}");
}

echo "product parameters modal contract passed.\n";
