<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'POST') {
    require_csrf();
    $raw = file_get_contents('php://input');
    $json = json_decode($raw === false ? '' : $raw, true);
    $input = is_array($json) ? $json : $_POST;
    if (!is_array($input)) {
        $input = [];
    }
} else {
    $input = $_GET;
}
$action = (string) ($input['action'] ?? '');
$handler = new Artdon\PromotionCenter\ApiHandler(db(), new Artdon\PromotionCenter\LegacyPermissionAdapter());
try {
    $result = $handler->handle($action, $input, $method);
} catch (Throwable $e) {
    error_log('promotion_center_v1 api: ' . $e->getMessage());
    http_response_code(500);
    api_response(false, '推广中心暂时无法完成这个操作', [], 'SERVER');
}
http_response_code((int) $result['status']);
api_response((bool) $result['ok'], (string) $result['message'], $result['data'], (string) $result['error_code']);
