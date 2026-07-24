<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (($argv[1] ?? '') !== '--apply') {
    fwrite(STDERR, "Usage: php database/apply.php --apply\n");
    exit(2);
}

$root = dirname(__DIR__, 2);
$configFile = $root . '/includes/config.php';
$migrationName = (string)($argv[2] ?? '001_foundation.sql');
$allowedMigrations = [
    '001_foundation.sql' => ['cc_schema_migrations','cc_entity_links','cc_integration_logs','cc_activity_logs'],
    '002_unified_orders.sql' => ['cc_external_orders','cc_orders','cc_order_items','cc_order_status_history','cc_external_order_events'],
];
if (!isset($allowedMigrations[$migrationName])) {
    fwrite(STDERR, "Refusing migration: file is not approved.\n");
    exit(1);
}
$migrationFile = __DIR__ . '/migrations/' . $migrationName;

if (!is_file($configFile) || !is_file($migrationFile)) {
    fwrite(STDERR, "Required configuration or migration file is unavailable.\n");
    exit(1);
}

$legacy = require $configFile;
$db = is_array($legacy['db'] ?? null) ? $legacy['db'] : [];
$databaseName = (string)($db['name'] ?? '');

if ($databaseName !== 'artdon_new_erp') {
    fwrite(STDERR, "Refusing migration: unexpected target database.\n");
    exit(1);
}

$sql = (string)file_get_contents($migrationFile);
$withoutComments = preg_replace('/^\s*--.*$/m', '', $sql) ?? '';
$statements = array_values(array_filter(array_map('trim', explode(';', $withoutComments))));

if ($statements === []) {
    fwrite(STDERR, "No migration statements found.\n");
    exit(1);
}

$expectedTables = $allowedMigrations[$migrationName];

foreach ($statements as $statement) {
    if (!preg_match('/^CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+(cc_[a-z0-9_]+)\s*\(/i', $statement, $match)) {
        fwrite(STDERR, "Refusing migration: a statement is outside the CREATE cc_* boundary.\n");
        exit(1);
    }
    if (!in_array(strtolower($match[1]), $expectedTables, true)) {
        fwrite(STDERR, "Refusing migration: an unexpected cc_* table was requested.\n");
        exit(1);
    }
}

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    (string)($db['host'] ?? '127.0.0.1'),
    (int)($db['port'] ?? 3306),
    $databaseName
);

try {
    $pdo = new PDO(
        $dsn,
        (string)($db['user'] ?? ''),
        (string)($db['password'] ?? $db['pass'] ?? ''),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $actualDatabase = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($actualDatabase !== $databaseName) {
        throw new RuntimeException('Connected database does not match the approved target.');
    }

    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }

    $placeholders = implode(',', array_fill(0, count($expectedTables), '?'));
    $check = $pdo->prepare(
        "SELECT TABLE_NAME
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ({$placeholders})
         ORDER BY TABLE_NAME"
    );
    $check->execute(array_merge([$databaseName], $expectedTables));
    $created = $check->fetchAll(PDO::FETCH_COLUMN);

    if (count($created) !== count($expectedTables)) {
        throw new RuntimeException('Not all approved tables are present after migration.');
    }

    echo "Migration applied to {$databaseName}.\n";
    echo "Verified tables:\n- " . implode("\n- ", $created) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, "Migration failed: {$error->getMessage()}\n");
    exit(1);
}
