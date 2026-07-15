<?php

function crm_modules(): array
{
    return [
        'workspace' => ['short' => '工作台', 'full' => '工作台 / 老板驾驶舱 / 员工工作台', 'icon' => 'WK', 'permission' => 'dashboard.view'],
        'customers' => ['short' => '客户', 'full' => '客户中心', 'icon' => 'CU', 'permission' => 'customer.view'],
        'mail' => ['short' => '邮箱', 'full' => '邮箱中心', 'icon' => 'ML', 'permission' => 'mail.view'],
        'promotion' => ['short' => '推广', 'full' => '推广中心', 'icon' => 'MK', 'permission' => 'promotion.view'],
        'tasks' => ['short' => '任务', 'full' => '任务与提醒', 'icon' => 'TK', 'permission' => 'task.view'],
        'visits' => ['short' => '拜访', 'full' => '拜访 / 来访', 'icon' => 'VI', 'permission' => 'visit.view'],
        'opportunities' => ['short' => '商机', 'full' => '商机中心', 'icon' => 'OP', 'permission' => 'opportunity.view'],
        'ai' => ['short' => 'AI中心', 'full' => 'AI中心', 'icon' => 'AI', 'permission' => 'radar_view'],
        'linkage' => ['short' => '联动', 'full' => '联动中心', 'icon' => 'LK', 'permission' => 'linkage.view'],
        'materials' => ['short' => '资料', 'full' => '资料生成系统', 'icon' => 'MT', 'permission' => 'customer.view'],
        'analytics' => ['short' => '分析', 'full' => '数据分析', 'icon' => 'AN', 'permission' => 'logs.view_own'],
        'logs' => ['short' => '日志', 'full' => '日志中心', 'icon' => 'LG', 'permission' => 'logs.view_own'],
        'settings' => ['short' => '设置', 'full' => '系统设置', 'icon' => 'ST', 'permission' => 'settings.view'],
    ];
}

function crm_top_module_keys(): array
{
    return ['workspace', 'customers', 'mail', 'promotion', 'tasks', 'visits', 'opportunities', 'ai', 'settings'];
}

function crm_top_modules(array $allowedModules): array
{
    $top = [];
    foreach (crm_top_module_keys() as $key) {
        if (isset($allowedModules[$key])) $top[$key] = $allowedModules[$key];
    }
    return $top;
}

function crm_linkage_tabs(): array
{
    return ['quote' => '报价', 'plm' => 'PLM', 'bom' => 'BOM', 'dispatch' => '派工', 'naming' => '命名', 'orders' => '订单', 'shipments' => '出货详情', 'documents' => '单证', 'receivables' => '收款欠款', 'reconcile' => '对账'];
}

function crm_material_tabs(): array
{
    return ['desk' => '资料工作台', 'products' => '产品资料', 'packages' => '客户资料包', 'reports' => '测试报告', 'manuals' => '安装说明', 'promo' => '宣传资料', 'attachments' => '邮件附件', 'generated' => '已生成', 'sent' => '发送记录', 'logs' => '使用日志', 'templates' => '模板设置', 'storage' => '空间统计'];
}

function crm_default_preferences(): array
{
    return [
        'theme_name' => 'compact-light',
        'font_scale' => 12,
        'density_mode' => 'compact',
        'animation_mode' => 'subtle',
        'topbar_height' => 48,
        'tabbar_height' => 38,
        'actionbar_width' => 220,
        'table_row_height' => 28,
        'email_list_font_size' => 12,
        'email_body_font_size' => 13,
        'email_editor_font_size' => 13,
        'tab_label_mode' => 'icon_short',
        'actionbar_collapsed' => 0,
        'module_layout' => [],
        'visible_fields' => [],
        'shortcuts' => [],
    ];
}

function crm_action_map(): array
{
    return [
        'workspace' => ['刷新工作台', '在线人员', '组件设置', '进入客户中心', '查看邮件'],
        'customers' => ['新建客户', '编辑客户', '新建跟进', '新建拜访', '新建来访', '查看拜访记录', '查看来访记录', '创建接待派工', '加入分组', '分配客户', '公海池', '客户日志'],
        'mail' => ['写邮件', '收取邮件', '回复', '回复全部', '转发', '关联客户', '查看当前邮件往来', '邮箱设置', '邮件日志'],
        'promotion' => ['刷新推广中心', '创建推广任务', '编辑推广任务', '重命名推广任务', '复制已有项目', '查看推广目标', '预览推广项目', '复制推广项目', '执行推广任务', '暂停推广任务', '删除推广任务', '推广任务日志', '客户推广池', '联系人策略', '执行中心', '渠道分析', '推广记录', '转化分析'],
        'tasks' => ['跟进任务', '拜访计划', '来访接待', '外出记录', '拜访报表', '新建拜访', '新建来访', '查看今日拜访', '查看今日来访', '待确认拜访', '待填写结果', '填写拜访结果', '填写接待结果', '创建派工', '创建跟进', '导出记录'],
        'visits' => ['拜访计划', '来访接待', '外出记录', '拜访报表', '新建拜访', '新建来访', '查看今日拜访', '查看今日来访', '待确认拜访', '待填写结果', '填写拜访结果', '填写接待结果', '创建派工', '创建跟进', '导出记录'],
        'opportunities' => ['新建商机', '查看商机', '编辑商机', '推进阶段', '创建跟进', '创建报价', '创建样品任务', '创建资料任务', '创建派工', '标记赢单', '标记输单', '暂停商机', '删除商机', '导入商机', '导出商机', '查看我的商机', '查看逾期商机', '查看商机报表'],
        'ai' => ['客户雷达', '雷达首页', '种子客户', '客户画像', '搜索关键词', '候选客户池', '审核中心', '运行日志', '雷达设置', '智能报价', '智能资料', 'AI任务', '运行记录', 'AI设置'],
        'linkage' => ['查看报价', '查看BOM', '刷新联动', '查看日志'],
        'materials' => [],
        'analytics' => [],
        'logs' => ['刷新日志', '导出日志', '只看今天', '查看失败', '查看安全日志', '清空筛选'],
        'settings' => ['保存界面偏好', '保存企业信息', '保存客户Tab配置', '顶部菜单配置', '权限中心', '邮箱设置', 'AI 设置', '刷新配置中心', '日志中心', '恢复默认配置'],
    ];
}

function crm_permission_state(array $permissions): array
{
    $state = [];
    foreach ($permissions as $permission) {
        $permission = trim((string)$permission);
        if ($permission === '') continue;
        $state[$permission] = has_permission($permission);
    }
    return $state;
}
