<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';
if (!config_installed()) redirect('install.php');
$message = $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $realName = trim($_POST['real_name'] ?? '');
    $englishName = trim($_POST['english_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $departmentRequest = trim($_POST['department_request'] ?? '');
    $reason = trim($_POST['reason'] ?? '');
    try {
        if ($username === '' || $realName === '' || $email === '') throw new RuntimeException('用户名、姓名、邮箱必填。');
        if ($password !== $confirm) throw new RuntimeException('两次密码不一致。');
        if (!password_is_strong($password)) throw new RuntimeException(password_strength_message());
        $roleId = db()->query("SELECT id FROM crm_roles WHERE role_key = 'pending' LIMIT 1")->fetchColumn();
        $stmt = db()->prepare("INSERT INTO crm_users (username, password_hash, real_name, english_name, email, phone, role_id, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())");
        $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $realName, $englishName, $email, $phone, $roleId ?: null]);
        $userId = db()->lastInsertId();
        audit_log('register_request', 'auth', 'user', $userId, null, ['username' => $username, 'department_request' => $departmentRequest, 'reason' => $reason], ['id' => $userId, 'username' => $username]);
        $message = '注册申请已提交，请等待管理员审核。';
    } catch (Throwable $e) {
        $error = strpos($e->getMessage(), 'Duplicate') !== false ? '用户名或邮箱已存在。' : $e->getMessage();
    }
}
auth_page_header('注册申请', true);
?>
  <h1>注册申请</h1>
  <?php if ($message) flash($message, 'success'); if ($error) flash($error, 'error'); ?>
  <form method="post" class="form-grid">
    <?= csrf_field() ?>
    <label>用户名<input name="username" required></label>
    <label>姓名<input name="real_name" required></label>
    <label>英文名<input name="english_name"></label>
    <label>邮箱<input type="email" name="email" required></label>
    <label>手机<input name="phone"></label>
    <label>申请部门<input name="department_request"></label>
    <label>密码<input type="password" name="password" required></label>
    <label>确认密码<input type="password" name="confirm_password" required></label>
    <label class="full">申请原因<textarea name="reason"></textarea></label>
    <div class="form-actions"><button type="submit">提交申请</button><a class="button secondary" href="login.php">返回登录</a></div>
  </form>
<?php auth_page_footer(); ?>
