<?php
declare(strict_types=1);

namespace Artdon\Centers\Base;

/**
 * 登录与权限检查。判断逻辑全部来自旧系统 includes/：
 *   current_user()、has_permission()、is_super_admin()、get_user_scope()、logout_user()
 * 所以新中心和旧系统对同一个人、同一个权限的判断结果完全一致。
 */
final class Auth
{
    /** 当前登录用户（crm_users 一行，含 role_key、role_name、department_name），未登录返回 null。 */
    public static function user(): ?array
    {
        $user = function_exists('current_user') ? current_user() : null;
        return is_array($user) && $user ? $user : null;
    }

    public static function id(): int
    {
        $user = self::user();
        return $user ? (int) $user['id'] : 0;
    }

    /** 显示用的名字：姓名，没有则账号名。 */
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
        return self::user() !== null && has_permission($permission);
    }

    public static function isSuperAdmin(): bool
    {
        return self::user() !== null && (bool) is_super_admin();
    }

    /** 登录页地址，带上登录后要回到的页面。 */
    public static function loginUrl(string $returnTo = ''): string
    {
        $url = Url::center('system', 'login.php');
        return $returnTo !== '' ? $url . '?redirect=' . rawurlencode($returnTo) : $url;
    }

    /**
     * 页面入口：未登录跳登录页；账号已被停用、拒绝或锁定时退出并跳登录页。
     * 返回当前用户。
     */
    public static function requirePage(): array
    {
        $user = self::user();
        if (!$user) {
            Http::redirect(self::loginUrl(Url::current()), 302);
        }
        if (!self::isActive($user)) {
            logout_user();
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
            logout_user();
            Http::fail(ErrorCode::ACCOUNT_INACTIVE, '账号不可用，请联系管理员');
        }
        return $user;
    }

    /** 页面权限：没有权限时显示无权限页。 */
    public static function requirePermissionPage(string $permission): void
    {
        if (!self::can($permission)) {
            Layout::forbidden($permission);
        }
    }

    /** 接口权限：没有权限时返回 403。 */
    public static function requirePermissionApi(string $permission): void
    {
        if (!self::can($permission)) {
            Http::fail(ErrorCode::PERMISSION_DENIED, '没有权限（' . $permission . '）');
        }
    }

    /** 账号状态是否允许使用系统。与 login_user() 拒绝登录的状态一致。 */
    public static function isActive(array $user): bool
    {
        return (string) ($user['status'] ?? '') === 'active';
    }
}
