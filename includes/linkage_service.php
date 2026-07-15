<?php

function linkage_status_label(string $status): string
{
    return [
        'connected' => '已接入',
        'pending' => '待接入',
        'reserved' => '预留',
        'error' => '异常',
    ][$status] ?? $status;
}

function linkage_store(): array
{
    static $initialized = false;
    static $modules = [];
    static $edges = [];

    if (!$initialized) {
        $initialized = true;

        $core = [
            ['login', '登录系统', 'connected', ['group' => '核心底座', 'files' => ['login.php', 'includes/auth.php'], 'permissions' => ['dashboard.view'], 'logs' => ['crm_login_logs', 'sys_login_logs'], 'note' => '登录后读取当前用户、角色和部门。']],
            ['permission', '权限中心', 'connected', ['group' => '核心底座', 'files' => ['permissions.php', 'includes/permission.php'], 'permissions' => ['permission_request.view_own', 'users.view'], 'logs' => ['sys_action_logs'], 'note' => '权限申请、审批、临时授权和用户权限管理。']],
            ['notification', '通知中心', 'connected', ['group' => '核心底座', 'files' => ['notifications.php', 'includes/notification_service.php'], 'permissions' => ['notifications.view_own'], 'logs' => ['sys_notifications'], 'note' => '通知入口和未读数量已建立，业务通知会陆续接入。']],
            ['logs', '日志中心', 'connected', ['group' => '核心底座', 'files' => ['logs.php', 'services/log_service.php'], 'permissions' => ['logs.view_own', 'logs.view_all'], 'logs' => ['crm_audit_logs', 'sys_action_logs', 'sys_homepage_logs'], 'note' => '登录、操作、首页行为和高危日志基础能力。']],
            ['settings', '系统设置', 'connected', ['group' => '核心底座', 'files' => ['settings.php', 'includes/schema.php'], 'permissions' => ['settings.view', 'settings.schema_repair'], 'logs' => ['sys_action_logs'], 'note' => '系统参数、字段检测和一键修复入口。']],
            ['backup', '备份中心', 'connected', ['group' => '核心底座', 'files' => ['backup/full_backup.php', 'services/backup_service.php'], 'permissions' => ['settings.schema_repair'], 'logs' => ['sys_backup_records', 'sys_action_logs'], 'note' => '模板备份、数据备份、整站备份和恢复中心。']],
            ['linkage', '联动中心', 'connected', ['group' => '核心底座', 'files' => ['linkage_center.php', 'includes/linkage_service.php'], 'permissions' => ['linkage.view', 'linkage.audit', 'linkage.manage'], 'logs' => ['sys_linkage_events'], 'note' => '系统联动架构预留中心。']],
        ];

        $business = [
            ['crm', 'CRM 客户经营', 'pending', ['group' => '业务模块', 'files' => ['crm.php', 'crm_api.php'], 'permissions' => ['customer.view'], 'logs' => ['crm_operation_logs'], 'note' => 'CRM 第一阶段重建框架已建立，业务数据和真实联动后续接入。']],
            ['mail', '邮箱中心', 'pending', ['group' => '业务模块', 'files' => ['mail.php'], 'permissions' => ['mail.view'], 'logs' => [], 'note' => '当前为入口占位，收发信和客户归档未真实接入。']],
            ['customer', '客户中心', 'pending', ['group' => '业务模块', 'files' => ['crm.php#customers'], 'permissions' => ['customer.view'], 'logs' => [], 'note' => '客户资料、联系人和跟进功能后续接入。']],
            ['contact', '联系人', 'pending', ['group' => '业务模块', 'files' => ['crm.php#customers'], 'permissions' => ['contact.view'], 'logs' => [], 'note' => '联系人管理后续作为 CRM 子模块接入。']],
            ['promotion', '推广中心', 'pending', ['group' => '业务模块', 'files' => ['promotion.php'], 'permissions' => ['promotion.view'], 'logs' => [], 'note' => '推广项目、分组和停止推广状态后续接入。']],
            ['customer_logs', '客户日志', 'pending', ['group' => '业务模块', 'files' => ['logs.php'], 'permissions' => ['customer.view_logs'], 'logs' => [], 'note' => '客户新增、修改、导入、推广变更后续写入。']],
            ['import_export', '导入导出', 'pending', ['group' => '业务模块', 'files' => [], 'permissions' => ['import.preview', 'export.customer'], 'logs' => [], 'note' => '导入导出能力后续接入，不在当前阶段读取旧数据。']],
        ];

        $reserved = [
            ['plm', 'PLM 项目/研发', 'reserved', ['group' => '外部系统 / 后续接入', 'files' => [], 'permissions' => [], 'logs' => [], 'note' => '旧 PLM 或新研发系统后续接入。']],
            ['bom', 'BOM 成本', 'reserved', ['group' => '外部系统 / 后续接入', 'files' => [], 'permissions' => [], 'logs' => [], 'note' => 'BOM 成本系统后续接入。']],
            ['quote', '报价系统', 'reserved', ['group' => '外部系统 / 后续接入', 'files' => [], 'permissions' => [], 'logs' => [], 'note' => '报价、PDF/Excel 和价格记录后续接入。']],
            ['dispatch', '派工待办', 'reserved', ['group' => '外部系统 / 后续接入', 'files' => [], 'permissions' => [], 'logs' => [], 'note' => '派工任务和协作日志后续接入。']],
            ['order_finance', '订单/财务', 'reserved', ['group' => '外部系统 / 后续接入', 'files' => [], 'permissions' => [], 'logs' => [], 'note' => '订单、对账、回款和财务记录后续接入。']],
            ['document_pack', '资料生成', 'reserved', ['group' => '外部系统 / 后续接入', 'files' => [], 'permissions' => [], 'logs' => [], 'note' => '客户资料包、IES、证书和高清图后续接入。']],
            ['website_inquiry', '官网询盘', 'reserved', ['group' => '外部系统 / 后续接入', 'files' => [], 'permissions' => [], 'logs' => [], 'note' => '官网询盘同步到 CRM 后续接入。']],
        ];

        foreach (array_merge($core, $business, $reserved) as $module) {
            register_linkage_module($module[0], $module[1], $module[2], $module[3]);
        }

        register_linkage_edge('login', 'permission', 'auth_permission', 'connected', '登录后读取当前用户、角色、部门和权限。');
        register_linkage_edge('permission', 'notification', 'permission_notice', 'connected', '权限申请、审核结果、临时授权到期通知入口已预留，通知列表和未读数量已建立。');
        register_linkage_edge('permission', 'logs', 'audit', 'connected', '权限变更、用户审核、临时授权记录日志。');
        register_linkage_edge('index', 'logs', 'homepage_audit', 'pending', '后续持续记录用户点击首页模块入口。');
        register_linkage_edge('mail', 'crm', 'mail_archive', 'pending', '后续邮件可匹配客户、归档到客户、转跟进。');
        register_linkage_edge('crm', 'promotion', 'customer_promotion_sync', 'pending', '后续客户、联系人、不推广状态同步到推广中心。');
        register_linkage_edge('crm', 'customer_logs', 'customer_audit', 'pending', '后续客户新增、修改、导入、停止推广写入客户日志。');
        register_linkage_edge('backup', 'logs', 'backup_audit', 'connected', '备份、恢复、自动备份写入操作日志。');
        register_linkage_edge('settings', 'schema_repair', 'schema_tool', 'reserved', '系统设置中执行字段检测和一键修复。');
    }

    return ['modules' => $modules, 'edges' => $edges];
}

