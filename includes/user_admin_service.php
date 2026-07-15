<?php
require_once __DIR__ . '/artdon_sso_core.php';

function ensure_user_create_permission(): void
{
    try {
        db()->prepare('INSERT IGNORE INTO crm_permissions (permission_key, module, action, description, risk_level, created_at) VALUES (?, ?, ?, ?, ?, NOW())')
            ->execute(['users.create', 'users', 'create', '后台新增账号', 'high']);
        db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key)
            SELECT role_id, 'users.create' FROM crm_role_permissions WHERE permission_key = 'users.approve'");
        db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key)
            SELECT r.id, 'users.create' FROM crm_roles r WHERE r.role_key = 'super_admin'");
    } catch (Throwable $e) {
        error_log('ensure_user_create_permission failed: ' . $e->getMessage());
    }
}

function ensure_user_optional_email_schema(): void
{
    try {
        db()->exec('ALTER TABLE crm_users MODIFY email varchar(190) NULL DEFAULT NULL');
    } catch (Throwable $e) {
        error_log('ensure_user_optional_email_schema failed: ' . $e->getMessage());
    }
}

function user_admin_column_exists(string $table, string $column): bool
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function ensure_user_profile_schema(): void
{
    try {
        if (!user_admin_column_exists('crm_users', 'position')) {
            db()->exec('ALTER TABLE crm_users ADD COLUMN position varchar(120) NULL DEFAULT NULL AFTER phone');
        }
    } catch (Throwable $e) {
        error_log('ensure_user_profile_schema failed: ' . $e->getMessage());
    }
}

function ensure_default_departments(): void
{
    $departments = [
        ['总经办', 'general_office', 1],
        ['业务部', 'sales', 10],
        ['外贸部', 'overseas_sales', 11],
        ['市场推广部', 'marketing', 12],
        ['工程部', 'engineering', 20],
        ['研发部', 'rd', 21],
        ['PLM项目部', 'plm', 22],
        ['BOM成本部', 'bom_cost', 23],
        ['生产部', 'production', 30],
        ['品质部', 'quality', 31],
        ['采购部', 'purchasing', 32],
        ['仓库部', 'warehouse', 33],
        ['财务部', 'finance', 40],
        ['行政人事部', 'hr_admin', 50],
        ['IT系统部', 'it', 60],
        ['售后服务部', 'after_sales', 70],
    ];
    try {
        db()->prepare("UPDATE crm_departments SET status = 'disabled', updated_at = NOW() WHERE code = 'headquarters'")->execute();
        $stmt = db()->prepare('INSERT INTO crm_departments (name, code, status, sort_order, created_at, updated_at) VALUES (?, ?, "active", ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE name = VALUES(name), status = "active", sort_order = VALUES(sort_order), updated_at = NOW()');
        foreach ($departments as $department) $stmt->execute($department);
    } catch (Throwable $e) {
        error_log('ensure_default_departments failed: ' . $e->getMessage());
    }
}

function user_admin_department_code_from_name(string $name): string
{
    $base = strtolower(trim($name));
    $base = preg_replace('/[^a-z0-9]+/i', '_', $base);
    $base = trim((string)$base, '_');
    if ($base === '') {
        $base = 'dept_' . substr(md5($name), 0, 8);
    }
    $code = $base;
    $i = 1;
    $stmt = db()->prepare('SELECT id FROM crm_departments WHERE code = ? LIMIT 1');
    while (true) {
        $stmt->execute([$code]);
        if (!$stmt->fetchColumn()) return $code;
        $code = $base . '_' . (++$i);
    }
}

