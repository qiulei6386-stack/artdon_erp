<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/user_admin_service.php';
require_once __DIR__ . '/crm_auth.php';
require_login();
ensure_user_create_permission();
ensure_user_profile_schema();
ensure_default_departments();
crm_ensure_permissions();
ensure_artdon_department_permission_templates();

$message = $error = '';
$tab = $_GET['tab'] ?? 'people';

function can_open_approval_center()
{
    return is_super_admin()
        || has_permission('permission_request.approve_all')
        || has_permission('permission_request.approve_department')
        || has_permission('dangerous.permission_admin');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_csrf();
    try {
        $mode = $_POST['mode'] ?? '';
        if ($mode === 'create_request') {
            require_permission('permission_request.create');
            create_permission_request($_POST);
            $message = '权限申请已提交。';
            $tab = 'request';
        } elseif ($mode === 'cancel_request') {
            require_permission('permission_request.cancel_own');
            $requestId = (int)$_POST['request_id'];
            $stmt = db()->prepare("SELECT * FROM crm_permission_requests WHERE id = ? AND requester_id = ? AND status = 'pending'");
            $stmt->execute([$requestId, current_user()['id']]);
            $before = $stmt->fetch();
            if (!$before) throw new RuntimeException('只能取消自己的待审批申请。');
            db()->prepare("UPDATE crm_permission_requests SET status = 'cancelled', updated_at = NOW() WHERE id = ?")->execute([$requestId]);
            permission_request_log($requestId, current_user()['id'], 'cancel', $before, ['status' => 'cancelled'], '用户取消申请');
            audit_log('cancel_permission_request', 'permission', 'request', $requestId, $before, ['status' => 'cancelled']);
            $message = '申请已取消。';
            $tab = 'my';
        } elseif ($mode === 'approve_request') {
            approve_permission_request((int)$_POST['request_id'], $_POST);
            $message = '权限申请已批准。';
            $tab = 'approval';
        } elseif ($mode === 'reject_request') {
            reject_permission_request((int)$_POST['request_id'], trim($_POST['reject_reason'] ?? ''));
            $message = '权限申请已拒绝。';
            $tab = 'approval';
        } elseif ($mode === 'revoke_grant') {
            revoke_permission_grant((int)$_POST['grant_id'], trim($_POST['revoke_reason'] ?? ''));
            $message = '授权已撤销。';
            $tab = 'approval';
        } elseif ($mode === 'expire_check') {
            require_permission('permission_request.expire_check');
            $count = expire_old_permission_grants();
            $message = '已检查过期权限，处理 ' . $count . ' 条。';
            $tab = 'approval';
        } elseif ($mode === 'role_permissions') {
            require_permission('dangerous.permission_admin');
            $roleId = (int)($_POST['role_id'] ?? 0);
            $roleStmt = db()->prepare('SELECT * FROM crm_roles WHERE id = ?');
            $roleStmt->execute([$roleId]);
            $role = $roleStmt->fetch();
            if (!$role) throw new RuntimeException('角色不存在。');
            if ($role['role_key'] === 'super_admin' && !is_super_admin()) throw new RuntimeException('super_admin 权限不可被普通管理员移除。');
            $before = db()->prepare('SELECT permission_key FROM crm_role_permissions WHERE role_id = ?');
            $before->execute([$roleId]);
            $beforeKeys = $before->fetchAll(PDO::FETCH_COLUMN);
            db()->prepare('DELETE FROM crm_role_permissions WHERE role_id = ?')->execute([$roleId]);
            $insert = db()->prepare('INSERT IGNORE INTO crm_role_permissions (role_id, permission_key) VALUES (?, ?)');
            foreach ($_POST['permissions'] ?? [] as $key) $insert->execute([$roleId, $key]);
            audit_permission_event('save_role_permissions', 'role', $roleId, $beforeKeys, $_POST['permissions'] ?? []);
            $message = '角色权限已保存。';
            $tab = 'matrix';
        } elseif ($mode === 'crm_role_permissions' || $mode === 'system_role_permissions') {
            require_permission('dangerous.permission_admin');
            $roleId = (int)($_POST['role_id'] ?? 0);
            $systemKey = permission_system_key((string)($_POST['system_key'] ?? 'crm'));
            $roleStmt = db()->prepare('SELECT * FROM crm_roles WHERE id = ?');
            $roleStmt->execute([$roleId]);
            $role = $roleStmt->fetch();
            if (!$role) throw new RuntimeException('角色不存在。');
            if ($role['role_key'] === 'super_admin' && !is_super_admin()) throw new RuntimeException('super_admin 权限不可被普通管理员移除。');

            $systemKeys = permission_system_keys($systemKey);
            if (!$systemKeys) throw new RuntimeException('当前系统没有可管理的权限项。');
            $submitted = array_values(array_intersect($_POST['permissions'] ?? [], $systemKeys));
            $before = db()->prepare('SELECT permission_key FROM crm_role_permissions WHERE role_id = ? AND permission_key IN (' . implode(',', array_fill(0, count($systemKeys), '?')) . ')');
            $before->execute(array_merge([$roleId], $systemKeys));
            $beforeKeys = $before->fetchAll(PDO::FETCH_COLUMN);

            $delete = db()->prepare('DELETE FROM crm_role_permissions WHERE role_id = ? AND permission_key IN (' . implode(',', array_fill(0, count($systemKeys), '?')) . ')');
            $delete->execute(array_merge([$roleId], $systemKeys));
            $insert = db()->prepare('INSERT IGNORE INTO crm_role_permissions (role_id, permission_key) VALUES (?, ?)');
            foreach ($submitted as $key) $insert->execute([$roleId, $key]);
            audit_permission_event('save_system_role_permissions', 'role', $roleId, ['system' => $systemKey, 'permissions' => $beforeKeys], ['system' => $systemKey, 'permissions' => $submitted]);
            $message = permission_system_label($systemKey) . '角色权限已保存。';
            $tab = 'systems';
        } elseif ($mode === 'user_permission') {
            require_permission('users.assign_permission');
            $userId = (int)$_POST['user_id'];
            db()->prepare('INSERT INTO crm_user_permissions (user_id, permission_key, effect, created_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE effect = VALUES(effect)')->execute([$userId, $_POST['permission_key'], $_POST['effect']]);
            audit_permission_event('save_user_permission', 'user', $userId, null, $_POST);
            $message = '用户权限已保存。';
            $returnTab = preg_replace('/[^a-z0-9_\\-]/i', '', (string)($_POST['return_tab'] ?? 'people'));
            $tab = $returnTab ?: 'people';
        } elseif ($mode === 'apply_permission_preset') {
            require_permission('users.assign_permission');
            $userId = (int)($_POST['user_id'] ?? 0);
            $systemKey = permission_system_key((string)($_POST['system_key'] ?? 'crm'));
            $presetKey = permission_preset_key((string)($_POST['preset_key'] ?? 'employee'));
            $systemKeys = permission_system_keys($systemKey);
            if (!$userId || !$systemKeys) throw new RuntimeException('缺少用户或系统权限项。');
            $before = db()->prepare('SELECT permission_key, effect FROM crm_user_permissions WHERE user_id = ? AND permission_key IN (' . implode(',', array_fill(0, count($systemKeys), '?')) . ') ORDER BY permission_key');
            $before->execute(array_merge([$userId], $systemKeys));
            $beforeRows = $before->fetchAll();
            $presetPerms = db()->query("SELECT * FROM crm_permissions ORDER BY module, permission_key")->fetchAll();
            $allowKeys = permission_preset_keys($systemKey, $presetKey, $presetPerms);
            $allowSet = array_fill_keys($allowKeys, true);
            db()->prepare('DELETE FROM crm_user_permissions WHERE user_id = ? AND permission_key IN (' . implode(',', array_fill(0, count($systemKeys), '?')) . ')')->execute(array_merge([$userId], $systemKeys));
            $insert = db()->prepare('INSERT INTO crm_user_permissions (user_id, permission_key, effect, created_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE effect = VALUES(effect)');
            foreach ($systemKeys as $key) {
                if (isset($allowSet[$key])) {
                    $insert->execute([$userId, $key, 'allow']);
                }
            }
            audit_permission_event('apply_permission_preset', 'user', $userId, ['system' => $systemKey, 'before' => $beforeRows], ['system' => $systemKey, 'preset' => $presetKey, 'allow' => $allowKeys]);
            $message = '已应用「' . permission_preset_label($presetKey) . '」到 ' . permission_system_label($systemKey) . ' 权限。';
            $tab = 'systems';
        } elseif ($mode === 'scope') {
            require_permission('users.assign_permission');
            $userId = (int)$_POST['user_id'];
            db()->prepare('INSERT INTO crm_user_scopes (user_id, module, scope_type, scope_value, created_at) VALUES (?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE scope_type = VALUES(scope_type), scope_value = VALUES(scope_value)')->execute([$userId, $_POST['module'], $_POST['scope_type'], $_POST['scope_value'] ?? '']);
            audit_permission_event('save_scope', 'user', $userId, null, $_POST);
            $message = '数据范围已保存。';
            $tab = 'people';
        } elseif ($mode === 'field') {
            require_permission('users.assign_permission');
            db()->prepare('INSERT INTO crm_field_permissions (role_id, user_id, field_key, can_view, can_export, created_at) VALUES (?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE can_view = VALUES(can_view), can_export = VALUES(can_export)')->execute([$_POST['role_id'] ?: null, $_POST['user_id'] ?: null, $_POST['field_key'], !empty($_POST['can_view']) ? 1 : 0, !empty($_POST['can_export']) ? 1 : 0]);
            audit_permission_event('save_field_permission', 'field', $_POST['field_key'], null, $_POST);
            $message = '字段权限已保存。';
            $tab = 'people';
        } elseif ($mode === 'update_user_account') {
            $updatedUserId = admin_update_user_account($_POST);
            $_GET['user_id'] = (string)$updatedUserId;
            $message = '账号资料已保存。';
            $tab = 'people';
        } elseif ($mode === 'delete_user_account') {
            $deletedUserId = (int)($_POST['user_id'] ?? 0);
            admin_delete_user_account($deletedUserId);
            $_GET['user_id'] = '';
            $message = '账号已删除。';
            $tab = 'people';
        } elseif ($mode === 'create_user') {
            admin_create_user($_POST);
            $message = '账号已创建，首次登录将要求修改密码。';
            $tab = 'people';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

function permission_module_name(string $module): string
{
    $map = [
        'dashboard' => '基础工作台',
        'mail' => '邮件',
        'customer' => '客户管理',
        'contact' => '联系人',
        'follow' => '跟进记录',
        'promotion' => '推广管理',
        'visit' => '拜访 / 来访',
        'opportunity' => '商机中心',
        'logs' => '日志审计',
        'import' => '导入',
        'export' => '导出',
        'settings' => '系统',
        'users' => '用户权限',
        'dangerous' => '高危操作',
        'field' => '字段权限',
        'permission_request' => '权限申请',
        'crm' => 'CRM',
        'crm_config' => 'CRM 配置',
        'online' => '在线状态',
        'material' => '资料中心',
        'dispatch' => '派工待办',
        'quote' => '报价系统',
        'bom' => 'BOM 系统',
        'naming' => '型号命名',
        'datasheet' => '资料生成',
        'ai' => 'AI 机器人',
        'linkage' => '联动中心',
        'notifications' => '通知中心',
        'task' => '任务中心',
        'sample' => '样品寄送',
    ];
    return $map[$module] ?? $module;
}

function permission_action_name(string $action): string
{
    $map = [
        'view' => '查看',
        'create' => '创建',
        'edit' => '编辑',
        'delete' => '删除',
        'send' => '发送',
        'reply' => '回复',
        'compose' => '写信',
        'export' => '导出',
        'import' => '导入',
        'assign' => '分配',
        'confirm' => '确认',
        'result' => '填写结果',
        'report' => '报表',
        'stage' => '推进阶段',
        'win' => '标记赢单',
        'lose' => '标记输单',
        'complete' => '完成',
        'delay' => '延期',
        'shipped' => '标记寄出',
        'signed' => '标记签收',
        'tracking' => '快递跟踪',
        'reception' => '接待准备',
        'convert_followup' => '转跟进',
        'dispatch' => '创建派工',
        'quote' => '创建报价',
        'material' => '生成资料',
        'lead_capture' => '自动获客',
        'quote_draft' => '报价草稿',
        'material_draft' => '资料草稿',
        'confirm_own' => '确认本人任务',
        'confirm_all' => '确认全部任务',
        'confirm_customer' => '确认客户建议',
        'confirm_material' => '确认资料草稿',
        'confirm_opportunity' => '确认商机建议',
        'confirm_quote' => '确认报价草稿',
        'reject_own' => '驳回本人草稿',
        'settings' => '设置',
        'logs' => '日志',
        'auto_send' => '自动发送',
        'amount_view' => '查看金额',
        'approve' => '审批',
        'reject' => '驳回',
        'disable' => '禁用',
        'enable' => '启用',
        'reset_password' => '重置密码',
        'assign_role' => '分配角色',
        'assign_department' => '分配部门',
        'assign_permission' => '分配权限',
        'manage_roles' => '管理角色',
        'manage_departments' => '管理部门',
        'schema_repair' => '字段修复',
        'schema_scan' => '字段检测',
        'view_body' => '查看正文',
        'view_attachments' => '查看附件',
        'bulk_delete' => '批量删除',
        'bulk_export' => '批量导出',
        'permission_admin' => '权限管理员',
        'approve_all' => '审批全部申请',
        'approve_department' => '审批部门申请',
        'view_own' => '查看本人',
        'cancel_own' => '取消本人申请',
        'revoke' => '撤销授权',
        'expire_check' => '检查过期授权',
        'sync' => '同步',
        'link_customer' => '关联客户',
        'attachment_download' => '下载附件',
        'account_bind_own' => '绑定本人邮箱',
        'account_manage_all' => '管理员工邮箱',
        'manage_accounts' => '管理邮箱账号',
        'archive_to_customer' => '归档到客户',
        'signature_manage_own' => '管理本人签名',
        'signature_batch_apply' => '批量应用签名',
        'manage' => '管理',
        'audit' => '审计',
        'task_create' => '创建任务',
        'execute' => '执行',
        'analytics' => '数据分析',
        'create_project' => '创建项目',
        'edit_project' => '编辑项目',
        'delete_project' => '删除项目',
        'create_group' => '创建分组',
        'edit_group' => '编辑分组',
        'delete_group' => '删除分组',
        'move_customer' => '移动客户',
        'stop_promotion' => '停止推广',
        'restore_promotion' => '恢复推广',
        'promotion_manage' => '推广管理',
        'batch' => '批量/分组',
        'transfer_public' => '转入公海',
        'claim_public' => '领取公海',
        'public_pool' => '公海池',
        'lead_pool' => '暂存池',
        'lead_pool_view' => '查看暂存池',
        'manage_temp_pool' => '管理临时池',
        'blacklist' => '黑名单',
        'sensitive_audit' => '敏感字段审计',
        'merge' => '合并查重',
        'graph_manage' => '关系图谱',
        'timeline_view' => '时间线',
        'event_manage' => '重要事件',
        'file_upload' => '上传文件',
        'file_delete' => '删除文件',
        'file_preview' => '预览文件',
        'file_download' => '下载文件',
        'delete_file' => '删除文件',
        'delete_image' => '删除图片',
        'force_delete' => '彻底删除',
        'mail_summary' => '邮件摘要',
        'quote_summary' => '报价摘要',
        'plm_summary' => 'PLM 摘要',
        'bom_summary' => 'BOM 摘要',
        'dispatch_summary' => '派工摘要',
        'order_summary' => '订单摘要',
        'material_summary' => '资料摘要',
        'preferences_edit' => '修改偏好',
        'layout_company' => '修改公司布局',
        'view_storage' => '查看资料库',
        'template_edit' => '编辑模板',
        'view_internal' => '查看内部资料',
        'convert_customer' => '转客户资料',
        'view_all' => '查看全部',
        'view_department' => '查看本部门',
        'view_logs' => '查看日志',
        'view_link_preview' => '查看联动预览',
        'view_notifications' => '查看通知',
        'create_personal' => '创建个人待办',
        'create_private' => '创建私人待办',
        'create_dispatch' => '创建单人派工',
        'create_multi' => '创建多人派工',
        'create_plan' => '创建计划派工',
        'create_recurring' => '创建周期派工',
        'edit_own' => '编辑自己创建',
        'edit_assigned' => '编辑分配给自己',
        'edit_all' => '编辑全部',
        'edit_cell' => '单元格即时编辑',
        'change_status' => '修改状态',
        'change_assignee' => '修改负责人',
        'change_due_date' => '修改截止日期',
        'row_order' => '调整行顺序',
        'table_customize' => '表格自定义',
        'custom_fields' => '自定义列',
        'comment' => '评论/进度',
        'urge' => '催办',
        'upload_image' => '上传图片',
        'upload_attachment' => '上传附件',
        'download_attachment' => '下载附件',
        'delete_attachment' => '删除附件',
        'download' => '下载',
        'delete_own' => '删除自己创建',
        'delete_all' => '删除任意任务',
        'delete_multi' => '删除多人派工组',
        'restore' => '恢复',
        'backup' => '备份',
        'manage_visibility' => '管理人员可见范围',
        'manage_permissions' => '管理派工权限',
        'init_schema' => '初始化/修复表',
        'admin' => '管理员',
        'sync_website' => '同步官网',
        'order_convert' => '转订单',
        'naming_select' => '选择命名型号',
        'cost_view' => '查看成本',
        'supplier_view' => '查看供应商',
        'naming_link' => '联动型号命名',
        'view_cost' => '查看成本',
        'view_full' => '查看完整内容',
        'sync_source' => '同步来源资料',
        'generate_pdf' => '生成 PDF',
        'generate_excel' => '生成 Excel',
        'package' => '生成资料包',
        'storage' => '资料空间',
        'upload_file' => '上传附件',
        'base' => '基础设置',
        'country' => '国家设置',
        'customer_level' => '客户等级',
        'customer_source' => '客户来源',
        'defaults' => '默认值',
        'duplicate' => '查重规则',
        'field' => '字段设置',
        'owner_role' => '归属规则',
        'promotion' => '推广设置',
    ];
    return $map[$action] ?? str_replace('_', ' ', $action);
}

function permission_view_meta(array $p): array
{
    $module = (string)$p['module'];
    $key = (string)$p['permission_key'];
    $riskLevel = (string)($p['risk_level'] ?? 'low');
    $isDanger = is_dangerous_permission($key) || in_array($riskLevel, ['dangerous', 'high'], true);
    $risk = $isDanger ? 'danger' : ($riskLevel === 'medium' ? 'sensitive' : 'normal');
    $riskText = $risk === 'danger' ? '高危' : ($risk === 'sensitive' ? '敏感' : '普通');
    $title = permission_module_name($module) . ' - ' . permission_action_name((string)$p['action']);
    $desc = trim((string)($p['description'] ?? ''));
    if ($desc === '') {
        $desc = '允许执行“' . $title . '”相关操作。';
    }
    if (strpos($key, 'customer.view_') === 0) {
        $title = '客户字段 - ' . permission_action_name(substr($key, strlen('customer.')));
        $desc = '控制客户敏感字段的查看范围。';
    }
    if (strpos($key, 'mail.view_') === 0) {
        $title = '邮件 - ' . permission_action_name(substr($key, strlen('mail.')));
        $desc = '控制邮件正文和附件等敏感信息查看权限。';
    }
    return ['title' => $title, 'desc' => $desc, 'risk' => $risk, 'risk_text' => $riskText, 'key' => $key, 'module' => $module];
}

function permission_bucket(array $p): string
{
    $key = (string)$p['permission_key'];
    $module = (string)$p['module'];
    if (is_dangerous_permission($key) || in_array($p['risk_level'] ?? '', ['high', 'dangerous'], true)) return 'danger';
    if (in_array($module, ['customer', 'contact', 'follow', 'visit', 'opportunity', 'field', 'import', 'export', 'task', 'sample'], true)) return 'customer';
    if ($module === 'mail') return 'mail';
    if ($module === 'promotion') return 'promotion';
    if ($module === 'dispatch') return 'system';
    if (in_array($module, ['settings', 'users', 'logs', 'permission_request'], true)) return 'system';
    return 'base';
}

function permission_system_definitions(): array
{
    return [
        'crm' => [
            'label' => 'CRM',
            'modules' => ['dashboard', 'customer', 'contact', 'follow', 'visit', 'opportunity', 'task', 'sample', 'mail', 'promotion', 'crm', 'crm_config', 'online', 'field', 'material'],
            'prefixes' => ['dashboard.', 'customer.', 'contact.', 'follow.', 'visit.', 'opportunity.', 'task.', 'sample.', 'mail.', 'promotion.', 'crm.', 'online.', 'field.', 'material.'],
            'domains' => ['workspace', 'customer', 'contact', 'follow', 'visit', 'opportunity', 'task', 'sample', 'mail', 'promotion', 'field', 'config', 'online', 'material'],
        ],
        'dispatch' => ['label' => '派工待办', 'modules' => ['dispatch'], 'prefixes' => ['dispatch.'], 'domains' => ['dispatch']],
        'quote' => ['label' => '报价系统', 'modules' => ['quote'], 'prefixes' => ['quote.'], 'domains' => ['quote']],
        'bom' => ['label' => 'BOM 系统', 'modules' => ['bom'], 'prefixes' => ['bom.'], 'domains' => ['bom']],
        'naming' => ['label' => '型号命名', 'modules' => ['naming'], 'prefixes' => ['naming.'], 'domains' => ['naming']],
        'datasheet' => ['label' => '资料生成', 'modules' => ['datasheet'], 'prefixes' => ['datasheet.'], 'domains' => ['datasheet']],
        'ai' => ['label' => 'AI 机器人', 'modules' => ['ai'], 'prefixes' => ['ai.'], 'domains' => ['ai']],
        'linkage' => ['label' => '联动中心', 'modules' => ['linkage'], 'prefixes' => ['linkage.'], 'domains' => ['linkage']],
        'system' => [
            'label' => '系统/账号',
            'modules' => ['settings', 'users', 'logs', 'permission_request', 'dangerous', 'notifications', 'import', 'export'],
            'prefixes' => ['settings.', 'users.', 'logs.', 'permission_request.', 'dangerous.', 'notifications.', 'import.', 'export.'],
            'domains' => ['system', 'notifications'],
        ],
    ];
}

function permission_system_key(string $key): string
{
    $key = strtolower(trim($key));
    return isset(permission_system_definitions()[$key]) ? $key : 'crm';
}

function permission_system_label(string $key): string
{
    $systems = permission_system_definitions();
    return $systems[permission_system_key($key)]['label'];
}

function permission_system_for_permission(array $p): string
{
    $key = (string)$p['permission_key'];
    $module = (string)$p['module'];
    foreach (permission_system_definitions() as $systemKey => $def) {
        if (in_array($module, $def['modules'], true)) return $systemKey;
        foreach ($def['prefixes'] as $prefix) {
            if (strpos($key, $prefix) === 0) return $systemKey;
        }
    }
    return 'system';
}

function is_system_permission(array $p, ?string $systemKey = null): bool
{
    $resolved = permission_system_for_permission($p);
    return $systemKey === null ? isset(permission_system_definitions()[$resolved]) : $resolved === permission_system_key($systemKey);
}

function permission_system_domain(array $p): string
{
    $key = (string)$p['permission_key'];
    $module = (string)$p['module'];
    if ($key === 'dashboard.view') return 'workspace';
    if (strpos($key, 'mail.') === 0 || $module === 'mail') return 'mail';
    if (strpos($key, 'promotion.') === 0 || $module === 'promotion') return 'promotion';
    if (strpos($key, 'visit.') === 0 || $module === 'visit') return 'visit';
    if (strpos($key, 'opportunity.') === 0 || $module === 'opportunity') return 'opportunity';
    if (strpos($key, 'task.') === 0 || $module === 'task') return 'task';
    if (strpos($key, 'sample.') === 0 || $module === 'sample') return 'sample';
    if (strpos($key, 'contact.') === 0 || $module === 'contact') return 'contact';
    if (strpos($key, 'follow.') === 0 || $module === 'follow') return 'follow';
    if ($module === 'field' || preg_match('/^(customer|mail)\.view_/', $key)) return 'field';
    if (strpos($key, 'crm.config.') === 0 || $module === 'crm_config') return 'config';
    if (strpos($key, 'online.') === 0 || $module === 'online') return 'online';
    if (strpos($key, 'material.') === 0 || $module === 'material') return 'material';
    if (strpos($key, 'dispatch.') === 0 || $module === 'dispatch') return 'dispatch';
    if (strpos($key, 'quote.') === 0 || $module === 'quote') return 'quote';
    if (strpos($key, 'bom.') === 0 || $module === 'bom') return 'bom';
    if (strpos($key, 'naming.') === 0 || $module === 'naming') return 'naming';
    if (strpos($key, 'datasheet.') === 0 || $module === 'datasheet') return 'datasheet';
    if (strpos($key, 'ai.') === 0 || $module === 'ai') return 'ai';
    if (strpos($key, 'linkage.') === 0 || $module === 'linkage') return 'linkage';
    if (strpos($key, 'notifications.') === 0 || $module === 'notifications') return 'notifications';
    if (in_array($module, ['settings', 'users', 'logs', 'permission_request', 'dangerous', 'import', 'export'], true)) return 'system';
    if (strpos($key, 'customer.') === 0 || $module === 'customer') return 'customer';
    return 'workspace';
}

function permission_domain_label(string $domain): string
{
    $map = [
        'workspace' => 'CRM 工作台',
        'customer' => '客户资料',
        'contact' => '联系人',
        'follow' => '跟进记录',
        'mail' => '邮件联动',
        'promotion' => '推广中心',
        'visit' => '拜访 / 来访',
        'opportunity' => '商机中心',
        'task' => '任务中心',
        'sample' => '样品寄送',
        'field' => '敏感字段',
        'config' => 'CRM 配置',
        'online' => '在线状态',
        'material' => '资料/BOM',
        'dispatch' => '派工待办',
        'quote' => '报价系统',
        'bom' => 'BOM 系统',
        'naming' => '型号命名',
        'datasheet' => '资料生成',
        'ai' => 'AI 机器人',
        'linkage' => '联动中心',
        'notifications' => '通知中心',
        'system' => '系统/账号',
    ];
    return $map[$domain] ?? $domain;
}

function permission_system_keys(string $systemKey): array
{
    static $cache = [];
    $systemKey = permission_system_key($systemKey);
    if (isset($cache[$systemKey])) return $cache[$systemKey];
    $keys = [];
    foreach (db()->query("SELECT permission_key, module FROM crm_permissions ORDER BY permission_key")->fetchAll() as $p) {
        if (is_system_permission($p, $systemKey)) $keys[] = $p['permission_key'];
    }
    return $cache[$systemKey] = $keys;
}

function permission_preset_definitions(): array
{
    return [
        'boss' => ['label' => '老板/管理员', 'desc' => '当前系统全部权限，包含高危、管理、导出、删除和敏感数据。'],
        'sales' => ['label' => '业务部', 'desc' => '客户、邮箱、推广、商机、报价需求、资料和拜访来访。'],
        'engineering' => ['label' => '工程部', 'desc' => '技术确认、PLM、样品、资料、BOM 技术查看和派工执行。'],
        'purchase' => ['label' => '采购部', 'desc' => 'BOM 采购价、供应商、交期、成本确认和采购派工。'],
        'production' => ['label' => '生产部', 'desc' => '生产任务、样品制作、派工执行、异常反馈。'],
        'manager' => ['label' => '主管', 'desc' => '日常管理权限，可查看更多数据和执行主要业务操作，不含极高危权限。'],
        'employee' => ['label' => '员工', 'desc' => '日常使用权限，允许查看、创建、编辑自己相关数据和基础附件操作。'],
        'readonly' => ['label' => '只读/临时', 'desc' => '仅查看权限，不允许新增、编辑、删除和导出。'],
    ];
}

function permission_preset_key(string $key): string
{
    $key = strtolower(trim($key));
    return isset(permission_preset_definitions()[$key]) ? $key : 'employee';
}

function permission_preset_label(string $key): string
{
    $defs = permission_preset_definitions();
    return $defs[permission_preset_key($key)]['label'];
}

function permission_preset_keys(string $systemKey, string $presetKey, array $perms): array
{
    $systemKey = permission_system_key($systemKey);
    $presetKey = permission_preset_key($presetKey);
    $items = array_values(array_filter($perms, fn($p) => is_system_permission($p, $systemKey)));
    if (in_array($presetKey, ['sales','engineering','purchase','production'], true)) {
        $departmentTemplates = [
            'sales' => artdon_department_permission_templates()['sales_department']['permissions'] ?? [],
            'engineering' => artdon_department_permission_templates()['engineering_department']['permissions'] ?? [],
            'purchase' => artdon_department_permission_templates()['purchase_department']['permissions'] ?? [],
            'production' => artdon_department_permission_templates()['production_department']['permissions'] ?? [],
        ];
        $systemSet = array_fill_keys(array_map(fn($p) => (string)$p['permission_key'], $items), true);
        return array_values(array_filter($departmentTemplates[$presetKey] ?? [], fn($key) => isset($systemSet[$key])));
    }
    if ($presetKey === 'boss') {
        return array_values(array_map(fn($p) => (string)$p['permission_key'], $items));
    }
    $allow = [];
    foreach ($items as $p) {
        $key = (string)$p['permission_key'];
        $action = (string)$p['action'];
        $risk = (string)($p['risk_level'] ?? 'low');
        $isDanger = is_dangerous_permission($key) || in_array($risk, ['dangerous'], true);
        if ($presetKey === 'readonly') {
            if ($action === 'view' || str_starts_with($action, 'view_') || preg_match('/\\.view(_|$)/', $key)) $allow[] = $key;
            continue;
        }
        if ($presetKey === 'employee') {
            $employeeActions = [
                'view', 'view_notifications', 'view_link_preview',
                'create', 'create_personal', 'create_private', 'create_dispatch',
                'edit', 'edit_own', 'edit_assigned', 'edit_cell',
                'change_status', 'change_due_date', 'row_order', 'table_customize',
                'comment', 'urge', 'upload_image', 'upload_attachment', 'download_attachment',
                'download', 'generate_pdf', 'generate_excel', 'package', 'naming_select', 'naming_link',
                'sync', 'attachment_download', 'account_bind_own', 'signature_manage_own', 'link_customer', 'reply', 'compose', 'send',
                'task_create', 'execute', 'analytics',
                'batch', 'lead_pool', 'claim_public', 'timeline_view', 'file_upload',
            ];
            if (!$isDanger && (in_array($action, $employeeActions, true) || $action === 'view' || str_starts_with($action, 'view_'))) $allow[] = $key;
            continue;
        }
        if ($presetKey === 'manager') {
            $blocked = [
                'admin', 'manage_permissions', 'init_schema', 'schema_repair', 'delete_all',
                'delete_multi', 'backup_restore', 'permission_admin', 'reset_password',
                'signature_batch_apply', 'sync_website', 'sync_source',
            ];
            if (in_array($action, $blocked, true)) continue;
            if ($isDanger && !in_array($action, ['delete_own', 'delete', 'export', 'backup', 'approve', 'cost_view', 'supplier_view'], true)) continue;
            $allow[] = $key;
        }
    }
    return array_values(array_unique($allow));
}

function permission_effective_for_board(string $permissionKey, array $overrides, array $rolePerms): bool
{
    if (($overrides[$permissionKey] ?? '') === 'deny') return false;
    if (($overrides[$permissionKey] ?? '') === 'allow' || isset($rolePerms[$permissionKey])) return true;
    $aliases = function_exists('permission_alias_keys') ? permission_alias_keys($permissionKey) : [];
    foreach ($aliases as $alias) {
        if (($overrides[$alias] ?? '') === 'deny') return false;
    }
    foreach ($aliases as $alias) {
        if (($overrides[$alias] ?? '') === 'allow' || isset($rolePerms[$alias])) return true;
    }
    return false;
}

function status_label(string $status): string
{
    return ['active' => '启用', 'pending' => '待审核', 'disabled' => '禁用', 'rejected' => '驳回', 'locked' => '锁定'][$status] ?? $status;
}

function status_class(string $status): string
{
    return ['active' => 'ok', 'pending' => 'wait', 'disabled' => 'off', 'rejected' => 'off', 'locked' => 'off'][$status] ?? 'off';
}

function time_left_text(?string $expiresAt): string
{
    if (!$expiresAt) return '永久';
    $seconds = strtotime($expiresAt) - time();
    if ($seconds <= 0) return '已到期';
    $days = intdiv($seconds, 86400);
    if ($days > 0) return $days . ' 天';
    $hours = max(1, intdiv($seconds, 3600));
    return $hours . ' 小时';
}

$user = current_user();
$requestTablesReady = permission_request_tables_ready();
$roles = db()->query("SELECT * FROM crm_roles ORDER BY id")->fetchAll();
$roleKeyToId = [];
foreach ($roles as $roleRow) $roleKeyToId[(string)$roleRow['role_key']] = (int)$roleRow['id'];
$departments = db()->query("SELECT id, name, code FROM crm_departments WHERE status = 'active' ORDER BY sort_order, id")->fetchAll();
$perms = db()->query("SELECT * FROM crm_permissions ORDER BY module, permission_key")->fetchAll();
$permByKey = [];
$permGroups = ['base' => [], 'customer' => [], 'mail' => [], 'promotion' => [], 'system' => [], 'danger' => []];
$permissionSystems = permission_system_definitions();
$selectedSystemKey = permission_system_key((string)($_GET['system'] ?? $_POST['system_key'] ?? 'crm'));
$selectedSystem = $permissionSystems[$selectedSystemKey];
$systemDomains = [];
foreach ($selectedSystem['domains'] as $domain) {
    $systemDomains[$domain] = [];
}
foreach ($perms as $p) {
    $permByKey[$p['permission_key']] = $p;
    $permGroups[permission_bucket($p)][] = $p;
    if (is_system_permission($p, $selectedSystemKey)) {
        $domain = permission_system_domain($p);
        if (!isset($systemDomains[$domain])) $systemDomains[$domain] = [];
        $systemDomains[$domain][] = $p;
    }
}
$systemDomains = array_filter($systemDomains);
$systemPermissionKeys = [];
foreach ($systemDomains as $domainItems) {
    foreach ($domainItems as $p) $systemPermissionKeys[] = $p['permission_key'];
}

$rolePerms = [];
foreach (db()->query('SELECT role_id, permission_key FROM crm_role_permissions')->fetchAll() as $rp) $rolePerms[$rp['role_id']][$rp['permission_key']] = true;

$people = db()->query("SELECT u.*, r.role_key, r.role_name, d.name AS department_name FROM crm_users u LEFT JOIN crm_roles r ON r.id = u.role_id LEFT JOIN crm_departments d ON d.id = u.department_id ORDER BY FIELD(u.status, 'pending','active','locked','disabled','rejected'), u.id DESC LIMIT 200")->fetchAll();
$selectedId = (int)($_GET['user_id'] ?? ($people[0]['id'] ?? $user['id']));
$selectedUser = null;
foreach ($people as $person) {
    if ((int)$person['id'] === $selectedId) {
        $selectedUser = $person;
        break;
    }
}
if (!$selectedUser) $selectedUser = $user;

$selectedRolePerms = [];
if (!empty($selectedUser['role_id'])) {
    $selectedRolePerms = $rolePerms[$selectedUser['role_id']] ?? [];
}
$selectedOverrides = [];
$stmt = db()->prepare('SELECT permission_key, effect FROM crm_user_permissions WHERE user_id = ? ORDER BY permission_key');
$stmt->execute([$selectedUser['id']]);
foreach ($stmt->fetchAll() as $row) $selectedOverrides[$row['permission_key']] = $row['effect'];
$selectedGrants = get_active_permission_grants((int)$selectedUser['id']);

$myRolePerms = [];
if ($user['role_id']) {
    $stmt = db()->prepare('SELECT permission_key FROM crm_role_permissions WHERE role_id = ? ORDER BY permission_key');
    $stmt->execute([$user['role_id']]);
    $myRolePerms = $stmt->fetchAll(PDO::FETCH_COLUMN);
}
$stmt = db()->prepare('SELECT permission_key, effect FROM crm_user_permissions WHERE user_id = ? ORDER BY permission_key');
$stmt->execute([$user['id']]);
$myUserPerms = $stmt->fetchAll();
$myGrants = get_active_permission_grants((int)$user['id']);
$myRequests = [];
if ($requestTablesReady) {
    $stmt = db()->prepare('SELECT * FROM crm_permission_requests WHERE requester_id = ? ORDER BY id DESC LIMIT 100');
    $stmt->execute([$user['id']]);
    $myRequests = $stmt->fetchAll();
}

$pendingSql = "SELECT pr.*, d.name AS department_name, r.role_name FROM crm_permission_requests pr LEFT JOIN crm_departments d ON d.id = pr.department_id LEFT JOIN crm_roles r ON r.id = pr.role_id";
$params = [];
$filter = $_GET['filter'] ?? 'pending';
$where = [];
if ($filter === 'pending') $where[] = "pr.status = 'pending'";
if ($filter === 'dangerous') {
    $dangerKeys = array_filter(array_map(fn($p) => is_dangerous_permission($p['permission_key']) ? $p['permission_key'] : null, $perms));
    $where[] = $dangerKeys ? "pr.permission_key IN ('" . implode("','", array_map('addslashes', $dangerKeys)) . "')" : '1=0';
}
if ($filter === 'urgent') $where[] = "pr.urgency IN ('urgent','critical')";
if ($filter === 'department' && $user['department_id']) {
    $where[] = 'pr.department_id = ?';
    $params[] = $user['department_id'];
}
if ($where) $pendingSql .= ' WHERE ' . implode(' AND ', $where);
$pendingSql .= ' ORDER BY pr.id DESC LIMIT 200';
$requestsForApproval = [];
$grants = [];
if ($requestTablesReady) {
    $stmt = db()->prepare($pendingSql);
    $stmt->execute($params);
    $requestsForApproval = $stmt->fetchAll();
    $grants = db()->query("SELECT pg.*, u.username, u.real_name FROM crm_permission_grants pg LEFT JOIN crm_users u ON u.id = pg.user_id ORDER BY pg.id DESC LIMIT 200")->fetchAll();
}

$counts = [
    'users' => (int)db()->query('SELECT COUNT(*) FROM crm_users')->fetchColumn(),
    'roles' => count($roles),
    'perms' => count($perms),
    'system_perms' => count($systemPermissionKeys),
    'pending_users' => (int)db()->query("SELECT COUNT(*) FROM crm_users WHERE status = 'pending'")->fetchColumn(),
    'pending_requests' => $requestTablesReady ? (int)db()->query("SELECT COUNT(*) FROM crm_permission_requests WHERE status = 'pending'")->fetchColumn() : 0,
    'temporary' => $requestTablesReady ? (int)db()->query("SELECT COUNT(*) FROM crm_permission_grants WHERE status = 'active'")->fetchColumn() : 0,
];

$canAssignPermission = has_permission('users.assign_permission');

require_once __DIR__ . '/includes/layout.php';
page_header('权限中心', ['ui_type' => 'permission', 'page' => 'permission']);
if ($message) flash($message, 'success');
if ($error) flash($error, 'error');
if (!$requestTablesReady) flash('权限申请表尚未初始化。请到设置中心执行“一键初始化 / 修复权限申请表”。', 'error');
?>
<section class="acl-page">
  <div class="acl-hero">
    <div>
      <span class="acl-kicker">Access Control Center</span>
      <h2>企业权限管理系统</h2>
      <p>集中管理员工、角色、权限申请、审批、临时授权和审计记录。权限底层校验仍由后端统一执行。</p>
    </div>
    <label class="acl-advanced"><input type="checkbox" data-acl-advanced> 高级模式显示 key</label>
  </div>

  <div class="acl-metrics">
    <div><span>用户总数</span><strong><?= h($counts['users']) ?></strong><em>People</em></div>
    <div><span>角色数量</span><strong><?= h($counts['roles']) ?></strong><em>Roles</em></div>
    <div><span>权限总数</span><strong><?= h($counts['perms']) ?></strong><em>Rules</em></div>
    <div><span><?= h(permission_system_label($selectedSystemKey)) ?>权限</span><strong><?= h($counts['system_perms']) ?></strong><em>System ACL</em></div>
    <div><span>待审核用户</span><strong><?= h($counts['pending_users']) ?></strong><em>Review</em></div>
    <div><span>待审批权限</span><strong><?= h($counts['pending_requests']) ?></strong><em>Approval</em></div>
  </div>

  <nav class="acl-tabs">
    <a class="<?= $tab === 'people' ? 'active' : '' ?>" href="permissions.php?tab=people&user_id=<?= h($selectedUser['id']) ?>">人员权限</a>
    <?php foreach ($permissionSystems as $navSystem => $navSystemDef): if ($navSystem === 'system') continue; ?>
    <a class="<?= $tab === 'systems' && $selectedSystemKey === $navSystem ? 'active' : '' ?>" href="permissions.php?tab=systems&system=<?= h($navSystem) ?>&user_id=<?= h($selectedUser['id']) ?>"><?= h(permission_system_label($navSystem)) ?>权限</a>
    <?php endforeach; ?>
    <a class="<?= $tab === 'systems' && $selectedSystemKey === 'system' ? 'active' : '' ?>" href="permissions.php?tab=systems&system=system&user_id=<?= h($selectedUser['id']) ?>">系统账号权限</a>
    <a class="<?= $tab === 'my' ? 'active' : '' ?>" href="permissions.php?tab=my">我的权限</a>
    <a class="<?= $tab === 'request' ? 'active' : '' ?>" href="permissions.php?tab=request">申请权限</a>
    <?php if (can_open_approval_center()): ?><a class="<?= $tab === 'approval' ? 'active' : '' ?>" href="permissions.php?tab=approval">审批中心</a><?php endif; ?>
    <?php if (has_permission('dangerous.permission_admin')): ?><a class="<?= $tab === 'matrix' ? 'active' : '' ?>" href="permissions.php?tab=matrix">角色模板</a><?php endif; ?>
  </nav>

<?php if ($tab === 'request'): ?>
  <section class="acl-panel">
    <div class="acl-panel-head"><h3>申请权限</h3><p>选择需要申请的权限、范围和使用时长，审批通过后将生成临时授权。</p></div>
    <form method="post" class="acl-form-grid">
      <?= csrf_field() ?><input type="hidden" name="mode" value="create_request">
      <label>申请模块<select name="module_hint"><?php foreach (array_keys($permGroups) as $bucket): ?><option value="<?= h($bucket) ?>"><?= h(['base'=>'基础权限','customer'=>'客户权限','mail'=>'邮件权限','promotion'=>'推广权限','system'=>'系统权限','danger'=>'高危权限'][$bucket]) ?></option><?php endforeach; ?></select></label>
      <label>申请权限<select name="permission_key"><?php foreach ($perms as $p): $meta = permission_view_meta($p); ?><option value="<?= h($p['permission_key']) ?>"><?= h($meta['title'] . ' - ' . $meta['risk_text']) ?></option><?php endforeach; ?></select></label>
      <label>申请数据范围<select name="request_scope_type"><?php foreach (['none'=>'无','self'=>'本人','assigned'=>'已分配','department'=>'部门','team'=>'团队','country'=>'国家','customer_group'=>'客户分组','all'=>'全部'] as $k => $v): ?><option value="<?= h($k) ?>"><?= h($v) ?></option><?php endforeach; ?></select></label>
      <label>范围值<input name="request_scope_value" placeholder="国家 / 分组 / 部门等"></label>
      <label>字段权限<select name="field_key"><option value="">不申请字段权限</option><?php foreach ($perms as $p): if ($p['module'] === 'field'): $meta = permission_view_meta($p); ?><option value="<?= h($p['permission_key']) ?>"><?= h($meta['title']) ?></option><?php endif; endforeach; ?></select></label>
      <label>期望使用天数<select name="expected_days"><option value="1">1 天</option><option value="3" selected>3 天</option><option value="7">7 天</option><option value="15">15 天</option><option value="30">30 天</option></select></label>
      <label>关联对象类型<input name="related_type" placeholder="customer / mail / project"></label>
      <label>关联对象 ID<input name="related_id" placeholder="可选"></label>
      <label>紧急程度<select name="urgency"><option value="normal">普通</option><option value="urgent">加急</option><option value="critical">紧急</option></select></label>
      <label class="full">申请原因<textarea name="reason" required></textarea></label>
      <div class="form-actions"><button type="submit">提交权限申请</button></div>
    </form>
  </section>
<?php elseif ($tab === 'approval' && can_open_approval_center()): ?>
  <section class="acl-panel">
    <div class="acl-panel-head">
      <div><h3>审批中心</h3><p>支持同意、拒绝、限时授权和高危风险备注。</p></div>
      <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="mode" value="expire_check"><button>检查过期权限</button></form>
    </div>
    <div class="acl-filter-row">
      <a href="permissions.php?tab=approval&filter=pending">待审批</a>
      <a href="permissions.php?tab=approval&filter=department">本部门</a>
      <a href="permissions.php?tab=approval&filter=dangerous">高危</a>
      <a href="permissions.php?tab=approval&filter=urgent">加急</a>
      <a href="permissions.php?tab=approval&filter=all">全部</a>
    </div>
    <div class="acl-request-grid">
      <?php foreach ($requestsForApproval as $r): $meta = permission_view_meta($permByKey[$r['permission_key']] ?? ['permission_key'=>$r['permission_key'],'module'=>$r['module'],'action'=>$r['action'],'description'=>'','risk_level'=>'low']); ?>
      <article class="acl-request-card risk-<?= h($meta['risk']) ?>">
        <div class="acl-request-top"><strong><?= h($r['requester_name']) ?></strong><span class="risk-chip <?= h($meta['risk']) ?>"><?= h($meta['risk_text']) ?></span></div>
        <h4><?= h($meta['title']) ?></h4>
        <p><?= h($r['reason']) ?></p>
        <div class="acl-mini-meta"><span><?= h(($r['department_name'] ?: '-') . ' / ' . ($r['role_name'] ?: '-')) ?></span><span><?= h($r['request_scope_type'] . ' ' . $r['request_scope_value']) ?></span><span><?= h($r['expected_days']) ?> 天 / <?= h($r['urgency']) ?></span></div>
        <?php if ($r['status'] === 'pending'): ?>
        <form method="post" class="acl-approve-form">
          <?= csrf_field() ?><input type="hidden" name="mode" value="approve_request"><input type="hidden" name="request_id" value="<?= h($r['id']) ?>">
          <label>授权权限<select name="permission_key"><option value="<?= h($r['permission_key']) ?>"><?= h($meta['title']) ?></option><?php foreach ($perms as $p): $m = permission_view_meta($p); ?><option value="<?= h($p['permission_key']) ?>"><?= h($m['title']) ?></option><?php endforeach; ?></select></label>
          <label>授权时长<select name="grant_days"><option value="1">1 天</option><option value="3">3 天</option><option value="7">7 天</option><option value="30">30 天</option></select></label>
          <label>自定义截止<input name="expires_at" placeholder="YYYY-MM-DD HH:MM:SS"></label>
          <label>审批备注<input name="approval_note" <?= $meta['risk'] === 'danger' ? 'required' : '' ?> placeholder="<?= $meta['risk'] === 'danger' ? '高危权限必填' : '可选' ?>"></label>
          <input type="hidden" name="scope_type" value="<?= h($r['request_scope_type']) ?>"><input type="hidden" name="scope_value" value="<?= h($r['request_scope_value']) ?>"><input type="hidden" name="field_key" value="<?= h($r['field_key']) ?>">
          <button>同意</button>
        </form>
        <form method="post" class="acl-reject-form"><?= csrf_field() ?><input type="hidden" name="mode" value="reject_request"><input type="hidden" name="request_id" value="<?= h($r['id']) ?>"><input name="reject_reason" placeholder="拒绝原因/补充说明"><button class="danger">拒绝</button></form>
        <?php else: ?><span class="status-pill <?= h(status_class($r['status'])) ?>"><?= h($r['status']) ?></span><?php endif; ?>
        <code class="advanced-key"><?= h($r['permission_key']) ?></code>
      </article>
      <?php endforeach; ?>
    </div>
  </section>
  <section class="acl-panel">
    <div class="acl-panel-head"><h3>临时授权</h3><p>查看当前有效、过期或可撤销的临时授权。</p></div>
    <div class="temp-grant-grid">
      <?php foreach ($grants as $g): $meta = permission_view_meta($permByKey[$g['permission_key']] ?? ['permission_key'=>$g['permission_key'],'module'=>$g['module'],'action'=>$g['action'],'description'=>'','risk_level'=>'low']); ?>
      <article class="temp-grant-card"><span class="status-pill temp">临时权限</span><h4><?= h($meta['title']) ?></h4><p><?= h($g['username'] . ' ' . $g['real_name']) ?></p><div><span>生效</span><?= h($g['starts_at']) ?></div><div><span>到期</span><?= h($g['expires_at'] ?: '永久') ?></div><div><span>剩余</span><?= h(time_left_text($g['expires_at'])) ?></div><?php if ($g['status'] === 'active'): ?><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="mode" value="revoke_grant"><input type="hidden" name="grant_id" value="<?= h($g['id']) ?>"><input name="revoke_reason" placeholder="撤销原因"><button class="danger">撤销</button></form><?php endif; ?><code class="advanced-key"><?= h($g['permission_key']) ?></code></article>
      <?php endforeach; ?>
    </div>
  </section>
<?php elseif ($tab === 'matrix' && has_permission('dangerous.permission_admin')): ?>
  <section class="acl-panel">
    <div class="acl-panel-head"><h3>角色模板</h3><p>保存角色模板会覆盖该角色的权限集合；底层仍使用原有角色权限逻辑。</p></div>
    <div class="role-template-grid">
    <?php foreach ($roles as $role): ?>
      <form method="post" class="role-template-card">
        <?= csrf_field() ?><input type="hidden" name="mode" value="role_permissions"><input type="hidden" name="role_id" value="<?= h($role['id']) ?>">
        <h4><?= h($role['role_name']) ?></h4><span class="advanced-key"><?= h($role['role_key']) ?></span>
        <div class="role-perm-list">
          <?php foreach ($perms as $p): $meta = permission_view_meta($p); ?>
          <label class="acl-check risk-<?= h($meta['risk']) ?>" title="<?= h($meta['desc']) ?>"><input type="checkbox" name="permissions[]" value="<?= h($p['permission_key']) ?>" <?= isset($rolePerms[$role['id']][$p['permission_key']]) ? 'checked' : '' ?>><span><?= h(permission_module_name((string)$p['module']) . ' - ' . $meta['title']) ?></span><code class="advanced-key"><?= h($p['permission_key']) ?></code></label>
          <?php endforeach; ?>
        </div>
        <button>保存模板</button>
      </form>
    <?php endforeach; ?>
    </div>
  </section>
<?php elseif ($tab === 'systems'): ?>
  <section class="acl-workbench crm-acl-workbench">
    <aside class="employee-panel">
      <div class="employee-search"><input data-employee-search placeholder="搜索姓名 / 账号 / 部门"></div>
      <div class="employee-list">
        <?php foreach ($people as $person): ?>
        <a class="employee-card <?= (int)$person['id'] === (int)$selectedUser['id'] ? 'active' : '' ?>" href="permissions.php?tab=systems&system=<?= h($selectedSystemKey) ?>&user_id=<?= h($person['id']) ?>" data-employee-card data-search="<?= h(strtolower(($person['real_name'] ?? '') . ' ' . $person['username'] . ' ' . ($person['department_name'] ?? '') . ' ' . ($person['position'] ?? ''))) ?>">
          <strong><?= h($person['real_name'] ?: $person['username']) ?></strong>
          <span><?= h($person['username']) ?></span>
          <div><em><?= h($person['department_name'] ?: '未分配部门') ?></em><em><?= h($person['position'] ?: '未填职位') ?></em><em><?= h($person['role_name'] ?: '未分配角色') ?></em></div>
          <b class="status-pill <?= h(status_class($person['status'])) ?>"><?= h(status_label($person['status'])) ?></b>
        </a>
        <?php endforeach; ?>
      </div>
    </aside>
    <main class="acl-detail">
      <section class="crm-acl-summary">
        <div>
          <span><?= h(permission_system_label($selectedSystemKey)) ?> Access</span>
          <h3><?= h($selectedUser['real_name'] ?: $selectedUser['username']) ?></h3>
          <p><?= h(($selectedUser['role_name'] ?: '未分配角色') . ' / ' . ($selectedUser['department_name'] ?: '未分配部门')) ?></p>
        </div>
        <dl>
          <div><dt>当前系统权限</dt><dd><?= h(count($systemPermissionKeys)) ?></dd></div>
          <div><dt>角色继承</dt><dd><?= h(count(array_intersect(array_keys($selectedRolePerms), $systemPermissionKeys))) ?></dd></div>
          <div><dt>单独覆盖</dt><dd><?= h(count(array_intersect(array_keys($selectedOverrides), $systemPermissionKeys))) ?></dd></div>
          <div><dt>临时授权</dt><dd><?= h(count(array_filter($selectedGrants, fn($g) => in_array($g['permission_key'], $systemPermissionKeys, true)))) ?></dd></div>
        </dl>
      </section>

      <?php if ($canAssignPermission): ?>
      <section class="acl-preset-panel">
        <div class="acl-preset-head">
          <div>
            <span>Permission Presets</span>
            <h3><?= h(permission_system_label($selectedSystemKey)) ?>权限预设模板</h3>
            <p>模板只作用于当前系统。应用后会刷新当前员工的单独授权/禁止，其他系统不受影响。</p>
          </div>
        </div>
        <div class="acl-preset-grid">
          <?php foreach (permission_preset_definitions() as $presetKey => $preset): $presetKeys = permission_preset_keys($selectedSystemKey, $presetKey, $perms); ?>
          <form method="post" class="acl-preset-card">
            <?= csrf_field() ?>
            <input type="hidden" name="mode" value="apply_permission_preset">
            <input type="hidden" name="system_key" value="<?= h($selectedSystemKey) ?>">
            <input type="hidden" name="user_id" value="<?= h($selectedUser['id']) ?>">
            <input type="hidden" name="preset_key" value="<?= h($presetKey) ?>">
            <strong><?= h($preset['label']) ?></strong>
            <span><?= h(count($presetKeys)) ?> / <?= h(count($systemPermissionKeys)) ?> 项</span>
            <p><?= h($preset['desc']) ?></p>
            <button type="submit">应用<?= h($preset['label']) ?>模板</button>
          </form>
          <?php endforeach; ?>
        </div>
      </section>
      <?php endif; ?>

      <section class="permission-board crm-permission-board">
        <div class="perm-board-head"><h3><?= h(permission_system_label($selectedSystemKey)) ?>人员权限</h3><span>不同系统权限独立管理；当前只显示「<?= h(permission_system_label($selectedSystemKey)) ?>」权限，不混入其他系统。</span></div>
        <div class="perm-tabbar system-switch-tabs">
          <?php foreach ($permissionSystems as $systemKey => $systemDef): $count = count(permission_system_keys($systemKey)); ?>
          <a class="<?= $systemKey === $selectedSystemKey ? 'active' : '' ?>" href="permissions.php?tab=systems&system=<?= h($systemKey) ?>&user_id=<?= h($selectedUser['id']) ?>"><?= h($systemDef['label']) ?><em><?= h($count) ?></em></a>
          <?php endforeach; ?>
        </div>
        <div class="perm-tabbar" data-acl-tabs>
          <?php $firstSystemDomain = array_key_first($systemDomains) ?: 'workspace'; foreach ($systemDomains as $domain => $items): ?><button type="button" class="<?= $domain === $firstSystemDomain ? 'active' : '' ?>" data-acl-tab="system-<?= h($domain) ?>"><?= h(permission_domain_label($domain)) ?><em><?= h(count($items)) ?></em></button><?php endforeach; ?>
          <button type="button" data-acl-tab="system-temporary">临时授权<em><?= h(count(array_filter($selectedGrants, fn($g) => in_array($g['permission_key'], $systemPermissionKeys, true)))) ?></em></button>
        </div>
        <?php foreach ($systemDomains as $domain => $items): ?>
        <div class="perm-tab-panel <?= $domain === $firstSystemDomain ? 'active' : '' ?>" data-acl-panel="system-<?= h($domain) ?>">
          <div class="perm-card-grid">
            <?php foreach ($items as $p): $meta = permission_view_meta($p); $roleOn = isset($selectedRolePerms[$p['permission_key']]); $override = $selectedOverrides[$p['permission_key']] ?? ''; $effective = permission_effective_for_board($p['permission_key'], $selectedOverrides, $selectedRolePerms) || (is_super_admin() && (int)$selectedUser['id'] === (int)$user['id']); ?>
            <?php if ($canAssignPermission): ?>
            <form method="post" class="acl-check user-permission-check risk-<?= h($meta['risk']) ?>" title="<?= h($meta['desc']) ?>">
              <?= csrf_field() ?><input type="hidden" name="mode" value="user_permission"><input type="hidden" name="return_tab" value="systems"><input type="hidden" name="system_key" value="<?= h($selectedSystemKey) ?>"><input type="hidden" name="user_id" value="<?= h($selectedUser['id']) ?>"><input type="hidden" name="permission_key" value="<?= h($meta['key']) ?>"><input type="hidden" name="effect" value="<?= $effective ? 'deny' : 'allow' ?>">
              <input type="checkbox" <?= $effective ? 'checked' : '' ?> data-perm-switch><span><?= h(permission_module_name((string)$p['module']) . ' - ' . $meta['title']) ?></span><code class="advanced-key"><?= h($meta['key']) ?></code>
            </form>
            <?php else: ?>
            <label class="acl-check user-permission-check readonly risk-<?= h($meta['risk']) ?>" title="<?= h($meta['desc']) ?>"><input type="checkbox" disabled <?= $effective ? 'checked' : '' ?>><span><?= h(permission_module_name((string)$p['module']) . ' - ' . $meta['title']) ?></span><code class="advanced-key"><?= h($meta['key']) ?></code></label>
            <?php endif; ?>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endforeach; ?>
        <div class="perm-tab-panel" data-acl-panel="system-temporary">
          <div class="temp-grant-grid">
            <?php foreach ($selectedGrants as $g): if (!in_array($g['permission_key'], $systemPermissionKeys, true)) continue; $meta = permission_view_meta($permByKey[$g['permission_key']] ?? ['permission_key'=>$g['permission_key'],'module'=>$g['module'],'action'=>$g['action'],'description'=>'','risk_level'=>'low']); ?><article class="temp-grant-card"><span class="status-pill temp">临时授权</span><h4><?= h($meta['title']) ?></h4><p>来源：审批授权</p><div><span>生效</span><?= h($g['starts_at']) ?></div><div><span>到期</span><?= h($g['expires_at'] ?: '永久') ?></div><div><span>剩余</span><?= h(time_left_text($g['expires_at'])) ?></div><code class="advanced-key"><?= h($g['permission_key']) ?></code></article><?php endforeach; ?>
          </div>
        </div>
      </section>

      <?php if (has_permission('dangerous.permission_admin')): ?>
      <section class="acl-panel crm-role-panel">
        <div class="acl-panel-head"><h3><?= h(permission_system_label($selectedSystemKey)) ?>角色模板</h3><p>这里只保存当前系统权限，不覆盖其他系统权限。</p></div>
        <div class="role-template-grid">
        <?php foreach ($roles as $role): ?>
          <form method="post" class="role-template-card crm-role-card">
            <?= csrf_field() ?><input type="hidden" name="mode" value="system_role_permissions"><input type="hidden" name="system_key" value="<?= h($selectedSystemKey) ?>"><input type="hidden" name="role_id" value="<?= h($role['id']) ?>">
            <h4><?= h($role['role_name']) ?></h4><span class="advanced-key"><?= h($role['role_key']) ?></span>
            <?php foreach ($systemDomains as $domain => $items): ?>
            <details class="crm-domain-checks" open>
              <summary><?= h(permission_domain_label($domain)) ?><span><?= h(count($items)) ?></span></summary>
              <div class="role-perm-list">
                <?php foreach ($items as $p): $meta = permission_view_meta($p); ?>
                <label class="acl-check risk-<?= h($meta['risk']) ?>" title="<?= h($meta['desc']) ?>"><input type="checkbox" name="permissions[]" value="<?= h($p['permission_key']) ?>" <?= isset($rolePerms[$role['id']][$p['permission_key']]) ? 'checked' : '' ?>><span><?= h(permission_module_name((string)$p['module']) . ' - ' . $meta['title']) ?></span><code class="advanced-key"><?= h($p['permission_key']) ?></code></label>
                <?php endforeach; ?>
              </div>
            </details>
            <?php endforeach; ?>
            <button>保存<?= h(permission_system_label($selectedSystemKey)) ?>模板</button>
          </form>
        <?php endforeach; ?>
        </div>
      </section>
      <?php endif; ?>
    </main>
  </section>
<?php elseif ($tab === 'my'): ?>
  <section class="acl-panel">
    <div class="acl-panel-head"><h3>我的权限</h3><p>查看当前角色权限、单独授权、临时授权和申请记录。</p></div>
    <div class="role-perm-list my-perm-list">
      <?php foreach ($myRolePerms as $key): if (!isset($permByKey[$key])) continue; $meta = permission_view_meta($permByKey[$key]); $p = $permByKey[$key]; ?><label class="acl-check readonly risk-<?= h($meta['risk']) ?>" title="<?= h($meta['desc']) ?>"><input type="checkbox" checked disabled><span><?= h(permission_module_name((string)$p['module']) . ' - ' . $meta['title']) ?></span><code class="advanced-key"><?= h($key) ?></code></label><?php endforeach; ?>
      <?php foreach ($myUserPerms as $p): if (!isset($permByKey[$p['permission_key']])) continue; $meta = permission_view_meta($permByKey[$p['permission_key']]); $permRow = $permByKey[$p['permission_key']]; ?><label class="acl-check readonly risk-<?= h($meta['risk']) ?>" title="<?= h($meta['desc']) ?>"><input type="checkbox" <?= $p['effect'] === 'deny' ? '' : 'checked' ?> disabled><span><?= h(permission_module_name((string)$permRow['module']) . ' - ' . $meta['title']) ?></span><code class="advanced-key"><?= h($p['permission_key']) ?></code></label><?php endforeach; ?>
    </div>
  </section>
  <section class="acl-panel">
    <div class="acl-panel-head"><h3>我的申请</h3><p>跟踪权限申请处理状态。</p></div>
    <div class="acl-request-grid">
      <?php foreach ($myRequests as $r): $meta = permission_view_meta($permByKey[$r['permission_key']] ?? ['permission_key'=>$r['permission_key'],'module'=>$r['module'],'action'=>$r['action'],'description'=>'','risk_level'=>'low']); ?><article class="acl-request-card risk-<?= h($meta['risk']) ?>"><div class="acl-request-top"><strong><?= h($meta['title']) ?></strong><span class="status-pill <?= h(status_class($r['status'])) ?>"><?= h($r['status']) ?></span></div><p><?= h($r['reason']) ?></p><small><?= h($r['created_at']) ?></small><?php if ($r['status'] === 'pending'): ?><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="mode" value="cancel_request"><input type="hidden" name="request_id" value="<?= h($r['id']) ?>"><button class="danger">取消申请</button></form><?php endif; ?><code class="advanced-key"><?= h($r['permission_key']) ?></code></article><?php endforeach; ?>
    </div>
  </section>
<?php else: ?>
  <section class="acl-workbench">
    <aside class="employee-panel">
      <div class="employee-search"><input data-employee-search placeholder="搜索姓名 / 账号 / 部门"></div>
      <div class="employee-list">
        <?php foreach ($people as $person): ?>
        <a class="employee-card <?= (int)$person['id'] === (int)$selectedUser['id'] ? 'active' : '' ?>" href="permissions.php?tab=people&user_id=<?= h($person['id']) ?>" data-employee-card data-search="<?= h(strtolower(($person['real_name'] ?? '') . ' ' . $person['username'] . ' ' . ($person['department_name'] ?? '') . ' ' . ($person['position'] ?? ''))) ?>">
          <strong><?= h($person['real_name'] ?: $person['username']) ?></strong>
          <span><?= h($person['username']) ?></span>
          <div><em><?= h($person['department_name'] ?: '未分配部门') ?></em><em><?= h($person['position'] ?: '未填职位') ?></em><em><?= h($person['role_name'] ?: '未分配角色') ?></em></div>
          <b class="status-pill <?= h(status_class($person['status'])) ?>"><?= h(status_label($person['status'])) ?></b>
          <small>最近登录：<?= h($person['last_login_at'] ?: '暂无') ?></small>
        </a>
        <?php endforeach; ?>
      </div>
    </aside>
    <main class="acl-detail">
      <?php if (has_permission('users.create')): ?>
      <section class="user-create-panel acl-create-user">
        <div>
          <span>User Create</span>
          <h2>后台新增账号</h2>
          <p>仅管理员可创建账号；新账号直接启用，首次登录必须修改初始密码。</p>
        </div>
        <button type="button" class="acl-dialog-button" data-open-account-dialog="create">新增账号</button>
      </section>
      <?php endif; ?>
      <section class="user-detail-card user-edit-card">
        <div class="user-edit-head">
          <div>
            <span>当前员工</span>
            <h3><?= h($selectedUser['real_name'] ?: $selectedUser['username']) ?></h3>
            <p><?= h($selectedUser['username']) ?> / <?= h($selectedUser['email'] ?: '未绑定邮箱') ?> / 最近登录：<?= h($selectedUser['last_login_at'] ?: '暂无') ?></p>
          </div>
          <div class="user-edit-actions">
            <span class="status-pill <?= h(status_class($selectedUser['status'])) ?>"><?= h(status_label($selectedUser['status'])) ?></span>
            <?php if (is_super_admin() || has_permission('users.create') || has_permission('users.assign_role') || has_permission('users.assign_department')): ?>
            <button type="button" class="acl-dialog-button secondary" data-open-account-dialog="edit">修改账号</button>
            <?php endif; ?>
          </div>
        </div>
        <?php if (is_super_admin() || has_permission('users.create') || has_permission('users.assign_role') || has_permission('users.assign_department')): ?>
        <dl><div><dt>部门</dt><dd><?= h($selectedUser['department_name'] ?: '未分配') ?></dd></div><div><dt>职位</dt><dd><?= h($selectedUser['position'] ?: '未填写') ?></dd></div><div><dt>角色</dt><dd><?= h($selectedUser['role_name'] ?: '未分配') ?></dd></div><div><dt>状态</dt><dd><span class="status-pill <?= h(status_class($selectedUser['status'])) ?>"><?= h(status_label($selectedUser['status'])) ?></span></dd></div><div><dt>最近登录</dt><dd><?= h($selectedUser['last_login_at'] ?: '暂无') ?></dd></div></dl>
        <?php else: ?>
        <dl><div><dt>部门</dt><dd><?= h($selectedUser['department_name'] ?: '未分配') ?></dd></div><div><dt>职位</dt><dd><?= h($selectedUser['position'] ?: '未填写') ?></dd></div><div><dt>角色</dt><dd><?= h($selectedUser['role_name'] ?: '未分配') ?></dd></div><div><dt>状态</dt><dd><span class="status-pill <?= h(status_class($selectedUser['status'])) ?>"><?= h(status_label($selectedUser['status'])) ?></span></dd></div><div><dt>最近登录</dt><dd><?= h($selectedUser['last_login_at'] ?: '暂无') ?></dd></div></dl>
        <?php endif; ?>
      </section>
      <section class="permission-board">
        <div class="perm-board-head"><h3>权限配置</h3><span>按业务域分组，默认隐藏技术 key。</span></div>
        <div class="perm-tabbar" data-acl-tabs>
          <?php foreach (['base'=>'基础权限','customer'=>'客户权限','mail'=>'邮件权限','promotion'=>'推广权限','system'=>'系统权限','danger'=>'高危权限','temporary'=>'临时授权'] as $bucket => $label): ?><button type="button" class="<?= $bucket === 'base' ? 'active' : '' ?>" data-acl-tab="<?= h($bucket) ?>"><?= h($label) ?></button><?php endforeach; ?>
        </div>
        <?php foreach ($permGroups as $bucket => $items): ?>
        <div class="perm-tab-panel <?= $bucket === 'base' ? 'active' : '' ?>" data-acl-panel="<?= h($bucket) ?>">
          <div class="perm-card-grid">
            <?php foreach ($items as $p): $meta = permission_view_meta($p); $roleOn = isset($selectedRolePerms[$p['permission_key']]); $override = $selectedOverrides[$p['permission_key']] ?? ''; $effective = $override === 'deny' ? false : ($override === 'allow' || $roleOn || is_super_admin() && (int)$selectedUser['id'] === (int)$user['id']); ?>
            <?php if ($canAssignPermission): ?>
            <form method="post" class="acl-check user-permission-check risk-<?= h($meta['risk']) ?>" title="<?= h($meta['desc']) ?>">
              <?= csrf_field() ?><input type="hidden" name="mode" value="user_permission"><input type="hidden" name="user_id" value="<?= h($selectedUser['id']) ?>"><input type="hidden" name="permission_key" value="<?= h($meta['key']) ?>"><input type="hidden" name="effect" value="<?= $effective ? 'deny' : 'allow' ?>">
              <input type="checkbox" <?= $effective ? 'checked' : '' ?> data-perm-switch><span><?= h(permission_module_name((string)$p['module']) . ' - ' . $meta['title']) ?></span><code class="advanced-key"><?= h($meta['key']) ?></code>
            </form>
            <?php else: ?>
            <label class="acl-check user-permission-check readonly risk-<?= h($meta['risk']) ?>" title="<?= h($meta['desc']) ?>"><input type="checkbox" disabled <?= $effective ? 'checked' : '' ?>><span><?= h(permission_module_name((string)$p['module']) . ' - ' . $meta['title']) ?></span><code class="advanced-key"><?= h($meta['key']) ?></code></label>
            <?php endif; ?>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endforeach; ?>
        <div class="perm-tab-panel" data-acl-panel="temporary">
          <div class="temp-grant-grid">
            <?php foreach ($selectedGrants as $g): $meta = permission_view_meta($permByKey[$g['permission_key']] ?? ['permission_key'=>$g['permission_key'],'module'=>$g['module'],'action'=>$g['action'],'description'=>'','risk_level'=>'low']); ?><article class="temp-grant-card"><span class="status-pill temp">临时授权</span><h4><?= h($meta['title']) ?></h4><p>来源：审批授权</p><div><span>生效</span><?= h($g['starts_at']) ?></div><div><span>到期</span><?= h($g['expires_at'] ?: '永久') ?></div><div><span>剩余</span><?= h(time_left_text($g['expires_at'])) ?></div><code class="advanced-key"><?= h($g['permission_key']) ?></code></article><?php endforeach; ?>
          </div>
        </div>
      </section>
    </main>
  </section>
<?php endif; ?>
</section>
<?php if (has_permission('users.create')): ?>
<dialog class="acl-account-dialog" data-account-dialog="create">
  <form method="post" class="acl-account-dialog-panel user-create-form">
    <?= csrf_field() ?>
    <input type="hidden" name="mode" value="create_user">
    <header>
      <div><span>User Create</span><strong>新增账号</strong><p>新账号直接启用，首次登录必须修改初始密码。</p></div>
      <button type="button" data-close-account-dialog aria-label="关闭">×</button>
    </header>
    <main>
      <section class="acl-account-hero"><div><span>账号名称</span><input name="username" required autocomplete="off" placeholder="例如 QL"></div><b>User</b></section>
      <section class="acl-account-section"><h3>基础资料</h3><div class="acl-account-grid">
        <label>姓名<input name="real_name" required placeholder="中文姓名"></label>
        <label>英文名<input name="english_name" placeholder="English name"></label>
        <label>邮箱（可选）<input type="email" name="email" autocomplete="off" placeholder="没有邮箱可留空"></label>
        <label>手机<input name="phone" placeholder="手机号"></label>
      </div></section>
      <section class="acl-account-section"><h3>组织与角色</h3><div class="acl-account-grid">
        <label>部门<input name="department_name" list="department-options" required data-department-input placeholder="可选择，也可直接输入新部门"></label>
        <label>职位<input name="position" placeholder="例如 业务主管 / 工程师 / 采购"></label>
        <label>角色
          <select name="role_id" data-role-select>
            <option value="">按部门自动套用</option>
            <?php foreach ($roles as $r): ?><option value="<?= h($r['id']) ?>"><?= h($r['role_name']) ?></option><?php endforeach; ?>
          </select>
        </label>
        <label>初始密码<input type="password" name="password" required autocomplete="new-password" placeholder="至少8位，含字母和数字"></label>
      </div></section>
    </main>
    <footer><button type="button" data-close-account-dialog>取消</button><button type="submit">创建账号</button></footer>
  </form>
</dialog>
<?php endif; ?>
<?php if (is_super_admin() || has_permission('users.create') || has_permission('users.assign_role') || has_permission('users.assign_department')): ?>
<dialog class="acl-account-dialog" data-account-dialog="edit">
  <form method="post" class="acl-account-dialog-panel user-account-form">
    <?= csrf_field() ?>
    <input type="hidden" name="mode" value="update_user_account">
    <input type="hidden" name="user_id" value="<?= h($selectedUser['id']) ?>">
    <header>
      <div><span>User Edit</span><strong>修改账号</strong><p><?= h($selectedUser['real_name'] ?: $selectedUser['username']) ?> / <?= h($selectedUser['username']) ?></p></div>
      <button type="button" data-close-account-dialog aria-label="关闭">×</button>
    </header>
    <main>
      <section class="acl-account-hero"><div><span>账号名称</span><input name="username" required autocomplete="off" value="<?= h($selectedUser['username']) ?>"></div><b>Edit</b></section>
      <section class="acl-account-section"><h3>基础资料</h3><div class="acl-account-grid">
        <label>中文姓名<input name="real_name" required value="<?= h($selectedUser['real_name'] ?? '') ?>"></label>
        <label>英文名<input name="english_name" value="<?= h($selectedUser['english_name'] ?? '') ?>"></label>
        <label>邮箱<input type="email" name="email" autocomplete="off" value="<?= h($selectedUser['email'] ?? '') ?>"></label>
        <label>手机<input name="phone" value="<?= h($selectedUser['phone'] ?? '') ?>"></label>
      </div></section>
      <section class="acl-account-section"><h3>组织、角色与状态</h3><div class="acl-account-grid">
        <label>部门<input name="department_name" list="department-options" required data-account-department data-department-input value="<?= h($selectedUser['department_name'] ?? '') ?>" placeholder="可选择，也可直接输入新部门"></label>
        <label>职位<input name="position" value="<?= h($selectedUser['position'] ?? '') ?>" placeholder="例如 业务主管 / 工程师"></label>
        <label>角色
          <select name="role_id" data-account-role required>
            <?php foreach ($roles as $r): ?><option value="<?= h($r['id']) ?>" <?= (int)($selectedUser['role_id'] ?? 0) === (int)$r['id'] ? 'selected' : '' ?>><?= h($r['role_name']) ?></option><?php endforeach; ?>
          </select>
        </label>
        <label>状态
          <select name="status" required>
            <?php foreach (['active' => '启用', 'locked' => '锁定', 'disabled' => '停用', 'pending' => '待审核', 'rejected' => '已拒绝'] as $statusKey => $statusText): ?>
            <option value="<?= h($statusKey) ?>" <?= (string)($selectedUser['status'] ?? '') === $statusKey ? 'selected' : '' ?>><?= h($statusText) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div></section>
    </main>
    <footer>
      <?php if ((is_super_admin() || has_permission('dangerous.permission_admin')) && (int)$selectedUser['id'] !== (int)$user['id']): ?><button type="submit" class="danger" form="delete-account-form">删除账号</button><?php endif; ?>
      <button type="button" data-close-account-dialog>取消</button><button type="submit">保存账号</button>
    </footer>
  </form>
</dialog>
<?php if ((is_super_admin() || has_permission('dangerous.permission_admin')) && (int)$selectedUser['id'] !== (int)$user['id']): ?>
<form method="post" id="delete-account-form" hidden>
  <?= csrf_field() ?>
  <input type="hidden" name="mode" value="delete_user_account">
  <input type="hidden" name="user_id" value="<?= h($selectedUser['id']) ?>">
</form>
<?php endif; ?>
<?php endif; ?>
<datalist id="department-options">
  <?php foreach ($departments as $d): $defaultRoleKey = artdon_role_key_for_department_code((string)$d['code']); ?>
  <option value="<?= h($d['name']) ?>" data-id="<?= h($d['id']) ?>" data-default-role="<?= h($roleKeyToId[$defaultRoleKey] ?? '') ?>"></option>
  <?php endforeach; ?>
</datalist>
<?php page_footer(); ?>
