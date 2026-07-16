<?php

function crm_ensure_tables(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    db()->exec("CREATE TABLE IF NOT EXISTS crm_user_preferences (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        theme_name VARCHAR(80) NOT NULL DEFAULT 'compact-light',
        font_scale INT NOT NULL DEFAULT 12,
        density_mode VARCHAR(40) NOT NULL DEFAULT 'compact',
        animation_mode VARCHAR(40) NOT NULL DEFAULT 'subtle',
        topbar_height INT NOT NULL DEFAULT 48,
        tabbar_height INT NOT NULL DEFAULT 38,
        actionbar_width INT NOT NULL DEFAULT 220,
        table_row_height INT NOT NULL DEFAULT 28,
        email_list_font_size INT NOT NULL DEFAULT 12,
        email_body_font_size INT NOT NULL DEFAULT 13,
        email_editor_font_size INT NOT NULL DEFAULT 13,
        module_layout_json JSON NULL,
        module_visible_fields_json JSON NULL,
        shortcuts_json JSON NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_crm_pref_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS crm_company_preferences (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        default_theme VARCHAR(80) NOT NULL DEFAULT 'compact-light',
        default_density VARCHAR(40) NOT NULL DEFAULT 'compact',
        default_font_scale INT NOT NULL DEFAULT 12,
        default_animation_mode VARCHAR(40) NOT NULL DEFAULT 'subtle',
        default_layout_json JSON NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS crm_online_users (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        user_name VARCHAR(120) NOT NULL,
        department VARCHAR(120) NULL,
        role VARCHAR(120) NULL,
        status VARCHAR(40) NOT NULL DEFAULT 'online',
        current_module VARCHAR(80) NULL,
        login_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_active_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        ip_address VARCHAR(64) NULL,
        device_type VARCHAR(40) NULL,
        browser VARCHAR(120) NULL,
        user_agent VARCHAR(500) NULL,
        session_id VARCHAR(160) NOT NULL,
        is_mobile TINYINT(1) NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_crm_online_session (session_id),
        KEY idx_crm_online_user (user_id),
        KEY idx_crm_online_active (last_active_time)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS crm_online_sessions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        user_name VARCHAR(120) NOT NULL,
        department VARCHAR(120) NULL,
        role VARCHAR(120) NULL,
        current_module VARCHAR(80) NULL,
        session_id VARCHAR(160) NOT NULL,
        login_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_active_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        logout_time DATETIME NULL,
        status VARCHAR(40) NOT NULL DEFAULT 'active',
        active_seconds INT UNSIGNED NOT NULL DEFAULT 0,
        heartbeat_count INT UNSIGNED NOT NULL DEFAULT 0,
        last_event VARCHAR(40) NULL,
        ip_address VARCHAR(64) NULL,
        device_type VARCHAR(40) NULL,
        browser VARCHAR(120) NULL,
        user_agent VARCHAR(500) NULL,
        is_mobile TINYINT(1) NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_crm_online_session_log (session_id),
        KEY idx_crm_online_session_user (user_id),
        KEY idx_crm_online_session_active (last_active_time),
        KEY idx_crm_online_session_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS crm_operation_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NULL,
        operator_name VARCHAR(120) NULL,
        module_key VARCHAR(80) NOT NULL,
        action_key VARCHAR(120) NOT NULL,
        target_type VARCHAR(80) NULL,
        target_id VARCHAR(120) NULL,
        before_json JSON NULL,
        after_json JSON NULL,
        success TINYINT(1) NOT NULL DEFAULT 1,
        failure_reason VARCHAR(500) NULL,
        ip_address VARCHAR(64) NULL,
        user_agent VARCHAR(500) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_crm_log_user (user_id),
        KEY idx_crm_log_module (module_key),
        KEY idx_crm_log_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    crm_log_ensure_permissions();
}

function crm_log_ensure_permissions(): void
{
    $permissions = [
        ['online.view', 'online', 'view', '查看在线人数', 'low'],
        ['online.view_all', 'online', 'view_all', '查看全部在线人员', 'high'],
        ['crm.preferences.edit', 'crm', 'preferences_edit', '修改个人 CRM 偏好', 'low'],
        ['crm.layout.edit_company', 'crm', 'layout_company', '修改全公司 CRM 默认设置', 'high'],
        ['material.view', 'material', 'view', '查看资料', 'medium'],
        ['material.generate', 'material', 'generate', '生成资料', 'high'],
        ['material.download', 'material', 'download', '下载资料', 'high'],
        ['material.send', 'material', 'send', '发送资料', 'high'],
        ['material.delete', 'material', 'delete', '删除资料', 'dangerous'],
        ['material.view_logs', 'material', 'view_logs', '查看资料日志', 'medium'],
        ['material.view_storage', 'material', 'view_storage', '查看资料空间', 'medium'],
        ['material.template_edit', 'material', 'template_edit', '修改资料模板', 'high'],
        ['material.view_internal', 'material', 'view_internal', '查看内部资料', 'dangerous'],
        ['material.convert_customer', 'material', 'convert_customer', '转换客户资料', 'high'],
        ['bom.view_cost', 'bom', 'view_cost', '查看 BOM 成本', 'dangerous'],
        ['bom.view_full', 'bom', 'view_full', '查看完整 BOM', 'dangerous'],
    ];
    $stmt = db()->prepare('INSERT IGNORE INTO crm_permissions (permission_key, module, action, description, risk_level, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
    foreach ($permissions as $permission) {
        $stmt->execute($permission);
    }
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key) SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p WHERE r.role_key = 'super_admin' AND p.module IN ('online','crm','material','bom')");
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key) SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p WHERE r.role_key = 'admin' AND p.permission_key IN ('online.view','online.view_all','crm.preferences.edit','material.view','material.generate','material.download','material.send','material.view_logs','material.view_storage','material.convert_customer')");
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key) SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p WHERE r.role_key IN ('manager','sales','marketing','finance','viewer') AND p.permission_key IN ('online.view','crm.preferences.edit','material.view','material.generate','material.download','material.view_logs','material.convert_customer')");
}

function crm_preferences(int $userId): array
{
    crm_ensure_tables();
    $defaults = crm_default_preferences();
    $stmt = db()->prepare('SELECT * FROM crm_user_preferences WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) return $defaults;
    return array_replace($defaults, [
        'theme_name' => $row['theme_name'],
        'font_scale' => (int)$row['font_scale'],
        'density_mode' => $row['density_mode'],
        'animation_mode' => $row['animation_mode'],
        'topbar_height' => (int)$row['topbar_height'],
        'tabbar_height' => (int)$row['tabbar_height'],
        'actionbar_width' => (int)$row['actionbar_width'],
        'table_row_height' => (int)$row['table_row_height'],
        'email_list_font_size' => (int)$row['email_list_font_size'],
        'email_body_font_size' => (int)$row['email_body_font_size'],
        'email_editor_font_size' => (int)$row['email_editor_font_size'],
        'module_layout' => json_decode((string)$row['module_layout_json'], true) ?: [],
        'visible_fields' => json_decode((string)$row['module_visible_fields_json'], true) ?: [],
        'shortcuts' => json_decode((string)$row['shortcuts_json'], true) ?: [],
    ]);
}

function crm_save_preferences(int $userId, array $input): array
{
    crm_ensure_tables();
    $before = crm_preferences($userId);
    $next = array_replace($before, [
        'theme_name' => preg_replace('/[^a-z0-9\\-]/i', '', (string)($input['theme_name'] ?? $before['theme_name'])) ?: 'compact-light',
        'font_scale' => max(11, min(15, (int)($input['font_scale'] ?? $before['font_scale']))),
        'density_mode' => in_array($input['density_mode'] ?? '', ['ultra', 'compact', 'standard', 'comfortable'], true) ? $input['density_mode'] : $before['density_mode'],
        'animation_mode' => in_array($input['animation_mode'] ?? '', ['off', 'subtle', 'standard'], true) ? $input['animation_mode'] : $before['animation_mode'],
        'topbar_height' => max(44, min(52, (int)($input['topbar_height'] ?? $before['topbar_height']))),
        'tabbar_height' => max(32, min(46, (int)($input['tabbar_height'] ?? $before['tabbar_height']))),
        'actionbar_width' => max(160, min(420, (int)($input['actionbar_width'] ?? $before['actionbar_width']))),
        'table_row_height' => max(24, min(38, (int)($input['table_row_height'] ?? $before['table_row_height']))),
        'email_list_font_size' => max(11, min(15, (int)($input['email_list_font_size'] ?? $before['email_list_font_size']))),
        'email_body_font_size' => max(11, min(16, (int)($input['email_body_font_size'] ?? $before['email_body_font_size']))),
        'email_editor_font_size' => max(11, min(16, (int)($input['email_editor_font_size'] ?? $before['email_editor_font_size']))),
        'tab_label_mode' => in_array($input['tab_label_mode'] ?? '', ['full', 'short', 'icon_short', 'icon'], true) ? $input['tab_label_mode'] : ($before['tab_label_mode'] ?? 'icon_short'),
        'actionbar_collapsed' => !empty($input['actionbar_collapsed']) ? 1 : 0,
    ]);
    if (isset($input['top_menu_visible'])) {
        $visible = is_array($input['top_menu_visible']) ? $input['top_menu_visible'] : explode(',', (string)$input['top_menu_visible']);
        $visible = array_values(array_intersect(array_map('strval', $visible), crm_top_module_keys()));
        if ($visible) {
            $next['module_layout'] = is_array($next['module_layout'] ?? null) ? $next['module_layout'] : [];
            $next['module_layout']['top_menu_visible'] = $visible;
        }
    }
    $next['module_layout'] = is_array($next['module_layout'] ?? null) ? $next['module_layout'] : [];
    if (isset($input['button_style']) && in_array((string)$input['button_style'], ['rounded', 'square', 'pill'], true)) {
        $next['module_layout']['button_style'] = (string)$input['button_style'];
    }
    if (isset($input['button_size']) && in_array((string)$input['button_size'], ['small', 'standard', 'large'], true)) {
        $next['module_layout']['button_size'] = (string)$input['button_size'];
    }
    $moduleColors = is_array($next['module_layout']['module_colors'] ?? null) ? $next['module_layout']['module_colors'] : [];
    foreach (crm_top_module_keys() as $moduleKey) {
        $field = 'module_color_' . $moduleKey;
        if (!isset($input[$field])) continue;
        $color = trim((string)$input[$field]);
        if (preg_match('/^#[0-9a-f]{6}$/i', $color)) $moduleColors[$moduleKey] = $color;
    }
    if ($moduleColors) $next['module_layout']['module_colors'] = $moduleColors;
    if (isset($input['world_time_zones_json'])) {
        $decoded = json_decode((string)$input['world_time_zones_json'], true);
        $zones = [];
        if (is_array($decoded)) {
            foreach ($decoded as $item) {
                if (!is_array($item)) continue;
                $name = trim((string)($item['name'] ?? $item['label'] ?? ''));
                $zone = trim((string)($item['zone'] ?? $item['timezone'] ?? ''));
                if ($name === '' || $zone === '') continue;
                if (!preg_match('/^[A-Za-z0-9_+\\-\\/]+$/', $zone)) continue;
                $zones[] = [
                    'name' => substr($name, 0, 120),
                    'zone' => substr($zone, 0, 80),
                    'label' => substr(trim((string)($item['label'] ?? $zone)), 0, 120),
                ];
                if (count($zones) >= 10) break;
            }
        }
        if ($zones) $next['module_layout']['world_time_zones'] = $zones;
    }
    db()->prepare('INSERT INTO crm_user_preferences (user_id, theme_name, font_scale, density_mode, animation_mode, topbar_height, tabbar_height, actionbar_width, table_row_height, email_list_font_size, email_body_font_size, email_editor_font_size, module_layout_json, module_visible_fields_json, shortcuts_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE theme_name=VALUES(theme_name), font_scale=VALUES(font_scale), density_mode=VALUES(density_mode), animation_mode=VALUES(animation_mode), topbar_height=VALUES(topbar_height), tabbar_height=VALUES(tabbar_height), actionbar_width=VALUES(actionbar_width), table_row_height=VALUES(table_row_height), email_list_font_size=VALUES(email_list_font_size), email_body_font_size=VALUES(email_body_font_size), email_editor_font_size=VALUES(email_editor_font_size), module_layout_json=VALUES(module_layout_json), module_visible_fields_json=VALUES(module_visible_fields_json), shortcuts_json=VALUES(shortcuts_json), updated_at=NOW()')
        ->execute([$userId, $next['theme_name'], $next['font_scale'], $next['density_mode'], $next['animation_mode'], $next['topbar_height'], $next['tabbar_height'], $next['actionbar_width'], $next['table_row_height'], $next['email_list_font_size'], $next['email_body_font_size'], $next['email_editor_font_size'], json_encode($next['module_layout'], JSON_UNESCAPED_UNICODE), json_encode($next['visible_fields'], JSON_UNESCAPED_UNICODE), json_encode($next['shortcuts'], JSON_UNESCAPED_UNICODE)]);
    crm_log_event('settings', 'save_preferences', 'user', (string)$userId, $before, $next);
    return $next;
}

function crm_device_type(): array
{
    $ua = user_agent();
    $mobile = preg_match('/Mobile|Android|iPhone|iPad/i', $ua) ? 1 : 0;
    $browser = 'Browser';
    if (preg_match('/Chrome/i', $ua)) $browser = 'Chrome';
    elseif (preg_match('/Safari/i', $ua)) $browser = 'Safari';
    elseif (preg_match('/Firefox/i', $ua)) $browser = 'Firefox';
    elseif (preg_match('/Edge/i', $ua)) $browser = 'Edge';
    return [$mobile ? 'mobile' : 'desktop', $browser, $mobile];
}

function crm_touch_online(string $module = 'workspace'): void
{
    crm_ensure_tables();
    $user = current_user();
    if (!$user) return;
    [$device, $browser, $mobile] = crm_device_type();
    $session = session_id();
    db()->prepare("INSERT INTO crm_online_users (user_id, user_name, department, role, status, current_module, login_time, last_active_time, ip_address, device_type, browser, user_agent, session_id, is_mobile, created_at, updated_at) VALUES (?, ?, ?, ?, 'online', ?, NOW(), NOW(), ?, ?, ?, ?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE user_name=VALUES(user_name), department=VALUES(department), role=VALUES(role), status='online', current_module=VALUES(current_module), last_active_time=NOW(), ip_address=VALUES(ip_address), device_type=VALUES(device_type), browser=VALUES(browser), user_agent=VALUES(user_agent), is_mobile=VALUES(is_mobile), updated_at=NOW()")
        ->execute([$user['id'], $user['real_name'] ?: $user['username'], $user['department_name'] ?? '', $user['role_name'] ?? '', $module, client_ip(), $device, $browser, user_agent(), $session, $mobile]);
    crm_touch_online_session($module, 'heartbeat');
}

function crm_touch_online_session(string $module = 'workspace', string $event = 'heartbeat'): void
{
    $user = current_user();
    if (!$user) return;
    [$device, $browser, $mobile] = crm_device_type();
    $session = session_id();
    $now = time();
    $stmt = db()->prepare('SELECT id, last_active_time FROM crm_online_sessions WHERE session_id = ? LIMIT 1');
    $stmt->execute([$session]);
    $row = $stmt->fetch();
    $add = 0;
    if ($row) {
        $last = strtotime((string)$row['last_active_time']);
        $delta = $last ? max(0, $now - $last) : 0;
        $add = ($delta > 0 && $delta <= 180) ? $delta : 0;
        $status = $event === 'leave' ? 'offline' : 'active';
        db()->prepare("UPDATE crm_online_sessions SET user_id=?, user_name=?, department=?, role=?, current_module=?, last_active_time=NOW(), logout_time=" . ($event === 'leave' ? 'NOW()' : 'NULL') . ", status=?, active_seconds=active_seconds+?, heartbeat_count=heartbeat_count+1, last_event=?, ip_address=?, device_type=?, browser=?, user_agent=?, is_mobile=?, updated_at=NOW() WHERE id=?")
            ->execute([$user['id'], $user['real_name'] ?: $user['username'], $user['department_name'] ?? '', $user['role_name'] ?? '', $module, $status, $add, $event, client_ip(), $device, $browser, user_agent(), $mobile, $row['id']]);
        return;
    }
    db()->prepare("INSERT INTO crm_online_sessions (user_id, user_name, department, role, current_module, session_id, login_time, last_active_time, logout_time, status, active_seconds, heartbeat_count, last_event, ip_address, device_type, browser, user_agent, is_mobile, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), " . ($event === 'leave' ? 'NOW()' : 'NULL') . ", ?, 0, 1, ?, ?, ?, ?, ?, ?, NOW(), NOW())")
        ->execute([$user['id'], $user['real_name'] ?: $user['username'], $user['department_name'] ?? '', $user['role_name'] ?? '', $module, $session, $event === 'leave' ? 'offline' : 'active', $event, client_ip(), $device, $browser, user_agent(), $mobile]);
}

function crm_mark_online_leave(string $module = 'workspace'): void
{
    crm_ensure_tables();
    $user = current_user();
    if (!$user) return;
    crm_touch_online_session($module, 'leave');
    db()->prepare("UPDATE crm_online_users SET status='offline', current_module=?, last_active_time=NOW(), updated_at=NOW() WHERE session_id=?")
        ->execute([$module, session_id()]);
}

function crm_online_people(): array
{
    crm_ensure_tables();
    $rows = db()->query("SELECT ou.*, COALESCE(os.active_seconds, 0) AS online_seconds
        FROM crm_online_users ou
        LEFT JOIN crm_online_sessions os ON os.session_id = ou.session_id
        WHERE ou.status <> 'offline' AND ou.last_active_time >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ORDER BY ou.last_active_time DESC
        LIMIT 100")->fetchAll();
    $people = [];
    foreach ($rows as $row) {
        $key = (int)($row['user_id'] ?? 0) > 0 ? 'u' . (int)$row['user_id'] : 's' . (string)($row['session_id'] ?? '');
        if (isset($people[$key])) continue;
        $idle = time() - strtotime($row['last_active_time']);
        $row['status'] = $idle <= 300 ? ($row['is_mobile'] ? '手机在线' : '在线') : '离开';
        $seconds = (int)($row['online_seconds'] ?? 0);
        $row['online_seconds'] = $seconds;
        $row['online_text'] = crm_online_session_duration_text($seconds);
        $people[$key] = $row;
    }
    return array_values($people);
}

function crm_online_count(): int
{
    crm_ensure_tables();
    return (int)db()->query("SELECT COUNT(DISTINCT CASE WHEN user_id > 0 THEN user_id ELSE session_id END) FROM crm_online_users WHERE status <> 'offline' AND last_active_time >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)")->fetchColumn();
}

function crm_online_session_duration_text(int $seconds): string
{
    $seconds = max(0, $seconds);
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    if ($hours > 0) return $hours . '小时' . $minutes . '分';
    return $minutes . '分';
}

function crm_log_event(string $module, string $action, string $targetType = '', string $targetId = '', $before = null, $after = null, bool $success = true, string $failure = ''): void
{
    try {
        crm_ensure_tables();
        $user = current_user();
        db()->prepare('INSERT INTO crm_operation_logs (user_id, operator_name, module_key, action_key, target_type, target_id, before_json, after_json, success, failure_reason, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())')
            ->execute([$user['id'] ?? null, $user['username'] ?? 'guest', $module, $action, $targetType, $targetId, $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE), $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE), $success ? 1 : 0, $failure, client_ip(), user_agent()]);
    } catch (Throwable $e) {
        error_log('crm_log_event failed: ' . $e->getMessage());
    }
}

function crm_recent_operation_logs(array $input = []): array
{
    if (!has_permission('logs.view_all') && !has_permission('logs.view_own') && !has_permission('customer.view_logs') && !is_super_admin()) {
        return ['rows' => []];
    }
    $where = ['1=1'];
    $params = [];
    if (!is_super_admin() && !has_permission('logs.view_all')) {
        $where[] = 'user_id = ?';
        $params[] = current_user()['id'] ?? 0;
    }
    $module = trim((string)($input['module_key'] ?? ''));
    if ($module !== '') {
        $where[] = 'module_key = ?';
        $params[] = $module;
    }
    $q = trim((string)($input['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(operator_name LIKE ? OR module_key LIKE ? OR action_key LIKE ? OR target_type LIKE ? OR target_id LIKE ? OR failure_reason LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like, $like, $like);
    }
    $stmt = db()->prepare('SELECT * FROM crm_operation_logs WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 200');
    $stmt->execute($params);
    return ['rows' => $stmt->fetchAll()];
}

function crm_log_center(array $input = []): array
{
    crm_ensure_tables();
    if (!has_permission('logs.view_all') && !has_permission('logs.view_department') && !has_permission('logs.view_own') && !has_permission('customer.view_logs') && !is_super_admin()) {
        return ['rows' => [], 'total' => 0, 'kpis' => crm_log_center_empty_kpis(), 'users' => [], 'types' => crm_log_center_types()];
    }

    $scopeUserIds = crm_log_center_scope_user_ids();
    $rows = crm_log_center_collect_rows($scopeUserIds);
    $rows = crm_log_center_filter_rows($rows, $input, $scopeUserIds);
    usort($rows, fn($a, $b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')) ?: ((int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0)));

    $total = count($rows);
    $pageSize = max(20, min(200, (int)($input['page_size'] ?? 50)));
    $page = max(1, (int)($input['page'] ?? 1));
    $offset = ($page - 1) * $pageSize;
    $pageRows = array_slice($rows, $offset, $pageSize);

    return [
        'rows' => $pageRows,
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
        'pages' => max(1, (int)ceil($total / $pageSize)),
        'kpis' => crm_log_center_kpis($rows),
        'users' => crm_log_center_users($scopeUserIds),
        'types' => crm_log_center_types(),
    ];
}

function crm_log_center_empty_kpis(): array
{
    return ['total' => 0, 'today' => 0, 'success' => 0, 'failed' => 0, 'danger' => 0, 'login' => 0, 'business' => 0];
}

function crm_log_center_types(): array
{
    return [
        ['key' => 'all', 'label' => '全部日志'],
        ['key' => 'operation', 'label' => 'CRM 操作'],
        ['key' => 'audit', 'label' => '统一审计'],
        ['key' => 'login', 'label' => '登录日志'],
        ['key' => 'customer', 'label' => '客户日志'],
        ['key' => 'mail', 'label' => '邮件日志'],
        ['key' => 'promotion', 'label' => '推广日志'],
        ['key' => 'task', 'label' => '任务日志'],
        ['key' => 'opportunity', 'label' => '商机日志'],
        ['key' => 'ai', 'label' => 'AI 日志'],
        ['key' => 'settings', 'label' => '设置日志'],
        ['key' => 'security', 'label' => '安全/失败'],
    ];
}

function crm_log_center_scope_user_ids(): ?array
{
    if (is_super_admin() || has_permission('logs.view_all')) return null;
    $user = current_user() ?: [];
    $userId = (int)($user['id'] ?? 0);
    if (has_permission('logs.view_department')) {
        $ids = [];
        if (!empty($user['department_id'])) {
            $stmt = db()->prepare('SELECT id FROM crm_users WHERE department_id = ?');
            $stmt->execute([(int)$user['department_id']]);
            $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }
        if ($userId && !in_array($userId, $ids, true)) $ids[] = $userId;
        return $ids ?: [$userId];
    }
    return [$userId];
}

function crm_log_center_users(?array $scopeUserIds): array
{
    if (!db_table_exists('crm_users')) return [];
    $where = '1=1';
    $params = [];
    if (is_array($scopeUserIds)) {
        if (!$scopeUserIds) return [];
        $where = 'u.id IN (' . implode(',', array_fill(0, count($scopeUserIds), '?')) . ')';
        $params = $scopeUserIds;
    }
    $stmt = db()->prepare("SELECT u.id, u.username, u.real_name, d.name AS department_name FROM crm_users u LEFT JOIN crm_departments d ON d.id = u.department_id WHERE {$where} ORDER BY d.sort_order, u.real_name, u.username LIMIT 300");
    $stmt->execute($params);
    return array_map(function ($row) {
        return [
            'id' => (int)$row['id'],
            'name' => ($row['real_name'] ?: $row['username']) ?: ('用户 #' . $row['id']),
            'department' => $row['department_name'] ?? '',
        ];
    }, $stmt->fetchAll());
}

function crm_log_center_collect_rows(?array $scopeUserIds): array
{
    $rows = [];
    crm_log_center_collect_operation($rows, $scopeUserIds);
    crm_log_center_collect_audit($rows, $scopeUserIds);
    crm_log_center_collect_login($rows, $scopeUserIds);
    crm_log_center_collect_ai($rows, $scopeUserIds);
    crm_log_center_collect_marketing($rows, $scopeUserIds);
    crm_log_center_collect_mail_sync($rows, $scopeUserIds);
    crm_log_center_collect_opportunity($rows, $scopeUserIds);
    return $rows;
}

function crm_log_center_scope_sql(string $alias, string $column, ?array $scopeUserIds, array &$params): string
{
    if (!is_array($scopeUserIds)) return '1=1';
    if (!$scopeUserIds) return '1=0';
    $params = array_merge($params, $scopeUserIds);
    return "{$alias}.{$column} IN (" . implode(',', array_fill(0, count($scopeUserIds), '?')) . ')';
}

function crm_log_center_collect_operation(array &$rows, ?array $scopeUserIds): void
{
    if (!db_table_exists('crm_operation_logs')) return;
    $params = [];
    $scope = crm_log_center_scope_sql('l', 'user_id', $scopeUserIds, $params);
    $stmt = db()->prepare("SELECT l.* FROM crm_operation_logs l WHERE {$scope} ORDER BY l.id DESC LIMIT 1200");
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = crm_log_center_row([
            'id' => $row['id'] ?? null,
            'log_type' => 'operation',
            'type_label' => 'CRM 操作',
            'source_table' => 'crm_operation_logs',
            'module_key' => $row['module_key'] ?? '',
            'action_key' => $row['action_key'] ?? '',
            'operator_name' => $row['operator_name'] ?? '',
            'user_id' => $row['user_id'] ?? null,
            'target_type' => $row['target_type'] ?? '',
            'target_id' => $row['target_id'] ?? '',
            'result_status' => ((int)($row['success'] ?? 1) === 1) ? 'success' : 'failed',
            'failure_reason' => $row['failure_reason'] ?? '',
            'ip_address' => $row['ip_address'] ?? '',
            'created_at' => $row['created_at'] ?? '',
            'before_json' => $row['before_json'] ?? '',
            'after_json' => $row['after_json'] ?? '',
            'detail' => crm_log_center_change_text($row['before_json'] ?? '', $row['after_json'] ?? ''),
        ]);
    }
}

function crm_log_center_collect_audit(array &$rows, ?array $scopeUserIds): void
{
    if (!db_table_exists('crm_audit_logs')) return;
    $params = [];
    $scope = crm_log_center_scope_sql('l', 'user_id', $scopeUserIds, $params);
    $stmt = db()->prepare("SELECT l.* FROM crm_audit_logs l WHERE {$scope} ORDER BY l.id DESC LIMIT 1200");
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = crm_log_center_row([
            'id' => $row['id'] ?? null,
            'log_type' => 'audit',
            'type_label' => '统一审计',
            'source_table' => 'crm_audit_logs',
            'module_key' => $row['module'] ?? '',
            'action_key' => $row['action'] ?? '',
            'operator_name' => $row['operator_name'] ?? '',
            'user_id' => $row['user_id'] ?? null,
            'target_type' => $row['target_type'] ?? '',
            'target_id' => $row['target_id'] ?? '',
            'result_status' => 'success',
            'ip_address' => $row['ip'] ?? '',
            'created_at' => $row['created_at'] ?? '',
            'before_json' => $row['before_json'] ?? '',
            'after_json' => $row['after_json'] ?? '',
            'detail' => crm_log_center_change_text($row['before_json'] ?? '', $row['after_json'] ?? ''),
        ]);
    }
}

function crm_log_center_collect_login(array &$rows, ?array $scopeUserIds): void
{
    $table = db_table_exists('sys_login_logs') ? 'sys_login_logs' : (db_table_exists('crm_login_logs') ? 'crm_login_logs' : '');
    if ($table === '') return;
    $params = [];
    $scope = crm_log_center_scope_sql('l', 'user_id', $scopeUserIds, $params);
    $stmt = db()->prepare("SELECT l.* FROM {$table} l WHERE {$scope} ORDER BY l.id DESC LIMIT 800");
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $status = (string)($row['status'] ?? '');
        $failed = in_array($status, ['fail', 'failed', 'error'], true);
        $rows[] = crm_log_center_row([
            'id' => $row['id'] ?? null,
            'log_type' => 'login',
            'type_label' => '登录日志',
            'source_table' => $table,
            'module_key' => 'login',
            'action_key' => 'login_' . ($status ?: 'record'),
            'operator_name' => $row['username'] ?? '',
            'user_id' => $row['user_id'] ?? null,
            'target_type' => 'account',
            'target_id' => $row['username'] ?? '',
            'result_status' => $failed ? 'failed' : 'success',
            'failure_reason' => $failed ? (string)($row['message'] ?? '') : '',
            'ip_address' => $row['ip'] ?? '',
            'created_at' => $row['created_at'] ?? '',
            'detail' => $row['message'] ?? '',
        ]);
    }
}

function crm_log_center_collect_ai(array &$rows, ?array $scopeUserIds): void
{
    if (!db_table_exists('crm_ai_logs')) return;
    $params = [];
    $scope = crm_log_center_scope_sql('l', 'operator_id', $scopeUserIds, $params);
    $stmt = db()->prepare("SELECT l.*, u.username, u.real_name FROM crm_ai_logs l LEFT JOIN crm_users u ON u.id=l.operator_id WHERE {$scope} ORDER BY l.id DESC LIMIT 800");
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $status = (string)($row['result_status'] ?? 'success');
        $rows[] = crm_log_center_row([
            'id' => $row['id'] ?? null,
            'log_type' => 'ai',
            'type_label' => 'AI 日志',
            'source_table' => 'crm_ai_logs',
            'module_key' => 'ai',
            'action_key' => $row['action_key'] ?? '',
            'operator_name' => ($row['real_name'] ?: $row['username']) ?? '',
            'user_id' => $row['operator_id'] ?? null,
            'target_type' => 'ai_task',
            'target_id' => $row['ai_task_id'] ?? '',
            'result_status' => $status === 'failed' ? 'failed' : 'success',
            'failure_reason' => $row['failure_reason'] ?? '',
            'created_at' => $row['created_at'] ?? '',
            'detail' => crm_log_center_json_summary($row['detail_json'] ?? ''),
        ]);
    }
}

function crm_log_center_collect_marketing(array &$rows, ?array $scopeUserIds): void
{
    if (!db_table_exists('crm_marketing_logs')) return;
    $params = [];
    $scope = crm_log_center_scope_sql('l', 'operator_id', $scopeUserIds, $params);
    $stmt = db()->prepare("SELECT l.*, u.username, u.real_name FROM crm_marketing_logs l LEFT JOIN crm_users u ON u.id=l.operator_id WHERE {$scope} ORDER BY l.id DESC LIMIT 800");
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $status = (string)($row['result_status'] ?? 'success');
        $rows[] = crm_log_center_row([
            'id' => $row['id'] ?? null,
            'log_type' => 'promotion',
            'type_label' => '推广日志',
            'source_table' => 'crm_marketing_logs',
            'module_key' => 'promotion',
            'action_key' => $row['action_key'] ?? '',
            'operator_name' => ($row['real_name'] ?: $row['username']) ?? '',
            'user_id' => $row['operator_id'] ?? null,
            'target_type' => 'marketing_task',
            'target_id' => $row['task_id'] ?? '',
            'result_status' => $status === 'failed' ? 'failed' : 'success',
            'failure_reason' => $row['failure_reason'] ?? '',
            'created_at' => $row['touched_at'] ?: ($row['created_at'] ?? ''),
            'detail' => '渠道：' . (string)($row['channel_key'] ?? '') . '；客户 #' . (string)($row['customer_id'] ?? '') . '；联系人 #' . (string)($row['contact_id'] ?? '') . '；' . crm_log_center_json_summary($row['detail_json'] ?? ''),
        ]);
    }
}

