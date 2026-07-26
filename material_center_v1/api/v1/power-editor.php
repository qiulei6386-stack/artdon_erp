<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2).'/bootstrap.php';

use Artdon\MaterialCenter\Adapters\LegacyAuthAdapter;
use Artdon\MaterialCenter\Security\PermissionService;
use Artdon\MaterialCenter\Services\PowerEditorService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $user = (new LegacyAuthAdapter())->current();
    $permissions = new PermissionService();
    $service = new PowerEditorService();
    $action = (string)($_GET['action'] ?? $_POST['action'] ?? 'schema');

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $permissions->require($user, 'material_center.view');
        if ($action === 'schema') {
            $data = $service->schema($user);
        } elseif ($action === 'detail') {
            $data = $service->detail((int)($_GET['material_id'] ?? 0), $user);
        } else {
            throw new RuntimeException('不支持的读取操作。', 404);
        }
    } else {
        if (!function_exists('verify_csrf') || !verify_csrf((string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('安全令牌已过期。', 419);
        }
        if ($action === 'save') {
            $permissions->require(
                $user,
                (int)($_POST['material_id'] ?? 0) > 0
                    ? 'material_center.material.edit'
                    : 'material_center.material.create'
            );
            $payload = json_decode((string)($_POST['payload'] ?? '{}'), true);
            $data = $service->save((int)($_POST['material_id'] ?? 0), is_array($payload) ? $payload : [], $user);
        } elseif ($action === 'source_draft') {
            $permissions->require($user, 'material_center.power.confirm');
            $payload = json_decode((string)($_POST['payload'] ?? '{}'), true);
            $data = $service->createFromSource(
                (int)($_POST['source_record_id'] ?? 0),
                is_array($payload) ? $payload : [],
                $user
            );
        } elseif ($action === 'batch_preview' || $action === 'batch_execute') {
            $permissions->require($user, 'material_center.material.batch');
            $ids = json_decode((string)($_POST['ids'] ?? '[]'), true);
            $changes = json_decode((string)($_POST['changes'] ?? '{}'), true);
            $data = $action === 'batch_preview'
                ? $service->batchPreview(is_array($ids) ? $ids : [], is_array($changes) ? $changes : [], (string)($_POST['policy'] ?? ''), $user)
                : $service->batchExecute(is_array($ids) ? $ids : [], is_array($changes) ? $changes : [], (string)($_POST['policy'] ?? ''), $user);
        } elseif ($action === 'rollback') {
            $permissions->require($user, 'material_center.material.batch');
            $data = $service->rollback((string)($_POST['job_uuid'] ?? ''), $user);
        } else {
            throw new RuntimeException('不支持的写入操作。', 404);
        }
    }
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    $code = $e->getCode();
    http_response_code($code >= 400 && $code < 600 ? $code : 422);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
