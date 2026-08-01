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
            'phase' => 10,
            'phase_name' => '最终验收和切换评估',
            'legacy_adaptation_mutated' => false,
            'legacy_bom_mutated' => false,
            'menu_switched' => true,
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
                'workspace_source_save',
                'workspace_recalculate',
                'adaptation_results',
                'product_versions',
                'product_version_diff',
                'product_version_submit',
                'product_version_approve',
                'product_version_reject',
                'product_version_publish',
                'product_version_rollback',
                'product_group_save',
                'material_candidates',
                'packages',
                'package_detail',
                'package_save',
                'package_version_prepare',
                'package_group_save',
                'package_option_save',
                'package_preview',
                'package_publish',
                'channel_clients',
                'channel_packages',
                'channel_package_detail',
                'channel_order_snapshot',
                'cutover_readiness',
                'cutover_audit_record',
            ],
            'routes' => [
                '/material_center_v1/adaptation_v2/index.php?view=home',
                '/material_center_v1/adaptation_v2/index.php?view=products',
                '/material_center_v1/adaptation_v2/index.php?view=categories',
                '/material_center_v1/adaptation_v2/index.php?view=groups',
                '/material_center_v1/adaptation_v2/index.php?view=templates',
                '/material_center_v1/adaptation_v2/index.php?view=rules',
                '/material_center_v1/adaptation_v2/index.php?view=workspace',
                '/material_center_v1/adaptation_v2/index.php?view=packages',
                '/material_center_v1/adaptation_v2/index.php?view=publish',
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

    if ($action === 'workspace_source_save') {
        pa2_json_response(pa2_save_workspace_source(pa2_request_data()), '产品分类/模板已套用到当前工作台');
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

    if ($action === 'product_versions') {
        pa2_require_any(['adaptation_v2.view', 'material_center.view'], '没有查看产品版本的权限。');
        $productId = (int)($_GET['product_id'] ?? 0);
        if ($productId <= 0) throw new RuntimeException('产品不能为空。');
        pa2_json_response(pa2_product_versions($productId));
        exit;
    }

    if ($action === 'product_version_diff') {
        pa2_require_any(['adaptation_v2.view', 'material_center.view'], '没有查看版本差异的权限。');
        $data = pa2_request_data();
        $productId = (int)($data['product_id'] ?? $_GET['product_id'] ?? 0);
        if ($productId <= 0) throw new RuntimeException('产品不能为空。');
        $config = pa2_fetch_config_by_product($productId);
        if (!$config) throw new RuntimeException('产品配置不存在。');
        $base = (int)($data['base_version_id'] ?? $_GET['base_version_id'] ?? ($config['active_published_version_id'] ?? 0)) ?: null;
        $compare = (int)($data['compare_version_id'] ?? $_GET['compare_version_id'] ?? ($config['active_draft_version_id'] ?? 0));
        if ($compare <= 0) throw new RuntimeException('对比版本不能为空。');
        pa2_json_response(['diff' => pa2_store_version_diff((int)$config['id'], $base, $compare)]);
        exit;
    }

    if ($action === 'product_version_submit') {
        $data = pa2_request_data();
        pa2_json_response(pa2_product_version_submit((int)($data['product_id'] ?? 0), (string)($data['note'] ?? '')), '产品配置已提交审批');
        exit;
    }

    if ($action === 'product_version_approve') {
        $data = pa2_request_data();
        pa2_json_response(pa2_product_version_approve((int)($data['product_id'] ?? 0), (string)($data['note'] ?? '')), '产品配置已审批通过');
        exit;
    }

    if ($action === 'product_version_reject') {
        $data = pa2_request_data();
        pa2_json_response(pa2_product_version_reject((int)($data['product_id'] ?? 0), (string)($data['note'] ?? '')), '产品配置已驳回');
        exit;
    }

    if ($action === 'product_version_publish') {
        $data = pa2_request_data();
        pa2_json_response(pa2_product_version_publish((int)($data['product_id'] ?? 0), (string)($data['note'] ?? '')), '产品配置已发布');
        exit;
    }

    if ($action === 'product_version_rollback') {
        $data = pa2_request_data();
        pa2_json_response(pa2_product_version_rollback((int)($data['product_id'] ?? 0), (int)($data['target_version_id'] ?? 0), (string)($data['note'] ?? '')), '产品配置已回滚');
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

    if ($action === 'packages') {
        pa2_require_any(['adaptation_v2.view', 'material_center.view'], '没有查看配置包中心的权限。');
        pa2_json_response(['packages' => pa2_fetch_packages()]);
        exit;
    }

    if ($action === 'package_detail') {
        pa2_require_any(['adaptation_v2.view', 'material_center.view'], '没有查看配置包详情的权限。');
        $package = pa2_fetch_package((int)($_GET['package_id'] ?? 0));
        if (!$package) throw new RuntimeException('配置包不存在。');
        pa2_json_response(['package' => $package, 'preview' => pa2_package_preview((int)$package['id'])]);
        exit;
    }

    if ($action === 'package_save') {
        pa2_json_response(['package' => pa2_upsert_package(pa2_request_data())], '配置包已保存');
        exit;
    }

    if ($action === 'package_version_prepare') {
        $data = pa2_request_data();
        pa2_json_response(['package' => pa2_prepare_package_version((int)($data['package_id'] ?? 0), (int)($data['source_product_config_version_id'] ?? 0))], '配置包草稿版本已准备');
        exit;
    }

    if ($action === 'package_group_save') {
        pa2_json_response(['package' => pa2_save_package_group(pa2_request_data())], '配置包组规则已保存');
        exit;
    }

    if ($action === 'package_option_save') {
        pa2_json_response(['package' => pa2_save_package_option(pa2_request_data())], '配置包选项已保存');
        exit;
    }

    if ($action === 'package_preview') {
        pa2_require_any(['adaptation_v2.view', 'material_center.view'], '没有预览配置包的权限。');
        pa2_json_response(['preview' => pa2_package_preview((int)($_GET['package_id'] ?? 0))]);
        exit;
    }

    if ($action === 'package_publish') {
        $data = pa2_request_data();
        pa2_json_response(pa2_publish_package((int)($data['package_id'] ?? 0)), '配置包已发布');
        exit;
    }

    if ($action === 'channel_clients') {
        pa2_require_any(['adaptation_v2.manage_channel', 'material_center.adaptation.manage'], '没有查看渠道客户端的权限。');
        pa2_json_response(['clients' => pa2_channel_clients()]);
        exit;
    }

    if ($action === 'channel_packages') {
        $raw = file_get_contents('php://input') ?: '';
        $context = pa2_channel_auth_context($raw);
        $payload = pa2_channel_published_packages((string)$context['client']['channel_code']);
        pa2_channel_log($action, $context, 200, 'ok', $_GET, $payload);
        pa2_json_response($payload, '已读取已发布配置包');
        exit;
    }

    if ($action === 'channel_package_detail') {
        $raw = file_get_contents('php://input') ?: '';
        $context = pa2_channel_auth_context($raw);
        $packageCode = (string)($_GET['package_code'] ?? '');
        $payload = pa2_channel_published_package_detail((string)$context['client']['channel_code'], $packageCode);
        pa2_channel_log($action, $context, 200, 'ok', $_GET, $payload);
        pa2_json_response($payload, '已读取已发布配置包详情');
        exit;
    }

    if ($action === 'channel_order_snapshot') {
        $raw = file_get_contents('php://input') ?: '';
        $context = pa2_channel_auth_context($raw);
        $data = pa2_request_data();
        $payload = pa2_channel_order_snapshot($data, $context);
        pa2_channel_log($action, $context, 200, 'ok', $data, $payload);
        pa2_json_response($payload, '下游订单快照已保存');
        exit;
    }

    if ($action === 'cutover_readiness') {
        pa2_require_any(['adaptation_v2.view', 'material_center.view'], '没有查看最终验收状态的权限。');
        pa2_json_response(['readiness' => pa2_cutover_readiness()]);
        exit;
    }

    if ($action === 'cutover_audit_record') {
        $data = pa2_request_data();
        pa2_json_response(pa2_record_cutover_audit((string)($data['note'] ?? '')), '最终验收记录已保存');
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
        ['allowed_actions' => ['status','categories','category_save','groups','group_save','group_option_save','group_behavior_save','products','product_map_save','workspace','workspace_prepare','workspace_source_save','workspace_recalculate','adaptation_results','product_versions','product_version_diff','product_version_submit','product_version_approve','product_version_reject','product_version_publish','product_version_rollback','product_group_save','material_candidates','packages','package_detail','package_save','package_version_prepare','package_group_save','package_option_save','package_preview','package_publish','channel_clients','channel_packages','channel_package_detail','channel_order_snapshot','cutover_readiness','cutover_audit_record','templates','template_detail','template_save','template_group_save','template_preview','template_publish','template_reference_check','rules','rule_save','rule_cycle_check']],
        '未知的产品适配 V2 接口动作。',
        false,
        ['ACTION_NOT_FOUND'],
        404
    );
} catch (Throwable $e) {
    if (str_starts_with($action, 'channel_')) {
        pa2_channel_log($action, null, 400, $e->getMessage(), $_GET, []);
    }
    pa2_json_response([], $e->getMessage(), false, ['PA2_API_ERROR'], 400);
}
