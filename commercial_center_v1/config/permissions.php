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
    'roles' => ['管理员', '商务经理', '业务员', '跟单', '财务', '查看者'],
    'actions' => ['menu.view', 'page.view', 'record.create', 'record.edit', 'record.delete', 'record.approve', 'record.export'],
    'audit' => [
        'required_for' => ['record.create', 'record.edit', 'record.delete', 'record.approve', 'record.export'],
        'sink' => 'cc_activity_logs',
    ],
];
