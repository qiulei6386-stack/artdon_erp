<?php
declare(strict_types=1);

$legacyConfigFile = dirname(__DIR__, 2) . '/includes/config.php';
$legacy = is_file($legacyConfigFile) ? require $legacyConfigFile : [];
$db = is_array($legacy['db'] ?? null) ? $legacy['db'] : [];

return [
    'connection_source' => 'legacy includes/config.php',
    'expected_database' => 'artdon_new_erp',
    'actual_database' => (string)($db['name'] ?? ''),
    'read_only_legacy' => true,
    'new_table_prefix' => 'cc_',
    'migration_execution_enabled' => false,
];
