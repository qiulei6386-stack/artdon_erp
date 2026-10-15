<?php
declare(strict_types=1);

namespace Artdon\Centers\Base;

/** 密码规则。哈希格式用 PHP 标准 password_hash，与 crm_users 里已有的密码通用。 */
final class Password
{
    /** 最短长度来自安全设置（默认 8 位，可在设置中心调到 8-32 位）。 */
    public static function minLength(): int
    {
        return SystemSettings::security()['password_min_length'];
    }

    /** 密码规则说明，显示在输入框下和错误提示里。 */
    public static function rule(): string
    {
        return '密码至少 ' . self::minLength() . ' 位，并包含字母和数字。';
    }

    public static function isStrong(string $password): bool
    {
        return strlen($password) >= self::minLength()
            && preg_match('/[A-Za-z]/', $password) === 1
            && preg_match('/[0-9]/', $password) === 1;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public static function verify(string $password, string $hash): bool
    {
        return $hash !== '' && password_verify($password, $hash);
    }
}
