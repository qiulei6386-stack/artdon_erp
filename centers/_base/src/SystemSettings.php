<?php
declare(strict_types=1);

namespace Artdon\Centers\Base;

use PDO;
use Throwable;

/**
 * 读取系统设置（crm_system_settings 表）。本次请求内缓存。
 *
 * 公司信息沿用表里已有的键（system_name、company_name 等），数据直接接得上；
 * 新系统自己的设置用 centers. 开头的键，例如安全设置 centers.security（JSON）、Logo centers.logo。
 * 读不到时用默认值（安全设置的默认值来自 centers/config.php 的 app 段）。
 */
final class SystemSettings
{
    /** @var array<string, string>|null */
    private static $cache = null;

    public static function get(string $key, string $default = ''): string
    {
        $all = self::all();
        $value = $all[$key] ?? '';
        return $value === '' ? $default : $value;
    }

    /** @return array<string, string> */
    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            try {
                if (Config::exists() && Db::tableExists('crm_system_settings')) {
                    foreach (Db::pdo()->query('SELECT setting_key, setting_value FROM crm_system_settings')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        self::$cache[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
                    }
                }
            } catch (Throwable $e) {
                self::$cache = [];
            }
        }
        return self::$cache;
    }

    public static function clearCache(): void
    {
        self::$cache = null;
    }

    /**
     * 安全设置：登录失败锁定次数、锁定分钟、密码最短长度、闲置自动退出分钟（0 表示不自动退出）。
     *
     * @return array{login_max_failures: int, login_lock_minutes: int, password_min_length: int, session_idle_minutes: int}
     */
    public static function security(): array
    {
        $defaults = [
            'login_max_failures' => (int) Config::get('app.login_max_failures', 5),
            'login_lock_minutes' => (int) Config::get('app.login_lock_minutes', 15),
            'password_min_length' => 8,
            'session_idle_minutes' => 0,
        ];
        $saved = json_decode(self::get('centers.security', '{}'), true);
        $saved = is_array($saved) ? $saved : [];
        $out = [];
        foreach ($defaults as $key => $value) {
            $out[$key] = isset($saved[$key]) ? (int) $saved[$key] : $value;
        }
        $out['login_max_failures'] = max(1, min(20, $out['login_max_failures']));
        $out['login_lock_minutes'] = max(1, min(1440, $out['login_lock_minutes']));
        $out['password_min_length'] = max(8, min(32, $out['password_min_length']));
        $out['session_idle_minutes'] = $out['session_idle_minutes'] <= 0 ? 0 : max(10, min(1440, $out['session_idle_minutes']));
        return $out;
    }

    /** 系统显示名称。 */
    public static function systemName(): string
    {
        $name = trim(self::get('system_name'));
        return $name !== '' ? $name : 'Artdon 业务系统';
    }

    /** Logo（data:image/... 格式，存在设置里），没有时返回空字符串。 */
    public static function logo(): string
    {
        $logo = self::get('centers.logo');
        return preg_match('#^data:image/(png|jpeg|webp);base64,[A-Za-z0-9+/=]+$#', $logo) ? $logo : '';
    }
}
