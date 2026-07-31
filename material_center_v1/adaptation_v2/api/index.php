<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/response.php';
require_once dirname(__DIR__) . '/lib/foundation.php';

$action = (string) ($_GET['action'] ?? 'status');

function pa2_request_data(): array
{
    $data = $_POST;
    $raw = file_get_contents('php://input');
    if (is_string($raw) && trim($raw) !== '') {
        $json = json_decode($raw, true);
        if (is_array($json)) $data = array_merge($data, $json);
        else {
            parse_str($raw, $parsed);
            if (is_array($parsed) && $parsed) $data = array_merge($data, $parsed);
        }
    }
    return $data;
}

try {
    if ($action === 'status') {
        pa2_json_response([
            'phase' => 2,
            'phase_name' => '基础数据模型和产品分类中心',
            'legacy_adaptation_mutated' => false,
            'legacy_bom_mutated' => false,
            'menu_switched' => false,
            'table_prefix' => 'mc_pa2_',
            'foundation' => pa2_foundation_summary(),
            'allowed_actions' => [
                'status',
                'categories',
                'category_save',
                'groups',
                'group_save',
                'group_option_save',
                'products',
                'product_map_save',
            ],
            'routes' => [
                '/material_center_v1/adaptation_v2/index.php?view=home',
                '/material_center_v1/adaptation_v2/index.php?view=products',
                '/material_center_v1/adaptation_v2/index.php?view=categories',
                '/material_center_v1/adaptation_v2/index.php?view=groups',
                '/material_center_v1/adaptation_v2/index.php?view=templates',
                '/material_center_v1/adaptation_v2/index.php?view=workspace',
            ],
        ]);
        exit;
    }

    if ($action === 'categories') {
        pa2_require_any(['adaptation_v2.view', 'material_center.view'], '没有查看产品适配 V2 的权限。');
        pa2_json_response(['categories' => pa2_fetch_categories()]);
        exit;
    }

    if ($action === 'category_save') {
        $row = pa2_upsert_category(pa2_request_data());
        pa2_json_response(['category' => $row], '分类已保存');
        exit;
    }

    if ($action === 'groups') {
        pa2_require_any(['adaptation_v2.view', 'material_center.view'], '没有查看产品适配 V2 的权限。');
        pa2_json_response(['groups' => pa2_fetch_groups(true)]);
        exit;
    }

    if ($action === 'group_save') {
        $row = pa2_upsert_group(pa2_request_data());
        pa2_json_response(['group' => $row], '配置组已保存');
        exit;
    }

    if ($action === 'group_option_save') {
        $row = pa2_add_group_option(pa2_request_data());
        pa2_json_response(['option' => $row], '配置组选项已保存');
        exit;
    }

    if ($action === 'products') {
        pa2_require_any(['adaptation_v2.view', 'material_center.view'], '没有查看产品适配 V2 的权限。');
        pa2_json_response(['products' => pa2_search_products((string)($_GET['q'] ?? ''), (int)($_GET['limit'] ?? 30))]);
        exit;
    }

    if ($action === 'product_map_save') {
        $row = pa2_map_product_category(pa2_request_data());
        pa2_json_response(['mapping' => $row], '产品分类映射已保存');
        exit;
    }

    pa2_json_response(
        ['allowed_actions' => ['status','categories','category_save','groups','group_save','group_option_save','products','product_map_save']],
        '未知的产品适配 V2 接口动作。',
        false,
        ['ACTION_NOT_FOUND'],
        404
    );
} catch (Throwable $e) {
    pa2_json_response([], $e->getMessage(), false, ['PA2_API_ERROR'], 400);
}
