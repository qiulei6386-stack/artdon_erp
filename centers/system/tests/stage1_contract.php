<?php
declare(strict_types=1);

/**
 * 公共底座 + 系统中心第 1 步（登录、个人账号）契约测试。
 * 不连正式库：php centers/system/tests/stage1_contract.php
 */

require_once dirname(__DIR__, 2) . '/_base/tests/harness.php';

use Artdon\Centers\Base\ErrorCode;
use Artdon\Centers\Base\Nav;
use Artdon\Centers\Base\Url;
use Artdon\Centers\Base\ValidationException;
use Artdon\Centers\Base\Validator;
use Artdon\Centers\System\ProfileService;

$root = $GLOBALS['centers_test_root'];

// ---------- 地址 ----------
$_SERVER['SCRIPT_NAME'] = '/artdon_erp/centers/system/login.php';
check(Url::appBase() === '/artdon_erp', '子目录部署时应用根路径为 /artdon_erp');
check(Url::center('system', 'profile.php') === '/artdon_erp/centers/system/profile.php', '中心内地址拼接正确');
check(Url::app('crm.php#customers') === '/artdon_erp/crm.php#customers', '应用内地址拼接正确');
$_SERVER['SCRIPT_NAME'] = '/centers/system/login.php';
check(Url::appBase() === '', '根目录部署时应用根路径为空');
check(Url::app('index.php') === '/index.php', '根目录部署时地址以 / 开头');

// 跳转地址过滤必须与旧系统 auth_safe_redirect() 结果一致
$cases = ['', '/artdon_erp/crm.php#customers', 'crm.php?x=1', 'https://evil.example/', '//evil.example', 'javascript:alert(1)', "/a\\b", '/artdon_erp/login.php?x', '/artdon_erp/centers/system/logout.php', " /x\r\n"];
$same = true;
foreach ($cases as $case) {
    if (Url::safeRedirect($case, 'FALLBACK') !== auth_safe_redirect($case, 'FALLBACK')) {
        $same = false;
        fwrite(STDOUT, '  不一致：' . json_encode($case) . "\n");
    }
}
check($same, '登录后跳转地址的过滤规则与旧系统一致（' . count($cases) . ' 种情况）');
check(Url::safeRedirect('https://evil.example/', '/home') === '/home', '外站地址不允许跳转');

// ---------- 错误码 ----------
check(ErrorCode::status(ErrorCode::AUTH_REQUIRED) === 401, '未登录返回 401');
check(ErrorCode::status(ErrorCode::PERMISSION_DENIED) === 403, '无权限返回 403');
check(ErrorCode::status(ErrorCode::VALIDATION_FAILED) === 422, '校验失败返回 422');

// ---------- 校验器 ----------
$v = new Validator(['name' => '  张三  ', 'email' => 'bad', 'n' => '12', 'x' => ['a']]);
check($v->text('name', '姓名', ['required' => true]) === '张三', '文本去掉首尾空格');
$v->email('email', '邮箱');
check(($v->errors()['email'] ?? '') === '邮箱格式不正确', '邮箱格式错误有提示');
check($v->id('n', '部门') === 12, '整数字段转换');
$v->text('x', '字段');
check(isset($v->errors()['x']), '数组值被拒绝');
$v->text('missing', '电话', ['required' => true]);
check(($v->errors()['missing'] ?? '') === '电话不能为空', '必填字段为空有提示');
expect_throws(ValidationException::class, static function () use ($v): void {
    $v->check();
}, '有错误时 check() 抛出校验异常');

// ---------- 导航 ----------
$groups = Nav::visible('profile', static function (string $permission): bool {
    return in_array($permission, ['customer.view', 'mail.view'], true);
});
$keys = [];
foreach ($groups as $group) {
    foreach ($group['items'] as $item) {
        $keys[$item['key']] = $item;
    }
}
check(isset($keys['customer'], $keys['mail']) && !isset($keys['promotion']), '导航只显示有权限的中心');
check(isset($keys['profile']) && $keys['profile']['active'] === true, '个人账号所有人可见并标为当前页');
check(!isset($keys['accounts']) && !isset($keys['settings']), '没有权限时不显示账号管理和设置中心');
check($keys['customer']['legacy'] === true && $keys['profile']['legacy'] === false, '未重做的模块标为旧版');

// ---------- 个人资料 ----------
centers_test_reset();
$uid = centers_test_user();
$other = centers_test_user(['username' => 'lina', 'email' => 'lina@example.com', 'real_name' => '李娜']);
$service = new ProfileService(db());

$profile = $service->profile($uid);
check($profile !== null && $profile['role_name'] === '业务员' && $profile['department_name'] === '外贸部', '读取资料含角色名和部门名');
check(!array_key_exists('password_hash', (array) $profile), '资料中不包含密码哈希');
check($service->departments() === [1 => '外贸部', 2 => '财务部'], '可选部门只列出启用中的部门');

$after = $service->updateProfile($uid, ['username' => 'wang.lei', 'real_name' => '王磊', 'english_name' => 'Leo', 'email' => '', 'phone' => '+86 20 0000', 'position' => '业务经理', 'department_id' => '2']);
check($after['username'] === 'wang.lei' && $after['department_name'] === '财务部' && $after['position'] === '业务经理', '修改资料成功');
check($after['email'] === null, '邮箱留空时存为 NULL（与旧系统一致）');
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
check($e instanceof ValidationException && ($e->fieldErrors()['new_password'] ?? '') === password_strength_message(), '强度提示与旧系统一致');

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

// 新密码能通过旧系统的 login_user() 登录，说明两边完全通用
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$result = @login_user('wang.lei', 'NewPass2026');
check(!empty($result['success']), '新密码可通过旧系统 login_user() 登录');
$result = @login_user('wang.lei', 'Artdon2026');
check(empty($result['success']), '旧密码不能再登录');

// ---------- 底座规则：新中心不在运行时改表结构 ----------
$offenders = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/centers', FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    $path = str_replace('\\', '/', (string) $file);
    if (substr($path, -4) !== '.php' || strpos($path, '/tests/') !== false || strpos($path, '/migrations/') !== false) {
        continue;
    }
    $code = (string) file_get_contents($path);
    if (preg_match('/\b(CREATE|ALTER|DROP)\s+TABLE\b/i', $code)) {
        $offenders[] = substr($path, strlen($root) + 1);
    }
}
check($offenders === [], '中心代码中没有运行时建表或改表语句' . ($offenders ? '：' . implode(', ', $offenders) : ''));

centers_test_finish('底座与系统中心第 1 步');
