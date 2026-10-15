<?php
declare(strict_types=1);

/**
 * 设置中心、日志查看契约测试。不连正式库：php centers/system/tests/settings_contract.php
 */

require_once dirname(__DIR__, 2) . '/_base/tests/harness.php';

use Artdon\Centers\Base\Audit;
use Artdon\Centers\Base\Auth;
use Artdon\Centers\Base\Nav;
use Artdon\Centers\Base\Password;
use Artdon\Centers\Base\PermissionException;
use Artdon\Centers\Base\Session;
use Artdon\Centers\Base\SystemSettings;
use Artdon\Centers\Base\ValidationException;
use Artdon\Centers\System\LogService;
use Artdon\Centers\System\SettingsService;

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
centers_test_reset();
db()->exec("INSERT INTO crm_roles (id, role_key, role_name, status) VALUES (3, 'viewer', '只读', 'active'), (4, 'auditor', '审计', 'active')");
foreach ([[3, 'settings.view'], [3, 'logs.view_own'], [4, 'settings.view'], [4, 'settings.edit'], [4, 'logs.view_all']] as [$r, $k]) {
    db()->exec("INSERT INTO crm_role_permissions (role_id, permission_key) VALUES ({$r}, '{$k}')");
}
// 正式库里已有的设置行（建表脚本的初始数据在测试库里不会插入）
db()->exec("INSERT INTO crm_system_settings (setting_key, setting_value, setting_group, description) VALUES ('system_name', 'Artdon Office V20', 'system', '系统名称')");
$salesId = centers_test_user(['username' => 'sales', 'email' => 's@example.com', 'role_id' => 2]);
$viewerId = centers_test_user(['username' => 'viewer', 'email' => 'v@example.com', 'role_id' => 3, 'real_name' => '只读']);
$adminId = centers_test_user(['username' => 'admin', 'email' => 'a@example.com', 'role_id' => 4, 'real_name' => '管理']);
$viewer = new SettingsService(Auth::loadUser($viewerId));
$admin = new SettingsService(Auth::loadUser($adminId));

// ---------- 公司信息 ----------
check($admin->canEdit() && !$viewer->canEdit(), '只有 settings.edit 能修改');
check($admin->company()['system_name'] === 'Artdon Office V20', '公司信息读取已有的 system_name');
expect_throws(PermissionException::class, static function () use ($viewer): void {
    $viewer->saveCompany(['system_name' => 'X', 'company_name' => 'Y']);
}, '没有编辑权限不能保存公司信息');
$e = expect_throws(ValidationException::class, static function () use ($admin): void {
    $admin->saveCompany(['system_name' => '', 'company_name' => '', 'company_email' => 'bad', 'company_website' => 'not a site']);
}, '公司信息校验');
$errors = $e instanceof ValidationException ? $e->fieldErrors() : [];
check(isset($errors['system_name'], $errors['company_name'], $errors['company_email'], $errors['company_website']), '必填、邮箱、网站各自报错');
$admin->saveCompany(['system_name' => ' 雅登办公 ', 'company_name' => '广州雅登', 'company_email' => 'info@artdon.com', 'company_website' => 'www.artdon.com']);
check(SystemSettings::systemName() === '雅登办公', '系统名称保存后马上生效（去掉首尾空格）');
check(SystemSettings::get('company_email') === 'info@artdon.com', '公司邮箱已保存');
check((int) db()->query("SELECT COUNT(*) FROM crm_system_settings WHERE setting_key = 'system_name'")->fetchColumn() === 1, '已有的键是更新，不重复插入');
check((int) db()->query("SELECT COUNT(*) FROM crm_audit_logs WHERE action = 'settings_company_update'")->fetchColumn() === 1, '保存公司信息写操作日志');

