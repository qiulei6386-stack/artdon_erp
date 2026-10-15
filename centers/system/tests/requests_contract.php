<?php
declare(strict_types=1);

/**
 * 权限中心第 3 批（权限申请与临时授权）契约测试。不连正式库：php centers/system/tests/requests_contract.php
 */

require_once dirname(__DIR__, 2) . '/_base/tests/harness.php';

use Artdon\Centers\Base\Auth;
use Artdon\Centers\Base\NotFoundException;
use Artdon\Centers\Base\Permission;
use Artdon\Centers\Base\PermissionException;
use Artdon\Centers\Base\ValidationException;
use Artdon\Centers\System\RequestService;

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
centers_test_reset();
$ins = db()->prepare('INSERT INTO crm_permissions (permission_key, module, action, description, risk_level) VALUES (?, ?, ?, ?, ?)');
foreach ([
    ['customer.view', 'customer', 'view', '查看客户', 'low'],
    ['customer.export', 'customer', 'export', '导出客户', 'high'],
    ['mail.view', 'mail', 'view', '查看邮箱', 'low'],
    ['mail.send', 'mail', 'send', '发送邮件', 'medium'],
    ['dangerous.bulk_delete', 'dangerous', 'bulk_delete', '批量删除', 'dangerous'],
    ['permission_request.approve_all', 'permission_request', 'approve_all', '审批全部', 'high'],
    ['permission_request.approve_department', 'permission_request', 'approve_department', '审批本部门', 'medium'],
    ['permission_request.revoke', 'permission_request', 'revoke', '撤销授权', 'high'],
    ['dangerous.permission_admin', 'dangerous', 'permission_admin', '权限管理员', 'dangerous'],
] as $p) {
    $ins->execute($p);
}
db()->exec("INSERT INTO crm_roles (id, role_key, role_name, status) VALUES (3, 'lead', '主管', 'active'), (4, 'hr', '人事', 'active'), (5, 'padmin', '权限管理员', 'active')");
// 主管：本部门审批，有 mail.send；人事：全部审批 + 撤销，有 mail.send、customer.export；权限管理员
foreach ([[3, 'permission_request.approve_department'], [3, 'mail.send'], [3, 'customer.view'],
          [4, 'permission_request.approve_all'], [4, 'permission_request.revoke'], [4, 'mail.send'], [4, 'customer.export'],
          [5, 'dangerous.permission_admin'], [2, 'customer.view']] as [$r, $k]) {
    db()->exec("INSERT INTO crm_role_permissions (role_id, permission_key) VALUES ({$r}, '{$k}')");
}
$salesId = centers_test_user(['username' => 'sales', 'email' => 's@example.com', 'role_id' => 2, 'department_id' => 1, 'real_name' => '业务']);
$otherDeptId = centers_test_user(['username' => 'fin', 'email' => 'f@example.com', 'role_id' => 2, 'department_id' => 2, 'real_name' => '财务员']);
$leadId = centers_test_user(['username' => 'lead', 'email' => 'l@example.com', 'role_id' => 3, 'department_id' => 1, 'real_name' => '主管']);
$hrId = centers_test_user(['username' => 'hr', 'email' => 'h@example.com', 'role_id' => 4, 'department_id' => 2, 'real_name' => '人事']);
$paId = centers_test_user(['username' => 'pa', 'email' => 'pa@example.com', 'role_id' => 5, 'department_id' => 2, 'real_name' => '权限管理员']);
$bossId = centers_test_user(['username' => 'boss', 'email' => 'b@example.com', 'role_id' => 1, 'is_super_admin' => 1, 'real_name' => '老板']);
$svc = static function (int $id): RequestService {
    return new RequestService(Auth::loadUser($id));
};
$sales = $svc($salesId);

