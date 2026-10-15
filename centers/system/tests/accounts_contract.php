<?php
declare(strict_types=1);

/**
 * 系统中心第 2 步（账号管理）契约测试。不连正式库：php centers/system/tests/accounts_contract.php
 */

require_once dirname(__DIR__, 2) . '/_base/tests/harness.php';

use Artdon\Centers\Base\Auth;
use Artdon\Centers\Base\NotFoundException;
use Artdon\Centers\Base\Password;
use Artdon\Centers\Base\PermissionException;
use Artdon\Centers\Base\ValidationException;
use Artdon\Centers\System\AccountService;

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

centers_test_reset();
// 角色 3 = 账号管理员：有全部账号权限；角色 4 = 只能改角色
db()->exec("INSERT INTO crm_roles (id, role_key, role_name, status) VALUES (3, 'hr', '人事', 'active'), (4, 'role_only', '角色管理', 'active'), (5, 'old', '停用角色', 'disabled')");
foreach (['users.view', 'users.create', 'users.approve', 'users.reject', 'users.disable', 'users.enable', 'users.reset_password', 'users.assign_role', 'users.assign_department'] as $key) {
    db()->exec("INSERT INTO crm_role_permissions (role_id, permission_key) VALUES (3, '{$key}')");
}
db()->exec("INSERT INTO crm_role_permissions (role_id, permission_key) VALUES (4, 'users.view'), (4, 'users.assign_role'), (2, 'customer.view')");

$hrId = centers_test_user(['username' => 'hr', 'email' => 'hr@example.com', 'real_name' => '人事', 'role_id' => 3]);
$bossId = centers_test_user(['username' => 'boss', 'email' => 'boss@example.com', 'real_name' => '老板', 'role_id' => 1, 'is_super_admin' => 1]);
$salesId = centers_test_user(['username' => 'sales1', 'email' => 's1@example.com', 'real_name' => '业务一']);
$pendingId = centers_test_user(['username' => 'newcomer', 'email' => 'n@example.com', 'real_name' => '新人', 'status' => 'pending', 'department_id' => null, 'role_id' => null]);
$hr = Auth::loadUser($hrId);
$svc = new AccountService($hr);

// ---------- 列表 ----------
$all = $svc->list([]);
check($all['total'] === 4 && (int) $all['rows'][0]['id'] === $pendingId, '列表含全部账号，待审核排在最前');
check(!array_key_exists('password_hash', $all['rows'][0]), '列表不返回密码哈希');
check($svc->list(['status' => 'pending'])['total'] === 1, '按状态筛选');
check($svc->list(['q' => '业务'])['total'] === 1 && $svc->list(['q' => 'S1@EXAMPLE'])['total'] >= 0, '按姓名搜索');
check($svc->list(['q' => '%'])['total'] === 0, '搜索里的 % 按普通字符处理，不会匹配全部');
check($svc->list(['department_id' => 1])['total'] === 3, '按部门筛选');
$counts = $svc->statusCounts(['status' => 'pending']);
check($counts['all'] === 4 && $counts['pending'] === 1 && $counts['active'] === 3, '状态计数不受当前状态筛选影响');
check($svc->roles() === [1 => '超级管理员', 2 => '业务员', 3 => '人事', 4 => '角色管理'], '角色选项只列启用中的角色');

// ---------- 新建 ----------
$rolePermsBefore = (int) db()->query('SELECT COUNT(*) FROM crm_role_permissions')->fetchColumn();
$e = expect_throws(ValidationException::class, static function () use ($svc): void {
    $svc->create(['username' => 'sales1', 'real_name' => '', 'email' => 'boss@example.com', 'department_id' => '3', 'role_id' => '5', 'password' => 'weak']);
}, '新建时多项错误一次性返回');
$fields = $e instanceof ValidationException ? array_keys($e->fieldErrors()) : [];
sort($fields);
check($fields === ['department_id', 'email', 'password', 'real_name', 'role_id', 'username'], '账号重复、姓名必填、邮箱重复、停用部门、停用角色、弱密码都报错');
$e = expect_throws(ValidationException::class, static function () use ($svc): void {
    $svc->create(['username' => 'x1', 'real_name' => '某人']);
}, '部门和角色必填');
check($e instanceof ValidationException && isset($e->fieldErrors()['department_id'], $e->fieldErrors()['role_id']), '缺部门和角色时提示请选择');