function user_admin_resolve_department_id(array $input): int
{
    $departmentName = trim((string)($input['department_name'] ?? ''));
    $departmentId = (int)($input['department_id'] ?? 0);
    if ($departmentName !== '') {
        $stmt = db()->prepare("SELECT id FROM crm_departments WHERE name = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$departmentName]);
        $found = (int)$stmt->fetchColumn();
        if ($found) return $found;
        $code = user_admin_department_code_from_name($departmentName);
        db()->prepare('INSERT INTO crm_departments (name, code, status, sort_order, created_at, updated_at) VALUES (?, ?, "active", 999, NOW(), NOW())')
            ->execute([$departmentName, $code]);
        return (int)db()->lastInsertId();
    }
    return $departmentId;
}

function artdon_department_template_defs(): array
{
    return [
        'boss_admin' => [
            'role_key' => 'boss_admin',
            'role_name' => '老板 / 管理员',
            'department_codes' => ['general_office', 'it'],
            'description' => '全公司管理、权限、敏感数据和系统设置。',
            'profile' => 'boss',
        ],
        'sales_department' => [
            'role_key' => 'sales',
            'role_name' => '业务部',
            'department_codes' => ['sales', 'overseas_sales', 'marketing', 'after_sales'],
            'description' => '客户、邮件、推广、商机、报价需求和拜访来访。',
            'profile' => 'sales',
        ],
        'engineering_department' => [
            'role_key' => 'engineering',
            'role_name' => '工程部',
            'department_codes' => ['engineering', 'rd', 'plm', 'bom_cost'],
            'description' => '技术确认、PLM、样品、资料、BOM 技术查看和派工执行。',
            'profile' => 'engineering',
        ],
        'purchase_department' => [
            'role_key' => 'purchase',
            'role_name' => '采购部',
            'department_codes' => ['purchasing', 'warehouse'],
            'description' => 'BOM 采购价、供应商报价、交期和采购派工。',
            'profile' => 'purchase',
        ],
        'production_department' => [
            'role_key' => 'production',
            'role_name' => '生产部',
            'department_codes' => ['production', 'quality'],
            'description' => '生产任务、样品制作、派工执行、异常反馈。',
            'profile' => 'production',
        ],
        'readonly' => [
            'role_key' => 'viewer',
            'role_name' => '只读 / 临时',
            'department_codes' => [],
            'description' => '仅查看被授权模块，不允许编辑、删除、导出和发送。',
            'profile' => 'readonly',
        ],
    ];
}

function artdon_permission_rows(): array
{
    return db()->query('SELECT permission_key, module, action, risk_level FROM crm_permissions ORDER BY permission_key')->fetchAll(PDO::FETCH_ASSOC);
}

function artdon_permission_keys_for_policy(array $modules, array $actions, array $extra = [], array $deny = []): array
{
    $moduleSet = array_fill_keys($modules, true);
    $actionSet = array_fill_keys($actions, true);
    $extraSet = array_fill_keys($extra, true);
    $denySet = array_fill_keys($deny, true);
    $keys = [];
    foreach (artdon_permission_rows() as $row) {
        $key = (string)$row['permission_key'];
        if (isset($denySet[$key])) continue;
        $module = (string)$row['module'];
        $action = (string)$row['action'];
        if (isset($extraSet[$key])) {
            $keys[] = $key;
            continue;
        }
        if (!isset($moduleSet[$module])) continue;
        if (isset($actionSet[$action]) || str_starts_with($action, 'view_')) {
            $keys[] = $key;
        }
    }
    return array_values(array_unique($keys));
}

function artdon_template_permissions(string $profile): array
{
    $viewOnly = ['view'];
    $basic = ['view','create','edit','result','stage','quote','material','dispatch','sync','send','reply','compose','attachment_download','link_customer','account_bind_own','signature_manage_own','task_create','execute','analytics','create_project','edit_project','create_group','edit_group','move_customer','create_personal','create_private','create_dispatch','create_multi','create_plan','create_recurring','edit_own','edit_assigned','edit_cell','change_status','change_due_date','comment','urge','upload_image','upload_attachment','download_attachment','download','generate_pdf','generate_excel','package','naming_select','lead_capture','quote_draft','material_draft','confirm_own','reject_own','preferences_edit'];
    $sensitiveCustomerFields = ['customer.view_email','customer.view_phone','customer.view_whatsapp','customer.view_address','customer.view_all'];
    if ($profile === 'boss') {
        return db()->query('SELECT permission_key FROM crm_permissions')->fetchAll(PDO::FETCH_COLUMN);
    }
    if ($profile === 'sales') {
        return artdon_permission_keys_for_policy(
            ['dashboard','crm','online','customer','contact','follow','visit','opportunity','mail','promotion','dispatch','quote','datasheet','ai','logs'],
            $basic,
            ['customer.view_department','customer.view_email','customer.view_phone','customer.view_whatsapp','customer.view_address','logs.view_own'],
            ['customer.delete','customer.force_delete','customer.export','customer.view_all','opportunity.delete','opportunity.export','opportunity.win','opportunity.amount_view','mail.account_manage_all','mail.signature_batch_apply','promotion.delete_project','promotion.delete_group','ai.settings','ai.logs','ai.auto_send']
        );
    }
    if ($profile === 'engineering') {
        return artdon_permission_keys_for_policy(
            ['dashboard','crm','online','customer','contact','visit','opportunity','dispatch','bom','naming','datasheet','ai','logs'],
            ['view','create','edit','result','material','dispatch','create_personal','create_private','create_dispatch','create_multi','create_plan','create_recurring','edit_assigned','edit_cell','change_status','change_due_date','comment','upload_image','upload_attachment','download_attachment','download','generate_pdf','generate_excel','package','naming_select','naming_link','confirm_own','preferences_edit'],
            ['customer.plm_summary','customer.bom_summary','customer.dispatch_summary','customer.material_summary','bom.import','datasheet.sync_source','logs.view_own'],
            array_merge($sensitiveCustomerFields, ['bom.cost_view','bom.supplier_view','bom.delete','bom.export','bom.admin','opportunity.win','opportunity.lose','opportunity.amount_view','ai.settings','ai.auto_send'])
        );
    }
    if ($profile === 'purchase') {
        return artdon_permission_keys_for_policy(
            ['dashboard','crm','online','customer','opportunity','dispatch','bom','datasheet','ai','logs'],
            ['view','edit','create','create_personal','create_private','create_dispatch','create_multi','create_plan','create_recurring','edit_assigned','change_status','comment','upload_attachment','download_attachment','download','confirm_own','preferences_edit'],
            ['customer.bom_summary','customer.dispatch_summary','customer.material_summary','bom.import','bom.export','bom.cost_view','bom.supplier_view','bom.naming_link','logs.view_own'],
            array_merge($sensitiveCustomerFields, ['opportunity.stage','opportunity.win','opportunity.lose','opportunity.amount_view','ai.lead_capture','ai.quote_draft','ai.material_draft','ai.settings','ai.auto_send'])
        );
    }
    if ($profile === 'production') {
        return artdon_permission_keys_for_policy(
            ['dashboard','crm','online','customer','opportunity','dispatch','datasheet','logs'],
            ['view','create','create_personal','create_private','create_dispatch','create_multi','create_plan','create_recurring','edit_assigned','change_status','comment','upload_image','upload_attachment','download_attachment','download','preferences_edit'],
            ['logs.view_own'],
            array_merge($sensitiveCustomerFields, ['opportunity.amount_view','opportunity.create','opportunity.edit','opportunity.stage','opportunity.win','opportunity.lose','customer.mail_summary','customer.quote_summary','customer.bom_summary','mail.view'])
        );
    }
    return artdon_permission_keys_for_policy(
        ['dashboard','customer','contact','follow','visit','opportunity','promotion','mail','dispatch','quote','bom','naming','datasheet','logs'],
        $viewOnly,
        ['logs.view_own'],
        array_merge($sensitiveCustomerFields, ['customer.view_all','opportunity.amount_view','bom.cost_view','bom.supplier_view'])
    );
}

function artdon_department_permission_templates(): array
{
    $templates = artdon_department_template_defs();
    foreach ($templates as $key => $template) {
        $templates[$key]['permissions'] = artdon_template_permissions((string)($template['profile'] ?? 'readonly'));
    }
    return $templates;
}

function artdon_register_extra_permissions(): void
{
    try {
        if (function_exists('crm_ensure_permissions')) crm_ensure_permissions();
        if (function_exists('artdon_dispatch_ensure_permissions')) artdon_dispatch_ensure_permissions();
        if (function_exists('artdon_naming_ensure_permissions')) artdon_naming_ensure_permissions();
        if (function_exists('artdon_quote_ensure_permissions')) artdon_quote_ensure_permissions();
        if (function_exists('artdon_bom_ensure_permissions')) artdon_bom_ensure_permissions();
        if (function_exists('artdon_datasheet_ensure_permissions')) artdon_datasheet_ensure_permissions();
        $permissions = [
            ['visit.view','visit','view','查看拜访/来访','low'],['visit.create','visit','create','新建拜访/来访','medium'],['visit.edit','visit','edit','编辑拜访/来访','medium'],['visit.delete','visit','delete','删除拜访/来访','high'],['visit.result','visit','result','填写拜访/来访结果','medium'],['visit.dispatch','visit','dispatch','从拜访/来访创建派工','medium'],['visit.export','visit','export','导出拜访/来访','high'],
            ['opportunity.view','opportunity','view','查看商机','low'],['opportunity.view_all','opportunity','view_all','查看全部商机','high'],['opportunity.create','opportunity','create','新建商机','medium'],['opportunity.edit','opportunity','edit','编辑商机','medium'],['opportunity.delete','opportunity','delete','删除商机','high'],['opportunity.stage','opportunity','stage','推进商机阶段','medium'],['opportunity.win','opportunity','win','标记赢单','high'],['opportunity.lose','opportunity','lose','标记输单','medium'],['opportunity.export','opportunity','export','导出商机','high'],['opportunity.quote','opportunity','quote','从商机创建报价','medium'],['opportunity.material','opportunity','material','从商机生成资料','medium'],['opportunity.dispatch','opportunity','dispatch','从商机创建派工','medium'],['opportunity.amount_view','opportunity','amount_view','查看商机金额/预测','high'],
            ['ai.view','ai','view','查看 AI 机器人','low'],['ai.lead_capture','ai','lead_capture','使用 AI 自动获客','medium'],['ai.quote_draft','ai','quote_draft','生成 AI 报价草稿','medium'],['ai.material_draft','ai','material_draft','生成 AI 资料草稿','medium'],['ai.confirm_own','ai','confirm_own','确认分配给自己的 AI 任务','medium'],['ai.confirm_all','ai','confirm_all','确认全部 AI 任务','high'],['ai.reject_own','ai','reject_own','驳回自己的 AI 草稿','medium'],['ai.settings','ai','settings','修改 AI 设置','dangerous'],['ai.logs','ai','logs','查看 AI 日志','high'],['ai.auto_send','ai','auto_send','AI 自动对外发送','dangerous'],
        ];
        $stmt = db()->prepare('INSERT INTO crm_permissions (permission_key, module, action, description, risk_level, created_at) VALUES (?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE module=VALUES(module), action=VALUES(action), description=VALUES(description), risk_level=VALUES(risk_level)');
        foreach ($permissions as $permission) $stmt->execute($permission);
    } catch (Throwable $e) {
        error_log('artdon_register_extra_permissions failed: ' . $e->getMessage());
    }
}

function artdon_role_key_for_department_code(string $code): string
{
    $code = strtolower(trim($code));
    foreach (artdon_department_permission_templates() as $templateKey => $template) {
        if (in_array($code, $template['department_codes'] ?? [], true)) {
            return $template['role_key'] ?? $templateKey;
        }
    }
    return 'viewer';
}

function ensure_artdon_department_permission_templates(): void
{
    ensure_default_departments();
    artdon_register_extra_permissions();
    try {
        $roleStmt = db()->prepare('INSERT INTO crm_roles (role_key, role_name, description, is_system, status, created_at, updated_at) VALUES (?, ?, ?, 1, "active", NOW(), NOW()) ON DUPLICATE KEY UPDATE role_name=VALUES(role_name), description=VALUES(description), status="active", updated_at=NOW()');
        foreach (artdon_department_permission_templates() as $templateKey => $template) {
            $roleKey = $template['role_key'] ?? $templateKey;
            $roleStmt->execute([$roleKey, $template['role_name'], $template['description'] ?? '']);
        }
        $allKeys = db()->query('SELECT permission_key FROM crm_permissions')->fetchAll(PDO::FETCH_COLUMN);
        $allSet = array_fill_keys($allKeys, true);
        $roleIdStmt = db()->prepare('SELECT id FROM crm_roles WHERE role_key = ? LIMIT 1');
        $insert = db()->prepare('INSERT IGNORE INTO crm_role_permissions (role_id, permission_key) VALUES (?, ?)');
        $clear = db()->prepare('DELETE FROM crm_role_permissions WHERE role_id = ?');
        foreach (artdon_department_permission_templates() as $templateKey => $template) {
            $roleKey = $template['role_key'] ?? $templateKey;
            $roleIdStmt->execute([$roleKey]);
            $roleId = (int)$roleIdStmt->fetchColumn();
            if (!$roleId) continue;
            $clear->execute([$roleId]);
            $keys = in_array('*', $template['permissions'], true) ? $allKeys : array_values(array_intersect($template['permissions'], $allKeys));
            foreach ($keys as $key) $insert->execute([$roleId, $key]);
        }
    } catch (Throwable $e) {
        error_log('ensure_artdon_department_permission_templates failed: ' . $e->getMessage());
    }
}

function apply_department_permission_template_to_user(int $userId, string $departmentCode, ?string $operatorNote = null): void
{
    if ($userId <= 0) return;
    ensure_artdon_department_permission_templates();
    $roleKey = artdon_role_key_for_department_code($departmentCode);
    $template = null;
    foreach (artdon_department_permission_templates() as $candidate) {
        if (($candidate['role_key'] ?? '') === $roleKey || $roleKey === 'boss_admin' && ($candidate['role_key'] ?? '') === '') {
            $template = $candidate;
            break;
        }
    }
    if (!$template) return;
    $keys = $template['permissions'];
    $valid = array_fill_keys(db()->query('SELECT permission_key FROM crm_permissions')->fetchAll(PDO::FETCH_COLUMN), true);
    db()->prepare('DELETE FROM crm_user_permissions WHERE user_id = ?')->execute([$userId]);
    $insert = db()->prepare('INSERT INTO crm_user_permissions (user_id, permission_key, effect, created_at) VALUES (?, ?, "allow", NOW()) ON DUPLICATE KEY UPDATE effect=VALUES(effect)');
    foreach ($keys as $key) {
        if (isset($valid[$key])) $insert->execute([$userId, $key]);
    }
    if (function_exists('audit_log')) {
        audit_log('apply_department_permission_template', 'users', 'user', $userId, null, [
            'department_code' => $departmentCode,
            'role_key' => $roleKey,
            'permission_count' => count($keys),
            'note' => $operatorNote,
        ]);
    }
}

function admin_create_user(array $input): int
{
    ensure_user_create_permission();
    ensure_user_optional_email_schema();
    ensure_user_profile_schema();
    ensure_default_departments();
    ensure_artdon_department_permission_templates();
    require_permission('users.create');
    $username = trim((string)($input['username'] ?? ''));
    $realName = trim((string)($input['real_name'] ?? ''));
    $englishName = trim((string)($input['english_name'] ?? ''));
    $email = trim((string)($input['email'] ?? ''));
    $emailForDb = $email !== '' ? $email : null;
    $phone = trim((string)($input['phone'] ?? ''));
    $position = trim((string)($input['position'] ?? ''));
    $deptId = user_admin_resolve_department_id($input);
    $roleId = (int)($input['role_id'] ?? 0);
    $password = (string)($input['password'] ?? '');

    if (!preg_match('/^[a-zA-Z0-9_.-]{2,80}$/', $username)) {
        throw new RuntimeException('用户名只能使用字母、数字、点、下划线或横线，长度 2-80。');
    }
    if ($realName === '') {
        throw new RuntimeException('姓名不能为空。');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('邮箱格式不正确。');
    }
    $exists = db()->prepare('SELECT id FROM crm_users WHERE username = ? LIMIT 1');
    $exists->execute([$username]);
    if ($exists->fetchColumn()) {
        throw new RuntimeException('用户名已存在，请换一个账号名。');
    }
    if ($email !== '') {
        $exists = db()->prepare('SELECT id FROM crm_users WHERE email = ? LIMIT 1');
        $exists->execute([$email]);
        if ($exists->fetchColumn()) {
            throw new RuntimeException('邮箱已存在，请换一个邮箱。');
        }
    }
    if (!$deptId) {
        throw new RuntimeException('必须分配部门。');
    }
    $deptCheck = db()->prepare("SELECT id FROM crm_departments WHERE id = ? AND status = 'active' LIMIT 1");
    $deptCheck->execute([$deptId]);
    if (!$deptCheck->fetchColumn()) {
        throw new RuntimeException('选择的部门无效，请重新选择。');
    }
    if (!$roleId) {
        $dept = db()->prepare('SELECT code FROM crm_departments WHERE id = ? LIMIT 1');
        $dept->execute([$deptId]);
        $roleKey = artdon_role_key_for_department_code((string)$dept->fetchColumn());
        $roleLookup = db()->prepare("SELECT id FROM crm_roles WHERE role_key = ? AND status = 'active' LIMIT 1");
        $roleLookup->execute([$roleKey]);
        $roleId = (int)$roleLookup->fetchColumn();
    }
    if (!$roleId) {
        throw new RuntimeException('必须分配角色，或选择可自动匹配角色的部门。');
    }
    $roleCheck = db()->prepare("SELECT id FROM crm_roles WHERE id = ? AND status = 'active' LIMIT 1");
    $roleCheck->execute([$roleId]);
    if (!$roleCheck->fetchColumn()) {
        throw new RuntimeException('选择的角色无效，请重新选择。');
    }
    if (!password_is_strong($password)) {
        throw new RuntimeException(password_strength_message());
    }

    $operator = current_user();
    $stmt = db()->prepare("INSERT INTO crm_users (username, password_hash, real_name, english_name, email, phone, position, department_id, role_id, status, force_password_change, approved_at, approved_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', 1, NOW(), ?, NOW(), NOW())");
    $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $realName, $englishName, $emailForDb, $phone, $position, $deptId, $roleId, $operator['id']]);
    $newUserId = (int)db()->lastInsertId();
    audit_log('user_create', 'users', 'user', $newUserId, null, [
        'username' => $username,
        'real_name' => $realName,
        'email' => $emailForDb,
        'position' => $position,
        'department_id' => $deptId,
        'role_id' => $roleId,
    ]);
    db()->prepare('INSERT INTO crm_user_approval_logs (user_id, action, operator_id, operator_name, reason, before_json, after_json, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())')
        ->execute([$newUserId, 'approve', $operator['id'], $operator['username'], '管理员后台新增账号', null, json_encode(['username' => $username, 'status' => 'active', 'source' => 'admin_create'], JSON_UNESCAPED_UNICODE)]);
    $dept = db()->prepare('SELECT code FROM crm_departments WHERE id = ? LIMIT 1');
    $dept->execute([$deptId]);
    apply_department_permission_template_to_user($newUserId, (string)$dept->fetchColumn(), 'admin_create_user');
    return $newUserId;
}

