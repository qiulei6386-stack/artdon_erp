<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Artdon\CommercialCenter\Controllers\DashboardController;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

try {
    $status = (new DashboardController())->status();
    echo json_encode([
        'ok' => $status['database']['ok'],
        'service' => 'commercial_center_v1',
        'database' => [
            'status' => $status['database']['status'],
            'name' => $status['database']['database'],
        ],
        'authentication' => [
            'status' => $status['auth']['status'],
        ],
        'permission' => [
            'status' => $status['permission']['status'],
            'source' => $status['permission']['source'],
        ],
        'adapters' => $status['adapters'],
        'isolation' => $status['isolation'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'service' => 'commercial_center_v1',
        'status' => 'unavailable',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