function crm_log_center_collect_mail_sync(array &$rows, ?array $scopeUserIds): void
{
    if (!db_table_exists('crm_mail_sync_logs')) return;
    $params = [];
    $scope = crm_log_center_scope_sql('l', 'user_id', $scopeUserIds, $params);
    $stmt = db()->prepare("SELECT l.*, u.username, u.real_name FROM crm_mail_sync_logs l LEFT JOIN crm_users u ON u.id=l.user_id WHERE {$scope} ORDER BY l.id DESC LIMIT 800");
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $status = (string)($row['status'] ?? 'running');
        $failed = in_array($status, ['failed', 'error'], true);
        $rows[] = crm_log_center_row([
            'id' => $row['id'] ?? null,
            'log_type' => 'mail',
            'type_label' => '邮件日志',
            'source_table' => 'crm_mail_sync_logs',
            'module_key' => 'mail',
            'action_key' => 'mail_sync_' . $status,
            'operator_name' => ($row['real_name'] ?: $row['username']) ?? '',
            'user_id' => $row['user_id'] ?? null,
            'target_type' => 'mail_account',
            'target_id' => $row['mail_account_id'] ?? '',
            'result_status' => $failed ? 'failed' : 'success',
            'failure_reason' => $row['error_message'] ?? '',
            'created_at' => $row['created_at'] ?? ($row['started_at'] ?? ''),
            'detail' => trim((string)($row['message'] ?? '') . ' 新增 ' . (string)($row['new_count'] ?? 0) . '，重复 ' . (string)($row['duplicate_count'] ?? 0) . '，附件 ' . (string)($row['attachment_count'] ?? 0)),
        ]);
    }
}

