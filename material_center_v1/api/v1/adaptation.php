<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2).'/bootstrap.php';

use Artdon\MaterialCenter\Adapters\LegacyAuthAdapter;
use Artdon\MaterialCenter\Security\PermissionService;
use Artdon\MaterialCenter\Services\AdaptationService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$json = static function (string $key, array $fallback = []): array {
    $value = json_decode((string) ($_POST[$key] ?? ''), true);
    return is_array($value) ? $value : $fallback;
};

try {
    $user = (new LegacyAuthAdapter())->current();
    $permission = new PermissionService();
    $permission->require($user, 'material_center.view');
    $service = new AdaptationService();
    $action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $data = match ($action) {
            'approved' => $service->approved((int) ($_GET['legacy_product_id'] ?? 0)),
            'products' => $service->products(trim((string) ($_GET['q'] ?? ''))),
            'workspace' => $service->workspace((int) ($_GET['product_id'] ?? 0), (int) ($_GET['group_id'] ?? 0)),
            'candidates' => $service->candidateMaterials((int) ($_GET['group_id'] ?? 0), $_GET),
            'metadata' => $service->metadata(),
            default => throw new RuntimeException('读取操作无效。', 400),
        };
        echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $permission->require($user, 'material_center.adaptation.manage');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
        throw new RuntimeException('安全令牌已过期。', 419);
    }

    $data = match ($action) {
        'sync' => $service->syncProducts($user->id),
        'initialize_groups', 'apply_template' => $service->initializeGroups((int) ($_POST['product_id'] ?? 0), $user->id),
        'save_group' => ['id' => $service->saveGroup($_POST, $user->id)],
        'delete_group' => (static function () use ($service, $user): array {
            $service->deleteGroup((int) ($_POST['group_id'] ?? 0), $user->id);
            return [];
        })(),
        'reorder_groups' => (static function () use ($service, $user, $json): array {
            $service->reorderGroups((int) ($_POST['product_id'] ?? 0), $json('group_ids'), $user->id);
            return [];
        })(),
        'save_option' => ['id' => $service->saveOption($_POST, $user->id)],
        'add_options' => $service->addOptions((int) ($_POST['group_id'] ?? 0), $json('material_ids'), $user->id),
        'set_default' => (static function () use ($service, $user, $json): array {
            $service->setDefault(
                (int) ($_POST['group_id'] ?? 0),
                $json('option_ids'),
                (int) ($_POST['min_select'] ?? 0),
                (int) ($_POST['max_select'] ?? 1),
                $user->id
            );
            return [];
        })(),
        'save_conditions' => $service->saveConditions((int) ($_POST['group_id'] ?? 0), $json('conditions'), $user->id),
        'save_conflict' => ['id' => $service->saveConflict($_POST, $user->id)],
        'evaluate' => $service->evaluate(
            (int) ($_POST['product_id'] ?? 0),
            $json('option_ids'),
            $json('context_json')
        ),
        'approve' => (static function () use ($service, $user): array {
            (new PermissionService())->require($user, 'material_center.approve');
            $service->approveProduct((int) ($_POST['product_id'] ?? 0), $user->id, !empty($_POST['approve_exceptions']));
            return [];
        })(),
        default => throw new RuntimeException('操作无效。', 400),
    };
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 422;
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
