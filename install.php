<?php
declare(strict_types=1);
session_start();
date_default_timezone_set('Asia/Shanghai');

function ih($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function install_csrf_token() {
    if (empty($_SESSION['install_csrf'])) $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['install_csrf'];
}
function install_check_csrf() {
    return hash_equals($_SESSION['install_csrf'] ?? '', $_POST['csrf_token'] ?? '');
}
function install_flash($message, $type = 'info') {
    echo '<div class="flash ' . ih($type) . '">' . ih($message) . '</div>';
}
function installed_config_exists() {
    $file = __DIR__ . '/includes/config.php';
    if (!file_exists($file) || !file_exists(__DIR__ . '/includes/install.lock')) return false;
    $config = require $file;
    return !empty($config['installed']);
}
function safe_db_name($name) {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) throw new RuntimeException('数据库名只能包含字母、数字和下划线。');
    return $name;
}
function connect_server($host, $port, $user, $password) {
    return new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}
function connect_db($host, $port, $dbName, $user, $password) {
    return new PDO("mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}
function execute_schema(PDO $pdo) {
    require_once __DIR__ . '/includes/schema.php';
    validate_sql_migration_files();
    execute_sql_migrations($pdo);
    $pdo->prepare("UPDATE crm_system_settings SET setting_value = '0', updated_at = NOW() WHERE setting_key = 'installed'")->execute();
}
function write_config($host, $port, $dbName, $user, $password, $systemName) {
    $config = "<?php\nreturn " . var_export([
        'installed' => true,
        'system_name' => $systemName,
        'db' => [
            'host' => $host,
            'port' => (int)$port,
            'name' => $dbName,
            'user' => $user,
            'password' => $password,
            'charset' => 'utf8mb4',
        ],
    ], true) . ";\n";
    file_put_contents(__DIR__ . '/includes/config.php', $config, LOCK_EX);
    file_put_contents(__DIR__ . '/includes/install.lock', 'installed at ' . date('Y-m-d H:i:s'), LOCK_EX);
}

$message = '';
$error = '';
$step = $_POST['step'] ?? $_GET['step'] ?? 'env';
$defaults = [
    'host' => $_POST['host'] ?? '127.0.0.1',
    'port' => $_POST['port'] ?? '3306',
    'db_name' => $_POST['db_name'] ?? 'artdon_new_erp',
    'db_user' => $_POST['db_user'] ?? '',
    'db_password' => $_POST['db_password'] ?? '',
    'system_name' => $_POST['system_name'] ?? 'Artdon Office V18',
];

if (installed_config_exists()) {
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><title>系统已安装</title><link rel="stylesheet" href="assets/crm-v18.css"></head><body class="install-page"><main class="install-panel"><h1>系统已安装</h1><p>Artdon Office V18 已完成安装，安装器已锁定。</p><p><a class="button" href="login.php">进入登录页</a></p></main></body></html>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!install_check_csrf()) throw new RuntimeException('CSRF 校验失败。');
        $dbName = safe_db_name($defaults['db_name']);
        if ($_POST['action'] === 'test_db') {
            try {
                connect_db($defaults['host'], $defaults['port'], $dbName, $defaults['db_user'], $defaults['db_password']);
                $message = '数据库连接成功。';
            } catch (Throwable $e) {
                $server = connect_server($defaults['host'], $defaults['port'], $defaults['db_user'], $defaults['db_password']);
                $server->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $message = '数据库不存在，已尝试创建并连接成功。';
            }
            $step = 'db';
        } elseif ($_POST['action'] === 'init_db') {
            try {
                $pdo = connect_db($defaults['host'], $defaults['port'], $dbName, $defaults['db_user'], $defaults['db_password']);
            } catch (Throwable $e) {
                $server = connect_server($defaults['host'], $defaults['port'], $defaults['db_user'], $defaults['db_password']);
                $server->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $pdo = connect_db($defaults['host'], $defaults['port'], $dbName, $defaults['db_user'], $defaults['db_password']);
            }
            execute_schema($pdo);
            $_SESSION['install_db'] = $defaults;
            $message = '数据库表和默认权限初始化完成。';
            $step = 'admin';
        } elseif ($_POST['action'] === 'create_admin') {
            $saved = $_SESSION['install_db'] ?? $defaults;
            $dbName = safe_db_name($saved['db_name']);
            $pdo = connect_db($saved['host'], $saved['port'], $dbName, $saved['db_user'], $saved['db_password']);
            $username = trim($_POST['username'] ?? '');
            $realName = trim($_POST['real_name'] ?? '');
            $englishName = trim($_POST['english_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $password = $_POST['password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';
            if ($username === '' || $realName === '' || $email === '') throw new RuntimeException('用户名、姓名、邮箱必填。');
            if ($password !== $confirm) throw new RuntimeException('两次密码不一致。');
            if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) throw new RuntimeException('密码至少 8 位，并包含字母和数字。');
            $roleId = $pdo->query("SELECT id FROM crm_roles WHERE role_key = 'super_admin' LIMIT 1")->fetchColumn();
            $deptId = $pdo->query("SELECT id FROM crm_departments WHERE code = 'general_office' LIMIT 1")->fetchColumn();
            if (!$deptId) $deptId = $pdo->query("SELECT id FROM crm_departments WHERE code = 'headquarters' LIMIT 1")->fetchColumn();
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $existingStmt = $pdo->prepare("SELECT id, username, email FROM crm_users WHERE username = ? OR email = ? ORDER BY id ASC");
            $existingStmt->execute([$username, $email]);
            $existingUsers = $existingStmt->fetchAll();
            $existingIds = array_values(array_unique(array_map(fn($row) => (int)$row['id'], $existingUsers)));
            if (count($existingIds) > 1) {
                throw new RuntimeException('用户名或邮箱已分别被不同用户占用，请更换用户名或邮箱后重试。');
            }

            $pdo->beginTransaction();
            if ($existingIds) {
                $userId = $existingIds[0];
                $stmt = $pdo->prepare("UPDATE crm_users SET username = ?, password_hash = ?, real_name = ?, english_name = ?, email = ?, phone = ?, department_id = ?, role_id = ?, status = 'active', is_super_admin = 1, approved_at = COALESCE(approved_at, NOW()), updated_at = NOW() WHERE id = ?");
                $stmt->execute([$username, $passwordHash, $realName, $englishName, $email, $phone, $deptId ?: null, $roleId ?: null, $userId]);
                $auditAction = 'update_super_admin';
            } else {
                $stmt = $pdo->prepare("INSERT INTO crm_users (username, password_hash, real_name, english_name, email, phone, department_id, role_id, status, is_super_admin, approved_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', 1, NOW(), NOW(), NOW())");
                $stmt->execute([$username, $passwordHash, $realName, $englishName, $email, $phone, $deptId ?: null, $roleId ?: null]);
                $userId = (int)$pdo->lastInsertId();
                $auditAction = 'create_super_admin';
            }

            $audit = $pdo->prepare("INSERT INTO crm_audit_logs (user_id, operator_name, action, module, target_type, target_id, after_json, ip, user_agent, created_at) VALUES (?, ?, ?, 'install', 'user', ?, ?, ?, ?, NOW())");
            $audit->execute([$userId, $username, $auditAction, $userId, json_encode(['username' => $username, 'email' => $email], JSON_UNESCAPED_UNICODE), $_SERVER['REMOTE_ADDR'] ?? '', substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)]);
            $pdo->prepare("UPDATE crm_system_settings SET setting_value = '1', updated_at = NOW() WHERE setting_key = 'installed'")->execute();
            $pdo->commit();
            write_config($saved['host'], $saved['port'], $dbName, $saved['db_user'], $saved['db_password'], $saved['system_name']);
            $step = 'done';
            $message = '安装完成，第一个超级管理员已创建。';
        }
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

$env = [
    'PHP 版本 >= 7.4' => version_compare(PHP_VERSION, '7.4.0', '>='),
    'PDO 扩展' => extension_loaded('pdo_mysql'),
    'mysqli 扩展' => extension_loaded('mysqli'),
    'includes 目录可写' => is_writable(__DIR__ . '/includes'),
    'SQL 文件存在' => file_exists(__DIR__ . '/sql/001_auth_permission_base.sql') && file_exists(__DIR__ . '/sql/002_permission_requests.sql'),
    '尚未安装' => !installed_config_exists(),
];
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>安装 Artdon Office V18</title>
  <link rel="stylesheet" href="assets/crm-v18.css">
</head>
<body class="install-page">
<main class="install-panel">
  <h1>安装 Artdon Office V18</h1>
  <?php if ($message) install_flash($message, 'success'); ?>
  <?php if ($error) install_flash($error, 'error'); ?>
  <section>
    <h2>步骤 1：环境检测</h2>
    <table class="data-table"><tbody>
    <?php foreach ($env as $label => $ok): ?>
      <tr><th><?= ih($label) ?></th><td><?= $ok ? '通过' : '未通过' ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
  </section>

  <?php if ($step !== 'done'): ?>
  <section>
    <h2>步骤 2：数据库配置</h2>
    <form method="post" class="form-grid">
      <input type="hidden" name="csrf_token" value="<?= ih(install_csrf_token()) ?>">
      <input type="hidden" name="action" value="test_db">
      <label>数据库 host<input name="host" value="<?= ih($defaults['host']) ?>"></label>
      <label>数据库端口<input name="port" value="<?= ih($defaults['port']) ?>"></label>
      <label>数据库名<input name="db_name" value="<?= ih($defaults['db_name']) ?>"></label>
      <label>数据库用户名<input name="db_user" value="<?= ih($defaults['db_user']) ?>"></label>
      <label>数据库密码<input type="password" name="db_password" value="<?= ih($defaults['db_password']) ?>"></label>
      <label>系统名称<input name="system_name" value="<?= ih($defaults['system_name']) ?>"></label>
      <div class="form-actions"><button type="submit">测试数据库连接</button></div>
    </form>
  </section>
  <section>
    <h2>步骤 3：一键初始化数据库</h2>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= ih(install_csrf_token()) ?>">
      <input type="hidden" name="action" value="init_db">
      <?php foreach ($defaults as $k => $v): ?><input type="hidden" name="<?= ih($k) ?>" value="<?= ih($v) ?>"><?php endforeach; ?>
      <button type="submit">一键初始化数据库表</button>
      <p class="muted">只执行 CREATE TABLE IF NOT EXISTS 和幂等初始化，不包含 DROP、TRUNCATE、DELETE。</p>
    </form>
  </section>
  <section>
    <h2>步骤 4：创建第一个超级管理员</h2>
    <form method="post" class="form-grid">
      <input type="hidden" name="csrf_token" value="<?= ih(install_csrf_token()) ?>">
      <input type="hidden" name="action" value="create_admin">
      <label>用户名<input name="username" required></label>
      <label>姓名<input name="real_name" required></label>
      <label>英文名<input name="english_name"></label>
      <label>邮箱<input type="email" name="email" required></label>
      <label>手机<input name="phone"></label>
      <label>密码<input type="password" name="password" required></label>
      <label>确认密码<input type="password" name="confirm_password" required></label>
      <div class="form-actions"><button type="submit">创建超级管理员并完成安装</button></div>
    </form>
  </section>
  <?php else: ?>
  <section>
    <h2>步骤 5：完成安装</h2>
    <p>已生成 <code>includes/config.php</code> 和 <code>includes/install.lock</code>。再次打开安装器只显示系统已安装。</p>
    <p><a class="button" href="login.php">进入登录页</a></p>
  </section>
  <?php endif; ?>
</main>
</body>
</html>
