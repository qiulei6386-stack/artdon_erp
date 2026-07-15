<?php
function is_super_admin()
{
    $user = current_user();
    return $user && (int)$user['is_super_admin'] === 1;
}

function has_permission($permission)
{
    $user = current_user();
    if (!$user) {
        return false;
    }
    if (is_super_admin()) {
        return true;
    }
    $aliases = permission_alias_keys((string)$permission);
    $deny = db()->prepare("SELECT 1 FROM crm_user_permissions WHERE user_id = ? AND permission_key = ? AND effect = 'deny' LIMIT 1");
    $deny->execute([$user['id'], $permission]);
    if ($deny->fetchColumn()) {
        return false;
    }
    foreach ($aliases as $alias) {
        $deny->execute([$user['id'], $alias]);
        if ($deny->fetchColumn()) return false;
    }
    expire_old_permission_grants((int)$user['id']);
    if (has_active_temporary_permission($permission, (int)$user['id'])) {
        return true;
    }
    foreach ($aliases as $alias) {
        if (has_active_temporary_permission($alias, (int)$user['id'])) return true;
    }
    $allow = db()->prepare("SELECT 1 FROM crm_user_permissions WHERE user_id = ? AND permission_key = ? AND effect = 'allow' LIMIT 1");
    $allow->execute([$user['id'], $permission]);
    if ($allow->fetchColumn()) {
        return true;
    }
    foreach ($aliases as $alias) {
        $allow->execute([$user['id'], $alias]);
        if ($allow->fetchColumn()) return true;
    }
    if (empty($user['role_id'])) {
        return false;
    }
    $role = db()->prepare('SELECT 1 FROM crm_role_permissions WHERE role_id = ? AND permission_key = ? LIMIT 1');
    $role->execute([$user['role_id'], $permission]);
    if ($role->fetchColumn()) return true;
    foreach ($aliases as $alias) {
        $role->execute([$user['role_id'], $alias]);
        if ($role->fetchColumn()) return true;
    }
    return false;
}

function permission_alias_keys(string $permission): array
{
    $permission = trim($permission);
    $map = [
        'mail.view_own' => ['mail.view'],
        'mail.sync' => ['mail.view'],
        'mail.send' => ['mail.view'],
        'mail.compose' => ['mail.view'],
        'mail.reply' => ['mail.view'],
        'mail.attachment_download' => ['mail.view'],
        'mail.link_customer' => ['mail.view'],
        'mail.account_bind_own' => ['mail.view'],
        'mail.signature_manage_own' => ['mail.account_bind_own', 'mail.view'],
        'mail.view_body' => ['mail.view'],
        'mail.view_attachments' => ['mail.view', 'mail.attachment_download'],
        'mail.view_logs' => ['mail.view'],
        'promotion.task_create' => ['promotion.view'],
        'promotion.execute' => ['promotion.view'],
        'promotion.manage' => ['promotion.view'],
        'promotion.analytics' => ['promotion.view'],
        'promotion.create_project' => ['promotion.task_create', 'promotion.view'],
        'promotion.edit_project' => ['promotion.task_create', 'promotion.view'],
        'promotion.create_group' => ['promotion.view'],
        'promotion.edit_group' => ['promotion.view'],
        'promotion.move_customer' => ['promotion.view'],
        'customer.mail_summary' => ['customer.view'],
        'customer.quote_summary' => ['customer.view'],
        'customer.plm_summary' => ['customer.view'],
        'customer.bom_summary' => ['customer.view'],
        'customer.dispatch_summary' => ['customer.view'],
        'customer.order_summary' => ['customer.view'],
        'customer.material_summary' => ['customer.view'],
        'customer.timeline_view' => ['customer.view'],
        'customer.view_email' => ['customer.view'],
        'customer.view_phone' => ['customer.view'],
        'customer.view_whatsapp' => ['customer.view'],
        'customer.view_address' => ['customer.view'],
        'contact.create' => ['contact.view'],
        'contact.edit' => ['contact.view'],
        'follow.create' => ['follow.view'],
        'follow.edit' => ['follow.view'],
    ];
    return $map[$permission] ?? [];
}

