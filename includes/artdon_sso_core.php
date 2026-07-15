<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function artdon_sso_db(): PDO
{
    return db();
}

function artdon_sso_ip(): string
{
    return function_exists('client_ip') ? client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');
}

function artdon_sso_table_exists(string $table): bool
{
    return db_table_exists($table);
}

function artdon_sso_current_user(bool $sync = true): array
{
    $user = current_user();
    if (!$user) {
        return [];
    }
    $user['display_name'] = $user['real_name'] ?: $user['username'];
    $user['department'] = $user['department_name'] ?? '';
    $user['role'] = $user['role_key'] ?? '';
    return $user;
}

function artdon_sso_login_url(string $returnUrl = ''): string
{
    $returnUrl = $returnUrl !== '' ? $returnUrl : ($_SERVER['REQUEST_URI'] ?? 'index.php');
    $returnUrl = str_replace(["\r", "\n"], '', $returnUrl);
    if ($returnUrl === '' || preg_match('#^[a-z][a-z0-9+.-]*:#i', $returnUrl) || strpos($returnUrl, '//') === 0 || strpos($returnUrl, '\\') !== false) {
        $returnUrl = 'index.php';
    }
    return 'login.php?redirect=' . rawurlencode($returnUrl);
}

function artdon_sso_json_error(string $msg, int $code = 403, array $extra = []): void
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok' => false, 'success' => false, 'error' => $msg, 'msg' => $msg], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function artdon_sso_role_key(array $user): string
{
    return (string)($user['role_key'] ?? $user['role'] ?? '');
}

function artdon_sso_role_label($role): string
{
    $roleKey = is_array($role) ? artdon_sso_role_key($role) : (string)$role;
    $map = [
        'super_admin' => '超级管理员',
        'admin' => '管理员',
        'manager' => '主管',
        'sales' => '业务员',
        'marketing' => '推广',
        'finance' => '财务',
        'viewer' => '只读',
    ];
    if (is_array($role) && !empty($role['role_name'])) {
        return (string)$role['role_name'];
    }
    return $map[$roleKey] ?? ($roleKey !== '' ? $roleKey : '未分配角色');
}

function artdon_sso_role_is_admin(array $user): bool
{
    return !empty($user['is_super_admin']) || in_array(artdon_sso_role_key($user), ['super_admin', 'admin'], true);
}

