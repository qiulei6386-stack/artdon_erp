<?php

$root = dirname(__DIR__);
$js = file_get_contents($root . '/assets/crm/crm.js');
$radar = file_get_contents($root . '/radar.php');

foreach ([
    'data-task-prompt-workbench',
    'name="prompt_template_id"',
    'name="keyword_prompt"',
    'data-task-prompt-replace',
    'data-task-prompt-append',
    'data-task-prompt-copy',
    'var buildPromptText = function (tpl)',
    "radarPost('radar_templates_list', { status: 'active' })",
    "radarPost('radar_template_preview', promptPreviewPayload(tpl))",
    "replaceLines('keywords', keywords)",
    "addLines('keywords', keywords)",
    'name="ai_prompt_template"',
    'cfg.ai_prompt_template = String(data.ai_prompt_template || \'\').trim();',
] as $marker) {
    if (!str_contains($js, $marker)) {
        throw new RuntimeException("Radar prompt template workflow JS marker missing: {$marker}");
    }
}

foreach ([
    "'ai_prompt_template'",
] as $marker) {
    if (!str_contains($radar, $marker)) {
        throw new RuntimeException("Radar prompt template workflow PHP marker missing: {$marker}");
    }
}

echo "CRM radar prompt template workflow contract passed.\n";
