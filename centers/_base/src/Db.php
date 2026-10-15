<?php
declare(strict_types=1);

namespace Artdon\Centers\Base;

use PDO;

/**
 * 新系统的数据库连接。用 centers/config.php 里的连接信息，直接连现有数据库，读写现有表。
 * 整个请求只建立一个连接。
 */
final class Db
{
    /** @var PDO|null */
    private static $pdo = null;

    /** @var array<string, bool> */
    private static $tables = [];

    /** @var array<string, list<string>> */
    private static $columns = [];

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $db = (array) Config::get('db', []);
            if (!empty($db['dsn'])) {
                // 仅用于本地测试（如 sqlite:/path/test.sqlite），正式环境用下面的 MySQL 连接
                self::$pdo = new PDO((string) $db['dsn'], null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                return self::$pdo;
            }
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                (string) ($db['host'] ?? '127.0.0.1'),
                (int) ($db['port'] ?? 3306),
                (string) ($db['name'] ?? ''),
                (string) ($db['charset'] ?? 'utf8mb4')
            );
            self::$pdo = new PDO($dsn, (string) ($db['user'] ?? ''), (string) ($db['password'] ?? ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            self::$pdo->exec("SET time_zone = '" . date('P') . "'");
        }
        return self::$pdo;
    }

    /** 测试时注入连接（如内存 SQLite）。 */
    public static function set(?PDO $pdo): void
    {
        self::$pdo = $pdo;
        self::$tables = [];
        self::$columns = [];
    }

    /** 表是否存在（结果在本次请求内缓存）。 */
    public static function tableExists(string $table): bool
    {
        if (!array_key_exists($table, self::$tables)) {
            $pdo = self::pdo();
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
            } else {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
            }
            $stmt->execute([$table]);
            self::$tables[$table] = (int) $stmt->fetchColumn() > 0;
        }
        return self::$tables[$table];
    }

    /** 表的字段名（表不存在时为空）。只接受字母、数字、下划线组成的表名。 @return list<string> */
    public static function columns(string $table): array
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !self::tableExists($table)) {
            return [];
        }
        if (!array_key_exists($table, self::$columns)) {
            $pdo = self::pdo();
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $names = array_column($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC), 'name');
            } else {
                $stmt = $pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position');
                $stmt->execute([$table]);
                $names = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }
            self::$columns[$table] = array_map('strval', $names);
        }
        return self::$columns[$table];
    }

    /** “插入，已存在则跳过”的写法：MySQL 为 INSERT IGNORE，SQLite（测试）为 INSERT OR IGNORE。 */
    public static function insertIgnore(): string
    {
        return self::pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
    }

    /** 当前时间字符串，统一由 PHP 生成，避免依赖数据库函数。 */
    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
