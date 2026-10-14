<?php
declare(strict_types=1);

namespace Artdon\PromotionCenter;

class PmSchema
{
    public static function sqlPath(): string
    {
        return dirname(__DIR__) . '/database/migrations/20261014_001_pm_groups.sql';
    }

    public static function apply(\PDO $pdo): void
    {
        $sql = file_get_contents(self::sqlPath());
        if ($sql === false || trim($sql) === '') {
            throw new PmHttpException('找不到分组表迁移文件', 500, 'SCHEMA');
        }
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $parts = preg_split('/;\s*(?:\R|$)/', $sql) ?: [];
        foreach ($parts as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }
            $pdo->exec($statement);
        }
    }

    public static function ready(\PDO $pdo): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $stmt->execute(['pm_groups']);
        return (int) $stmt->fetchColumn() > 0;
    }
}
