<?php
declare(strict_types=1);

/**
 * 权限中心第 1 批（部门、角色、角色权限）契约测试。
 * 不连正式库：php centers/system/tests/permission_center_contract.php
 */

require_once dirname(__DIR__, 2) . '/_base/tests/harness.php';

use Artdon\Centers\Base\Auth;
use Artdon\Centers\Base\NotFoundException;
use Artdon\Centers\Base\Permission;
use Artdon\Centers\Base\PermissionException;
use Artdon\Centers\Base\ValidationException;
use Artdon\Centers\System\DepartmentService;
use Artdon\Centers\System\RoleService;

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
centers_test_reset();

$perms = [
    ['customer.view', 'customer', 'view', '查看客户', 'low'],
    ['customer.export', 'customer', 'export', '导出客户', 'high'],
    ['mail.view', 'mail', 'view', '查看邮箱', 'low'],
    ['users.view', 'users', 'view', '查看用户', 'medium'],
    ['users.manage_roles', 'users', 'manage_roles', '管理角色', 'high'],
    ['users.manage_departments', 'users', 'manage_departments', '管理部门', 'high'],
    ['dangerous.permission_admin', 'dangerous', 'permission_admin', '权限管理员', 'dangerous'],
    ['zzz.custom', 'zzz', 'custom', '', 'low'],
];
$ins = db()->prepare('INSERT INTO crm_permissions (permission_key, module, action, description, risk_level) VALUES (?, ?, ?, ?, ?)');
foreach ($perms as $p) {
    $ins->execute($p);
}
// 角色 3 = 权限管理员（不是超管）：有管理角色、部门、改权限，但没有 customer.export
db()->exec("INSERT INTO crm_roles (id, role_key, role_name, status, is_system) VALUES (3, 'perm_admin', '权限管理员', 'active', 0)");
foreach (['users.view', 'users.manage_roles', 'users.manage_departments', 'dangerous.permission_admin', 'customer.view', 'mail.view'] as $k) {
    db()->exec("INSERT INTO crm_role_permissions (role_id, permission_key) VALUES (3, '{$k}')");
}
db()->exec("INSERT INTO crm_role_permissions (role_id, permission_key) VALUES (2, 'customer.view')");
$adminId = centers_test_user(['username' => 'pa', 'email' => 'pa@example.com', 'role_id' => 3]);
$bossId = centers_test_user(['username' => 'boss', 'email' => 'b@example.com', 'role_id' => 1, 'is_super_admin' => 1]);
$salesId = centers_test_user(['username' => 'sales', 'email' => 's@example.com', 'role_id' => 2]);
$admin = Auth::loadUser($adminId);
$boss = Auth::loadUser($bossId);
$sales = Auth::loadUser($salesId);

// ---------- 部门 ----------
$dept = new DepartmentService($admin);
$list = $dept->list();
check(count($list) === 3 && $list[0]['status'] === 'active' && end($list)['status'] === 'disabled', '部门列表含停用部门，启用的排前面');
$d1 = array_values(array_filter($list, static function ($r) { return (int) $r['id'] === 1; }))[0];
check((int) $d1['user_count'] === 3, '部门显示账号数');
$e = expect_throws(ValidationException::class, static function () use ($dept): void {
    $dept->create(['name' => '外贸部', 'code' => 'Bad Code', 'manager_user_id' => '9999']);
}, '新建部门时同名、编码格式错、负责人无效都报错');
$f = $e instanceof ValidationException ? array_keys($e->fieldErrors()) : [];
sort($f);
check($f === ['code', 'manager_user_id', 'name'], '三个字段的错误都返回');
$newDept = $dept->create(['name' => '市场部', 'code' => '', 'sort_order' => '20', 'manager_user_id' => (string) $salesId]);
$row = $dept->find($newDept);
check($row['status'] === 'active' && strpos((string) $row['code'], 'dept_') === 0 && (int) $row['manager_user_id'] === $salesId, '新建部门：编码留空时自动生成，可指定负责人');
$dept->update($newDept, ['name' => '市场推广部', 'sort_order' => '5', 'manager_user_id' => '']);
$row = $dept->find($newDept);
check($row['name'] === '市场推广部' && (int) $row['sort_order'] === 5 && $row['manager_user_id'] === null, '编辑部门名称、排序、清空负责人');
expect_throws(RuntimeException::class, static function () use ($dept): void {
    $dept->disable(1);
}, '部门下还有账号时不能停用');
$dept->disable($newDept);
check($dept->find($newDept)['status'] === 'disabled', '没有账号的部门可以停用');
$dept->enable($newDept);
check($dept->find($newDept)['status'] === 'active', '重新启用部门');
expect_throws(PermissionException::class, static function () use ($sales): void {
    (new DepartmentService($sales))->create(['name' => '新部门']);
}, '没有 users.manage_departments 不能新建部门');
check((int) db()->query("SELECT COUNT(*) FROM crm_audit_logs WHERE action LIKE 'department_%'")->fetchColumn() === 4, '部门操作都写日志');