function require_permission($permission)
{
    require_login();
    if (!has_permission($permission)) {
        if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'api.php') {
            permission_denied_response();
        }
        http_response_code(403);
        exit('权限不足');
    }
    $user = current_user();
    if ($user && has_active_temporary_permission($permission, (int)$user['id'])) {
        audit_log('use_temporary_permission', 'permission', 'permission', $permission, null, ['permission_key' => $permission, 'user_id' => $user['id']]);
    }
}

function require_admin()
{
    require_permission('users.view');
}

function require_super_admin()
{
    require_login();
    if (!is_super_admin()) {
        if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'api.php') {
            permission_denied_response();
        }
        http_response_code(403);
        exit('权限不足');
    }
}

function get_user_scope($module)
{
    $user = current_user();
    if (!$user) {
        return ['scope_type' => 'none', 'scope_value' => ''];
    }
    if (is_super_admin()) {
        return ['scope_type' => 'all', 'scope_value' => ''];
    }
    $stmt = db()->prepare('SELECT scope_type, scope_value FROM crm_user_scopes WHERE user_id = ? AND module = ? LIMIT 1');
    $stmt->execute([$user['id'], $module]);
    return $stmt->fetch() ?: ['scope_type' => 'self', 'scope_value' => ''];
}

function can_view_field($field_key)
{
    $user = current_user();
    if (!$user) {
        return false;
    }
    if (is_super_admin()) {
        return true;
    }
    $stmt = db()->prepare('SELECT can_view FROM crm_field_permissions WHERE field_key = ? AND (user_id = ? OR role_id = ?) ORDER BY user_id DESC LIMIT 1');
    $stmt->execute([$field_key, $user['id'], $user['role_id'] ?? 0]);
    $value = $stmt->fetchColumn();
    return $value === false ? true : (bool)$value;
}

function filter_fields($module, $row)
{
    $filtered = $row;
    foreach ($row as $key => $value) {
        $fieldKey = $module . '.' . $key;
        if (!can_view_field($fieldKey)) {
            $filtered[$key] = null;
        }
    }
    return $filtered;
}

function audit_permission_event($action, $target_type, $target_id, $before = null, $after = null)
{
    audit_log($action, 'permission', $target_type, $target_id, $before, $after);
}

function permission_request_tables_ready()
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $ready = db_table_exists('crm_permission_requests')
            && db_table_exists('crm_permission_grants')
            && db_table_exists('crm_permission_request_logs');
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

function is_dangerous_permission($permission)
{
    $dangerous = [
        'dangerous.bulk_delete',
        'dangerous.bulk_export',
        'dangerous.hard_delete_customer',
        'dangerous.schema_repair',
        'dangerous.permission_admin',
        'dangerous.view_all_mail',
        'dangerous.view_all_customer',
        'customer.export',
        'customer.delete',
        'customer.soft_delete',
        'mail.view_all',
        'mail.view_body_all',
        'logs.view_all',
        'settings.schema_repair',
        'users.manage_permissions',
    ];
    if (in_array($permission, $dangerous, true)) {
        return true;
    }
    try {
        $stmt = db()->prepare("SELECT risk_level FROM crm_permissions WHERE permission_key = ? LIMIT 1");
        $stmt->execute([$permission]);
        return in_array($stmt->fetchColumn(), ['high', 'dangerous'], true);
    } catch (Throwable $e) {
        return false;
    }
}

function permission_request_log($requestId, $userId, $action, $before = null, $after = null, $note = '')
{
    if (!permission_request_tables_ready()) {
        return;
    }
    $operator = current_user();
    db()->prepare('INSERT INTO crm_permission_request_logs (request_id, user_id, operator_id, operator_name, action, before_json, after_json, note, ip, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())')
        ->execute([
            $requestId,
            $userId,
            $operator['id'] ?? null,
            $operator['username'] ?? 'system',
            $action,
            $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
            $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
            $note,
            client_ip(),
            user_agent(),
        ]);
}

function get_permission_meta($permission)
{
    $stmt = db()->prepare('SELECT * FROM crm_permissions WHERE permission_key = ? LIMIT 1');
    $stmt->execute([$permission]);
    return $stmt->fetch();
}

function has_active_temporary_permission($permission, $userId = null)
{
    if (!permission_request_tables_ready()) {
        return false;
    }
    $user = current_user();
    $userId = $userId ?: (int)($user['id'] ?? 0);
    if (!$userId) {
        return false;
    }
    $stmt = db()->prepare("SELECT 1 FROM crm_permission_grants WHERE user_id = ? AND permission_key = ? AND effect = 'allow' AND status = 'active' AND starts_at <= NOW() AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 1");
    $stmt->execute([$userId, $permission]);
    return (bool)$stmt->fetchColumn();
}

