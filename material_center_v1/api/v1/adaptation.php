<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2).'/bootstrap.php';

use Artdon\MaterialCenter\Adapters\LegacyAuthAdapter;
use Artdon\MaterialCenter\Security\PermissionService;
use Artdon\MaterialCenter\Services\AdaptationService;
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
    $service = new AdaptationService();
    $action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $data = match ($action) {
            'approved' => $service->approved((int) ($_GET['legacy_product_id'] ?? 0)),
            'products' => $service->products(trim((string) ($_GET['q'] ?? ''))),
            'workspace' => $service->workspace((int) ($_GET['product_id'] ?? 0), (int) ($_GET['group_id'] ?? 0)),
            'candidates' => $service->candidateMaterials((int) ($_GET['group_id'] ?? 0), $_GET),
            'metadata' => $service->metadata(),
            'reuse_templates' => $service->reuseTemplates(),
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
        'initialize_groups' => $service->initializeGroups((int) ($_POST['product_id'] ?? 0), $user->id),
        'apply_template' => $service->initializeGroups(
            (int) ($_POST['product_id'] ?? 0),
            $user->id,
            array_key_exists('template_keys', $_POST) ? $json('template_keys') : null
        ),
        'batch_initialize_groups' => $service->batchInitializeGroups(
            $json('product_ids'),
            $json('template_keys'),
            $user->id
        ),
        'save_group' => ['id' => $service->saveGroup($_POST, $user->id)],
        'save_template_category' => $service->saveTemplateCategory(array_merge($_POST, ['option_values' => $json('option_values')]), $user->id),
        'delete_template_category' => (static function () use ($service, $user): array {
            $service->deleteTemplateCategory((int) ($_POST['product_id'] ?? 0), (int) ($_POST['group_id'] ?? 0), $user->id);
            return [];
        })(),
        'save_quick_rules' => $service->saveQuickRules((int) ($_POST['group_id'] ?? 0), $json('rules'), $user->id),
        'save_power_rules' => $service->savePowerRules((int) ($_POST['group_id'] ?? 0), $json('rules'), $user->id),
        'save_technical_profile' => $service->saveTechnicalProfile((int) ($_POST['product_id'] ?? 0), $json('profile'), $user->id),
        'delete_group' => (static function () use ($service, $user): array {
            $service->deleteGroup((int) ($_POST['group_id'] ?? 0), $user->id);
            return [];
        })(),
        'reorder_groups' => (static function () use ($service, $user, $json): array {
            $service->reorderGroups((int) ($_POST['product_id'] ?? 0), $json('group_ids'), $user->id);
            return [];
        })(),
        'save_option' => ['id' => $service->saveOption($_POST, $user->id)],
        'add_options' => $service->addOptions(
            (int) ($_POST['group_id'] ?? 0),
            $json('material_ids'),
            $user->id,
            trim((string) ($_POST['force_exception_reason'] ?? ''))
        ),
        'save_option_chip_variants' => (new ChipSpecificationService())->saveOptionVariants(
            (int) ($_POST['option_id'] ?? 0),
            $json('variant_ids'),
            (int) ($_POST['default_variant_id'] ?? 0),
            $user->id
        ),
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
        'preview_batch' => (static function () use ($service, $user, $json): array {
            $includePower = !empty($_POST['include_power_rule']);
            if ($includePower) (new PermissionService())->require($user, 'material_center.power.rules.manage');
            return $service->previewBatchApply(
                (int) ($_POST['source_product_id'] ?? 0),
                $json('target_product_ids'),
                (string) ($_POST['mode'] ?? 'fill_missing'),
                $includePower,
                array_key_exists('source_group_ids', $_POST) ? $json('source_group_ids') : null
            );
        })(),
        'batch_apply' => (static function () use ($service, $user, $json): array {
            $includePower = !empty($_POST['include_power_rule']);
            if ($includePower) (new PermissionService())->require($user, 'material_center.power.rules.manage');
            return $service->batchApply(
                (int) ($_POST['source_product_id'] ?? 0),
                $json('target_product_ids'),
                (string) ($_POST['mode'] ?? 'fill_missing'),
                $includePower,
                $user->id,
                array_key_exists('source_group_ids', $_POST) ? $json('source_group_ids') : null
            );
        })(),
        'save_reuse_template' => (static function () use ($service, $user, $json): array {
            if (!empty($_POST['include_power_rule'])) (new PermissionService())->require($user, 'material_center.power.rules.manage');
            return $service->saveReuseTemplate([
                'source_product_id' => (int) ($_POST['source_product_id'] ?? 0),
                'template_name' => (string) ($_POST['template_name'] ?? ''),
                'description' => (string) ($_POST['description'] ?? ''),
                'source_group_ids' => $json('source_group_ids'),
                'include_power_rule' => !empty($_POST['include_power_rule']),
            ], $user->id);
        })(),
        'preview_reuse_template' => (static function () use ($service, $user, $json): array {
            $templateId = (int) ($_POST['reuse_template_id'] ?? 0);
            if ($service->reuseTemplateIncludesPower($templateId)) (new PermissionService())->require($user, 'material_center.power.rules.manage');
            return $service->previewReuseTemplate($templateId, $json('target_product_ids'), (string) ($_POST['mode'] ?? 'fill_missing'));
        })(),
        'apply_reuse_template' => (static function () use ($service, $user, $json): array {
            $templateId = (int) ($_POST['reuse_template_id'] ?? 0);
            if ($service->reuseTemplateIncludesPower($templateId)) (new PermissionService())->require($user, 'material_center.power.rules.manage');
            return $service->applyReuseTemplate($templateId, $json('target_product_ids'), (string) ($_POST['mode'] ?? 'fill_missing'), $user->id);
        })(),
        'disable_reuse_template' => (static function () use ($service, $user): array {
            $service->disableReuseTemplate((int) ($_POST['reuse_template_id'] ?? 0), $user->id);
            return [];
        })(),
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
