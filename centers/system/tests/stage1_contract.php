<?php
declare(strict_types=1);

/**
 * 公共底座 + 系统中心第 1 步（登录、个人账号）契约测试。
 * 不连正式库、不引用旧系统代码：php centers/system/tests/stage1_contract.php
 */

require_once dirname(__DIR__, 2) . '/_base/tests/harness.php';

use Artdon\Centers\Base\Auth;
use Artdon\Centers\Base\Csrf;
use Artdon\Centers\Base\ErrorCode;
use Artdon\Centers\Base\Nav;
use Artdon\Centers\Base\Password;
use Artdon\Centers\Base\Permission;
use Artdon\Centers\Base\Session;
use Artdon\Centers\Base\Url;
use Artdon\Centers\Base\ValidationException;
use Artdon\Centers\Base\Validator;
use Artdon\Centers\System\ProfileService;

$root = $GLOBALS['centers_test_root'];
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

// ---------- 新系统不引用旧代码 ----------
$offenders = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/centers', FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    $path = str_replace('\\', '/', (string) $file);
    if (substr($path, -4) !== '.php' || strpos($path, '/tools/') !== false) {
        continue;
    }
    $code = (string) file_get_contents($path);
    $rel = substr($path, strlen($root) + 1);
    if (preg_match('#(require|include)(_once)?[^;]*(includes/|\.\./\.\./[a-z_]+\.php)#', $code)) {
        $offenders[] = $rel . '（引用旧文件）';
    }
    if (preg_match('/\b(current_user|has_permission|login_user|logout_user|require_login|verify_csrf|csrf_token|audit_log|login_log|app_setting|api_response)\s*\(/', $code)) {
        $offenders[] = $rel . '（调用旧函数）';
    }
    if (strpos($path, '/tests/') === false && preg_match('/\b(CREATE|ALTER|DROP)\s+TABLE\b/i', $code)) {
        $offenders[] = $rel . '（运行时改表）';
    }
}
check($offenders === [], '新系统代码不引用旧文件、不调用旧函数、不在运行时改表' . ($offenders ? '：' . implode('，', $offenders) : ''));

// ---------- 地址 ----------
$_SERVER['SCRIPT_NAME'] = '/artdon_erp/centers/system/login.php';
check(Url::appBase() === '/artdon_erp', '子目录部署时应用根路径为 /artdon_erp');
check(Url::center('system', 'profile.php') === '/artdon_erp/centers/system/profile.php', '中心内地址拼接正确');
$_SERVER['SCRIPT_NAME'] = '/centers/system/login.php';
check(Url::appBase() === '', '根目录部署时应用根路径为空');
$redirects = [
    ['', 'F'], ['/artdon_erp/centers/system/profile.php', '/artdon_erp/centers/system/profile.php'], ['profile.php?x=1', 'profile.php?x=1'],
    ['https://evil.example/', 'F'], ['//evil.example', 'F'], ['javascript:alert(1)', 'F'], ["/a\\b", 'F'],
    ['/x/login.php?a=1', 'F'], ['/x/logout.php', 'F'], [" /ok\r\n", '/ok'],
];
$bad = [];
foreach ($redirects as [$in, $want]) {
    if (Url::safeRedirect($in, 'F') !== $want) {
        $bad[] = json_encode($in);
    }
}
check($bad === [], '登录后跳转只允许本站地址（' . count($redirects) . ' 种情况）' . ($bad ? '：' . implode(' ', $bad) : ''));