function get_active_permission_grants($userId = null)
{
    if (!permission_request_tables_ready()) {
        return [];
    }
    $user = current_user();
    $userId = $userId ?: (int)($user['id'] ?? 0);
    if (!$userId) {
        return [];
    }
    expire_old_permission_grants($userId);
    $stmt = db()->prepare("SELECT * FROM crm_permission_grants WHERE user_id = ? AND status = 'active' AND starts_at <= NOW() AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY expires_at IS NULL DESC, expires_at ASC");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function expire_old_permission_grants($userId = null)
{
    if (!permission_request_tables_ready()) {
        return 0;
    }
    $sql = "SELECT * FROM crm_permission_grants WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at <= NOW()";
    $params = [];
    if ($userId) {
        $sql .= ' AND user_id = ?';
        $params[] = $userId;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $expired = $stmt->fetchAll();
    foreach ($expired as $grant) {
        db()->prepare("UPDATE crm_permission_grants SET status = 'expired', updated_at = NOW() WHERE id = ?")->execute([$grant['id']]);
        db()->prepare("UPDATE crm_permission_requests SET status = 'expired', updated_at = NOW() WHERE id = ? AND status = 'approved'")->execute([$grant['request_id']]);
        permission_request_log($grant['request_id'], $grant['user_id'], 'expire', $grant, ['status' => 'expired'], '临时授权到期自动失效');
        audit_log('permission_grant_expired', 'permission', 'grant', $grant['id'], $grant, ['status' => 'expired']);
    }
    return count($expired);
}

function can_approve_permission_request($request, $approver = null, &$reason = '')
{
    $approver = $approver ?: current_user();
    if (!$approver) {
        $reason = '请先登录。';
        return false;
    }
    if ((int)$approver['id'] === (int)$request['requester_id']) {
        $reason = '不能审批自己的权限申请。';
        return false;
    }
    if (is_super_admin()) {
        return true;
    }
    $roleKey = $approver['role_key'] ?? '';
    $dangerous = is_dangerous_permission($request['permission_key']);
    if ($dangerous && !has_permission('dangerous.permission_admin')) {
        $reason = '高危权限只能由老板或权限管理员审批。';
        return false;
    }
    if ($roleKey === 'admin') {
        if (!has_permission($request['permission_key']) && !has_permission('dangerous.permission_admin')) {
            $reason = '管理员不能审批超过自己权限范围的申请。';
            return false;
        }
        return has_permission('permission_request.approve_all') || has_permission('dangerous.permission_admin');
    }
    if ($roleKey === 'manager') {
        if ($dangerous || in_array($request['permission_key'], ['customer.export', 'dangerous.view_all_mail', 'settings.schema_repair'], true)) {
            $reason = '主管不能审批导出、查看全部邮箱、字段修复或高危权限。';
            return false;
        }
        if ((int)$approver['department_id'] !== (int)$request['department_id']) {
            $reason = '主管只能审批本部门申请。';
            return false;
        }
        return has_permission('permission_request.approve_department');
    }
    if ($roleKey === 'team_leader') {
        if ($dangerous || preg_match('/delete|export|repair|view_all/i', $request['permission_key'])) {
            $reason = '组长不能审批导出、删除、修复、查看全部等权限。';
            return false;
        }
        if ((int)$approver['department_id'] !== (int)$request['department_id']) {
            $reason = '组长只能审批本组/本部门申请。';
            return false;
        }
        return has_permission('permission_request.approve_department');
    }
    $reason = '没有审批权限。';
    return false;
}

function create_permission_request($data)
{
    require_login();
    if (!permission_request_tables_ready()) {
        throw new RuntimeException('权限申请表未初始化，请先在设置中心执行结构修复。');
    }
    $user = current_user();
    $permission = trim($data['permission_key'] ?? '');
    if ($permission === '' || $permission === 'super_admin') {
        throw new RuntimeException('不可申请该权限。');
    }
    $meta = get_permission_meta($permission);
    if (!$meta) {
        throw new RuntimeException('权限 key 不存在。');
    }
    $reason = trim($data['reason'] ?? '');
    if ($reason === '') {
        throw new RuntimeException('申请原因必填。');
    }
    $stmt = db()->prepare("INSERT INTO crm_permission_requests (requester_id, requester_name, department_id, role_id, permission_key, module, action, request_scope_type, request_scope_value, field_key, related_type, related_id, reason, urgency, expected_days, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())");
    $stmt->execute([
        $user['id'],
        $user['username'],
        $user['department_id'] ?: null,
        $user['role_id'] ?: null,
        $permission,
        $meta['module'],
        $meta['action'],
        $data['request_scope_type'] ?? 'self',
        $data['request_scope_value'] ?? '',
        $data['field_key'] ?? null,
        $data['related_type'] ?? null,
        $data['related_id'] ?? null,
        $reason,
        $data['urgency'] ?? 'normal',
        (int)($data['expected_days'] ?? 1),
    ]);
    $id = db()->lastInsertId();
    $after = ['id' => $id, 'permission_key' => $permission, 'reason' => $reason, 'urgency' => $data['urgency'] ?? 'normal'];
    permission_request_log($id, $user['id'], 'submit', null, $after, $reason);
    audit_log(is_dangerous_permission($permission) ? 'submit_dangerous_permission_request' : 'submit_permission_request', 'permission', 'request', $id, null, $after);
    return $id;
}

function approve_permission_request($requestId, $data)
{
    require_login();
    $stmt = db()->prepare('SELECT pr.*, u.username, u.department_id AS current_department_id, u.role_id AS current_role_id FROM crm_permission_requests pr LEFT JOIN crm_users u ON u.id = pr.requester_id WHERE pr.id = ? LIMIT 1');
    $stmt->execute([(int)$requestId]);
    $request = $stmt->fetch();
    if (!$request || $request['status'] !== 'pending') {
        throw new RuntimeException('申请不存在或已处理。');
    }
    $reason = '';
    if (!can_approve_permission_request($request, current_user(), $reason)) {
        throw new RuntimeException($reason);
    }
    $permissionKey = $data['permission_key'] ?? $request['permission_key'];
    $permissionMeta = get_permission_meta($permissionKey);
    if (!$permissionMeta) {
        throw new RuntimeException('批准的权限 key 不存在。');
    }
    $dangerous = is_dangerous_permission($permissionKey);
    $note = trim($data['approval_note'] ?? '');
    if ($dangerous && $note === '') {
        throw new RuntimeException('高危权限审批必须填写审批备注。');
    }
    $isPermanent = !empty($data['is_permanent']);
    if ($isPermanent && !is_super_admin()) {
        throw new RuntimeException('永久授权只有老板 / super_admin 可操作。');
    }
    if ($dangerous && $isPermanent && !is_super_admin()) {
        throw new RuntimeException('高危权限永久授权只允许老板。');
    }
    $startsAt = $data['starts_at'] ?: date('Y-m-d H:i:s');
    if ($isPermanent) {
        $expiresAt = null;
    } elseif (!empty($data['expires_at'])) {
        $expiresAt = $data['expires_at'];
    } else {
        $days = max(1, (int)($data['grant_days'] ?? 1));
        if ((current_user()['role_key'] ?? '') === 'team_leader' && $days > 3) {
            throw new RuntimeException('组长只能审批 1 天或 3 天临时权限。');
        }
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . $days . ' days'));
    }
    $approver = current_user();
    $before = $request;
    db()->prepare("UPDATE crm_permission_requests SET status = 'approved', approved_by = ?, approved_by_name = ?, approved_at = NOW(), approval_note = ?, updated_at = NOW() WHERE id = ?")
        ->execute([$approver['id'], $approver['username'], $note, $requestId]);
    db()->prepare("INSERT INTO crm_permission_grants (request_id, user_id, permission_key, module, action, effect, scope_type, scope_value, field_key, related_type, related_id, granted_by, granted_by_name, granted_at, starts_at, expires_at, is_temporary, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 'allow', ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, 'active', NOW(), NOW())")
        ->execute([
            $requestId,
            $request['requester_id'],
            $permissionKey,
            $permissionMeta['module'],
            $permissionMeta['action'],
            $data['scope_type'] ?? $request['request_scope_type'],
            $data['scope_value'] ?? $request['request_scope_value'],
            $data['field_key'] ?? $request['field_key'],
            $data['related_type'] ?? $request['related_type'],
            $data['related_id'] ?? $request['related_id'],
            $approver['id'],
            $approver['username'],
            $startsAt,
            $expiresAt,
            $isPermanent ? 0 : 1,
        ]);
    $grantId = db()->lastInsertId();
    $after = ['request_id' => $requestId, 'grant_id' => $grantId, 'permission_key' => $permissionKey, 'starts_at' => $startsAt, 'expires_at' => $expiresAt, 'is_permanent' => $isPermanent];
    permission_request_log($requestId, $request['requester_id'], 'approve', $before, $after, $note);
    audit_log($dangerous ? 'approve_dangerous_permission_request' : 'approve_permission_request', 'permission', 'request', $requestId, $before, $after);
    return $grantId;
}

