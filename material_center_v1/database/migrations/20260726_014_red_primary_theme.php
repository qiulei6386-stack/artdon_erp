<?php
declare(strict_types=1);

return [
    'version' => '20260726_014_red_primary_theme',
    'description' => 'Use the global Material Center red primary palette for existing and future pages',
    'up' => [
        "UPDATE mc_ui_settings SET default_json='\"#d60000\"',updated_at=NOW() WHERE setting_key='theme.primary'",
        "UPDATE mc_ui_setting_scopes SET value_json='\"#d60000\"',version=version+1,updated_at=NOW() WHERE setting_key='theme.primary'",
        "UPDATE mc_ui_themes SET tokens_json=JSON_SET(tokens_json,'$.primary','#d60000'),updated_at=NOW() WHERE status='active'",
    ],
    'down' => [],
];
