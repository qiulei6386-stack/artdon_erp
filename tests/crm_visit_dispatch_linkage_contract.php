<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$visit = file_get_contents($root . '/crm_visit.php');
$api = file_get_contents($root . '/crm_api.php');
$js = file_get_contents($root . '/assets/crm/crm.js');

if (in_array(false, [$visit, $api, $js], true)) {
    throw new RuntimeException('CRM visit dispatch linkage sources are not readable');
}

foreach ([
    [$visit, "require_once __DIR__ . '/dispatch_next_schema.php'", '拜访派工会初始化派工表结构'],
    [$visit, 'dispatch_next_init_schema()', '拜访派工接入 dispatch_next'],
    [$visit, 'INSERT INTO dispatch_next_tasks', '拜访创建真实派工待办'],
    [$visit, 'dispatch_next_notifications', '拜访派工写入派工通知'],
    [$visit, 'dispatch_next_logs', '拜访派工写入派工日志'],
    [$visit, 'linked_table = \'crm_visit_records\'', '派工记录反链 CRM 拜访表'],
    [$visit, 'crm_visit_dispatch_existing', '同一拜访防重复创建派工'],
    [$visit, 'related_dispatch_id', '拜访记录保存关联派工 ID'],
    [$visit, 'crm_visit_dispatch_assignee', '拜访派工默认负责人规则'],
    [$visit, 'crm_visit_dispatch_due_at', '拜访派工默认截止时间规则'],
    [$visit, 'crm_visit_dispatch_description', '派工描述包含拜访上下文'],
    [$visit, "|| !empty(\$input['create_dispatch']) ? 'followup_pending' : 'completed'", '结果页创建派工会进入后续状态'],
    [$visit, "|| !empty(\$input['create_dispatch'])) ? 1", '结果页创建派工会反写 need_dispatch'],
    [$visit, "crm_visit_dispatch_placeholder(\$id, 'visit_result')", '填写拜访结果时勾选创建派工会真实联动'],
    [$visit, "crm_visit_dispatch_placeholder(\$id, (\$visit['visit_type'] ?? '') === 'customer_arrival' ? 'arrival_reception' : 'visit_prepare')", '保存拜访时勾选创建派工会真实联动'],
    [$visit, "'status' => 'ready'", '联动动作状态不再是待接入'],
    [$visit, '派工已创建：', '成功返回派工编号'],
    [$visit, '已存在派工：', '重复点击返回已有派工'],
    [$api, "api_response(true, '派工已生成', crm_visit_dispatch_placeholder", 'API 文案改为派工已生成'],
    [$js, '创建派工会真实生成派工待办', '前端保存说明不再提示派工未接入'],
    [$js, '已创建过不会重复', '前端提示防重复规则'],
    [$js, "toast((json.data && json.data.message) || json.message || '派工已生成')", '右侧 ACTIONS 创建派工成功提示'],
] as [$source, $needle, $label]) {
    if (!str_contains($source, $needle)) {
        throw new RuntimeException('缺少：' . $label);
    }
}

if (str_contains($visit, '拜访/来访派工接口待接入')) {
    throw new RuntimeException('拜访派工仍残留待接入文案');
}

echo "crm_visit_dispatch_linkage_contract: OK\n";
