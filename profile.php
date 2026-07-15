<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/user_admin_service.php';
require_login();
ensure_user_profile_schema();
$message = $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        $mode = $_POST['mode'] ?? 'password';
        if ($mode === 'profile') {
            user_self_update_profile($_POST);
            $message = '账号资料已保存。';
        } else {
            user_self_change_password($_POST);
            logout_user();
            redirect('login.php');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
$profile = user_self_profile_row((int)current_user()['id']);
require_once __DIR__ . '/includes/layout.php';
page_header('个人账号', ['ui_type' => 'system', 'page' => 'profile']);
if ($message) flash($message, 'success');
if ($error) flash($error, 'error');
?>
<form method="post" class="narrow-form">
  <?= csrf_field() ?>
  <input type="hidden" name="mode" value="profile">
  <h2>我的账号</h2>
  <label>账号<input name="username" value="<?= h($profile['username'] ?? '') ?>" required autocomplete="username"></label>
  <label>姓名<input name="real_name" value="<?= h($profile['real_name'] ?? '') ?>" required autocomplete="name"></label>
  <label>英文名<input name="english_name" value="<?= h($profile['english_name'] ?? '') ?>"></label>
  <label>邮箱<input type="email" name="email" value="<?= h($profile['email'] ?? '') ?>" autocomplete="email"></label>
  <label>手机 / 电话<input name="phone" value="<?= h($profile['phone'] ?? '') ?>" autocomplete="tel"></label>
  <label>职位<input name="position" value="<?= h($profile['position'] ?? '') ?>" placeholder="可自行填写"></label>
  <label>部门<input name="department_name" value="<?= h($profile['department_name'] ?? '') ?>" placeholder="可自行填写"></label>
  <label>角色<input value="<?= h($profile['role_name'] ?? '未分配角色') ?>" readonly></label>
  <button type="submit">保存账号资料</button>
</form>

<form method="post" class="narrow-form">
  <?= csrf_field() ?>
  <input type="hidden" name="mode" value="password">
  <h2>修改密码</h2>
  <label>旧密码<input type="password" name="old_password" required></label>
  <label>新密码<input type="password" name="new_password" required></label>
  <label>确认新密码<input type="password" name="confirm_password" required></label>
  <button type="submit">保存并重新登录</button>
</form>
<?php page_footer(); ?>
