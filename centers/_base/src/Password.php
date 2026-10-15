<?php
declare(strict_types=1);

namespace Artdon\Centers\Base;

/** 密码规则。哈希格式用 PHP 标准 password_hash，与 crm_users 里已有的密码通用。 */
final class Password
{
    public const RULE = '密码至少 8 位，并包含字母和数字。';

    public static function isStrong(string $password): bool
    {
        return strlen($password) >= 8
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