function register_linkage_module($module_key, $module_name, $status, $meta = [])
{
    $GLOBALS['linkage_modules'] = $GLOBALS['linkage_modules'] ?? [];
    $GLOBALS['linkage_modules'][$module_key] = [
        'module_key' => (string)$module_key,
        'module_name' => (string)$module_name,
        'status' => (string)$status,
        'meta' => is_array($meta) ? $meta : [],
    ];
}

function register_linkage_edge($from, $to, $relation_type, $status, $description)
{
    $GLOBALS['linkage_edges'] = $GLOBALS['linkage_edges'] ?? [];
    $GLOBALS['linkage_edges'][] = [
        'from' => (string)$from,
        'to' => (string)$to,
        'relation_type' => (string)$relation_type,
        'status' => (string)$status,
        'description' => (string)$description,
    ];
}

function get_linkage_modules()
{
    linkage_store();
    return array_values($GLOBALS['linkage_modules'] ?? []);
}

function get_linkage_edges()
{
    linkage_store();
    return $GLOBALS['linkage_edges'] ?? [];
}

function record_linkage_event($module_key, $event_type, $title, $detail = [], $status = 'success')
{
    if (!function_exists('db_table_exists') || !db_table_exists('sys_linkage_events')) {
        return false;
    }
    $user = function_exists('current_user') ? current_user() : null;
    $stmt = db()->prepare("INSERT INTO sys_linkage_events (module_key, event_type, title, detail_json, status, related_module, related_id, created_by, created_by_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    $stmt->execute([
        (string)$module_key,
        (string)$event_type,
        (string)$title,
        json_encode($detail, JSON_UNESCAPED_UNICODE),
        (string)$status,
        $detail['related_module'] ?? null,
        $detail['related_id'] ?? null,
        $user['id'] ?? null,
        $user['username'] ?? null,
    ]);
    return true;
}

function get_recent_linkage_events($module_key = null)
{
    if (!function_exists('db_table_exists') || !db_table_exists('sys_linkage_events')) {
        return [];
    }
    if ($module_key) {
        $stmt = db()->prepare('SELECT * FROM sys_linkage_events WHERE module_key = ? ORDER BY id DESC LIMIT 50');
        $stmt->execute([$module_key]);
        return $stmt->fetchAll();
    }
    return db()->query('SELECT * FROM sys_linkage_events ORDER BY id DESC LIMIT 50')->fetchAll();
}

function get_linkage_health_summary()
{
    $summary = ['connected' => 0, 'pending' => 0, 'reserved' => 0, 'error' => 0];
    foreach (get_linkage_modules() as $module) {
        $status = $module['status'];
        if (isset($summary[$status])) {
            $summary[$status]++;
        }
    }
    return $summary;
}
