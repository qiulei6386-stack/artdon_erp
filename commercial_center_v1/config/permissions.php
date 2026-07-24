<?php
declare(strict_types=1);

return [
    'mode' => 'legacy-read-only',
    'default_access' => 'authenticated',
    'checks' => [
        'commercial_center.view' => [
            'legacy_aliases' => ['dashboard.view', 'crm.view'],
            'fallback' => 'authenticated',
        ],
    ],
    'writes_to_legacy_permission_tables' => false,
];
