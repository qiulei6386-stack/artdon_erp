<?php
declare(strict_types=1);

/**
 * 新系统登录页。账号密码来自 crm_users；登录状态保存在新系统自己的会话里，与旧系统互不影响。
 * 规则见 Artdon\Centers\Base\Auth::attempt()。
 */

require_once __DIR__ . '/bootstrap.php';

use Artdon\Centers\Base\Auth;
use Artdon\Centers\Base\Config;
use Artdon\Centers\Base\ConfigMissingException;
use Artdon\Centers\Base\Form;
use Artdon\Centers\Base\Http;
use Artdon\Centers\Base\Layout;
use Artdon\Centers\Base\Url;

if (!Config::exists()) {
    throw new ConfigMissingException('缺少 centers/config.php');
}

$fallback = Layout::homeUrl();
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
        $result = Auth::attempt($username, $password);
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
<p class="auth-foot">还没有账号？请联系管理员开通。</p>
<?php
Layout::authEnd();