function artdon_dispatch_ensure_permissions(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $permissions = [
        ['dispatch.view', 'dispatch', 'view', '查看派工待办', 'medium'],
        ['dispatch.view_all', 'dispatch', 'view_all', '查看全部派工待办', 'high'],
        ['dispatch.view_department', 'dispatch', 'view_department', '查看本部门派工待办', 'medium'],
        ['dispatch.view_logs', 'dispatch', 'view_logs', '查看派工日志', 'high'],
        ['dispatch.view_link_preview', 'dispatch', 'view_link_preview', '查看 @命名 / @BOM / @报价 / @PLM 联动预览', 'medium'],
        ['dispatch.view_notifications', 'dispatch', 'view_notifications', '查看派工通知', 'low'],
        ['dispatch.create', 'dispatch', 'create', '创建派工任务', 'high'],
        ['dispatch.create_personal', 'dispatch', 'create_personal', '创建个人待办', 'medium'],
        ['dispatch.create_private', 'dispatch', 'create_private', '创建私人待办', 'medium'],
        ['dispatch.create_dispatch', 'dispatch', 'create_dispatch', '创建单人派工', 'high'],
        ['dispatch.create_multi', 'dispatch', 'create_multi', '创建多人派工', 'high'],
        ['dispatch.create_plan', 'dispatch', 'create_plan', '创建计划派工', 'high'],
        ['dispatch.create_recurring', 'dispatch', 'create_recurring', '创建周期派工', 'dangerous'],
        ['dispatch.edit', 'dispatch', 'edit', '编辑派工任务', 'high'],
        ['dispatch.edit_own', 'dispatch', 'edit_own', '编辑自己创建的派工/待办', 'medium'],
        ['dispatch.edit_assigned', 'dispatch', 'edit_assigned', '编辑分配给自己的派工', 'medium'],
        ['dispatch.edit_all', 'dispatch', 'edit_all', '编辑全部派工', 'dangerous'],
        ['dispatch.edit_cell', 'dispatch', 'edit_cell', '表格内单元格即时编辑', 'medium'],
        ['dispatch.change_status', 'dispatch', 'change_status', '修改派工状态 / 完成 / 暂停', 'medium'],
        ['dispatch.change_assignee', 'dispatch', 'change_assignee', '修改派工负责人', 'high'],
        ['dispatch.change_due_date', 'dispatch', 'change_due_date', '修改派工截止日期', 'medium'],
        ['dispatch.row_order', 'dispatch', 'row_order', '调整自己的派工行顺序', 'low'],
        ['dispatch.table_customize', 'dispatch', 'table_customize', '调整派工表格列宽/列显示/颜色', 'low'],
        ['dispatch.custom_fields', 'dispatch', 'custom_fields', '新增/编辑派工自定义列', 'high'],
        ['dispatch.comment', 'dispatch', 'comment', '新增派工进度/评论', 'medium'],
        ['dispatch.urge', 'dispatch', 'urge', '催办派工', 'medium'],
        ['dispatch.upload_image', 'dispatch', 'upload_image', '上传派工图片', 'medium'],
        ['dispatch.upload_attachment', 'dispatch', 'upload_attachment', '上传派工附件', 'medium'],
        ['dispatch.download_attachment', 'dispatch', 'download_attachment', '下载派工附件', 'medium'],
        ['dispatch.delete_attachment', 'dispatch', 'delete_attachment', '删除派工附件', 'high'],
        ['dispatch.delete', 'dispatch', 'delete', '删除派工任务', 'dangerous'],
        ['dispatch.delete_own', 'dispatch', 'delete_own', '删除自己创建的派工/待办', 'high'],
        ['dispatch.delete_all', 'dispatch', 'delete_all', '删除任意派工/待办', 'dangerous'],
        ['dispatch.delete_multi', 'dispatch', 'delete_multi', '删除整组多人派工', 'dangerous'],
        ['dispatch.restore', 'dispatch', 'restore', '恢复已删除派工', 'dangerous'],
        ['dispatch.export', 'dispatch', 'export', '导出/备份派工数据', 'dangerous'],
        ['dispatch.backup', 'dispatch', 'backup', '生成派工备份', 'dangerous'],
        ['dispatch.manage_visibility', 'dispatch', 'manage_visibility', '配置人员可见范围 A 可看/不可看 B', 'dangerous'],
        ['dispatch.manage_permissions', 'dispatch', 'manage_permissions', '管理派工模块权限设置', 'dangerous'],
        ['dispatch.init_schema', 'dispatch', 'init_schema', '初始化/修复派工数据表', 'dangerous'],
        ['dispatch.admin', 'dispatch', 'admin', '派工管理员', 'dangerous'],
    ];
    $stmt = db()->prepare('INSERT INTO crm_permissions (permission_key, module, action, description, risk_level, created_at) VALUES (?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE module=VALUES(module), action=VALUES(action), description=VALUES(description), risk_level=VALUES(risk_level)');
    foreach ($permissions as $permission) {
        $stmt->execute($permission);
    }
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key)
        SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p
        WHERE r.role_key = 'super_admin' AND p.module = 'dispatch'");
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key)
        SELECT r.id, 'dispatch.view' FROM crm_roles r
        WHERE r.status = 'active'");
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key)
        SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p
        WHERE r.status = 'active'
          AND p.permission_key IN ('dispatch.create','dispatch.create_personal','dispatch.create_private','dispatch.create_dispatch','dispatch.create_multi','dispatch.create_plan','dispatch.create_recurring')");
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key)
        SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p
        WHERE r.role_key = 'admin' AND p.permission_key IN ('dispatch.view','dispatch.create','dispatch.edit','dispatch.delete','dispatch.export','dispatch.admin')");
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key)
        SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p
        WHERE r.role_key IN ('manager','sales','marketing') AND p.permission_key IN ('dispatch.view','dispatch.create','dispatch.edit')");
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key)
        SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p
        WHERE r.role_key IN ('viewer','finance') AND p.permission_key = 'dispatch.view'");
}

