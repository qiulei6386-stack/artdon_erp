<?php
function sys_log_risk(string $action, string $module): string
{
    $text = strtolower($action . ' ' . $module);
    if (preg_match('/delete|drop|truncate|repair|restore|disable|approve|permission|export|backup_restore|danger/i', $text)) {
        return 'danger';
    }
    if (preg_match('/view_body|settings|backup|download|grant|role|field/i', $text)) {
        return 'sensitive';
    }
    return 'normal';
}

function sys_action_log(string $module, string $action, string $targetType = '', $targetId = null, $before = null, $after = null, string $remark = '', ?string $risk = null): void
{
    try {
        if (!db_table_exists('sys_action_logs')) return;
        $user = function_exists('current_user') ? current_user() : null;
        $stmt = db()->prepare('INSERT INTO sys_action_logs (user_id, operator_name, module, action, target_type, target_id, before_json, after_json, risk_level, remark, ip, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
        $stmt->execute([
            $user['id'] ?? null,
            $user['username'] ?? 'system',
            $module,
            $action,
            $targetType,
            $targetId,
            $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
            $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
            $risk ?: sys_log_risk($action, $module),
            $remark,
            client_ip(),
            user_agent(),
        ]);
    } catch (Throwable $e) {
        error_log('sys_action_log failed: ' . $e->getMessage());
    }
}

function sys_homepage_log(string $eventType, string $moduleKey = '', string $targetUrl = '', $payload = null): void
{
    try {
        if (!db_table_exists('sys_homepage_logs')) return;
        $user = function_exists('current_user') ? current_user() : null;
        $stmt = db()->prepare('INSERT INTO sys_homepage_logs (user_id, operator_name, event_type, module_key, target_url, payload_json, ip, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())');
        $stmt->execute([
            $user['id'] ?? null,
            $user['username'] ?? 'guest',
            $eventType,
            $moduleKey ?: null,
            $targetUrl ?: null,
            $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE),
            client_ip(),
            user_agent(),
        ]);
    } catch (Throwable $e) {
        error_log('sys_homepage_log failed: ' . $e->getMessage());
    }
}

function sys_login_log_mirror($userId, string $username, string $status, string $message): void
{
    try {
        if (!db_table_exists('sys_login_logs')) return;
        $stmt = db()->prepare('INSERT INTO sys_login_logs (user_id, username, status, ip, user_agent, message, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
        $stmt->execute([$userId, $username, $status, client_ip(), user_agent(), $message]);
    } catch (Throwable $e) {
        error_log('sys_login_log_mirror failed: ' . $e->getMessage());
    }
}
