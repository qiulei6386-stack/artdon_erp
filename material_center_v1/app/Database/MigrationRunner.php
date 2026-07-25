<?php
declare(strict_types=1);
namespace Artdon\MaterialCenter\Database;

use PDO;
use RuntimeException;

final class MigrationRunner
{
    public function __construct(private PDO $db) {}

    public function migrate(): array
    {
        $this->ensureLedger();
        $applied = [];
        foreach (glob(MC_ROOT . '/database/migrations/*.php') ?: [] as $file) {
            $migration = require $file;
            if ($this->isApplied($migration['version'])) continue;
            foreach ($migration['up'] as $sql) $this->db->exec($sql);
            $stmt = $this->db->prepare('INSERT INTO mc_schema_migrations(version,description,applied_at) VALUES(?,?,NOW())');
            $stmt->execute([$migration['version'],$migration['description']]);
            $applied[] = $migration['version'];
        }
        return $applied;
    }

    public function rollback(string $version): void
    {
        $this->ensureLedger();
        $file = $this->find($version);
        $migration = require $file;
        foreach ($migration['down'] as $sql) $this->db->exec($sql);
        $stmt = $this->db->prepare('DELETE FROM mc_schema_migrations WHERE version=?');
        $stmt->execute([$version]);
    }

    private function ensureLedger(): void
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS mc_schema_migrations (version VARCHAR(100) PRIMARY KEY, description VARCHAR(255) NOT NULL, applied_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    private function isApplied(string $version): bool
    {
        $stmt=$this->db->prepare('SELECT 1 FROM mc_schema_migrations WHERE version=?');$stmt->execute([$version]);return (bool)$stmt->fetchColumn();
    }
    private function find(string $version): string
    {
        foreach (glob(MC_ROOT.'/database/migrations/*.php')?:[] as $file) { $m=require $file;if($m['version']===$version)return $file; }
        throw new RuntimeException('Migration not found.');
    }
}
