<?php
declare(strict_types=1);

/**
 * 权限中心第 2 批（人员权限）契约测试。不连正式库：php centers/system/tests/people_contract.php
 */

require_once dirname(__DIR__, 2) . '/_base/tests/harness.php';

use Artdon\Centers\Base\Auth;
use Artdon\Centers\Base\NotFoundException;
use Artdon\Centers\Base\Permission;
use Artdon\Centers\Base\PermissionException;
use Artdon\Centers\Base\ValidationException;
use Artdon\Centers\System\PeopleService;

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
centers_test_reset();
$ins = db()->prepare('INSERT INTO crm_permissions (permission_key, module, action, description, risk_level) VALUES (?, ?, ?, ?, ?)');
foreach ([
    ['customer.view', 'customer', 'view', '查看客户', 'low'],
    ['customer.export', 'customer', 'export', '导出客户', 'high'],
    ['mail.view', 'mail', 'view', '查看邮箱', 'low'],
    ['mail.send', 'mail', 'send', '发送邮件', 'medium'],
    ['users.view', 'users', 'view', '查看用户', 'medium'],
    ['users.assign_permission', 'users', 'assign_permission', '分配用户权限', 'high'],
] as $p) {
    $ins->execute($p);
}
// 角色 3 = 授权员：能分配个人权限，有 customer.view、mail.view，没有 customer.export、mail.send
db()->exec("INSERT INTO crm_roles (id, role_key, role_name, status) VALUES (3, 'assigner', '授权员', 'active')");
foreach (['users.view', 'users.assign_permission', 'customer.view', 'mail.view'] as $k) {
    db()->exec("INSERT INTO crm_role_permissions (role_id, permission_key) VALUES (3, '{$k}')");
}
db()->exec("INSERT INTO crm_role_permissions (role_id, permission_key) VALUES (2, 'customer.view'), (2, 'mail.view')");
$assignerId = centers_test_user(['username' => 'as', 'email' => 'as@example.com', 'role_id' => 3, 'real_name' => '授权员']);
$bossId = centers_test_user(['username' => 'boss', 'email' => 'b@example.com', 'role_id' => 1, 'is_super_admin' => 1, 'real_name' => '老板']);
$salesId = centers_test_user(['username' => 'sales', 'email' => 's@example.com', 'role_id' => 2, 'real_name' => '业务']);
$future = date('Y-m-d H:i:s', time() + 86400);
db()->prepare("INSERT INTO crm_permission_grants (user_id, permission_key, module, action, effect, status, starts_at, expires_at, granted_by_name) VALUES (?, 'mail.send', 'mail', 'send', 'allow', 'active', ?, ?, '老板')")
    ->execute([$salesId, date('Y-m-d H:i:s', time() - 60), $future]);

$assigner = Auth::loadUser($assignerId);
$svc = new PeopleService($assigner);

// ---------- 列表与明细 ----------
$list = $svc->users();
check(count($list) === 3, '列出全部人员');
check(count($svc->users('业务')) === 1 && count($svc->users('%')) === 0, '按姓名搜索，% 不会匹配全部');
$groups = $svc->effective($salesId);
$flat = [];
foreach ($groups as $g) {
    foreach ($g['permissions'] as $p) {
        $flat[$p['key']] = $p;
    }
}
check($flat['customer.view']['on'] && $flat['customer.view']['source'] === '角色', '来自角色的权限显示来源“角色”');
check($flat['mail.send']['on'] && $flat['mail.send']['source'] === '临时授权' && $flat['mail.send']['grant_expires'] === $future, '临时授权显示来源和到期时间');
check(!$flat['customer.export']['on'] && $flat['customer.export']['source'] === '', '没有授权的显示为未生效');
$bossFlat = [];
foreach ($svc->effective($bossId) as $g) {
    foreach ($g['permissions'] as $p) {
        $bossFlat[$p['key']] = $p;
    }
}
check($bossFlat['customer.export']['on'] && $bossFlat['customer.export']['source'] === '超级管理员', '超级管理员全部生效');
check(count($svc->activeGrants($salesId)) === 1, '列出有效的临时授权');
expect_throws(NotFoundException::class, static function () use ($svc): void {
    $svc->effective(999);
}, '账号不存在时返回“不存在”');

