<?php

$root = dirname(__DIR__);
$js = file_get_contents($root . '/assets/crm/crm.js');

foreach ([
    "country: '', city: ''",
    "keywords: '', languages: ''",
    'var countryLanguages = {',
    "India: ['en', 'hi']",
    "Indonesia: ['en', 'id']",
    "'United Arab Emirates': ['en', 'ar']",
    "Vietnam: ['en', 'vi']",
    'var taskLanguagesForCountry = function (country)',
    'data.languages = taskLanguagesForCountry(data.country).join',
    'var replaceLines = function (name, lines)',
    "replaceLines('keywords', preset.keywords || [])",
    "replaceLines('target_products', preset.products || [])",
    "replaceLines('target_project_types', preset.projects || [])",
] as $marker) {
    if (!str_contains($js, $marker)) {
        throw new RuntimeException("Radar task editor country/language marker missing: {$marker}");
    }
}

foreach ([
    "row = row || { task_name: '', country: '越南'",
    "keywords: 'Vietnam architectural lighting distributor\\nVietnam commercial lighting supplier'",
    "data.languages = 'en\\nvi'",
] as $forbidden) {
    if (str_contains($js, $forbidden)) {
        throw new RuntimeException("Radar task editor must not keep hardcoded Vietnam defaults: {$forbidden}");
    }
}

if (!preg_match("/materialize = function \\(value\\).*?replace\\(\\/\\\\s\\+\\/g, ' '\\)\\.trim\\(\\)/s", $js)) {
    throw new RuntimeException('Radar task editor must materialize country/city placeholders without leaving extra whitespace.');
}

echo "CRM radar task editor country/language contract passed.\n";
