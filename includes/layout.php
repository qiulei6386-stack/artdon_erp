<?php

function render_layout($title, $content, array $options = [])
{
    page_header($title, $options);
    echo $content;
    page_footer($options);
}

function auth_page_header($title, bool $wide = false, string $variant = '')
{
    $systemName = function_exists('db_config') ? (db_config()['system_name'] ?? 'Artdon Office V20') : 'Artdon Office V20';
    $panelClass = trim('login-panel' . ($wide ? ' wide' : '') . ($variant ? ' ' . $variant : ''));
    $cssVersion = @filemtime(__DIR__ . '/../assets/crm-v18.css') ?: time();
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h($title) . ' - ' . h($systemName) . '</title><link rel="stylesheet" href="assets/crm-v18.css?v=' . h($cssVersion) . '"></head>';
    echo '<body class="login-page"><main class="' . h($panelClass) . '">';
}

function auth_page_footer()
{
    echo '</main></body></html>';
}

function page_header($title, array $options = [])
{
    $user = current_user();
    $systemName = db_config()['system_name'] ?? 'Artdon Office V20';
    $portal = !empty($options['portal']);
    $base = layout_base_path();
    $context = layout_context($options);
    $uiType = $context['ui_type'];
    $page = $context['page'];
    $bodyClass = $portal
        ? 'portal-body'
        : 'app-body ui-body ui-body-' . h($uiType) . ' page-' . h($page);

    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h($title) . ' - ' . h($systemName) . '</title>';
    echo '<script>(function(){try{var t=localStorage.getItem("artdon.theme")||"office-light";var d=localStorage.getItem("artdon.density")||"standard";document.documentElement.dataset.theme=t;document.documentElement.dataset.density=d;}catch(e){}})();</script>';
    $cssVersion = @filemtime(__DIR__ . '/../assets/crm-v18.css') ?: time();
    echo '<link rel="stylesheet" href="' . h($base) . 'assets/crm-v18.css?v=' . h($cssVersion) . '"></head><body class="' . $bodyClass . '">';
    render_topbar($systemName, $user, $base, $portal, $options);

    if ($portal) {
        echo '<main class="portal-content">';
        return;
    }

    echo '<div class="shell app-shell app-shell-' . h($uiType) . '">';
    echo '<aside class="sidebar app-sidebar">';
    render_context_sidebar($uiType, $page, $base);
    echo '</aside>';
    echo '<main class="content app-content">';
    echo '<div class="page-title page-title-' . h($uiType) . '"><span>' . h(layout_type_label($uiType)) . '</span><h1>' . h($title) . '</h1></div>';
}

function render_topbar($systemName, $user, $base, $portal, array $options = [])
{
    echo '<header class="topbar app-topbar"><div class="brand"><a href="' . h($base) . 'index.php">' . h($systemName) . '</a></div><nav class="topnav">';
    foreach (layout_topnav_items() as $item) {
        echo '<a href="' . h(layout_href($item[1], $base)) . '">' . h($item[0]) . '</a>';
    }
    echo '</nav><div class="account">';
    if ($user) {
        $notifications = isset($options['notifications'])
            ? (int)$options['notifications']
            : (function_exists('notification_unread_count') ? notification_unread_count((int)$user['id']) : 0);
        echo '<div class="theme-tools"><label>主题<select data-theme-picker><option value="office-light">Office Light</option><option value="ocean-blue">Ocean Blue</option><option value="graphite">Graphite</option><option value="mint">Mint</option><option value="warm-sand">Warm Sand</option><option value="dark">Dark</option></select></label><label>密度<select data-density-picker><option value="comfortable">舒适</option><option value="standard">标准</option><option value="compact">紧凑</option></select></label></div>';
        echo '<a class="top-notify" href="' . h($base) . 'notifications.php" title="通知中心">通知<span>' . h($notifications) . '</span></a>';
        echo '<div class="account-card"><strong>' . h($user['username']) . '</strong><span>' . h($user['role_name'] ?: '未分配角色') . ' / ' . h($user['department_name'] ?: '未分配部门') . '</span></div>';
        echo '<a class="account-link" href="' . h($base) . 'profile.php">我的账号</a><a class="account-link danger-link" href="' . h($base) . 'login.php?action=logout">退出</a>';
    }
    echo '</div></header>';
}