// ---------- 错误码、校验器、CSRF ----------
check(ErrorCode::status(ErrorCode::AUTH_REQUIRED) === 401 && ErrorCode::status(ErrorCode::PERMISSION_DENIED) === 403 && ErrorCode::status(ErrorCode::VALIDATION_FAILED) === 422, '错误码对应正确的 HTTP 状态');
$v = new Validator(['name' => '  张三  ', 'email' => 'bad', 'n' => '12', 'x' => ['a']]);
check($v->text('name', '姓名', ['required' => true]) === '张三', '文本去掉首尾空格');
$v->email('email', '邮箱');
check(($v->errors()['email'] ?? '') === '邮箱格式不正确', '邮箱格式错误有提示');
check($v->id('n', '部门') === 12, '整数字段转换');
$v->text('x', '字段');
check(isset($v->errors()['x']), '数组值被拒绝');
expect_throws(ValidationException::class, static function () use ($v): void {
    $v->check();
}, '有错误时 check() 抛出校验异常');
$token = Csrf::token();
$_POST['csrf_token'] = $token;
check(Csrf::valid(), '正确的 CSRF 令牌通过');
$_POST['csrf_token'] = 'forged';
check(!Csrf::valid(), '伪造的 CSRF 令牌被拒绝');
unset($_POST['csrf_token']);
check(!Csrf::valid(), '没有 CSRF 令牌被拒绝');
check(Password::isStrong('Abcdefg1') && !Password::isStrong('abcdefgh') && !Password::isStrong('12345678'), '密码强度规则：8 位以上，含字母和数字');

// ---------- 登录 ----------
centers_test_reset();
$uid = centers_test_user();
centers_test_user(['username' => 'pend', 'email' => 'p@example.com', 'status' => 'pending']);
centers_test_user(['username' => 'off', 'email' => 'o@example.com', 'status' => 'disabled']);

