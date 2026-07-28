<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$page = file_get_contents($root.'/adaptation/index.php');
$script = file_get_contents($root.'/assets/js/adaptation-shell.js');
$service = file_get_contents($root.'/app/Services/AdaptationService.php');
$api = file_get_contents($root.'/api/v1/adaptation.php');

foreach ([
    '完整标准配置模板',
    'data-selected-configuration',
] as $marker) {
    if (!str_contains($page, $marker)) throw new RuntimeException("adaptation page marker missing: {$marker}");
}

foreach ([
    'renderPersistentConfiguration',
    '已选物料持续显示',
    'data-power-rule-form',
    '保存电源关键范围',
    "post('save_power_rules'",
    '套用完整标准模板',
] as $marker) {
    if (!str_contains($script, $marker)) throw new RuntimeException("adaptation interaction missing: {$marker}");
}

foreach ([
    'POWER_RULE_FIELDS',
    'savePowerRules',
    "'power_rule' => $this->productPowerRule",
    'save_power_rules_from_adaptation',
] as $marker) {
    if (!str_contains($service, $marker)) throw new RuntimeException("adaptation power rule service missing: {$marker}");
}

if (!str_contains($api, "'save_power_rules'")) throw new RuntimeException('adaptation power rule endpoint missing');

echo "Product adaptation persistent selection and inline power contract: OK\n";