function artdon_naming_ensure_permissions(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $permissions = [
        ['naming.view', 'naming', 'view', '型号命名查看', 'medium'],
        ['naming.create', 'naming', 'create', '型号命名新增', 'high'],
        ['naming.edit', 'naming', 'edit', '型号命名编辑', 'high'],
        ['naming.delete', 'naming', 'delete', '型号命名删除', 'dangerous'],
        ['naming.import', 'naming', 'import', '型号命名导入', 'high'],
        ['naming.export', 'naming', 'export', '型号命名导出', 'high'],
        ['naming.sync_website', 'naming', 'sync_website', '型号命名官网同步', 'dangerous'],
        ['naming.admin', 'naming', 'admin', '型号命名管理员', 'dangerous'],
    ];
    $stmt = db()->prepare('INSERT IGNORE INTO crm_permissions (permission_key, module, action, description, risk_level, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
    foreach ($permissions as $permission) {
        $stmt->execute($permission);
    }
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key)
        SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p
        WHERE r.role_key = 'super_admin' AND p.module = 'naming'");
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key)
        SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p
        WHERE r.role_key = 'admin' AND p.permission_key IN ('naming.view','naming.create','naming.edit','naming.import','naming.export','naming.sync_website','naming.admin')");
}

function artdon_quote_ensure_permissions(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $permissions = [
        ['quote.view', 'quote', 'view', '报价系统查看', 'medium'],
        ['quote.create', 'quote', 'create', '报价系统新增', 'high'],
        ['quote.edit', 'quote', 'edit', '报价系统编辑', 'high'],
        ['quote.delete', 'quote', 'delete', '报价系统删除', 'dangerous'],
        ['quote.export', 'quote', 'export', '报价 PDF / Excel 导出', 'high'],
        ['quote.approve', 'quote', 'approve', '报价审核', 'dangerous'],
        ['quote.order_convert', 'quote', 'order_convert', '报价转订单', 'dangerous'],
        ['quote.naming_select', 'quote', 'naming_select', '报价选择命名型号', 'medium'],
        ['quote.admin', 'quote', 'admin', '报价系统管理员', 'dangerous'],
    ];
    $stmt = db()->prepare('INSERT IGNORE INTO crm_permissions (permission_key, module, action, description, risk_level, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
    foreach ($permissions as $permission) {
        $stmt->execute($permission);
    }
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key)
        SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p
        WHERE r.role_key = 'super_admin' AND p.module = 'quote'");
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key)
        SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p
        WHERE r.role_key = 'admin' AND p.module = 'quote'");
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key)
        SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p
        WHERE r.role_key IN ('manager','sales') AND p.permission_key IN ('quote.view','quote.create','quote.edit','quote.export','quote.order_convert','quote.naming_select')");
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key)
        SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p
        WHERE r.role_key IN ('marketing','finance','viewer') AND p.permission_key IN ('quote.view','quote.export','quote.naming_select')");
}

function artdon_bom_ensure_permissions(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $permissions = [
        ['bom.view', 'bom', 'view', 'BOM 系统查看', 'medium'],
        ['bom.create', 'bom', 'create', 'BOM 新增', 'high'],
        ['bom.edit', 'bom', 'edit', 'BOM 编辑', 'high'],
        ['bom.delete', 'bom', 'delete', 'BOM 删除', 'dangerous'],
        ['bom.import', 'bom', 'import', 'BOM 物料导入', 'high'],
        ['bom.export', 'bom', 'export', 'BOM 导出/备份', 'dangerous'],
        ['bom.cost_view', 'bom', 'cost_view', '查看 BOM 成本', 'dangerous'],
        ['bom.supplier_view', 'bom', 'supplier_view', '查看 BOM 供应商', 'dangerous'],
        ['bom.naming_link', 'bom', 'naming_link', 'BOM 联动型号命名', 'high'],
        ['bom.admin', 'bom', 'admin', 'BOM 管理员', 'dangerous'],
    ];
    $stmt = db()->prepare('INSERT IGNORE INTO crm_permissions (permission_key, module, action, description, risk_level, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
    foreach ($permissions as $permission) {
        $stmt->execute($permission);
    }
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key)
        SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p
        WHERE r.role_key = 'super_admin' AND p.module = 'bom'");
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key)
        SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p
        WHERE r.role_key = 'admin' AND p.module = 'bom'");
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key)
        SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p
        WHERE r.role_key IN ('manager') AND p.permission_key IN ('bom.view','bom.create','bom.edit','bom.import','bom.export','bom.cost_view','bom.supplier_view','bom.naming_link')");
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key)
        SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p
        WHERE r.role_key IN ('sales','marketing') AND p.permission_key IN ('bom.view','bom.naming_link')");
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key)
        SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p
        WHERE r.role_key IN ('finance','viewer') AND p.permission_key = 'bom.view'");
}

