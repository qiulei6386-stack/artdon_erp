<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/crm_config.php';
require_once __DIR__ . '/crm_auth.php';
require_once __DIR__ . '/crm_log.php';
require_once __DIR__ . '/radar.php';

require_login();

$action = $_POST['action'] ?? $_GET['action'] ?? 'radar_bootstrap';

try {
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
    if ($action === 'radar_seed_list') api_response(true, '', radar_seed_list($_POST));
    if ($action === 'radar_seed_get') api_response(true, '', radar_seed_get((int)($_POST['id'] ?? 0)));
    if ($action === 'radar_seed_save') { require_csrf(); api_response(true, '种子客户已保存', radar_seed_save($_POST)); }
    if ($action === 'radar_seed_delete') { require_csrf(); api_response(true, '种子客户已删除', radar_seed_soft_delete($_POST)); }
    if ($action === 'radar_seed_status') { require_csrf(); api_response(true, '种子客户状态已更新', radar_seed_status($_POST)); }
    if ($action === 'radar_seed_batch') { require_csrf(); api_response(true, '批量操作已完成', radar_seed_batch($_POST)); }
    if ($action === 'radar_initial_import') { require_csrf(); api_response(true, '首批种子和关键词已导入', radar_import_initial_data()); }
    if ($action === 'radar_seed_import_text') { require_csrf(); api_response(true, '种子客户批量导入完成', radar_seed_import_text($_POST)); }
    if ($action === 'radar_profiles_list') api_response(true, '', radar_profiles_list($_POST));
    if ($action === 'radar_profiles_generate') { require_csrf(); api_response(true, '客户画像已生成', radar_generate_all_profiles(!empty($_POST['force']))); }
    if ($action === 'radar_profile_save') { require_csrf(); api_response(true, '客户画像已保存', radar_profile_save($_POST)); }
    if ($action === 'radar_keywords_list') api_response(true, '', radar_keywords_list($_POST));
    if ($action === 'radar_keyword_save') { require_csrf(); api_response(true, '关键词已保存', radar_keyword_save($_POST)); }
    if ($action === 'radar_keyword_delete') { require_csrf(); api_response(true, '关键词已更新', radar_keyword_delete($_POST)); }
    if ($action === 'radar_keyword_import_text') { require_csrf(); api_response(true, '关键词批量导入完成', radar_keyword_import_text($_POST)); }
    if ($action === 'radar_tasks_list') api_response(true, '', radar_tasks_list($_POST));
    if ($action === 'radar_task_get') api_response(true, '', radar_task_get((int)($_POST['id'] ?? 0)));
    if ($action === 'radar_task_save') { require_csrf(); api_response(true, '搜索任务已保存', radar_task_save($_POST)); }
    if ($action === 'radar_task_start') { require_csrf(); api_response(true, '搜索任务已进入队列', radar_task_start((int)($_POST['id'] ?? 0))); }
    if ($action === 'radar_task_pause') { require_csrf(); api_response(true, '搜索任务已暂停', radar_task_control((int)($_POST['id'] ?? 0), 'pause')); }
    if ($action === 'radar_task_resume') { require_csrf(); api_response(true, '搜索任务已继续', radar_task_control((int)($_POST['id'] ?? 0), 'resume')); }
    if ($action === 'radar_task_cancel') { require_csrf(); api_response(true, '搜索任务已取消', radar_task_control((int)($_POST['id'] ?? 0), 'cancel')); }
    if ($action === 'radar_task_delete') { require_csrf(); api_response(true, '搜索任务已删除', radar_task_delete((int)($_POST['id'] ?? 0))); }
    if ($action === 'radar_task_reorder') { require_csrf(); api_response(true, '搜索任务顺序已保存', radar_task_reorder($_POST)); }
    if ($action === 'radar_task_copy') { require_csrf(); api_response(true, '搜索任务已复制', radar_task_copy((int)($_POST['id'] ?? 0))); }
    if ($action === 'radar_worker_run') { require_csrf(); api_response(true, '后台队列已执行', radar_worker_run(max(1, min(50, (int)($_POST['limit'] ?? 10))))); }
    if ($action === 'radar_candidates_list') api_response(true, '', radar_candidates_list($_POST));
    if ($action === 'radar_candidate_get') api_response(true, '', radar_candidate_get((int)($_POST['id'] ?? 0)));
    if ($action === 'radar_candidate_reanalyze') { require_csrf(); api_response(true, '候选客户已重新分析', radar_candidate_reanalyze((int)($_POST['id'] ?? 0))); }
    if ($action === 'radar_candidate_recheck_duplicate') { require_csrf(); api_response(true, 'CRM重复检查已更新', radar_candidate_recheck_duplicate((int)($_POST['id'] ?? 0))); }
    if ($action === 'radar_candidate_manual_save') { require_csrf(); api_response(true, '候选客户分类已保存', radar_candidate_manual_save($_POST)); }
    if ($action === 'radar_candidate_status') { require_csrf(); api_response(true, '候选客户状态已更新', radar_candidate_status($_POST)); }
    if ($action === 'radar_review_options') api_response(true, '', radar_review_options());
    if ($action === 'radar_candidate_convert_to_crm') { require_csrf(); api_response(true, '候选客户已转入CRM', radar_candidate_convert_to_crm($_POST)); }
    if ($action === 'radar_feedback_save') { require_csrf(); api_response(true, '反馈已保存', radar_feedback_save($_POST)); }
    if ($action === 'radar_stats') api_response(true, '', radar_stats());
    if ($action === 'radar_templates_list') api_response(true, '', radar_templates_list($_POST));
    if ($action === 'radar_template_get') api_response(true, '', radar_template_get((int)($_POST['id'] ?? 0)));
    if ($action === 'radar_template_preview') api_response(true, '', radar_template_preview($_POST));
    if ($action === 'radar_template_save') { require_csrf(); api_response(true, '搜索模板已保存', radar_template_save($_POST)); }
    if ($action === 'radar_template_status') { require_csrf(); api_response(true, '搜索模板状态已更新', radar_template_status($_POST)); }
    if ($action === 'radar_template_delete') { require_csrf(); api_response(true, '搜索模板已删除', radar_template_delete((int)($_POST['id'] ?? 0))); }
    if ($action === 'radar_template_copy') { require_csrf(); api_response(true, '搜索模板已复制', radar_template_copy($_POST)); }
    if ($action === 'radar_template_restore') { require_csrf(); api_response(true, '系统模板已恢复默认', radar_template_restore((int)($_POST['id'] ?? 0))); }
    if ($action === 'radar_template_create_task') { require_csrf(); api_response(true, '已由模板创建草稿任务', radar_template_create_task($_POST)); }
    if ($action === 'radar_search_services_list') api_response(true, '', radar_search_services_list());
    if ($action === 'radar_search_service_save') { require_csrf(); api_response(true, '搜索服务配置已保存', radar_search_service_save($_POST)); }
    if ($action === 'radar_export') { require_csrf(); api_response(true, '', radar_export_rows((string)($_POST['type'] ?? 'seeds'))); }
    api_response(false, '未知客户雷达接口：' . $action, [], 'NOT_FOUND');
} catch (Throwable $e) {
    if (function_exists('radar_log')) radar_log('api_error', ['action' => $action], false, $e->getMessage());
    api_response(false, $e->getMessage(), [], 'SERVER_ERROR');
}