function layout_topnav_items()
{
    return [
        ['返回首页', 'index.php'],
        ['CRM', 'crm.php'],
        ['邮箱', 'mail.php'],
        ['推广', 'promotion.php'],
        ['报价', 'quotation.php'],
        ['资料', 'datasheet.php'],
        ['BOM', 'bom.php'],
        ['派工', 'dispatch_next.php'],
        ['命名', 'naming.php'],
        ['PLM', 'crm.php#linkage'],
    ];
}

function layout_context(array $options)
{
    $legacyType = $options['type'] ?? '';
    $uiType = $options['ui_type'] ?? '';
    $page = $options['page'] ?? '';

    if (!$uiType && $legacyType) {
        $uiType = [
            'permission' => 'permission',
            'settings' => 'system',
            'log' => 'system',
            'backup' => 'monitor',
            'business' => 'business',
            'system' => 'system',
            'monitor' => 'monitor',
        ][$legacyType] ?? 'business';
        $page = $page ?: $legacyType;
    }

    $uiType = in_array($uiType, ['business', 'system', 'permission', 'monitor'], true) ? $uiType : 'business';
    $page = $page ?: layout_infer_page();
    return ['ui_type' => $uiType, 'page' => preg_replace('/[^a-z0-9_\\-]/i', '', $page) ?: 'workspace'];
}

function layout_infer_page()
{
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $map = [
        'mail.php' => 'mail',
        'crm.php' => 'crm',
        'customer.php' => 'customer',
        'promotion.php' => 'promotion',
        'permissions.php' => 'permission',
        'users.php' => 'users',
        'settings.php' => 'settings',
        'logs.php' => 'logs',
        'notifications.php' => 'notifications',
        'profile.php' => 'profile',
        'linkage_center.php' => 'linkage',
        'full_backup.php' => 'backup',
        'template_backup.php' => 'template_backup',
        'data_backup.php' => 'data_backup',
        'backup_scheduler.php' => 'backup_scheduler',
        'restore.php' => 'restore',
        'system_status.php' => 'system_status',
    ];
    return $map[$script] ?? preg_replace('/\\.php$/', '', $script);
}

function layout_base_path()
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    return strpos($script, '/backup/') !== false ? '../' : '';
}

function layout_href($href, $base)
{
    if ($href === '#' || preg_match('/^(https?:)?\/\//', $href)) {
        return $href;
    }
    if (strpos($href, '../') === 0) {
        return $href;
    }
    return $base . $href;
}

function layout_type_label($uiType)
{
    return [
        'permission' => 'Access Control',
        'business' => 'Business Workspace',
        'system' => 'System Console',
        'monitor' => 'Monitor Console',
    ][$uiType] ?? 'Workspace';
}

function render_context_sidebar($uiType, $page, $base = '')
{
    $items = layout_sidebar_items($uiType);
    echo '<div class="sidebar-head sidebar-head-' . h($uiType) . '"><strong>' . h(layout_type_label($uiType)) . '</strong><span>Artdon Office V20</span></div>';
    echo '<nav class="sidebar-nav" aria-label="' . h(layout_type_label($uiType)) . '">';
    foreach ($items as $item) {
        if (!layout_item_allowed($item)) {
            continue;
        }
        $active = ($item['page'] ?? '') === $page ? ' active' : '';
        $badge = $item['badge'] ?? '';
        echo '<a class="' . h($active) . '" href="' . h(layout_href($item['href'], $base)) . '"><span>' . h($item['icon']) . '</span><strong>' . h($item['label']) . '</strong>';
        if ($badge) echo '<em>' . h($badge) . '</em>';
        echo '</a>';
    }
    echo '</nav>';
}