function crm_log_center_collect_opportunity(array &$rows, ?array $scopeUserIds): void
{
    if (!db_table_exists('crm_opportunity_logs')) return;
    $params = [];
    $scope = crm_log_center_scope_sql('l', 'created_by', $scopeUserIds, $params);
    $stmt = db()->prepare("SELECT l.*, u.username, u.real_name FROM crm_opportunity_logs l LEFT JOIN crm_users u ON u.id=l.created_by WHERE {$scope} ORDER BY l.id DESC LIMIT 800");
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = crm_log_center_row([
            'id' => $row['id'] ?? null,
            'log_type' => 'opportunity',
            'type_label' => '商机日志',
            'source_table' => 'crm_opportunity_logs',
            'module_key' => 'opportunity',
            'action_key' => $row['action_key'] ?? '',
            'operator_name' => ($row['real_name'] ?: $row['username']) ?? '',
            'user_id' => $row['created_by'] ?? null,
            'target_type' => 'opportunity',
            'target_id' => $row['opportunity_id'] ?? '',
            'result_status' => 'success',
            'created_at' => $row['created_at'] ?? '',
            'detail' => crm_log_center_change_text($row['old_value'] ?? '', $row['new_value'] ?? '') . (($row['note'] ?? '') ? '；' . $row['note'] : ''),
        ]);
    }
}

