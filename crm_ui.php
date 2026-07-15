<?php

function crm_column_exists(string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];
    $stmt = db()->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    $cache[$key] = (int)$stmt->fetchColumn() > 0;
    return $cache[$key];
}

function crm_add_column_if_missing(string $table, string $column, string $definition): void
{
    if (!crm_column_exists($table, $column)) {
        db()->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }
}

function crm_ui_ensure_tables(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    crm_ensure_tables();
    crm_add_column_if_missing('crm_user_preferences', 'font_config_json', 'JSON NULL');
    crm_add_column_if_missing('crm_user_preferences', 'density_config_json', 'JSON NULL');
    crm_add_column_if_missing('crm_user_preferences', 'column_config_json', 'JSON NULL');
    crm_add_column_if_missing('crm_user_preferences', 'layout_config_json', 'JSON NULL');
    crm_add_column_if_missing('crm_user_preferences', 'custom_theme_json', 'JSON NULL');
    crm_add_column_if_missing('crm_user_preferences', 'dashboard_layout_json', 'JSON NULL');
    crm_add_column_if_missing('crm_user_preferences', 'dashboard_widgets_json', 'JSON NULL');
    crm_add_column_if_missing('crm_company_preferences', 'default_font_config_json', 'JSON NULL');
    crm_add_column_if_missing('crm_company_preferences', 'default_density_config_json', 'JSON NULL');
    crm_add_column_if_missing('crm_company_preferences', 'default_dashboard_layout_json', 'JSON NULL');
    crm_add_column_if_missing('crm_company_preferences', 'default_dashboard_widgets_json', 'JSON NULL');
    db()->exec("CREATE TABLE IF NOT EXISTS crm_dashboard_widgets (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        widget_key VARCHAR(120) NOT NULL,
        widget_name VARCHAR(120) NOT NULL,
        widget_category VARCHAR(80) NOT NULL,
        supported_modes VARCHAR(255) NOT NULL,
        default_mode VARCHAR(40) NOT NULL DEFAULT 'card',
        required_permission VARCHAR(120) NULL,
        default_size VARCHAR(20) NOT NULL DEFAULT '2x1',
        config_schema_json JSON NULL,
        is_enabled TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 100,
        UNIQUE KEY uk_crm_widget_key (widget_key),
        KEY idx_crm_widget_category (widget_category),
        KEY idx_crm_widget_enabled (is_enabled)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS crm_user_dashboard_widgets (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        widget_key VARCHAR(120) NOT NULL,
        display_mode VARCHAR(40) NOT NULL DEFAULT 'card',
        position_x INT NOT NULL DEFAULT 0,
        position_y INT NOT NULL DEFAULT 0,
        width INT NOT NULL DEFAULT 2,
        height INT NOT NULL DEFAULT 1,
        filter_config_json JSON NULL,
        refresh_interval INT NOT NULL DEFAULT 300,
        is_visible TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 100,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_user_widget (user_id, widget_key),
        KEY idx_user_widget_user (user_id),
        KEY idx_user_widget_visible (is_visible)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS crm_dashboard_fx_cache (
        id INT AUTO_INCREMENT PRIMARY KEY,
        base_currency VARCHAR(10) NOT NULL,
        rates_json JSON NULL,
        provider VARCHAR(50) DEFAULT NULL,
        rate_date DATE DEFAULT NULL,
        fetched_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_base_currency (base_currency)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS crm_dashboard_weather_cache (
        id INT AUTO_INCREMENT PRIMARY KEY,
        city_key VARCHAR(50) NOT NULL,
        city_name VARCHAR(100) NOT NULL,
        latitude DECIMAL(10,6) NOT NULL,
        longitude DECIMAL(10,6) NOT NULL,
        timezone VARCHAR(80) NOT NULL,
        weather_json JSON NULL,
        fetched_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_city_key (city_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS crm_dashboard_weather_cities (
        id INT AUTO_INCREMENT PRIMARY KEY,
        city_key VARCHAR(50) NOT NULL UNIQUE,
        city_name VARCHAR(100) NOT NULL,
        country_name VARCHAR(100) DEFAULT NULL,
        latitude DECIMAL(10,6) NOT NULL,
        longitude DECIMAL(10,6) NOT NULL,
        timezone VARCHAR(80) NOT NULL,
        sort_order INT DEFAULT 0,
        is_enabled TINYINT DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS crm_dashboard_api_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        provider VARCHAR(80) NOT NULL,
        api_type VARCHAR(50) NOT NULL,
        request_url TEXT NULL,
        response_status VARCHAR(50) DEFAULT NULL,
        error_message TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    crm_dashboard_seed_weather_cities();
    crm_seed_dashboard_widgets();
}

function crm_ui_default_font_config(): array
{
    return [
        'global' => ['base' => 12, 'weight' => 400, 'page_title' => 16, 'section_title' => 13, 'muted' => 11],
        'topbar' => ['globalbar' => 12, 'tabbar' => 13, 'online' => 12, 'account' => 12],
        'table' => ['head' => 12, 'body' => 12, 'row_height' => 28, 'gap' => 8],
        'customers' => ['list' => 12, 'detail' => 12, 'contact' => 12, 'profile_tag' => 11, 'followup' => 12, 'log' => 12],
        'mail' => ['list' => 12, 'subject' => 12, 'summary' => 11, 'body' => 13, 'editor' => 13, 'attachment' => 11],
        'workspace' => ['card_title' => 12, 'metric' => 20, 'chart_title' => 12, 'list' => 12, 'alert' => 12],
        'modal' => ['title' => 14, 'label' => 11, 'input' => 12, 'button' => 12, 'error' => 11],
    ];
}

function crm_ui_default_density_config(): array
{
    $modules = array_keys(crm_modules());
    $config = [];
    foreach ($modules as $module) {
        $config[$module] = ['density' => 'compact', 'row_height' => 28, 'card_gap' => 8, 'card_padding' => 9, 'button_height' => 30, 'input_height' => 30, 'modal_width' => 820, 'modal_height' => 660, 'show_icons' => 1, 'animation' => 'subtle'];
    }
    return $config;
}

function crm_ui_default_column_config(): array
{
    return [
        'customers' => ['widths' => ['customer' => 190, 'country' => 92, 'level' => 70, 'source' => 92, 'owner' => 90], 'order' => ['customer','country','level','source','owner','contact','followup','mail','quote','status','updated'], 'hidden' => [], 'fixed' => ['customer'], 'label_mode' => 'short'],
        'mail' => ['widths' => ['sender' => 130, 'subject' => 280, 'time' => 90], 'order' => ['sender','subject','summary','time'], 'hidden' => [], 'fixed' => ['subject'], 'label_mode' => 'short'],
        'promotion' => ['widths' => [], 'order' => [], 'hidden' => [], 'fixed' => [], 'label_mode' => 'short'],
        'logs' => ['widths' => [], 'order' => [], 'hidden' => [], 'fixed' => [], 'label_mode' => 'short'],
        'linkage' => ['widths' => [], 'order' => [], 'hidden' => [], 'fixed' => [], 'label_mode' => 'short'],
        'materials' => ['widths' => [], 'order' => [], 'hidden' => [], 'fixed' => [], 'label_mode' => 'short'],
    ];
}

function crm_ui_default_layout_config(): array
{
    return [
        'workspace' => ['default_mode' => 'card', 'default_range' => 'month', 'rightbar_visible' => 1, 'rightbar_collapsed' => 0],
        'customers' => ['list_width' => 36, 'detail_width' => 64, 'default_tab' => 'overview', 'page_size' => 50, 'show_contacts' => 1, 'show_linkage' => 1, 'show_materials' => 1, 'show_logs' => 1],
        'mail' => ['list_width' => 320, 'body_width' => 1, 'info_width' => 260, 'show_summary' => 1, 'show_attachments' => 1],
        'materials' => ['source_width' => 35, 'preview_width' => 65, 'show_templates' => 1, 'show_storage' => 1],
    ];
}

function crm_ui_theme_names(): array
{
    return ['compact-light','dark','office-gray','blue-gray','mono','glass','eye-care','high-contrast','deep-tech','soft-light'];
}

function crm_ui_default_custom_theme(): array
{
    return ['primary' => '#2563eb', 'accent' => '#0f766e', 'danger' => '#dc2626', 'success' => '#059669', 'warning' => '#d97706', 'bg' => '#f5f7fb', 'card_bg' => '#ffffff', 'border' => '#dbe3ef', 'hover_bg' => '#eff6ff', 'active_bg' => '#dbeafe'];
}

function crm_widget_catalog(): array
{
    $widgets = [
        ['today_customers','今日新增客户','customer','card,pie,bar,trend','card','customer.view','1x1'],
        ['week_customers','本周新增客户','customer','card,bar,trend','card','customer.view','1x1'],
        ['month_customers','本月新增客户','customer','card,bar,trend','card','customer.view','1x1'],
        ['customer_growth_summary','客户新增','customer','summary,card','summary','customer.view','2x1'],
        ['my_customers','我的客户','customer','card,list','card','customer.view','1x1'],
        ['owner_customers','我负责的客户','customer','card,list,pie','card','customer.view','1x1'],
        ['collab_customers','我协作的客户','customer','card,list','list','customer.view','2x1'],
        ['public_customers','公海客户','customer','card,list','card','customer.view','1x1'],
        ['unassigned_customers','未分配客户','customer','card,list','list','customer.view_all','2x1'],
        ['vip_customers','重点客户','customer','card,list','list','customer.view','2x1'],
        ['cold_customers','冷客户','customer','card,list','card','customer.view','1x1'],
        ['blacklist_customers','黑名单客户','customer','card,list','card','customer.view','1x1'],
        ['no_follow_7','7 天未跟进客户','follow','list,card','list','customer.view','2x1'],
        ['no_follow_15','15 天未跟进客户','follow','list,card','list','customer.view','2x1'],
        ['no_follow_30','30 天未跟进客户','follow','list,card','list','customer.view','2x1'],
        ['customer_level_pie','客户等级分布','customer','pie,bar','pie','customer.view','2x2'],
        ['customer_source_pie','客户来源分布','customer','pie,bar','pie','customer.view','2x2'],
        ['country_rank','国家客户排行','customer','bar,list','bar','customer.view','2x2'],
        ['new_contacts','新增联系人','contact','card,list','card','contact.view','1x1'],
        ['missing_primary_contact','主联系人缺失客户','contact','list,card','list','contact.view','2x1'],
        ['missing_contact_email','邮箱缺失联系人','contact','list,card','list','contact.view','2x1'],
        ['missing_contact_whatsapp','WhatsApp 缺失联系人','contact','list,card','list','contact.view','2x1'],
        ['stopped_contacts','不推广联系人','promotion','list,card','list','contact.view','2x1'],
        ['decision_contacts','决策人联系人','contact','list,card','list','contact.view','2x1'],
        ['today_mail','今日新邮件','mail','card,list,trend','card','mail.view','1x1'],
        ['unread_mail','未读邮件','mail','card,list','list','mail.view','2x1'],
        ['unreplied_mail','未回复邮件','mail','card,list','list','mail.view','2x1'],
        ['mail_reply_rate','邮件回复率','mail','card,trend,progress','progress','mail.view','2x1'],
        ['promotion_pending','待推广客户','promotion','card,list','list','promotion.view','2x1'],
        ['promotion_status_pie','推广方式分布','promotion','pie,bar','pie','promotion.view','2x2'],
        ['today_follow','今日跟进','follow','card,list','list','follow.view','2x1'],
        ['overdue_follow','逾期跟进','follow','card,list','list','follow.view','2x1'],
        ['follow_rate','跟进完成率','follow','progress,trend','progress','follow.view','2x1'],
        ['tasks_today','今日任务','task','card,list','list','task.view','2x1'],
        ['tasks_overdue','逾期任务','task','card,list','list','task.view','2x1'],
        ['today_visits','今日拜访','task','card,list','list','visit.view','2x1'],
        ['today_arrivals','今日来访','task','card,list','list','visit.view','2x1'],
        ['visit_overdue_results','拜访结果待填','task','card,list','list','visit.view','1x1'],
        ['sample_shipments','样品寄送','task','card,list','list','sample.view','2x1'],
        ['sample_signed_followup','签收待跟进','task','card,list','list','sample.view','2x1'],
        ['today_quote','今日报价','quote','card,list','card','quote.view','1x1'],
        ['quote_amount','报价金额统计','quote','card,bar,trend','bar','quote.view','2x2'],
        ['quote_to_order','报价转订单率','quote','progress,trend','progress','quote.view','2x1'],
        ['quote_order_board','报价订单联动','quote','board,list','board','quote.view','3x2'],
        ['plm_projects','客户相关项目','plm','card,list,progress','progress','plm.view','2x1'],
        ['plm_testing','测试中项目','plm','card,list','list','plm.view','2x1'],
        ['bom_cost_alert','成本异常','bom','card,list','list','bom.view_cost','2x1'],
        ['bom_missing','缺料提醒','bom','card,list','list','bom.view_cost','2x1'],
        ['dispatch_today','今日派工','dispatch','card,list','card','dispatch.view','1x1'],
        ['dispatch_overdue','逾期派工','dispatch','card,list','list','dispatch.view','2x1'],
        ['dispatch_rate','派工完成率','dispatch','progress,trend','progress','dispatch.view','2x1'],
        ['material_today','今日生成资料','material','card,list','card','material.view','1x1'],
        ['material_pending','待生成资料','material','card,list','list','material.view','2x1'],
        ['material_storage','资料空间占用','material','pie,progress','pie','material.view_storage','2x2'],
        ['order_receivable','客户应收','order','card,list,bar','bar','finance.view','2x2'],
        ['payment_reminder','回款提醒','order','list,card','list','finance.view','2x1'],
        ['team_sales_compare','员工销售对比','sales','table,list','table','dashboard.view','3x2'],
        ['team_sales_trend','员工销售趋势','sales','bar,table','bar','dashboard.view','3x2'],
        ['customer_order_rank','客户订单排行','customer','table,list','table','customer.view','2x2'],
        ['customer_amount_rank','客户成交金额排行','customer','table,list','table','customer.view','2x2'],
        ['customer_quote_rank','客户报价排行','quote','table,list','table','quote.view','2x2'],
        ['ar_customer_rank','应收客户排行','order','table,list','table','finance.view','2x2'],
        ['team_ar_rank','员工应收责任','order','table,list','table','finance.view','2x2'],
        ['target_completion','本月目标完成率','sales','card,progress','progress','dashboard.view','2x1'],
        ['key_reminders','今日关键提醒','system','list,card','list','dashboard.view','2x1'],
        ['ar_aging','应收账龄分析','order','bar,list','bar','finance.view','2x2'],
        ['sales_rank','业务员排行','sales','list,bar','list','customer.view','2x2'],
        ['silent_customers','沉默客户','customer','list,bar','list','customer.view','2x2'],
        ['high_potential_customers','高潜客户','customer','list,card','list','customer.view','2x2'],
        ['website_inquiry_conversion','官网询盘转化','promotion','bar,card','bar','promotion.view','2x2'],
        ['promotion_effect_summary','推广效果','promotion','bar,card','bar','promotion.view','2x2'],
        ['after_sales_summary','售后/客诉','service','bar,card','bar','dashboard.view','2x2'],
        ['crm_system_logs','CRM 系统日志','system','list','list','logs.view_own','2x2'],
        ['online_count','当前在线人数','system','card,list','card','online.view','1x1'],
        ['online_people','在线员工列表','system','list','list','online.view','2x2'],
        ['recent_logs','最近操作日志','system','list','list','logs.view_own','2x2'],
        ['storage_usage','存储空间占用','system','pie,progress','progress','settings.view','2x1'],
        ['weather_tool','天气','tool','tool,card','tool','dashboard.view','2x1'],
        ['flight_tool','航班','tool','tool,card','tool','dashboard.view','2x1'],
        ['express_tool','快递','tool','tool,card','tool','dashboard.view','2x1'],
        ['exchange_rate','汇率','tool','tool,card','tool','dashboard.view','2x1'],
        ['world_time','世界时间','tool','tool,list','list','dashboard.view','2x1'],
    ];
    return array_map(fn($w) => ['widget_key'=>$w[0], 'widget_name'=>$w[1], 'widget_category'=>$w[2], 'supported_modes'=>$w[3], 'default_mode'=>$w[4], 'required_permission'=>$w[5], 'default_size'=>$w[6], 'sort_order'=>100], $widgets);
}

function crm_seed_dashboard_widgets(): void
{
    $stmt = db()->prepare('INSERT INTO crm_dashboard_widgets (widget_key, widget_name, widget_category, supported_modes, default_mode, required_permission, default_size, config_schema_json, is_enabled, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?) ON DUPLICATE KEY UPDATE widget_name=VALUES(widget_name), widget_category=VALUES(widget_category), supported_modes=VALUES(supported_modes), default_mode=VALUES(default_mode), required_permission=VALUES(required_permission), default_size=VALUES(default_size), is_enabled=1, sort_order=VALUES(sort_order)');
    $i = 10;
    foreach (crm_widget_catalog() as $widget) {
        $stmt->execute([$widget['widget_key'], $widget['widget_name'], $widget['widget_category'], $widget['supported_modes'], $widget['default_mode'], $widget['required_permission'], $widget['default_size'], '{}', $i]);
        $i += 10;
    }
}

function crm_dashboard_role_defaults(array $user): array
{
    $role = $user['role_key'] ?? $user['role_name'] ?? '';
    if (is_super_admin() || stripos($role, 'admin') !== false || stripos($role, '老板') !== false) {
        return ['key_reminders','tasks_today','tasks_overdue','today_visits','today_arrivals','sample_shipments','sample_signed_followup','team_sales_compare','customer_amount_rank','ar_customer_rank','customer_order_rank','customer_quote_rank','quote_order_board','team_ar_rank','ar_aging','silent_customers','customer_level_pie','customer_source_pie','country_rank','quote_amount','quote_to_order','unreplied_mail','online_people','recent_logs','team_sales_trend'];
    }
    if (stripos($role, 'manager') !== false || stripos($role, 'leader') !== false || stripos($role, '主管') !== false || stripos($role, '经理') !== false) {
        return ['tasks_today','tasks_overdue','today_visits','today_arrivals','sample_shipments','sample_signed_followup','week_customers','owner_customers','today_follow','overdue_follow','unreplied_mail','promotion_pending','dispatch_overdue','material_pending','online_people','recent_logs','world_time','express_tool'];
    }
    if (stripos($role, 'sales') !== false || stripos($role, '业务') !== false) {
        return ['tasks_today','today_visits','today_arrivals','sample_shipments','sample_signed_followup','my_customers','today_follow','overdue_follow','unreplied_mail','today_quote','quote_order_board','material_pending','dispatch_today','today_mail','recent_logs','world_time','exchange_rate'];
    }
    if (stripos($role, 'clerk') !== false || stripos($role, '文员') !== false || stripos($role, '跟单') !== false) {
        return ['missing_primary_contact','missing_contact_email','material_pending','today_mail','dispatch_today','recent_logs','express_tool','flight_tool'];
    }
    return ['tasks_today','sample_shipments','today_follow','dispatch_today','collab_customers','material_today','recent_logs','online_count','weather_tool','world_time'];
}

function crm_user_dashboard_widgets(int $userId): array
{
    crm_ui_ensure_tables();
    $stmt = db()->prepare('SELECT uw.*, w.widget_name, w.widget_category, w.supported_modes, w.required_permission, w.default_size FROM crm_user_dashboard_widgets uw JOIN crm_dashboard_widgets w ON w.widget_key = uw.widget_key WHERE uw.user_id = ? AND w.is_enabled = 1 ORDER BY uw.sort_order, uw.id');
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();
    if (!$rows) {
        $user = current_user() ?: [];
        $keys = crm_dashboard_role_defaults($user);
        $catalog = [];
        foreach (crm_widget_catalog() as $widget) $catalog[$widget['widget_key']] = $widget;
        $insert = db()->prepare('INSERT IGNORE INTO crm_user_dashboard_widgets (user_id, widget_key, display_mode, width, height, refresh_interval, is_visible, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 1, ?, NOW(), NOW())');
        $sort = 10;
        foreach ($keys as $key) {
            if (!isset($catalog[$key])) continue;
            [$width, $height] = array_map('intval', explode('x', $catalog[$key]['default_size']));
            $insert->execute([$userId, $key, $catalog[$key]['default_mode'], $width, $height, 300, $sort]);
            $sort += 10;
        }
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll();
    }
    return array_values(array_filter($rows, function ($row) {
        return (int)$row['is_visible'] === 1 && (empty($row['required_permission']) || has_permission($row['required_permission']) || is_super_admin());
    }));
}

function crm_dashboard_hidden_widget_keys(int $userId): array
{
    crm_ui_ensure_tables();
    $stmt = db()->prepare('SELECT uw.widget_key FROM crm_user_dashboard_widgets uw JOIN crm_dashboard_widgets w ON w.widget_key = uw.widget_key WHERE uw.user_id = ? AND uw.is_visible = 0 AND w.is_enabled = 1 ORDER BY uw.sort_order, uw.id');
    $stmt->execute([$userId]);
    return array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []));
}

function crm_dashboard_set_card_visible(int $userId, string $cardId, bool $visible): array
{
    crm_ui_ensure_tables();
    $cardId = preg_replace('/[^a-z0-9_\-]/i', '', $cardId);
    if ($cardId === '') return ['widgets' => crm_user_dashboard_widgets($userId), 'hidden_widgets' => crm_dashboard_hidden_widget_keys($userId)];
    $stmt = db()->prepare('SELECT * FROM crm_dashboard_widgets WHERE widget_key = ? AND is_enabled = 1 LIMIT 1');
    $stmt->execute([$cardId]);
    $meta = $stmt->fetch();
    if (!$meta) return ['widgets' => crm_user_dashboard_widgets($userId), 'hidden_widgets' => crm_dashboard_hidden_widget_keys($userId)];
    [$width, $height] = array_map('intval', explode('x', (string)($meta['default_size'] ?? '2x1')));
    db()->prepare('INSERT INTO crm_user_dashboard_widgets (user_id, widget_key, display_mode, width, height, refresh_interval, is_visible, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 300, ?, 999, NOW(), NOW()) ON DUPLICATE KEY UPDATE is_visible=VALUES(is_visible), updated_at=NOW()')
        ->execute([$userId, $cardId, $meta['default_mode'] ?: 'card', max(1, $width), max(1, $height), $visible ? 1 : 0]);
    crm_log_event('workspace', $visible ? 'show_dashboard_card' : 'hide_dashboard_card', 'dashboard_widget', $cardId, null, ['visible' => $visible ? 1 : 0]);
    return ['widgets' => crm_user_dashboard_widgets($userId), 'hidden_widgets' => crm_dashboard_hidden_widget_keys($userId)];
}

function crm_dashboard_reset_layout(int $userId): array
{
    crm_ui_ensure_tables();
    db()->prepare('DELETE FROM crm_user_dashboard_widgets WHERE user_id = ?')->execute([$userId]);
    crm_log_event('workspace', 'reset_dashboard_layout', 'user', (string)$userId, null, null);
    return ['widgets' => crm_user_dashboard_widgets($userId), 'hidden_widgets' => []];
}

function crm_dashboard_widget_value(string $key): array
{
    if ($key === 'customer_growth_summary') return crm_dashboard_customer_growth_summary();
    if ($key === 'quote_order_summary') return crm_dashboard_quote_order_summary();
    if ($key === 'quote_order_board') return crm_dashboard_quote_order_board();
    if ($key === 'target_completion') return ['value' => '0%', 'hint' => '销售目标待设置', 'progress' => 0, 'desc' => '本月目标 / 已完成 / 差额'];
    if ($key === 'key_reminders') return crm_dashboard_key_reminders();
    if ($key === 'ar_aging') return crm_dashboard_ar_aging();
    if ($key === 'sales_rank') return crm_dashboard_sales_rank();
    if ($key === 'silent_customers') return crm_dashboard_silent_customers();
    if ($key === 'country_rank') return crm_dashboard_country_rank();
    if ($key === 'quote_amount') return crm_dashboard_quote_amount_trend();
    if ($key === 'team_sales_compare') return crm_dashboard_team_sales_compare();
    if ($key === 'team_sales_trend') return crm_dashboard_team_sales_trend();
    if ($key === 'customer_order_rank') return crm_dashboard_customer_order_rank();
    if ($key === 'customer_amount_rank') return crm_dashboard_customer_amount_rank();
    if ($key === 'customer_quote_rank') return crm_dashboard_customer_quote_rank();
    if ($key === 'ar_customer_rank') return crm_dashboard_ar_customer_rank();
    if ($key === 'team_ar_rank') return crm_dashboard_team_ar_rank();
    if ($key === 'high_potential_customers') return crm_dashboard_high_potential_customers();
    if ($key === 'tasks_today') return crm_dashboard_tasks_widget('today');
    if ($key === 'tasks_overdue') return crm_dashboard_tasks_widget('overdue');
    if ($key === 'dispatch_today') return crm_dashboard_dispatch_widget('today');
    if ($key === 'dispatch_overdue') return crm_dashboard_dispatch_widget('overdue');
    if ($key === 'today_visits') return crm_dashboard_visits_widget('customer_visit', 'today');
    if ($key === 'today_arrivals') return crm_dashboard_visits_widget('customer_arrival', 'today');
    if ($key === 'visit_overdue_results') return crm_dashboard_visits_widget('', 'overdue_result');
    if ($key === 'sample_shipments') return crm_dashboard_samples_widget('active');
    if ($key === 'sample_signed_followup') return crm_dashboard_samples_widget('signed_followup');
    if ($key === 'customer_level_pie') return crm_dashboard_customer_distribution('level');
    if ($key === 'customer_source_pie') return crm_dashboard_customer_distribution('source');
    if ($key === 'online_people') return crm_dashboard_online_users();
    if ($key === 'recent_logs' || $key === 'crm_system_logs') return crm_dashboard_recent_logs();
    if (in_array($key, ['website_inquiry_conversion','promotion_effect_summary','after_sales_summary','crm_system_logs'], true)) return crm_dashboard_formal_empty($key);
    if (in_array($key, ['today_mail', 'unread_mail', 'unreplied_mail'], true) && function_exists('crm_mail_dashboard_summary')) {
        try {
            $summary = crm_mail_dashboard_summary();
            if (empty($summary['bound'])) return ['value' => 0, 'hint' => '未绑定邮箱', 'items' => ['请先绑定企业邮箱']];
            if ($key === 'today_mail') return ['value' => (int)($summary['today_received'] ?? 0), 'hint' => '今日收件'];
            if ($key === 'unread_mail') return ['value' => (int)($summary['unread'] ?? 0), 'hint' => '未读邮件'];
            return [
                'value' => (int)($summary['unreplied'] ?? 0),
                'hint' => '等待回复处理',
                'items' => crm_dashboard_unreplied_mail_items(),
            ];
        } catch (Throwable $e) {
            return ['value' => 0, 'hint' => '邮箱未接入或无权限'];
        }
    }
    if ($key === 'online_count') return ['value' => crm_online_count(), 'hint' => '15 分钟内活跃'];
    if ($key === 'today_customers' && db_table_exists('crm_customers')) return ['value' => (int)db()->query("SELECT COUNT(*) FROM crm_customers WHERE DATE(created_at)=CURDATE() AND deleted_at IS NULL")->fetchColumn(), 'hint' => '今日新增'];
    if ($key === 'month_customers' && db_table_exists('crm_customers')) return ['value' => (int)db()->query("SELECT COUNT(*) FROM crm_customers WHERE DATE_FORMAT(created_at,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m') AND deleted_at IS NULL")->fetchColumn(), 'hint' => '本月新增'];
    if ($key === 'my_customers' && db_table_exists('crm_customer_owners')) {
        $uid = (int)(current_user()['id'] ?? 0);
        $stmt = db()->prepare('SELECT COUNT(*) FROM crm_customer_owners WHERE user_id = ?');
        $stmt->execute([$uid]);
        return ['value' => (int)$stmt->fetchColumn(), 'hint' => '我参与的客户'];
    }
    return ['value' => 0, 'hint' => '待业务接入'];
}

function crm_dashboard_formal_empty(string $key): array
{
    $map = [
        'high_potential_customers' => '暂无高潜客户数据',
        'website_inquiry_conversion' => '官网询盘接口待接入',
        'promotion_effect_summary' => '暂无推广效果数据',
        'after_sales_summary' => '售后/客诉接口待接入',
        'crm_system_logs' => '暂无系统日志摘要',
    ];
    return ['value' => 0, 'hint' => $map[$key] ?? '暂无数据', 'desc' => $map[$key] ?? '暂无数据', 'items' => [$map[$key] ?? '暂无数据']];
}

function crm_dashboard_customer_distribution(string $type): array
{
    if (!crm_dashboard_table_exists('crm_customers')) return ['value' => 0, 'hint' => '客户表未接入', 'slices' => [], 'bars' => []];
    $cols = crm_dashboard_table_columns('crm_customers');
    $candidates = $type === 'source'
        ? ['source', 'customer_source', 'lead_source', 'origin', 'from_source']
        : ['level', 'customer_level', 'grade', 'customer_grade', 'rank_level'];
    $col = crm_dashboard_first_existing_column($cols, $candidates);
    if (!$col) return ['value' => 0, 'hint' => $type === 'source' ? '暂无客户来源字段' : '暂无客户等级字段', 'slices' => [], 'bars' => []];
    $deleted = in_array('deleted_at', $cols, true) ? ' AND deleted_at IS NULL' : '';
    $stmt = db()->query("SELECT COALESCE(NULLIF({$col}, ''), '未填写') AS label, COUNT(*) AS total FROM crm_customers WHERE 1=1{$deleted} GROUP BY COALESCE(NULLIF({$col}, ''), '未填写') ORDER BY total DESC LIMIT 8");
    $rows = $stmt->fetchAll();
    $sum = max(1, array_sum(array_map(fn($r) => (int)$r['total'], $rows)));
    $slices = [];
    $bars = [];
    foreach ($rows as $row) {
        $label = (string)$row['label'];
        $count = (int)$row['total'];
        $percent = round($count * 100 / $sum, 1);
        $slices[] = [$label, $percent, $count];
        $bars[] = [$label, $percent, $count . ' 个'];
    }
    return [
        'value' => array_sum(array_map(fn($r) => (int)$r['total'], $rows)),
        'hint' => $type === 'source' ? '客户来源 / 数量 / 占比' : '客户等级 / 数量 / 占比',
        'slices' => $slices,
        'bars' => $bars,
    ];
}

function crm_dashboard_key_reminders(): array
{
    $task = crm_dashboard_tasks_widget('today');
    $overdue = crm_dashboard_tasks_widget('overdue');
    $sample = crm_dashboard_samples_widget('signed_followup');
    $visit = crm_dashboard_visits_widget('', 'today');
    $items = array_merge(
        ['今日任务：' . (int)($task['value'] ?? 0), '逾期任务：' . (int)($overdue['value'] ?? 0), '签收待跟进：' . (int)($sample['value'] ?? 0), '今日拜访/来访：' . (int)($visit['value'] ?? 0)],
        array_slice((array)($task['items'] ?? []), 0, 2),
        array_slice((array)($sample['items'] ?? []), 0, 2)
    );
    return ['value' => (int)($task['value'] ?? 0) + (int)($overdue['value'] ?? 0) + (int)($sample['value'] ?? 0), 'hint' => '任务 / 逾期 / 样品 / 拜访', 'items' => array_slice($items, 0, 8)];
}

function crm_dashboard_task_scope(string $alias = 't'): string
{
    if (!db_table_exists('crm_tasks')) return '0=1';
    if (function_exists('crm_task_scope_sql')) return crm_task_scope_sql($alias);
    if (is_super_admin() || has_permission('task.view_all')) return '1=1';
    $user = current_user() ?: [];
    $uid = (int)($user['id'] ?? 0);
    if (has_permission('task.view_department')) {
        $dept = (int)($user['department_id'] ?? 0);
        return "({$alias}.assigned_user_id={$uid} OR {$alias}.created_by={$uid} OR EXISTS (SELECT 1 FROM crm_users tu WHERE tu.id={$alias}.assigned_user_id AND tu.department_id={$dept}))";
    }
    return "({$alias}.assigned_user_id={$uid} OR {$alias}.created_by={$uid})";
}

function crm_dashboard_tasks_widget(string $mode): array
{
    if (!db_table_exists('crm_tasks') || (!has_permission('task.view') && !is_super_admin())) return ['value' => 0, 'hint' => '任务中心未接入或无权限', 'items' => []];
    $scope = crm_dashboard_task_scope('t');
    $where = $mode === 'overdue'
        ? "t.deleted_at IS NULL AND {$scope} AND t.status NOT IN ('done','closed','cancelled') AND t.due_at IS NOT NULL AND t.due_at < NOW()"
        : "t.deleted_at IS NULL AND {$scope} AND DATE(t.due_at)=CURDATE() AND t.status NOT IN ('done','closed','cancelled')";
    $count = (int)db()->query("SELECT COUNT(*) FROM crm_tasks t WHERE {$where}")->fetchColumn();
    $sql = "SELECT t.title, t.task_type, t.status, t.due_at, c.customer_name, u.username AS owner_name
        FROM crm_tasks t
        LEFT JOIN crm_customers c ON c.id=t.customer_id
        LEFT JOIN crm_users u ON u.id=t.assigned_user_id
        WHERE {$where}
        ORDER BY COALESCE(t.due_at,t.created_at) ASC, t.id DESC LIMIT 6";
    $items = [];
    foreach (db()->query($sql)->fetchAll() as $row) {
        $items[] = trim(($row['title'] ?: '未命名任务') . ' · ' . ($row['customer_name'] ?: '-') . ' · ' . substr((string)($row['due_at'] ?: ''), 0, 16));
    }
    return ['value' => $count, 'hint' => $mode === 'overdue' ? '未完成且已超期' : '今天到期的统一任务', 'items' => $items ?: ['暂无' . ($mode === 'overdue' ? '逾期任务' : '今日任务')]];
}

function crm_dashboard_dispatch_widget(string $mode): array
{
    if (!db_table_exists('crm_tasks') || (!has_permission('task.view') && !is_super_admin())) return ['value' => 0, 'hint' => '派工任务未接入或无权限', 'items' => []];
    $scope = crm_dashboard_task_scope('t');
    $where = $mode === 'overdue'
        ? "t.deleted_at IS NULL AND {$scope} AND t.task_type='dispatch_confirm' AND t.status NOT IN ('done','closed','cancelled') AND t.due_at IS NOT NULL AND t.due_at < NOW()"
        : "t.deleted_at IS NULL AND {$scope} AND t.task_type='dispatch_confirm' AND DATE(t.due_at)=CURDATE() AND t.status NOT IN ('done','closed','cancelled')";
    $count = (int)db()->query("SELECT COUNT(*) FROM crm_tasks t WHERE {$where}")->fetchColumn();
    $sql = "SELECT t.title, t.status, t.due_at, t.source_type, c.customer_name, u.username AS owner_name
        FROM crm_tasks t
        LEFT JOIN crm_customers c ON c.id=t.customer_id
        LEFT JOIN crm_users u ON u.id=t.assigned_user_id
        WHERE {$where}
        ORDER BY COALESCE(t.due_at,t.created_at) ASC, t.id DESC LIMIT 6";
    $items = [];
    foreach (db()->query($sql)->fetchAll() as $row) {
        $source = $row['source_type'] === 'website_inquiry' ? '官网询盘' : '派工';
        $items[] = $source . ' · ' . ($row['title'] ?: '未命名派工') . ' · ' . substr((string)($row['due_at'] ?: ''), 0, 16);
    }
    return ['value' => $count, 'hint' => $mode === 'overdue' ? '未完成且已超期派工' : '今天到期的派工确认', 'items' => $items ?: ['暂无' . ($mode === 'overdue' ? '逾期派工' : '今日派工')]];
}

function crm_dashboard_visit_scope(string $alias = 'v'): string
{
    if (!db_table_exists('crm_visit_records')) return '0=1';
    if (function_exists('crm_visit_scope_sql')) return crm_visit_scope_sql($alias);
    if (is_super_admin() || has_permission('visit.view_all')) return '1=1';
    $user = current_user() ?: [];
    $uid = (int)($user['id'] ?? 0);
    if (has_permission('visit.view_department')) {
        $dept = (int)($user['department_id'] ?? 0);
        return "({$alias}.owner_user_id={$uid} OR {$alias}.created_by={$uid} OR EXISTS (SELECT 1 FROM crm_users vu WHERE vu.id={$alias}.owner_user_id AND vu.department_id={$dept}))";
    }
    return "({$alias}.owner_user_id={$uid} OR {$alias}.created_by={$uid})";
}

function crm_dashboard_visits_widget(string $type = '', string $mode = 'today'): array
{
    if (!db_table_exists('crm_visit_records') || (!has_permission('visit.view') && !is_super_admin())) return ['value' => 0, 'hint' => '拜访/来访未接入或无权限', 'items' => []];
    $scope = crm_dashboard_visit_scope('v');
    $where = ["v.deleted_at IS NULL", $scope];
    if ($type !== '') $where[] = "v.visit_type=" . db()->quote($type);
    if ($mode === 'overdue_result') $where[] = "v.visit_date < CURDATE() AND v.status IN ('confirmed','pending_execute','executing','overdue_no_record')";
    else $where[] = "v.visit_date=CURDATE()";
    $whereSql = implode(' AND ', $where);
    $count = (int)db()->query("SELECT COUNT(*) FROM crm_visit_records v WHERE {$whereSql}")->fetchColumn();
    $rows = db()->query("SELECT v.title, v.visit_type, v.status, v.visit_date, v.visit_time, c.customer_name, u.username AS owner_name
        FROM crm_visit_records v
        LEFT JOIN crm_customers c ON c.id=v.customer_id
        LEFT JOIN crm_users u ON u.id=v.owner_user_id
        WHERE {$whereSql}
        ORDER BY COALESCE(v.visit_time,'23:59:59') ASC, v.id DESC LIMIT 6")->fetchAll();
    $items = [];
    foreach ($rows as $row) {
        $kind = $row['visit_type'] === 'customer_arrival' ? '来访' : '拜访';
        $items[] = $kind . ' · ' . ($row['customer_name'] ?: '-') . ' · ' . ($row['title'] ?: '-') . ' · ' . substr((string)($row['visit_time'] ?: ''), 0, 5);
    }
    $hint = $mode === 'overdue_result' ? '已过日期但未填写结果' : ($type === 'customer_arrival' ? '今日来访接待' : ($type === 'customer_visit' ? '今日外出拜访' : '今日拜访/来访'));
    return ['value' => $count, 'hint' => $hint, 'items' => $items ?: ['暂无' . $hint]];
}

function crm_dashboard_samples_widget(string $mode = 'active'): array
{
    if (!db_table_exists('crm_sample_shipments') || (!has_permission('sample.view') && !is_super_admin())) return ['value' => 0, 'hint' => '样品寄送未接入或无权限', 'items' => []];
    $scope = function_exists('crm_sample_scope_sql') ? crm_sample_scope_sql('s') : (is_super_admin() || has_permission('task.view_all') ? '1=1' : ('(s.owner_user_id=' . (int)(current_user()['id'] ?? 0) . ' OR s.created_by=' . (int)(current_user()['id'] ?? 0) . ')'));
    $where = ["s.deleted_at IS NULL", $scope];
    if ($mode === 'signed_followup') $where[] = "s.status='signed' AND (s.followup_time IS NULL OR s.followup_time <= DATE_ADD(NOW(), INTERVAL 1 DAY))";
    else $where[] = "s.status NOT IN ('feedback','cancelled')";
    $whereSql = implode(' AND ', $where);
    $count = (int)db()->query("SELECT COUNT(*) FROM crm_sample_shipments s WHERE {$whereSql}")->fetchColumn();
    $rows = db()->query("SELECT s.sample_name, s.product_model, s.status, s.courier_company, s.tracking_no, s.followup_time, c.customer_name, u.username AS owner_name
        FROM crm_sample_shipments s
        LEFT JOIN crm_customers c ON c.id=s.customer_id
        LEFT JOIN crm_users u ON u.id=s.owner_user_id
        WHERE {$whereSql}
        ORDER BY COALESCE(s.followup_time,s.expected_arrival_date,s.updated_at) ASC, s.id DESC LIMIT 6")->fetchAll();
    $statusMap = function_exists('crm_sample_status_map') ? crm_sample_status_map() : [];
    $items = [];
    foreach ($rows as $row) {
        $items[] = ($row['customer_name'] ?: '-') . ' · ' . ($row['sample_name'] ?: '-') . ' · ' . ($statusMap[$row['status']] ?? $row['status']) . (($row['tracking_no'] ?? '') ? ' · ' . $row['tracking_no'] : '');
    }
    return ['value' => $count, 'hint' => $mode === 'signed_followup' ? '已签收待跟进样品' : '未完成样品寄送', 'items' => $items ?: ['暂无样品寄送提醒']];
}

function crm_dashboard_ar_aging(): array
{
    if (!crm_dashboard_table_exists('quote_sales_orders')) return ['value' => 0, 'hint' => '订单接口待接入', 'bars' => []];
    $cols = crm_dashboard_table_columns('quote_sales_orders');
    $balanceCol = crm_dashboard_first_existing_column($cols, ['balance_amount', 'unpaid_amount', 'receivable_amount']);
    $dateCol = crm_dashboard_first_existing_column($cols, ['order_date', 'created_at']);
    if (!$balanceCol || !$dateCol) return ['value' => 0, 'hint' => '无账龄字段', 'bars' => []];
    $sql = "SELECT
        SUM(CASE WHEN DATEDIFF(CURDATE(), {$dateCol}) <= 0 AND {$balanceCol} > 0 THEN 1 ELSE 0 END) AS current_due,
        SUM(CASE WHEN DATEDIFF(CURDATE(), {$dateCol}) BETWEEN 1 AND 7 AND {$balanceCol} > 0 THEN 1 ELSE 0 END) AS d7,
        SUM(CASE WHEN DATEDIFF(CURDATE(), {$dateCol}) BETWEEN 8 AND 30 AND {$balanceCol} > 0 THEN 1 ELSE 0 END) AS d30,
        SUM(CASE WHEN DATEDIFF(CURDATE(), {$dateCol}) BETWEEN 31 AND 60 AND {$balanceCol} > 0 THEN 1 ELSE 0 END) AS d60,
        SUM(CASE WHEN DATEDIFF(CURDATE(), {$dateCol}) > 60 AND {$balanceCol} > 0 THEN 1 ELSE 0 END) AS d60p
        FROM quote_sales_orders";
    $row = db()->query($sql)->fetch() ?: [];
    $values = [['未到期', (int)($row['current_due'] ?? 0)], ['1-7天', (int)($row['d7'] ?? 0)], ['8-30天', (int)($row['d30'] ?? 0)], ['31-60天', (int)($row['d60'] ?? 0)], ['60天+', (int)($row['d60p'] ?? 0)]];
    $max = max(1, ...array_map(fn($v) => $v[1], $values));
    return ['value' => array_sum(array_map(fn($v) => $v[1], $values)), 'hint' => '应收账龄风险', 'bars' => array_map(fn($v) => [$v[0], round($v[1] * 100 / $max), $v[1]], $values)];
}

function crm_dashboard_sales_rank(): array
{
    if (!crm_dashboard_table_exists('quote_orders')) return ['value' => 0, 'hint' => '报价接口待接入', 'items' => ['暂无业务员排行']];
    $stmt = db()->query("SELECT COALESCE(NULLIF(user_name,''),'未填写') AS user_name, COUNT(*) AS quote_count, SUM(CASE WHEN amount REGEXP '^-?[0-9]+(\\\\.[0-9]+)?$' THEN amount + 0 ELSE 0 END) AS quote_amount FROM quote_orders WHERE DATE_FORMAT(COALESCE(quote_date, created_at), '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m') GROUP BY COALESCE(NULLIF(user_name,''),'未填写') ORDER BY quote_amount DESC LIMIT 6");
    $items = [];
    foreach ($stmt->fetchAll() as $row) $items[] = $row['user_name'] . ' · 报价 ' . (int)$row['quote_count'] . ' · ' . crm_dashboard_format_money($row['quote_amount'] ?? 0);
    return ['value' => count($items), 'hint' => '本月报价排行', 'items' => $items ?: ['暂无业务员排行']];
}

function crm_dashboard_silent_customers(): array
{
    if (!db_table_exists('crm_customers')) return ['value' => 0, 'hint' => '客户接口待接入', 'items' => ['暂无沉默客户数据']];
    $d30 = (int)db()->query("SELECT COUNT(*) FROM crm_customers WHERE deleted_at IS NULL AND updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();
    $d60 = (int)db()->query("SELECT COUNT(*) FROM crm_customers WHERE deleted_at IS NULL AND updated_at < DATE_SUB(NOW(), INTERVAL 60 DAY)")->fetchColumn();
    $d90 = (int)db()->query("SELECT COUNT(*) FROM crm_customers WHERE deleted_at IS NULL AND updated_at < DATE_SUB(NOW(), INTERVAL 90 DAY)")->fetchColumn();
    return ['value' => $d30, 'hint' => '长时间无更新客户', 'items' => ['30天无更新：' . $d30, '60天无更新：' . $d60, '90天无更新：' . $d90, '曾报价未成交：接口待接入']];
}

function crm_dashboard_country_rank(): array
{
    if (!db_table_exists('crm_customers')) return crm_dashboard_table_result('客户数据未接入', ['国家','客户数','报价金额','成交金额','应收','活跃'], []);
    $sql = "SELECT COALESCE(NULLIF(c.country,''),'未填写') AS country, COUNT(DISTINCT c.id) AS customer_count,
        COALESCE(SUM(CASE WHEN q.amount REGEXP '^-?[0-9]+(\\\\.[0-9]+)?$' THEN q.amount + 0 ELSE 0 END),0) AS quote_amount,
        COALESCE(SUM(CASE WHEN o.amount REGEXP '^-?[0-9]+(\\\\.[0-9]+)?$' THEN o.amount + 0 ELSE 0 END),0) AS order_amount,
        COALESCE(SUM(CASE WHEN o.balance_amount REGEXP '^-?[0-9]+(\\\\.[0-9]+)?$' THEN o.balance_amount + 0 ELSE 0 END),0) AS ar_amount,
        COUNT(DISTINCT CASE WHEN c.updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN c.id ELSE NULL END) AS active_count
        FROM crm_customers c
        LEFT JOIN quote_orders q ON q.customer_id = c.id AND DATE_FORMAT(COALESCE(q.quote_date,q.created_at),'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m')
        LEFT JOIN quote_sales_orders o ON o.customer_id = c.id AND DATE_FORMAT(COALESCE(o.order_date,o.created_at),'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m')
        WHERE c.deleted_at IS NULL GROUP BY COALESCE(NULLIF(c.country,''),'未填写') ORDER BY customer_count DESC LIMIT 10";
    $rows = [];
    foreach (db()->query($sql)->fetchAll() as $r) $rows[] = ['country'=>$r['country'], 'customers'=>(string)(int)$r['customer_count'], 'quote'=>crm_dashboard_money_text($r['quote_amount'], 'USD'), 'sales'=>crm_dashboard_money_text($r['order_amount'], 'USD'), 'ar'=>crm_dashboard_money_text($r['ar_amount'], 'USD'), 'active'=>(string)(int)$r['active_count']];
    return crm_dashboard_table_result('前 10 国家客户经营数据', ['国家','客户数','报价金额','成交金额','应收','活跃'], $rows);
}

function crm_dashboard_quote_amount_trend(): array
{
    if (!crm_dashboard_table_exists('quote_orders')) return ['value'=>0, 'hint'=>'报价数据未接入', 'bars'=>[]];
    $stmt = db()->query("SELECT DATE(COALESCE(quote_date, created_at)) AS d, COUNT(*) AS quote_count, SUM(CASE WHEN amount REGEXP '^-?[0-9]+(\\\\.[0-9]+)?$' THEN amount + 0 ELSE 0 END) AS quote_amount FROM quote_orders WHERE DATE_FORMAT(COALESCE(quote_date, created_at), '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m') GROUP BY DATE(COALESCE(quote_date, created_at)) ORDER BY d DESC LIMIT 10");
    $rows = $stmt->fetchAll();
    $max = max(1, ...array_map(fn($r) => (float)$r['quote_amount'], $rows ?: [['quote_amount'=>0]]));
    return ['value'=>count($rows), 'hint'=>'本月每日报价金额趋势', 'bars'=>array_reverse(array_map(fn($r) => [substr((string)$r['d'],5), round((float)$r['quote_amount'] * 100 / $max), crm_dashboard_format_money($r['quote_amount']) . ' / ' . (int)$r['quote_count'] . '份'], $rows))];
}

function crm_dashboard_format_money($amount): string
{
    return function_exists('crm_money') ? crm_money($amount) : number_format((float)$amount, 2, '.', ',');
}

function crm_dashboard_money_text($amount, string $currency = 'USD'): string
{
    return trim($currency ?: 'USD') . ' ' . crm_dashboard_format_money($amount ?? 0);
}

function crm_dashboard_table_result(string $hint, array $columns, array $rows, string $footnote = ''): array
{
    return [
        'value' => count($rows),
        'hint' => $hint,
        'display_mode' => 'table',
        'table_columns' => $columns,
        'table_rows' => $rows,
        'items' => array_map(fn($row) => implode(' · ', array_slice(array_values($row), 0, 3)), $rows),
        'footnote' => $footnote,
    ];
}

function crm_dashboard_team_sales_compare(array $input = []): array
{
    if (!crm_dashboard_table_exists('quote_orders')) return crm_dashboard_table_result('报价接口未接入', ['排名','员工','客户','跟进','报价','报价金额','订单','成交金额','应收','转化率'], []);
    $sql = "SELECT q.user_name AS employee,
        COUNT(DISTINCT q.customer_id) AS customer_count,
        COUNT(q.id) AS quote_count,
        COALESCE(SUM(CASE WHEN q.amount REGEXP '^-?[0-9]+(\\\\.[0-9]+)?$' THEN q.amount + 0 ELSE 0 END),0) AS quote_amount,
        COALESCE(o.order_count,0) AS order_count,
        COALESCE(o.order_amount,0) AS order_amount,
        COALESCE(o.receivable_amount,0) AS receivable_amount
        FROM quote_orders q
        LEFT JOIN (
            SELECT user_name, COUNT(*) AS order_count,
            SUM(CASE WHEN amount REGEXP '^-?[0-9]+(\\\\.[0-9]+)?$' THEN amount + 0 ELSE 0 END) AS order_amount,
            SUM(CASE WHEN balance_amount REGEXP '^-?[0-9]+(\\\\.[0-9]+)?$' THEN balance_amount + 0 ELSE 0 END) AS receivable_amount
            FROM quote_sales_orders
            WHERE DATE_FORMAT(COALESCE(order_date, created_at), '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
            GROUP BY user_name
        ) o ON o.user_name = q.user_name
        WHERE DATE_FORMAT(COALESCE(q.quote_date, q.created_at), '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
        GROUP BY q.user_name, o.order_count, o.order_amount, o.receivable_amount
        ORDER BY order_amount DESC, quote_amount DESC LIMIT 8";
    $rows = [];
    foreach (db()->query($sql)->fetchAll() as $i => $r) {
        $quoteCount = (int)$r['quote_count'];
        $orderCount = (int)$r['order_count'];
        $rows[] = [
            'rank' => (string)($i + 1),
            'employee' => $r['employee'] ?: '未填写',
            'customers' => (string)(int)$r['customer_count'],
            'followups' => '0',
            'quotes' => (string)$quoteCount,
            'quote_amount' => crm_dashboard_money_text($r['quote_amount'], 'USD'),
            'orders' => (string)$orderCount,
            'order_amount' => crm_dashboard_money_text($r['order_amount'], 'USD'),
            'ar' => crm_dashboard_money_text($r['receivable_amount'], 'USD'),
            'rate' => $quoteCount ? round($orderCount * 100 / $quoteCount, 1) . '%' : '0%',
        ];
    }
    return crm_dashboard_table_result('按本月成交金额排序', ['排名','员工','客户','跟进','报价','报价金额','订单','成交金额','应收','转化率'], $rows, '销售金额按订单金额统计；未接入跟进时显示 0。');
}

function crm_dashboard_customer_order_rank(array $input = []): array
{
    if (!crm_dashboard_table_exists('quote_sales_orders')) return crm_dashboard_table_result('订单数据未接入', ['排名','客户','国家','负责人','订单','订单金额','最近订单','应收'], []);
    $sql = "SELECT o.customer_id, o.customer_name, COALESCE(c.country,'') AS country, COALESCE(c.owner_department,'') AS owner_name,
        COUNT(*) AS order_count, SUM(CASE WHEN o.amount REGEXP '^-?[0-9]+(\\\\.[0-9]+)?$' THEN o.amount + 0 ELSE 0 END) AS order_amount,
        SUM(CASE WHEN o.balance_amount REGEXP '^-?[0-9]+(\\\\.[0-9]+)?$' THEN o.balance_amount + 0 ELSE 0 END) AS receivable_amount,
        MAX(COALESCE(o.order_date, o.created_at)) AS last_order
        FROM quote_sales_orders o LEFT JOIN crm_customers c ON c.id = o.customer_id
        WHERE DATE_FORMAT(COALESCE(o.order_date, o.created_at), '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
        GROUP BY o.customer_id, o.customer_name, c.country, c.owner_department
        ORDER BY order_count DESC, order_amount DESC LIMIT 10";
    $rows = [];
    foreach (db()->query($sql)->fetchAll() as $i => $r) {
        $rows[] = ['rank'=>(string)($i+1), 'customer'=>$r['customer_name'] ?: '未命名客户', 'country'=>$r['country'] ?: '-', 'owner'=>$r['owner_name'] ?: '-', 'orders'=>(string)(int)$r['order_count'], 'amount'=>crm_dashboard_money_text($r['order_amount'], 'USD'), 'last'=>substr((string)$r['last_order'],0,10), 'ar'=>crm_dashboard_money_text($r['receivable_amount'], 'USD')];
    }
    return crm_dashboard_table_result('本月订单数量最多客户', ['排名','客户','国家','负责人','订单','订单金额','最近订单','应收'], $rows);
}

function crm_dashboard_customer_amount_rank(array $input = []): array
{
    if (!crm_dashboard_table_exists('quote_sales_orders')) return crm_dashboard_table_result('订单数据未接入', ['排名','客户','国家','等级','负责人','订单','成交金额','应收'], []);
    $sql = "SELECT o.customer_id, o.customer_name, COALESCE(c.country,'') AS country, COALESCE(c.level,'') AS level_name, COALESCE(c.owner_department,'') AS owner_name,
        COUNT(*) AS order_count, SUM(CASE WHEN o.amount REGEXP '^-?[0-9]+(\\\\.[0-9]+)?$' THEN o.amount + 0 ELSE 0 END) AS order_amount,
        SUM(CASE WHEN o.balance_amount REGEXP '^-?[0-9]+(\\\\.[0-9]+)?$' THEN o.balance_amount + 0 ELSE 0 END) AS receivable_amount
        FROM quote_sales_orders o LEFT JOIN crm_customers c ON c.id = o.customer_id
        WHERE DATE_FORMAT(COALESCE(o.order_date, o.created_at), '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
        GROUP BY o.customer_id, o.customer_name, c.country, c.level, c.owner_department
        ORDER BY order_amount DESC LIMIT 10";
    $rows = [];
    $total = 0;
    foreach (db()->query($sql)->fetchAll() as $i => $r) {
        $total += (float)$r['order_amount'];
        $rows[] = ['rank'=>(string)($i+1), 'customer'=>$r['customer_name'] ?: '未命名客户', 'country'=>$r['country'] ?: '-', 'level'=>$r['level_name'] ?: '未分级', 'owner'=>$r['owner_name'] ?: '-', 'orders'=>(string)(int)$r['order_count'], 'amount'=>crm_dashboard_money_text($r['order_amount'], 'USD'), 'ar'=>crm_dashboard_money_text($r['receivable_amount'], 'USD')];
    }
    return crm_dashboard_table_result('本月成交金额最高客户', ['排名','客户','国家','等级','负责人','订单','成交金额','应收'], $rows, 'TOP10 成交总额 USD ' . crm_dashboard_format_money($total));
}

function crm_dashboard_customer_quote_rank(array $input = []): array
{
    if (!crm_dashboard_table_exists('quote_orders')) return crm_dashboard_table_result('报价数据未接入', ['客户','国家','负责人','报价','报价金额','成交金额','转化率','最近报价'], []);
    $sql = "SELECT q.customer_id, q.customer_name, COALESCE(c.country,'') AS country, COALESCE(c.owner_department,'') AS owner_name,
        COUNT(*) AS quote_count, SUM(CASE WHEN q.amount REGEXP '^-?[0-9]+(\\\\.[0-9]+)?$' THEN q.amount + 0 ELSE 0 END) AS quote_amount,
        COALESCE(o.order_amount,0) AS order_amount, MAX(COALESCE(q.quote_date, q.created_at)) AS last_quote
        FROM quote_orders q LEFT JOIN crm_customers c ON c.id = q.customer_id
        LEFT JOIN (SELECT customer_id, SUM(CASE WHEN amount REGEXP '^-?[0-9]+(\\\\.[0-9]+)?$' THEN amount + 0 ELSE 0 END) AS order_amount FROM quote_sales_orders GROUP BY customer_id) o ON o.customer_id=q.customer_id
        WHERE DATE_FORMAT(COALESCE(q.quote_date, q.created_at), '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
        GROUP BY q.customer_id, q.customer_name, c.country, c.owner_department, o.order_amount
        ORDER BY quote_amount DESC LIMIT 10";
    $rows = [];
    foreach (db()->query($sql)->fetchAll() as $r) {
        $qa = (float)$r['quote_amount']; $oa = (float)$r['order_amount'];
        $rows[] = ['customer'=>$r['customer_name'] ?: '未命名客户', 'country'=>$r['country'] ?: '-', 'owner'=>$r['owner_name'] ?: '-', 'quotes'=>(string)(int)$r['quote_count'], 'quote_amount'=>crm_dashboard_money_text($qa, 'USD'), 'order_amount'=>crm_dashboard_money_text($oa, 'USD'), 'rate'=>$qa ? round($oa * 100 / $qa, 1) . '%' : '0%', 'last'=>substr((string)$r['last_quote'],0,10)];
    }
    return crm_dashboard_table_result('报价金额最高但未必成交', ['客户','国家','负责人','报价','报价金额','成交金额','转化率','最近报价'], $rows);
}

function crm_dashboard_ar_customer_rank(array $input = []): array
{
    if (!crm_dashboard_table_exists('quote_sales_orders')) return crm_dashboard_table_result('应收数据未接入', ['客户','国家','负责人','应收金额','逾期金额','最大逾期','最近收款'], []);
    $sql = "SELECT o.customer_id, o.customer_name, COALESCE(c.country,'') AS country, COALESCE(c.owner_department,'') AS owner_name,
        SUM(CASE WHEN o.balance_amount REGEXP '^-?[0-9]+(\\\\.[0-9]+)?$' THEN o.balance_amount + 0 ELSE 0 END) AS ar_amount,
        SUM(CASE WHEN DATEDIFF(CURDATE(), COALESCE(o.order_date,o.created_at)) > 0 AND o.balance_amount > 0 THEN o.balance_amount + 0 ELSE 0 END) AS overdue_amount,
        MAX(CASE WHEN o.balance_amount > 0 THEN DATEDIFF(CURDATE(), COALESCE(o.order_date,o.created_at)) ELSE 0 END) AS max_days
        FROM quote_sales_orders o LEFT JOIN crm_customers c ON c.id=o.customer_id
        GROUP BY o.customer_id, o.customer_name, c.country, c.owner_department
        HAVING ar_amount > 0 ORDER BY ar_amount DESC LIMIT 10";
    $rows = [];
    foreach (db()->query($sql)->fetchAll() as $r) $rows[] = ['customer'=>$r['customer_name'] ?: '未命名客户', 'country'=>$r['country'] ?: '-', 'owner'=>$r['owner_name'] ?: '-', 'ar'=>crm_dashboard_money_text($r['ar_amount'], 'USD'), 'overdue'=>crm_dashboard_money_text($r['overdue_amount'], 'USD'), 'days'=>(string)max(0,(int)$r['max_days']) . '天', 'last_payment'=>'-'];
    return crm_dashboard_table_result('欠款最多客户', ['客户','国家','负责人','应收金额','逾期金额','最大逾期','最近收款'], $rows);
}

function crm_dashboard_team_ar_rank(array $input = []): array
{
    if (!crm_dashboard_table_exists('quote_sales_orders')) return crm_dashboard_table_result('应收数据未接入', ['员工','客户','应收总额','逾期金额','逾期客户','最大逾期'], []);
    $sql = "SELECT COALESCE(NULLIF(user_name,''),'未填写') AS employee, COUNT(DISTINCT customer_id) AS customer_count,
        SUM(CASE WHEN balance_amount REGEXP '^-?[0-9]+(\\\\.[0-9]+)?$' THEN balance_amount + 0 ELSE 0 END) AS ar_amount,
        SUM(CASE WHEN DATEDIFF(CURDATE(), COALESCE(order_date,created_at)) > 0 AND balance_amount > 0 THEN balance_amount + 0 ELSE 0 END) AS overdue_amount,
        COUNT(DISTINCT CASE WHEN DATEDIFF(CURDATE(), COALESCE(order_date,created_at)) > 0 AND balance_amount > 0 THEN customer_id ELSE NULL END) AS overdue_customers,
        MAX(CASE WHEN balance_amount > 0 THEN DATEDIFF(CURDATE(), COALESCE(order_date,created_at)) ELSE 0 END) AS max_days
        FROM quote_sales_orders GROUP BY COALESCE(NULLIF(user_name,''),'未填写') HAVING ar_amount > 0 ORDER BY ar_amount DESC LIMIT 10";
    $rows = [];
    foreach (db()->query($sql)->fetchAll() as $r) $rows[] = ['employee'=>$r['employee'], 'customers'=>(string)(int)$r['customer_count'], 'ar'=>crm_dashboard_money_text($r['ar_amount'], 'USD'), 'overdue'=>crm_dashboard_money_text($r['overdue_amount'], 'USD'), 'overdue_customers'=>(string)(int)$r['overdue_customers'], 'days'=>(string)max(0,(int)$r['max_days']) . '天'];
    return crm_dashboard_table_result('员工名下客户应收责任', ['员工','客户','应收总额','逾期金额','逾期客户','最大逾期'], $rows);
}

function crm_dashboard_team_sales_trend(array $input = []): array
{
    if (!crm_dashboard_table_exists('quote_sales_orders')) return ['value' => 0, 'hint' => '订单数据未接入', 'bars' => []];
    $stmt = db()->query("SELECT COALESCE(NULLIF(user_name,''),'未填写') AS employee, SUM(CASE WHEN amount REGEXP '^-?[0-9]+(\\\\.[0-9]+)?$' THEN amount + 0 ELSE 0 END) AS amount FROM quote_sales_orders WHERE COALESCE(order_date, created_at) >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY employee ORDER BY amount DESC LIMIT 5");
    $rows = $stmt->fetchAll();
    $max = max(1, ...array_map(fn($r) => (float)$r['amount'], $rows ?: [['amount'=>0]]));
    return ['value' => count($rows), 'hint' => '最近 6 个月成交金额 TOP5', 'bars' => array_map(fn($r) => [$r['employee'], round((float)$r['amount'] * 100 / $max), crm_dashboard_format_money($r['amount'])], $rows)];
}

function crm_dashboard_high_potential_customers(array $input = []): array
{
    if (!crm_dashboard_table_exists('quote_orders')) return crm_dashboard_table_result('报价数据未接入', ['客户','国家','负责人','预计金额','最近动作','下一步建议'], []);
    $sql = "SELECT q.customer_id, q.customer_name, COALESCE(c.country,'') AS country, COALESCE(c.owner_department,'') AS owner_name,
        SUM(CASE WHEN q.amount REGEXP '^-?[0-9]+(\\\\.[0-9]+)?$' THEN q.amount + 0 ELSE 0 END) AS quote_amount,
        COUNT(*) AS quote_count, MAX(COALESCE(q.quote_date, q.created_at)) AS last_action
        FROM quote_orders q LEFT JOIN crm_customers c ON c.id=q.customer_id
        WHERE COALESCE(q.quote_date, q.created_at) >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY q.customer_id, q.customer_name, c.country, c.owner_department
        HAVING quote_count >= 1 ORDER BY quote_amount DESC LIMIT 10";
    $rows = [];
    foreach (db()->query($sql)->fetchAll() as $r) {
        $rows[] = ['customer'=>$r['customer_name'] ?: '未命名客户', 'country'=>$r['country'] ?: '-', 'owner'=>$r['owner_name'] ?: '-', 'amount'=>crm_dashboard_money_text($r['quote_amount'], 'USD'), 'action'=>'近30天报价 ' . (int)$r['quote_count'] . ' 次', 'next'=>'跟进报价'];
    }
    return crm_dashboard_table_result('近 30 天高报价客户', ['客户','国家','负责人','预计金额','最近动作','下一步建议'], $rows);
}

function crm_dashboard_online_users(): array
{
    $rows = function_exists('crm_online_people') ? crm_online_people() : [];
    $items = [];
    foreach (array_slice($rows, 0, 8) as $r) {
        $items[] = ($r['user_name'] ?? '未知') . ' · ' . (($r['department'] ?? '') ?: '-') . ' · ' . (($r['status'] ?? '') ?: '在线') . ' · ' . substr((string)($r['last_active_time'] ?? ''), 11, 5);
    }
    return ['value' => count($rows), 'hint' => '15 分钟内活跃', 'items' => $items ?: ['当前暂无在线员工']];
}

function crm_dashboard_recent_logs(): array
{
    if (!crm_dashboard_table_exists('crm_operation_logs')) return ['value' => 0, 'hint' => '日志接口未接入', 'items' => ['暂无日志']];
    $stmt = db()->query("SELECT operator_name, module_key, action_key, target_type, target_id, created_at FROM crm_operation_logs ORDER BY created_at DESC LIMIT 10");
    $items = [];
    foreach ($stmt->fetchAll() as $r) $items[] = substr((string)$r['created_at'], 5, 11) . ' · ' . ($r['operator_name'] ?: '-') . ' · ' . $r['module_key'] . '/' . $r['action_key'] . ' · ' . ($r['target_type'] ?: '-') . ' #' . ($r['target_id'] ?: '-');
    return ['value' => count($items), 'hint' => '最近关键操作', 'items' => $items ?: ['暂无日志']];
}

function crm_dashboard_customer_growth_summary(): array
{
    $today = 0;
    $month = 0;
    $lead = 0;
    if (db_table_exists('crm_customers')) {
        $today = (int)db()->query("SELECT COUNT(*) FROM crm_customers WHERE DATE(created_at)=CURDATE() AND deleted_at IS NULL")->fetchColumn();
        $month = (int)db()->query("SELECT COUNT(*) FROM crm_customers WHERE DATE_FORMAT(created_at,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m') AND deleted_at IS NULL")->fetchColumn();
    }
    if (db_table_exists('crm_lead_pool') && (has_permission('customer.lead_pool_view') || has_permission('customer.lead_pool') || is_super_admin())) {
        $lead = (int)db()->query("SELECT COUNT(*) FROM crm_lead_pool WHERE status = 'pending'")->fetchColumn();
    }
    return [
        'value' => $month,
        'hint' => '今日 / 本月 / 暂存池',
        'status' => 'ready',
        'desc' => '客户新增汇总',
        'items' => [
            ['label' => '今日新增', 'value' => $today],
            ['label' => '本月新增', 'value' => $month],
            ['label' => '暂存池', 'value' => $lead],
        ],
    ];
}

function crm_dashboard_quote_order_summary(): array
{
    $quote = crm_dashboard_monthly_money_summary('quote_orders', ['quote_date', 'created_at'], ['amount', 'total_amount', 'grand_total'], 'quote');
    $order = crm_dashboard_monthly_money_summary('quote_sales_orders', ['order_date', 'created_at'], ['amount', 'total_amount', 'grand_total'], 'order');
    $receivable = crm_dashboard_monthly_receivable_summary();
    return [
        'value' => $quote['count'],
        'hint' => '本月报价 / 订单 / 欠款',
        'status' => ($quote['status'] === 'missing' && $order['status'] === 'missing') ? 'pending_config' : 'ready',
        'desc' => '报价订单收款汇总',
        'items' => [
            ['label' => '报价', 'count' => $quote['count'], 'amount' => $quote['amount_text']],
            ['label' => '订单', 'count' => $order['count'], 'amount' => $order['amount_text']],
            ['label' => '欠款', 'count' => $receivable['count'], 'amount' => $receivable['amount_text'], 'danger' => true],
        ],
    ];
}

function crm_dashboard_quote_order_board(array $input = []): array
{
    $stages = [
        ['key' => 'pending_review', 'label' => '报价审核', 'count' => 0, 'status' => 'pending'],
        ['key' => 'completed', 'label' => '报价完成', 'count' => 0, 'status' => 'success'],
        ['key' => 'rejected', 'label' => '驳回', 'count' => 0, 'status' => 'danger'],
        ['key' => 'converted', 'label' => '转订单', 'count' => 0, 'status' => 'success'],
        ['key' => 'shipment', 'label' => '出货', 'count' => 0, 'status' => 'info'],
        ['key' => 'document', 'label' => '单证', 'count' => 0, 'status' => 'info'],
    ];
    $notes = [];
    try {
        if (crm_dashboard_table_exists('quote_orders')) {
            $cols = crm_dashboard_table_columns('quote_orders');
            $dateCol = crm_dashboard_first_existing_column($cols, ['quote_date', 'submitted_at', 'updated_at', 'created_at']);
            $dateWhere = $dateCol ? "DATE_FORMAT(`{$dateCol}`, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')" : '1=1';
            $statusExpr = in_array('approval_status', $cols, true) ? "COALESCE(NULLIF(approval_status,''),'pending')" : "'pending'";
            $row = db()->query("SELECT
                SUM(CASE WHEN {$statusExpr}='pending' THEN 1 ELSE 0 END) AS pending_review,
                SUM(CASE WHEN {$statusExpr}='approved' THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN {$statusExpr}='rejected' THEN 1 ELSE 0 END) AS rejected
                FROM quote_orders WHERE {$dateWhere}")->fetch() ?: [];
            foreach (['pending_review', 'completed', 'rejected'] as $k) {
                foreach ($stages as &$stage) {
                    if ($stage['key'] === $k) $stage['count'] = (int)($row[$k] ?? 0);
                }
                unset($stage);
            }
            $convertedWhere = $dateWhere;
            $convertedParts = [];
            if (in_array('converted_order_id', $cols, true)) $convertedParts[] = 'COALESCE(converted_order_id,0)>0';
            if (in_array('converted_order_no', $cols, true)) $convertedParts[] = "COALESCE(converted_order_no,'')<>''";
            if ($convertedParts) {
                $converted = (int)db()->query('SELECT COUNT(*) FROM quote_orders WHERE ' . $convertedWhere . ' AND (' . implode(' OR ', $convertedParts) . ')')->fetchColumn();
                foreach ($stages as &$stage) if ($stage['key'] === 'converted') $stage['count'] = $converted;
                unset($stage);
            }
        } else {
            $notes[] = '报价数据未接入';
        }
        if (crm_dashboard_table_exists('quote_sales_orders')) {
            $cols = crm_dashboard_table_columns('quote_sales_orders');
            $dateCol = crm_dashboard_first_existing_column($cols, ['order_date', 'created_at']);
            $dateWhere = $dateCol ? "DATE_FORMAT(`{$dateCol}`, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')" : '1=1';
            $orders = (int)db()->query("SELECT COUNT(*) FROM quote_sales_orders WHERE {$dateWhere}")->fetchColumn();
            foreach ($stages as &$stage) if ($stage['key'] === 'converted' && $stage['count'] <= 0) $stage['count'] = $orders;
            unset($stage);
        }
        if (crm_dashboard_table_exists('quote_shipments')) {
            $cols = crm_dashboard_table_columns('quote_shipments');
            $dateCol = crm_dashboard_first_existing_column($cols, ['ship_date', 'created_at']);
            $dateWhere = $dateCol ? "DATE_FORMAT(`{$dateCol}`, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')" : '1=1';
            $shipments = (int)db()->query("SELECT COUNT(*) FROM quote_shipments WHERE {$dateWhere}")->fetchColumn();
            $docParts = [];
            if (in_array('pl_generated_at', $cols, true)) $docParts[] = 'pl_generated_at IS NOT NULL';
            if (in_array('ci_generated_at', $cols, true)) $docParts[] = 'ci_generated_at IS NOT NULL';
            $documents = $docParts ? (int)db()->query("SELECT SUM((" . implode(')+(', $docParts) . ')) FROM quote_shipments WHERE ' . $dateWhere)->fetchColumn() : 0;
            foreach ($stages as &$stage) {
                if ($stage['key'] === 'shipment') $stage['count'] = $shipments;
                if ($stage['key'] === 'document') $stage['count'] = $documents;
            }
            unset($stage);
        } else {
            $notes[] = '出货/单证数据未接入';
        }
    } catch (Throwable $e) {
        $notes[] = '报价订单看板统计异常';
    }
    $total = array_sum(array_map(function ($stage) { return (int)$stage['count']; }, $stages));
    return [
        'value' => $total,
        'hint' => '审核 / 完成 / 驳回 / 转订单 / 出货 / 单证',
        'status' => $notes ? 'partial' : 'ready',
        'desc' => '报价到订单、出货、单证的联动看板',
        'items' => $stages,
        'stages' => $stages,
        'notes' => $notes,
    ];
}

function crm_dashboard_monthly_money_summary(string $table, array $dateColumns, array $amountColumns, string $type): array
{
    $canPrice = function_exists('crm_quote_can_view_price') ? crm_quote_can_view_price() : (has_permission('quote.view') || is_super_admin());
    if (!crm_dashboard_table_exists($table)) return ['count' => 0, 'amount_text' => '未接入', 'status' => 'missing'];
    if (!$canPrice) return ['count' => 0, 'amount_text' => '***', 'status' => 'denied'];
    $cols = crm_dashboard_table_columns($table);
    $dateCol = crm_dashboard_first_existing_column($cols, $dateColumns) ?: 'id';
    $amountCol = crm_dashboard_first_existing_column($cols, $amountColumns);
    $currencyCol = in_array('currency', $cols, true) ? 'currency' : '';
    $dateWhere = $dateCol === 'id' ? '1=1' : "DATE_FORMAT({$dateCol}, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')";
    if (!$amountCol) {
        $stmt = db()->query("SELECT COUNT(*) FROM {$table} WHERE {$dateWhere}");
        return ['count' => (int)$stmt->fetchColumn(), 'amount_text' => '无金额字段', 'status' => 'connected'];
    }
    $currencyExpr = $currencyCol ? "COALESCE(NULLIF({$currencyCol}, ''), 'USD')" : "'USD'";
    $stmt = db()->query("SELECT {$currencyExpr} AS currency, COUNT(*) AS total_count, SUM(CASE WHEN {$amountCol} REGEXP '^-?[0-9]+(\\\\.[0-9]+)?$' THEN {$amountCol} + 0 ELSE 0 END) AS total_amount FROM {$table} WHERE {$dateWhere} GROUP BY {$currencyExpr} ORDER BY total_amount DESC");
    $rows = $stmt->fetchAll();
    $count = 0;
    $parts = [];
    foreach ($rows as $row) {
        $count += (int)($row['total_count'] ?? 0);
        $parts[] = trim((string)($row['currency'] ?? 'USD')) . ' ' . crm_dashboard_format_money($row['total_amount'] ?? 0);
    }
    return ['count' => $count, 'amount_text' => $parts ? implode(' / ', array_slice($parts, 0, 2)) : '0', 'status' => 'connected'];
}

function crm_dashboard_monthly_receivable_summary(): array
{
    $canPrice = function_exists('crm_quote_can_view_price') ? crm_quote_can_view_price() : (has_permission('quote.view') || is_super_admin());
    if (!crm_dashboard_table_exists('quote_sales_orders')) return ['count' => 0, 'amount_text' => '未接入'];
    if (!$canPrice) return ['count' => 0, 'amount_text' => '***'];
    $cols = crm_dashboard_table_columns('quote_sales_orders');
    $dateCol = crm_dashboard_first_existing_column($cols, ['order_date', 'created_at']) ?: 'id';
    $amountCol = crm_dashboard_first_existing_column($cols, ['amount', 'total_amount', 'grand_total']);
    $paidCol = crm_dashboard_first_existing_column($cols, ['paid_amount', 'received_amount', 'payment_amount']);
    $balanceCol = crm_dashboard_first_existing_column($cols, ['balance_amount', 'unpaid_amount', 'receivable_amount']);
    $currencyCol = in_array('currency', $cols, true) ? 'currency' : '';
    $dateWhere = $dateCol === 'id' ? '1=1' : "DATE_FORMAT({$dateCol}, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')";
    if (!$balanceCol && (!$amountCol || !$paidCol)) return ['count' => 0, 'amount_text' => '无欠款字段'];
    $currencyExpr = $currencyCol ? "COALESCE(NULLIF({$currencyCol}, ''), 'USD')" : "'USD'";
    $balanceExpr = $balanceCol ? "CASE WHEN {$balanceCol} REGEXP '^-?[0-9]+(\\\\.[0-9]+)?$' THEN {$balanceCol} + 0 ELSE 0 END" : "GREATEST(0, (CASE WHEN {$amountCol} REGEXP '^-?[0-9]+(\\\\.[0-9]+)?$' THEN {$amountCol} + 0 ELSE 0 END) - (CASE WHEN {$paidCol} REGEXP '^-?[0-9]+(\\\\.[0-9]+)?$' THEN {$paidCol} + 0 ELSE 0 END))";
    $stmt = db()->query("SELECT {$currencyExpr} AS currency, SUM({$balanceExpr}) AS total_amount, SUM(CASE WHEN {$balanceExpr} > 0.0001 THEN 1 ELSE 0 END) AS unpaid_count FROM quote_sales_orders WHERE {$dateWhere} GROUP BY {$currencyExpr} ORDER BY total_amount DESC");
    $rows = $stmt->fetchAll();
    $count = 0;
    $parts = [];
    foreach ($rows as $row) {
        $count += (int)($row['unpaid_count'] ?? 0);
        $parts[] = trim((string)($row['currency'] ?? 'USD')) . ' ' . crm_dashboard_format_money($row['total_amount'] ?? 0);
    }
    return ['count' => $count, 'amount_text' => $parts ? implode(' / ', array_slice($parts, 0, 2)) : '0'];
}

function crm_dashboard_first_existing_column(array $cols, array $candidates): string
{
    foreach ($candidates as $col) {
        if (in_array($col, $cols, true)) return $col;
    }
    return '';
}

function crm_dashboard_table_exists(string $table): bool
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) return false;
    if (function_exists('db_table_exists')) return db_table_exists($table);
    $stmt = db()->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function crm_dashboard_table_columns(string $table): array
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !crm_dashboard_table_exists($table)) return [];
    $rows = db()->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll();
    return array_values(array_map(fn($row) => (string)($row['Field'] ?? ''), $rows));
}

function crm_dashboard_seed_weather_cities(): void
{
    $rows = [
        ['zhongshan', '中山', 'China', 22.517600, 113.392600, 'Asia/Shanghai', 10],
        ['guangzhou', '广州', 'China', 23.129100, 113.264400, 'Asia/Shanghai', 20],
        ['hongkong', '香港', 'China', 22.319300, 114.169400, 'Asia/Hong_Kong', 30],
        ['dubai', '迪拜', 'UAE', 25.204800, 55.270800, 'Asia/Dubai', 40],
        ['riyadh', '利雅得', 'Saudi Arabia', 24.713600, 46.675300, 'Asia/Riyadh', 50],
        ['seoul', '首尔', 'Korea', 37.566500, 126.978000, 'Asia/Seoul', 60],
        ['delhi', '新德里', 'India', 28.613900, 77.209000, 'Asia/Kolkata', 70],
        ['frankfurt', '法兰克福', 'Germany', 50.110900, 8.682100, 'Europe/Berlin', 80],
    ];
    $stmt = db()->prepare('INSERT IGNORE INTO crm_dashboard_weather_cities (city_key, city_name, country_name, latitude, longitude, timezone, sort_order, is_enabled) VALUES (?, ?, ?, ?, ?, ?, ?, 1)');
    foreach ($rows as $row) $stmt->execute($row);
}

function crm_dashboard_http_json(string $url, int $timeout = 3): array
{
    $body = '';
    $status = '0';
    $error = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'ArtdonCRM/1.0',
        ]);
        $body = (string)curl_exec($ch);
        $status = (string)(curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 0);
        $error = curl_error($ch) ?: '';
        curl_close($ch);
    } else {
        $context = stream_context_create(['http' => ['timeout' => $timeout, 'header' => "User-Agent: ArtdonCRM/1.0\r\n"]]);
        $body = (string)@file_get_contents($url, false, $context);
        $status = $body !== '' ? '200' : '0';
        $error = $body !== '' ? '' : 'request_failed';
    }
    $json = json_decode($body, true);
    if (!is_array($json)) return ['ok' => false, 'status' => $status, 'error' => $error ?: 'invalid_json', 'data' => null];
    return ['ok' => true, 'status' => $status, 'error' => '', 'data' => $json];
}

function crm_dashboard_api_log(string $provider, string $type, string $url, string $status, string $error = ''): void
{
    try {
        db()->prepare('INSERT INTO crm_dashboard_api_logs (provider, api_type, request_url, response_status, error_message) VALUES (?, ?, ?, ?, ?)')
            ->execute([$provider, $type, $url, $status, $error]);
    } catch (Throwable $e) {
    }
}

function crm_dashboard_fx(array $input = []): array
{
    $base = strtoupper(preg_replace('/[^A-Z]/i', '', (string)($input['base'] ?? 'USD'))) ?: 'USD';
    $symbolsRaw = strtoupper((string)($input['symbols'] ?? 'CNY,EUR,HKD,SGD,AED,SAR,INR,KRW'));
    $symbols = array_values(array_unique(array_filter(array_map(fn($v) => preg_replace('/[^A-Z]/i', '', trim($v)), explode(',', $symbolsRaw)))));
    $symbols = array_slice(array_filter($symbols, fn($v) => $v && $v !== $base), 0, 12);
    if (!$symbols) $symbols = ['CNY', 'EUR', 'HKD'];
    $force = !empty($input['force']);
    $cacheStmt = db()->prepare('SELECT * FROM crm_dashboard_fx_cache WHERE base_currency = ? LIMIT 1');
    $cacheStmt->execute([$base]);
    $cache = $cacheStmt->fetch() ?: null;
    $cacheRates = $cache ? (json_decode((string)($cache['rates_json'] ?? ''), true) ?: []) : [];
    $fresh = $cache && strtotime((string)$cache['fetched_at']) >= time() - 21600;
    if ($fresh && !$force) {
        return ['provider' => $cache['provider'] ?: 'Cache', 'base' => $base, 'date' => $cache['rate_date'], 'fetched_at' => $cache['fetched_at'], 'from_cache' => true, 'rates' => array_intersect_key($cacheRates, array_flip($symbols))];
    }
    $rates = [];
    $provider = 'Frankfurter';
    $date = date('Y-m-d');
    $url = 'https://api.frankfurter.dev/v1/latest?base=' . rawurlencode($base) . '&symbols=' . rawurlencode(implode(',', $symbols));
    $res = crm_dashboard_http_json($url, 3);
    if ($res['ok'] && !empty($res['data']['rates']) && is_array($res['data']['rates'])) {
        $rates = $res['data']['rates'];
        $date = (string)($res['data']['date'] ?? $date);
    } else {
        crm_dashboard_api_log('Frankfurter', 'fx', $url, $res['status'], $res['error']);
        $provider = 'ExchangeRate-API';
        $url = 'https://open.er-api.com/v6/latest/' . rawurlencode($base);
        $res = crm_dashboard_http_json($url, 3);
        if ($res['ok'] && !empty($res['data']['rates']) && is_array($res['data']['rates'])) {
            foreach ($symbols as $symbol) {
                if (isset($res['data']['rates'][$symbol])) $rates[$symbol] = $res['data']['rates'][$symbol];
            }
            $date = date('Y-m-d');
        } else {
            crm_dashboard_api_log('ExchangeRate-API', 'fx', $url, $res['status'], $res['error']);
        }
    }
    if ($rates) {
        db()->prepare('INSERT INTO crm_dashboard_fx_cache (base_currency, rates_json, provider, rate_date, fetched_at) VALUES (?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE rates_json=VALUES(rates_json), provider=VALUES(provider), rate_date=VALUES(rate_date), fetched_at=NOW()')
            ->execute([$base, json_encode($rates, JSON_UNESCAPED_UNICODE), $provider, $date]);
        return ['provider' => $provider, 'base' => $base, 'date' => $date, 'fetched_at' => date('Y-m-d H:i:s'), 'from_cache' => false, 'rates' => array_intersect_key($rates, array_flip($symbols))];
    }
    if ($cacheRates) {
        return ['provider' => $cache['provider'] ?: 'Cache', 'base' => $base, 'date' => $cache['rate_date'], 'fetched_at' => $cache['fetched_at'], 'from_cache' => true, 'rates' => array_intersect_key($cacheRates, array_flip($symbols)), 'warning' => '汇率使用上一次缓存'];
    }
    return ['provider' => '', 'base' => $base, 'date' => null, 'fetched_at' => null, 'from_cache' => false, 'rates' => [], 'warning' => '汇率暂不可用'];
}

function crm_dashboard_weather_code_text(int $code): string
{
    $map = [0=>'晴',1=>'基本晴',2=>'局部多云',3=>'阴',45=>'雾',48=>'雾凇',51=>'小毛毛雨',53=>'中毛毛雨',55=>'大毛毛雨',56=>'冻毛毛雨',57=>'强冻毛毛雨',61=>'小雨',63=>'中雨',65=>'大雨',66=>'冻雨',67=>'强冻雨',71=>'小雪',73=>'中雪',75=>'大雪',77=>'雪粒',80=>'小阵雨',81=>'中阵雨',82=>'强阵雨',85=>'小阵雪',86=>'大阵雪',95=>'雷雨',96=>'雷雨伴小冰雹',99=>'雷雨伴大冰雹'];
    return $map[$code] ?? '未知';
}

function crm_dashboard_weather(array $input = []): array
{
    $force = !empty($input['force']);
    $rows = db()->query('SELECT * FROM crm_dashboard_weather_cities WHERE is_enabled = 1 ORDER BY sort_order, id LIMIT 12')->fetchAll();
    $cities = [];
    $cacheStmt = db()->prepare('SELECT * FROM crm_dashboard_weather_cache WHERE city_key = ? LIMIT 1');
    $upsertStmt = db()->prepare('INSERT INTO crm_dashboard_weather_cache (city_key, city_name, latitude, longitude, timezone, weather_json, fetched_at) VALUES (?, ?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE city_name=VALUES(city_name), latitude=VALUES(latitude), longitude=VALUES(longitude), timezone=VALUES(timezone), weather_json=VALUES(weather_json), fetched_at=NOW()');
    foreach ($rows as $city) {
        $cacheStmt->execute([$city['city_key']]);
        $cache = $cacheStmt->fetch() ?: null;
        $cachedWeather = $cache ? (json_decode((string)($cache['weather_json'] ?? ''), true) ?: null) : null;
        $fresh = $cache && strtotime((string)$cache['fetched_at']) >= time() - 3600;
        $weather = (!$force && $cachedWeather) ? $cachedWeather : null;
        if (!$weather) {
            if (!$force) continue;
            $url = 'https://api.open-meteo.com/v1/forecast?latitude=' . rawurlencode((string)$city['latitude']) . '&longitude=' . rawurlencode((string)$city['longitude']) . '&current=temperature_2m,relative_humidity_2m,weather_code,wind_speed_10m&daily=temperature_2m_max,temperature_2m_min,precipitation_probability_max&timezone=' . rawurlencode((string)$city['timezone']);
            $res = crm_dashboard_http_json($url, 2);
            if ($res['ok'] && !empty($res['data']['current'])) {
                $current = $res['data']['current'];
                $daily = $res['data']['daily'] ?? [];
                $code = (int)($current['weather_code'] ?? 0);
                $weather = [
                    'city_key' => $city['city_key'],
                    'city_name' => $city['city_name'],
                    'temperature' => round((float)($current['temperature_2m'] ?? 0), 1),
                    'weather_code' => $code,
                    'weather_text' => crm_dashboard_weather_code_text($code),
                    'humidity' => (int)($current['relative_humidity_2m'] ?? 0),
                    'wind_speed' => round((float)($current['wind_speed_10m'] ?? 0), 1),
                    'temp_max' => isset($daily['temperature_2m_max'][0]) ? round((float)$daily['temperature_2m_max'][0], 1) : null,
                    'temp_min' => isset($daily['temperature_2m_min'][0]) ? round((float)$daily['temperature_2m_min'][0], 1) : null,
                    'timezone' => $city['timezone'],
                ];
                $upsertStmt->execute([$city['city_key'], $city['city_name'], $city['latitude'], $city['longitude'], $city['timezone'], json_encode($weather, JSON_UNESCAPED_UNICODE)]);
            } else {
                crm_dashboard_api_log('Open-Meteo', 'weather', $url, $res['status'], $res['error']);
                $weather = $cachedWeather;
            }
        }
        if (is_array($weather)) {
            $weather['fetched_at'] = (!$force && $cache) || ($force && $weather === $cachedWeather && $cache) ? $cache['fetched_at'] : date('Y-m-d H:i:s');
            $weather['is_stale'] = $cache && !$fresh ? 1 : 0;
            $cities[] = $weather;
        }
    }
    return ['provider' => 'Open-Meteo', 'updated_at' => date('Y-m-d H:i:s'), 'from_cache' => !$force, 'cities' => $cities, 'warning' => $cities ? '' : '天气暂不可用，请点击刷新天气'];
}

function crm_dashboard_unreplied_mail_items(): array
{
    if (!function_exists('crm_mail_current_account')) return [];
    $account = crm_mail_current_account(false);
    if (!$account || !db_table_exists('crm_mails')) return [];
    $stmt = db()->prepare("SELECT subject, from_email, from_name, body_text, received_at FROM crm_mails WHERE user_id = ? AND mail_account_id = ? AND is_unreplied = 1 AND is_deleted = 0 ORDER BY COALESCE(received_at, created_at) DESC, id DESC LIMIT 5");
    $stmt->execute([(int)$account['user_id'], (int)$account['id']]);
    $items = [];
    foreach ($stmt->fetchAll() as $row) {
        $name = trim((string)($row['from_name'] ?: $row['from_email'] ?: '未知发件人'));
        $subject = trim((string)($row['subject'] ?: '无主题'));
        $summary = trim(strip_tags((string)($row['body_text'] ?? '')));
        if (function_exists('crm_mail_repair_text')) {
            $name = crm_mail_repair_text($name);
            $subject = crm_mail_repair_text($subject);
            $summary = crm_mail_repair_text($summary);
        }
        $summary = preg_replace('/\s+/u', ' ', $summary) ?: '暂无正文摘要';
        $summary = function_exists('mb_substr') ? mb_substr($summary, 0, 80) : substr($summary, 0, 80);
        $items[] = [
            'from' => $name,
            'subject' => $subject,
            'summary' => $summary,
        ];
    }
    return $items ?: [['from' => '暂无未回复邮件', 'subject' => '当前没有等待回复的邮件', 'summary' => '']];
}

function crm_workspace_role_key(array $user): string
{
    $role = strtolower((string)($user['role_key'] ?? $user['role_name'] ?? ''));
    if (is_super_admin() || stripos($role, 'admin') !== false || stripos($role, 'boss') !== false || stripos($role, '老板') !== false) return 'boss';
    if (stripos($role, 'manager') !== false || stripos($role, 'leader') !== false || stripos($role, '主管') !== false || stripos($role, '经理') !== false) return 'manager';
    if (stripos($role, 'sales') !== false || stripos($role, '业务') !== false) return 'sales';
    if (stripos($role, 'clerk') !== false || stripos($role, 'assistant') !== false || stripos($role, '文员') !== false || stripos($role, '跟单') !== false) return 'assistant';
    return 'staff';
}

function crm_workspace_tool_status(string $key): array
{
    $labels = [
        'weather_tool' => ['天气接口', '配置天气 API Key、城市和刷新频率后启用。'],
        'flight_tool' => ['航班接口', '配置航班查询供应商、AppKey 和默认航线后启用。'],
        'express_tool' => ['快递接口', '配置快递查询接口、客户编码和默认快递公司后启用。'],
        'exchange_rate' => ['汇率接口', '配置汇率源、基准币种和目标币种后启用。'],
        'world_time' => ['世界时间配置', '配置常用时区后显示实时世界时间。'],
    ];
    if (!isset($labels[$key])) return [];
    return ['value' => '待接入', 'hint' => $labels[$key][0], 'status' => 'pending_config', 'desc' => $labels[$key][1]];
}

function crm_workspace_bootstrap(array $input = []): array
{
    crm_ui_ensure_tables();
    crm_require('dashboard.view');
    $user = current_user() ?: [];
    $range = preg_replace('/[^a-z0-9_\\-]/i', '', (string)($input['range'] ?? 'month')) ?: 'month';
    $catalog = crm_dashboard_catalog();
    $catalogByKey = [];
    foreach ($catalog as $item) $catalogByKey[$item['widget_key']] = $item;
    $rows = crm_user_dashboard_widgets((int)($user['id'] ?? 0));
    $hiddenKeys = crm_dashboard_hidden_widget_keys((int)($user['id'] ?? 0));
    $widgets = [];
    foreach ($rows as $row) {
        $key = (string)$row['widget_key'];
        if (in_array($key, ['today_customers', 'month_customers', 'lead_pool'], true)) continue;
        $value = crm_workspace_tool_status($key) ?: crm_dashboard_widget_value($key);
        $widgets[] = array_merge($row, [
            'value' => $value['value'] ?? 0,
            'hint' => $value['hint'] ?? '',
            'status' => $value['status'] ?? 'ready',
            'desc' => $value['desc'] ?? ($value['hint'] ?? ''),
            'items' => $value['items'] ?? [],
            'bars' => $value['bars'] ?? [],
            'slices' => $value['slices'] ?? [],
            'progress' => $value['progress'] ?? null,
            'table_columns' => $value['table_columns'] ?? [],
            'table_rows' => $value['table_rows'] ?? [],
            'footnote' => $value['footnote'] ?? '',
            'range' => $range,
        ]);
    }
    $presentKeys = [];
    foreach ($widgets as $widget) $presentKeys[(string)($widget['widget_key'] ?? '')] = true;
    $virtualSort = 5;
    foreach (crm_dashboard_role_defaults($user) as $key) {
        if (isset($presentKeys[$key]) || in_array($key, $hiddenKeys, true) || !isset($catalogByKey[$key]) || in_array($key, ['today_customers', 'month_customers', 'lead_pool'], true)) continue;
        $meta = $catalogByKey[$key];
        $value = crm_workspace_tool_status($key) ?: crm_dashboard_widget_value($key);
        [$w, $h] = array_map('intval', explode('x', (string)($meta['default_size'] ?? '2x1')));
        $widgets[] = array_merge($meta, [
            'id' => 0,
            'user_id' => (int)($user['id'] ?? 0),
            'display_mode' => $value['display_mode'] ?? $meta['default_mode'],
            'width' => $w,
            'height' => $h,
            'is_visible' => 1,
            'sort_order' => $virtualSort,
            'value' => $value['value'] ?? 0,
            'hint' => $value['hint'] ?? '',
            'status' => $value['status'] ?? 'ready',
            'desc' => $value['desc'] ?? ($value['hint'] ?? ''),
            'items' => $value['items'] ?? [],
            'bars' => $value['bars'] ?? [],
            'slices' => $value['slices'] ?? [],
            'progress' => $value['progress'] ?? null,
            'table_columns' => $value['table_columns'] ?? [],
            'table_rows' => $value['table_rows'] ?? [],
            'footnote' => $value['footnote'] ?? '',
            'range' => $range,
        ]);
        $presentKeys[$key] = true;
        $virtualSort += 5;
    }
    usort($widgets, fn($a, $b) => ((int)($a['sort_order'] ?? 100)) <=> ((int)($b['sort_order'] ?? 100)));
    if (!in_array('customer_growth_summary', $hiddenKeys, true)) array_unshift($widgets, array_merge([
        'id' => 0,
        'user_id' => (int)($user['id'] ?? 0),
        'widget_key' => 'customer_growth_summary',
        'widget_name' => '客户新增',
        'widget_category' => '客户',
        'display_mode' => 'summary',
        'default_mode' => 'summary',
        'default_size' => '2x1',
        'width' => 2,
        'height' => 1,
        'is_visible' => 1,
        'sort_order' => 1,
        'range' => $range,
    ], crm_dashboard_customer_growth_summary()));
    return [
        'role' => crm_workspace_role_key($user),
        'role_name' => $user['role_name'] ?? '',
        'department_name' => $user['department_name'] ?? '',
        'range' => $range,
        'widgets' => $widgets,
        'hidden_widgets' => $hiddenKeys,
        'catalog' => array_values($catalog),
        'tool_configured' => false,
    ];
}

function crm_ui_preferences(int $userId): array
{
    crm_ui_ensure_tables();
    $prefs = crm_preferences($userId);
    $stmt = db()->prepare('SELECT font_config_json, density_config_json, column_config_json, layout_config_json, custom_theme_json, dashboard_layout_json, dashboard_widgets_json FROM crm_user_preferences WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch() ?: [];
    return array_replace($prefs, [
        'font_config' => json_decode((string)($row['font_config_json'] ?? ''), true) ?: crm_ui_default_font_config(),
        'density_config' => json_decode((string)($row['density_config_json'] ?? ''), true) ?: crm_ui_default_density_config(),
        'column_config' => json_decode((string)($row['column_config_json'] ?? ''), true) ?: crm_ui_default_column_config(),
        'layout_config' => json_decode((string)($row['layout_config_json'] ?? ''), true) ?: crm_ui_default_layout_config(),
        'custom_theme' => json_decode((string)($row['custom_theme_json'] ?? ''), true) ?: crm_ui_default_custom_theme(),
        'dashboard_layout' => json_decode((string)($row['dashboard_layout_json'] ?? ''), true) ?: ['mode' => 'grid', 'gap' => 8],
        'dashboard_widgets' => json_decode((string)($row['dashboard_widgets_json'] ?? ''), true) ?: [],
    ]);
}

function crm_ui_save_preferences(int $userId, array $input): array
{
    crm_ui_ensure_tables();
    $before = crm_ui_preferences($userId);
    $base = crm_save_preferences($userId, $input);
    $jsonFields = [
        'font_config' => crm_ui_default_font_config(),
        'density_config' => crm_ui_default_density_config(),
        'column_config' => crm_ui_default_column_config(),
        'layout_config' => crm_ui_default_layout_config(),
        'custom_theme' => crm_ui_default_custom_theme(),
        'dashboard_layout' => ['mode' => 'grid', 'gap' => 8],
        'dashboard_widgets' => [],
    ];
    $next = [];
    foreach ($jsonFields as $key => $default) {
        $value = $input[$key] ?? null;
        if (is_string($value)) $value = json_decode($value, true);
        $next[$key] = is_array($value) ? $value : ($before[$key] ?? $default);
    }
    db()->prepare('UPDATE crm_user_preferences SET font_config_json=?, density_config_json=?, column_config_json=?, layout_config_json=?, custom_theme_json=?, dashboard_layout_json=?, dashboard_widgets_json=?, updated_at=NOW() WHERE user_id=?')
        ->execute([json_encode($next['font_config'], JSON_UNESCAPED_UNICODE), json_encode($next['density_config'], JSON_UNESCAPED_UNICODE), json_encode($next['column_config'], JSON_UNESCAPED_UNICODE), json_encode($next['layout_config'], JSON_UNESCAPED_UNICODE), json_encode($next['custom_theme'], JSON_UNESCAPED_UNICODE), json_encode($next['dashboard_layout'], JSON_UNESCAPED_UNICODE), json_encode($next['dashboard_widgets'], JSON_UNESCAPED_UNICODE), $userId]);
    $after = array_replace($base, $next);
    crm_log_event('settings', 'save_ui_control', 'user', (string)$userId, $before, $after);
    return $after;
}

function crm_dashboard_save_widgets(int $userId, array $widgets): array
{
    crm_ui_ensure_tables();
    $sort = 10;
    $stmt = db()->prepare('INSERT INTO crm_user_dashboard_widgets (user_id, widget_key, display_mode, position_x, position_y, width, height, filter_config_json, refresh_interval, is_visible, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE display_mode=VALUES(display_mode), position_x=VALUES(position_x), position_y=VALUES(position_y), width=VALUES(width), height=VALUES(height), filter_config_json=VALUES(filter_config_json), refresh_interval=VALUES(refresh_interval), is_visible=VALUES(is_visible), sort_order=VALUES(sort_order), updated_at=NOW()');
    foreach ($widgets as $widget) {
        $key = preg_replace('/[^a-z0-9_\\-]/i', '', (string)($widget['widget_key'] ?? ''));
        if ($key === '') continue;
        $mode = preg_replace('/[^a-z0-9_\\-]/i', '', (string)($widget['display_mode'] ?? 'card')) ?: 'card';
        $stmt->execute([$userId, $key, $mode, (int)($widget['position_x'] ?? 0), (int)($widget['position_y'] ?? 0), max(1, min(4, (int)($widget['width'] ?? 2))), max(1, min(2, (int)($widget['height'] ?? 1))), json_encode($widget['filter_config'] ?? [], JSON_UNESCAPED_UNICODE), (int)($widget['refresh_interval'] ?? 300), !empty($widget['is_visible']) ? 1 : 0, (int)($widget['sort_order'] ?? $sort)]);
        $sort += 10;
    }
    crm_log_event('workspace', 'save_dashboard_widgets', 'user', (string)$userId, null, $widgets);
    return crm_user_dashboard_widgets($userId);
}

function crm_dashboard_catalog(): array
{
    crm_ui_ensure_tables();
    $rows = db()->query('SELECT * FROM crm_dashboard_widgets WHERE is_enabled = 1 ORDER BY widget_category, sort_order, id')->fetchAll();
    return array_values(array_filter($rows, fn($row) => empty($row['required_permission']) || has_permission($row['required_permission']) || is_super_admin()));
}