function layout_item_allowed(array $item)
{
    $permission = $item['permission'] ?? '';
    return $permission === '' || has_permission($permission) || is_super_admin();
}

function layout_sidebar_items($uiType)
{
    $page = layout_infer_page();
    if ($uiType === 'permission') {
        return permission_sidebar_items();
    }
    if ($uiType === 'system') {
        if ($page === 'settings') return settings_sidebar_items();
        if ($page === 'logs') return log_sidebar_items();
        if ($page === 'notifications') return notification_sidebar_items();
        return system_sidebar_items();
    }
    if ($uiType === 'monitor') {
        if (in_array($page, ['backup', 'template_backup', 'data_backup', 'backup_scheduler', 'restore'], true)) {
            return backup_sidebar_items();
        }
        return monitor_sidebar_items();
    }
    return business_sidebar_items();
}

function permission_sidebar_items()
{
    return [
        ['label' => '人员权限', 'href' => 'permissions.php?tab=people', 'permission' => 'users.view', 'icon' => '人', 'page' => 'permission'],
        ['label' => '注册审核', 'href' => 'users.php?status=pending', 'permission' => 'users.approve', 'icon' => '审', 'page' => 'users'],
        ['label' => '部门管理', 'href' => 'permissions.php?tab=people', 'permission' => 'users.assign_department', 'icon' => '部', 'page' => 'department'],
        ['label' => '岗位职级', 'href' => 'permissions.php?tab=people', 'permission' => 'users.assign_role', 'icon' => '岗', 'page' => 'position'],
        ['label' => '角色管理', 'href' => 'permissions.php?tab=matrix', 'permission' => 'dangerous.permission_admin', 'icon' => '角', 'page' => 'roles'],
        ['label' => '权限矩阵', 'href' => 'permissions.php?tab=matrix', 'permission' => 'dangerous.permission_admin', 'icon' => '阵', 'page' => 'matrix'],
        ['label' => '数据范围', 'href' => 'permissions.php?tab=people', 'permission' => 'users.assign_permission', 'icon' => '域', 'page' => 'scope'],
        ['label' => '字段权限', 'href' => 'permissions.php?tab=people', 'permission' => 'users.assign_permission', 'icon' => '字', 'page' => 'field'],
        ['label' => '高危权限', 'href' => 'permissions.php?tab=people', 'permission' => 'dangerous.permission_admin', 'icon' => '危', 'page' => 'danger'],
        ['label' => '权限申请', 'href' => 'permissions.php?tab=request', 'permission' => 'permission_request.create', 'icon' => '申', 'page' => 'request'],
        ['label' => '审批中心', 'href' => 'permissions.php?tab=approval', 'permission' => 'permission_request.approve_department', 'icon' => '批', 'page' => 'approval'],
        ['label' => '临时授权', 'href' => 'permissions.php?tab=approval', 'permission' => 'permission_request.revoke', 'icon' => '临', 'page' => 'grant'],
        ['label' => '权限日志', 'href' => 'logs.php#action', 'permission' => 'logs.view_own', 'icon' => '志', 'page' => 'permission_logs'],
    ];
}

