<?php
function current_user()
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    if (empty($_SESSION['user_id'])) {
        $user = null;
        return null;
    }
    $stmt = db()->prepare('SELECT u.*, r.role_key, r.role_name, d.name AS department_name FROM crm_users u LEFT JOIN crm_roles r ON r.id = u.role_id LEFT JOIN crm_departments d ON d.id = u.department_id WHERE u.id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch() ?: null;
    return $user;
}

function is_logged_in()
{
    return (bool)current_user();
}

function require_login()
{
    if (!is_logged_in()) {
        if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'api.php') {
            api_response(false, '请先登录', [], 'AUTH_REQUIRED');
        }
        redirect('login.php');
    }
}

function login_user($username, $password)
{
    $stmt = db()->prepare('SELECT u.*, r.role_key FROM crm_users u LEFT JOIN crm_roles r ON r.id = u.role_id WHERE u.username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if (!$user) {
        login_log($username, 'fail', '用户不存在');
        return ['success' => false, 'message' => '用户名或密码错误'];
    }
    if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
        login_log($username, 'fail', '账号临时锁定', $user['id']);
        return ['success' => false, 'message' => '账号已临时锁定，请稍后再试'];
    }
    if ($user['status'] === 'pending') {
        login_log($username, 'fail', '待审核账号尝试登录', $user['id']);
        return ['success' => false, 'message' => '账号正在等待管理员审核'];
    }
    if (in_array($user['status'], ['disabled', 'rejected', 'locked'], true)) {
        login_log($username, 'fail', '账号状态不可登录：' . $user['status'], $user['id']);
        return ['success' => false, 'message' => '账号不可登录，请联系管理员'];
    }
    if (!password_verify($password, $user['password_hash'])) {
        $failed = (int)$user['failed_login_count'] + 1;
        $lockedUntil = $failed >= 5 ? date('Y-m-d H:i:s', time() + 900) : null;
        $stmt = db()->prepare('UPDATE crm_users SET failed_login_count = ?, locked_until = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$failed, $lockedUntil, $user['id']]);
        login_log($username, 'fail', '密码错误', $user['id']);
        return ['success' => false, 'message' => $lockedUntil ? '密码错误次数过多，账号已临时锁定 15 分钟' : '用户名或密码错误'];
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $stmt = db()->prepare('UPDATE crm_users SET failed_login_count = 0, locked_until = NULL, last_login_at = NOW(), last_login_ip = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([client_ip(), $user['id']]);
    login_log($username, 'success', '登录成功', $user['id']);
    return ['success' => true, 'message' => '登录成功', 'user' => $user];
}

function logout_user()
{
    $user = current_user();
    if ($user) {
        login_log($user['username'], 'logout', '退出登录', $user['id']);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