// ---------- Logo ----------
$png16 = 'iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAIAAACQkWg2AAAAF0lEQVR4nGP438BAEiJN9aiGUQ1DSgMAnHV/EBlpJJcAAAAASUVORK5CYII=';
$png8 = 'iVBORw0KGgoAAAANSUhEUgAAAAgAAAAICAIAAABLbSncAAAAEklEQVR4nGP438CAFWEXHbQSAKmzX8FBxi1QAAAAAElFTkSuQmCC';
check(SystemSettings::logo() === '', '默认没有 Logo');
foreach ([
    'data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=' => 'SVG 不接受',
    'data:image/png;base64,' . base64_encode('not an image at all') => '不是图片的内容不接受',
    'data:image/png;base64,' . $png8 => '太小的图片不接受',
    'data:image/png;base64,' . base64_encode(str_repeat('x', SettingsService::LOGO_MAX_BYTES + 1)) => '超过大小不接受',
    'javascript:alert(1)' => '非图片地址不接受',
] as $bad => $label) {
    expect_throws(ValidationException::class, static function () use ($admin, $bad): void {
        $admin->saveLogo($bad);
    }, $label);
}
expect_throws(PermissionException::class, static function () use ($viewer, $png16): void {
    $viewer->saveLogo('data:image/png;base64,' . $png16);
}, '没有编辑权限不能换 Logo');
// 声明 jpeg、实际 png：按真实类型保存
$admin->saveLogo('data:image/jpeg;base64,' . $png16);
check(SystemSettings::logo() === 'data:image/png;base64,' . $png16, 'Logo 按真实图片类型保存');
$admin->removeLogo();
check(SystemSettings::logo() === '', '去掉 Logo 后回到默认');
db()->prepare('UPDATE crm_system_settings SET setting_value = ? WHERE setting_key = ?')->execute(['data:text/html;base64,PHNjcmlwdD4=', 'centers.logo']);
SystemSettings::clearCache();
check(SystemSettings::logo() === '', '表里被写入非图片内容时不输出');

// ---------- 安全设置 ----------
$sec = SystemSettings::security();
check($sec === ['login_max_failures' => 5, 'login_lock_minutes' => 15, 'password_min_length' => 8, 'session_idle_minutes' => 0], '安全设置默认值');
$e = expect_throws(ValidationException::class, static function () use ($admin): void {
    $admin->saveSecurity(['login_max_failures' => '0', 'login_lock_minutes' => '2000', 'password_min_length' => '6', 'session_idle_minutes' => '5']);
}, '安全设置范围校验');
$errors = $e instanceof ValidationException ? $e->fieldErrors() : [];
check(count($errors) === 4, '四项超出范围各自报错');
$e = expect_throws(ValidationException::class, static function () use ($admin): void {
    $admin->saveSecurity(['login_max_failures' => 'abc', 'login_lock_minutes' => '', 'password_min_length' => '-1']);
}, '非数字报错');
expect_throws(PermissionException::class, static function () use ($viewer): void {
    $viewer->saveSecurity(['login_max_failures' => '3', 'login_lock_minutes' => '10', 'password_min_length' => '10', 'session_idle_minutes' => '30']);
}, '没有编辑权限不能改安全设置');
$admin->saveSecurity(['login_max_failures' => '2', 'login_lock_minutes' => '30', 'password_min_length' => '10', 'session_idle_minutes' => '30']);
check(SystemSettings::security() === ['login_max_failures' => 2, 'login_lock_minutes' => 30, 'password_min_length' => 10, 'session_idle_minutes' => 30], '安全设置已保存');
check(Password::minLength() === 10 && !Password::isStrong('Artdon202') && Password::isStrong('Artdon2026'), '密码最短长度生效');
check(strpos(Password::rule(), '10') !== false, '密码规则说明跟着变');

// 锁定次数生效：错 2 次就锁
Auth::attempt('sales', 'wrong');
$r = Auth::attempt('sales', 'wrong');
check(!$r['success'] && strpos($r['message'], '30 分钟') !== false, '失败 2 次锁定 30 分钟');
$locked = (string) db()->query("SELECT locked_until FROM crm_users WHERE id = {$salesId}")->fetchColumn();
check($locked !== '' && strtotime($locked) > time() + 29 * 60, '锁定时间按设置计算');

// 闲置退出
$_SESSION = [];
Auth::refresh();
$r = Auth::attempt('viewer', 'Artdon2026');
check($r['success'] && Auth::id() === $viewerId, '登录成功');
check(!Auth::expireIfIdle(), '刚登录不算闲置');
Session::set('centers_last_seen', time() - 31 * 60);
check(Auth::expireIfIdle() && Auth::id() === 0, '闲置 31 分钟自动退出');
check((int) db()->query("SELECT COUNT(*) FROM crm_login_logs WHERE status = 'logout' AND message LIKE '[新系统]%闲置%'")->fetchColumn() === 1, '自动退出写登录日志');
$admin->saveSecurity(['login_max_failures' => '5', 'login_lock_minutes' => '15', 'password_min_length' => '8', 'session_idle_minutes' => '0']);
$_SESSION = [];
Auth::refresh();
Auth::attempt('viewer', 'Artdon2026');
Session::set('centers_last_seen', time() - 999 * 60);
check(!Auth::expireIfIdle() && Auth::id() === $viewerId, '设为 0 时不自动退出');
$_SESSION = [];
Auth::refresh();

