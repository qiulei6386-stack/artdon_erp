<?php
function client_ip()
{
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

function user_agent()
{
    return substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
}

function audit_log($action, $module, $target_type = '', $target_id = null, $before = null, $after = null, $user = null)
{
    try {
        $user = $user ?: (function_exists('current_user') ? current_user() : null);
        $stmt = db()->prepare('INSERT INTO crm_audit_logs (user_id, operator_name, action, module, target_type, target_id, before_json, after_json, ip, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
        $stmt->execute([
            $user['id'] ?? null,
            $user['username'] ?? 'system',
            $action,
            $module,
            $target_type,
            $target_id,
            $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
            $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
            client_ip(),
            user_agent(),
        ]);
        if (function_exists('sys_action_log')) {
            sys_action_log($module, $action, $target_type, $target_id, $before, $after);
        }
    } catch (Throwable $e) {
        error_log('audit_log failed: ' . $e->getMessage());
    }
}

function login_log($username, $status, $message = '', $user_id = null)
{
    try {
        $stmt = db()->prepare('INSERT INTO crm_login_logs (user_id, username, status, ip, user_agent, message, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
        $stmt->execute([$user_id, $username, $status, client_ip(), user_agent(), $message]);
        if (function_exists('sys_login_log_mirror')) {
            sys_login_log_mirror($user_id, $username, $status, $message);
        }
    } catch (Throwable $e) {
        error_log('login_log failed: ' . $e->getMessage());
    }
}
