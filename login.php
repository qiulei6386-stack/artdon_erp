<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

if (!config_installed()) {
    redirect('install.php');
}
if (($_GET['action'] ?? '') === 'logout') {
    logout_user();
    redirect('login.php');
}
$loginSettings = app_settings([
    'system_name' => 'Artdon Office V20',
    'portal_subtitle' => '统一进入 CRM、报价、PLM、BOM、派工、财务、权限和系统管理，保障企业内部协作安全有序。',
]);
$redirectTo = auth_safe_redirect($_POST['redirect'] ?? ($_GET['redirect'] ?? ''), 'index.php');
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $result = login_user(trim($_POST['username'] ?? ''), $_POST['password'] ?? '');
    if ($result['success']) {
        redirect($redirectTo);
    }
    $error = $result['message'];
}
auth_page_header('登录', false, 'login-modern');
?>
  <section class="login-modern-brand">
    <div class="login-brand-mark">AD</div>
    <span><?= h($loginSettings['system_name']) ?></span>
    <h1>工厂数字化工作台</h1>
    <p><?= h($loginSettings['portal_subtitle']) ?></p>
    <div class="login-brand-signals"><em>Secure Access</em><em>Office Portal</em><em>Factory Workflow</em></div>
  </section>
  <section class="login-modern-card">
    <div class="login-card-head">
      <span>Welcome Back</span>
      <h2>登录系统</h2>
      <p>使用已审核账号进入 <?= h($loginSettings['system_name']) ?>。</p>
    </div>
    <?php if ($error) flash($error, 'error'); ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="redirect" value="<?= h($redirectTo) ?>">
      <label>用户名<input name="username" required autofocus placeholder="请输入用户名"></label>
      <label>密码<input type="password" name="password" required placeholder="请输入密码"></label>
      <button type="submit">登录</button>
    </form>
    <p class="login-register-link"><a href="register.php">申请账号</a></p>
  </section>
<?php auth_page_footer(); ?>
