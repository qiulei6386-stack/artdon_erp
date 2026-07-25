<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
use Artdon\MaterialCenter\Database\MigrationRunner;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$runner = new MigrationRunner(db());
$command = $argv[1] ?? 'status';
if ($command === 'up') { echo json_encode(['applied'=>$runner->migrate()],JSON_UNESCAPED_UNICODE),"\n"; exit; }
if ($command === 'down' && !empty($argv[2])) { $runner->rollback($argv[2]); echo "rolled back {$argv[2]}\n"; exit; }
$runner->migrate();
$rows=db()->query('SELECT version,description,applied_at FROM mc_schema_migrations ORDER BY applied_at')->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";
