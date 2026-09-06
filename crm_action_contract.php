<?php

/** Module-scoped action identities. Labels are aliases, never global permissions. */
function crm_action_contract_definitions(): array
{
    return [
        'customers' => [
            'customers.delete_many' => ['labels' => ['批量删除'], 'permissions' => ['customer.delete']],
            'customers.create_followup' => ['labels' => ['新建跟进', '创建跟进'], 'permissions' => ['follow.create']],
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
            foreach ($definition['labels'] as $label) {
                $result[$module][$label] = ['id' => $id, 'allowed' => $allowed];
            }
        }
    }
    return $result;
}
