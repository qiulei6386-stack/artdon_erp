<?php
declare(strict_types=1);

final class Pa2MigrationRunner
{
    public function __construct(private PDO $db) {}

    public function migrate(): array
    {
        $this->ensureLedger();
        $applied = [];
        $files = glob(dirname(__DIR__) . '/database/migrations/*.php') ?: [];
        sort($files);
        foreach ($files as $file) {
            $migration = require $file;
            $version = (string)($migration['version'] ?? '');
            if ($version === '' || $this->isApplied($version)) continue;
            foreach (($migration['up'] ?? []) as $sql) {
                $this->db->exec($sql);
            }
            $stmt = $this->db->prepare('INSERT INTO mc_pa2_schema_migrations(version,description,applied_at) VALUES(?,?,NOW())');
            $stmt->execute([$version, (string)($migration['description'] ?? '')]);
            $applied[] = $version;
        }
        return $applied;
    }

    public function status(): array
    {
        $this->ensureLedger();
        return $this->db->query('SELECT version,description,applied_at FROM mc_pa2_schema_migrations ORDER BY applied_at,version')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function rollback(string $version): void
    {
        $this->ensureLedger();
        $file = $this->find($version);
        $migration = require $file;
        foreach (array_reverse($migration['down'] ?? []) as $sql) {
            $this->db->exec($sql);
        }
        $stmt = $this->db->prepare('DELETE FROM mc_pa2_schema_migrations WHERE version=?');
        $stmt->execute([$version]);
    }

    private function ensureLedger(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS mc_pa2_schema_migrations (
                version VARCHAR(100) PRIMARY KEY,
                description VARCHAR(255) NOT NULL,
                applied_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    private function isApplied(string $version): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM mc_pa2_schema_migrations WHERE version=? LIMIT 1');
        $stmt->execute([$version]);
        return (bool)$stmt->fetchColumn();
    }

    private function find(string $version): string
    {
        foreach (glob(dirname(__DIR__) . '/database/migrations/*.php') ?: [] as $file) {
            $migration = require $file;
            if ((string)($migration['version'] ?? '') === $version) return $file;
        }
        throw new RuntimeException('V2 migration not found: ' . $version);
    }
}