function artdon_datasheet_ensure_permissions(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $permissions = [
        ['datasheet.view', 'datasheet', 'view', '资料生成系统查看', 'medium'],
        ['datasheet.create', 'datasheet', 'create', '资料新增/保存快照', 'high'],
        ['datasheet.edit', 'datasheet', 'edit', '资料编辑/配对', 'high'],
        ['datasheet.delete', 'datasheet', 'delete', '资料删除', 'dangerous'],
        ['datasheet.import', 'datasheet', 'import', '资料导入', 'high'],
        ['datasheet.export', 'datasheet', 'export', '资料导出', 'high'],
        ['datasheet.download', 'datasheet', 'download', '资料下载', 'high'],
        ['datasheet.sync_source', 'datasheet', 'sync_source', '同步命名/官网来源资料', 'dangerous'],
        ['datasheet.generate_pdf', 'datasheet', 'generate_pdf', '生成 PDF 资料', 'high'],
        ['datasheet.generate_excel', 'datasheet', 'generate_excel', '生成 Excel 资料', 'high'],
        ['datasheet.package', 'datasheet', 'package', '生成资料包 ZIP', 'high'],
        ['datasheet.storage', 'datasheet', 'storage', '资料空间统计', 'medium'],
        ['datasheet.admin', 'datasheet', 'admin', '资料生成系统管理员', 'dangerous'],
    ];
    $stmt = db()->prepare('INSERT IGNORE INTO crm_permissions (permission_key, module, action, description, risk_level, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
    foreach ($permissions as $permission) {
        $stmt->execute($permission);
    }
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key)
        SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p
        WHERE r.role_key = 'super_admin' AND p.module = 'datasheet'");
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key)
        SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p
        WHERE r.role_key = 'admin' AND p.module = 'datasheet'");
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key)
        SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p
        WHERE r.role_key IN ('manager','sales','marketing') AND p.permission_key IN ('datasheet.view','datasheet.create','datasheet.edit','datasheet.export','datasheet.download','datasheet.sync_source','datasheet.generate_pdf','datasheet.generate_excel','datasheet.package')");
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key)
        SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p
        WHERE r.role_key IN ('finance','viewer') AND p.permission_key IN ('datasheet.view','datasheet.download')");
}

function artdon_sso_can(string $module, string $cap, ?array $user = null): bool
{
    $user = $user ?: artdon_sso_current_user();
    if (!$user) {
        return false;
    }
    if ($module === 'dispatch') {
        artdon_dispatch_ensure_permissions();
    }
    if ($module === 'naming') {
        artdon_naming_ensure_permissions();
    }
    if ($module === 'quote') {
        artdon_quote_ensure_permissions();
    }
    if ($module === 'bom') {
        artdon_bom_ensure_permissions();
    }
    if ($module === 'datasheet') {
        artdon_datasheet_ensure_permissions();
    }
    if (artdon_sso_role_is_admin($user)) {
        return true;
    }
    return has_permission($module . '.' . $cap);
}

