<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/crm_config.php';
require_once __DIR__ . '/crm_auth.php';
require_once __DIR__ . '/crm_log.php';
require_once __DIR__ . '/crm_customer.php';
require_once __DIR__ . '/crm_visit.php';
require_once __DIR__ . '/crm_task_center.php';
require_once __DIR__ . '/crm_opportunity.php';
require_once __DIR__ . '/crm_mail.php';
require_once __DIR__ . '/crm_marketing.php';
require_once __DIR__ . '/crm_ai.php';
require_once __DIR__ . '/radar.php';
require_once __DIR__ . '/crm_settings_config.php';
require_once __DIR__ . '/crm_ui.php';
require_once __DIR__ . '/includes/user_admin_service.php';
require_login();
crm_run_schema_ensures();

$action = $_POST['action'] ?? $_GET['action'] ?? 'bootstrap';
$crmApiPerfStart = microtime(true);
register_shutdown_function(static function () use ($crmApiPerfStart, $action): void {
    $elapsedMs = (int)round((microtime(true) - $crmApiPerfStart) * 1000);
    if ($elapsedMs < 150) {
        return;
    }
    $safeAction = preg_replace('/[^a-zA-Z0-9_\\-]/', '', (string)$action) ?: 'unknown';
    $logDir = __DIR__ . '/storage';
    $logFile = is_dir($logDir) && is_writable($logDir) ? $logDir . '/crm_api_perf.log' : sys_get_temp_dir() . '/crm_api_perf.log';
    $line = json_encode([
        'time' => date('Y-m-d H:i:s'),
        'action' => $safeAction,
        'elapsed_ms' => $elapsedMs,
        'memory_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'referer' => basename(parse_url((string)($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH) ?: ''),
        'status' => http_response_code(),
    ], JSON_UNESCAPED_UNICODE);
    if ($line !== false) {
        @file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
});

function crm_api_release_session_lock(): void
{
    current_user();
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

try {
    if ($action === 'bootstrap') {
        $user = current_user();
        crm_touch_online('workspace');
        $permissionState = crm_permission_state([
            'contact.view',
            'follow.view',
            'visit.view',
            'visit.create',
            'visit.edit',
            'visit.confirm',
            'visit.result',
            'visit.export',
            'visit.report',
            'visit.file_upload',
            'visit.file_delete',
            'visit.file_preview',
            'visit.file_download',
            'task.view',
            'task.view_all',
            'task.view_department',
            'task.create',
            'task.edit',
            'task.delete',
            'task.complete',
            'task.delay',
            'sample.view',
            'sample.create',
            'sample.edit',
            'sample.upload_image',
            'sample.delete_image',
            'sample.upload_file',
            'sample.delete_file',
            'sample.tracking',
            'sample.shipped',
            'sample.signed',
            'sample.export',
            'opportunity.view',
            'opportunity.create',
            'opportunity.edit',
            'opportunity.delete',
            'opportunity.stage',
            'opportunity.win',
            'opportunity.lose',
            'opportunity.export',
            'opportunity.report',
            'opportunity.file_upload',
            'opportunity.file_delete',
            'opportunity.file_preview',
            'opportunity.file_download',
            'customer.mail_summary',
            'customer.quote_summary',
            'customer.plm_summary',
            'customer.bom_summary',
            'customer.dispatch_summary',
            'customer.order_summary',
            'customer.material_summary',
            'customer.graph_manage',
            'customer.view_logs',
            'customer.lead_pool_view',
            'customer.lead_pool',
            'customer.merge',
            'customer.timeline_view',
            'customer.event_manage',
            'customer.file_upload',
            'customer.view',
            'customer.create',
            'customer.edit',
            'customer.delete',
            'mail.view',
            'mail.view_own',
            'mail.account_bind_own',
            'mail.sync',
            'mail.reply',
            'mail.send',
            'mail.delete',
            'mail.attachment_download',
            'mail.link_customer',
            'mail.signature_manage_own',
            'mail.signature_batch_apply',
            'mail.view_logs',
            'promotion.view',
            'promotion.manage',
            'promotion.task_create',
            'promotion.execute',
            'promotion.analytics',
            'ai.view',
            'ai.lead_capture',
            'ai.quote_draft',
            'ai.material_draft',
            'ai.confirm_customer',
            'ai.confirm_opportunity',
            'ai.confirm_quote',
            'ai.confirm_material',
            'ai.reject',
            'ai.logs',
            'ai.settings',
            'radar_view',
            'radar_seed_manage',
            'radar_profile_manage',
            'radar_task_create',
            'radar_task_run',
            'radar_task_pause',
            'radar_candidate_view',
            'radar_candidate_review',
            'radar_candidate_delete',
            'radar_candidate_to_crm',
            'radar_rule_manage',
            'radar_cost_view',
            'radar_log_view',
            'radar_settings_manage',
            'radar_feedback_submit',
            'radar_template_view',
            'radar_template_create',
            'radar_template_edit',
            'radar_template_disable',
            'radar_template_delete',
            'radar_template_restore',
            'radar_template_export',
            'radar_template_import',
            'radar_template_create_task',
        ]);
        api_response(true, '', [
            'modules' => crm_allowed_modules(),
            'actions' => crm_action_map(),
            'preferences' => crm_preferences((int)$user['id']),
            'config' => crm_config_bootstrap(),
            'module_settings' => crm_module_settings_all(),
            'permissions' => $permissionState,
            'online_count' => crm_online_count(),
        ]);
    }

    if ($action === 'preferences_save') {
        require_csrf();
        crm_require('crm.preferences.edit');
        $user = current_user();
        $prefs = crm_save_preferences((int)$user['id'], $_POST);
        api_response(true, '偏好已保存', ['preferences' => $prefs]);
    }

    if ($action === 'self_profile_get') {
        api_response(true, '', ['user' => user_self_profile_row((int)current_user()['id'])]);
    }
    if ($action === 'self_profile_save') {
        require_csrf();
        api_response(true, '账号资料已保存', ['user' => user_self_update_profile($_POST)]);
    }
    if ($action === 'self_password_change') {
        require_csrf();
        user_self_change_password($_POST);
        api_response(true, '密码已修改，下次登录请使用新密码。');
    }

    if ($action === 'company_settings_save') {
        require_csrf();
        crm_require('settings.view');
        api_response(true, '企业信息已保存', ['company' => save_company_settings($_POST)]);
    }
    if ($action === 'module_settings_get') {
        require_csrf();
        crm_require('settings.view');
        api_response(true, '', ['module_settings' => crm_module_settings_all()]);
    }
    if ($action === 'module_settings_save') {
        require_csrf();
        crm_require('settings.view');
        $section = preg_replace('/[^a-z0-9_\\-]/i', '', (string)($_POST['section'] ?? ''));
        api_response(true, '模块设置已保存', ['section' => $section, 'settings' => crm_save_module_setting($section, $_POST)]);
    }
    if ($action === 'radar_bootstrap') {
        api_response(true, '', radar_bootstrap());
    }
    if ($action === 'radar_upgrade') {
        require_csrf();
        api_response(true, '客户雷达数据库升级完成', radar_upgrade_run());
    }
    if ($action === 'radar_settings_save') {
        require_csrf();
        api_response(true, '客户雷达设置已保存', radar_settings_save($_POST));
    }
    if ($action === 'radar_seed_list') {
        api_response(true, '', radar_seed_list($_POST));
    }
    if ($action === 'radar_seed_get') {
        api_response(true, '', radar_seed_get((int)($_POST['id'] ?? 0)));
    }
    if ($action === 'radar_seed_save') {
        require_csrf();
        api_response(true, '种子客户已保存', radar_seed_save($_POST));
    }
    if ($action === 'radar_seed_delete') {
        require_csrf();
        api_response(true, '种子客户已删除', radar_seed_soft_delete($_POST));
    }
    if ($action === 'radar_seed_status') {
        require_csrf();
        api_response(true, '种子客户状态已更新', radar_seed_status($_POST));
    }
    if ($action === 'radar_seed_batch') {
        require_csrf();
        api_response(true, '批量操作已完成', radar_seed_batch($_POST));
    }
    if ($action === 'radar_initial_import') {
        require_csrf();
        api_response(true, '首批种子和关键词已导入', radar_import_initial_data());
    }
    if ($action === 'radar_seed_import_text') {
        require_csrf();
        api_response(true, '种子客户批量导入完成', radar_seed_import_text($_POST));
    }
    if ($action === 'radar_profiles_list') {
        api_response(true, '', radar_profiles_list($_POST));
    }
    if ($action === 'radar_profiles_generate') {
        require_csrf();
        api_response(true, '客户画像已生成', radar_generate_all_profiles(!empty($_POST['force'])));
    }
    if ($action === 'radar_profile_save') {
        require_csrf();
        api_response(true, '客户画像已保存', radar_profile_save($_POST));
    }
    if ($action === 'radar_keywords_list') {
        api_response(true, '', radar_keywords_list($_POST));
    }
    if ($action === 'radar_keyword_save') {
        require_csrf();
        api_response(true, '关键词已保存', radar_keyword_save($_POST));
    }
    if ($action === 'radar_keyword_delete') {
        require_csrf();
        api_response(true, '关键词已更新', radar_keyword_delete($_POST));
    }
    if ($action === 'radar_keyword_import_text') {
        require_csrf();
        api_response(true, '关键词批量导入完成', radar_keyword_import_text($_POST));
    }
    if ($action === 'radar_tasks_list') {
        api_response(true, '', radar_tasks_list($_POST));
    }
    if ($action === 'radar_task_get') {
        api_response(true, '', radar_task_get((int)($_POST['id'] ?? 0)));
    }
    if ($action === 'radar_task_save') {
        require_csrf();
        api_response(true, '搜索任务已保存', radar_task_save($_POST));
    }
    if ($action === 'radar_task_start') {
        require_csrf();
        api_response(true, '搜索任务已进入队列', radar_task_start((int)($_POST['id'] ?? 0)));
    }
    if ($action === 'radar_task_pause') {
        require_csrf();
        api_response(true, '搜索任务已暂停', radar_task_control((int)($_POST['id'] ?? 0), 'pause'));
    }
    if ($action === 'radar_task_resume') {
        require_csrf();
        api_response(true, '搜索任务已继续', radar_task_control((int)($_POST['id'] ?? 0), 'resume'));
    }
    if ($action === 'radar_task_cancel') {
        require_csrf();
        api_response(true, '搜索任务已取消', radar_task_control((int)($_POST['id'] ?? 0), 'cancel'));
    }
    if ($action === 'radar_task_delete') {
        require_csrf();
        api_response(true, '搜索任务已删除', radar_task_delete((int)($_POST['id'] ?? 0)));
    }
    if ($action === 'radar_task_reorder') {
        require_csrf();
        api_response(true, '搜索任务顺序已保存', radar_task_reorder($_POST));
    }
    if ($action === 'radar_task_copy') {
        require_csrf();
        api_response(true, '搜索任务已复制', radar_task_copy((int)($_POST['id'] ?? 0)));
    }
    if ($action === 'radar_worker_run') {
        require_csrf();
        api_response(true, '后台队列已执行', radar_worker_run(max(1, min(50, (int)($_POST['limit'] ?? 10)))));
    }
    if ($action === 'radar_search_services_list') {
        api_response(true, '', radar_search_services_list());
    }
    if ($action === 'radar_search_service_save') {
        require_csrf();
        api_response(true, '搜索服务配置已保存', radar_search_service_save($_POST));
    }
    if ($action === 'radar_export') {
        require_csrf();
        api_response(true, '', radar_export_rows((string)($_POST['type'] ?? 'seeds')));
    }

    if ($action === 'crm_config_bootstrap') {
        crm_require('crm.config.view');
        api_response(true, '', [
            'config' => crm_config_bootstrap(),
            'permissions' => crm_permission_state([
                'contact.view',
                'follow.view',
                'visit.view',
                'visit.create',
                'visit.edit',
                'visit.confirm',
                'visit.result',
                'visit.export',
                'visit.report',
                'visit.file_upload',
                'visit.file_delete',
                'visit.file_preview',
                'visit.file_download',
                'task.view',
                'task.view_all',
                'task.view_department',
                'task.create',
                'task.edit',
                'task.delete',
                'task.complete',
                'task.delay',
                'sample.view',
                'sample.create',
                'sample.edit',
                'sample.upload_image',
                'sample.delete_image',
                'sample.upload_file',
                'sample.delete_file',
                'sample.tracking',
                'sample.shipped',
                'sample.signed',
                'sample.export',
                'opportunity.view',
                'opportunity.create',
                'opportunity.edit',
                'opportunity.delete',
                'opportunity.stage',
                'opportunity.win',
                'opportunity.lose',
                'opportunity.export',
                'opportunity.report',
                'opportunity.file_upload',
                'opportunity.file_delete',
                'opportunity.file_preview',
                'opportunity.file_download',
                'customer.mail_summary',
                'customer.quote_summary',
                'customer.plm_summary',
                'customer.bom_summary',
                'customer.dispatch_summary',
                'customer.order_summary',
                'customer.material_summary',
                'customer.graph_manage',
                'customer.view_logs',
                'customer.lead_pool_view',
                'customer.lead_pool',
                'customer.merge',
                'customer.timeline_view',
                'customer.event_manage',
                'customer.file_upload',
                'customer.view',
                'customer.create',
                'customer.edit',
                'customer.delete',
                'radar_view',
                'radar_seed_manage',
                'radar_profile_manage',
                'radar_task_create',
                'radar_task_run',
                'radar_task_pause',
                'radar_candidate_view',
                'radar_candidate_review',
                'radar_candidate_delete',
                'radar_candidate_to_crm',
                'radar_rule_manage',
                'radar_cost_view',
                'radar_log_view',
                'radar_settings_manage',
                'radar_feedback_submit',
                'radar_template_view',
                'radar_template_create',
                'radar_template_edit',
                'radar_template_disable',
                'radar_template_delete',
                'radar_template_restore',
                'radar_template_export',
                'radar_template_import',
                'radar_template_create_task',
            ]),
        ]);
    }
    if ($action === 'dictionary_item_save') {
        require_csrf();
        api_response(true, '配置项已保存', crm_dictionary_save_item($_POST));
    }
    if ($action === 'dictionary_item_disable') {
        require_csrf();
        api_response(true, '配置项已停用', crm_dictionary_disable_item((string)($_POST['type_key'] ?? ''), (string)($_POST['item_key'] ?? '')));
    }
    if ($action === 'rule_save') {
        require_csrf();
        $config = $_POST['config'] ?? [];
        if (is_string($config)) $config = json_decode($config, true) ?: [];
        api_response(true, '规则已保存', ['config' => crm_rule_save((string)($_POST['rule_key'] ?? ''), $config)]);
    }
    if ($action === 'field_config_save') {
        require_csrf();
        api_response(true, '字段配置已保存', ['fields' => crm_field_config_save($_POST)]);
    }

    if ($action === 'online_heartbeat') {
        require_csrf();
        crm_api_release_session_lock();
        $module = preg_replace('/[^a-z0-9_\\-]/i', '', (string)($_POST['module'] ?? 'workspace')) ?: 'workspace';
        crm_touch_online($module);
        api_response(true, 'online', ['count' => crm_online_count()]);
    }

    if ($action === 'online_leave') {
        require_csrf();
        $module = preg_replace('/[^a-z0-9_\\-]/i', '', (string)($_POST['module'] ?? 'workspace')) ?: 'workspace';
        crm_mark_online_leave($module);
        api_response(true, 'offline', ['count' => crm_online_count()]);
    }

    if ($action === 'presence_ping') {
        crm_api_release_session_lock();
        $module = preg_replace('/[^a-z0-9_\\-]/i', '', (string)($_POST['module'] ?? $_GET['module'] ?? 'dashboard')) ?: 'dashboard';
        crm_touch_online($module);
        api_response(true, 'online', ['count' => crm_online_count()]);
    }

    if ($action === 'online_list') {
        require_csrf();
        crm_require('online.view');
        crm_api_release_session_lock();
        $people = crm_online_people();
        if (!is_super_admin() && !has_permission('online.view_all')) {
            $current = current_user();
            $dept = $current['department_name'] ?? '';
            $people = array_values(array_filter($people, fn($row) => ($row['department'] ?? '') === $dept));
        }
        api_response(true, '', ['count' => crm_online_count(), 'people' => $people]);
    }

    if ($action === 'dashboard_online_users') {
        crm_require('online.view');
        api_response(true, '', crm_dashboard_online_users());
    }
    if ($action === 'dashboard_recent_logs') {
        crm_require('logs.view_own');
        api_response(true, '', crm_dashboard_recent_logs());
    }
    if ($action === 'dashboard_quote_order_board') {
        crm_require('quote.view');
        api_response(true, '', crm_dashboard_quote_order_board($_POST + $_GET));
    }
    if ($action === 'dashboard_team_sales') {
        api_response(true, '', crm_dashboard_team_sales_compare($_POST + $_GET));
    }
    if ($action === 'dashboard_customer_order_rank') {
        api_response(true, '', crm_dashboard_customer_order_rank($_POST + $_GET));
    }
    if ($action === 'dashboard_customer_amount_rank') {
        api_response(true, '', crm_dashboard_customer_amount_rank($_POST + $_GET));
    }
    if ($action === 'dashboard_customer_quote_rank') {
        api_response(true, '', crm_dashboard_customer_quote_rank($_POST + $_GET));
    }
    if ($action === 'dashboard_ar_customer_rank') {
        api_response(true, '', crm_dashboard_ar_customer_rank($_POST + $_GET));
    }
    if ($action === 'dashboard_team_ar_rank') {
        api_response(true, '', crm_dashboard_team_ar_rank($_POST + $_GET));
    }
    if ($action === 'dashboard_potential_customers') {
        api_response(true, '', crm_dashboard_high_potential_customers($_POST + $_GET));
    }
    if ($action === 'dashboard_silent_customers') {
        api_response(true, '', crm_dashboard_silent_customers());
    }

    if ($action === 'notification_summary') {
        require_csrf();
        $category = (string)($_POST['category'] ?? $_GET['category'] ?? 'all');
        $limit = (int)($_POST['limit'] ?? $_GET['limit'] ?? 8);
        api_response(true, '', function_exists('notification_summary') ? notification_summary((int)(current_user()['id'] ?? 0), $limit, $category) : ['unread_count' => 0, 'items' => [], 'category_counts' => []]);
    }
    if ($action === 'notification_list') {
        require_csrf();
        $category = (string)($_POST['category'] ?? $_GET['category'] ?? 'all');
        $limit = max(1, min(200, (int)($_POST['limit'] ?? $_GET['limit'] ?? 80)));
        api_response(true, '', function_exists('notification_summary') ? notification_summary((int)(current_user()['id'] ?? 0), $limit, $category) : ['items' => [], 'unread_count' => 0, 'category_counts' => [], 'pending_interface' => true, 'message' => '通知接口待接入']);
    }
    if ($action === 'notification_unread_count') {
        require_csrf();
        $uid = (int)(current_user()['id'] ?? 0);
        crm_api_release_session_lock();
        api_response(true, '', [
            'unread_count' => function_exists('notification_unread_count') ? notification_unread_count($uid) : 0,
            'category_counts' => function_exists('notification_category_counts') ? notification_category_counts($uid) : [],
        ]);
    }
    if ($action === 'notification_mark_read') {
        require_csrf();
        $ids = $_POST['ids'] ?? ($_POST['id'] ?? []);
        if (is_string($ids)) $ids = json_decode($ids, true) ?: preg_split('/\s*,\s*/', $ids);
        $affected = function_exists('notification_mark_read') ? notification_mark_read($ids, (int)(current_user()['id'] ?? 0)) : 0;
        api_response(true, '通知已标记为已读', ['affected' => $affected, 'unread_count' => notification_unread_count((int)(current_user()['id'] ?? 0))]);
    }
    if ($action === 'notification_mark_all_read') {
        require_csrf();
        $category = (string)($_POST['category'] ?? 'all');
        $affected = function_exists('notification_mark_all_read') ? notification_mark_all_read((int)(current_user()['id'] ?? 0), $category) : 0;
        api_response(true, '已全部标记为已读', ['affected' => $affected, 'unread_count' => notification_unread_count((int)(current_user()['id'] ?? 0))]);
    }
    if ($action === 'notification_archive') {
        require_csrf();
        $ids = $_POST['ids'] ?? ($_POST['id'] ?? []);
        if (is_string($ids)) $ids = json_decode($ids, true) ?: preg_split('/\s*,\s*/', $ids);
        $affected = function_exists('notification_archive') ? notification_archive($ids, (int)(current_user()['id'] ?? 0)) : 0;
        api_response(true, '通知已归档', ['affected' => $affected]);
    }
    if ($action === 'notification_clear_read') {
        require_csrf();
        $affected = function_exists('notification_clear_read') ? notification_clear_read((int)(current_user()['id'] ?? 0)) : 0;
        api_response(true, '已清除已读通知', ['affected' => $affected]);
    }
    if ($action === 'notification_create') {
        require_csrf();
        if (!is_super_admin() && !has_permission('settings.view')) {
            throw new RuntimeException('无权限创建系统通知。');
        }
        $targetUserId = (int)($_POST['user_id'] ?? current_user()['id'] ?? 0);
        $payload = $_POST['payload'] ?? [];
        if (is_string($payload)) $payload = json_decode($payload, true) ?: [];
        $ok = function_exists('create_system_notification') && create_system_notification($targetUserId, (string)($_POST['notification_type'] ?? $_POST['type'] ?? 'system_notice'), (string)($_POST['title'] ?? '系统通知'), (string)($_POST['content'] ?? ''), is_array($payload) ? $payload : []);
        api_response($ok, $ok ? '通知已创建' : '通知接口待接入', ['pending_interface' => !$ok]);
    }
    if ($action === 'notification_create_batch') {
        require_csrf();
        if (!is_super_admin() && !has_permission('settings.view')) {
            throw new RuntimeException('无权限批量创建通知。');
        }
        $items = $_POST['items'] ?? [];
        if (is_string($items)) $items = json_decode($items, true) ?: [];
        $created = 0;
        foreach (is_array($items) ? $items : [] as $item) {
            if (!is_array($item)) continue;
            $payload = $item['payload'] ?? [];
            if (is_string($payload)) $payload = json_decode($payload, true) ?: [];
            if (create_system_notification((int)($item['user_id'] ?? 0), (string)($item['notification_type'] ?? $item['type'] ?? 'system_notice'), (string)($item['title'] ?? '系统通知'), (string)($item['content'] ?? ''), is_array($payload) ? $payload : [])) $created++;
        }
        api_response(true, '通知批量创建完成', ['created' => $created]);
    }
    if ($action === 'notification_get_settings') {
        require_csrf();
        api_response(true, '', ['settings' => function_exists('notification_settings') ? notification_settings((int)(current_user()['id'] ?? 0)) : []]);
    }
    if ($action === 'notification_save_settings') {
        require_csrf();
        api_response(true, '通知设置已保存', ['settings' => function_exists('notification_save_settings') ? notification_save_settings($_POST, (int)(current_user()['id'] ?? 0)) : []]);
    }

    if ($action === 'log_event') {
        require_csrf();
        $module = preg_replace('/[^a-z0-9_\\-]/i', '', (string)($_POST['module'] ?? 'workspace')) ?: 'workspace';
        $event = preg_replace('/[^a-z0-9_\\-]/i', '', (string)($_POST['event'] ?? 'event')) ?: 'event';
        $target = substr((string)($_POST['target'] ?? ''), 0, 120);
        crm_log_event($module, $event, 'ui', $target);
        api_response(true, '已记录');
    }
    if ($action === 'crm_log_list') {
        api_response(true, '', crm_recent_operation_logs($_POST));
    }
    if ($action === 'crm_log_center') {
        require_csrf();
        api_response(true, '', crm_log_center($_POST));
    }

    if ($action === 'ai_bootstrap') {
        api_response(true, '', crm_ai_bootstrap($_POST));
    }
    if ($action === 'ai_analyze_text') {
        require_csrf();
        api_response(true, 'AI 识别已生成草稿和确认任务', crm_ai_create_task((string)($_POST['task_type'] ?? 'lead_capture'), $_POST));
    }
    if ($action === 'ai_create_customer_draft') {
        require_csrf();
        api_response(true, 'AI 客户草稿已生成，等待人工确认', crm_ai_create_task('lead_capture', array_replace($_POST, ['task_type' => 'lead_capture'])));
    }
    if ($action === 'ai_create_opportunity_draft') {
        require_csrf();
        api_response(true, 'AI 商机草稿已生成，等待人工确认', crm_ai_create_task('lead_capture', array_replace($_POST, ['task_type' => 'lead_capture'])));
    }
    if ($action === 'ai_create_confirm_task') {
        require_csrf();
        api_response(true, 'AI 确认任务已创建', crm_ai_create_task('confirm_task', $_POST));
    }
    if ($action === 'ai_create_quote_draft') {
        require_csrf();
        $draft = crm_ai_create_task('quote_draft', $_POST);
        $draft['quote_interface'] = crm_ai_pending_interface($action);
        api_response(true, 'AI 报价需求草稿已保存，报价系统草稿接口待接入', $draft, 'PENDING_INTERFACE');
    }
    if ($action === 'ai_create_material_draft') {
        require_csrf();
        $draft = crm_ai_create_task('material_draft', $_POST);
        $draft['material_interface'] = crm_ai_pending_interface($action);
        api_response(true, 'AI 资料需求草稿已保存，资料生成接口待接入', $draft, 'PENDING_INTERFACE');
    }
    if ($action === 'ai_get_confirm_task') {
        api_response(true, '', ['task' => crm_ai_task_get((int)($_POST['task_id'] ?? $_GET['task_id'] ?? 0))]);
    }
    if ($action === 'ai_task_list') {
        api_response(true, '', ['tasks' => crm_ai_tasks($_POST)]);
    }
    if ($action === 'ai_log_list') {
        api_response(true, '', ['logs' => crm_ai_logs($_POST)]);
    }
    if ($action === 'ai_update_confirm_result') {
        require_csrf();
        api_response(true, 'AI 确认状态已更新', crm_ai_update_confirm($_POST));
    }
    if ($action === 'ai_settings_save') {
        require_csrf();
        api_response(true, 'AI 设置已保存', crm_ai_settings_save($_POST));
    }
    if (in_array($action, ['ai_confirm_customer_create','ai_confirm_opportunity_create','ai_link_existing_customer','ai_get_quote_draft','ai_update_quote_draft','ai_confirm_quote','ai_send_quote_after_confirm','ai_get_customer_quote_history','ai_get_product_quote_options','ai_search_material','ai_create_material_task','ai_create_material_package_draft','ai_confirm_material','ai_send_material_after_confirm','ai_create_confirm_dispatch','ai_sync_confirm_result','ai_create_email_draft','ai_send_email_after_confirm'], true)) {
        api_response(true, '接口待接入', crm_ai_pending_interface($action), 'PENDING_INTERFACE');
    }

    if ($action === 'mail_account_get_own') {
        api_response(true, '', crm_mail_account_get_own($_POST));
    }
    if ($action === 'mail_dashboard_summary') {
        api_response(true, '', crm_mail_dashboard_summary());
    }
    if ($action === 'workspace_bootstrap') {
        api_response(true, '', crm_workspace_bootstrap($_POST));
    }
    if ($action === 'dashboard_fx') {
        api_response(true, '', crm_dashboard_fx($_POST + $_GET));
    }
    if ($action === 'dashboard_weather') {
        api_response(true, '', crm_dashboard_weather($_POST + $_GET));
    }
    if ($action === 'workspace_widgets_save') {
        require_csrf();
        $widgets = $_POST['widgets'] ?? [];
        if (is_string($widgets)) $widgets = json_decode($widgets, true) ?: [];
        api_response(true, '工作台组件已保存', crm_dashboard_save_widgets((int)(current_user()['id'] ?? 0), is_array($widgets) ? $widgets : []));
    }
    if ($action === 'dashboard_layout_get') {
        $userId = (int)(current_user()['id'] ?? 0);
        api_response(true, '', [
            'widgets' => crm_user_dashboard_widgets($userId),
            'hidden_widgets' => crm_dashboard_hidden_widget_keys($userId),
            'catalog' => crm_dashboard_catalog(),
        ]);
    }
    if ($action === 'dashboard_layout_save') {
        require_csrf();
        $widgets = $_POST['widgets'] ?? ($_POST['layout_json'] ?? []);
        if (is_string($widgets)) {
            $decoded = json_decode($widgets, true) ?: [];
            $widgets = isset($decoded['cards']) && is_array($decoded['cards']) ? $decoded['cards'] : $decoded;
        }
        api_response(true, '工作台布局已保存', crm_dashboard_save_widgets((int)(current_user()['id'] ?? 0), is_array($widgets) ? $widgets : []));
    }
    if ($action === 'dashboard_card_hide') {
        require_csrf();
        $data = crm_dashboard_set_card_visible((int)(current_user()['id'] ?? 0), (string)($_POST['card_id'] ?? $_GET['card_id'] ?? ''), false);
        api_response(true, '已隐藏该版块，可在工作台设置中恢复', $data);
    }
    if ($action === 'dashboard_card_show') {
        require_csrf();
        $data = crm_dashboard_set_card_visible((int)(current_user()['id'] ?? 0), (string)($_POST['card_id'] ?? $_GET['card_id'] ?? ''), true);
        api_response(true, '已恢复该版块', $data);
    }
    if ($action === 'dashboard_layout_reset') {
        require_csrf();
        api_response(true, '已恢复默认工作台布局', crm_dashboard_reset_layout((int)(current_user()['id'] ?? 0)));
    }
    if ($action === 'mail_account_set_current') {
        require_csrf();
        api_response(true, '邮箱已切换', crm_mail_account_set_current((int)($_POST['mail_account_id'] ?? 0), $_POST));
    }
    if ($action === 'mail_account_save_own') {
        require_csrf();
        api_response(true, '邮箱绑定已保存', crm_mail_account_save_own($_POST));
    }
    if ($action === 'mail_account_delete_own') {
        require_csrf();
        api_response(true, '邮箱已删除，相关邮件和草稿已清空', crm_mail_account_delete_own((int)($_POST['mail_account_id'] ?? 0), $_POST));
    }
    if ($action === 'mail_signature_template_save') {
        require_csrf();
        api_response(true, '公司签名模板已保存', crm_mail_signature_template_save($_POST));
    }
    if ($action === 'mail_signature_apply_batch_start') {
        require_csrf();
        api_response(true, '公司签名已批量应用', crm_mail_signature_apply_batch($_POST));
    }
    if ($action === 'mail_account_test_imap') {
        require_csrf();
        api_response(true, '收信连接测试完成', crm_mail_account_test('imap', $_POST));
    }
    if ($action === 'mail_account_test_smtp') {
        require_csrf();
        api_response(true, '发信连接测试完成', crm_mail_account_test('smtp', $_POST));
    }
    if ($action === 'mail_list' || $action === 'mail_search') {
        api_response(true, '', crm_mail_list($_POST));
    }
    if ($action === 'mail_folder_counts') {
        api_response(true, '', ['folder_counts' => crm_mail_folder_counts()]);
    }
    if ($action === 'mail_get') {
        api_response(true, '', crm_mail_get((int)($_POST['mail_id'] ?? 0), (string)($_POST['include_crm'] ?? '1') !== '0'));
    }
    if ($action === 'mail_crm_context') {
        api_response(true, '', crm_mail_crm_context((int)($_POST['mail_id'] ?? 0)));
    }
    if ($action === 'mail_sync_start') {
        require_csrf();
        api_response(true, '收信同步已开始', crm_mail_sync_start($_POST));
    }
    if ($action === 'mail_sync_progress') {
        crm_api_release_session_lock();
        api_response(true, '', crm_mail_sync_progress((string)($_POST['sync_id'] ?? '')));
    }
    if ($action === 'mail_send_start' || $action === 'mail_reply' || $action === 'mail_reply_all' || $action === 'mail_forward') {
        require_csrf();
        api_response(true, '发送任务已创建', crm_mail_send_start($_POST, $_FILES));
    }
    if ($action === 'mail_send_progress') {
        crm_api_release_session_lock();
        api_response(true, '', crm_mail_send_progress((string)($_POST['job_id'] ?? '')));
    }
    if ($action === 'mail_send_cancel') {
        require_csrf();
        api_response(true, '待发送邮件已取消', crm_mail_send_cancel((string)($_POST['job_id'] ?? '')));
    }
    if ($action === 'mail_save_draft') {
        require_csrf();
        api_response(true, '草稿已保存', crm_mail_save_draft($_POST, $_FILES));
    }
    if ($action === 'mail_draft_get') {
        api_response(true, '', crm_mail_draft_get((int)($_POST['draft_id'] ?? 0)));
    }
    if ($action === 'mail_draft_delete') {
        require_csrf();
        api_response(true, '草稿已删除', crm_mail_draft_delete($_POST));
    }
    if ($action === 'mail_diagnostics') {
        require_csrf();
        api_response(true, '', crm_mail_diagnostics());
    }
    if ($action === 'mail_remote_image') {
        $image = crm_mail_remote_image_proxy((string)($_GET['url'] ?? $_POST['url'] ?? ''));
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: ' . $image['mime_type']);
        header('Content-Length: ' . (string)$image['file_size']);
        header('Cache-Control: private, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        echo $image['content'];
        exit;
    }
    if ($action === 'mail_datasheet_search') {
        require_csrf();
        api_response(true, '资料附件已查询', crm_mail_datasheet_search($_POST));
    }
    if ($action === 'mail_recipient_search') {
        require_csrf();
        api_response(true, '', crm_mail_recipient_search($_POST));
    }
    if ($action === 'mail_datasheet_preview') {
        $preview = crm_mail_datasheet_preview_file($_GET + $_POST);
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: ' . $preview['mime']);
        header('Content-Length: ' . (string)filesize($preview['path']));
        header('Content-Disposition: inline; filename="' . rawurlencode($preview['name']) . '"; filename*=UTF-8\'\'' . rawurlencode($preview['name']));
        header('X-Content-Type-Options: nosniff');
        readfile($preview['path']);
        if (!empty($preview['temporary'])) @unlink($preview['path']);
        exit;
    }
    if ($action === 'mail_link_customer') {
        require_csrf();
        api_response(true, '邮件已关联客户', crm_mail_link_customer((int)($_POST['mail_id'] ?? 0), (int)($_POST['customer_id'] ?? 0)));
    }
    if ($action === 'mail_current_related') {
        require_csrf();
        api_response(true, '', crm_mail_current_related_mails((int)($_POST['mail_id'] ?? 0)));
    }
    if ($action === 'mail_attachment_download') {
        $inline = !empty($_GET['inline']) || !empty($_POST['inline']);
        $attachment = crm_mail_attachment_for_download((int)($_POST['attachment_id'] ?? $_GET['attachment_id'] ?? 0), $inline);
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: ' . $attachment['mime_type']);
        header('Content-Length: ' . (string)$attachment['file_size']);
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . rawurlencode($attachment['file_name']) . '"; filename*=UTF-8\'\'' . rawurlencode($attachment['file_name']));
        header('X-Content-Type-Options: nosniff');
        readfile($attachment['path']);
        exit;
    }
    if ($action === 'mail_attachment_preview') {
        $preview = crm_mail_attachment_preview((int)($_POST['attachment_id'] ?? $_GET['attachment_id'] ?? 0));
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: ' . $preview['mime_type']);
        header('Content-Length: ' . (string)$preview['file_size']);
        header('Content-Disposition: inline; filename="' . rawurlencode($preview['name']) . '"; filename*=UTF-8\'\'' . rawurlencode($preview['name']));
        header('X-Content-Type-Options: nosniff');
        readfile($preview['path']);
        exit;
    }
    if ($action === 'mail_local_attachment_preview') {
        require_csrf();
        $preview = crm_mail_local_attachment_preview($_FILES);
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: ' . $preview['mime_type']);
        header('Content-Length: ' . (string)$preview['file_size']);
        header('Content-Disposition: inline; filename="' . rawurlencode($preview['name']) . '"; filename*=UTF-8\'\'' . rawurlencode($preview['name']));
        header('X-Content-Type-Options: nosniff');
        readfile($preview['path']);
        exit;
    }
    if ($action === 'mail_mark_read') {
        require_csrf();
        api_response(true, '邮件已标记为已读', crm_mail_apply_action($_POST, 'read'));
    }
    if ($action === 'mail_mark_unread') {
        require_csrf();
        api_response(true, '邮件已标记为未读', crm_mail_apply_action($_POST, 'unread'));
    }
    if ($action === 'mail_archive') {
        require_csrf();
        api_response(true, '邮件已归档', crm_mail_apply_action($_POST, 'archive'));
    }
    if ($action === 'mail_delete') {
        require_csrf();
        api_response(true, '邮件已删除', crm_mail_apply_action($_POST, 'delete'));
    }
    if ($action === 'mail_recall') {
        require_csrf();
        api_response(true, '撤回通知已发送', crm_mail_recall_sent_mail((int)($_POST['mail_id'] ?? 0)));
    }
    if ($action === 'marketing_bootstrap') {
        api_response(true, '', crm_marketing_bootstrap($_POST));
    }
    if ($action === 'marketing_pool_view') {
        api_response(true, '', crm_marketing_pool_view($_POST));
    }
    if ($action === 'marketing_contacts') {
        api_response(true, '', crm_marketing_contact_strategy_view($_POST));
    }
    if ($action === 'marketing_group_save') {
        require_csrf();
        api_response(true, '推广分组已保存', crm_marketing_group_save($_POST));
    }
    if ($action === 'marketing_group_delete') {
        require_csrf();
        api_response(true, '推广分组已删除', crm_marketing_group_delete($_POST));
    }
    if ($action === 'marketing_group_copy') {
        require_csrf();
        api_response(true, '推广分组已复制', crm_marketing_group_copy($_POST));
    }
    if ($action === 'marketing_group_status_update') {
        require_csrf();
        api_response(true, '客户组状态已更新', crm_marketing_group_status_update($_POST));
    }
    if ($action === 'marketing_group_customer_update') {
        require_csrf();
        api_response(true, '推广分组客户已更新', crm_marketing_group_customer_update($_POST));
    }
    if ($action === 'marketing_task_create') {
        require_csrf();
        api_response(true, '推广任务已创建', crm_marketing_task_create($_POST));
    }
    if ($action === 'marketing_target_preview') {
        require_csrf();
        api_response(true, '', crm_marketing_target_preview($_POST));
    }
    if ($action === 'marketing_queue_build') {
        require_csrf();
        api_response(true, '推广发送队列已生成', crm_marketing_queue_build($_POST));
    }
    if ($action === 'marketing_queue_list' || $action === 'marketing_queue_status') {
        api_response(true, '', crm_marketing_queue_list($_POST));
    }
    if ($action === 'marketing_queue_retry_failed') {
        require_csrf();
        api_response(true, '失败队列已放回重试', crm_marketing_queue_retry_failed($_POST));
    }
    if ($action === 'marketing_queue_cancel') {
        require_csrf();
        api_response(true, '未发送队列已取消', crm_marketing_queue_cancel($_POST));
    }
    if ($action === 'marketing_test_send') {
        require_csrf();
        api_response(true, '测试邮件已发送', crm_marketing_test_send($_POST));
    }
    if ($action === 'marketing_task_copy') {
        require_csrf();
        api_response(true, '推广项目已复制', crm_marketing_task_copy($_POST));
    }
    if ($action === 'marketing_task_update') {
        require_csrf();
        api_response(true, '推广任务已保存', crm_marketing_task_update($_POST));
    }
    if ($action === 'marketing_task_delete') {
        require_csrf();
        api_response(true, '推广任务已删除', crm_marketing_task_delete($_POST));
    }
    if ($action === 'marketing_template_copy') {
        require_csrf();
        api_response(true, '邮件模板已复制', crm_marketing_template_copy($_POST));
    }
    if ($action === 'marketing_customer_status_update') {
        require_csrf();
        api_response(true, '客户推广状态已更新', crm_marketing_update_customer_status($_POST));
    }
    if ($action === 'marketing_contact_strategy_update') {
        require_csrf();
        api_response(true, '联系人推广策略已更新', crm_marketing_update_contact_strategy($_POST));
    }
    if ($action === 'marketing_task_status') {
        require_csrf();
        api_response(true, '推广任务状态已更新', crm_marketing_task_set_status($_POST));
    }
    if ($action === 'marketing_task_execute') {
        require_csrf();
        api_response(true, '推广任务已执行', crm_marketing_task_execute($_POST));
    }
    if ($action === 'marketing_manual_execute') {
        require_csrf();
        api_response(true, '手动执行已记录', crm_marketing_manual_execute($_POST, $_FILES));
    }
    if ($action === 'marketing_task_targets') {
        api_response(true, '', ['targets' => crm_marketing_task_targets($_POST)]);
    }
    if ($action === 'marketing_task_report') {
        api_response(true, '', ['report' => crm_marketing_task_report($_POST)]);
    }
    if ($action === 'marketing_task_logs') {
        api_response(true, '', ['logs' => crm_marketing_logs($_POST)]);
    }
    if ($action === 'marketing_failure_handle') {
        require_csrf();
        api_response(true, '失败目标已处理', crm_marketing_failure_handle($_POST));
    }
    if ($action === 'marketing_log_touch') {
        require_csrf();
        api_response(true, '推广记录已写入', crm_marketing_log_touch($_POST));
    }
    if ($action === 'mail_dispatch_options') {
        api_response(true, '', crm_mail_dispatch_options());
    }
    if ($action === 'mail_create_dispatch') {
        require_csrf();
        api_response(true, '邮件已转派工', crm_mail_create_dispatch($_POST));
    }
    if ($action === 'mail_create_task') {
        require_csrf();
        api_response(true, '邮件任务已创建', crm_mail_create_task_from_mail($_POST));
    }
    if ($action === 'mail_customer_prefill') {
        api_response(true, '', crm_mail_customer_prefill((int)($_POST['mail_id'] ?? 0)));
    }
    if ($action === 'mail_create_customer') {
        require_csrf();
        api_response(true, '客户已创建并关联邮件', crm_mail_create_customer_from_mail($_POST));
    }
    if ($action === 'mail_create_lead_pool') {
        require_csrf();
        api_response(true, '邮件客户已转入暂存池', crm_mail_create_lead_from_mail($_POST));
    }
    if ($action === 'mail_create_followup') {
        require_csrf();
        api_response(true, '邮件跟进已创建', crm_mail_create_followup_from_mail($_POST));
    }
    if ($action === 'mail_save_attachment_to_customer') {
        require_csrf();
        api_response(true, '附件已保存到客户', crm_mail_save_attachment_to_customer($_POST));
    }
    if ($action === 'mail_followup_status') {
        require_csrf();
        api_response(true, '未回复状态已更新', crm_mail_followup_action($_POST));
    }
    if (in_array($action, ['mail_create_contact','mail_create_quote','mail_generate_material','mail_save_attachment_to_material','mail_attachment_preview','mail_tag_update','mail_mark_read','mail_mark_unread','mail_delete','mail_archive','mail_star'], true)) {
        require_csrf();
        api_response(true, '接口已预留', crm_mail_reserved_action($action, $_POST));
    }

    if ($action === 'customer_list') {
        crm_require('customer.view');
        api_response(true, '', crm_customer_list($_POST));
    }
    if ($action === 'customer_get') {
        crm_require('customer.view');
        api_response(true, '', crm_customer_get((int)($_POST['customer_id'] ?? 0), (string)($_POST['detail'] ?? 'full')));
    }
    if ($action === 'customer_overview_stats') {
        crm_require('customer.view');
        api_response(true, '', crm_customer_overview_stats());
    }
    if ($action === 'crm_linkage_data') {
        crm_require('customer.view');
        api_response(true, '', crm_linkage_data($_POST));
    }
    if ($action === 'customer_graph_options') {
        crm_require('customer.view');
        api_response(true, '', crm_graph_options());
    }
    if ($action === 'customer_duplicate_check') {
        crm_require('customer.view');
        api_response(true, '', ['matches' => crm_customer_duplicate_matches($_POST, (int)($_POST['ignore_id'] ?? 0))]);
    }
    if ($action === 'customer_create') {
        require_csrf();
        api_response(true, '客户已进入暂存池，请完成查重确认', crm_customer_create($_POST));
    }
    if ($action === 'customer_create_confirmed') {
        require_csrf();
        api_response(true, '客户已创建', crm_customer_create_confirmed($_POST));
    }
    if ($action === 'customer_import_preview') {
        require_csrf();
        api_response(true, '客户导入预览已生成', crm_customer_import_preview($_POST, $_FILES['file'] ?? null));
    }
    if ($action === 'customer_import_commit') {
        require_csrf();
        $rows = json_decode((string)($_POST['rows_json'] ?? '[]'), true);
        api_response(true, '客户导入已完成', crm_customer_import_commit(is_array($rows) ? $rows : []));
    }
    if ($action === 'customer_export') {
        crm_customer_export($_GET ?: $_POST);
        exit;
    }
    if ($action === 'lead_confirm_create') {
        require_csrf();
        $lead = crm_lead_pool_get((int)($_POST['lead_id'] ?? 0));
        $payload = array_merge($lead['payload'], $_POST);
        api_response(true, '客户已从暂存池确认创建', crm_customer_create_confirmed($payload));
    }
    if ($action === 'lead_pool_list') {
        require_csrf();
        api_response(true, '', crm_lead_pool_list($_POST));
    }
    if ($action === 'lead_use_existing') {
        require_csrf();
        api_response(true, '已关联到已有客户', ['lead' => crm_lead_pool_use_existing((int)($_POST['lead_id'] ?? 0), (int)($_POST['customer_id'] ?? 0))]);
    }
    if ($action === 'lead_update') {
        require_csrf();
        api_response(true, '暂存客户已保存', ['lead' => crm_lead_pool_update($_POST)]);
    }
    if ($action === 'customer_entry_use_existing') {
        require_csrf();
        $lead = crm_lead_pool_create($_POST);
        $customerId = (int)($_POST['target_customer_id'] ?? 0);
        api_response(true, '已记录为疑似重复并关联已有客户', ['lead' => crm_lead_pool_use_existing((int)$lead['lead']['id'], $customerId)]);
    }
    if ($action === 'lead_reject') {
        require_csrf();
        api_response(true, '暂存客户已丢弃', ['lead' => crm_lead_pool_reject((int)($_POST['lead_id'] ?? 0))]);
    }
    if ($action === 'duplicate_merge_cases') {
        require_csrf();
        api_response(true, '', crm_duplicate_merge_cases($_POST));
    }
    if ($action === 'duplicate_merge_scan') {
        require_csrf();
        api_response(true, '重复客户扫描完成', crm_duplicate_merge_scan());
    }
    if ($action === 'customer_relation_create') {
        require_csrf();
        api_response(true, '客户关系已保存', crm_customer_relation_create($_POST));
    }
    if ($action === 'customer_event_create') {
        require_csrf();
        api_response(true, '客户事件已保存', crm_customer_event_create($_POST));
    }
    if ($action === 'customer_chat_group_save') {
        require_csrf();
        api_response(true, '客户群已保存', crm_customer_chat_group_save($_POST));
    }
    if ($action === 'customer_chat_group_delete') {
        require_csrf();
        api_response(true, '客户群已删除', crm_customer_chat_group_delete($_POST));
    }
    if ($action === 'customer_update') {
        require_csrf();
        api_response(true, '客户已保存', crm_customer_update((int)($_POST['customer_id'] ?? 0), $_POST));
    }
    if ($action === 'customer_delete') {
        require_csrf();
        crm_customer_delete((int)($_POST['customer_id'] ?? 0), trim((string)($_POST['delete_reason'] ?? '')));
        api_response(true, '客户已删除');
    }
    if ($action === 'customer_restore') {
        require_csrf();
        crm_customer_restore((int)($_POST['customer_id'] ?? 0));
        api_response(true, '客户已恢复');
    }
    if ($action === 'customer_force_delete') {
        require_csrf();
        crm_customer_force_delete((int)($_POST['customer_id'] ?? 0), trim((string)($_POST['delete_reason'] ?? '')));
        api_response(true, '客户已强制删除');
    }
    if ($action === 'customer_batch_delete') {
        require_csrf();
        foreach ((array)($_POST['customer_ids'] ?? []) as $id) crm_customer_delete((int)$id, trim((string)($_POST['delete_reason'] ?? '批量删除')));
        api_response(true, '批量删除完成');
    }
    if ($action === 'customer_batch_force_delete') {
        require_csrf();
        foreach ((array)($_POST['customer_ids'] ?? []) as $id) crm_customer_force_delete((int)$id, trim((string)($_POST['delete_reason'] ?? '批量强制删除')));
        api_response(true, '批量强制删除完成');
    }
    if ($action === 'customer_batch_assign') {
        require_csrf();
        $parseIds = static function ($value): array {
            if (is_string($value)) {
                $trimmed = trim($value);
                if ($trimmed === '') return [];
                $decoded = json_decode($trimmed, true);
                $value = is_array($decoded) ? $decoded : preg_split('/[,，\s]+/', $trimmed);
            } elseif (!is_array($value)) {
                $value = [$value];
            }
            $ids = [];
            foreach ((array)$value as $item) {
                $id = (int)$item;
                if ($id > 0 && !in_array($id, $ids, true)) $ids[] = $id;
            }
            return $ids;
        };
        crm_batch_assign(
            $parseIds($_POST['customer_ids'] ?? []),
            (int)($_POST['owner_user_id'] ?? 0),
            $parseIds($_POST['owner_user_ids'] ?? [])
        );
        api_response(true, '批量分配完成');
    }
    if ($action === 'customer_assign_options') {
        api_response(true, '', crm_customer_assign_options());
    }
    if ($action === 'customer_batch_group_move' || $action === 'group_move_customers') {
        require_csrf();
        crm_group_move_customers(array_map('intval', (array)($_POST['customer_ids'] ?? [])), (int)($_POST['group_id'] ?? 0));
        api_response(true, '分组移动完成');
    }
    if ($action === 'customer_transfer_public') {
        require_csrf();
        crm_customer_transfer_public(array_map('intval', (array)($_POST['customer_ids'] ?? [])), trim((string)($_POST['reason'] ?? '')));
        api_response(true, '客户已转入公海');
    }
    if ($action === 'customer_claim_public') {
        require_csrf();
        api_response(true, '公海客户已领取', crm_customer_claim_public((int)($_POST['customer_id'] ?? 0)));
    }
    if ($action === 'customer_today_logs') {
        api_response(true, '', crm_customer_today_logs($_POST));
    }
    if ($action === 'group_list') {
        crm_require('customer.view');
        api_response(true, '', ['groups' => crm_customer_groups()]);
    }
    if ($action === 'group_create') {
        require_csrf();
        api_response(true, '分组已创建', crm_group_create($_POST));
    }
    if ($action === 'group_update') {
        require_csrf();
        api_response(true, '分组已保存', crm_group_update($_POST));
    }
    if ($action === 'group_delete') {
        require_csrf();
        api_response(true, '分组已删除', crm_group_delete((int)($_POST['group_id'] ?? 0)));
    }
    if ($action === 'contact_create') {
        require_csrf();
        api_response(true, '联系人已创建', crm_contact_create($_POST));
    }
    if ($action === 'contact_update') {
        require_csrf();
        api_response(true, '联系人已保存', crm_contact_update((int)($_POST['contact_id'] ?? 0), $_POST));
    }
    if ($action === 'contact_bulk_promotion_update') {
        require_csrf();
        api_response(true, '联系人推广方式已批量设置', crm_contact_bulk_update_promotions((int)($_POST['customer_id'] ?? 0), $_POST));
    }
    if ($action === 'contact_delete') {
        require_csrf();
        crm_contact_delete((int)($_POST['contact_id'] ?? 0));
        api_response(true, '联系人已删除');
    }
    if ($action === 'followup_create') {
        require_csrf();
        api_response(true, '跟进已创建', crm_followup_create($_POST));
    }
    if ($action === 'followup_get') {
        require_csrf();
        api_response(true, '', crm_followup_get((int)($_POST['followup_id'] ?? 0)));
    }
    if ($action === 'followup_update') {
        require_csrf();
        api_response(true, '跟进已保存', crm_followup_update((int)($_POST['followup_id'] ?? 0), $_POST));
    }
    if ($action === 'followup_delete') {
        require_csrf();
        api_response(true, '跟进已删除', crm_followup_delete($_POST));
    }
    if ($action === 'visit_options') {
        require_csrf();
        crm_require('visit.view');
        api_response(true, '', crm_visit_options());
    }
    if ($action === 'visit_list') {
        require_csrf();
        api_response(true, '', crm_visit_list($_POST));
    }
    if ($action === 'visit_save') {
        require_csrf();
        api_response(true, '拜访/来访已保存', crm_visit_save($_POST));
    }
    if ($action === 'visit_result_save') {
        require_csrf();
        api_response(true, '拜访/来访结果已保存', crm_visit_result_save((int)($_POST['visit_id'] ?? 0), $_POST));
    }
    if ($action === 'visit_dispatch_placeholder') {
        require_csrf();
        api_response(true, '派工接口待接入', crm_visit_dispatch_placeholder((int)($_POST['visit_id'] ?? 0), (string)($_POST['kind'] ?? 'visit_prepare')));
    }
    if ($action === 'visit_files') {
        require_csrf();
        crm_require('visit.view');
        api_response(true, '', ['files' => crm_visit_files((int)($_POST['visit_id'] ?? 0))]);
    }
    if ($action === 'visit_file_upload') {
        require_csrf();
        $files = $_FILES['files'] ?? $_FILES['attachments'] ?? [];
        api_response(true, '文件已上传', crm_visit_upload_files((int)($_POST['visit_id'] ?? 0), (string)($_POST['file_kind'] ?? 'attachment'), $files));
    }
    if ($action === 'visit_file_delete') {
        require_csrf();
        api_response(true, '文件已删除', crm_visit_delete_file((int)($_POST['file_id'] ?? 0)));
    }
    if ($action === 'visit_file_download') {
        crm_visit_stream_file((int)($_GET['file_id'] ?? $_POST['file_id'] ?? 0), !empty($_GET['inline']) || !empty($_POST['inline']));
    }
    if ($action === 'task_center_options') {
        require_csrf();
        api_response(true, '', crm_task_center_options());
    }
    if ($action === 'task_center_list') {
        require_csrf();
        api_response(true, '', crm_task_center_list($_POST));
    }
    if ($action === 'task_detail') {
        require_csrf();
        api_response(true, '', crm_task_detail((int)($_POST['task_id'] ?? 0)));
    }
    if ($action === 'task_save') {
        require_csrf();
        api_response(true, '任务已保存', crm_task_save($_POST));
    }
    if ($action === 'task_status_update') {
        require_csrf();
        api_response(true, '任务状态已更新', crm_task_update_status($_POST));
    }
    if ($action === 'task_delay') {
        require_csrf();
        api_response(true, '任务已延期', crm_task_delay($_POST));
    }
    if ($action === 'task_delete') {
        require_csrf();
        api_response(true, '任务已删除', crm_task_delete($_POST));
    }
    if ($action === 'sample_shipment_list') {
        require_csrf();
        api_response(true, '', crm_sample_shipments($_POST));
    }
    if ($action === 'sample_shipment_detail') {
        require_csrf();
        api_response(true, '', crm_sample_shipment_detail((int)($_POST['shipment_id'] ?? 0)));
    }
    if ($action === 'sample_shipment_save') {
        require_csrf();
        api_response(true, '样品寄送已保存', crm_sample_shipment_save($_POST));
    }
    if ($action === 'sample_shipment_status') {
        require_csrf();
        api_response(true, '样品寄送状态已更新', crm_sample_set_status($_POST));
    }
    if ($action === 'sample_shipment_quick_update') {
        require_csrf();
        api_response(true, '样品寄送已更新', crm_sample_quick_update($_POST));
    }
    if ($action === 'sample_shipment_delete') {
        require_csrf();
        api_response(true, '样品寄送已删除', crm_sample_shipment_delete($_POST));
    }
    if ($action === 'sample_shipment_files') {
        require_csrf();
        crm_require('sample.view');
        api_response(true, '', ['files' => crm_sample_files((int)($_POST['shipment_id'] ?? 0))]);
    }
    if ($action === 'sample_shipment_file_upload') {
        require_csrf();
        $files = $_FILES['files'] ?? $_FILES['attachments'] ?? [];
        api_response(true, '样品寄送文件已上传', crm_sample_upload_files((int)($_POST['shipment_id'] ?? 0), (string)($_POST['file_type'] ?? 'attachment'), $files));
    }
    if ($action === 'sample_shipment_file_delete') {
        require_csrf();
        api_response(true, '样品寄送文件已删除', crm_sample_delete_file((int)($_POST['file_id'] ?? 0)));
    }
    if ($action === 'sample_shipment_file_download') {
        crm_sample_stream_file((int)($_GET['file_id'] ?? $_POST['file_id'] ?? 0), !empty($_GET['inline']) || !empty($_POST['inline']));
    }
    if ($action === 'task_customer_search') {
        require_csrf();
        api_response(true, '', crm_task_customer_options($_POST));
    }
    if ($action === 'task_customer_contacts') {
        require_csrf();
        api_response(true, '', crm_task_customer_contacts((int)($_POST['customer_id'] ?? 0)));
    }
    if ($action === 'task_dispatch_placeholder') {
        require_csrf();
        api_response(true, '派工接口待接入', crm_task_pending_interface('派工'));
    }
    if ($action === 'task_logistics_placeholder') {
        require_csrf();
        api_response(true, '物流接口待接入', crm_task_pending_interface('物流'));
    }
    if ($action === 'opportunity_options') {
        require_csrf();
        crm_require('opportunity.view');
        api_response(true, '', crm_opportunity_options());
    }
    if ($action === 'opportunity_list') {
        require_csrf();
        api_response(true, '', crm_opportunity_list($_POST));
    }
    if ($action === 'opportunity_detail') {
        require_csrf();
        api_response(true, '', crm_opportunity_detail((int)($_POST['opportunity_id'] ?? 0)));
    }
    if ($action === 'opportunity_save') {
        require_csrf();
        api_response(true, '商机已保存', crm_opportunity_save($_POST));
    }
    if ($action === 'opportunity_stage_update') {
        require_csrf();
        api_response(true, '商机阶段已更新', crm_opportunity_stage_update((int)($_POST['opportunity_id'] ?? 0), (string)($_POST['stage'] ?? ''), $_POST));
    }
    if ($action === 'opportunity_win') {
        require_csrf();
        api_response(true, '商机已标记赢单', crm_opportunity_win((int)($_POST['opportunity_id'] ?? 0), $_POST));
    }
    if ($action === 'opportunity_lose') {
        require_csrf();
        api_response(true, '商机已标记输单', crm_opportunity_lose((int)($_POST['opportunity_id'] ?? 0), $_POST));
    }
    if ($action === 'opportunity_delete') {
        require_csrf();
        api_response(true, '商机已删除', crm_opportunity_delete((int)($_POST['opportunity_id'] ?? 0)));
    }
    if ($action === 'opportunity_files') {
        require_csrf();
        crm_require('opportunity.view');
        api_response(true, '', ['files' => crm_opportunity_files((int)($_POST['opportunity_id'] ?? 0))]);
    }
    if ($action === 'opportunity_file_upload') {
        require_csrf();
        $files = $_FILES['files'] ?? $_FILES['attachments'] ?? [];
        api_response(true, '商机文件已上传', crm_opportunity_upload_files((int)($_POST['opportunity_id'] ?? 0), (string)($_POST['file_type'] ?? 'attachment'), $files));
    }
    if ($action === 'opportunity_file_delete') {
        require_csrf();
        api_response(true, '商机文件已删除', crm_opportunity_delete_file((int)($_POST['file_id'] ?? 0)));
    }
    if ($action === 'opportunity_file_download') {
        crm_opportunity_stream_file((int)($_GET['file_id'] ?? $_POST['file_id'] ?? 0), !empty($_GET['inline']) || !empty($_POST['inline']));
    }

    api_response(false, '未知 CRM API', [], 'UNKNOWN_ACTION');
} catch (Throwable $e) {
    api_response(false, $e->getMessage(), [], 'SERVER_ERROR');
}