// ---------- 申请 ----------
$keys = [];
foreach ($sales->requestable() as $g) {
    foreach ($g['permissions'] as $p) {
        $keys[] = $p['key'];
    }
}
check(!in_array('customer.view', $keys, true) && in_array('mail.send', $keys, true), '可申请列表不含自己已有的权限');
$e = expect_throws(ValidationException::class, static function () use ($sales): void {
    $sales->submit(['permission_key' => 'customer.view', 'reason' => '', 'urgency' => 'whatever', 'expected_days' => '0']);
}, '申请已有的权限、原因为空、紧急程度和天数不对时报错');
$f = $e instanceof ValidationException ? array_keys($e->fieldErrors()) : [];
sort($f);
check($f === ['expected_days', 'permission_key', 'reason', 'urgency'], '四个字段的错误都返回');
$r1 = $sales->submit(['permission_key' => 'mail.send', 'reason' => '需要给客户发报价', 'urgency' => 'urgent', 'expected_days' => '3']);
$row = $sales->findRequest($r1);
check($row['status'] === 'pending' && (int) $row['department_id'] === 1 && $row['module'] === 'mail' && $row['permission_label'] === '发送邮件', '提交申请：记录部门、模块、权限名称');
expect_throws(ValidationException::class, static function () use ($sales): void {
    $sales->submit(['permission_key' => 'mail.send', 'reason' => '再来一次', 'expected_days' => '1']);
}, '同一权限已有待审批申请时不能重复提交');
check(count($sales->myRequests()) === 1, '我的申请列表');
check((int) db()->query("SELECT COUNT(*) FROM crm_permission_request_logs WHERE action = 'submit'")->fetchColumn() === 1, '提交写申请记录');

// ---------- 审批资格 ----------
check($sales->approveBlockedReason($row) === '不能审批自己的申请', '不能审批自己的申请');
check($svc($leadId)->approveBlockedReason($row) === '', '本部门主管可以审批本部门的普通权限');
$r2 = $svc($otherDeptId)->submit(['permission_key' => 'mail.send', 'reason' => '财务对账', 'expected_days' => '1']);
check($svc($leadId)->approveBlockedReason($svc($leadId)->findRequest($r2)) === '只能审批本部门的申请', '主管不能审批其他部门的申请');
check($svc($hrId)->approveBlockedReason($svc($hrId)->findRequest($r2)) === '', '有“审批全部”权限的人可以审批其他部门');
$r3 = $sales->submit(['permission_key' => 'customer.export', 'reason' => '导出客户清单', 'expected_days' => '1']);
check($svc($hrId)->approveBlockedReason($svc($hrId)->findRequest($r3)) === '高风险权限只能由超级管理员或权限管理员审批', '高风险权限：审批全部的人也不能批');
check($svc($paId)->approveBlockedReason($svc($paId)->findRequest($r3)) === '', '高风险权限：权限管理员可以批');
$r4 = $sales->submit(['permission_key' => 'mail.view', 'reason' => '看邮件', 'expected_days' => '1']);
check($svc($leadId)->approveBlockedReason($svc($leadId)->findRequest($r4)) === '不能审批超出自己权限范围的申请', '不能审批自己也没有的权限');
check(count($svc($leadId)->pendingForMe()) === 1 && count($svc($hrId)->pendingForMe()) === 2, '待我审批只列出我能批的');
check($sales->pendingForMe() === [] && !$sales->isApprover(), '普通员工没有待我审批');

// ---------- 批准 ----------
$lead = $svc($leadId);
expect_throws(ValidationException::class, static function () use ($lead, $r1): void {
    $lead->approve($r1, ['days' => '200']);
}, '授权天数超过 90 天时拒绝');
expect_throws(ValidationException::class, static function () use ($lead, $r1): void {
    $lead->approve($r1, ['permanent' => '1']);
}, '不是超管不能给永久授权');
$grantId = $lead->approve($r1, ['days' => '3', 'note' => '']);
$grant = db()->query('SELECT * FROM crm_permission_grants WHERE id = ' . $grantId)->fetch();
check($grant['status'] === 'active' && (int) $grant['user_id'] === $salesId && (int) $grant['is_temporary'] === 1 && abs(strtotime($grant['expires_at']) - (time() + 3 * 86400)) < 60, '批准后生成 3 天的临时授权');
check($sales->findRequest($r1)['status'] === 'approved', '申请变为已批准');
check(Permission::allows(Auth::loadUser($salesId), 'mail.send'), '批准后申请人马上有这项权限');
expect_throws(RuntimeException::class, static function () use ($lead, $r1): void {
    $lead->approve($r1, ['days' => '3']);
}, '已处理的申请不能再批');
$pa = $svc($paId);
expect_throws(ValidationException::class, static function () use ($pa, $r3): void {
    $pa->approve($r3, ['days' => '1', 'note' => '']);
}, '高风险权限批准时必须写备注');
$pa->approve($r3, ['days' => '1', 'note' => '老板口头同意']);
check(Permission::allows(Auth::loadUser($salesId), 'customer.export'), '权限管理员批准高风险权限');
$bossGrant = $svc($bossId)->approve($r4, ['permanent' => '1']);
check(db()->query('SELECT expires_at FROM crm_permission_grants WHERE id = ' . $bossGrant)->fetchColumn() === null, '超管可以给永久授权');
expect_throws(PermissionException::class, static function () use ($lead, $r2): void {
    $lead->approve($r2, ['days' => '1']);
}, '没有资格审批时拒绝');