function artdon_sso_can_feature(string $module, string $feature, ?array $user = null): bool
{
    $module = strtolower(trim($module));
    $feature = strtolower(trim($feature));
    if ($module === 'naming') {
        $featureMap = [
            'view_models' => 'view',
            'create_model' => 'create',
            'edit_model' => 'edit',
            'delete_model' => 'delete',
            'disable_model' => 'edit',
            'confirm_model' => 'edit',
            'manage_rules' => 'admin',
            'manage_settings' => 'admin',
            'sync_website' => 'sync_website',
            'process_inbox' => 'edit',
            'return_inbox' => 'edit',
            'dispatch_create' => 'edit',
            'export_csv' => 'export',
            'backup_json' => 'export',
            'backup_restore' => 'admin',
            'view_logs' => 'admin',
        ];
        return artdon_sso_can('naming', $featureMap[$feature] ?? $feature, $user);
    }
    if ($module === 'quote') {
        $featureMap = [
            'can_access' => 'view',
            'quote_create' => 'create',
            'quote_edit' => 'edit',
            'quote_delete' => 'delete',
            'export_pdf_excel' => 'export',
            'quote_review_view' => 'approve',
            'quote_approve' => 'approve',
            'order_convert' => 'order_convert',
            'product_view' => 'view',
            'customer_view' => 'view',
            'history_view' => 'view',
            'material_view' => 'view',
            'log_view' => 'view',
            'product_manage' => 'edit',
            'customer_manage' => 'edit',
            'doc_settings_manage' => 'edit',
            'rate_manage' => 'admin',
            'settings_manage' => 'admin',
            'permission_manage' => 'admin',
            'log_manage' => 'admin',
            'naming_select' => 'naming_select',
        ];
        return artdon_sso_can('quote', $featureMap[$feature] ?? $feature, $user);
    }
    if ($module === 'bom') {
        $featureMap = [
            'view_dashboard' => 'view',
            'view_library' => 'view',
            'view_bom' => 'view',
            'dashboard' => 'view',
            'library' => 'view',
            'create_bom' => 'create',
            'create_project' => 'create',
            'edit_bom' => 'edit',
            'manage_materials' => 'edit',
            'delete_bom' => 'delete',
            'delete_materials' => 'delete',
            'import_materials' => 'import',
            'export_bom' => 'export',
            'backup_restore' => 'export',
            'view_cost' => 'cost_view',
            'cost_view' => 'cost_view',
            'view_bom_cost' => 'cost_view',
            'supplier_view' => 'supplier_view',
            'view_supplier' => 'supplier_view',
            'naming_models' => 'naming_link',
            'naming_link' => 'naming_link',
            'create_from_naming' => 'naming_link',
            'bind_naming_to_project' => 'naming_link',
            'manage_users' => 'admin',
            'manage_settings' => 'admin',
            'admin' => 'admin',
        ];
        return artdon_sso_can('bom', $featureMap[$feature] ?? $feature, $user);
    }
    if ($module === 'datasheet') {
        $featureMap = [
            'view_datasheet' => 'view',
            'view_products' => 'view',
            'view_snapshots' => 'view',
            'view_all_snapshots' => 'admin',
            'edit_params' => 'edit',
            'sync_website' => 'sync_source',
            'sync_website_cache' => 'sync_source',
            'upload_file' => 'create',
            'upload_library_hd' => 'create',
            'upload_library_accessory' => 'create',
            'upload_library_curve' => 'create',
            'pair_hd' => 'edit',
            'pair_accessory' => 'edit',
            'pair_curve' => 'edit',
            'delete_file' => 'delete',
            'generate_pdf' => 'generate_pdf',
            'generate_excel' => 'generate_excel',
            'generate_zip' => 'package',
            'batch_generate' => 'package',
            'record_send' => 'create',
            'manage_accessory' => 'edit',
            'select_material' => 'edit',
            'view_online' => 'admin',
            'view_logs' => 'admin',
            'manage_settings' => 'admin',
            'manage_permissions' => 'admin',
            'storage' => 'storage',
            'download' => 'download',
            'export' => 'export',
            'import' => 'import',
        ];
        return artdon_sso_can('datasheet', $featureMap[$feature] ?? $feature, $user);
    }
    return artdon_sso_can($module, $feature, $user);
}

function artdon_sso_can_any_feature(string $module, array $features, ?array $user = null): bool
{
    foreach ($features as $feature) {
        if (artdon_sso_can_feature($module, (string)$feature, $user)) {
            return true;
        }
    }
    return false;
}

function artdon_perm_require_action(string $module, string $action, array $featureMap = [], string $defaultFeature = 'view'): void
{
    $feature = $featureMap[$action] ?? $defaultFeature;
    if ($feature === null || $feature === '') {
        return;
    }
    if (!artdon_sso_can_feature($module, (string)$feature)) {
        artdon_sso_json_error('当前账号没有该操作权限', 403, [
            'error_code' => 'PERMISSION_DENIED',
            'module' => $module,
            'action' => $action,
            'feature' => $feature,
        ]);
    }
}

function artdon_sso_forbidden_page(string $module): void
{
    http_response_code(403);
    $labels = ['naming' => '型号命名系统', 'dispatch' => '派工待办', 'quote' => '报价系统', 'bom' => 'BOM 成本系统', 'datasheet' => '资料生成系统'];
    $label = $labels[$module] ?? $module;
    echo '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>权限不足</title><body style="margin:0;background:#f5f7fb;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Microsoft YaHei,Arial;color:#111827;padding:32px"><div style="max-width:680px;margin:10vh auto;background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:26px;box-shadow:0 18px 50px rgba(15,23,42,.10)"><h1 style="margin:0 0 10px;font-size:22px">权限不足</h1><p>当前账号没有进入「' . h($label) . '」的权限。</p><p><a href="index.php">返回首页</a></p></div></body></html>';
    exit;
}