function crm_log_center_row(array $row): array
{
    $row['id'] = (int)($row['id'] ?? 0);
    $row['user_id'] = isset($row['user_id']) ? (int)$row['user_id'] : null;
    $row['module_label'] = crm_log_center_module_label((string)($row['module_key'] ?? ''));
    $row['action_label'] = crm_log_center_action_label((string)($row['action_key'] ?? ''));
    $row['result_text'] = (($row['result_status'] ?? '') === 'failed') ? '失败' : '成功';
    $row['risk_level'] = crm_log_center_risk((string)($row['action_key'] ?? ''), (string)($row['module_key'] ?? ''), (string)($row['result_status'] ?? 'success'));
    $row['detail'] = trim((string)($row['detail'] ?? ''));
    return $row;
}

function crm_log_center_module_label(string $module): string
{
    $map = ['workspace' => '工作台', 'customers' => '客户', 'customer' => '客户', 'contact' => '联系人', 'mail' => '邮箱', 'promotion' => '推广', 'marketing' => '推广', 'tasks' => '任务', 'visit' => '拜访/来访', 'opportunity' => '商机', 'settings' => '设置', 'permission' => '权限', 'ai' => 'AI', 'login' => '登录', 'dispatch' => '派工', 'quote' => '报价', 'bom' => 'BOM', 'plm' => 'PLM'];
    return $map[$module] ?? ($module ?: '系统');
}