// ---------- 角色 ----------
$roles = new RoleService($admin);
$all = $roles->list();
check(count($all) === 3 && (int) $all[1]['user_count'] === 1 && (int) $all[2]['permission_count'] === 6, '角色列表带账号数和权限数');
$e = expect_throws(ValidationException::class, static function () use ($roles): void {
    $roles->create(['role_key' => 'sales', 'role_name' => '业务员']);
}, '角色编码或名称重复时拒绝');
check($e instanceof ValidationException && count($e->fieldErrors()) === 2, '编码和名称的重复都报错');
expect_throws(ValidationException::class, static function () use ($roles): void {
    $roles->create(['role_key' => 'Bad-Key', 'role_name' => '新角色']);
}, '角色编码格式不对时拒绝');
$rid = $roles->create(['role_key' => 'sales_manager', 'role_name' => '业务主管', 'description' => '管理业务员']);
check($roles->permissionsOf($rid) === [] && $roles->find($rid)['is_system'] == 0, '新角色没有任何权限，不是系统内置');
$roles->update($rid, ['role_name' => '业务经理', 'description' => '']);
check($roles->find($rid)['role_name'] === '业务经理' && $roles->find($rid)['role_key'] === 'sales_manager', '编辑角色名称，编码不变');
expect_throws(RuntimeException::class, static function () use ($roles): void {
    $roles->disable(2);
}, '还有账号在用的角色不能停用');
$roles->disable($rid);
check($roles->find($rid)['status'] === 'disabled', '没人用的角色可以停用');
$roles->enable($rid);
expect_throws(PermissionException::class, static function () use ($roles): void {
    $roles->update(1, ['role_name' => '超管改名']);
}, '不是超管的人不能修改“超级管理员”角色');
expect_throws(NotFoundException::class, static function () use ($roles): void {
    $roles->update(999, ['role_name' => 'x']);
}, '角色不存在时返回“不存在”');
expect_throws(PermissionException::class, static function () use ($sales): void {
    (new RoleService($sales))->create(['role_key' => 'x_role', 'role_name' => 'X']);
}, '没有 users.manage_roles 不能新建角色');

// ---------- 权限清单 ----------
$catalog = $roles->catalog();
$modules = array_column($catalog, 'module');
check($modules[0] === 'customer' && end($modules) === 'zzz', '权限按模块分组，常用模块在前，未知模块在后');
check($catalog[0]['label'] === '客户', '模块显示中文名');
$zzz = end($catalog);
check($zzz['label'] === 'zzz' && $zzz['permissions'][0]['label'] === 'custom', '没有中文名的模块和权限显示编码');

// ---------- 角色权限 ----------
$r = $roles->savePermissions($rid, ['customer.view', 'mail.view']);
check($r['added'] === ['customer.view', 'mail.view'] && $r['removed'] === [], '保存角色权限：返回增加的项');
$uid = centers_test_user(['username' => 'mgr', 'email' => 'mgr@example.com', 'role_id' => $rid]);
check(Permission::allows(Auth::loadUser($uid), 'mail.view'), '角色权限保存后，这个角色下的账号马上有权限');
$r = $roles->savePermissions($rid, ['customer.view']);
check($r['removed'] === ['mail.view'] && !Permission::allows(Auth::loadUser($uid), 'mail.view'), '去掉权限后马上失效');
$r = $roles->savePermissions($rid, ['customer.view']);
check($r['added'] === [] && $r['removed'] === [], '没有改动时不写数据');
expect_throws(PermissionException::class, static function () use ($roles, $rid): void {
    $roles->savePermissions($rid, ['customer.view', 'customer.export']);
}, '不是超管的人不能授予自己没有的权限');
check(!$roles->grantable('customer.export') && $roles->grantable('customer.view'), '页面据此把自己没有的权限显示为不可勾选');
expect_throws(ValidationException::class, static function () use ($roles, $rid): void {
    $roles->savePermissions($rid, ['no.such']);
}, '提交不存在的权限时拒绝');
$bossRoles = new RoleService($boss);
$bossRoles->savePermissions($rid, ['customer.view', 'customer.export']);
check(in_array('customer.export', $bossRoles->permissionsOf($rid), true), '超管可以授予任何权限');
$roles->savePermissions($rid, []);
check($roles->permissionsOf($rid) === [], '非超管可以去掉自己没有的权限（全部清空）');
expect_throws(PermissionException::class, static function () use ($sales, $rid): void {
    (new RoleService($sales))->savePermissions($rid, ['customer.view']);
}, '没有“权限管理员”权限不能修改角色权限');
$log = db()->query("SELECT before_json, after_json FROM crm_audit_logs WHERE action = 'role_permissions_update' ORDER BY id DESC LIMIT 1")->fetch();
check(strpos((string) $log['before_json'], 'customer.export') !== false, '角色权限修改写日志，记下增加和去掉的项');

centers_test_finish('权限中心第 1 批：部门、角色、角色权限');