// ---------- 拒绝与取消 ----------
$hr = $svc($hrId);
expect_throws(ValidationException::class, static function () use ($hr, $r2): void {
    $hr->reject($r2, ['reason' => '']);
}, '拒绝必须填原因');
$hr->reject($r2, ['reason' => '财务不需要发邮件']);
check($hr->findRequest($r2)['status'] === 'rejected' && $hr->findRequest($r2)['reject_reason'] === '财务不需要发邮件', '拒绝申请');
$r5 = $sales->submit(['permission_key' => 'dangerous.bulk_delete', 'reason' => '清理数据', 'expected_days' => '1']);
expect_throws(PermissionException::class, static function () use ($lead, $r5): void {
    $lead->cancel($r5);
}, '只能取消自己的申请');
$sales->cancel($r5);
check($sales->findRequest($r5)['status'] === 'cancelled', '取消自己的申请');

// ---------- 临时授权：撤销与延长 ----------
check(count($hr->activeGrants(true)) === 3 && count($sales->activeGrants(false)) === 3, '列出有效的临时授权（全部 / 自己的）');
expect_throws(PermissionException::class, static function () use ($lead, $grantId): void {
    $lead->revoke($grantId, ['reason' => 'x']);
}, '没有撤销权限的人不能撤销');
expect_throws(ValidationException::class, static function () use ($hr, $grantId): void {
    $hr->extend($grantId, ['expires_on' => date('Y-m-d')]);
}, '延长的日期要晚于现在的到期时间');
expect_throws(ValidationException::class, static function () use ($hr, $grantId): void {
    $hr->extend($grantId, ['expires_on' => date('Y-m-d', time() + 200 * 86400)]);
}, '最多延长到 90 天内');
$newDate = date('Y-m-d', time() + 10 * 86400);
$hr->extend($grantId, ['expires_on' => $newDate, 'note' => '项目延期']);
check(db()->query('SELECT expires_at FROM crm_permission_grants WHERE id = ' . $grantId)->fetchColumn() === $newDate . ' 23:59:59', '延长到指定日期当天结束');
expect_throws(RuntimeException::class, static function () use ($hr, $bossGrant): void {
    $hr->extend($bossGrant, ['expires_on' => date('Y-m-d', time() + 5 * 86400)]);
}, '永久授权不需要延长');
expect_throws(ValidationException::class, static function () use ($hr, $grantId): void {
    $hr->revoke($grantId, ['reason' => '']);
}, '撤销必须填原因');
$hr->revoke($grantId, ['reason' => '工作已完成']);
check(!Permission::allows(Auth::loadUser($salesId), 'mail.send'), '撤销后马上失效');
check($sales->findRequest($r1)['status'] === 'cancelled', '撤销后对应申请标记为已取消');
expect_throws(RuntimeException::class, static function () use ($hr, $grantId): void {
    $hr->revoke($grantId, ['reason' => '再撤一次']);
}, '已撤销的授权不能再撤销');
expect_throws(NotFoundException::class, static function () use ($hr): void {
    $hr->revoke(9999, ['reason' => 'x']);
}, '授权不存在时返回“不存在”');
db()->exec("UPDATE crm_permission_grants SET expires_at = '2000-01-01 00:00:00' WHERE id = (SELECT MIN(id) FROM crm_permission_grants WHERE status = 'active' AND expires_at IS NOT NULL)");
check(count($hr->activeGrants(true)) === 1, '过期的授权不再列出（按时间判断，不改数据）');

// ---------- 日志与全部记录 ----------
$actions = db()->query('SELECT action FROM crm_permission_request_logs ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
check(count(array_intersect(['submit', 'approve', 'reject', 'cancel', 'revoke', 'extend'], $actions)) === 6, '提交、批准、拒绝、取消、撤销、延长都写申请记录');
check(count($hr->allRequests()) === 5 && count($hr->allRequests('pending')) === 0, '全部申请可按状态筛选');
expect_throws(PermissionException::class, static function () use ($sales): void {
    $sales->allRequests();
}, '普通员工不能看全部申请');

centers_test_finish('权限中心第 3 批：权限申请与临时授权');