function crm_log_center_action_label(string $action): string
{
    $map = [
        'save_preferences' => '保存界面偏好',
        'company_settings_save' => '保存企业信息',
        'module_switch' => '切换模块',
        'action_click' => '点击操作',
        'login_success' => '登录成功',
        'login_fail' => '登录失败',
        'mail_sync_success' => '邮件同步成功',
        'mail_sync_failed' => '邮件同步失败',
        'create' => '新建',
        'update' => '修改',
        'delete' => '删除',
        'restore' => '恢复',
        'export' => '导出',
        'import' => '导入',
        'confirm' => '确认',
        'reject' => '驳回',
    ];
    if (isset($map[$action])) return $map[$action];
    $label = str_replace(['_', '-'], ' ', $action);
    return trim($label) ?: '操作';
}

function crm_log_center_risk(string $action, string $module, string $status): string
{
    $text = strtolower($action . ' ' . $module);
    if ($status === 'failed') return 'danger';
    if (preg_match('/delete|force|permission|role|backup|restore|export|security|blacklist|forbid|disable|danger/', $text)) return 'danger';
    if (preg_match('/edit|update|save|create|import|assign|confirm|approve|sync|send/', $text)) return 'sensitive';
    return 'normal';
}

function crm_log_center_change_text($before, $after): string
{
    $beforeText = crm_log_center_json_summary($before);
    $afterText = crm_log_center_json_summary($after);
    if ($beforeText !== '' && $afterText !== '') return '前：' . $beforeText . '；后：' . $afterText;
    if ($afterText !== '') return '后：' . $afterText;
    if ($beforeText !== '') return '前：' . $beforeText;
    return '';
}

