<?php
declare(strict_types=1);

namespace Artdon\CommercialCenter\Services;

use Artdon\CommercialCenter\Support\Logger;
use PDO;
use Throwable;

final class DatabaseHealthService
{
    public function check(): array
    {
        try {
            if (!function_exists('db')) {
                return ['ok' => false, 'status' => 'unavailable', 'database' => 'unknown'];
            }
            $pdo = db();
            $row = $pdo->query('SELECT DATABASE() AS database_name, VERSION() AS server_version')->fetch(PDO::FETCH_ASSOC);
            $database = (string)($row['database_name'] ?? '');
            return [
                'ok' => $database === 'artdon_new_erp',
                'status' => $database === 'artdon_new_erp' ? 'connected' : 'unexpected_database',
                'database' => $database,
                'server_version' => (string)($row['server_version'] ?? ''),
                'mode' => 'legacy-read-only-access',
            ];
        } catch (Throwable $error) {
            Logger::error('Database health check failed', [
                'type' => get_class($error),
                'message' => $error->getMessage(),
            ]);
            return ['ok' => false, 'status' => 'unavailable', 'database' => 'unknown'];
        }
    }
}
