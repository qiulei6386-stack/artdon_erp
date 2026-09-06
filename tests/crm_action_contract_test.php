<?php
// Pure permission-contract test: no bootstrap, database or business interfaces.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/crm_action_contract.php';
$granted = [];
function crm_can(string $permission): bool { global $granted; return in_array($permission, $granted, true); }
function expect_contract(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$granted = ['task.edit', 'task.complete', 'customer.delete'];
$map = crm_action_contracts();
expect_contract($map['tasks']['编辑任务']['allowed'], 'Task editing must not require promotion permission');
expect_contract($map['tasks']['标记完成']['allowed'], 'Task completion must use task permission');
expect_contract($map['customers']['批量删除']['allowed'], 'Customer deletion must use customer permission');
expect_contract(!$map['promotion']['编辑任务']['allowed'], 'Task permission must not grant promotion editing');
expect_contract(!$map['promotion']['批量删除']['allowed'], 'Customer permission must not grant promotion deletion');
expect_contract($map['tasks']['创建派工']['id'] === $map['tasks']['生成派工']['id'], 'Dispatch aliases must have one identity');
$granted = ['promotion.edit_project', 'promotion.execute', 'promotion.delete_project'];
$map = crm_action_contracts();
expect_contract(!$map['promotion']['编辑任务']['allowed'], 'Promotion edit also requires the actual save endpoint permission');
$granted[] = 'promotion.task_create';
$map = crm_action_contracts();
expect_contract(!$map['tasks']['编辑任务']['allowed'] && !$map['tasks']['标记完成']['allowed'], 'Promotion permission must not grant task actions');
expect_contract(!$map['customers']['批量删除']['allowed'], 'Promotion permission must not grant customer deletion');
expect_contract($map['promotion']['编辑任务']['allowed'] && $map['promotion']['标记完成']['allowed'], 'Promotion actions remain available to proper role');
$granted = ['follow.create'];
expect_contract(!crm_action_contracts()['tasks']['新建跟进']['allowed'], 'Customer picker requires customer visibility');
$granted[] = 'customer.view';
expect_contract(crm_action_contracts()['tasks']['新建跟进']['allowed'], 'Follow-up creator can open customer picker');
echo "crm_action_contract_test: OK\n";