function crm_log_center_json_summary($value): string
{
    if (is_array($value)) $data = $value;
    else {
        $text = trim((string)$value);
        if ($text === '') return '';
        $data = json_decode($text, true);
        if (!is_array($data)) return mb_substr($text, 0, 260);
    }
    $parts = [];
    foreach ($data as $key => $val) {
        if (is_array($val)) $val = json_encode($val, JSON_UNESCAPED_UNICODE);
        $parts[] = crm_log_center_field_label((string)$key) . '：' . mb_substr((string)$val, 0, 80);
        if (count($parts) >= 8) break;
    }
    return implode('；', $parts);
}

function crm_log_center_field_label(string $field): string
{
    $map = [
        'customer_id' => '客户ID',
        'customer_name' => '客户名称',
        'contact_id' => '联系人ID',
        'group_name' => '客户群',
        'group_platform' => '群平台',
        'group_owner' => '群负责人',
        'use_for_promotion' => '用于推广',
        'status' => '状态',
        'remark' => '备注',
        'title' => '标题',
        'name' => '名称',
        'email' => '邮箱',
        'phone' => '电话',
        'owner_user_id' => '负责人',
        'stage' => '阶段',
        'old_value' => '旧值',
        'new_value' => '新值',
    ];
    return $map[$field] ?? $field;
}