function settings_sidebar_items()
{
    return [
        ['label' => '系统信息', 'href' => 'settings.php#system', 'permission' => 'settings.view', 'icon' => '系', 'page' => 'settings'],
        ['label' => '公司信息', 'href' => 'settings.php#company', 'permission' => 'settings.view', 'icon' => '司', 'page' => 'company'],
        ['label' => '主题设置', 'href' => 'settings.php#ui', 'permission' => 'settings.view', 'icon' => '题', 'page' => 'ui'],
        ['label' => 'UI 密度', 'href' => 'settings.php#ui', 'permission' => 'settings.view', 'icon' => '密', 'page' => 'density'],
        ['label' => '安全设置', 'href' => 'settings.php#security', 'permission' => 'settings.view', 'icon' => '安', 'page' => 'security'],
        ['label' => '密码策略', 'href' => 'settings.php#password', 'permission' => 'settings.view', 'icon' => '码', 'page' => 'password'],
        ['label' => '数据统计', 'href' => 'settings.php#data', 'permission' => 'settings.view', 'icon' => '数', 'page' => 'data'],
        ['label' => '存储使用', 'href' => 'settings.php#storage', 'permission' => 'settings.view', 'icon' => '储', 'page' => 'storage'],
        ['label' => '字段检测', 'href' => 'settings.php#schema', 'permission' => 'settings.schema_scan', 'icon' => '检', 'page' => 'schema'],
        ['label' => '一键修复', 'href' => 'settings.php#schema', 'permission' => 'settings.schema_repair', 'icon' => '修', 'page' => 'repair'],
        ['label' => '通知设置', 'href' => 'settings.php#notice', 'permission' => 'settings.view', 'icon' => '通', 'page' => 'notice'],
        ['label' => '日志设置', 'href' => 'settings.php#logs', 'permission' => 'settings.view', 'icon' => '志', 'page' => 'log_settings'],
        ['label' => '备份设置', 'href' => 'settings.php#backup', 'permission' => 'settings.view', 'icon' => '备', 'page' => 'backup_settings'],
    ];
}

function log_sidebar_items()
{
    return [
        ['label' => '登录日志', 'href' => 'logs.php#login', 'permission' => 'logs.view_own', 'icon' => '登', 'page' => 'logs'],
        ['label' => '操作日志', 'href' => 'logs.php#action', 'permission' => 'logs.view_own', 'icon' => '操', 'page' => 'action_logs'],
        ['label' => '权限日志', 'href' => 'logs.php#action', 'permission' => 'logs.view_own', 'icon' => '权', 'page' => 'permission_logs'],
        ['label' => '高危日志', 'href' => 'logs.php#security', 'permission' => 'logs.view_all', 'icon' => '危', 'page' => 'security_logs'],
        ['label' => '首页操作日志', 'href' => 'logs.php#home', 'permission' => 'logs.view_own', 'icon' => '首', 'page' => 'homepage_logs'],
        ['label' => '备份日志', 'href' => 'logs.php#security', 'permission' => 'logs.view_all', 'icon' => '备', 'page' => 'backup_logs'],
        ['label' => '系统日志', 'href' => 'logs.php#action', 'permission' => 'logs.view_all', 'icon' => '系', 'page' => 'system_logs'],
    ];
}

function notification_sidebar_items()
{
    return [
        ['label' => '通知列表', 'href' => 'notifications.php', 'permission' => 'notifications.view_own', 'icon' => '列', 'page' => 'notifications'],
        ['label' => '注册审核通知', 'href' => 'notifications.php#register', 'permission' => 'notifications.view_own', 'icon' => '审', 'page' => 'register_notice'],
        ['label' => '权限申请通知', 'href' => 'notifications.php#permission', 'permission' => 'notifications.view_own', 'icon' => '权', 'page' => 'permission_notice'],
        ['label' => '审批结果通知', 'href' => 'notifications.php#approval', 'permission' => 'notifications.view_own', 'icon' => '批', 'page' => 'approval_notice'],
        ['label' => '高危操作通知', 'href' => 'notifications.php#security', 'permission' => 'notifications.view_own', 'icon' => '危', 'page' => 'security_notice'],
        ['label' => '备份完成通知', 'href' => 'notifications.php#backup', 'permission' => 'notifications.view_own', 'icon' => '备', 'page' => 'backup_notice'],
    ];
}

function system_sidebar_items()
{
    return [
        ['label' => '系统设置', 'href' => 'settings.php', 'permission' => 'settings.view', 'icon' => '设', 'page' => 'settings'],
        ['label' => '通知中心', 'href' => 'notifications.php', 'permission' => 'notifications.view_own', 'icon' => '通', 'page' => 'notifications'],
        ['label' => '日志审计', 'href' => 'logs.php', 'permission' => 'logs.view_own', 'icon' => '志', 'page' => 'logs'],
        ['label' => '个人安全', 'href' => 'profile.php', 'permission' => '', 'icon' => '密', 'page' => 'profile'],
    ];
}