$created = $svc->create(['username' => 'chenchen', 'real_name' => '陈晨', 'email' => '', 'department_id' => '2', 'role_id' => '2', 'position' => '会计']);
check($created['generated'] && Password::isStrong($created['password']) && strlen($created['password']) === 10, '不填密码时自动生成 10 位强密码');
$row = $svc->find($created['id']);
check($row['status'] === 'active' && (int) $row['force_password_change'] === 1 && (int) $row['approved_by'] === $hrId && $row['email'] === null, '新账号为正常状态、首次登录需改密码、记录审核人');
check((int) $row['is_super_admin'] === 0, '新建账号不是超级管理员');
check((int) db()->query('SELECT COUNT(*) FROM crm_role_permissions')->fetchColumn() === $rolePermsBefore, '新建账号不改动任何角色的权限');
check((int) db()->query('SELECT COUNT(*) FROM crm_user_permissions WHERE user_id = ' . $created['id'])->fetchColumn() === 0, '新建账号不写个人权限，权限跟着角色走');
check(db()->query("SELECT action FROM crm_user_approval_logs WHERE user_id = {$created['id']}")->fetchColumn() === 'approve', '写审核记录');
$audit = (string) db()->query("SELECT after_json FROM crm_audit_logs WHERE action = 'user_create' ORDER BY id DESC LIMIT 1")->fetchColumn();
check($audit !== '' && strpos($audit, 'password') === false, '写操作日志，不含密码');
check(Auth::attempt('chenchen', $created['password'])['success'] === true, '新账号可以用生成的密码登录');
Auth::logout();
$own = $svc->create(['username' => 'zhao', 'real_name' => '赵', 'department_id' => '1', 'role_id' => '2', 'password' => 'Zhao2026ok']);
check($own['generated'] === false && Auth::attempt('zhao', 'Zhao2026ok')['success'] === true, '可以指定初始密码');
Auth::logout();

// ---------- 编辑 ----------
$svc->update($salesId, ['username' => 'sales.one', 'real_name' => '业务一号', 'email' => 's1@example.com', 'department_id' => '2', 'role_id' => '3', 'position' => '主管']);
$row = $svc->find($salesId);
check($row['username'] === 'sales.one' && $row['department_name'] === '财务部' && $row['role_name'] === '人事' && $row['position'] === '主管', '编辑资料、部门、角色');

$roleOnly = new AccountService(Auth::loadUser(centers_test_user(['username' => 'ro', 'email' => 'ro@example.com', 'role_id' => 4])));
$roleOnly->update($salesId, ['username' => 'hacked', 'real_name' => '改名', 'department_id' => '1', 'role_id' => '2']);
$row = $svc->find($salesId);
check($row['username'] === 'sales.one' && (int) $row['department_id'] === 2 && (int) $row['role_id'] === 2, '只有“分配角色”权限时只改得了角色，其他字段保持不变');

expect_throws(PermissionException::class, static function () use ($svc, $bossId): void {
    $svc->update($bossId, ['username' => 'boss', 'real_name' => '老板改名']);
}, '不是超级管理员的人不能编辑超级管理员账号');
expect_throws(NotFoundException::class, static function () use ($svc): void {
    $svc->update(99999, ['username' => 'x', 'real_name' => 'x']);
}, '账号不存在时返回“不存在”');
$boss = new AccountService(Auth::loadUser($bossId));
$boss->update($hrId, ['username' => 'hr', 'real_name' => '人事主管', 'department_id' => '1', 'role_id' => '3']);
check($svc->find($hrId)['real_name'] === '人事主管', '超级管理员可以编辑任何账号');

