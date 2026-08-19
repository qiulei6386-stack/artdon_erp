<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Artdon\MaterialCenter\Adapters\LegacyAuthAdapter;
use Artdon\MaterialCenter\Security\PermissionService;
use Artdon\MaterialCenter\Services\LensAngleCompatibilityService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $user = (new LegacyAuthAdapter())->current();
    $permissions = new PermissionService();
    $service = new LensAngleCompatibilityService();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $permissions->require($user, 'material_center.view');
        $data = $service->detail((int) ($_GET['material_id'] ?? 0));
        echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !function_exists('verify_csrf') || !verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
        throw new RuntimeException('安全令牌已过期。', 419);
    }
    $permissions->require($user, 'material_center.material.edit');
    $action = (string) ($_POST['action'] ?? 'save');
    if ($action !== 'save') throw new RuntimeException('不支持的操作。');
    $rows = json_decode((string) ($_POST['rows_json'] ?? '[]'), true);
    if (!is_array($rows)) throw new RuntimeException('适配表数据格式不正确。');
    $data = $service->save((int) ($_POST['material_id'] ?? 0), $rows, $user->id);
    echo json_encode(['ok' => true, 'message' => '芯片角度适配表已保存', 'data' => $data], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code($e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 422);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