$r = Auth::attempt('nobody', 'x');
check(!$r['success'] && $r['message'] === '用户名或密码错误', '账号不存在：提示“用户名或密码错误”');
$r = Auth::attempt('pend', 'Artdon2026');
check(!$r['success'] && $r['message'] === '账号正在等待管理员审核', '待审核账号不能登录');
$r = Auth::attempt('off', 'Artdon2026');
check(!$r['success'] && $r['message'] === '账号不可登录，请联系管理员', '停用账号不能登录');
for ($i = 1; $i <= 4; $i++) {
    $r = Auth::attempt('wanglei', 'wrong');
}
check(!$r['success'] && $r['message'] === '用户名或密码错误', '前 4 次密码错误只提示错误');
$r = Auth::attempt('wanglei', 'wrong');
check(!$r['success'] && strpos($r['message'], '锁定 15 分钟') !== false, '第 5 次密码错误锁定 15 分钟');
$r = Auth::attempt('wanglei', 'Artdon2026');
check(!$r['success'] && $r['message'] === '账号已临时锁定，请稍后再试', '锁定期间密码正确也不能登录');
check(Auth::user() === null, '登录失败时没有登录状态');
db()->exec("UPDATE crm_users SET locked_until = '2000-01-01 00:00:00' WHERE id = " . $uid);
$r = Auth::attempt(' wanglei ', 'Artdon2026');
check($r['success'] && (int) Session::get(Session::USER_KEY) === $uid, '锁定过期后可以登录，会话记下用户编号');
$row = db()->query('SELECT failed_login_count, locked_until, last_login_at, last_login_ip FROM crm_users WHERE id = ' . $uid)->fetch();
check((int) $row['failed_login_count'] === 0 && $row['locked_until'] === null && $row['last_login_ip'] === '127.0.0.1' && $row['last_login_at'] !== null, '登录成功后清零失败次数、记录登录时间和 IP');
check(Auth::user() !== null && Auth::user()['role_name'] === '业务员' && Auth::user()['department_name'] === '外贸部', '当前用户含角色名和部门名');
$logs = db()->query("SELECT status, COUNT(*) AS n FROM crm_login_logs GROUP BY status ORDER BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
check((int) ($logs['fail'] ?? 0) === 9 && (int) ($logs['success'] ?? 0) === 1, '每次登录尝试都写登录日志');
$msg = (string) db()->query("SELECT message FROM crm_login_logs ORDER BY id DESC LIMIT 1")->fetchColumn();
check(strpos($msg, '[新系统]') === 0, '新系统的登录日志带“[新系统]”标记，和旧系统区分');
Auth::logout();
check(Auth::user() === null && Session::get(Session::USER_KEY) === null, '退出后没有登录状态');

// ---------- 权限 ----------
centers_test_reset();
$sales = centers_test_user();
$boss = centers_test_user(['username' => 'boss', 'email' => 'b@example.com', 'is_super_admin' => 1, 'role_id' => 1]);
db()->exec("INSERT INTO crm_role_permissions (role_id, permission_key) VALUES (2, 'customer.view'), (2, 'mail.view')");
db()->exec("INSERT INTO crm_user_permissions (user_id, permission_key, effect) VALUES ({$sales}, 'mail.view', 'deny'), ({$sales}, 'visit.view', 'allow')");
$past = date('Y-m-d H:i:s', time() - 3600);
$future = date('Y-m-d H:i:s', time() + 3600);
db()->prepare("INSERT INTO crm_permission_grants (user_id, permission_key, module, action, effect, status, starts_at, expires_at) VALUES (?, 'promotion.view', 'promotion', 'view', 'allow', 'active', ?, ?), (?, 'settings.view', 'settings', 'view', 'allow', 'active', ?, ?), (?, 'mail.view', 'mail', 'view', 'allow', 'active', ?, ?)")
    ->execute([$sales, $past, $future, $sales, date('Y-m-d H:i:s', time() - 7200), $past, $sales, $past, $future]);
$u = Auth::loadUser($sales);
$b = Auth::loadUser($boss);
check(Permission::allows($u, 'customer.view'), '角色有的权限：允许');
check(!Permission::allows($u, 'mail.view'), '个人拒绝优先于角色和临时授权');
check(Permission::allows($u, 'visit.view'), '个人允许：允许');
check(Permission::allows($u, 'promotion.view'), '有效期内的临时授权：允许');
check(!Permission::allows($u, 'settings.view'), '已过期的临时授权：不允许');
check(!Permission::allows($u, 'users.view'), '没有任何授权：不允许');
check(Permission::allows($b, 'anything.at_all'), '超级管理员：全部允许');
$grantStatus = db()->query("SELECT status FROM crm_permission_grants WHERE permission_key = 'settings.view'")->fetchColumn();
check($grantStatus === 'active', '判断权限时不修改授权数据');
check(Permission::scope($u, 'customer') === ['scope_type' => 'self', 'scope_value' => ''] && Permission::scope($b, 'customer')['scope_type'] === 'all', '数据范围：默认只看自己的，超级管理员看全部');

// ---------- 导航 ----------
$groups = Nav::visible('profile', static function (string $permission) use ($u): bool {
    return Permission::allows($u, $permission);
});
$keys = [];
foreach ($groups as $group) {
    foreach ($group['items'] as $item) {
        $keys[$item['key']] = $item;
    }
}
check(isset($keys['customer'], $keys['followup'], $keys['promotion']) && !isset($keys['mail']), '导航只显示有权限的中心');
check(isset($keys['profile']) && $keys['profile']['active'] === true && $keys['profile']['ready'] === true, '个人账号所有人可见并标为当前页');
check(!isset($keys['accounts']) && !isset($keys['settings']), '没有权限时不显示账号管理和设置中心');
check($keys['customer']['ready'] === false && $keys['customer']['href'] === '', '还没做好的模块标为建设中，不能点击');

// ---------- 个人资料 ----------
centers_test_reset();
$uid = centers_test_user();
centers_test_user(['username' => 'lina', 'email' => 'lina@example.com', 'real_name' => '李娜']);
$service = new ProfileService();

$profile = $service->profile($uid);
check($profile !== null && $profile['role_name'] === '业务员' && $profile['department_name'] === '外贸部', '读取资料含角色名和部门名');
check(!array_key_exists('password_hash', (array) $profile), '资料中不包含密码哈希');
check($service->departments() === [1 => '外贸部', 2 => '财务部'], '可选部门只列出启用中的部门');

$after = $service->updateProfile($uid, ['username' => 'wang.lei', 'real_name' => '王磊', 'english_name' => 'Leo', 'email' => '', 'phone' => '+86 20 0000', 'position' => '业务经理', 'department_id' => '2']);
check($after['username'] === 'wang.lei' && $after['department_name'] === '财务部' && $after['position'] === '业务经理', '修改资料成功');
check($after['email'] === null, '邮箱留空时存为 NULL');
$audit = db()->query("SELECT * FROM crm_audit_logs WHERE action = 'user_self_update_profile' ORDER BY id DESC LIMIT 1")->fetch();
check($audit !== false && strpos((string) $audit['after_json'], 'password_hash') === false, '写入操作日志且不含密码哈希');

$e = expect_throws(ValidationException::class, static function () use ($service, $uid): void {
    $service->updateProfile($uid, ['username' => 'lina', 'real_name' => '王磊']);
}, '账号与他人重复时拒绝');
check($e instanceof ValidationException && isset($e->fieldErrors()['username']), '重复账号的错误落在账号字段');

$e = expect_throws(ValidationException::class, static function () use ($service, $uid): void {
    $service->updateProfile($uid, ['username' => 'a b', 'real_name' => '', 'email' => 'lina@example.com', 'department_id' => '3']);
}, '多个字段同时有误时一次性返回');
$fields = $e instanceof ValidationException ? array_keys($e->fieldErrors()) : [];
sort($fields);
check($fields === ['department_id', 'email', 'real_name', 'username'], '账号格式、姓名必填、邮箱重复、停用部门四个错误都返回');

db()->exec('UPDATE crm_users SET department_id = 3 WHERE id = ' . $uid);
$kept = $service->updateProfile($uid, ['username' => 'wang.lei', 'real_name' => '王磊', 'department_id' => '3']);
check((int) $kept['department_id'] === 3, '原部门已停用时允许保持不变');

// ---------- 修改密码 ----------
$e = expect_throws(ValidationException::class, static function () use ($service, $uid): void {
    $service->changePassword($uid, ['old_password' => 'wrong', 'new_password' => 'NewPass2026', 'confirm_password' => 'NewPass2026']);
}, '当前密码错误时拒绝');
check($e instanceof ValidationException && isset($e->fieldErrors()['old_password']), '错误落在当前密码字段');
$e = expect_throws(ValidationException::class, static function () use ($service, $uid): void {
    $service->changePassword($uid, ['old_password' => 'Artdon2026', 'new_password' => 'short', 'confirm_password' => 'short']);
}, '新密码太弱时拒绝');
check($e instanceof ValidationException && ($e->fieldErrors()['new_password'] ?? '') === Password::rule(), '提示密码规则');
expect_throws(ValidationException::class, static function () use ($service, $uid): void {
    $service->changePassword($uid, ['old_password' => 'Artdon2026', 'new_password' => 'Artdon2026', 'confirm_password' => 'Artdon2026']);
}, '新密码与当前密码相同时拒绝');
expect_throws(ValidationException::class, static function () use ($service, $uid): void {
    $service->changePassword($uid, ['old_password' => 'Artdon2026', 'new_password' => 'NewPass2026', 'confirm_password' => 'NewPass2027']);
}, '两次新密码不一致时拒绝');

db()->exec('UPDATE crm_users SET force_password_change = 1 WHERE id = ' . $uid);
$service->changePassword($uid, ['old_password' => 'Artdon2026', 'new_password' => 'NewPass2026', 'confirm_password' => 'NewPass2026']);
$row = db()->query('SELECT password_hash, force_password_change FROM crm_users WHERE id = ' . $uid)->fetch();
check(password_verify('NewPass2026', (string) $row['password_hash']), '密码已更新');
check((int) $row['force_password_change'] === 0, '修改后清除“需要改密码”标记');
check(Auth::attempt('wang.lei', 'NewPass2026')['success'] === true, '新密码可以登录');
Auth::logout();
check(Auth::attempt('wang.lei', 'Artdon2026')['success'] === false, '旧密码不能再登录');

centers_test_finish('底座与系统中心第 1 步');