function admin_update_user_account(array $input): int
{
    ensure_user_optional_email_schema();
    ensure_user_profile_schema();
    ensure_default_departments();
    ensure_artdon_department_permission_templates();
    if (
        !is_super_admin()
        && !has_permission('users.create')
        && !has_permission('users.assign_role')
        && !has_permission('users.assign_department')
    ) {
        throw new RuntimeException('没有账号编辑权限。');
    }

    $userId = (int)($input['user_id'] ?? 0);
    $username = trim((string)($input['username'] ?? ''));
    $realName = trim((string)($input['real_name'] ?? ''));
    $englishName = trim((string)($input['english_name'] ?? ''));
    $email = trim((string)($input['email'] ?? ''));
    $emailForDb = $email !== '' ? $email : null;
    $phone = trim((string)($input['phone'] ?? ''));
    $position = trim((string)($input['position'] ?? ''));
    $deptId = user_admin_resolve_department_id($input);
    $roleId = (int)($input['role_id'] ?? 0);
    $status = trim((string)($input['status'] ?? 'active'));

    if (!$userId) {
        throw new RuntimeException('缺少要编辑的账号。');
    }
    if (!preg_match('/^[a-zA-Z0-9_.-]{2,80}$/', $username)) {
        throw new RuntimeException('用户名只能使用字母、数字、点、下划线或横线，长度 2-80。');
    }
    if ($realName === '') {
        throw new RuntimeException('姓名不能为空。');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('邮箱格式不正确。');
    }
    if (!$deptId) {
        throw new RuntimeException('必须分配部门。');
    }
    if (!$roleId) {
        throw new RuntimeException('必须分配角色。');
    }
    if (!in_array($status, ['active', 'locked', 'disabled', 'pending', 'rejected'], true)) {
        throw new RuntimeException('账号状态无效。');
    }

    $beforeStmt = db()->prepare('SELECT id, username, real_name, english_name, email, phone, position, department_id, role_id, status FROM crm_users WHERE id = ? LIMIT 1');
    $beforeStmt->execute([$userId]);
    $before = $beforeStmt->fetch(PDO::FETCH_ASSOC);
    if (!$before) {
        throw new RuntimeException('账号不存在。');
    }

    $exists = db()->prepare('SELECT id FROM crm_users WHERE username = ? AND id <> ? LIMIT 1');
    $exists->execute([$username, $userId]);
    if ($exists->fetchColumn()) {
        throw new RuntimeException('用户名已存在，请换一个账号名。');
    }
    if ($email !== '') {
        $exists = db()->prepare('SELECT id FROM crm_users WHERE email = ? AND id <> ? LIMIT 1');
        $exists->execute([$email, $userId]);
        if ($exists->fetchColumn()) {
            throw new RuntimeException('邮箱已存在，请换一个邮箱。');
        }
    }

    $deptCheck = db()->prepare("SELECT id FROM crm_departments WHERE id = ? AND status = 'active' LIMIT 1");
    $deptCheck->execute([$deptId]);
    if (!$deptCheck->fetchColumn()) {
        throw new RuntimeException('选择的部门无效，请重新选择。');
    }
    $roleCheck = db()->prepare("SELECT id FROM crm_roles WHERE id = ? AND status = 'active' LIMIT 1");
    $roleCheck->execute([$roleId]);
    if (!$roleCheck->fetchColumn()) {
        throw new RuntimeException('选择的角色无效，请重新选择。');
    }

    db()->prepare('UPDATE crm_users SET username = ?, real_name = ?, english_name = ?, email = ?, phone = ?, position = ?, department_id = ?, role_id = ?, status = ?, updated_at = NOW() WHERE id = ?')
        ->execute([$username, $realName, $englishName, $emailForDb, $phone, $position, $deptId, $roleId, $status, $userId]);

    $after = [
        'id' => $userId,
        'username' => $username,
        'real_name' => $realName,
        'english_name' => $englishName,
        'email' => $emailForDb,
        'phone' => $phone,
        'position' => $position,
        'department_id' => $deptId,
        'role_id' => $roleId,
        'status' => $status,
    ];
    if (function_exists('audit_log')) {
        audit_log('user_update_account', 'users', 'user', $userId, $before, $after);
    }
    try {
        $operator = current_user();
        db()->prepare('INSERT INTO crm_user_approval_logs (user_id, action, operator_id, operator_name, reason, before_json, after_json, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())')
            ->execute([$userId, 'update', $operator['id'] ?? null, $operator['username'] ?? '', '权限中心页内编辑账号', json_encode($before, JSON_UNESCAPED_UNICODE), json_encode($after, JSON_UNESCAPED_UNICODE)]);
    } catch (Throwable $e) {
        error_log('admin_update_user_account approval log failed: ' . $e->getMessage());
    }

    return $userId;
}

