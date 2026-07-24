<?php
declare(strict_types=1);

namespace Artdon\CommercialCenter\Adapters;

use Artdon\CommercialCenter\Contracts\LegacyAdapterContract;
use Artdon\CommercialCenter\Support\Logger;
use PDO;
use Throwable;

abstract class AbstractLegacyReadOnlyAdapter implements LegacyAdapterContract
{
    /** @var list<string> */
    protected array $requiredTables = [];

    final protected function connection(): PDO
    {
        if (!function_exists('db')) {
            throw new \RuntimeException('Legacy database connection is unavailable.');
        }
        return db();
    }

    final protected function selectOne(string $sql, array $parameters = []): ?array
    {
        if (!preg_match('/^\s*SELECT\b/i', $sql)) {
            throw new \LogicException('Legacy adapters only permit SELECT statements.');
        }
        $statement = $this->connection()->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    final protected function tableExists(string $table): bool
    {
        $row = $this->selectOne(
            'SELECT COUNT(*) AS total FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        );
        return (int)($row['total'] ?? 0) === 1;
    }

    public function status(): array
    {
        try {
            $missing = array_values(array_filter(
                $this->requiredTables,
                fn(string $table): bool => !$this->tableExists($table)
            ));
            if ($missing !== []) {
                return $this->result('unavailable', '缺少旧表：' . implode(', ', $missing));
            }
            return $this->result('available', '只读连接可用；未执行任何业务写入。');
        } catch (Throwable $error) {
            Logger::error($this->name() . ' status failed', [
                'type' => get_class($error),
                'message' => $error->getMessage(),
            ]);
            return $this->result('unavailable', '状态检查失败，详情仅记录于新模块日志。');
        }
    }

    final protected function result(string $status, string $detail): array
    {
        return [
            'name' => $this->name(),
            'status' => $status,
            'detail' => $detail,
            'read_only' => true,
        ];
    }
}
