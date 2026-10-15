<?php
declare(strict_types=1);

namespace Artdon\Centers\Base;

/**
 * 防跨站请求伪造。每个会话一个令牌：表单里放隐藏字段 csrf_token，接口请求放请求头 X-CSRF-Token。
 */
final class Csrf
{
    private const KEY = 'centers_csrf';

    public static function token(): string
    {
        $token = Session::get(self::KEY);
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            Session::set(self::KEY, $token);
        }
        return $token;
    }

    public static function field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    /** 校验当前请求带的令牌。 */
    public static function valid(): bool
    {
        $sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        $token = Session::get(self::KEY);
        return is_string($sent) && is_string($token) && $token !== '' && hash_equals($token, $sent);
    }
}
