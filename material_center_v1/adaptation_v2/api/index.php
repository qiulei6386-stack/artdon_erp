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
            'phase' => 6,
            'phase_name' => '适配计算和冲突引擎',
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
                'templates',
                'template_detail',
                'template_save',
                'template_group_save',
                'template_preview',
                'template_publish',
                'template_reference_check',
                'group_behavior_save',
                'rules',
                'rule_save',
                'rule_cycle_check',
                'workspace',
                'workspace_prepare',
                'workspace_recalculate',
                'adaptation_results',
                'product_group_save',
                'material_candidates',
            ],
            'routes' => [
                '/material_center_v1/adaptation_v2/index.php?view=home',
                '/material_center_v1/adaptation_v2/index.php?view=products',
                '/material_center_v1/adaptation_v2/index.php?view=categories',
                '/material_center_v1/adaptation_v2/index.php?view=groups',
                '/material_center_v1/adaptation_v2/index.php?view=templates',
                '/material_center_v1/adaptation_v2/index.php?view=rules',
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

    if ($action === 'group_behavior_save') {
        $row = pa2_upsert_group_behavior(pa2_request_data());
        pa2_json_response(['behavior' => $row], '配置组行为已保存');
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

    if ($action === 'workspace') {
        pa2_require_any(['adaptation_v2.view', 'adaptation_v2.configure_product', 'material_center.view'], '没有查看产品工作台的权限。');
        $productId = (int)($_GET['product_id'] ?? 0);
        if ($productId <= 0) throw new RuntimeException('产品不能为空。');
        pa2_json_response(pa2_workspace_detail($productId));
        exit;
    }

    if ($action === 'workspace_prepare') {
        $data = pa2_request_data();
        $productId = (int)($data['product_id'] ?? $_GET['product_id'] ?? 0);
        if ($productId <= 0) throw new RuntimeException('产品不能为空。');
        pa2_json_response(pa2_prepare_workspace($productId), '工作台草稿已准备');
        exit;
    }

    if ($action === 'workspace_recalculate') {
        $data = pa2_request_data();
        $productId = (int)($data['product_id'] ?? $_GET['product_id'] ?? 0);
        if ($productId <= 0) throw new RuntimeException('产品不能为空。');
        pa2_json_response(pa2_recalculate_workspace($productId, (string)($data['reason'] ?? 'manual')), '适配结果已重新计算');
        exit;
    }

    if ($action === 'adaptation_results') {
        pa2_require_any(['adaptation_v2.view', 'adaptation_v2.configure_product', 'material_center.view'], '没有查看适配结果的权限。');
        $productId = (int)($_GET['product_id'] ?? 0);
        if ($productId <= 0) throw new RuntimeException('产品不能为空。');
        $detail = pa2_workspace_detail($productId);
        pa2_json_response([
            'summary' => $detail['check_summary']['engine'] ?? [],
            'technical_range' => $detail['check_summary']['technical_range'] ?? [],
            'results' => $detail['adaptation_results'] ?? [],
        ]);
        exit;
    }

    if ($action === 'product_group_save') {
        $row = pa2_save_product_group_selection(pa2_request_data());
        pa2_json_response(['selection' => $row], '产品配置已保存');
        exit;
    }

    if ($action === 'material_candidates') {
        pa2_require_any(['adaptation_v2.view', 'adaptation_v2.configure_product', 'material_center.view'], '没有查看候选物料的权限。');
        pa2_json_response([
            'materials' => pa2_material_candidates((string)($_GET['group_code'] ?? ''), (string)($_GET['q'] ?? ''), (int)($_GET['limit'] ?? 30), (int)($_GET['product_id'] ?? 0), (int)($_GET['product_group_config_id'] ?? 0)),
        ]);
        exit;
    }

    if ($action === 'templates') {
        pa2_require_any(['adaptation_v2.view', 'material_center.view'], '没有查看产品适配 V2 的权限。');
        pa2_json_response(['templates' => pa2_fetch_templates()]);
        exit;
    }

    if ($action === 'template_detail') {
        pa2_require_any(['adaptation_v2.view', 'material_center.view'], '没有查看产品适配 V2 的权限。');
        $id = (int)($_GET['id'] ?? 0);
        $template = pa2_fetch_template($id);
        if (!$template) throw new RuntimeException('模板不存在。');
        pa2_json_response([
            'template' => $template,
            'direct_groups' => pa2_fetch_template_direct_groups($id),
            'preview' => pa2_template_effective_groups($id),
        ]);
        exit;
    }

    if ($action === 'template_save') {
        $row = pa2_upsert_template(pa2_request_data());
        pa2_json_response(['template' => $row], '模板已保存');
        exit;
    }

    if ($action === 'template_group_save') {
        $row = pa2_upsert_template_group(pa2_request_data());
        pa2_json_response(['template_group' => $row], '模板配置组已保存');
        exit;
    }

    if ($action === 'template_preview') {
        pa2_require_any(['adaptation_v2.view', 'material_center.view'], '没有查看模板预览的权限。');
        pa2_json_response(['preview' => pa2_template_effective_groups((int)($_GET['template_id'] ?? 0))]);
        exit;
    }

    if ($action === 'template_publish') {
        $row = pa2_publish_template((int)(pa2_request_data()['template_id'] ?? 0));
        pa2_json_response(['version' => $row], '模板版本已发布');
        exit;
    }

    if ($action === 'template_reference_check') {
        pa2_require_any(['adaptation_v2.view', 'material_center.view'], '没有查看模板引用的权限。');
        pa2_json_response(['references' => pa2_template_reference_check((int)($_GET['template_id'] ?? 0))]);
        exit;
    }

    if ($action === 'rules') {
        pa2_require_any(['adaptation_v2.view', 'material_center.view'], '没有查看配置规则的权限。');
        $rules = pa2_fetch_rules(true);
        pa2_json_response([
            'rules' => $rules,
            'cycle_check' => pa2_detect_rule_cycles($rules),
        ]);
        exit;
    }

    if ($action === 'rule_save') {
        $row = pa2_upsert_rule(pa2_request_data());
        pa2_json_response(['rule' => $row, 'cycle_check' => pa2_detect_rule_cycles(pa2_fetch_rules(false))], '配置规则已保存');
        exit;
    }

    if ($action === 'rule_cycle_check') {
        pa2_require_any(['adaptation_v2.view', 'material_center.view'], '没有查看规则循环检测的权限。');
        pa2_json_response(['cycle_check' => pa2_detect_rule_cycles(pa2_fetch_rules(false))]);
        exit;
    }

    pa2_json_response(
        ['allowed_actions' => ['status','categories','category_save','groups','group_save','group_option_save','group_behavior_save','products','product_map_save','workspace','workspace_prepare','workspace_recalculate','adaptation_results','product_group_save','material_candidates','templates','template_detail','template_save','template_group_save','template_preview','template_publish','template_reference_check','rules','rule_save','rule_cycle_check']],
        '未知的产品适配 V2 接口动作。',
        false,
        ['ACTION_NOT_FOUND'],
        404
    );
} catch (Throwable $e) {
    pa2_json_response([], $e->getMessage(), false, ['PA2_API_ERROR'], 400);
}
