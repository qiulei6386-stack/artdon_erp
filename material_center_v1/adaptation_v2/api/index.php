<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/response.php';

$action = (string) ($_GET['action'] ?? 'status');

if ($action !== 'status') {
    pa2_json_response(
        ['allowed_actions' => ['status']],
        '产品适配 V2 当前处于第 1 阶段，只开放状态接口。',
        false,
        ['PHASE_1_BLUEPRINT_ONLY'],
        404
    );
    exit;
}

pa2_json_response([
    'phase' => 1,
    'phase_name' => '冻结旧版、审计和 V2 蓝图落地',
    'business_write_enabled' => false,
    'legacy_adaptation_mutated' => false,
    'legacy_bom_mutated' => false,
    'menu_switched' => false,
    'table_prefix' => 'mc_pa2_',
    'routes' => [
        '/material_center_v1/adaptation_v2/index.php?view=home',
        '/material_center_v1/adaptation_v2/index.php?view=products',
        '/material_center_v1/adaptation_v2/index.php?view=categories',
        '/material_center_v1/adaptation_v2/index.php?view=groups',
        '/material_center_v1/adaptation_v2/index.php?view=templates',
        '/material_center_v1/adaptation_v2/index.php?view=workspace',
        '/material_center_v1/adaptation_v2/index.php?view=packages',
        '/material_center_v1/adaptation_v2/index.php?view=publish',
        '/material_center_v1/adaptation_v2/index.php?view=approvals',
        '/material_center_v1/adaptation_v2/index.php?view=logs',
    ],
]);