// ---------- 审核与拒绝 ----------
$e = expect_throws(ValidationException::class, static function () use ($svc, $pendingId): void {
    $svc->approve($pendingId, []);
}, '审核通过必须分配部门和角色');
$svc->approve($pendingId, ['department_id' => '1', 'role_id' => '2', 'position' => '业务员']);
$row = $svc->find($pendingId);
check($row['status'] === 'active' && $row['role_name'] === '业务员' && (int) $row['approved_by'] === $hrId, '审核通过后为正常状态并分配部门角色');
expect_throws(RuntimeException::class, static function () use ($svc, $pendingId): void {
    $svc->approve($pendingId, ['department_id' => '1', 'role_id' => '2']);
}, '已经通过的账号不能再审核');
$p2 = centers_test_user(['username' => 'p2', 'email' => 'p2@example.com', 'status' => 'pending']);
expect_throws(ValidationException::class, static function () use ($svc, $p2): void {
    $svc->reject($p2, ['reason' => '  ']);
}, '拒绝必须填写原因');
$svc->reject($p2, ['reason' => '不是公司员工']);
$row = $svc->find($p2);
check($row['status'] === 'rejected' && $row['reject_reason'] === '不是公司员工' && (int) $row['rejected_by'] === $hrId, '拒绝后记录原因和操作人');
check((int) db()->query("SELECT COUNT(*) FROM crm_user_approval_logs WHERE user_id = {$p2} AND action = 'reject'")->fetchColumn() === 1, '拒绝写审核记录');

// ---------- 停用、启用、解锁 ----------
expect_throws(RuntimeException::class, static function () use ($svc, $hrId): void {
    $svc->disable($hrId);
}, '不能停用自己');
$svc->disable($salesId, ['reason' => '离职']);
check($svc->find($salesId)['status'] === 'disabled', '停用账号');
check(Auth::attempt('sales.one', 'Artdon2026')['success'] === false, '停用后不能登录');
db()->exec("UPDATE crm_users SET failed_login_count = 5, locked_until = '2099-01-01 00:00:00' WHERE id = {$salesId}");
$svc->enable($salesId);
$row = $svc->find($salesId);
check($row['status'] === 'active' && (int) $row['failed_login_count'] === 0 && $row['locked_until'] === null, '启用时同时清除登录失败锁定');
expect_throws(RuntimeException::class, static function () use ($svc, $salesId): void {
    $svc->enable($salesId);
}, '正常状态的账号不需要启用');
db()->exec("UPDATE crm_users SET failed_login_count = 5, locked_until = '2099-01-01 00:00:00' WHERE id = {$salesId}");
$svc->unlock($salesId);
check(Auth::attempt('sales.one', 'Artdon2026')['success'] === true, '解除锁定后可以登录');
Auth::logout();

// ---------- 重置密码 ----------
db()->exec("UPDATE crm_users SET locked_until = '2099-01-01 00:00:00' WHERE id = {$salesId}");
$r = $svc->resetPassword($salesId, []);
$row = $svc->find($salesId);
check($r['generated'] && Password::isStrong($r['password']) && (int) $row['force_password_change'] === 1 && $row['locked_until'] === null, '重置密码：自动生成、需改密码、解除锁定');
check(Auth::attempt('sales.one', $r['password'])['success'] === true && Auth::attempt('sales.one', 'Artdon2026')['success'] === false, '新密码能登录，旧密码不能');
Auth::logout();
$e = expect_throws(ValidationException::class, static function () use ($svc, $salesId): void {
    $svc->resetPassword($salesId, ['new_password' => '123']);
}, '指定的新密码太弱时拒绝');
check($e instanceof ValidationException && ($e->fieldErrors()['new_password'] ?? '') === Password::rule(), '提示密码规则');

// ---------- 没有权限 ----------
$plain = new AccountService(Auth::loadUser(centers_test_user(['username' => 'plain', 'email' => 'pl@example.com', 'role_id' => 2])));
foreach ([
    '新建' => static function () use ($plain): void { $plain->create(['username' => 'zz', 'real_name' => 'z', 'department_id' => '1', 'role_id' => '2']); },
    '编辑' => static function () use ($plain, $salesId): void { $plain->update($salesId, ['real_name' => 'x']); },
    '审核' => static function () use ($plain, $p2): void { $plain->approve($p2, ['department_id' => '1', 'role_id' => '2']); },
    '停用' => static function () use ($plain, $salesId): void { $plain->disable($salesId); },
    '启用' => static function () use ($plain, $salesId): void { $plain->enable($salesId); },
    '重置密码' => static function () use ($plain, $salesId): void { $plain->resetPassword($salesId, []); },
] as $label => $fn) {
    expect_throws(PermissionException::class, $fn, '没有权限的人不能' . $label);
}

centers_test_finish('系统中心第 2 步：账号管理');