function artdon_sso_require_page(string $module): array
{
    require_login();
    if ($module === 'dispatch') {
        artdon_dispatch_ensure_permissions();
    }
    if ($module === 'naming') {
        artdon_naming_ensure_permissions();
    }
    if ($module === 'quote') {
        artdon_quote_ensure_permissions();
    }
    if ($module === 'bom') {
        artdon_bom_ensure_permissions();
    }
    if ($module === 'datasheet') {
        artdon_datasheet_ensure_permissions();
    }
    if (!artdon_sso_can($module, 'view')) {
        artdon_sso_forbidden_page($module);
    }
    return artdon_sso_current_user();
}

function artdon_sso_require_api(string $module): array
{
    if (!current_user()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'AUTH_REQUIRED'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($module === 'dispatch') {
        artdon_dispatch_ensure_permissions();
    }
    if ($module === 'naming') {
        artdon_naming_ensure_permissions();
    }
    if ($module === 'quote') {
        artdon_quote_ensure_permissions();
    }
    if ($module === 'bom') {
        artdon_bom_ensure_permissions();
    }
    if ($module === 'datasheet') {
        artdon_datasheet_ensure_permissions();
    }
    if (!artdon_sso_can($module, 'view')) {
        artdon_sso_json_error('当前账号没有进入该系统的权限', 403, ['error_code' => 'PERMISSION_DENIED', 'module' => $module]);
    }
    return artdon_sso_current_user();
}

function artdon_sso_user_by_id(int $userId): ?array
{
    $stmt = db()->prepare('SELECT u.*, r.role_key, r.role_name, d.name AS department_name FROM crm_users u LEFT JOIN crm_roles r ON r.id = u.role_id LEFT JOIN crm_departments d ON d.id = u.department_id WHERE u.id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        return null;
    }
    $user['display_name'] = $user['real_name'] ?: $user['username'];
    $user['department'] = $user['department_name'] ?? '';
    $user['role'] = $user['role_key'] ?? '';
    return $user;
}

function artdon_sso_user_by_username(string $username): ?array
{
    $stmt = db()->prepare('SELECT u.*, r.role_key, r.role_name, d.name AS department_name FROM crm_users u LEFT JOIN crm_roles r ON r.id = u.role_id LEFT JOIN crm_departments d ON d.id = u.department_id WHERE u.username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        return null;
    }
    $user['display_name'] = $user['real_name'] ?: $user['username'];
    $user['department'] = $user['department_name'] ?? '';
    $user['role'] = $user['role_key'] ?? '';
    return $user;
}

function artdon_sso_user_has_permission(array $user, string $permission): bool
{
    if (!empty($user['is_super_admin']) || artdon_sso_role_is_admin($user)) {
        return true;
    }
    $aliases = function_exists('permission_alias_keys') ? permission_alias_keys($permission) : [];
    $deny = db()->prepare("SELECT 1 FROM crm_user_permissions WHERE user_id = ? AND permission_key = ? AND effect = 'deny' LIMIT 1");
    $deny->execute([(int)$user['id'], $permission]);
    if ($deny->fetchColumn()) {
        return false;
    }
    foreach ($aliases as $alias) {
        $deny->execute([(int)$user['id'], $alias]);
        if ($deny->fetchColumn()) return false;
    }
    $allow = db()->prepare("SELECT 1 FROM crm_user_permissions WHERE user_id = ? AND permission_key = ? AND effect = 'allow' LIMIT 1");
    $allow->execute([(int)$user['id'], $permission]);
    if ($allow->fetchColumn()) {
        return true;
    }
    foreach ($aliases as $alias) {
        $allow->execute([(int)$user['id'], $alias]);
        if ($allow->fetchColumn()) return true;
    }
    if (empty($user['role_id'])) {
        return false;
    }
    $role = db()->prepare('SELECT 1 FROM crm_role_permissions WHERE role_id = ? AND permission_key = ? LIMIT 1');
    $role->execute([(int)$user['role_id'], $permission]);
    if ($role->fetchColumn()) return true;
    foreach ($aliases as $alias) {
        $role->execute([(int)$user['role_id'], $alias]);
        if ($role->fetchColumn()) return true;
    }
    return false;
}

function artdon_perm_module_has_explicit(int $userId, string $module): bool
{
    if ($module === 'bom') {
        artdon_bom_ensure_permissions();
        return (bool)artdon_sso_user_by_id($userId);
    }
    if ($module === 'datasheet') {
        artdon_datasheet_ensure_permissions();
        return (bool)artdon_sso_user_by_id($userId);
    }
    if ($module !== 'quote') {
        return false;
    }
    artdon_quote_ensure_permissions();
    return (bool)artdon_sso_user_by_id($userId);
}

function artdon_perm_effective_feature_map(int $userId, string $module, ?array $user = null): array
{
    $module = strtolower($module);
    $user = $user ?: artdon_sso_user_by_id($userId);
    if (!$user) {
        return [];
    }
    if ($module === 'bom') {
        artdon_bom_ensure_permissions();
        $perm = static function (string $key) use ($user): bool {
            return artdon_sso_user_has_permission($user, $key);
        };
        $view = $perm('bom.view');
        $edit = $perm('bom.edit');
        $admin = $perm('bom.admin');
        return [
            'can_access' => $view,
            'view_dashboard' => $view,
            'view_library' => $view,
            'edit_bom' => $edit,
            'manage_materials' => $edit,
            'delete_bom' => $perm('bom.delete'),
            'import_materials' => $perm('bom.import'),
            'export_bom' => $perm('bom.export'),
            'view_cost' => $perm('bom.cost_view'),
            'supplier_view' => $perm('bom.supplier_view'),
            'naming_link' => $perm('bom.naming_link'),
            'manage_users' => $admin,
            'manage_settings' => $admin,
        ];
    }
    if ($module === 'datasheet') {
        artdon_datasheet_ensure_permissions();
        $perm = static function (string $key) use ($user): bool {
            return artdon_sso_user_has_permission($user, $key);
        };
        $view = $perm('datasheet.view');
        $edit = $perm('datasheet.edit');
        $admin = $perm('datasheet.admin');
        return [
            'view_datasheet' => $view,
            'view_products' => $view,
            'edit_params' => $edit,
            'sync_website' => $perm('datasheet.sync_source'),
            'upload_file' => $perm('datasheet.create'),
            'upload_library_hd' => $perm('datasheet.create'),
            'upload_library_accessory' => $perm('datasheet.create'),
            'upload_library_curve' => $perm('datasheet.create'),
            'pair_hd' => $edit,
            'pair_accessory' => $edit,
            'pair_curve' => $edit,
            'delete_file' => $perm('datasheet.delete'),
            'generate_pdf' => $perm('datasheet.generate_pdf') || $perm('datasheet.export'),
            'generate_excel' => $perm('datasheet.generate_excel') || $perm('datasheet.export'),
            'generate_zip' => $perm('datasheet.package') || $perm('datasheet.export'),
            'batch_generate' => $perm('datasheet.package') || $perm('datasheet.export'),
            'record_send' => $perm('datasheet.create'),
            'view_snapshots' => $view,
            'view_all_snapshots' => $admin,
            'manage_accessory' => $edit,
            'select_material' => $edit,
            'view_online' => $admin,
            'view_logs' => $admin,
            'manage_settings' => $admin,
            'manage_permissions' => $admin,
        ];
    }
    if ($module !== 'quote') {
        return [];
    }
    artdon_quote_ensure_permissions();
    $perm = static function (string $key) use ($user): bool {
        return artdon_sso_user_has_permission($user, $key);
    };
    $view = $perm('quote.view');
    $edit = $perm('quote.edit');
    $admin = $perm('quote.admin');
    return [
        'can_access' => $view,
        'quote_create' => $perm('quote.create'),
        'quote_edit' => $edit,
        'quote_review_view' => $perm('quote.approve') || $admin,
        'quote_approve' => $perm('quote.approve'),
        'quote_delete' => $perm('quote.delete'),
        'history_view' => $view,
        'customer_view' => $view,
        'customer_manage' => $edit,
        'product_view' => $view,
        'product_manage' => $edit,
        'material_view' => $view,
        'doc_settings_manage' => $edit || $admin,
        'rate_manage' => $admin,
        'settings_manage' => $admin,
        'permission_manage' => $admin,
        'export_pdf_excel' => $perm('quote.export'),
        'order_convert' => $perm('quote.order_convert'),
        'log_view' => $view,
        'log_manage' => $admin,
        'naming_select' => $perm('quote.naming_select'),
    ];
}
