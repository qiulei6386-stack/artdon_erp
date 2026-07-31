<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/migration_runner.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$runner = new Pa2MigrationRunner(db());
$command = $argv[1] ?? 'status';

if ($command === 'up') {
    echo json_encode(['applied' => $runner->migrate()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit;
}

if ($command === 'down' && !empty($argv[2])) {
    $runner->rollback((string)$argv[2]);
    echo "rolled back {$argv[2]}\n";
    exit;
}

$runner->migrate();
echo json_encode($runner->status(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