function reject_permission_request($requestId, $reason)
{
    require_login();
    $stmt = db()->prepare('SELECT * FROM crm_permission_requests WHERE id = ? LIMIT 1');
    $stmt->execute([(int)$requestId]);
    $request = $stmt->fetch();
    if (!$request || $request['status'] !== 'pending') {
        throw new RuntimeException('申请不存在或已处理。');
    }
    $denyReason = '';
    if (!can_approve_permission_request($request, current_user(), $denyReason)) {
        throw new RuntimeException($denyReason);
    }
    $operator = current_user();
    db()->prepare("UPDATE crm_permission_requests SET status = 'rejected', rejected_by = ?, rejected_by_name = ?, rejected_at = NOW(), reject_reason = ?, updated_at = NOW() WHERE id = ?")
        ->execute([$operator['id'], $operator['username'], $reason, $requestId]);
    permission_request_log($requestId, $request['requester_id'], 'reject', $request, ['status' => 'rejected', 'reason' => $reason], $reason);
    audit_log('reject_permission_request', 'permission', 'request', $requestId, $request, ['status' => 'rejected']);
}

function revoke_permission_grant($grantId, $reason = '')
{
    require_login();
    $stmt = db()->prepare('SELECT * FROM crm_permission_grants WHERE id = ? LIMIT 1');
    $stmt->execute([(int)$grantId]);
    $grant = $stmt->fetch();
    if (!$grant || $grant['status'] !== 'active') {
        throw new RuntimeException('授权不存在或已失效。');
    }
    $operator = current_user();
    if (!is_super_admin() && !has_permission('permission_request.revoke') && !has_permission('dangerous.permission_admin')) {
        throw new RuntimeException('没有撤销授权权限。');
    }
    db()->prepare("UPDATE crm_permission_grants SET status = 'revoked', revoked_by = ?, revoked_by_name = ?, revoked_at = NOW(), revoke_reason = ?, updated_at = NOW() WHERE id = ?")
        ->execute([$operator['id'], $operator['username'], $reason, $grantId]);
    db()->prepare("UPDATE crm_permission_requests SET status = 'cancelled', updated_at = NOW() WHERE id = ? AND status = 'approved'")->execute([$grant['request_id']]);
    permission_request_log($grant['request_id'], $grant['user_id'], 'revoke', $grant, ['status' => 'revoked', 'reason' => $reason], $reason);
    audit_log('revoke_permission_grant', 'permission', 'grant', $grantId, $grant, ['status' => 'revoked']);
}

function extend_permission_grant($grantId, $expiresAt, $note = '')
{
    require_login();
    if (!is_super_admin() && !has_permission('permission_request.revoke')) {
        throw new RuntimeException('没有延长授权权限。');
    }
    $stmt = db()->prepare('SELECT * FROM crm_permission_grants WHERE id = ? LIMIT 1');
    $stmt->execute([(int)$grantId]);
    $grant = $stmt->fetch();
    if (!$grant || $grant['status'] !== 'active') {
        throw new RuntimeException('授权不存在或已失效。');
    }
    db()->prepare('UPDATE crm_permission_grants SET expires_at = ?, is_temporary = 1, updated_at = NOW() WHERE id = ?')->execute([$expiresAt, $grantId]);
    permission_request_log($grant['request_id'], $grant['user_id'], 'extend', $grant, ['expires_at' => $expiresAt], $note);
    audit_log('extend_permission_grant', 'permission', 'grant', $grantId, $grant, ['expires_at' => $expiresAt]);
}
