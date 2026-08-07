<?php

$root = dirname(__DIR__);
$radar = file_get_contents($root . '/radar.php');
$js = file_get_contents($root . '/assets/crm/crm.js');

foreach ([
    "'service_key' => 'dataforseo'",
    "'service_name' => 'DataForSEO SERP API'",
    'https://api.dataforseo.com/v3/serp/google/organic/live/advanced',
    'function radar_is_dataforseo_search_service',
    'api.dataforseo.com',
    'function radar_dataforseo_auth_header',
    'Authorization: Basic',
    'API login:API password',
    'function radar_dataforseo_payload',
    'function radar_dataforseo_request',
    'function radar_dataforseo_error_is_invalid_location',
    'Bosnia and Herzegovina',
    "'keyword' => \$keyword",
    "'depth' => max(1, min(100, \$limit))",
    "'language_code' => radar_dataforseo_language_code(\$task)",
    "'location_name'",
    "radar_dataforseo_payload(\$task, \$keyword, \$limit, false)",
    'function radar_dataforseo_rows',
    "\$json['tasks']",
    "\$task['result']",
    "\$result['items']",
    "\$item['description']",
    'radar_dataforseo_search_service_call',
    "'method' => 'POST'",
] as $marker) {
    if (!str_contains($radar, $marker)) {
        throw new RuntimeException("DataForSEO radar backend marker missing: {$marker}");
    }
}

foreach ([
    'DataForSEO：service_key=dataforseo',
    'https://api.dataforseo.com/v3/serp/google/organic/live/advanced',
    'DataForSEO API login:API password',
    'brave / dataforseo',
    'Brave Search / DataForSEO SERP API',
] as $marker) {
    if (!str_contains($js, $marker)) {
        throw new RuntimeException("DataForSEO radar UI marker missing: {$marker}");
    }
}

if (!preg_match('/radar_is_dataforseo_search_service\(\$service\).*?radar_dataforseo_search_service_call\(\$task, \$keyword, \$service, \$limit, \$apiKey\)/s', $radar)) {
    throw new RuntimeException('Search worker must route DataForSEO services to the POST + Basic Auth adapter before generic GET handling.');
}

if (preg_match('/Authorization: Bearer[^\n]+DataForSEO/i', $radar)) {
    throw new RuntimeException('DataForSEO must not use Bearer auth; it requires Basic Auth with API login and API password.');
}

if (!preg_match('/function radar_dataforseo_payload\(array \$task, string \$keyword, int \$limit, bool \$withLocation = true\): array/', $radar)) {
    throw new RuntimeException('DataForSEO payload must support disabling location_name for retry fallback.');
}

if (!preg_match('/if \(\$withLocation && \$location !== \'\'\) \$row\[\'location_name\'\] = \$location;/', $radar)) {
    throw new RuntimeException('DataForSEO payload must only send location_name when explicit location mapping exists and fallback is enabled.');
}

if (!preg_match('/radar_dataforseo_error_is_invalid_location\(\$message\).*?radar_dataforseo_payload\(\$task, \$keyword, \$limit, false\)/s', $radar)) {
    throw new RuntimeException('DataForSEO invalid location_name errors must retry once without location_name.');
}

echo "CRM radar DataForSEO search contract passed.\n";
