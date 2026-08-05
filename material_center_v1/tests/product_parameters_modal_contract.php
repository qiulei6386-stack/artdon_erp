<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$file = $root . '/material_center_v1/product_parameters.php';

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

mc_param_modal_assert(str_contains($source, '.mc-param-modal{width:min(1280px,calc(100vw - 48px))'), 'Product parameter modal uses the unified wide V2 dialog shell');
mc_param_modal_assert(str_contains($source, 'mc-param-close') && str_contains($source, '>关闭</button>'), 'Product parameter modal uses text close button like V2 logic dialogs');
mc_param_modal_assert(str_contains($source, '.mc-param-form-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}'), 'Product parameter form uses the unified four-column dialog grid');
mc_param_modal_assert(str_contains($source, 'mc-param-guide') && str_contains($source, '建议先填会影响适配判断的硬条件'), 'Product parameter modal keeps the green dashed guidance block');
mc_param_modal_assert(str_contains($source, 'mc-param-mode-note') && str_contains($source, '供芯片、电源、光学适配共同使用'), 'Product parameter modal explains it is shared product master data');
foreach (['电气参数', '光学与外观', '结构尺寸'] as $section) {
    mc_param_modal_assert(str_contains($source, $section), "Product parameter modal contains section {$section}");
}
foreach (['power_min_w', 'current_min_ma', 'voltage_min_v', 'beam_angle', 'optical_size', 'length_mm', 'notes'] as $field) {
    mc_param_modal_assert(str_contains($source, 'name="' . $field . '"'), "Product parameter modal keeps field {$field}");
}

echo "product parameters modal contract passed.\n";
