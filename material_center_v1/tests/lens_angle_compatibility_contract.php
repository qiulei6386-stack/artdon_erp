<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$drawer = file_get_contents($root.'/components/layout_bottom.php');
$script = file_get_contents($root.'/assets/js/category-editor.js');
$css = file_get_contents($root.'/assets/css/app.css');
$service = file_get_contents($root.'/app/Services/LensAngleCompatibilityService.php');
$adaptation = file_get_contents($root.'/app/Services/AdaptationService.php');
$materialMaster = file_get_contents($root.'/app/Services/MaterialMasterService.php');
$api = file_get_contents($root.'/api/v1/lens-angle-compatibility.php');
$migration = file_get_contents($root.'/database/migrations/20260819_025_lens_chip_angle_compatibility.php');

foreach ([
    'data-category-tab="lens_angle"',
    '芯片角度适配',
    'data-lens-angle-list',
    'data-lens-angle-add',
] as $marker) {
    if (!str_contains($drawer, $marker)) throw new RuntimeException("lens angle drawer UI missing: {$marker}");
}

foreach ([
    'loadLensAngles',
    'saveLensAngles',
    'collectLensRows',
    'data-lens-chip',
    'data-lens-actual-angle',
    '芯片角度适配表每一行都必须填写实际角度',
] as $marker) {
    if (!str_contains($script, $marker)) throw new RuntimeException("lens angle editor script missing: {$marker}");
}

foreach ([
    '.mc-lens-angle-pane',
    '.mc-lens-angle-row',
    '.mc-category-editor-drawer[data-category-code="optical"]',
] as $marker) {
    if (!str_contains($css, $marker)) throw new RuntimeException("lens angle layout missing: {$marker}");
}

foreach ([
    'class LensAngleCompatibilityService',
    'detail(int $lensMaterialId)',
    'save(int $lensMaterialId',
    'mc_lens_chip_angle_compatibilities',
    '只有草稿光学物料可以编辑芯片角度适配',
] as $marker) {
    if (!str_contains($service, $marker)) throw new RuntimeException("lens angle service missing: {$marker}");
}

foreach ([
    'selectedChipForProduct',
    'opticalBeamEvidence',
    'lensAngleRowsForSelectedChip',
    '透镜已维护芯片角度适配，但未覆盖当前芯片',
    'optical_beam_angle_options',
] as $marker) {
    if (!str_contains($adaptation, $marker)) throw new RuntimeException("adaptation lens angle matching missing: {$marker}");
}

foreach ([
    'mc_lens_chip_angle_compatibilities',
    'lens_angle_compatibility_copied',
] as $marker) {
    if (!str_contains($materialMaster, $marker)) throw new RuntimeException("material draft/revision lens angle copy missing: {$marker}");
}

foreach ([
    'LensAngleCompatibilityService',
    'material_center.view',
    'material_center.material.edit',
    'rows_json',
] as $marker) {
    if (!str_contains($api, $marker)) throw new RuntimeException("lens angle API missing: {$marker}");
}

foreach ([
    'beam_angle_options',
    'mc_lens_chip_angle_compatibilities',
    'optical.beam_angle_options',
    '光束角选项',
    "'down'",
] as $marker) {
    if (!str_contains($migration, $marker)) throw new RuntimeException("lens angle migration missing: {$marker}");
}

echo "Lens chip angle compatibility contract: OK\n";
