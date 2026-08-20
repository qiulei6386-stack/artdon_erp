<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'PowerEditorService' => $root . '/app/Services/PowerEditorService.php',
    'MaterialMasterService' => $root . '/app/Services/MaterialMasterService.php',
    'AdaptationService' => $root . '/app/Services/AdaptationService.php',
    'adaptation_v2_foundation' => $root . '/adaptation_v2/lib/foundation.php',
];

foreach ($files as $name => $file) {
    if (!is_file($file)) throw new RuntimeException("$name missing: $file");
}

$powerEditor = file_get_contents($files['PowerEditorService']);
foreach ([
    'is_dip_switch=? WHERE material_id=?',
    "count(\$currents['values']) > 1 ? 1 : 0",
] as $marker) {
    if (!str_contains($powerEditor, $marker)) throw new RuntimeException("power editor multi-current marker missing: $marker");
}

$master = file_get_contents($files['MaterialMasterService']);
foreach ([
    'copyPowerSupplyDomain',
    'mc_power_supply_current_options',
    'mc_power_supply_dimming_modes',
    'power_domain_copied',
] as $marker) {
    if (!str_contains($master, $marker)) throw new RuntimeException("power revision draft marker missing: $marker");
}

$adaptation = file_get_contents($files['AdaptationService']);
foreach ([
    'current_options_ma',
    'currentRequirementMatches',
    'discreteCurrentOptions',
    '电源可选',
] as $marker) {
    if (!str_contains($adaptation, $marker)) throw new RuntimeException("legacy adaptation multi-current marker missing: $marker");
}

$foundation = file_get_contents($files['adaptation_v2_foundation']);
foreach ([
    'pa2_power_current_options',
    'current_options_ma',
    '不匹配电源拨码电流',
    '输出电流档位符合配置逻辑',
] as $marker) {
    if (!str_contains($foundation, $marker)) throw new RuntimeException("adaptation v2 multi-current marker missing: $marker");
}

echo "power_multi_current_contract ok\n";
