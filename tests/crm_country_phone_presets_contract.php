<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$items = require $root . '/includes/crm_country_region_defaults.php';
$settings = file_get_contents($root . '/crm_settings_config.php');
$script = file_get_contents($root . '/assets/crm/crm.js');
if (!is_array($items) || $settings === false || $script === false) {
    throw new RuntimeException('CRM country phone preset sources are not readable');
}
if (count($items) !== 249) {
    throw new RuntimeException('CRM country presets must contain exactly 249 ISO entries');
}

$keys = [];
foreach ($items as $item) {
    $key = (string)($item[0] ?? '');
    $extra = is_array($item[6] ?? null) ? $item[6] : [];
    if (!preg_match('/^[A-Z]{2}$/', $key)) {
        throw new RuntimeException("invalid ISO alpha-2 key: {$key}");
    }
    if (($extra['iso'] ?? '') !== $key) {
        throw new RuntimeException("ISO key mismatch: {$key}");
    }
    if (!preg_match('/^[A-Z]{3}$/', (string)($extra['iso3'] ?? ''))) {
        throw new RuntimeException("invalid ISO alpha-3 value: {$key}");
    }
    if (!preg_match('/^\d{3}$/', (string)($extra['numeric'] ?? ''))) {
        throw new RuntimeException("invalid ISO numeric value: {$key}");
    }
    if (!preg_match('/^\+\d{1,4}$/', (string)($extra['phone_code'] ?? ''))) {
        throw new RuntimeException("invalid international phone code: {$key}");
    }
    if (isset($keys[$key])) {
        throw new RuntimeException("duplicate ISO key: {$key}");
    }
    $keys[$key] = true;
}

foreach (['CN', 'HK', 'MO', 'US', 'GB', 'IN', 'AQ', 'PN', 'TF'] as $required) {
    if (!isset($keys[$required])) throw new RuntimeException("required country preset missing: {$required}");
}

foreach ([
    'function crm_country_region_defaults',
    'function crm_sync_country_region_presets',
    '20260727_country_region_249_v1',
    "'country_region' => crm_country_region_defaults()",
    "if (\$type === 'country_region')",
    '国际电话区号必须以 + 开头并只包含数字',
] as $marker) {
    if (!str_contains($settings, $marker)) throw new RuntimeException("country preset backend marker missing: {$marker}");
}

foreach ([
    "this.dictItems('country_region').map",
    'extra.phone_code',
    'country_phone_code',
    'country_region_name',
    'country_pinned',
    'this.countryKeyFromValue(country)',
] as $marker) {
    if (!str_contains($script, $marker)) throw new RuntimeException("country phone UI marker missing: {$marker}");
}

echo "CRM country phone presets contract: OK (249 entries)\n";
