<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Artdon\MaterialCenter\Adapters\LegacyAuthAdapter;
use Artdon\MaterialCenter\Security\PermissionService;
use Artdon\MaterialCenter\Services\SourceMaterialOrganizerService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $user = (new LegacyAuthAdapter())->current();
    $permissions = new PermissionService();
    $service = new SourceMaterialOrganizerService();
    $sourceRecordId = (int) ($_GET['source_record_id'] ?? $_POST['source_record_id'] ?? 0);
    $category = trim((string) ($_GET['category'] ?? $_POST['category'] ?? ''));

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $permissions->require($user, 'material_center.view');
        $data = $service->detail($sourceRecordId, $category, $user);
        $message = '来源资料读取完成';
    } else {
        if (!function_exists('verify_csrf') || !verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('安全令牌已过期。', 419);
        }
        $mode = (string) ($_POST['mode'] ?? 'draft');
        $mappedMaterialId = $service->mappedMaterialId($sourceRecordId, $category);
        $permissions->require(
            $user,
            $mappedMaterialId > 0 ? 'material_center.material.edit' : 'material_center.material.create'
        );
        if ($mode === 'approve') {
            $permissions->require($user, 'material_center.approve');
        } elseif ($mode === 'submit') {
            $permissions->require($user, 'material_center.material.lifecycle');
        }
        $payload = json_decode((string) ($_POST['payload'] ?? '{}'), true);
        $data = $service->save(
            $sourceRecordId,
            $category,
            is_array($payload) ? $payload : [],
            $mode,
            $user
        );
        $message = $mode === 'approve' ? '物料已确认并转正式' : ($mode === 'submit' ? '草稿已提交确认' : '来源物料草稿已保存');
    }

    echo json_encode(['ok' => true, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    $code = $e->getCode();
    http_response_code($code >= 400 && $code < 600 ? $code : 422);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
