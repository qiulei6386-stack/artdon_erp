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
    "'keyword' => \$keyword",
    "'depth' => max(1, min(100, \$limit))",
    "'language_code' => radar_dataforseo_language_code(\$task)",
    "'location_name'",
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

echo "CRM radar DataForSEO search contract passed.\n";
