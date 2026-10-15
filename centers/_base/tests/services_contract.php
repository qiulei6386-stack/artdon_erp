<?php
declare(strict_types=1);

/**
 * 底座公共件契约测试：通知、字典、数据范围、人员目录，以及权限申请发通知。
 * 不连正式库：php centers/_base/tests/services_contract.php
 */

require_once __DIR__ . '/harness.php';

use Artdon\Centers\Base\Auth;
use Artdon\Centers\Base\DataScope;
use Artdon\Centers\Base\Dictionary;
use Artdon\Centers\Base\Notify;
use Artdon\Centers\Base\UserDirectory;
use Artdon\Centers\System\RequestService;

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
centers_test_reset();

// ---------- 通知 ----------
$aId = centers_test_user(['username' => 'a', 'email' => 'a@example.com']);
$bId = centers_test_user(['username' => 'b', 'email' => 'b@example.com']);
check(Notify::unreadCount($aId) === 0, '一开始没有未读');
check(Notify::send([$aId, $aId, 0, $bId], 'test', '标题一', '内容', ['center' => 'system', 'path' => 'requests.php?view=approve']) === 2, '接收人去重、去掉 0');
Notify::send([$aId], 'test', '标题二');
check(Notify::send([], 'test', 'x') === 0, '没有接收人时不发');
check(Notify::unreadCount($aId) === 2 && Notify::unreadCount($bId) === 1, '未读数');
db()->exec("INSERT INTO sys_notifications (user_id, type, title, created_at) VALUES ({$aId}, 'mail_new', '旧系统的邮件通知', '2026-01-01 00:00:00')");
Notify::clearCache();
check(Notify::unreadCount($aId) === 2, '旧系统写的通知不算在新系统里');
$list = Notify::list($aId);
check($list['total'] === 2 && $list['rows'][0]['title'] === '标题二', '列表只有新系统通知，新的在前');
check($list['rows'][1]['url'] === \Artdon\Centers\Base\Url::center('system', 'requests.php?view=approve'), '通知链接指向新系统页面');
check($list['rows'][0]['url'] === '', '没有链接的通知 url 为空');
$firstId = (int) $list['rows'][1]['id'];
$bRow = Notify::list($bId)['rows'][0];
check(Notify::find($aId, (int) $bRow['id']) === null, '找不到别人的通知');
check(Notify::markRead($aId, [(int) $bRow['id']]) === 0, '不能把别人的通知标为已读');
check(Notify::markRead($aId, [$firstId]) === 1 && Notify::unreadCount($aId) === 1, '标为已读');
check(Notify::list($aId, 1, true)['total'] === 1, '只看未读');
check(Notify::markAllRead($aId) === 1 && Notify::unreadCount($aId) === 0 && Notify::unreadCount($bId) === 1, '全部标为已读只影响自己');
check((string) db()->query("SELECT read_at FROM sys_notifications WHERE type = 'mail_new'")->fetchColumn() === '', '不动旧系统的通知');
db()->prepare('INSERT INTO sys_notifications (user_id, type, title, payload_json, created_at) VALUES (?, ?, ?, ?, ?)')
    ->execute([$aId, 'centers.test', '坏链接', '{"center":"system","path":"//evil.example.com/x"}', '2026-01-02 00:00:00']);
db()->prepare('INSERT INTO sys_notifications (user_id, type, title, payload_json, created_at) VALUES (?, ?, ?, ?, ?)')
    ->execute([$aId, 'centers.test', '坏链接2', '{"center":"../x","path":"a.php"}', '2026-01-02 00:00:00']);
$bad = array_column(Notify::list($aId)['rows'], 'url', 'title');
check($bad['坏链接'] === '' && $bad['坏链接2'] === '', '不接受站外或越级的链接');
db()->exec('DROP TABLE sys_notifications');
\Artdon\Centers\Base\Db::set(db());
Notify::clearCache();
check(Notify::send([$aId], 'test', 'x') === 0 && Notify::unreadCount($aId) === 0 && Notify::list($aId)['total'] === 0, '没有通知表时不报错');

