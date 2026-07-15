<?php

function app_setting($key, $default = '')
{
    try {
        if (!config_installed() || !db_table_exists('crm_system_settings')) return $default;
        $stmt = db()->prepare('SELECT setting_value FROM crm_system_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false || $value === null || $value === '' ? $default : $value;
    } catch (Throwable $e) {
        return $default;
    }
}

function app_settings(array $defaults)
{
    $settings = $defaults;
    try {
        if (!config_installed() || !db_table_exists('crm_system_settings')) return $settings;
        $keys = array_keys($defaults);
        if (!$keys) return $settings;
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = db()->prepare("SELECT setting_key, setting_value FROM crm_system_settings WHERE setting_key IN ({$placeholders})");
        $stmt->execute($keys);
        foreach ($stmt->fetchAll() as $row) {
            if ($row['setting_value'] !== null && $row['setting_value'] !== '') {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        }
    } catch (Throwable $e) {
        return $settings;
    }
    return $settings;
}

function save_app_setting($key, $value, $group = 'system', $description = '')
{
    $user = function_exists('current_user') ? current_user() : null;
    db()->prepare('INSERT INTO crm_system_settings (setting_key, setting_value, setting_group, description, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), setting_group = VALUES(setting_group), description = VALUES(description), updated_by = VALUES(updated_by), updated_at = NOW()')
        ->execute([$key, $value, $group, $description, $user['id'] ?? null]);
}

function save_json_setting($key, array $value, $group, $description, $auditAction)
{
    $before = json_decode((string)app_setting($key, '{}'), true) ?: [];
    save_app_setting($key, json_encode($value, JSON_UNESCAPED_UNICODE), $group, $description);
    audit_log($auditAction, 'settings', 'setting', $key, $before, $value);
    if (function_exists('sys_action_log')) {
        sys_action_log('settings', $auditAction, 'setting', $key, $before, $value, $description, 'sensitive');
    }
}

function settings_default_company(): array
{
    $config = db_config();
    return [
        'system_name' => $config['system_name'] ?? 'Artdon Office V20',
        'company_name' => '中山雅大光电有限公司',
        'company_name_en' => 'Artdon Lighting Limited',
        'portal_subtitle' => '统一进入 CRM、邮箱、客户、推广、报价、PLM、BOM、派工、财务和系统管理。',
        'company_short_name' => 'Artdon',
        'company_address' => '',
        'company_phone' => '',
        'company_email' => '',
        'company_website' => '',
        'default_country' => 'CN',
        'default_language' => 'zh-CN',
        'default_currency' => 'USD',
        'company_logo' => '',
        'login_logo' => '',
        'topbar_logo' => '',
    ];
}

function get_company_settings(): array
{
    $defaults = settings_default_company();
    $settings = app_settings($defaults);
    foreach ($defaults as $key => $value) {
        if (($settings[$key] ?? '') === '') $settings[$key] = $value;
    }
    return $settings;
}

function save_company_settings(array $input): array
{
    $before = get_company_settings();
    $next = [
        'system_name' => trim((string)($input['system_name'] ?? '')),
        'company_name' => trim((string)($input['company_name'] ?? '')),
        'company_name_en' => trim((string)($input['company_name_en'] ?? '')),
        'portal_subtitle' => trim((string)($input['portal_subtitle'] ?? '')),
        'company_short_name' => trim((string)($input['company_short_name'] ?? '')),
        'company_address' => trim((string)($input['company_address'] ?? '')),
        'company_phone' => trim((string)($input['company_phone'] ?? '')),
        'company_email' => trim((string)($input['company_email'] ?? '')),
        'company_website' => trim((string)($input['company_website'] ?? '')),
        'default_country' => trim((string)($input['default_country'] ?? 'CN')),
        'default_language' => trim((string)($input['default_language'] ?? 'zh-CN')),
        'default_currency' => trim((string)($input['default_currency'] ?? 'USD')),
        'company_logo' => trim((string)($input['company_logo'] ?? '')),
        'login_logo' => trim((string)($input['login_logo'] ?? '')),
        'topbar_logo' => trim((string)($input['topbar_logo'] ?? '')),
    ];
    if ($next['system_name'] === '' || $next['company_name'] === '') {
        throw new RuntimeException('系统名称和公司中文名不能为空。');
    }
    save_app_setting('system_name', $next['system_name'], 'company', '系统显示名称');
    save_app_setting('company_name', $next['company_name'], 'company', '公司中文名');
    save_app_setting('company_name_en', $next['company_name_en'], 'company', '公司英文名');
    save_app_setting('portal_subtitle', $next['portal_subtitle'], 'company', '首页副标题');
    foreach (['company_short_name','company_address','company_phone','company_email','company_website','default_country','default_language','default_currency','company_logo','login_logo','topbar_logo'] as $key) {
        save_app_setting($key, $next[$key], 'company', '企业信息');
    }
    audit_log('save_company_settings', 'settings', 'company', 'company_profile', $before, $next);
    if (function_exists('sys_action_log')) {
        sys_action_log('settings', 'save_company_settings', 'company', 'company_profile', $before, $next, '保存公司信息', 'sensitive');
    }
    return $next;
}

function crm_default_module_settings(): array
{
    return [
        'mail_settings' => [
            'auto_sync_minutes' => 5,
            'force_recent_days' => 3,
            'auto_fill_list' => 1,
            'attachment_top' => 1,
            'max_attachment_mb' => 100,
            'auto_link_customer' => 1,
            'unknown_mail_to_ai' => 1,
            'show_folder_counts' => 1,
            'signature_image_enabled' => 1,
            'signature_text_color' => '#111827',
        ],
        'promotion_settings' => [
            'contact_strategy_priority' => 1,
            'cooldown_days' => 7,
            'cooldown_enabled' => 1,
            'skip_blacklist' => 1,
            'skip_no_promotion' => 1,
            'dedupe_email' => 1,
            'require_preview' => 1,
            'allow_test_bypass_cooldown' => 1,
            'default_channels' => ['email', 'wechat', 'whatsapp'],
        ],
        'opportunity_settings' => [
            'default_currency' => 'USD',
            'auto_probability_by_stage' => 1,
            'require_lost_reason' => 1,
            'require_won_amount' => 1,
            'auto_create_followup' => 1,
            'forecast_formula' => 'expected_amount * probability / 100',
            'stage_probabilities' => [
                'new_need' => 10,
                'need_confirm' => 20,
                'quoted' => 40,
                'sample' => 50,
                'technical' => 55,
                'negotiation' => 65,
                'waiting_confirm' => 80,
                'won' => 100,
                'lost' => 0,
            ],
        ],
        'ai_settings' => [
            'lead_enabled' => 1,
            'mail_recognition' => 1,
            'promotion_reply_recognition' => 1,
            'auto_deduplicate' => 1,
            'auto_create_draft' => 1,
            'auto_create_confirm_task' => 1,
            'lead_min_confidence' => 70,
            'quote_enabled' => 1,
            'quote_min_confidence' => 75,
            'material_enabled' => 1,
            'material_min_confidence' => 70,
            'allow_auto_send_mail' => 0,
            'allow_auto_send_quote' => 0,
            'allow_auto_send_material' => 0,
            'require_human_confirm' => 1,
        ],
        'task_settings' => [
            'followup_offsets' => [1, 3, 7, 15, 30, 60],
            'visit_remind_before_hours' => 24,
            'arrival_remind_before_hours' => 24,
            'overdue_result_hours' => 24,
            'auto_notification' => 1,
            'dispatch_interface_enabled' => 0,
            'task_statuses' => ['draft', 'pending_confirm', 'confirmed', 'pending', 'doing', 'done', 'cancelled', 'overdue_no_result'],
        ],
        'field_settings' => [
            'customer_visible_fields' => ['customer_code', 'customer_name', 'country', 'owner', 'level', 'email', 'phone'],
            'customer_required_fields' => ['customer_name', 'country'],
            'contact_visible_fields' => ['is_primary', 'name', 'email', 'phone', 'whatsapp', 'promotion_channels'],
            'opportunity_visible_fields' => ['opportunity_name', 'customer', 'stage', 'expected_amount', 'probability', 'forecast_amount', 'owner'],
        ],
    ];
}

function crm_module_settings_all(): array
{
    $defaults = crm_default_module_settings();
    $saved = json_decode((string)app_setting('crm_module_settings_json', '{}'), true) ?: [];
    return array_replace_recursive($defaults, $saved);
}

function crm_module_setting(string $section): array
{
    $all = crm_module_settings_all();
    return $all[$section] ?? [];
}

function crm_save_module_setting(string $section, array $input): array
{
    $defaults = crm_default_module_settings();
    if (!isset($defaults[$section])) {
        throw new RuntimeException('未知设置模块：' . $section);
    }
    $beforeAll = crm_module_settings_all();
    $before = $beforeAll[$section] ?? $defaults[$section];
    $next = crm_normalize_module_setting($section, $input, $defaults[$section]);
    $all = $beforeAll;
    $all[$section] = $next;
    save_app_setting('crm_module_settings_json', json_encode($all, JSON_UNESCAPED_UNICODE), 'crm_module', 'CRM 模块设置');
    audit_log('save_' . $section, 'settings', 'module_setting', $section, $before, $next);
    if (function_exists('sys_action_log')) {
        sys_action_log('settings', 'save_' . $section, 'module_setting', $section, $before, $next, '保存 CRM 模块设置', 'sensitive');
    }
    return $next;
}

function crm_normalize_module_setting(string $section, array $input, array $default): array
{
    $bool = fn($key) => normalize_bool($input[$key] ?? 0);
    $int = fn($key, $min, $max, $fallback) => max($min, min($max, (int)($input[$key] ?? $fallback)));
    if ($section === 'mail_settings') {
        return [
            'auto_sync_minutes' => $int('auto_sync_minutes', 1, 120, $default['auto_sync_minutes']),
            'force_recent_days' => $int('force_recent_days', 1, 30, $default['force_recent_days']),
            'auto_fill_list' => $bool('auto_fill_list'),
            'attachment_top' => $bool('attachment_top'),
            'max_attachment_mb' => $int('max_attachment_mb', 1, 200, $default['max_attachment_mb']),
            'auto_link_customer' => $bool('auto_link_customer'),
            'unknown_mail_to_ai' => $bool('unknown_mail_to_ai'),
            'show_folder_counts' => $bool('show_folder_counts'),
            'signature_image_enabled' => $bool('signature_image_enabled'),
            'signature_text_color' => preg_match('/^#[0-9a-f]{6}$/i', (string)($input['signature_text_color'] ?? '')) ? $input['signature_text_color'] : $default['signature_text_color'],
        ];
    }
    if ($section === 'promotion_settings') {
        $channels = $input['default_channels'] ?? [];
        if (!is_array($channels)) $channels = array_filter(array_map('trim', explode(',', (string)$channels)));
        return [
            'contact_strategy_priority' => $bool('contact_strategy_priority'),
            'cooldown_days' => $int('cooldown_days', 0, 365, $default['cooldown_days']),
            'cooldown_enabled' => $bool('cooldown_enabled'),
            'skip_blacklist' => $bool('skip_blacklist'),
            'skip_no_promotion' => $bool('skip_no_promotion'),
            'dedupe_email' => $bool('dedupe_email'),
            'require_preview' => $bool('require_preview'),
            'allow_test_bypass_cooldown' => $bool('allow_test_bypass_cooldown'),
            'default_channels' => array_values(array_unique(array_map(fn($v) => preg_replace('/[^a-z0-9_\\-]/i', '', (string)$v), $channels))),
        ];
    }
    if ($section === 'opportunity_settings') {
        $stage = [];
        foreach (($input['stage_probability'] ?? []) as $key => $value) {
            $key = preg_replace('/[^a-z0-9_\\-]/i', '', (string)$key);
            if ($key !== '') $stage[$key] = max(0, min(100, (int)$value));
        }
        return [
            'default_currency' => strtoupper(substr(preg_replace('/[^a-z]/i', '', (string)($input['default_currency'] ?? $default['default_currency'])), 0, 3)) ?: 'USD',
            'auto_probability_by_stage' => $bool('auto_probability_by_stage'),
            'require_lost_reason' => $bool('require_lost_reason'),
            'require_won_amount' => $bool('require_won_amount'),
            'auto_create_followup' => $bool('auto_create_followup'),
            'forecast_formula' => 'expected_amount * probability / 100',
            'stage_probabilities' => $stage ?: $default['stage_probabilities'],
        ];
    }
    if ($section === 'ai_settings') {
        return [
            'lead_enabled' => $bool('lead_enabled'),
            'mail_recognition' => $bool('mail_recognition'),
            'promotion_reply_recognition' => $bool('promotion_reply_recognition'),
            'auto_deduplicate' => $bool('auto_deduplicate'),
            'auto_create_draft' => $bool('auto_create_draft'),
            'auto_create_confirm_task' => $bool('auto_create_confirm_task'),
            'lead_min_confidence' => $int('lead_min_confidence', 0, 100, $default['lead_min_confidence']),
            'quote_enabled' => $bool('quote_enabled'),
            'quote_min_confidence' => $int('quote_min_confidence', 0, 100, $default['quote_min_confidence']),
            'material_enabled' => $bool('material_enabled'),
            'material_min_confidence' => $int('material_min_confidence', 0, 100, $default['material_min_confidence']),
            'allow_auto_send_mail' => 0,
            'allow_auto_send_quote' => 0,
            'allow_auto_send_material' => 0,
            'require_human_confirm' => 1,
        ];
    }
    if ($section === 'task_settings') {
        $offsets = $input['followup_offsets'] ?? [];
        if (!is_array($offsets)) $offsets = array_filter(array_map('trim', explode(',', (string)$offsets)));
        $offsets = array_values(array_unique(array_filter(array_map('intval', $offsets), fn($v) => $v > 0 && $v <= 365)));
        return [
            'followup_offsets' => $offsets ?: $default['followup_offsets'],
            'visit_remind_before_hours' => $int('visit_remind_before_hours', 0, 720, $default['visit_remind_before_hours']),
            'arrival_remind_before_hours' => $int('arrival_remind_before_hours', 0, 720, $default['arrival_remind_before_hours']),
            'overdue_result_hours' => $int('overdue_result_hours', 1, 720, $default['overdue_result_hours']),
            'auto_notification' => $bool('auto_notification'),
            'dispatch_interface_enabled' => $bool('dispatch_interface_enabled'),
            'task_statuses' => $default['task_statuses'],
        ];
    }
    if ($section === 'field_settings') {
        $parse = function ($key, $fallback) use ($input) {
            $value = $input[$key] ?? $fallback;
            if (!is_array($value)) $value = array_filter(array_map('trim', explode(',', (string)$value)));
            return array_values(array_unique(array_filter(array_map(fn($v) => preg_replace('/[^a-z0-9_\\-]/i', '', (string)$v), $value))));
        };
        return [
            'customer_visible_fields' => $parse('customer_visible_fields', $default['customer_visible_fields']),
            'customer_required_fields' => $parse('customer_required_fields', $default['customer_required_fields']),
            'contact_visible_fields' => $parse('contact_visible_fields', $default['contact_visible_fields']),
            'opportunity_visible_fields' => $parse('opportunity_visible_fields', $default['opportunity_visible_fields']),
        ];
    }
    return array_replace_recursive($default, $input);
}

function settings_default_world_clocks(): array
{
    return [
        ['label' => 'India', 'timezone' => 'Asia/Kolkata', 'emoji' => '🇮🇳', 'sort' => 10],
        ['label' => 'Korea', 'timezone' => 'Asia/Seoul', 'emoji' => '🇰🇷', 'sort' => 20],
        ['label' => 'Dubai', 'timezone' => 'Asia/Dubai', 'emoji' => '🇦🇪', 'sort' => 30],
        ['label' => 'Saudi Arabia', 'timezone' => 'Asia/Riyadh', 'emoji' => '🇸🇦', 'sort' => 40],
        ['label' => 'Germany', 'timezone' => 'Europe/Berlin', 'emoji' => '🇩🇪', 'sort' => 50],
    ];
}

function get_ui_settings(): array
{
    $defaults = [
        'theme' => 'office-light',
        'density' => 'standard',
        'portal_view' => 'cards',
        'show_world_time' => 1,
        'show_system_stats' => 1,
        'show_module_status' => 1,
        'show_background_art' => 1,
        'world_clocks' => settings_default_world_clocks(),
    ];
    $saved = json_decode((string)app_setting('ui_settings', '{}'), true) ?: [];
    $settings = array_replace($defaults, $saved);
    if (!is_array($settings['world_clocks'] ?? null)) {
        $settings['world_clocks'] = settings_default_world_clocks();
    }
    usort($settings['world_clocks'], fn($a, $b) => (int)($a['sort'] ?? 0) <=> (int)($b['sort'] ?? 0));
    return $settings;
}

function normalize_bool($value): int
{
    return in_array((string)$value, ['1', 'on', 'yes', 'true'], true) ? 1 : 0;
}

function save_ui_settings(array $input): array
{
    $allowedThemes = ['office-light', 'ocean-blue', 'graphite', 'mint', 'warm-sand', 'dark'];
    $allowedDensity = ['comfortable', 'standard', 'compact'];
    $rows = [];
    $labels = $input['clock_label'] ?? [];
    $zones = $input['clock_timezone'] ?? [];
    $emojis = $input['clock_emoji'] ?? [];
    $sorts = $input['clock_sort'] ?? [];
    foreach ($labels as $i => $label) {
        $label = trim((string)$label);
        $zone = trim((string)($zones[$i] ?? ''));
        if ($label === '' || $zone === '') continue;
        $rows[] = [
            'label' => substr($label, 0, 60),
            'timezone' => substr($zone, 0, 80),
            'emoji' => substr(trim((string)($emojis[$i] ?? '')), 0, 12),
            'sort' => (int)($sorts[$i] ?? (($i + 1) * 10)),
        ];
    }
    $next = [
        'theme' => in_array($input['theme'] ?? '', $allowedThemes, true) ? $input['theme'] : 'office-light',
        'density' => in_array($input['density'] ?? '', $allowedDensity, true) ? $input['density'] : 'standard',
        'portal_view' => ($input['portal_view'] ?? '') === 'list' ? 'list' : 'cards',
        'show_world_time' => normalize_bool($input['show_world_time'] ?? 0),
        'show_system_stats' => normalize_bool($input['show_system_stats'] ?? 0),
        'show_module_status' => normalize_bool($input['show_module_status'] ?? 0),
        'show_background_art' => normalize_bool($input['show_background_art'] ?? 0),
        'world_clocks' => $rows ?: settings_default_world_clocks(),
    ];
    save_json_setting('ui_settings', $next, 'ui', '界面与主题默认设置', 'save_ui_settings');
    return $next;
}

function get_security_settings(): array
{
    $defaults = [
        'login_lock_threshold' => 5,
        'login_lock_minutes' => 15,
        'password_min_length' => 8,
        'require_strong_password' => 1,
        'danger_confirm' => 1,
        'permission_change_confirm' => 1,
        'session_timeout_minutes' => 120,
        'block_unapproved_login' => 1,
        'lock_installer_after_install' => 1,
    ];
    $saved = json_decode((string)app_setting('security_settings', '{}'), true) ?: [];
    return array_replace($defaults, $saved);
}

function save_security_settings(array $input): array
{
    $next = [
        'login_lock_threshold' => max(1, min(20, (int)($input['login_lock_threshold'] ?? 5))),
        'login_lock_minutes' => max(1, min(1440, (int)($input['login_lock_minutes'] ?? 15))),
        'password_min_length' => max(6, min(32, (int)($input['password_min_length'] ?? 8))),
        'require_strong_password' => normalize_bool($input['require_strong_password'] ?? 0),
        'danger_confirm' => normalize_bool($input['danger_confirm'] ?? 0),
        'permission_change_confirm' => normalize_bool($input['permission_change_confirm'] ?? 0),
        'session_timeout_minutes' => max(10, min(1440, (int)($input['session_timeout_minutes'] ?? 120))),
        'block_unapproved_login' => normalize_bool($input['block_unapproved_login'] ?? 0),
        'lock_installer_after_install' => normalize_bool($input['lock_installer_after_install'] ?? 0),
    ];
    save_json_setting('security_settings', $next, 'security', '安全策略设置', 'save_security_settings');
    save_app_setting('login_lock_threshold', (string)$next['login_lock_threshold'], 'security', '连续失败锁定阈值');
    save_app_setting('login_lock_minutes', (string)$next['login_lock_minutes'], 'security', '临时锁定分钟数');
    return $next;
}

function get_notification_settings(): array
{
    $defaults = [];
    foreach (['register_review','permission_request','approval_result','temporary_expire','danger_operation','backup_done','schema_repair_done','abnormal_login'] as $key) {
        $defaults[$key] = ['site' => 1, 'popup' => 0, 'badge' => 1, 'sound' => 0, 'advance_hours' => $key === 'temporary_expire' ? 24 : 0];
    }
    $saved = json_decode((string)app_setting('notification_settings', '{}'), true) ?: [];
    return array_replace_recursive($defaults, $saved);
}

function save_notification_settings(array $input): array
{
    $next = [];
    foreach (get_notification_settings() as $key => $row) {
        $next[$key] = [
            'site' => normalize_bool($input[$key . '_site'] ?? 0),
            'popup' => normalize_bool($input[$key . '_popup'] ?? 0),
            'badge' => normalize_bool($input[$key . '_badge'] ?? 0),
            'sound' => normalize_bool($input[$key . '_sound'] ?? 0),
            'advance_hours' => max(0, min(720, (int)($input[$key . '_advance_hours'] ?? 0))),
        ];
    }
    save_json_setting('notification_settings', $next, 'notification', '通知策略设置', 'save_notification_settings');
    return $next;
}

function get_log_settings(): array
{
    $defaults = [
        'login_retention_days' => 180,
        'action_retention_days' => 365,
        'keep_danger_forever' => 1,
        'homepage_log_enabled' => 1,
        'module_click_log_enabled' => 1,
        'settings_change_log_enabled' => 1,
        'permission_change_log_enabled' => 1,
    ];
    $saved = json_decode((string)app_setting('log_settings', '{}'), true) ?: [];
    return array_replace($defaults, $saved);
}

function save_log_settings(array $input): array
{
    $next = [
        'login_retention_days' => max(7, min(3650, (int)($input['login_retention_days'] ?? 180))),
        'action_retention_days' => max(7, min(3650, (int)($input['action_retention_days'] ?? 365))),
        'keep_danger_forever' => normalize_bool($input['keep_danger_forever'] ?? 0),
        'homepage_log_enabled' => normalize_bool($input['homepage_log_enabled'] ?? 0),
        'module_click_log_enabled' => normalize_bool($input['module_click_log_enabled'] ?? 0),
        'settings_change_log_enabled' => normalize_bool($input['settings_change_log_enabled'] ?? 0),
        'permission_change_log_enabled' => normalize_bool($input['permission_change_log_enabled'] ?? 0),
    ];
    save_json_setting('log_settings', $next, 'logs', '日志保留策略', 'save_log_settings');
    return $next;
}

function get_system_overview(): array
{
    $config = db_config();
    try {
        $mysqlVersion = (string)db()->query('SELECT VERSION()')->fetchColumn();
        $dbName = (string)db()->query('SELECT DATABASE()')->fetchColumn();
    } catch (Throwable $e) {
        $mysqlVersion = 'unknown';
        $dbName = $config['db']['name'] ?? '';
    }
    $root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
    $storage = $root . '/storage';
    $backups = $storage . '/backups';
    return [
        'system_name' => get_company_settings()['system_name'],
        'version' => 'V18',
        'installed' => config_installed(),
        'php_version' => PHP_VERSION,
        'mysql_version' => $mysqlVersion,
        'database_name' => $dbName,
        'server_time' => date('Y-m-d H:i:s'),
        'site_root' => $root,
        'root_writable' => is_writable($root),
        'storage_path' => $storage,
        'storage_exists' => is_dir($storage),
        'storage_writable' => is_dir($storage) && is_writable($storage),
        'backup_path' => $backups,
        'backup_writable' => is_dir($backups) && is_writable($backups),
        'runtime_writable' => is_dir($storage) && is_writable($storage) && is_dir($backups) && is_writable($backups),
        'config_exists' => file_exists(__DIR__ . '/config.php'),
        'install_lock_exists' => file_exists(__DIR__ . '/install.lock'),
    ];
}

function scan_directory_size($path): array
{
    $result = ['size' => 0, 'files' => 0, 'updated_at' => null];
    if (!is_dir($path) && !is_file($path)) return $result;
    $excluded = ['.git', '.DS_Store', '__MACOSX', 'install.lock'];
    if (is_file($path)) {
        return ['size' => filesize($path) ?: 0, 'files' => 1, 'updated_at' => date('Y-m-d H:i:s', filemtime($path) ?: time())];
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            function ($current) use ($excluded) {
                return !in_array($current->getFilename(), $excluded, true);
            }
        ),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $file) {
        if (!$file->isFile()) continue;
        $result['files']++;
        $result['size'] += $file->getSize();
        $mtime = $file->getMTime();
        if (!$result['updated_at'] || $mtime > strtotime($result['updated_at'])) {
            $result['updated_at'] = date('Y-m-d H:i:s', $mtime);
        }
    }
    return $result;
}

function table_size_bytes(array $tables): int
{
    $tables = array_values(array_filter($tables, 'db_table_exists'));
    if (!$tables) return 0;
    $placeholders = implode(',', array_fill(0, count($tables), '?'));
    $stmt = db()->prepare("SELECT COALESCE(SUM(data_length + index_length),0) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ({$placeholders})");
    $stmt->execute($tables);
    return (int)$stmt->fetchColumn();
}

function get_database_size(): int
{
    $stmt = db()->query('SELECT COALESCE(SUM(data_length + index_length),0) FROM information_schema.tables WHERE table_schema = DATABASE()');
    return (int)$stmt->fetchColumn();
}

function settings_storage_snapshot(bool $refresh = false): array
{
    $cached = json_decode((string)app_setting('storage_snapshot_json', ''), true);
    if (!$refresh && is_array($cached) && !empty($cached['last_scanned_at'])) return $cached;
    $root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
    $backupDir = $root . '/storage/backups';
    $uploadDir = $root . '/storage/uploads';
    $cacheDir = $root . '/storage/cache';
    $tempDir = $root . '/storage/tmp';
    $assetsDir = $root . '/assets';
    $backup = scan_directory_size($backupDir);
    $upload = scan_directory_size($uploadDir);
    $cache = scan_directory_size($cacheDir);
    $temp = scan_directory_size($tempDir);
    $assets = scan_directory_size($assetsDir);
    $site = scan_directory_size($root);
    $databaseSize = get_database_size();
    $logTables = ['crm_login_logs', 'crm_audit_logs', 'sys_action_logs', 'sys_homepage_logs'];
    $logSize = table_size_bytes($logTables);
    $configSize = file_exists(__DIR__ . '/config.php') ? (filesize(__DIR__ . '/config.php') ?: 0) : 0;
    $known = $backup['size'] + $upload['size'] + $cache['size'] + $temp['size'] + $assets['size'] + $configSize;
    $snapshot = [
        'total_disk_space' => @disk_total_space($root) ?: 0,
        'free_disk_space' => @disk_free_space($root) ?: 0,
        'used_disk_space' => max(0, (@disk_total_space($root) ?: 0) - (@disk_free_space($root) ?: 0)),
        'site_total_size' => $site['size'],
        'database_size' => $databaseSize,
        'backup_size' => $backup['size'],
        'log_size' => $logSize,
        'upload_size' => $upload['size'],
        'cache_size' => $cache['size'] + $temp['size'],
        'temp_size' => $temp['size'],
        'assets_size' => $assets['size'],
        'config_size' => $configSize,
        'other_size' => max(0, $site['size'] - $known),
        'file_count' => $site['files'],
        'last_scanned_at' => date('Y-m-d H:i:s'),
        'details' => [
            ['type' => '数据库', 'path' => 'information_schema', 'size' => $databaseSize, 'files' => null, 'updated_at' => date('Y-m-d H:i:s'), 'status' => '已统计'],
            ['type' => '备份', 'path' => 'storage/backups', 'size' => $backup['size'], 'files' => $backup['files'], 'updated_at' => $backup['updated_at'], 'status' => is_dir($backupDir) ? '正常' : '未创建'],
            ['type' => '日志', 'path' => implode(', ', $logTables), 'size' => $logSize, 'files' => null, 'updated_at' => get_log_stats()['recent_log_time'], 'status' => '已统计'],
            ['type' => '上传文件', 'path' => 'storage/uploads', 'size' => $upload['size'], 'files' => $upload['files'], 'updated_at' => $upload['updated_at'], 'status' => is_dir($uploadDir) ? '正常' : '未接入'],
            ['type' => '缓存/临时', 'path' => 'storage/cache, storage/tmp', 'size' => $cache['size'] + $temp['size'], 'files' => $cache['files'] + $temp['files'], 'updated_at' => $cache['updated_at'] ?: $temp['updated_at'], 'status' => (is_dir($cacheDir) || is_dir($tempDir)) ? '正常' : '未接入'],
            ['type' => '系统文件', 'path' => 'assets, includes, php', 'size' => $assets['size'] + $configSize, 'files' => $assets['files'] + ($configSize ? 1 : 0), 'updated_at' => $assets['updated_at'], 'status' => '正常'],
            ['type' => '其他', 'path' => $root, 'size' => max(0, $site['size'] - $known), 'files' => null, 'updated_at' => $site['updated_at'], 'status' => '已统计'],
        ],
    ];
    save_app_setting('storage_snapshot_json', json_encode($snapshot, JSON_UNESCAPED_UNICODE), 'storage', '存储统计缓存');
    if ($refresh) {
        audit_log('refresh_storage_usage', 'settings', 'storage', 'site_root', null, $snapshot);
        if (function_exists('sys_action_log')) sys_action_log('settings', 'refresh_storage_usage', 'storage', 'site_root', null, $snapshot, '刷新存储统计', 'sensitive');
    }
    return $snapshot;
}

function get_storage_usage(): array
{
    return settings_storage_snapshot(false);
}

function refresh_storage_usage(): array
{
    return settings_storage_snapshot(true);
}

function get_storage_breakdown(): array
{
    $s = get_storage_usage();
    return [
        ['label' => '数据库', 'value' => $s['database_size'], 'color' => '#2563eb'],
        ['label' => '备份', 'value' => $s['backup_size'], 'color' => '#7c3aed'],
        ['label' => '日志', 'value' => $s['log_size'], 'color' => '#f97316'],
        ['label' => '上传文件', 'value' => $s['upload_size'], 'color' => '#10b981'],
        ['label' => '缓存/临时', 'value' => $s['cache_size'], 'color' => '#eab308'],
        ['label' => '系统文件', 'value' => $s['assets_size'] + $s['config_size'], 'color' => '#0f766e'],
        ['label' => '其他', 'value' => $s['other_size'], 'color' => '#64748b'],
    ];
}

function get_log_stats(): array
{
    $count = function ($table, $where = '') {
        if (!db_table_exists($table)) return 0;
        return (int)db()->query('SELECT COUNT(*) FROM ' . $table . ($where ? ' WHERE ' . $where : ''))->fetchColumn();
    };
    $recent = null;
    foreach (['crm_login_logs', 'crm_audit_logs', 'sys_action_logs', 'sys_homepage_logs'] as $table) {
        if (!db_table_exists($table)) continue;
        $value = db()->query("SELECT MAX(created_at) FROM {$table}")->fetchColumn();
        if ($value && (!$recent || strtotime($value) > strtotime($recent))) $recent = $value;
    }
    return [
        'login_logs' => $count('crm_login_logs'),
        'action_logs' => db_table_exists('sys_action_logs') ? $count('sys_action_logs') : $count('crm_audit_logs'),
        'permission_logs' => db_table_exists('sys_action_logs') ? $count('sys_action_logs', "module = 'permission' OR action LIKE '%permission%'") : $count('crm_audit_logs', "module = 'permission'"),
        'homepage_logs' => $count('sys_homepage_logs'),
        'danger_logs' => db_table_exists('sys_action_logs') ? $count('sys_action_logs', "risk_level = 'danger'") : 0,
        'log_table_size' => table_size_bytes(['crm_login_logs', 'crm_audit_logs', 'sys_action_logs', 'sys_homepage_logs']),
        'recent_log_time' => $recent ?: '暂无',
        'policy' => get_log_settings(),
    ];
}

function get_backup_stats(): array
{
    $records = db_table_exists('sys_backup_records') ? db()->query('SELECT * FROM sys_backup_records ORDER BY id DESC LIMIT 500')->fetchAll() : [];
    $countType = fn($type) => count(array_filter($records, fn($r) => ($r['backup_type'] ?? '') === $type));
    $schedule = db_table_exists('sys_backup_schedule') ? db()->query('SELECT * FROM sys_backup_schedule ORDER BY id LIMIT 20')->fetchAll() : [];
    $enabled = array_values(array_filter($schedule, fn($r) => (int)$r['enabled'] === 1));
    $recent = $records[0]['created_at'] ?? '暂无';
    return [
        'backup_count' => count($records),
        'backup_size' => array_sum(array_map(fn($r) => (int)($r['file_size'] ?? 0), $records)),
        'recent_backup_time' => $recent,
        'template_count' => $countType('template'),
        'data_count' => $countType('data'),
        'full_count' => $countType('full'),
        'auto_enabled' => count($enabled) > 0,
        'next_run_at' => $enabled ? min(array_filter(array_map(fn($r) => $r['next_run_at'] ?? null, $enabled)) ?: ['待计算']) : '未启用',
        'schedule' => $schedule,
        'status' => db_table_exists('sys_backup_records') ? '已接入' : '未接入',
    ];
}

function save_backup_settings(array $input): array
{
    if (!db_table_exists('sys_backup_schedule')) {
        throw new RuntimeException('自动备份表未初始化，请先执行字段检测 / 一键安全修复。');
    }
    $enabled = normalize_bool($input['backup_enabled'] ?? 0);
    $runTime = preg_match('/^\d{2}:\d{2}$/', (string)($input['run_time'] ?? '02:00')) ? $input['run_time'] : '02:00';
    $retain = max(1, min(120, (int)($input['retain_count'] ?? 14)));
    $types = $input['backup_type'] ?? ['data'];
    db()->beginTransaction();
    try {
        db()->exec('UPDATE sys_backup_schedule SET enabled = 0, updated_at = NOW()');
        foreach ((array)$types as $type) {
            if (!in_array($type, ['template', 'data', 'full'], true)) continue;
            db()->prepare("INSERT INTO sys_backup_schedule (schedule_type, enabled, run_time, retain_count, backup_type, module_scope, updated_by, updated_at) VALUES ('daily', ?, ?, ?, ?, 'all', ?, NOW()) ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), run_time = VALUES(run_time), retain_count = VALUES(retain_count), updated_by = VALUES(updated_by), updated_at = NOW()")
                ->execute([$enabled, $runTime, $retain, $type, current_user()['id'] ?? null]);
        }
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
    audit_log('save_backup_settings', 'settings', 'backup', 'schedule', null, $input);
    if (function_exists('sys_action_log')) sys_action_log('settings', 'save_backup_settings', 'backup', 'schedule', null, $input, '保存自动备份策略', 'sensitive');
    return get_backup_stats();
}

function get_schema_scan(): array
{
    $expected = [
        'crm_users' => ['id', 'username', 'password_hash', 'status'],
        'crm_departments' => ['id', 'name', 'status'],
        'crm_roles' => ['id', 'role_key', 'role_name', 'status'],
        'crm_permissions' => ['id', 'permission_key', 'module', 'action'],
        'crm_system_settings' => ['setting_key', 'setting_value'],
        'crm_login_logs' => ['id', 'username', 'status', 'created_at'],
        'crm_audit_logs' => ['id', 'action', 'module', 'created_at'],
        'crm_permission_requests' => ['id', 'user_id', 'permission_key', 'status'],
        'crm_permission_grants' => ['id', 'user_id', 'permission_key', 'status'],
        'crm_permission_request_logs' => ['id', 'request_id', 'action'],
        'sys_backup_records' => ['id', 'backup_type', 'version_no'],
        'sys_action_logs' => ['id', 'module', 'action', 'risk_level'],
        'sys_homepage_logs' => ['id', 'event_type', 'module_key'],
        'sys_backup_schedule' => ['id', 'schedule_type', 'backup_type'],
    ];
    $missingTables = [];
    $missingFields = [];
    foreach ($expected as $table => $fields) {
        if (!db_table_exists($table)) {
            $missingTables[] = $table;
            $missingFields[$table] = $fields;
            continue;
        }
        $stmt = db()->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?');
        $stmt->execute([$table]);
        $have = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $miss = array_values(array_diff($fields, $have));
        if ($miss) $missingFields[$table] = $miss;
    }
    $scan = [
        'installed_tables' => count($expected) - count($missingTables),
        'missing_tables' => count($missingTables),
        'missing_fields' => array_sum(array_map('count', $missingFields)),
        'missing_indexes' => 0,
        'missing_table_names' => $missingTables,
        'missing_field_map' => $missingFields,
        'last_scanned_at' => date('Y-m-d H:i:s'),
        'last_repaired_at' => app_setting('schema_last_repaired_at', '暂无'),
        'repair_result' => app_setting('schema_last_repair_result', '暂无'),
    ];
    save_app_setting('schema_last_scan_json', json_encode($scan, JSON_UNESCAPED_UNICODE), 'schema', '最近字段检测结果');
    audit_log('schema_scan', 'settings', 'schema', 'v18_base', null, $scan);
    return $scan;
}

function settings_format_bytes($bytes): string
{
    $bytes = max(0, (float)$bytes);
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return ($i === 0 ? (string)(int)$bytes : number_format($bytes, 2)) . ' ' . $units[$i];
}
