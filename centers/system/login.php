<?php
declare(strict_types=1);

/**
 * 新登录页。登录逻辑直接调用旧系统 includes/auth.php 的 login_user()：
 * 同一张 crm_users 表、同样的密码校验、失败 5 次锁定 15 分钟、同样写登录日志。
 * 登录后的会话与旧系统通用，旧页面无需再次登录。
 */

require_once __DIR__ . '/bootstrap.php';

use Artdon\Centers\Base\Auth;
use Artdon\Centers\Base\Form;
use Artdon\Centers\Base\Http;
use Artdon\Centers\Base\Layout;
use Artdon\Centers\Base\Url;

if (!config_installed()) {
    Http::redirect(Url::app('install.php'), 302);
}

$fallback = Url::app('index.php');
$redirectTo = Url::safeRedirect((string) ($_POST['redirect'] ?? ($_GET['redirect'] ?? '')), $fallback);

$current = Auth::user();
if ($current && Auth::isActive($current) && !Http::isPost()) {
    Http::redirect($redirectTo, 302);
}

$error = '';
$username = '';
if (Http::isPost()) {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    if (!Http::csrfValid()) {
        $error = '页面停留时间过长，请重新登录';
    } elseif ($username === '' || $password === '') {
        $error = '请输入账号和密码';
    } else {
        $result = login_user($username, $password);
        if (!empty($result['success'])) {
            if ((int) ($result['user']['force_password_change'] ?? 0) === 1) {
                // 个人账号页会根据账号上的标记显示“请修改密码”的提醒
                Http::redirect(Url::center('system', 'profile.php') . '?force=1');
            }
            Http::redirect($redirectTo);
        }
        $error = (string) ($result['message'] ?? '登录失败');
    }
}

Layout::authStart('登录');
if (isset($_GET['logged_out'])) {
    Layout::alert('info', '你已退出登录。');
}
if (isset($_GET['changed'])) {
    Layout::alert('success', '密码已修改，请用新密码登录。');
}
if (isset($_GET['inactive'])) {
    Layout::alert('warning', '账号已停用或正在审核，请联系管理员。');
}
if ($error !== '') {
    Layout::alert('error', $error);
}
?>
<div>
  <h1 class="auth-title">登录</h1>
  <p class="auth-sub">使用公司账号登录，可进入已授权的各个系统。</p>
</div>
<form method="post" class="stack" action="<?= Layout::e(Url::center('system', 'login.php')) ?>">
  <?= Layout::csrfField() ?>
  <input type="hidden" name="redirect" value="<?= Layout::e($redirectTo) ?>">
  <?= Form::input('username', '账号', $username, [], ['required' => true, 'autocomplete' => 'username', 'autofocus' => $username === '', 'maxlength' => 80]) ?>
  <?= Form::input('password', '密码', '', [], ['type' => 'password', 'required' => true, 'autocomplete' => 'current-password', 'autofocus' => $username !== '']) ?>
  <button type="submit" class="btn btn-primary btn-block">登录</button>
</form>
<p class="auth-foot">还没有账号？<a href="<?= Layout::e(Url::app('register.php')) ?>">申请账号</a></p>
<?php
Layout::authEnd();
