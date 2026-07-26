<?php
declare(strict_types=1);

$permissions = [
    ['material_center.view', 'view', '访问物料中心', 'low'],
    ['material_center.material.view', 'view', '查看物料', 'low'],
    ['material_center.material.create', 'create', '新建物料', 'medium'],
    ['material_center.material.copy', 'create', '复制新增物料', 'medium'],
    ['material_center.material.edit', 'edit', '编辑物料', 'medium'],
    ['material_center.material.batch', 'batch', '批量设置物料', 'high'],
    ['material_center.material.lifecycle', 'lifecycle', '变更物料生命周期', 'high'],
    ['material_center.material.formalize', 'formalize', '物料转正式', 'high'],
    ['material_center.material.reject', 'reject', '驳回物料', 'high'],
    ['material_center.material.disable', 'disable', '停用物料', 'high'],
    ['material_center.material.archive', 'archive', '归档物料', 'high'],
    ['material_center.material.delete_draft', 'delete_draft', '删除物料草稿', 'high'],
    ['material_center.material.merge', 'merge', '合并重复物料', 'dangerous'],
    ['material_center.import', 'import', '导入物料', 'high'],
    ['material_center.export', 'export', '导出物料', 'high'],
    ['material_center.purchase_price.view', 'purchase_price_view', '查看采购价', 'high'],
    ['material_center.purchase_price.edit', 'purchase_price_edit', '编辑采购价', 'dangerous'],
    ['material_center.supplier.manage', 'supplier_manage', '维护供应商', 'high'],
    ['material_center.adaptation.manage', 'adaptation_manage', '维护产品适配', 'high'],
    ['material_center.approve', 'approve', '物料审批', 'high'],
    ['material_center.documents.manage', 'documents_manage', '维护物料文档', 'medium'],
    ['material_center.permissions.manage', 'permissions_manage', '维护物料权限', 'dangerous'],
    ['material_center.settings.view', 'settings_view', '查看物料中心设置', 'low'],
    ['material_center.settings.manage_self', 'settings_manage_self', '维护个人物料设置', 'medium'],
    ['material_center.settings.manage_global', 'settings_manage_global', '维护全局物料设置', 'high'],
    ['material_center.field.sensitive', 'field_sensitive', '查看物料敏感字段', 'high'],
    ['material_center.power.standardize', 'power_standardize', '整理电源资料', 'medium'],
    ['material_center.power.confirm', 'power_confirm', '确认正式电源', 'high'],
    ['material_center.power.rules.view', 'power_rules_view', '查看产品电源规则', 'low'],
    ['material_center.power.rules.manage', 'power_rules_manage', '维护产品电源规则', 'high'],
    ['material_center.power.simulate', 'power_simulate', '运行电源匹配模拟', 'medium'],
];

$values = [];
foreach ($permissions as [$key, $action, $description, $risk]) {
    $values[] = sprintf(
        "(%s,'material_center',%s,%s,%s,NOW())",
        var_export($key, true),
        var_export($action, true),
        var_export($description, true),
        var_export($risk, true)
    );
}

$allKeys = array_column($permissions, 0);
$viewKeys = [
    'material_center.view',
    'material_center.material.view',
    'material_center.settings.view',
    'material_center.settings.manage_self',
    'material_center.power.rules.view',
];
$engineeringKeys = array_values(array_diff($allKeys, [
    'material_center.permissions.manage',
    'material_center.settings.manage_global',
    'material_center.purchase_price.view',
    'material_center.purchase_price.edit',
    'material_center.supplier.manage',
    'material_center.import',
    'material_center.export',
]));
$purchaseKeys = array_values(array_unique(array_merge($viewKeys, [
    'material_center.material.create',
    'material_center.material.copy',
    'material_center.material.edit',
    'material_center.material.batch',
    'material_center.import',
    'material_center.export',
    'material_center.purchase_price.view',
    'material_center.purchase_price.edit',
    'material_center.supplier.manage',
    'material_center.documents.manage',
])));
$managerKeys = array_values(array_diff($allKeys, ['material_center.permissions.manage']));

$roleInsert = static function (array $roleKeys, array $permissionKeys): string {
    $roles = implode(',', array_map(static fn(string $key): string => var_export($key, true), $roleKeys));
    $permissions = implode(',', array_map(static fn(string $key): string => var_export($key, true), $permissionKeys));
    return "INSERT IGNORE INTO crm_role_permissions(role_id,permission_key)
        SELECT r.id,p.permission_key FROM crm_roles r JOIN crm_permissions p
        WHERE r.role_key IN ($roles) AND p.permission_key IN ($permissions)";
};

return [
    'version' => '20260726_013_unified_permission_center',
    'description' => 'Move Material Center authorization to the unified CRM account and permission center',
    'up' => [
        'INSERT INTO crm_permissions(permission_key,module,action,description,risk_level,created_at) VALUES '
            . implode(',', $values)
            . ' ON DUPLICATE KEY UPDATE module=VALUES(module),action=VALUES(action),description=VALUES(description),risk_level=VALUES(risk_level)',
        $roleInsert(['admin', 'boss_admin'], $allKeys),
        $roleInsert(['manager', 'team_leader'], $managerKeys),
        $roleInsert(['engineering'], $engineeringKeys),
        $roleInsert(['purchase'], $purchaseKeys),
        $roleInsert(['sales', 'marketing', 'finance', 'production', 'viewer'], $viewKeys),
        "INSERT INTO crm_user_permissions(user_id,permission_key,effect,created_at)
            SELECT CAST(g.subject_id AS UNSIGNED),g.permission_key,g.effect,NOW()
            FROM mc_permission_grants g
            JOIN crm_users u ON u.id=CAST(g.subject_id AS UNSIGNED)
            JOIN crm_permissions p ON p.permission_key=g.permission_key COLLATE utf8mb4_unicode_ci
            WHERE g.subject_type='user' AND g.subject_id REGEXP '^[0-9]+$'
            ON DUPLICATE KEY UPDATE effect=VALUES(effect)",
        "INSERT IGNORE INTO crm_role_permissions(role_id,permission_key)
            SELECT r.id,g.permission_key
            FROM mc_permission_grants g
            JOIN crm_roles r ON r.role_key=g.subject_id COLLATE utf8mb4_unicode_ci
            JOIN crm_permissions p ON p.permission_key=g.permission_key COLLATE utf8mb4_unicode_ci
            WHERE g.subject_type='role' AND g.effect='allow'",
    ],
    'down' => [],
];
