<?php

function radar_permissions(): array
{
    return [
        ['radar_view', 'radar', 'view', '查看客户雷达', 'medium'],
        ['radar_seed_manage', 'radar', 'seed_manage', '管理雷达种子客户', 'medium'],
        ['radar_profile_manage', 'radar', 'profile_manage', '管理客户画像', 'medium'],
        ['radar_task_create', 'radar', 'task_create', '创建雷达搜索任务', 'high'],
        ['radar_task_run', 'radar', 'task_run', '运行雷达搜索任务', 'high'],
        ['radar_task_pause', 'radar', 'task_pause', '暂停雷达搜索任务', 'high'],
        ['radar_candidate_view', 'radar', 'candidate_view', '查看雷达候选客户', 'medium'],
        ['radar_candidate_review', 'radar', 'candidate_review', '审核雷达候选客户', 'high'],
        ['radar_candidate_delete', 'radar', 'candidate_delete', '删除雷达候选客户', 'high'],
        ['radar_candidate_to_crm', 'radar', 'candidate_to_crm', '雷达候选客户转入 CRM', 'dangerous'],
        ['radar_rule_manage', 'radar', 'rule_manage', '管理雷达规则', 'high'],
        ['radar_cost_view', 'radar', 'cost_view', '查看雷达费用', 'medium'],
        ['radar_log_view', 'radar', 'log_view', '查看雷达日志', 'medium'],
        ['radar_settings_manage', 'radar', 'settings_manage', '管理雷达设置', 'high'],
        ['radar_feedback_submit', 'radar', 'feedback_submit', '提交雷达客户反馈', 'low'],
        ['radar_template_view', 'radar', 'template_view', '查看雷达搜索模板', 'low'],
        ['radar_template_create', 'radar', 'template_create', '新建雷达搜索模板', 'medium'],
        ['radar_template_edit', 'radar', 'template_edit', '编辑雷达搜索模板', 'high'],
        ['radar_template_disable', 'radar', 'template_disable', '停用雷达搜索模板', 'medium'],
        ['radar_template_delete', 'radar', 'template_delete', '删除自定义雷达搜索模板', 'high'],
        ['radar_template_restore', 'radar', 'template_restore', '恢复系统雷达搜索模板', 'high'],
        ['radar_template_export', 'radar', 'template_export', '导出雷达搜索模板', 'medium'],
        ['radar_template_import', 'radar', 'template_import', '导入雷达搜索模板', 'high'],
        ['radar_template_create_task', 'radar', 'template_create_task', '由模板创建雷达搜索任务草稿', 'medium'],
    ];
}

function radar_ensure_permissions(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $stmt = db()->prepare('INSERT IGNORE INTO crm_permissions (permission_key, module, action, description, risk_level, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
    foreach (radar_permissions() as $permission) {
        $stmt->execute($permission);
    }
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key)
        SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p
        WHERE r.role_key IN ('super_admin','admin','boss_admin') AND p.module = 'radar'");
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key)
        SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p
        WHERE r.role_key IN ('manager') AND p.permission_key IN ('radar_view','radar_candidate_view','radar_candidate_review','radar_cost_view','radar_log_view','radar_template_view','radar_template_create_task')");
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key)
        SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p
        WHERE r.role_key IN ('sales','marketing','viewer','finance','engineering','purchase','production','team_leader')
          AND p.permission_key IN ('radar_view','radar_candidate_view','radar_feedback_submit','radar_template_view','radar_template_create_task')");
}