// ---------- 个人设置 ----------
$r = $svc->saveOverrides($salesId, ['customer.view' => 'deny', 'mail.view' => 'inherit', 'customer.export' => 'inherit']);
check($r['changed'] === 1, '只保存有变化的项');
$sales = Auth::loadUser($salesId);
check(!Permission::allows($sales, 'customer.view'), '个人拒绝后，角色里有的权限也失效');
$r = $svc->saveOverrides($salesId, ['customer.view' => 'inherit']);
check(Permission::allows($sales, 'customer.view') && $svc->overrides($salesId) === [], '改回“跟随角色”后删除个人设置，恢复角色权限');
$svc->saveOverrides($salesId, ['mail.send' => 'deny']);
Permission::clearCache();
check(!Permission::allows($sales, 'mail.send'), '个人拒绝优先于临时授权');
$svc->saveOverrides($salesId, ['mail.send' => 'inherit']);
expect_throws(PermissionException::class, static function () use ($svc, $salesId): void {
    $svc->saveOverrides($salesId, ['customer.export' => 'allow']);
}, '不能给别人允许自己没有的权限');
check(!$svc->canAllow('customer.export') && $svc->canAllow('mail.view'), '页面据此禁用“允许”选项');
$svc->saveOverrides($salesId, ['customer.export' => 'deny']);
check($svc->overrides($salesId) === ['customer.export' => 'deny'], '可以拒绝自己没有的权限');
expect_throws(ValidationException::class, static function () use ($svc, $salesId): void {
    $svc->saveOverrides($salesId, ['no.such' => 'allow']);
}, '不存在的权限被拒绝');
expect_throws(ValidationException::class, static function () use ($svc, $salesId): void {
    $svc->saveOverrides($salesId, ['mail.view' => 'maybe']);
}, '设置值不正确时拒绝');
expect_throws(RuntimeException::class, static function () use ($svc, $assignerId): void {
    $svc->saveOverrides($assignerId, ['customer.export' => 'deny']);
}, '不能修改自己的个人权限');
expect_throws(RuntimeException::class, static function () use ($svc, $bossId): void {
    $svc->saveOverrides($bossId, ['customer.export' => 'deny']);
}, '超级管理员不需要单独设置');
$boss = new PeopleService(Auth::loadUser($bossId));
$boss->saveOverrides($salesId, ['customer.export' => 'allow']);
check(Permission::allows(Auth::loadUser($salesId), 'customer.export'), '超管可以允许任何权限');
expect_throws(PermissionException::class, static function () use ($salesId): void {
    (new PeopleService(Auth::loadUser($salesId)))->saveOverrides($salesId, ['mail.view' => 'deny']);
}, '没有 users.assign_permission 不能修改');
$log = db()->query("SELECT before_json, after_json FROM crm_audit_logs WHERE action = 'user_permissions_update' ORDER BY id DESC LIMIT 1")->fetch();
check(strpos((string) $log['before_json'], '"customer.export":"deny"') !== false && strpos((string) $log['after_json'], '"customer.export":"allow"') !== false, '个人设置写日志，记下前后值');

// ---------- 数据范围 ----------
check(array_key_exists('customer', $svc->scopeModules($salesId)), '数据范围列出常用模块');
$e = expect_throws(ValidationException::class, static function () use ($svc, $salesId): void {
    $svc->saveScopes($salesId, ['customer' => ['type' => 'country', 'value' => ''], 'mail' => ['type' => 'weird']]);
}, '指定国家却没填、范围值不正确时报错');
check($e instanceof ValidationException && isset($e->fieldErrors()['scope[customer][value]'], $e->fieldErrors()['scope[mail][type]']), '错误落在对应的输入框');
$svc->saveScopes($salesId, ['customer' => ['type' => 'country', 'value' => '德国,法国'], 'mail' => ['type' => 'department', 'value' => '会被忽略'], 'visit' => ['type' => 'self']]);
check($svc->scopes($salesId) === ['customer' => ['type' => 'country', 'value' => '德国,法国'], 'mail' => ['type' => 'department', 'value' => '']], '保存数据范围；非国家/分组类型不存取值；“只看本人”不写记录');
check(Permission::scope(Auth::loadUser($salesId), 'customer') === ['scope_type' => 'country', 'scope_value' => '德国,法国'], '保存后按新范围判断');
$svc->saveScopes($salesId, ['customer' => ['type' => 'self']]);
check(!array_key_exists('customer', $svc->scopes($salesId)), '改回“只看本人”时删除记录');
expect_throws(ValidationException::class, static function () use ($svc, $salesId): void {
    $svc->saveScopes($salesId, ['hacker' => ['type' => 'all']]);
}, '不能设置不在列表里的模块');
expect_throws(RuntimeException::class, static function () use ($svc, $assignerId): void {
    $svc->saveScopes($assignerId, ['customer' => ['type' => 'all']]);
}, '不能修改自己的数据范围');

centers_test_finish('权限中心第 2 批：人员权限');