// ---------- 系统信息 ----------
$info = $admin->systemInfo();
foreach (['程序版本', 'PHP 版本', '数据库', '时区', '账号', '角色', '部门', '权限项'] as $key) {
    check(isset($info[$key]) && $info[$key] !== '', '系统信息含“' . $key . '”');
}
check(strpos($info['账号'], '3 个') === 0, '账号数量正确');
check(SettingsService::gitVersion() !== '', '能读出程序版本');

// ---------- 日志 ----------
expect_throws(PermissionException::class, static function () use ($salesId): void {
    new LogService(Auth::loadUser($salesId));
}, '没有日志权限不能看');
db()->exec("INSERT INTO crm_login_logs (user_id, username, status, ip, message, created_at) VALUES ({$salesId}, 'sales', 'success', '1.1.1.1', '旧系统登录', '2026-01-05 10:00:00')");
Audit::log('custom_action', 'customer', 'customer', '9', null, ['a' => 1], Auth::loadUser($viewerId));
$own = new LogService(Auth::loadUser($viewerId));
$all = new LogService(Auth::loadUser($adminId));
check(!$own->seesAll() && $all->seesAll(), 'view_own 与 view_all 区分');
$ownLogins = $own->logins([]);
check($ownLogins['total'] >= 1 && count(array_filter($ownLogins['rows'], static function (array $r) use ($viewerId): bool {
    return (int) $r['user_id'] !== $viewerId;
})) === 0, '只看自己时只有自己的登录记录');
$allLogins = $all->logins([]);
check($allLogins['total'] > $ownLogins['total'], '看全部时记录更多');
check($all->logins(['source' => 'old'])['total'] === 1, '按来源筛选：旧系统');
check($all->logins(['source' => 'new'])['total'] === $allLogins['total'] - 1, '按来源筛选：新系统');
check($all->logins(['status' => 'fail', 'q' => 'sal'])['total'] === 2, '按结果与账号筛选');
check($all->logins(['from' => '2026-01-05', 'to' => '2026-01-05'])['total'] === 1, '按日期筛选');
check($all->logins(['q' => '%'])['total'] === 0, '搜索里的 % 按普通字符处理');
check($all->logins(['status' => 'drop table'])['total'] === $allLogins['total'], '无效的结果筛选被忽略');
$ownAudits = $own->audits([]);
check($ownAudits['total'] === 1 && $ownAudits['rows'][0]['action'] === 'custom_action', '只看自己的操作日志');
check($all->audits(['module' => 'settings'])['total'] >= 4, '按模块筛选操作日志');
check($all->audits(['action' => 'logo'])['total'] === 2, '按动作关键字筛选');
check(in_array('settings', $all->auditModules(), true) && $own->auditModules() === ['customer'], '模块下拉按可见范围');
for ($i = 0; $i < 60; $i++) {
    Audit::log('bulk_' . $i, 'test', 'x', (string) $i, null, null, Auth::loadUser($adminId));
}
$p2 = $all->audits(['module' => 'test'], 2);
check($p2['pages'] === 2 && $p2['page'] === 2 && count($p2['rows']) === 10, '分页');
check($all->audits(['module' => 'test'], 99)['page'] === 2, '页码超出时取最后一页');

// ---------- 导航 ----------
$keys = static function (callable $can): array {
    $out = [];
    foreach (Nav::visible('', $can) as $g) {
        foreach ($g['items'] as $item) {
            $out[] = $item['key'];
        }
    }
    return $out;
};
check(in_array('logs', $keys(static function (string $p): bool {
    return $p === 'logs.view_all';
}), true), '只有 logs.view_all 也能看到日志菜单');
check(!in_array('logs', $keys(static function (string $p): bool {
    return false;
}), true) && !in_array('settings', $keys(static function (string $p): bool {
    return false;
}), true), '没有权限看不到设置与日志菜单');

centers_test_finish('settings_contract');
