<?php
declare(strict_types=1);

namespace Artdon\Centers\Base;

/**
 * 新系统的登录与权限入口。账号数据来自 crm_users，登录状态保存在新系统自己的会话里。
 *
 * 登录规则：
 *   - 账号不存在或密码错误：提示“用户名或密码错误”，失败次数加 1；
 *   - 连续失败达到上限（默认 5 次）：锁定一段时间（默认 15 分钟）；
 *   - 状态为 pending 不能登录（等待审核），disabled / rejected / locked 不能登录；
 *   - 成功：清零失败次数，记录最近登录时间和 IP，写登录日志。
 */
final class Auth
{
    /** @var array<string, mixed>|null|false false 表示还没读取 */
    private static $user = false;

    /** 当前登录用户（crm_users 一行，含 role_key、role_name、department_name）。未登录返回 null。 */
    public static function user(): ?array
    {
        if (self::$user === false) {
            $id = (int) Session::get(Session::USER_KEY, 0);
            self::$user = $id > 0 ? self::loadUser($id) : null;
        }
        return self::$user ?: null;
    }

    public static function id(): int
    {
        $user = self::user();
        return $user ? (int) $user['id'] : 0;
    }

    /** 重新从数据库读取当前用户（资料修改后调用）。 */
    public static function refresh(): void
    {
        self::$user = false;
        Permission::clearCache();
    }

    public static function loadUser(int $id): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT u.*, r.role_key, r.role_name, d.name AS department_name'
            . ' FROM crm_users u'
            . ' LEFT JOIN crm_roles r ON r.id = u.role_id'
            . ' LEFT JOIN crm_departments d ON d.id = u.department_id'
            . ' WHERE u.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * 尝试登录。
     *
     * @return array{success: bool, message: string, user?: array<string, mixed>}
     */
    public static function attempt(string $username, string $password): array
    {
        $username = trim($username);
        $pdo = Db::pdo();
        $stmt = $pdo->prepare('SELECT * FROM crm_users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if (!$user) {
            Audit::login($username, 'fail', '用户不存在');
            return ['success' => false, 'message' => '用户名或密码错误'];
        }
        $userId = (int) $user['id'];
        if (!empty($user['locked_until']) && strtotime((string) $user['locked_until']) > time()) {
            Audit::login($username, 'fail', '账号临时锁定', $userId);
            return ['success' => false, 'message' => '账号已临时锁定，请稍后再试'];
        }
        $status = (string) $user['status'];
        if ($status === 'pending') {
            Audit::login($username, 'fail', '待审核账号尝试登录', $userId);
            return ['success' => false, 'message' => '账号正在等待管理员审核'];
        }
        if ($status !== 'active') {
            Audit::login($username, 'fail', '账号状态不可登录：' . $status, $userId);
            return ['success' => false, 'message' => '账号不可登录，请联系管理员'];
        }
        if (!Password::verify($password, (string) $user['password_hash'])) {
            $failed = (int) $user['failed_login_count'] + 1;
            $max = max(1, (int) Config::get('app.login_max_failures', 5));
            $minutes = max(1, (int) Config::get('app.login_lock_minutes', 15));
            $lockedUntil = $failed >= $max ? date('Y-m-d H:i:s', time() + $minutes * 60) : null;
            $pdo->prepare('UPDATE crm_users SET failed_login_count = ?, locked_until = ?, updated_at = ? WHERE id = ?')
                ->execute([$failed, $lockedUntil, Db::now(), $userId]);
            Audit::login($username, 'fail', '密码错误', $userId);
            return ['success' => false, 'message' => $lockedUntil ? '密码错误次数过多，账号已临时锁定 ' . $minutes . ' 分钟' : '用户名或密码错误'];
        }

        Session::regenerate();
        Session::set(Session::USER_KEY, $userId);
        $pdo->prepare('UPDATE crm_users SET failed_login_count = 0, locked_until = NULL, last_login_at = ?, last_login_ip = ?, updated_at = ? WHERE id = ?')
            ->execute([Db::now(), Audit::ip(), Db::now(), $userId]);
        Audit::login($username, 'success', '登录成功', $userId);
        self::refresh();
        return ['success' => true, 'message' => '登录成功', 'user' => self::user() ?? $user];
    }

    public static function logout(): void
    {
        $user = self::user();
        if ($user) {
            Audit::login((string) $user['username'], 'logout', '退出登录', (int) $user['id']);
        }
        Session::destroy();
        self::$user = null;
        Permission::clearCache();
    }

    public static function displayName(?array $user = null): string
    {
        $user = $user ?? self::user();
        if (!$user) {
            return '';
        }
        $name = trim((string) ($user['real_name'] ?? ''));
        return $name !== '' ? $name : (string) ($user['username'] ?? '');
    }

    public static function can(string $permission): bool
    {
        $user = self::user();
        return $user !== null && Permission::allows($user, $permission);
    }

    public static function isSuperAdmin(): bool
    {
        $user = self::user();
        return $user !== null && (int) ($user['is_super_admin'] ?? 0) === 1;
    }

    public static function loginUrl(string $returnTo = ''): string
    {
        $url = Url::center('system', 'login.php');
        return $returnTo !== '' ? $url . '?redirect=' . rawurlencode($returnTo) : $url;
    }

    /** 页面入口：未登录跳登录页；账号已不可用时退出并跳登录页。返回当前用户。 */
    public static function requirePage(): array
    {
        $user = self::user();
        if (!$user) {
            Http::redirect(self::loginUrl(Url::current()), 302);
        }
        if (!self::isActive($user)) {
            self::logout();
            Http::redirect(Url::center('system', 'login.php') . '?inactive=1', 302);
        }
        return $user;
    }

    /** 接口入口：未登录或账号不可用时返回 JSON 错误。 */
    public static function requireApi(): array
    {
        $user = self::user();
        if (!$user) {
            Http::fail(ErrorCode::AUTH_REQUIRED, '请先登录');
        }
        if (!self::isActive($user)) {
            self::logout();
            Http::fail(ErrorCode::ACCOUNT_INACTIVE, '账号不可用，请联系管理员');
        }
        return $user;
    }

    public static function requirePermissionPage(string $permission): void
    {
        if (!self::can($permission)) {
            Layout::forbidden($permission);
        }
    }

    public static function requirePermissionApi(string $permission): void
    {
        if (!self::can($permission)) {
            Http::fail(ErrorCode::PERMISSION_DENIED, '没有权限（' . $permission . '）');
        }
    }

    public static function isActive(array $user): bool
    {
        return (string) ($user['status'] ?? '') === 'active';
    }
}
