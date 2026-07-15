<?php
require_once __DIR__ . '/includes/bootstrap.php';
if (!config_installed()) api_response(false, '系统未安装', [], 'NOT_INSTALLED');

$action = $_GET['action'] ?? $_POST['action'] ?? 'ping';

function input_json()
{
    $raw = file_get_contents('php://input');
    $data = $raw ? json_decode($raw, true) : [];
    return is_array($data) ? array_merge($_POST, $data) : $_POST;
}

function require_write_api($permission)
{
    require_login();
    require_permission($permission);
    require_csrf();
}

try {
    if ($action === 'ping') {
        api_response(true, 'pong', ['time' => date('Y-m-d H:i:s')]);
    }
    if ($action === 'current_user') {
        require_login();
        $user = current_user();
        unset($user['password_hash']);
        api_response(true, '', $user);
    }
    if ($action === 'login') {
        require_csrf();
        $result = login_user(trim($_POST['username'] ?? ''), $_POST['password'] ?? '');
        api_response($result['success'], $result['message'], [], $result['success'] ? '' : 'LOGIN_FAILED');
    }
    if ($action === 'logout') {
        require_login();
        require_csrf();
        logout_user();
        api_response(true, '已退出');
    }
    if ($action === 'register') {
        require_csrf();
        $data = input_json();
        if (($data['password'] ?? '') !== ($data['confirm_password'] ?? '')) api_response(false, '两次密码不一致', [], 'VALIDATION_ERROR');
        if (!password_is_strong($data['password'] ?? '')) api_response(false, password_strength_message(), [], 'VALIDATION_ERROR');
        $roleId = db()->query("SELECT id FROM crm_roles WHERE role_key = 'pending' LIMIT 1")->fetchColumn();
        $stmt = db()->prepare("INSERT INTO crm_users (username, password_hash, real_name, english_name, email, phone, role_id, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())");
        $stmt->execute([trim($data['username'] ?? ''), password_hash($data['password'], PASSWORD_DEFAULT), trim($data['real_name'] ?? ''), trim($data['english_name'] ?? ''), trim($data['email'] ?? ''), trim($data['phone'] ?? ''), $roleId ?: null]);
        $id = db()->lastInsertId();
        audit_log('register_request', 'auth', 'user', $id, null, ['username' => $data['username'] ?? '']);
        api_response(true, '注册申请已提交，请等待管理员审核', ['user_id' => $id]);
    }
    if ($action === 'change_password') {
        require_write_api('dashboard.view');
        $user = current_user();
        $data = input_json();
        if (!password_verify($data['old_password'] ?? '', $user['password_hash'])) api_response(false, '旧密码不正确', [], 'VALIDATION_ERROR');
        if (($data['new_password'] ?? '') !== ($data['confirm_password'] ?? '')) api_response(false, '两次新密码不一致', [], 'VALIDATION_ERROR');
        if (!password_is_strong($data['new_password'] ?? '')) api_response(false, password_strength_message(), [], 'VALIDATION_ERROR');
        db()->prepare('UPDATE crm_users SET password_hash = ?, force_password_change = 0, updated_at = NOW() WHERE id = ?')->execute([password_hash($data['new_password'], PASSWORD_DEFAULT), $user['id']]);
        audit_log('change_password', 'profile', 'user', $user['id']);
        api_response(true, '密码已修改');
    }
    if ($action === 'users_list') {
        require_permission('users.view');
        $stmt = db()->query('SELECT u.id,u.username,u.real_name,u.english_name,u.email,u.phone,u.status,u.is_super_admin,u.created_at,r.role_name,d.name AS department_name FROM crm_users u LEFT JOIN crm_roles r ON r.id=u.role_id LEFT JOIN crm_departments d ON d.id=u.department_id ORDER BY u.id DESC LIMIT 200');
        api_response(true, '', ['users' => $stmt->fetchAll()]);
    }
    if (in_array($action, ['users_approve','users_reject','users_disable','users_enable','users_reset_password'], true)) {
        $perm = [
            'users_approve' => 'users.approve',
            'users_reject' => 'users.reject',
            'users_disable' => 'users.disable',
            'users_enable' => 'users.enable',
            'users_reset_password' => 'users.reset_password',
        ][$action];
        require_write_api($perm);
        $data = input_json();
        $userId = (int)($data['user_id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM crm_users WHERE id = ?');
        $stmt->execute([$userId]);
        $before = $stmt->fetch();
        if (!$before) api_response(false, '用户不存在', [], 'NOT_FOUND');
        if ((int)$before['is_super_admin'] === 1 && !is_super_admin()) permission_denied_response();
        $operator = current_user();
        if ($action === 'users_approve') {
            db()->prepare("UPDATE crm_users SET status='active', department_id=?, role_id=?, approved_at=NOW(), approved_by=?, updated_at=NOW() WHERE id=?")->execute([(int)$data['department_id'], (int)$data['role_id'], $operator['id'], $userId]);
            $logAction = 'approve';
        } elseif ($action === 'users_reject') {
            db()->prepare("UPDATE crm_users SET status='rejected', rejected_at=NOW(), rejected_by=?, reject_reason=?, updated_at=NOW() WHERE id=?")->execute([$operator['id'], $data['reason'] ?? '', $userId]);
            $logAction = 'reject';
        } elseif ($action === 'users_disable') {
            db()->prepare("UPDATE crm_users SET status='disabled', updated_at=NOW() WHERE id=?")->execute([$userId]);
            $logAction = 'disable';
        } elseif ($action === 'users_enable') {
            db()->prepare("UPDATE crm_users SET status='active', updated_at=NOW() WHERE id=?")->execute([$userId]);
            $logAction = 'enable';
        } else {
            $new = $data['new_password'] ?? bin2hex(random_bytes(6));
            if (!password_is_strong($new)) api_response(false, password_strength_message(), [], 'VALIDATION_ERROR');
            db()->prepare('UPDATE crm_users SET password_hash=?, force_password_change=1, updated_at=NOW() WHERE id=?')->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);
            $logAction = 'reset_password';
        }
        $stmt->execute([$userId]);
        $after = $stmt->fetch();
        if (in_array($logAction, ['approve', 'reject', 'disable', 'enable'], true)) {
            db()->prepare('INSERT INTO crm_user_approval_logs (user_id, action, operator_id, operator_name, reason, before_json, after_json, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())')->execute([$userId, $logAction, $operator['id'], $operator['username'], $data['reason'] ?? '', json_encode($before, JSON_UNESCAPED_UNICODE), json_encode($after, JSON_UNESCAPED_UNICODE)]);
        }
        audit_log('user_' . $logAction, 'users', 'user', $userId, $before, $after);
        api_response(true, '操作成功', isset($new) ? ['new_password' => $new] : []);
    }
    if ($action === 'roles_list') {
        require_permission('users.manage_roles');
        api_response(true, '', ['roles' => db()->query('SELECT * FROM crm_roles ORDER BY id')->fetchAll()]);
    }
    if ($action === 'permissions_list') {
        require_permission('dangerous.permission_admin');
        api_response(true, '', ['permissions' => db()->query('SELECT * FROM crm_permissions ORDER BY module, permission_key')->fetchAll()]);
    }
    if ($action === 'permissions_save') {
        require_write_api('dangerous.permission_admin');
        $data = input_json();
        $roleId = (int)($data['role_id'] ?? 0);
        $keys = $data['permissions'] ?? [];
        $role = db()->prepare('SELECT * FROM crm_roles WHERE id=?');
        $role->execute([$roleId]);
        $roleRow = $role->fetch();
        if (!$roleRow) api_response(false, '角色不存在', [], 'NOT_FOUND');
        if ($roleRow['role_key'] === 'super_admin' && !is_super_admin()) permission_denied_response();
        $before = db()->prepare('SELECT permission_key FROM crm_role_permissions WHERE role_id=?');
        $before->execute([$roleId]);
        $beforeKeys = $before->fetchAll(PDO::FETCH_COLUMN);
        db()->prepare('DELETE FROM crm_role_permissions WHERE role_id=?')->execute([$roleId]);
        $insert = db()->prepare('INSERT IGNORE INTO crm_role_permissions (role_id, permission_key) VALUES (?, ?)');
        foreach ($keys as $key) $insert->execute([$roleId, $key]);
        audit_permission_event('save_role_permissions', 'role', $roleId, $beforeKeys, $keys);
        api_response(true, '权限已保存');
    }
    if ($action === 'scopes_save') {
        require_write_api('users.assign_permission');
        $data = input_json();
        db()->prepare('INSERT INTO crm_user_scopes (user_id, module, scope_type, scope_value, created_at) VALUES (?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE scope_type=VALUES(scope_type), scope_value=VALUES(scope_value)')->execute([(int)$data['user_id'], $data['module'], $data['scope_type'], $data['scope_value'] ?? '']);
        audit_permission_event('save_scope', 'user', $data['user_id'], null, $data);
        api_response(true, '数据范围已保存');
    }
    if ($action === 'field_permissions_save') {
        require_write_api('users.assign_permission');
        $data = input_json();
        db()->prepare('INSERT INTO crm_field_permissions (role_id, user_id, field_key, can_view, can_export, created_at) VALUES (?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE can_view=VALUES(can_view), can_export=VALUES(can_export)')->execute([$data['role_id'] ?? null, $data['user_id'] ?? null, $data['field_key'], (int)!empty($data['can_view']), (int)!empty($data['can_export'])]);
        audit_permission_event('save_field_permission', 'field', $data['field_key'], null, $data);
        api_response(true, '字段权限已保存');
    }
    if ($action === 'permission_request_create') {
        require_write_api('permission_request.create');
        $id = create_permission_request(input_json());
        api_response(true, '权限申请已提交', ['request_id' => $id]);
    }
    if ($action === 'permission_request_list_my') {
        require_login();
        require_permission('permission_request.view_own');
        require_csrf();
        $stmt = db()->prepare('SELECT * FROM crm_permission_requests WHERE requester_id = ? ORDER BY id DESC LIMIT 200');
        $stmt->execute([current_user()['id']]);
        api_response(true, '', ['requests' => $stmt->fetchAll()]);
    }
    if ($action === 'permission_request_cancel') {
        require_write_api('permission_request.cancel_own');
        $data = input_json();
        $requestId = (int)($data['request_id'] ?? 0);
        $stmt = db()->prepare("SELECT * FROM crm_permission_requests WHERE id = ? AND requester_id = ? AND status = 'pending'");
        $stmt->execute([$requestId, current_user()['id']]);
        $before = $stmt->fetch();
        if (!$before) api_response(false, '只能取消自己的待审批申请', [], 'VALIDATION_ERROR');
        db()->prepare("UPDATE crm_permission_requests SET status = 'cancelled', updated_at = NOW() WHERE id = ?")->execute([$requestId]);
        permission_request_log($requestId, current_user()['id'], 'cancel', $before, ['status' => 'cancelled'], '用户取消申请');
        audit_log('cancel_permission_request', 'permission', 'request', $requestId, $before, ['status' => 'cancelled']);
        api_response(true, '申请已取消');
    }
    if ($action === 'permission_grants_my') {
        require_login();
        require_permission('permission_request.view_own');
        require_csrf();
        api_response(true, '', ['grants' => get_active_permission_grants((int)current_user()['id'])]);
    }
    if ($action === 'permission_request_list_pending') {
        require_login();
        require_csrf();
        if (!is_super_admin() && !has_permission('permission_request.approve_all') && !has_permission('permission_request.approve_department') && !has_permission('dangerous.permission_admin')) permission_denied_response();
        $user = current_user();
        if (!is_super_admin() && !has_permission('permission_request.approve_all') && has_permission('permission_request.approve_department')) {
            $stmt = db()->prepare("SELECT * FROM crm_permission_requests WHERE status = 'pending' AND department_id = ? ORDER BY id DESC LIMIT 200");
            $stmt->execute([$user['department_id']]);
        } else {
            $stmt = db()->query("SELECT * FROM crm_permission_requests WHERE status = 'pending' ORDER BY id DESC LIMIT 200");
        }
        api_response(true, '', ['requests' => $stmt->fetchAll()]);
    }
    if ($action === 'permission_request_detail') {
        require_login();
        require_csrf();
        $requestId = (int)($_GET['request_id'] ?? $_POST['request_id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM crm_permission_requests WHERE id = ? LIMIT 1');
        $stmt->execute([$requestId]);
        $request = $stmt->fetch();
        if (!$request) api_response(false, '申请不存在', [], 'NOT_FOUND');
        $user = current_user();
        if ((int)$request['requester_id'] !== (int)$user['id'] && !can_open_approval_api($request)) permission_denied_response();
        $logs = db()->prepare('SELECT * FROM crm_permission_request_logs WHERE request_id = ? ORDER BY id DESC');
        $logs->execute([$requestId]);
        $grants = db()->prepare('SELECT * FROM crm_permission_grants WHERE request_id = ? ORDER BY id DESC');
        $grants->execute([$requestId]);
        api_response(true, '', ['request' => $request, 'logs' => $logs->fetchAll(), 'grants' => $grants->fetchAll()]);
    }
    if ($action === 'permission_request_approve') {
        require_login();
        require_csrf();
        $data = input_json();
        $grantId = approve_permission_request((int)($data['request_id'] ?? 0), $data);
        api_response(true, '权限申请已批准', ['grant_id' => $grantId]);
    }
    if ($action === 'permission_request_reject') {
        require_login();
        require_csrf();
        $data = input_json();
        reject_permission_request((int)($data['request_id'] ?? 0), trim($data['reject_reason'] ?? ''));
        api_response(true, '权限申请已拒绝');
    }
    if ($action === 'permission_grant_revoke') {
        require_login();
        require_csrf();
        $data = input_json();
        revoke_permission_grant((int)($data['grant_id'] ?? 0), trim($data['revoke_reason'] ?? ''));
        api_response(true, '授权已撤销');
    }
    if ($action === 'permission_grant_extend') {
        require_login();
        require_csrf();
        $data = input_json();
        extend_permission_grant((int)($data['grant_id'] ?? 0), $data['expires_at'] ?? '', $data['note'] ?? '');
        api_response(true, '授权已延期');
    }
    if ($action === 'permission_grants_list') {
        require_login();
        require_csrf();
        if (!is_super_admin() && !has_permission('permission_request.approve_all') && !has_permission('permission_request.revoke') && !has_permission('dangerous.permission_admin')) permission_denied_response();
        api_response(true, '', ['grants' => db()->query('SELECT * FROM crm_permission_grants ORDER BY id DESC LIMIT 300')->fetchAll()]);
    }
    if ($action === 'permission_grants_expire_check' || $action === 'permission_grants_cleanup_status') {
        require_write_api('permission_request.expire_check');
        $count = expire_old_permission_grants();
        api_response(true, '过期权限检查完成', ['expired_count' => $count]);
    }
    if ($action === 'homepage_log') {
        require_login();
        require_permission('dashboard.view');
        $data = input_json();
        $event = preg_replace('/[^a-z_]/', '', (string)($data['event_type'] ?? 'module_click'));
        if (!in_array($event, ['view', 'module_click', 'quick_click', 'leave'], true)) {
            $event = 'module_click';
        }
        $module = substr((string)($data['module_key'] ?? ''), 0, 120);
        $target = substr((string)($data['target_url'] ?? ''), 0, 500);
        if (function_exists('sys_homepage_log')) {
            sys_homepage_log($event, $module, $target, $data['payload'] ?? null);
        }
        if (function_exists('sys_action_log')) {
            sys_action_log('homepage', $event, 'module', $module, null, ['target_url' => $target], '首页行为', 'normal');
        }
        api_response(true, '已记录');
    }
    if ($action === 'audit_logs') {
        require_permission('logs.view_all');
        api_response(true, '', ['logs' => db()->query('SELECT * FROM crm_audit_logs ORDER BY id DESC LIMIT 200')->fetchAll()]);
    }
    if ($action === 'notifications_unread_count') {
        require_login();
        require_permission('notifications.view_own');
        api_response(true, '', ['unread_count' => notification_unread_count((int)current_user()['id'])]);
    }
    if ($action === 'notifications_list') {
        require_login();
        require_permission('notifications.view_own');
        api_response(true, '', ['notifications' => notification_list((int)current_user()['id'])]);
    }
    if ($action === 'schema_status') {
        require_permission('settings.schema_scan');
        $tables = ['crm_users','crm_departments','crm_roles','crm_permissions','crm_role_permissions','crm_user_permissions','crm_user_scopes','crm_field_permissions','crm_login_logs','crm_audit_logs','crm_system_settings','crm_schema_migrations','crm_user_approval_logs','crm_permission_requests','crm_permission_grants','crm_permission_request_logs'];
        $status = [];
        foreach ($tables as $table) $status[$table] = db_table_exists($table);
        api_response(true, '', ['tables' => $status]);
    }
    if ($action === 'settings_overview') {
        require_permission('settings.view');
        api_response(true, '', ['overview' => get_system_overview()]);
    }
    if ($action === 'settings_save_company') {
        require_write_api('settings.edit');
        api_response(true, '公司信息已保存', ['company' => save_company_settings(input_json())]);
    }
    if ($action === 'settings_get_ui') {
        require_permission('settings.view');
        api_response(true, '', ['ui' => get_ui_settings()]);
    }
    if ($action === 'settings_save_ui') {
        require_write_api('settings.edit');
        api_response(true, '界面设置已保存', ['ui' => save_ui_settings(input_json())]);
    }
    if ($action === 'settings_get_security') {
        require_permission('settings.view');
        api_response(true, '', ['security' => get_security_settings()]);
    }
    if ($action === 'settings_save_security') {
        require_write_api('settings.edit');
        api_response(true, '安全设置已保存', ['security' => save_security_settings(input_json())]);
    }
    if ($action === 'settings_get_notifications') {
        require_permission('settings.view');
        api_response(true, '', ['notifications' => get_notification_settings()]);
    }
    if ($action === 'settings_save_notifications') {
        require_write_api('settings.edit');
        api_response(true, '通知设置已保存', ['notifications' => save_notification_settings(input_json())]);
    }
    if ($action === 'settings_get_log_policy') {
        require_permission('settings.view');
        api_response(true, '', ['policy' => get_log_settings()]);
    }
    if ($action === 'settings_save_log_policy') {
        require_write_api('settings.edit');
        api_response(true, '日志策略已保存', ['policy' => save_log_settings(input_json())]);
    }
    if ($action === 'settings_storage_usage') {
        require_permission('settings.view');
        api_response(true, '', ['storage' => get_storage_usage(), 'breakdown' => get_storage_breakdown()]);
    }
    if ($action === 'settings_storage_refresh') {
        require_write_api('settings.view');
        api_response(true, '存储统计已刷新', ['storage' => refresh_storage_usage(), 'breakdown' => get_storage_breakdown()]);
    }
    if ($action === 'settings_log_stats') {
        require_permission('settings.view');
        api_response(true, '', ['logs' => get_log_stats()]);
    }
    if ($action === 'settings_backup_stats') {
        require_permission('settings.view');
        api_response(true, '', ['backup' => get_backup_stats()]);
    }
    if ($action === 'settings_schema_scan') {
        require_permission('settings.schema_scan');
        api_response(true, '字段检测完成', ['schema' => get_schema_scan()]);
    }
    if ($action === 'settings_schema_repair') {
        require_write_api('settings.schema_repair');
        require_once __DIR__ . '/includes/schema.php';
        repair_permission_request_schema(db());
        save_app_setting('schema_last_repaired_at', date('Y-m-d H:i:s'), 'schema', '最近修复时间');
        save_app_setting('schema_last_repair_result', '安全修复完成', 'schema', '最近修复结果');
        audit_log('schema_repair', 'settings', 'schema', 'v18_base');
        if (function_exists('sys_action_log')) sys_action_log('settings', 'schema_repair', 'schema', 'v18_base', null, null, 'API 一键安全修复', 'danger');
        api_response(true, '一键安全修复已完成');
    }
    api_response(false, '未知 API', [], 'UNKNOWN_ACTION');
} catch (Throwable $e) {
    api_response(false, $e->getMessage(), [], 'SERVER_ERROR');
}

function can_open_approval_api($request)
{
    $reason = '';
    return can_approve_permission_request($request, current_user(), $reason)
        || has_permission('permission_request.approve_all')
        || has_permission('dangerous.permission_admin');
}
