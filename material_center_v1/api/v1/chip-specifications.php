<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2).'/bootstrap.php';

use Artdon\MaterialCenter\Adapters\LegacyAuthAdapter;
use Artdon\MaterialCenter\Security\PermissionService;
use Artdon\MaterialCenter\Services\ChipSpecificationService;

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
    $service = new ChipSpecificationService();
    $action = (string) ($_GET['action'] ?? $_POST['action'] ?? 'catalog');

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $data = match ($action) {
            'catalog' => $service->catalog(),
            'material' => $service->material((int) ($_GET['material_id'] ?? 0)),
            default => throw new RuntimeException('读取操作无效。', 400),
        };
        echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
        throw new RuntimeException('安全令牌已过期。', 419);
    }
    $permission->require($user, 'material_center.material.edit');
    $data = match ($action) {
        'save_template' => $service->saveTemplate([
            'template_id' => (int) ($_POST['template_id'] ?? 0),
            'template_name' => (string) ($_POST['template_name'] ?? ''),
            'description' => (string) ($_POST['description'] ?? ''),
            'change_note' => (string) ($_POST['change_note'] ?? ''),
            'is_system_default' => !empty($_POST['is_system_default']),
            'selection' => $json('selection'),
            'combinations' => $json('combinations'),
        ], $user->id),
        'preview_apply' => $service->previewApply(
            $json('template_ids'),
            $json('material_ids'),
            (string) ($_POST['mode'] ?? 'fill_missing')
        ),
        'apply_templates' => $service->applyTemplates(
            $json('template_ids'),
            $json('material_ids'),
            (string) ($_POST['mode'] ?? 'fill_missing'),
            $user->id
        ),
        'add_manual_variants' => $service->addManualVariants(
            (int) ($_POST['material_id'] ?? 0),
            $json('combinations'),
            $user->id
        ),
        'save_material_settings' => $service->saveMaterialSettings(
            (int) ($_POST['material_id'] ?? 0),
            [
                'active_variant_ids' => $json('active_variant_ids'),
                'default_variant_id' => (int) ($_POST['default_variant_id'] ?? 0),
                'confirm_variant_ids' => $json('confirm_variant_ids'),
            ],
            $user->id
        ),
        default => throw new RuntimeException('操作无效。', 400),
    };
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 422;
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
