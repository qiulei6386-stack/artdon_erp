<?php
declare(strict_types=1);

$candidateRoot = dirname(__DIR__);
$appRoot = getenv('CRM_APP_ROOT') ?: $candidateRoot;
require_once $appRoot . '/includes/bootstrap.php';
require_once $candidateRoot . '/crm_settings_config.php';

$pdo = db();
$beforeCountStmt = $pdo->prepare('SELECT COUNT(*) FROM crm_dictionary_items WHERE type_key = ? AND deleted_at IS NULL');
$beforeCountStmt->execute(['country_region']);
$beforeCount = (int)$beforeCountStmt->fetchColumn();

$pdo->beginTransaction();
try {
    crm_sync_country_region_presets();
    $rowsStmt = $pdo->prepare('SELECT item_key, extra_config_json FROM crm_dictionary_items WHERE type_key = ? AND deleted_at IS NULL');
    $rowsStmt->execute(['country_region']);
    $rows = $rowsStmt->fetchAll();
    $byKey = [];
    foreach ($rows as $row) {
        $key = (string)$row['item_key'];
        $extra = json_decode((string)($row['extra_config_json'] ?? '{}'), true) ?: [];
        $byKey[$key] = (string)($extra['phone_code'] ?? '');
    }
    foreach (crm_country_region_defaults() as $item) {
        $key = (string)$item[0];
        if (!isset($byKey[$key]) || !preg_match('/^\+\d{1,4}$/', $byKey[$key])) {
            throw new RuntimeException("country preset was not synchronized correctly: {$key}");
        }
    }
    if (count($byKey) < 249) {
        throw new RuntimeException('country preset integration count is below 249');
    }
    $pdo->rollBack();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

$beforeCountStmt->execute(['country_region']);
$afterRollbackCount = (int)$beforeCountStmt->fetchColumn();
if ($afterRollbackCount !== $beforeCount) {
    throw new RuntimeException('country preset integration rollback did not restore the original row count');
}

echo "CRM country phone presets integration: OK (previewed " . count($byKey) . ", rolled back to {$beforeCount})\n";