// ---------- 字典 ----------
centers_test_reset();
check(Dictionary::options('customer_level') === [] && Dictionary::defaultKey('customer_level', 'P3') === 'P3', '字典为空时用默认');
$ins = db()->prepare('INSERT INTO crm_dictionary_items (type_key, item_key, name_cn, name_en, is_default, is_enabled, sort_order, deleted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
foreach ([
    ['customer_level', 'P1', '重点', 'Key', 0, 1, 20, null],
    ['customer_level', 'P0', '战略', 'Strategic', 0, 1, 10, null],
    ['customer_level', 'P3', '普通', 'Normal', 1, 1, 40, null],
    ['customer_level', 'PX', '停用的', '', 0, 0, 50, null],
    ['customer_level', 'PD', '删掉的', '', 0, 1, 60, '2026-01-01 00:00:00'],
    ['customer_source', 'website', '网站', 'Website', 0, 1, 10, null],
] as $row) {
    $ins->execute($row);
}
Dictionary::clearCache();
check(Dictionary::options('customer_level') === ['P0' => '战略', 'P1' => '重点', 'P3' => '普通'], '按排序，只取启用、未删除的');
check(Dictionary::defaultKey('customer_level') === 'P3' && Dictionary::defaultKey('customer_source') === 'website', '默认项；没标默认取第一项');
check(Dictionary::label('customer_level', 'P1') === '重点' && Dictionary::label('customer_level', 'PX') === 'PX', '中文名；停用的原样显示键');
check(Dictionary::has('customer_level', 'P0') && !Dictionary::has('customer_level', 'PX'), '是否有效');

// ---------- 数据范围 ----------
foreach ([['customer.view_all', 'customer', 'view_all'], ['customer.view_department', 'customer', 'view_department']] as $p) {
    db()->prepare("INSERT INTO crm_permissions (permission_key, module, action, description, risk_level) VALUES (?, ?, ?, '', 'high')")->execute($p);
}
db()->exec("INSERT INTO crm_roles (id, role_key, role_name, status) VALUES (3, 'mgr', '主管', 'active'), (4, 'boss2', '总监', 'active')");
db()->exec("INSERT INTO crm_role_permissions (role_id, permission_key) VALUES (3, 'customer.view_department'), (4, 'customer.view_all')");
$sales = centers_test_user(['username' => 's1', 'email' => 's1@example.com', 'department_id' => 1]);
$mgr = centers_test_user(['username' => 'm1', 'email' => 'm1@example.com', 'role_id' => 3, 'department_id' => 1]);
$dir = centers_test_user(['username' => 'd1', 'email' => 'd1@example.com', 'role_id' => 4, 'department_id' => 2]);
$boss = centers_test_user(['username' => 'boss', 'email' => 'boss@example.com', 'role_id' => 1, 'is_super_admin' => 1]);
$scope = static function (int $id, string $module = 'customer'): array {
    return DataScope::resolve(Auth::loadUser($id), $module);
};
$setScope = static function (int $id, string $module, string $type, string $value = ''): void {
    db()->prepare('DELETE FROM crm_user_scopes WHERE user_id = ? AND module = ?')->execute([$id, $module]);
    db()->prepare('INSERT INTO crm_user_scopes (user_id, module, scope_type, scope_value) VALUES (?, ?, ?, ?)')->execute([$id, $module, $type, $value]);
};
$s = $scope($sales);
check(!$s['all'] && $s['self'] && !$s['department_ids'] && !$s['none'], '默认只看本人');
check(DataScope::describe($s) === '本人', '说明：本人');
check($scope($mgr)['department_ids'] === [1], '有本部门权限看本部门');
check($scope($dir)['all'] && $scope($boss)['all'], '有全部权限或超管看全部');
$setScope($sales, 'customer', 'department');
check($scope($sales)['department_ids'] === [1], '数据范围设为本部门');
$setScope($sales, 'customer', 'team');
check($scope($sales)['department_ids'] === [1], '本团队按本部门算');
$setScope($sales, 'customer', 'country', 'IN, 印度，US');
$s = $scope($sales);
check($s['countries'] === ['IN', '印度', 'US'] && $s['self'], '指定国家，同时仍看本人');
check(DataScope::describe($s) === '本人 + 国家：IN、印度、US', '说明：国家');
$setScope($sales, 'customer', 'customer_group', '3,5,x,3');
check($scope($sales)['group_ids'] === [3, 5], '指定分组');
$setScope($sales, 'customer', 'all');
check($scope($sales)['all'], '数据范围设为全部');
$setScope($sales, 'customer', 'none');
$s = $scope($sales);
check($s['none'] && !$s['self'], '都不能看');
$setScope($mgr, 'customer', 'none');
check(!$scope($mgr)['none'] && $scope($mgr)['department_ids'] === [1], '取范围大的：有本部门权限时“都不能看”不生效');
$setScope($mgr, 'customer', 'country', 'DE');
$m = $scope($mgr);
check($m['department_ids'] === [1] && $m['countries'] === ['DE'], '权限和数据范围叠加');
check($scope($sales, 'mail')['self'] && !$scope($sales, 'mail')['none'], '不同模块分开算');
$noDept = centers_test_user(['username' => 'nd', 'email' => 'nd@example.com', 'role_id' => 3, 'department_id' => null]);
check($scope($noDept)['department_ids'] === [], '没有部门时本部门为空');

// ---------- 人员目录 ----------
db()->exec("UPDATE crm_users SET status = 'disabled' WHERE id = {$noDept}");
UserDirectory::clearCache();
$ids = array_column(UserDirectory::active(), 'id');
check(in_array($sales, $ids, true) && !in_array($noDept, $ids, true), '只列正常账号');
check(UserDirectory::isActive($mgr) && !UserDirectory::isActive($noDept), '是否正常账号');
$g = UserDirectory::grouped();
check(isset($g['外贸部'][$sales], $g['财务部'][$dir]), '按部门分组');

// ---------- 权限申请发通知 ----------
foreach ([['mail.send', 'mail', 'send', 'medium'], ['permission_request.approve_all', 'permission_request', 'approve_all', 'high'], ['permission_request.approve_department', 'permission_request', 'approve_department', 'medium'], ['dangerous.permission_admin', 'dangerous', 'permission_admin', 'dangerous'], ['customer.export', 'customer', 'export', 'dangerous']] as $p) {
    db()->prepare("INSERT INTO crm_permissions (permission_key, module, action, description, risk_level) VALUES (?, ?, ?, '', ?)")->execute($p);
}
db()->exec("INSERT INTO crm_role_permissions (role_id, permission_key) VALUES (3, 'permission_request.approve_department'), (3, 'mail.send'), (4, 'permission_request.approve_all'), (4, 'mail.send')");
Notify::clearCache();
$req = new RequestService(Auth::loadUser($sales));
$id = $req->submit(['permission_key' => 'mail.send', 'reason' => '给客户发报价', 'urgency' => 'urgent', 'expected_days' => '7']);
$who = static function (string $type): array {
    $rows = db()->query("SELECT user_id FROM sys_notifications WHERE type = 'centers.{$type}' ORDER BY user_id")->fetchAll(PDO::FETCH_COLUMN);
    return array_map('intval', $rows);
};
check($who('permission_request') === [$mgr, $dir, $boss], '提交申请通知能审批的人（本部门主管、全部审批人、超管），不含申请人和停用账号');
check(strpos((string) db()->query("SELECT title FROM sys_notifications WHERE type = 'centers.permission_request' LIMIT 1")->fetchColumn(), '【紧急】') === 0, '紧急申请标题带【紧急】');
(new RequestService(Auth::loadUser($mgr)))->approve($id, ['days' => '7']);
check($who('permission_approved') === [$sales], '批准后通知申请人');
$id2 = $req->submit(['permission_key' => 'customer.export', 'reason' => '导出', 'urgency' => 'normal', 'expected_days' => '1']);
$high = array_map('intval', db()->query("SELECT user_id FROM sys_notifications WHERE type = 'centers.permission_request' AND title LIKE '%customer.export%'")->fetchAll(PDO::FETCH_COLUMN));
check($high === [$boss], '高风险权限只通知超管或权限管理员');
(new RequestService(Auth::loadUser($boss)))->reject($id2, ['reason' => '走流程']);
check($who('permission_rejected') === [$sales], '拒绝后通知申请人');
$grantId = (int) db()->query('SELECT id FROM crm_permission_grants LIMIT 1')->fetchColumn();
(new RequestService(Auth::loadUser($boss)))->extend($grantId, ['expires_on' => date('Y-m-d', time() + 20 * 86400)]);
check($who('permission_extended') === [$sales], '延长后通知授权人');
(new RequestService(Auth::loadUser($boss)))->revoke($grantId, ['reason' => '完成']);
check($who('permission_revoked') === [$sales], '撤销后通知授权人');
check(Notify::unreadCount($sales) === 4, '申请人收到 4 条');

centers_test_finish('底座公共件：通知、字典、数据范围、人员目录');