function user_self_profile_row(int $userId): array
{
    ensure_user_optional_email_schema();
    ensure_user_profile_schema();
    $stmt = db()->prepare("SELECT u.id, u.username, u.real_name, u.english_name, u.email, u.phone, u.position, u.department_id, u.role_id, u.status, r.role_name, d.name AS department_name FROM crm_users u LEFT JOIN crm_roles r ON r.id = u.role_id LEFT JOIN crm_departments d ON d.id = u.department_id WHERE u.id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('账号不存在。');
    }
    return $row;
}

function user_self_update_profile(array $input): array
{
    ensure_user_optional_email_schema();
    ensure_user_profile_schema();
    ensure_default_departments();
    $user = current_user();
    if (!$user) {
        throw new RuntimeException('请先登录。');
    }
    $userId = (int)$user['id'];
    $before = user_self_profile_row($userId);

    $username = trim((string)($input['username'] ?? $before['username']));
    $realName = trim((string)($input['real_name'] ?? $before['real_name']));
    $englishName = trim((string)($input['english_name'] ?? $before['english_name']));
    $email = trim((string)($input['email'] ?? ''));
    $emailForDb = $email !== '' ? $email : null;
    $phone = trim((string)($input['phone'] ?? ''));
    $position = trim((string)($input['position'] ?? ''));
    $departmentName = trim((string)($input['department_name'] ?? ''));

    if (!preg_match('/^[a-zA-Z0-9_.-]{2,80}$/', $username)) {
        throw new RuntimeException('账号只能使用字母、数字、点、下划线或横线，长度 2-80。');
    }
    if ($realName === '') {
        throw new RuntimeException('姓名不能为空。');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('邮箱格式不正确。');
    }

    $exists = db()->prepare('SELECT id FROM crm_users WHERE username = ? AND id <> ? LIMIT 1');
    $exists->execute([$username, $userId]);
    if ($exists->fetchColumn()) {
        throw new RuntimeException('账号已存在，请换一个账号名。');
    }
    if ($email !== '') {
        $exists = db()->prepare('SELECT id FROM crm_users WHERE email = ? AND id <> ? LIMIT 1');
        $exists->execute([$email, $userId]);
        if ($exists->fetchColumn()) {
            throw new RuntimeException('邮箱已存在，请换一个邮箱。');
        }
    }

    $deptInput = ['department_name' => $departmentName, 'department_id' => (int)($before['department_id'] ?? 0)];
    $deptId = user_admin_resolve_department_id($deptInput);
    db()->prepare('UPDATE crm_users SET username = ?, real_name = ?, english_name = ?, email = ?, phone = ?, position = ?, department_id = ?, updated_at = NOW() WHERE id = ?')
        ->execute([$username, $realName, $englishName, $emailForDb, $phone, $position, $deptId ?: null, $userId]);

    $after = user_self_profile_row($userId);
    if (function_exists('audit_log')) {
        audit_log('user_self_update_profile', 'profile', 'user', $userId, $before, $after);
    }
    return $after;
}

function user_self_change_password(array $input): void
{
    $user = current_user();
    if (!$user) {
        throw new RuntimeException('请先登录。');
    }
    $old = (string)($input['old_password'] ?? '');
    $new = (string)($input['new_password'] ?? '');
    $confirm = (string)($input['confirm_password'] ?? '');
    if (!password_verify($old, (string)$user['password_hash'])) {
        throw new RuntimeException('旧密码不正确。');
    }
    if ($new !== $confirm) {
        throw new RuntimeException('两次新密码不一致。');
    }
    if (!password_is_strong($new)) {
        throw new RuntimeException(password_strength_message());
    }
    db()->prepare('UPDATE crm_users SET password_hash = ?, force_password_change = 0, updated_at = NOW() WHERE id = ?')
        ->execute([password_hash($new, PASSWORD_DEFAULT), (int)$user['id']]);
    if (function_exists('audit_log')) {
        audit_log('change_password', 'profile', 'user', (int)$user['id']);
    }
}
