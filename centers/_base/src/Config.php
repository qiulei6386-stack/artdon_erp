<?php
declare(strict_types=1);

namespace Artdon\Centers\Base;

use RuntimeException;

/**
 * 新系统自己的配置，存放在 centers/config.php（不进 Git，格式见 centers/config.example.php）。
 * 不读取旧系统的任何配置文件。
 */
final class Config
{
    /** @var array<string, mixed>|null */
    private static $values = null;

    public static function path(): string
    {
        return ARTDON_CENTERS_ROOT . '/config.php';
    }

    public static function exists(): bool
    {
        return self::$values !== null || is_file(self::path());
    }

    /** 测试时直接注入配置。 @param array<string, mixed> $values */
    public static function set(array $values): void
    {
        self::$values = $values;
    }

    /** @return array<string, mixed> */
    public static function all(): array
    {
        if (self::$values === null) {
            if (!is_file(self::path())) {
                throw new ConfigMissingException('新系统还没有配置数据库连接（缺少 centers/config.php）');
            }
            $values = require self::path();
            if (!is_array($values)) {
                throw new RuntimeException('centers/config.php 格式不正确，应返回一个数组');
            }
            self::$values = $values;
        }
        return self::$values;
    }

    /** 读取配置项，支持点号路径：Config::get('db.host') */
    public static function get(string $key, $default = null)
    {
        $value = self::all();
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }
}
