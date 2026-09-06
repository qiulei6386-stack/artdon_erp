<?php

/** Module-scoped action identities. Labels are aliases, never global permissions. */
function crm_action_contract_definitions(): array
{
    return [
        'customers' => [
            'customers.create' => ['labels'=>['新建客户'],'permissions'=>['customer.create']],
            'customers.edit' => ['labels'=>['编辑客户'],'permissions'=>['customer.edit']],
            'customers.quote' => ['labels'=>['创建报价','转报价'],'permissions'=>['customer.view'],'external'=>['quote','create']],
            'customers.delete_many' => ['labels' => ['批量删除'], 'permissions' => ['customer.delete']],
            'customers.create_followup' => ['labels' => ['新建跟进', '创建跟进'], 'permissions' => ['follow.create']],
        ],
        'opportunities' => [
            'opportunities.create' => ['labels'=>['新建商机'],'permissions'=>['opportunity.create']],
            'opportunities.edit' => ['labels'=>['编辑商机'],'permissions'=>['opportunity.edit']],
            'opportunities.quote' => ['labels'=>['创建报价'],'permissions'=>['opportunity.view','customer.view'],'external'=>['quote','create']],
            'opportunities.create_followup' => ['labels' => ['创建跟进'], 'permissions' => ['opportunity.view', 'follow.create', 'customer.view']],
            'opportunities.create_sample_task' => ['labels' => ['创建样品任务'], 'permissions' => ['opportunity.view', 'customer.view', 'task.create', 'task.view']],
            'opportunities.create_material_task' => ['labels' => ['创建资料任务'], 'permissions' => ['opportunity.view', 'customer.view', 'task.create', 'task.view']],
        ],
        'mail' => [
            'mail.compose'=>['labels'=>['写邮件'],'permissions'=>['mail.send']],
            'mail.sync'=>['labels'=>['收取邮件'],'permissions'=>['mail.sync']],
            'mail.reply'=>['labels'=>['回复'],'permissions'=>['mail.view','mail.send']],
            'mail.reply_all'=>['labels'=>['回复全部'],'permissions'=>['mail.view','mail.send']],
            'mail.forward'=>['labels'=>['转发'],'permissions'=>['mail.send']],
            'mail.link_customer'=>['labels'=>['关联客户'],'permissions'=>['mail.link_customer']],
            'mail.delete'=>['labels'=>['删除'],'permissions'=>['mail.delete']],
            'mail.create_followup'=>['labels'=>['创建跟进'],'permissions'=>['mail.view','customer.view','follow.create']],
        ],
        'visits' => [
            'visits.create'=>['labels'=>['新建拜访','新建来访'],'permissions'=>['visit.create']],
            'visits.edit'=>['labels'=>['编辑拜访','编辑来访'],'permissions'=>['visit.edit']],
            'visits.result'=>['labels'=>['填写拜访结果','填写接待结果'],'permissions'=>['visit.result']],
            'visits.dispatch'=>['labels'=>['创建派工','创建接待派工','派工'],'permissions'=>['visit.dispatch']],
            'visits.create_followup'=>['labels'=>['创建跟进'],'permissions'=>['follow.create','customer.view']],
            'visits.delete'=>['labels'=>['删除拜访','删除来访','删除记录'],'permissions'=>['visit.delete']],
        ],
        'ai' => [
            'radar.task_candidates'=>['labels'=>['查看本次候选'],'permissions'=>['radar_candidate_view']],
        ],
        'tasks' => [
            'tasks.create' => ['labels' => ['新建任务'], 'permissions' => ['task.create']],
            'tasks.edit' => ['labels' => ['编辑任务'], 'permissions' => ['task.edit']],
            'tasks.complete' => ['labels' => ['标记完成'], 'permissions' => ['task.complete']],
            'tasks.delay' => ['labels' => ['延期'], 'permissions' => ['task.delay']],
            'tasks.delete' => ['labels' => ['删除任务'], 'permissions' => ['task.delete']],
            'tasks.create_followup' => ['labels' => ['新建跟进', '创建跟进'], 'permissions' => ['follow.create', 'customer.view']],
            'tasks.create_dispatch' => ['labels' => ['创建派工', '生成派工'], 'permissions' => ['task.edit']],
            'tasks.create_sample' => ['labels' => ['新建样品寄送'], 'permissions' => ['sample.create']],
            'tasks.edit_sample' => ['labels' => ['编辑寄送信息'], 'permissions' => ['sample.edit']],
        ],
        'promotion' => [
            'promotion.edit' => ['labels' => ['编辑任务', '编辑项目', '编辑推广任务'], 'permissions' => ['promotion.edit_project', 'promotion.task_create']],
            'promotion.delete_many' => ['labels' => ['批量删除'], 'permissions' => ['promotion.delete_project']],
            'promotion.manual_complete' => ['labels' => ['标记完成'], 'permissions' => ['promotion.execute']],
            'promotion.start' => ['labels' => ['启动项目', '执行推广任务'], 'permissions' => ['promotion.execute']],
            'promotion.pause' => ['labels' => ['暂停项目', '暂停推广任务'], 'permissions' => ['promotion.execute']],
            'promotion.resume' => ['labels' => ['继续项目', '继续推广任务'], 'permissions' => ['promotion.execute']],
            'promotion.cancel' => ['labels' => ['取消项目', '取消任务'], 'permissions' => ['promotion.execute']],
        ],
    ];
}

function crm_action_contracts(): array
{
    $result = [];
    foreach (crm_action_contract_definitions() as $module => $actions) {
        foreach ($actions as $id => $definition) {
            $allowed = true;
            foreach ($definition['permissions'] as $permission) {
                if (!crm_can($permission)) $allowed = false;
            }
            if (isset($definition['external']) && (!function_exists('crm_external_can') || !crm_external_can($definition['external'][0], $definition['external'][1]))) $allowed = false;
            foreach ($definition['labels'] as $label) {
                $result[$module][$label] = ['id' => $id, 'allowed' => $allowed];
            }
        }
    }
    return $result;
}