function crm_log_center_filter_rows(array $rows, array $input, ?array $scopeUserIds): array
{
    $type = trim((string)($input['log_type'] ?? 'all')) ?: 'all';
    $module = trim((string)($input['module'] ?? ''));
    $result = trim((string)($input['result'] ?? ''));
    $keyword = mb_strtolower(trim((string)($input['keyword'] ?? ($input['q'] ?? ''))));
    $dateFrom = trim((string)($input['date_from'] ?? ''));
    $dateTo = trim((string)($input['date_to'] ?? ''));
    $userId = (int)($input['user_id'] ?? 0);

    return array_values(array_filter($rows, function ($row) use ($type, $module, $result, $keyword, $dateFrom, $dateTo, $userId, $scopeUserIds) {
        if ($userId > 0) {
            if (is_array($scopeUserIds) && !in_array($userId, $scopeUserIds, true)) return false;
            if ((int)($row['user_id'] ?? 0) !== $userId) return false;
        }
        if ($type !== 'all' && !crm_log_center_type_match($row, $type)) return false;
        if ($module !== '' && (string)($row['module_key'] ?? '') !== $module) return false;
        if ($result !== '' && (string)($row['result_status'] ?? '') !== $result) return false;
        $created = (string)($row['created_at'] ?? '');
        if ($dateFrom !== '' && substr($created, 0, 10) < $dateFrom) return false;
        if ($dateTo !== '' && substr($created, 0, 10) > $dateTo) return false;
        if ($keyword !== '') {
            $haystack = mb_strtolower(implode(' ', [
                $row['type_label'] ?? '', $row['module_label'] ?? '', $row['module_key'] ?? '', $row['action_label'] ?? '',
                $row['action_key'] ?? '', $row['operator_name'] ?? '', $row['target_type'] ?? '', $row['target_id'] ?? '',
                $row['failure_reason'] ?? '', $row['detail'] ?? '', $row['ip_address'] ?? '',
            ]));
            if (mb_strpos($haystack, $keyword) === false) return false;
        }
        return true;
    }));
}

