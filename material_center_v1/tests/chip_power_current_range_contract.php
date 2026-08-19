<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$migration = file_get_contents($root . '/database/migrations/20260819_024_chip_power_current_ranges.php');
$fieldsMigration = file_get_contents($root . '/database/migrations/20260726_010_complete_category_fields.php');
$organizer = file_get_contents($root . '/app/Services/SourceMaterialOrganizerService.php');
$adaptation = file_get_contents($root . '/app/Services/AdaptationService.php');
$dashboard = file_get_contents($root . '/app/Services/MaterialDashboardService.php');
$categoryFields = file_get_contents($root . '/app/Services/CategoryFieldService.php');
$adaptationJs = file_get_contents($root . '/assets/js/adaptation-v3.js');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

foreach ([
    'ADD COLUMN min_power_w',
    'ADD COLUMN current_min_ma',
    'ADD COLUMN current_max_ma',
    'ADD COLUMN cct_k',
    'min_power_w=COALESCE(min_power_w,rated_power_w)',
    'current_max_ma=COALESCE(current_max_ma,current_ma)',
    "field_code IN('chip.rated_power_w','chip.current_ma','chip.cct_min_k','chip.cct_max_k')",
] as $marker) {
    $assert(str_contains($migration, $marker), "chip range migration missing {$marker}");
}

foreach ([
    "'chip','chip.min_power_w','最小功率'",
    "'chip','chip.max_power_w','最大功率'",
    "'chip','chip.current_min_ma','最小电流'",
    "'chip','chip.current_max_ma','最大电流'",
    "'chip','chip.cct_k','色温'",
] as $marker) {
    $assert(str_contains($fieldsMigration, $marker), "chip field registry missing {$marker}");
}

foreach ([
    "'power_range_w'",
    "'current_range_ma'",
    "'chip.max_power_w'",
    "'chip.current_max_ma'",
    "'chip.cct_k'",
] as $marker) {
    $assert(str_contains($organizer, $marker), "chip source parser missing {$marker}");
}

foreach ([
    'chip_min_power_w',
    'chip_current_min_ma',
    'chip_current_max_ma',
    'numberRange',
    "'芯片最小功率'",
    "'芯片最大电流'",
] as $marker) {
    $assert(str_contains($adaptation, $marker), "adaptation chip range support missing {$marker}");
}

$assert(str_contains($dashboard, 's.cct_k IS NULL'), 'chip pending dashboard must use single CCT');
$assert(str_contains($categoryFields, 'legacyChipRangeValues') && str_contains($categoryFields, 'spec_summary') && str_contains($categoryFields, 'chip.current_min_ma'), 'chip field values must fallback from legacy summary');
$assert(str_contains($adaptationJs, 'specRange') && str_contains($adaptationJs, 'chip_current_max_ma'), 'V3 candidate UI must show chip ranges');

echo "Chip power/current range contract: OK\n";
