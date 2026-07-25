<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

$user = mc_current_user();
$tableReady = mc_table_exists('bom_materials');

echo json_encode([
    'ok' => $tableReady,
    'service' => 'material_center_v1',
    'mode' => 'legacy-read-only',
    'authentication' => $user ? 'authenticated' : 'unauthenticated',
    'source_table' => $tableReady ? 'available' : 'missing',
    'write_enabled' => false,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