function crm_log_center_type_match(array $row, string $type): bool
{
    $logType = (string)($row['log_type'] ?? '');
    $module = (string)($row['module_key'] ?? '');
    if ($type === $logType) return true;
    if ($type === 'security') return (string)($row['risk_level'] ?? '') === 'danger' || (string)($row['result_status'] ?? '') === 'failed';
    if ($type === 'customer') return in_array($module, ['customer', 'customers', 'contact', 'chat_group'], true);
    if ($type === 'task') return in_array($module, ['tasks', 'follow', 'visit', 'dispatch'], true);
    if ($type === 'settings') return in_array($module, ['settings', 'permission', 'crm'], true);
    return false;
}

function crm_log_center_kpis(array $rows): array
{
    $today = date('Y-m-d');
    $kpis = crm_log_center_empty_kpis();
    $kpis['total'] = count($rows);
    foreach ($rows as $row) {
        if (substr((string)($row['created_at'] ?? ''), 0, 10) === $today) $kpis['today']++;
        if (($row['result_status'] ?? '') === 'failed') $kpis['failed']++; else $kpis['success']++;
        if (($row['risk_level'] ?? '') === 'danger') $kpis['danger']++;
        if (($row['log_type'] ?? '') === 'login') $kpis['login']++;
        if (in_array(($row['log_type'] ?? ''), ['operation', 'audit', 'promotion', 'mail', 'opportunity', 'ai'], true)) $kpis['business']++;
    }
    return $kpis;
}
