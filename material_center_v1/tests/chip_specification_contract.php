<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn(string $path): string => (string) file_get_contents($root.'/'.$path);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$migration = $read('material_center_v1/database/migrations/20260727_018_chip_specification_templates.php');
$service = $read('material_center_v1/app/Services/ChipSpecificationService.php');
$adaptation = $read('material_center_v1/app/Services/AdaptationService.php');
$adaptationUi = $read('material_center_v1/assets/js/adaptation-shell.js');
$chipUi = $read('material_center_v1/assets/js/chip-specifications.js');
$repository = $read('commercial_center_v1/app/Repositories/ConfigurationRepository.php');
$engine = $read('commercial_center_v1/app/Services/ConfigurationEngineService.php');
$page = $read('material_center_v1/adaptation/index.php');
$layout = $read('material_center_v1/components/layout_bottom.php');

foreach ([
    'mc_chip_spec_templates',
    'mc_chip_spec_template_versions',
    'mc_chip_material_templates',
    'mc_chip_spec_variants',
    'mc_adaptation_option_chip_variants',
] as $table) {
    $assert(str_contains($migration, $table), "migration missing {$table}");
}
$assert(str_contains($migration, 'needs_confirmation'), 'legacy chip ranges must retain a confirmation marker');
$assert(str_contains($service, 'current_version_no') && str_contains($service, 'applied_version_no'), 'template version tracking is missing');
$assert(str_contains($service, 'variantHasApprovedUse'), 'approved chip variants are not protected');
$assert(str_contains($service, 'count($materialIds) > 1000'), '1000-chip batch guard is missing');
$assert(str_contains($adaptation, 'configuration_overview'), 'adaptation workspace lacks a configuration overview');
$assert(str_contains($adaptation, 'selected_chip_variant_count'), 'adaptation options lack chip variant counts');
$assert(str_contains($adaptationUi, "page.dataset.stage = !state.workspace ? 'products'"), 'progressive three-stage workspace is missing');
$assert(str_contains($page, 'data-configuration-overview-full'), 'full configuration passport modal is missing');
$assert(str_contains($chipUi, 'enabledCombinations'), 'per-combination checkbox exclusion is missing');
$assert(str_contains($chipUi, 'preview_apply') && str_contains($chipUi, 'apply_templates'), 'template impact preview or explicit sync is missing');
$materialFormStart = strpos($layout, '<form data-category-editor-form>');
$materialFormEnd = strpos($layout, '</form>', $materialFormStart);
$templateFormStart = strpos($layout, 'data-chip-template-form');
$assert($materialFormStart !== false && $materialFormEnd !== false && $templateFormStart !== false && $materialFormEnd < $templateFormStart, 'chip template form must not be nested in the material form');
$assert(str_contains($repository, '_variant_') && str_contains($repository, "'chip_variant'=>\$chipSnapshot"), 'commercial catalog does not expose concrete chip variants');
$assert(str_contains($repository, "(int)\$row['is_default']&&(int)\$variant['option_variant_default']"), 'commercial default must combine the default material and default chip variant');
$assert(str_contains($engine, "'chip_variant'=>\$option['chip_variant']??null"), 'quote adaptation snapshot omits the concrete chip variant');

echo "Chip specification templates and quote bridge contract: OK\n";
