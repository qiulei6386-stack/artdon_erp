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
    '003_catalog_configuration.sql' => ['cc_products_extension','cc_materials','cc_config_groups','cc_config_options','cc_compatibility_rules'],
    '004_inventory_publishing.sql' => ['cc_inventory_skus','cc_stock_reservations','cc_channels','cc_channel_packages','cc_publication_jobs'],
    '005_standard_quotes.sql' => ['cc_quotes','cc_quote_versions','cc_quote_items','cc_quote_item_snapshots'],
    '006_configuration_engine.sql' => ['cc_config_templates','cc_config_template_versions','cc_config_group_settings','cc_config_template_groups','cc_product_config_templates','cc_product_allowed_options','cc_config_presets','cc_config_preset_values','cc_config_lock_rules','cc_option_material_mappings','cc_configuration_instances','cc_configuration_snapshots'],
    '007_commercial_foundation.sql' => ['cc_commercial_tasks','cc_quotation_logs','cc_commercial_settings','cc_commercial_permissions','cc_approval_flows'],
    '008_permission_center.sql' => ['cc_roles','cc_permissions','cc_role_permissions','cc_user_roles','cc_field_permissions','cc_data_permissions','cc_system_logs'],
    '009_product_sync.sql' => ['cc_commercial_products','cc_product_sync_logs','cc_product_options'],
    '010_unified_quote_model.sql' => ['cc_quote_details','cc_quote_item_details','cc_quote_files','cc_quote_item_files','cc_quote_snapshots','cc_quote_legacy_links'],
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

    $checksum = hash_file('sha256', $migrationFile);
    $existingMigration = $pdo->prepare(
        'SELECT checksum FROM cc_schema_migrations WHERE migration_name=? LIMIT 1'
    );
    $existingMigration->execute([$migrationName]);
    $existingChecksum = $existingMigration->fetchColumn();
    if (is_string($existingChecksum) && $existingChecksum !== '' && !hash_equals($existingChecksum, $checksum)) {
        throw new RuntimeException('Recorded migration checksum does not match the requested file.');
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

    $record = $pdo->prepare(
        "INSERT INTO cc_schema_migrations
            (migration_name,checksum,execution_status,applied_by_legacy_user_id,applied_at,created_at)
         VALUES (?,?, 'applied', NULL, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            execution_status='applied',applied_at=NOW()"
    );
    $record->execute([$migrationName, $checksum]);

    echo "Migration applied to {$databaseName}.\n";
    echo "Verified tables:\n- " . implode("\n- ", $created) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, "Migration failed: {$error->getMessage()}\n");
    exit(1);
}