function backup_sidebar_items()
{
    return [
        ['label' => '备份控制台', 'href' => 'backup/full_backup.php', 'permission' => 'settings.schema_repair', 'icon' => '控', 'page' => 'backup'],
        ['label' => '模板备份', 'href' => 'backup/template_backup.php', 'permission' => 'settings.schema_repair', 'icon' => '模', 'page' => 'template_backup'],
        ['label' => '数据备份', 'href' => 'backup/data_backup.php', 'permission' => 'settings.schema_repair', 'icon' => '数', 'page' => 'data_backup'],
        ['label' => '整站备份', 'href' => 'backup/full_backup.php', 'permission' => 'settings.schema_repair', 'icon' => '全', 'page' => 'backup'],
        ['label' => '自动备份', 'href' => 'backup/backup_scheduler.php', 'permission' => 'settings.schema_repair', 'icon' => '自', 'page' => 'backup_scheduler'],
        ['label' => '恢复中心', 'href' => 'backup/restore.php', 'permission' => 'settings.schema_repair', 'icon' => '恢', 'page' => 'restore'],
        ['label' => '备份日志', 'href' => 'logs.php#security', 'permission' => 'logs.view_all', 'icon' => '志', 'page' => 'backup_logs'],
    ];
}

function monitor_sidebar_items()
{
    return [
        ['label' => '系统联动中心', 'href' => 'linkage_center.php', 'permission' => 'linkage.view', 'icon' => '联', 'page' => 'linkage'],
        ['label' => '系统状态', 'href' => 'backup/system_status.php', 'permission' => 'settings.view', 'icon' => '态', 'page' => 'system_status'],
        ['label' => '备份控制台', 'href' => 'backup/full_backup.php', 'permission' => 'settings.schema_repair', 'icon' => '备', 'page' => 'backup'],
    ];
}

function business_sidebar_items()
{
    return [
        ['label' => '业务工作台', 'href' => 'index.php', 'permission' => 'dashboard.view', 'icon' => '台', 'page' => 'workspace'],
        ['label' => 'CRM 客户经营', 'href' => 'crm.php', 'permission' => 'customer.view', 'icon' => '客', 'page' => 'crm'],
        ['label' => '邮箱中心', 'href' => 'mail.php', 'permission' => 'mail.view', 'icon' => '邮', 'page' => 'mail'],
        ['label' => '推广中心', 'href' => 'promotion.php', 'permission' => 'promotion.view', 'icon' => '推', 'page' => 'promotion'],
        ['label' => '报价系统', 'href' => 'quotation.php', 'permission' => 'quote.view', 'icon' => '价', 'page' => 'quote', 'badge' => '已接入'],
        ['label' => '资料生成', 'href' => 'datasheet.php', 'permission' => 'datasheet.view', 'icon' => '资', 'page' => 'datasheet', 'badge' => '已接入'],
        ['label' => 'BOM 成本', 'href' => 'bom.php', 'permission' => 'bom.view', 'icon' => 'B', 'page' => 'bom', 'badge' => '已接入'],
        ['label' => '派工待办', 'href' => 'dispatch_next.php', 'permission' => 'dispatch.view', 'icon' => '工', 'page' => 'dispatch', 'badge' => '已启用'],
        ['label' => '数据分析', 'href' => 'index.php#analysis', 'permission' => 'dashboard.view', 'icon' => '析', 'page' => 'analysis'],
    ];
}

function page_footer(array $options = [])
{
    if (!empty($options['portal'])) {
        echo '</main><script src="' . h(layout_base_path()) . 'assets/crm-v18.js"></script></body></html>';
        return;
    }
    echo '</main></div><script src="' . h(layout_base_path()) . 'assets/crm-v18.js"></script></body></html>';
}

function flash($message, $type = 'info')
{
    echo '<div class="flash ' . h($type) . '">' . h($message) . '</div>';
}
