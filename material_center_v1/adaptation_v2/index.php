<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/lib/response.php';
require_once __DIR__ . '/lib/foundation.php';

$activeMenu = 'adaptation';
$view = (string) ($_GET['view'] ?? 'home');
$allowedViews = [
    'home',
    'products',
    'categories',
    'groups',
    'templates',
    'template_editor',
    'rules',
    'workspace',
    'packages',
    'publish',
    'approvals',
    'cutover',
    'logs',
];
if (!in_array($view, $allowedViews, true)) $view = 'home';

$pageTitle = '产品适配 V2';
$pageDescription = '第 10 阶段：最终验收和切换评估。';
$summary = pa2_foundation_summary();
$categories = pa2_fetch_categories();
$groups = pa2_fetch_groups(true);
$groupsById = [];
foreach ($groups as $groupRow) $groupsById[(int)$groupRow['id']] = $groupRow;
$pa2AccessoryGroupCodes = ['accessory', 'glass', 'honeycomb', 'four_leaf_louver', 'optical_film'];
$pa2AccessoryGroups = [];
foreach ($groups as $groupRow) {
    if (in_array((string)$groupRow['group_code'], $pa2AccessoryGroupCodes, true)) {
        $pa2AccessoryGroups[] = $groupRow;
    }
}
$rules = pa2_fetch_rules(true);
$cycleCheck = pa2_detect_rule_cycles($rules);
$products = $summary['ready'] ? pa2_search_products((string)($_GET['q'] ?? ''), 40) : [];
$workspaceProductId = (int)($_GET['product_id'] ?? 0);
$workspace = null;
if ($view === 'workspace' && $workspaceProductId > 0 && pa2_workspace_tables_ready()) {
    try {
        $workspace = pa2_workspace_detail($workspaceProductId);
    } catch (Throwable $e) {
        $workspace = ['error' => $e->getMessage()];
    }
}
$templates = pa2_fetch_templates();
$selectedTemplateId = (int)($_GET['template_id'] ?? 0);
if ($selectedTemplateId <= 0 && $templates) $selectedTemplateId = (int)$templates[0]['id'];
$selectedTemplate = $selectedTemplateId > 0 ? pa2_fetch_template($selectedTemplateId) : null;
$selectedTemplateGroups = $selectedTemplate ? pa2_fetch_template_direct_groups((int)$selectedTemplate['id']) : [];
$selectedTemplatePreview = $selectedTemplate ? pa2_template_effective_groups((int)$selectedTemplate['id']) : ['chain'=>[],'groups'=>[],'changes'=>[]];
$canManageCategory = pa2_can_any(['adaptation_v2.manage_category', 'material_center.adaptation.manage']);
$canManageGroup = pa2_can_any(['adaptation_v2.manage_group_definition', 'material_center.adaptation.manage']);
$canManageTemplate = pa2_can_any(['adaptation_v2.manage_template', 'material_center.adaptation.manage']);
$canPublishTemplate = pa2_can_any(['adaptation_v2.publish_template', 'material_center.adaptation.manage']);
$canManageRule = pa2_can_any(['adaptation_v2.manage_rule', 'material_center.adaptation.manage']);
$canConfigureProduct = pa2_can_any(['adaptation_v2.configure_product', 'material_center.adaptation.manage']);
$canApproveProduct = pa2_can_any(['adaptation_v2.approve_product', 'material_center.adaptation.manage']);
$canPublishProduct = pa2_can_any(['adaptation_v2.publish_product', 'material_center.adaptation.manage']);
$canManagePackage = pa2_can_any(['adaptation_v2.manage_package', 'material_center.adaptation.manage']);
$canPublishPackage = pa2_can_any(['adaptation_v2.publish', 'material_center.adaptation.manage']);
$packages = pa2_fetch_packages();
$channelClients = pa2_channel_clients();
$selectedPackageId = (int)($_GET['package_id'] ?? 0);
if ($selectedPackageId <= 0 && $packages) $selectedPackageId = (int)$packages[0]['id'];
$selectedPackage = $selectedPackageId > 0 ? pa2_fetch_package($selectedPackageId) : null;
$selectedPackagePreview = $selectedPackage ? pa2_package_preview((int)$selectedPackage['id']) : null;
$pa2PackageLockLabels = pa2_package_rule_labels();
$cutoverReadiness = pa2_cutover_readiness();
$pa2ResultLabels = [
    'full_match' => '完全适配',
    'conditional_match' => '条件适配',
    'approval_required' => '需要审批',
    'incompatible' => '不适配',
];
$pa2ResultBadge = [
    'full_match' => 'pa2-badge--match',
    'conditional_match' => 'pa2-badge--condition',
    'approval_required' => 'pa2-badge--approval',
    'incompatible' => 'pa2-badge--block',
];
$pa2GroupTypeLabels = [
    'material_select' => '物料选择',
    'enum_select' => '属性选择',
    'hybrid_select' => '混合选择',
    'number_input' => '数值输入',
    'text_input' => '文本输入',
    'boolean' => '开关选择',
];
$pa2SelectionKindLabels = [
    'material' => '物料',
    'attribute' => '属性',
    'hybrid' => '混合',
    'number' => '数值',
    'text' => '文本',
];
$pa2SourceModeLabels = [
    'official_material' => '正式物料',
    'static_options' => '固定选项',
    'manual_input' => '手工输入',
    'mixed' => '混合来源',
];
$pa2SelectionModeLabels = [
    'single' => '单选',
    'multiple' => '多选',
];
$pa2InheritanceActionLabels = [
    'add' => '新增',
    'override' => '覆盖',
    'disable' => '已移除',
];
$pa2MaterialCategoryLabels = [
    'chip' => '芯片 / 光源',
    'power_supply' => '电源 / 驱动',
    'optic' => '光学 / 透镜',
    'installation' => '安装方式',
    'accessory' => '配件',
    'glass' => '玻璃',
    'honeycomb' => '蜂窝网',
    'four_leaf_louver' => '四叶片',
    'optical_film' => '光学膜',
];

$routeCards = [
    ['home', '首页', 'V2 基础状态、模板状态和阶段入口。'],
    ['products', '全部产品 / 映射', '查看产品并维护产品分类映射。'],
    ['categories', '产品分类中心', '维护产品分类、父子分类、启停和排序。'],
    ['groups', '配置组定义中心', '维护数据化配置组和属性选项。'],
    ['templates', '模板中心', '维护通用、分类、系列和产品模板。'],
    ['rules', '规则编辑器', '第 4 阶段维护显示条件、物料过滤、默认项和循环检测。'],
    ['workspace', '单产品配置工作台', '第 7 阶段支持草稿、提交、审批、发布、版本差异和回滚。'],
    ['packages', '配置包中心', '第 8 阶段维护渠道配置包、锁定范围、价格、MOQ、库存和交期规则。'],
    ['publish', '渠道发布', '第 9 阶段提供下游只读接口、签名、缓存、快照和日志。'],
    ['approvals', '审批中心', '第 7 阶段接入审批和发布。'],
    ['cutover', '最终验收', '第 10 阶段全量检查和切换评估；未通过前不切菜单。'],
    ['logs', '日志与版本', '查看 V2 执行记录和阶段文档。'],
];

if (!function_exists('pa2_view_url')) {
    function pa2_view_url(string $view, array $query = []): string
    {
        $query = array_merge(['view' => $view], $query);
        return mc_url('adaptation_v2/index.php?' . http_build_query($query));
    }
}
if (!function_exists('pa2_material_filter_summary_cn')) {
    function pa2_material_filter_summary_cn(array $filter): array
    {
        $items = [];
        if (isset($filter['formal_status'])) {
            $status = [
                'official' => '正式物料',
                'draft' => '草稿物料',
                'pending' => '待审核物料',
                'archived' => '归档物料',
            ][(string)$filter['formal_status']] ?? (string)$filter['formal_status'];
            $items[] = '物料状态：' . $status;
        }
        if (array_key_exists('approved_required', $filter)) {
            $items[] = '审核要求：' . ($filter['approved_required'] ? '必须已审核' : '不强制审核');
        }
        if (isset($filter['keyword'])) {
            $items[] = '关键词：' . (string)$filter['keyword'];
        }
        if (isset($filter['keywords']) && is_array($filter['keywords'])) {
            $items[] = '关键词：' . implode('、', array_map('strval', $filter['keywords']));
        }
        if (isset($filter['material_category_code'])) {
            $categoryLabels = [
                'chip' => '芯片 / 光源',
                'power_supply' => '电源 / 驱动',
                'optic' => '光学 / 透镜',
                'installation' => '安装方式',
                'accessory' => '配件',
                'glass' => '玻璃',
                'honeycomb' => '蜂窝网',
                'four_leaf_louver' => '四叶片',
                'optical_film' => '光学膜',
            ];
            $categoryCode = (string)$filter['material_category_code'];
            $items[] = '物料分类：' . ($categoryLabels[$categoryCode] ?? $categoryCode);
        }
        return $items ?: ['已设置过滤条件'];
    }
}

include MC_ROOT . '/components/layout_top.php';
?>
<style>
.mc-pa2-page{--pa2-teal:#0f9f9a;--pa2-blue:#2563eb;--pa2-border:#dbe7f3;--pa2-muted:#667085;--pa2-soft:#f7fbfc;height:100%;min-height:0;overflow:auto;display:grid;grid-auto-rows:max-content;align-content:start;gap:18px;padding-bottom:72px;scroll-behavior:smooth}
.pa2-hero{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;background:radial-gradient(circle at 12% 8%,#e7fffb 0,#fff 35%,#f7fbff 100%);border:1px solid var(--pa2-border);border-radius:22px;padding:24px;box-shadow:0 18px 45px rgba(15,159,154,.08)}
.pa2-hero h1{margin:0 0 6px;font-size:28px;color:#122033}.pa2-hero p{margin:0;color:var(--pa2-muted)}
.pa2-actions{display:flex;gap:10px;flex-wrap:wrap}.pa2-pill{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--pa2-border);border-radius:999px;padding:6px 10px;background:#fff;color:#344054;font-size:13px}.pa2-pill--ok{background:#ecfdf3;color:#067647;border-color:#abefc6}.pa2-pill--warn{background:#fffaeb;color:#b54708;border-color:#fedf89}
.pa2-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.pa2-card{background:linear-gradient(180deg,#fff,#fbfdff);border:1px solid var(--pa2-border);border-radius:18px;padding:17px;box-shadow:0 10px 28px rgba(16,24,40,.05)}.pa2-card strong{display:block;color:#122033}.pa2-card b{font-size:26px}.pa2-card span,.pa2-card p{color:var(--pa2-muted)}
.pa2-tabs{display:flex;gap:10px;flex-wrap:wrap}.pa2-tabs a{border:1px solid var(--pa2-border);background:#fff;border-radius:999px;padding:9px 14px;color:#344054;text-decoration:none;font-weight:700}.pa2-tabs a.is-active{background:var(--pa2-teal);border-color:var(--pa2-teal);color:#fff}
.pa2-panel{background:#fff;border:1px solid var(--pa2-border);border-radius:18px;overflow:visible}.pa2-panel__head{display:flex;justify-content:space-between;gap:14px;align-items:center;padding:16px 18px;border-bottom:1px solid var(--pa2-border)}.pa2-panel__head h2{margin:0;font-size:20px}.pa2-panel__head p{margin:4px 0 0;color:var(--pa2-muted)}
.pa2-panel__body{padding:18px}.pa2-form{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;align-items:end}.pa2-form label{display:grid;gap:6px;color:#344054;font-weight:700}.pa2-form input,.pa2-form select,.pa2-form textarea{width:100%;border:1px solid #cfd8e6;border-radius:10px;padding:9px 10px;background:#fff}.pa2-form .wide{grid-column:span 2}.pa2-form .full{grid-column:1/-1}
.pa2-table{width:100%;border-collapse:separate;border-spacing:0}.pa2-table th,.pa2-table td{border-bottom:1px solid #e6edf5;padding:11px;text-align:left;vertical-align:top}.pa2-table th{background:#f8fafc;color:#344054;font-size:13px}.pa2-table tr:target td{background:#fffbeb}.pa2-table code{background:#f1f5f9;border-radius:6px;padding:2px 6px}.pa2-mini-form{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.pa2-mini-form input,.pa2-mini-form select{border:1px solid #cfd8e6;border-radius:9px;padding:7px 9px;min-width:110px}.pa2-options{display:flex;gap:6px;flex-wrap:wrap}.pa2-options span{background:#eef4ff;color:#1d4ed8;border-radius:999px;padding:4px 8px;font-size:12px}.pa2-filter-chips{display:flex;gap:6px;flex-wrap:wrap;margin-top:6px}.pa2-filter-chips span{background:#ecfdf3;color:#067647;border:1px solid #abefc6;border-radius:999px;padding:5px 9px;font-size:12px}.pa2-jump-card{display:flex;justify-content:space-between;gap:12px;align-items:center;border:1px solid #b7e4e2;background:linear-gradient(135deg,#f0fdfa,#fff);border-radius:16px;padding:12px 14px}.pa2-jump-card strong{color:#0b7773}.pa2-jump-links{display:flex;gap:8px;flex-wrap:wrap}.pa2-jump-links a{display:inline-flex;align-items:center;border:1px solid #c9eeeb;background:#fff;color:#0b7773;text-decoration:none;border-radius:999px;padding:7px 11px;font-weight:800}
.pa2-alert{border:1px solid #fedf89;background:#fffaeb;color:#93370d;border-radius:14px;padding:14px}.pa2-muted{color:var(--pa2-muted)}.pa2-section-gap{display:grid;gap:16px}.pa2-placeholder{padding:34px;text-align:center;color:var(--pa2-muted)}
.pa2-template-shell{display:grid;grid-template-columns:280px minmax(0,1fr) 360px;gap:16px;align-items:start}.pa2-template-list{display:grid;gap:10px}.pa2-template-item{display:block;text-decoration:none;color:inherit;border:1px solid var(--pa2-border);border-radius:16px;padding:14px;background:#fff}.pa2-template-item.is-active{border-color:var(--pa2-teal);box-shadow:0 12px 30px rgba(15,159,154,.12)}.pa2-template-item strong{display:block}.pa2-template-item span{color:var(--pa2-muted);font-size:13px}.pa2-flow{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.pa2-flow span{background:#eef8f8;color:#0b7773;border:1px solid #c9eeeb;border-radius:999px;padding:6px 10px}.pa2-group-grid{display:grid;gap:10px}.pa2-group-card{display:grid;grid-template-columns:1fr auto;gap:10px;border:1px solid var(--pa2-border);border-radius:16px;padding:13px;background:#fff}.pa2-group-card small{color:var(--pa2-muted)}.pa2-badge{display:inline-flex;align-items:center;border-radius:999px;padding:4px 8px;font-size:12px;background:#eef4ff;color:#1d4ed8}.pa2-badge--add{background:#ecfdf3;color:#067647}.pa2-badge--override{background:#fff7ed;color:#c2410c}.pa2-badge--disable{background:#fef2f2;color:#b42318}.pa2-badge--match{background:#ecfdf3;color:#067647}.pa2-badge--condition{background:#fffaeb;color:#b54708}.pa2-badge--approval{background:#eef4ff;color:#1d4ed8}.pa2-badge--block{background:#fef2f2;color:#b42318}.pa2-side-note{background:var(--pa2-soft);border:1px dashed #c9d8e8;border-radius:16px;padding:14px;color:#344054}.pa2-two-col{display:grid;grid-template-columns:1fr 1fr;gap:12px}.pa2-template-actions{display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end}.pa2-result-note{display:grid;gap:5px;border-top:1px dashed #dbe7f3;padding-top:8px}.pa2-result-note small{color:var(--pa2-muted)}.pa2-engine-summary{display:flex;gap:8px;flex-wrap:wrap;margin-top:6px}.pa2-engine-summary span{font-size:12px;color:#344054}
.pa2-rule-board{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(360px,.9fr);gap:16px;align-items:start}.pa2-rule-card{border:1px solid var(--pa2-border);border-radius:16px;padding:14px;background:linear-gradient(180deg,#fff,#fbfdff);display:grid;gap:8px}.pa2-rule-card.is-cycle{border-color:#fda29b;background:#fff7f7}.pa2-rule-line{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.pa2-chip{display:inline-flex;align-items:center;border-radius:999px;padding:5px 9px;background:#f2f4f7;color:#344054;font-size:12px}.pa2-chip--show{background:#ecfdf3;color:#067647}.pa2-chip--hide{background:#fef3f2;color:#b42318}.pa2-chip--filter{background:#eff8ff;color:#175cd3}.pa2-behavior{display:grid;gap:8px}.pa2-behavior summary{cursor:pointer;color:#0b7773;font-weight:800}.pa2-json{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:12px;white-space:pre-wrap;background:#f8fafc;border:1px solid #e6edf5;border-radius:10px;padding:8px;max-width:360px}
.pa2-workspace{display:grid;gap:16px}.pa2-product-hero{display:grid;grid-template-columns:96px minmax(0,1fr) auto;gap:18px;align-items:center;background:#fff;border:1px solid var(--pa2-border);border-radius:20px;padding:18px}.pa2-product-hero img{width:88px;height:88px;object-fit:contain;border:1px solid #e6edf5;border-radius:14px;background:#f8fafc}.pa2-steps{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.pa2-step{border:1px solid var(--pa2-border);border-radius:16px;padding:14px;background:#fff}.pa2-step b{display:inline-flex;width:24px;height:24px;align-items:center;justify-content:center;border-radius:50%;background:#e6fffb;color:#0b7773;margin-right:8px}.pa2-work-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.pa2-config-card{border:1px solid var(--pa2-border);border-radius:18px;background:#fff;padding:14px;display:grid;gap:10px;min-height:190px}.pa2-config-card.is-missing{border-color:#fedf89;background:#fffdf7}.pa2-config-card.is-done{border-color:#abefc6}.pa2-config-card__head{display:flex;justify-content:space-between;gap:8px}.pa2-selected{display:grid;gap:6px}.pa2-selected span{background:#f2f4f7;border-radius:10px;padding:7px 9px}.pa2-footerbar{display:flex;justify-content:space-between;gap:12px;align-items:center;border:1px solid var(--pa2-border);border-radius:18px;background:#fff;padding:14px 16px;position:sticky;bottom:10px;box-shadow:0 12px 32px rgba(16,24,40,.08)}.pa2-dialog{border:0;border-radius:20px;padding:0;width:min(980px,92vw);box-shadow:0 24px 80px rgba(16,24,40,.28)}.pa2-dialog--narrow{width:min(580px,92vw);overflow:hidden}.pa2-dialog::backdrop{background:rgba(15,23,42,.32)}.pa2-dialog__head,.pa2-dialog__foot{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:16px 18px;border-bottom:1px solid var(--pa2-border)}.pa2-dialog__head{background:linear-gradient(135deg,#f0fdfa,#f8fbff 55%,#fff)}.pa2-dialog__head h3{margin:0;font-size:19px;color:#122033}.pa2-dialog__head p{margin:4px 0 0;color:var(--pa2-muted);font-size:13px}.pa2-dialog__foot{border-top:1px solid var(--pa2-border);border-bottom:0;background:#fbfdff}.pa2-dialog__body{padding:16px 18px;max-height:62vh;overflow:auto}.pa2-dialog-form{display:grid;grid-template-columns:1fr 1fr;gap:12px}.pa2-dialog-form label{display:grid;gap:6px;font-weight:800;color:#344054}.pa2-dialog-form input,.pa2-dialog-form select{border:1px solid #cfd8e6;border-radius:12px;padding:10px 11px;background:#fff}.pa2-dialog-form .full{grid-column:1/-1}.pa2-dialog-hint{border:1px dashed #b7e4e2;background:#f0fdfa;border-radius:14px;padding:11px;color:#0b7773}.pa2-candidate-list{display:grid;gap:10px}.pa2-candidate{display:grid;grid-template-columns:1fr auto;gap:12px;align-items:center;border:1px solid #e6edf5;border-radius:14px;padding:12px}.pa2-candidate small{color:var(--pa2-muted)}
.pa2-package-shell{display:grid;grid-template-columns:360px minmax(0,1fr);gap:16px;align-items:start}.pa2-package-list{display:grid;gap:12px}.pa2-package-item{display:grid;gap:8px;text-decoration:none;color:inherit;border:1px solid var(--pa2-border);border-radius:18px;padding:14px;background:#fff}.pa2-package-item.is-active{border-color:var(--pa2-teal);box-shadow:0 14px 32px rgba(15,159,154,.12)}.pa2-package-item__meta{display:flex;gap:8px;flex-wrap:wrap}.pa2-package-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.pa2-package-stat{border:1px solid #e6edf5;border-radius:14px;background:#f8fafc;padding:11px}.pa2-package-checks{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.pa2-package-check{border:1px solid #e6edf5;border-radius:14px;padding:12px;background:#fff}.pa2-package-check.is-pass{border-color:#abefc6;background:#f6fef9}.pa2-package-check.is-fail{border-color:#fda29b;background:#fff7f7}.pa2-package-group{border:1px solid var(--pa2-border);border-radius:16px;padding:14px;background:#fff;display:grid;gap:10px}.pa2-package-group__head{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}.pa2-package-rule-row{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}.pa2-package-rule-row code{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.pa2-package-options{display:flex;gap:8px;flex-wrap:wrap}.pa2-package-options span{background:#eef4ff;color:#1d4ed8;border-radius:999px;padding:5px 9px;font-size:12px}.pa2-package-options span.is-locked{background:#fef3f2;color:#b42318}.pa2-package-options span.is-default{background:#ecfdf3;color:#067647}.pa2-channel-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.pa2-channel-card{border:1px solid var(--pa2-border);border-radius:18px;background:#fff;padding:15px;display:grid;gap:8px}.pa2-endpoint{border:1px dashed #c9d8e8;border-radius:14px;background:#f8fafc;padding:12px}.pa2-endpoint code{display:block;white-space:normal}.pa2-redline{border:1px solid #fda29b;background:#fff7f7;color:#b42318;border-radius:14px;padding:12px}.pa2-cutover-banner{border:1px solid #fedf89;background:#fffaeb;border-radius:18px;padding:16px;display:flex;justify-content:space-between;gap:12px;align-items:center}.pa2-cutover-banner.is-ready{border-color:#abefc6;background:#f6fef9}.pa2-check-list{display:grid;gap:10px}.pa2-check-item{display:grid;grid-template-columns:1fr auto;gap:10px;border:1px solid #e6edf5;border-radius:14px;background:#fff;padding:12px}.pa2-check-item.is-blocked{border-color:#fda29b;background:#fff7f7}.pa2-check-item.is-passed{border-color:#abefc6;background:#f6fef9}
@media(max-width:1100px){.pa2-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.pa2-form{grid-template-columns:repeat(2,minmax(0,1fr))}.pa2-hero{display:grid}}@media(max-width:700px){.pa2-grid,.pa2-form{grid-template-columns:1fr}.pa2-form .wide{grid-column:auto}}
@media(max-width:1280px){.pa2-template-shell,.pa2-rule-board,.pa2-package-shell{grid-template-columns:1fr}.pa2-work-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.pa2-template-actions{justify-content:flex-start}}@media(max-width:760px){.pa2-product-hero,.pa2-footerbar{display:grid}.pa2-steps,.pa2-work-grid,.pa2-package-stats,.pa2-package-checks,.pa2-package-rule-row,.pa2-channel-grid{grid-template-columns:1fr}}
</style>
<section class="mc-page mc-pa2-page" data-adaptation-v2 data-phase="10" data-view="<?=mc_h($view)?>">
    <header class="pa2-hero">
        <div>
            <div class="mc-breadcrumb">Artdon ERP / 物料中心 / 产品适配 V2</div>
            <h1>产品适配 V2</h1>
            <p>独立旁路开发中：旧版业务、旧 BOM 和正式菜单保持不变；V2 仅使用 <code>adaptation_v2/</code> 与 <code>mc_pa2_</code> 新表。</p>
        </div>
        <div class="pa2-actions">
            <span class="pa2-pill <?= $summary['ready'] ? 'pa2-pill--ok' : 'pa2-pill--warn' ?>"><?= $summary['ready'] ? '基础表已就绪' : '待执行 V2 迁移' ?></span>
            <a class="mc-button" href="<?=mc_h(mc_url('adaptation/index.php'))?>">返回旧版产品适配</a>
            <a class="mc-button mc-button--primary" href="<?=mc_h(pa2_view_url('logs'))?>">查看阶段日志</a>
        </div>
    </header>

    <nav class="pa2-tabs">
        <?php foreach ($routeCards as $card): ?>
            <a class="<?= $view === $card[0] ? 'is-active' : '' ?>" href="<?=mc_h(pa2_view_url($card[0]))?>"><?=mc_h($card[1])?></a>
        <?php endforeach; ?>
    </nav>

    <?php if (!$summary['ready']): ?>
        <div class="pa2-alert">V2 第 2 阶段基础表尚未迁移。发布后执行 <code>php material_center_v1/adaptation_v2/tools/migrate.php up</code> 即可创建 <code>mc_pa2_*</code> 新表和种子数据。</div>
    <?php endif; ?>

    <?php if ($view === 'home'): ?>
        <section class="pa2-grid">
            <article class="pa2-card"><strong>产品分类</strong><b><?=intval($summary['category_count'])?></b><p>首批分类种子：导轨灯、嵌入式、磁吸式等。</p></article>
            <article class="pa2-card"><strong>配置组定义</strong><b><?=intval($summary['group_count'])?></b><p>芯片、电源、光学、安装、颜色等全部数据化。</p></article>
            <article class="pa2-card"><strong>产品配置草稿</strong><b><?=intval($summary['product_config_count'])?></b><p>第 5 阶段开始保存 V2 单产品草稿配置。</p></article>
            <article class="pa2-card"><strong>最终验收</strong><b><?=intval($summary['cutover_blocker_count'] ?? count($cutoverReadiness['blockers'] ?? []))?></b><p>阻断项；审计记录 <?=intval($summary['cutover_audit_count'] ?? 0)?> 条。</p></article>
        </section>
        <section class="pa2-panel">
            <div class="pa2-panel__head"><div><h2>阶段路由和边界</h2><p>第 10 阶段开放最终验收和切换评估；当前仍不切正式菜单。只有全部阻断项消除后，才允许进入正式切换审批。</p></div><span class="pa2-pill <?=($cutoverReadiness['ready_to_switch'] ?? false)?'pa2-pill--ok':'pa2-pill--warn'?>"><?=mc_h($cutoverReadiness['decision'] ?? '待检查')?></span></div>
            <div class="pa2-panel__body">
                <table class="pa2-table">
                    <thead><tr><th>视图</th><th>入口</th><th>阶段说明</th></tr></thead>
                    <tbody>
                    <?php foreach ($routeCards as $card): ?>
                        <tr><td><?=mc_h($card[1])?></td><td><code>/material_center_v1/adaptation_v2/index.php?view=<?=mc_h($card[0])?></code></td><td><?=mc_h($card[2])?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php elseif ($view === 'categories'): ?>
        <section class="pa2-panel">
            <div class="pa2-panel__head">
                <div><h2>产品分类中心</h2><p>维护 V2 产品业务分类；分类不是物料分类，后续用于模板继承和配置包发布。</p></div>
                <div class="pa2-template-actions">
                    <?php if ($canManageCategory): ?><button class="mc-button mc-button--primary" type="button" data-open-category-create>新增分类</button><?php endif; ?>
                </div>
            </div>
            <div class="pa2-panel__body pa2-section-gap">
                <?php if ($canManageCategory): ?>
                <dialog class="pa2-dialog pa2-dialog--narrow" id="pa2-category-create-dialog">
                    <div class="pa2-dialog__head">
                        <div><h3>新增产品分类</h3><p>建立 V2 产品业务分类，用于模板继承和配置包发布。</p></div>
                        <button class="mc-button" type="button" data-close-category-create>关闭</button>
                    </div>
                    <form data-pa2-form action="<?=mc_h(mc_url('adaptation_v2/api/index.php?action=category_save'))?>">
                        <div class="pa2-dialog__body">
                            <div class="pa2-dialog-form">
                                <label><span>分类编码</span><input name="category_code" placeholder="例如 track_light"></label>
                                <label><span>分类名称 *</span><input name="category_name" required placeholder="例如 导轨灯"></label>
                                <label><span>父分类</span><select name="parent_id"><option value="">无父分类</option><?php foreach ($categories as $c): ?><option value="<?=intval($c['id'])?>"><?=mc_h($c['category_name'])?></option><?php endforeach; ?></select></label>
                                <label><span>排序</span><input type="number" name="sort_order" value="100"></label>
                                <label><span>状态</span><select name="is_enabled"><option value="1">启用</option><option value="0">停用</option></select></label>
                                <label class="full"><span>说明</span><input name="description" placeholder="分类用途和适用范围"></label>
                            </div>
                        </div>
                        <div class="pa2-dialog__foot">
                            <span class="pa2-muted">保存后自动刷新分类列表。</span>
                            <div class="pa2-template-actions">
                                <button class="mc-button" type="button" data-close-category-create>取消</button>
                                <button class="mc-button mc-button--primary" type="submit">保存分类</button>
                            </div>
                        </div>
                    </form>
                </dialog>
                <dialog class="pa2-dialog pa2-dialog--narrow" id="pa2-category-edit-dialog">
                    <div class="pa2-dialog__head">
                        <div><h3>编辑产品分类</h3><p id="pa2-category-edit-subtitle">修改分类基础资料。</p></div>
                        <button class="mc-button" type="button" data-close-category-edit>关闭</button>
                    </div>
                    <form data-pa2-form action="<?=mc_h(mc_url('adaptation_v2/api/index.php?action=category_save'))?>">
                        <input type="hidden" name="id">
                        <div class="pa2-dialog__body">
                            <div class="pa2-dialog-form">
                                <label><span>分类编码</span><input name="category_code"></label>
                                <label><span>分类名称 *</span><input name="category_name" required></label>
                                <label><span>父分类</span><select name="parent_id"><option value="">无父分类</option><?php foreach ($categories as $c): ?><option value="<?=intval($c['id'])?>"><?=mc_h($c['category_name'])?></option><?php endforeach; ?></select></label>
                                <label><span>排序</span><input type="number" name="sort_order"></label>
                                <label><span>状态</span><select name="is_enabled"><option value="1">启用</option><option value="0">停用</option></select></label>
                                <label class="full"><span>说明</span><input name="description"></label>
                            </div>
                        </div>
                        <div class="pa2-dialog__foot">
                            <span class="pa2-muted">不会修改旧版产品适配分类。</span>
                            <div class="pa2-template-actions">
                                <button class="mc-button" type="button" data-close-category-edit>取消</button>
                                <button class="mc-button mc-button--primary" type="submit">保存分类</button>
                            </div>
                        </div>
                    </form>
                </dialog>
                <?php endif; ?>
                <table class="pa2-table">
                    <thead><tr><th>编码</th><th>分类</th><th>父分类</th><th>产品数</th><th>排序/状态</th><th>编辑</th></tr></thead>
                    <tbody>
                    <?php foreach ($categories as $c): ?>
                        <tr>
                            <td><code><?=mc_h($c['category_code'])?></code></td>
                            <td><strong><?=mc_h($c['category_name'])?></strong><br><span class="pa2-muted"><?=mc_h($c['description'] ?? '')?></span></td>
                            <td><?=mc_h($c['parent_name'] ?? '—')?></td>
                            <td><?=intval($c['mapped_product_count'])?></td>
                            <td><?=intval($c['sort_order'])?> · <?=((int)$c['is_enabled'] === 1 ? '启用' : '停用')?></td>
                            <td>
                                <?php if ($canManageCategory): ?>
                                <button class="mc-button" type="button"
                                    data-open-category-edit
                                    data-category-id="<?=intval($c['id'])?>"
                                    data-category-code="<?=mc_h($c['category_code'])?>"
                                    data-category-name="<?=mc_h($c['category_name'])?>"
                                    data-parent-id="<?=intval($c['parent_id'] ?? 0)?>"
                                    data-sort-order="<?=intval($c['sort_order'])?>"
                                    data-is-enabled="<?=intval($c['is_enabled'])?>"
                                    data-description="<?=mc_h($c['description'] ?? '')?>">编辑</button>
                                <?php else: ?>只读<?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php elseif ($view === 'groups'): ?>
        <section class="pa2-panel">
            <div class="pa2-panel__head">
                <div><h2>配置组定义中心</h2><p>第 4 阶段：配置组可以设置物料来源、属性来源、默认项、必选/可选、单选/多选和数量限制。</p></div>
                <div class="pa2-template-actions">
                    <?php if ($canManageGroup): ?><button class="mc-button mc-button--primary" type="button" data-open-group-create>新增配置组</button><?php endif; ?>
                    <a class="mc-button" href="<?=mc_h(pa2_view_url('rules'))?>">打开规则编辑器</a>
                </div>
            </div>
            <div class="pa2-panel__body pa2-section-gap">
                <?php if ($canManageGroup): ?>
                <dialog class="pa2-dialog pa2-dialog--narrow" id="pa2-group-create-dialog">
                    <div class="pa2-dialog__head">
                        <div>
                            <h3>新增配置组</h3>
                            <p>先建立配置组定义，保存后再在列表中设置物料来源、默认项和规则。</p>
                        </div>
                        <button class="mc-button" type="button" data-close-group-create>关闭</button>
                    </div>
                    <form data-pa2-form action="<?=mc_h(mc_url('adaptation_v2/api/index.php?action=group_save'))?>">
                        <div class="pa2-dialog__body">
                            <div class="pa2-dialog-hint">建议编码使用英文小写和下划线，例如 <code>glass</code>、<code>honeycomb</code>、<code>optical_film</code>，后续模板和配置包会用到。</div>
                            <div class="pa2-dialog-form" style="margin-top:12px">
                                <label><span>组编码</span><input name="group_code" placeholder="例如 glass"></label>
                                <label><span>配置组名称 *</span><input name="group_name" required placeholder="例如 玻璃"></label>
                                <label><span>组类型</span><select name="group_type"><option value="material_select">物料选择</option><option value="enum_select">属性选择</option><option value="hybrid_select">混合选择</option><option value="number_input">数值输入</option><option value="text_input">文本输入</option><option value="boolean">布尔开关</option></select></label>
                                <label><span>排序</span><input type="number" name="sort_order" value="100"></label>
                                <label><span>图标</span><input name="icon" maxlength="40" placeholder="例如 ▯"></label>
                                <label><span>状态</span><select name="is_enabled"><option value="1">启用</option><option value="0">停用</option></select></label>
                                <label class="full"><span>说明</span><input name="description" placeholder="说明用途、来源和业务含义"></label>
                            </div>
                        </div>
                        <div class="pa2-dialog__foot">
                            <span class="pa2-muted">保存后可继续在表格中补行为设置。</span>
                            <div class="pa2-template-actions">
                                <button class="mc-button" type="button" data-close-group-create>取消</button>
                                <button class="mc-button mc-button--primary" type="submit">保存配置组</button>
                            </div>
                        </div>
                    </form>
                </dialog>
                <dialog class="pa2-dialog pa2-dialog--narrow" id="pa2-option-create-dialog">
                    <div class="pa2-dialog__head">
                        <div>
                            <h3>新增属性选项</h3>
                            <p id="pa2-option-create-subtitle">给当前配置组增加可选项。</p>
                        </div>
                        <button class="mc-button" type="button" data-close-option-create>关闭</button>
                    </div>
                    <form data-pa2-form action="<?=mc_h(mc_url('adaptation_v2/api/index.php?action=group_option_save'))?>">
                        <input type="hidden" name="group_definition_id">
                        <div class="pa2-dialog__body">
                            <div class="pa2-dialog-hint">选项编码建议使用英文或数字，例如 <code>white</code>、<code>black</code>；选项名称给业务人员看，可以写中文。</div>
                            <div class="pa2-dialog-form" style="margin-top:12px">
                                <label><span>选项编码</span><input name="option_code" placeholder="例如 white"></label>
                                <label><span>选项名称 *</span><input name="option_name" required placeholder="例如 白色"></label>
                                <label class="full"><span>默认项</span><select name="is_default"><option value="0">不是默认</option><option value="1">设为默认</option></select></label>
                            </div>
                        </div>
                        <div class="pa2-dialog__foot">
                            <span class="pa2-muted">保存后自动刷新列表。</span>
                            <div class="pa2-template-actions">
                                <button class="mc-button" type="button" data-close-option-create>取消</button>
                                <button class="mc-button mc-button--primary" type="submit">保存选项</button>
                            </div>
                        </div>
                    </form>
                </dialog>
                <dialog class="pa2-dialog pa2-dialog--narrow" id="pa2-group-edit-dialog">
                    <div class="pa2-dialog__head">
                        <div>
                            <h3>编辑配置组</h3>
                            <p id="pa2-group-edit-subtitle">修改配置组基础资料。</p>
                        </div>
                        <button class="mc-button" type="button" data-close-group-edit>关闭</button>
                    </div>
                    <form data-pa2-form action="<?=mc_h(mc_url('adaptation_v2/api/index.php?action=group_save'))?>">
                        <input type="hidden" name="id">
                        <div class="pa2-dialog__body">
                            <div class="pa2-dialog-form">
                                <label><span>组编码</span><input name="group_code"></label>
                                <label><span>配置组名称 *</span><input name="group_name" required></label>
                                <label><span>组类型</span><select name="group_type"><?php foreach (['material_select'=>'物料选择','enum_select'=>'属性选择','hybrid_select'=>'混合选择','number_input'=>'数值输入','text_input'=>'文本输入','boolean'=>'布尔开关'] as $k=>$v): ?><option value="<?=mc_h($k)?>"><?=mc_h($v)?></option><?php endforeach; ?></select></label>
                                <label><span>排序</span><input type="number" name="sort_order"></label>
                                <label class="full"><span>状态</span><select name="is_enabled"><option value="1">启用</option><option value="0">停用</option></select></label>
                            </div>
                        </div>
                        <div class="pa2-dialog__foot">
                            <span class="pa2-muted">只修改 V2 配置组定义。</span>
                            <div class="pa2-template-actions">
                                <button class="mc-button" type="button" data-close-group-edit>取消</button>
                                <button class="mc-button mc-button--primary" type="submit">保存配置组</button>
                            </div>
                        </div>
                    </form>
                </dialog>
                <dialog class="pa2-dialog" id="pa2-behavior-edit-dialog">
                    <div class="pa2-dialog__head">
                        <div>
                            <h3>编辑行为 / 来源</h3>
                            <p id="pa2-behavior-edit-subtitle">设置配置组的数据来源、必选规则、选择方式和过滤条件。</p>
                        </div>
                        <button class="mc-button" type="button" data-close-behavior-edit>关闭</button>
                    </div>
                    <form data-pa2-form action="<?=mc_h(mc_url('adaptation_v2/api/index.php?action=group_behavior_save'))?>">
                        <input type="hidden" name="group_definition_id">
                        <div class="pa2-dialog__body">
                            <div class="pa2-dialog-form">
                                <label><span>选择组类型</span><select name="selection_kind">
                                    <?php foreach (['material'=>'物料选择组','attribute'=>'属性选择组','hybrid'=>'混合选择组','number'=>'数值组','text'=>'文本组'] as $k=>$v): ?><option value="<?=mc_h($k)?>"><?=mc_h($v)?></option><?php endforeach; ?>
                                </select></label>
                                <label><span>数据来源</span><select name="source_mode">
                                    <?php foreach (['official_material'=>'正式物料','static_options'=>'静态属性选项','manual_input'=>'手工输入','mixed'=>'混合来源'] as $k=>$v): ?><option value="<?=mc_h($k)?>"><?=mc_h($v)?></option><?php endforeach; ?>
                                </select></label>
                                <label><span>物料分类</span><select name="material_category_code">
                                    <option value="">不限定分类</option>
                                    <?php foreach ($pa2MaterialCategoryLabels as $k=>$v): ?><option value="<?=mc_h($k)?>"><?=mc_h($v)?></option><?php endforeach; ?>
                                </select></label>
                                <label><span>必选/可选</span><select name="is_required_default"><option value="0">可选</option><option value="1">必选</option></select></label>
                                <label><span>单选/多选</span><select name="selection_mode_default"><option value="single">单选</option><option value="multiple">多选</option></select></label>
                                <label><span>最少选择</span><input type="number" name="min_select_default" placeholder="最少"></label>
                                <label><span>最多选择</span><input type="number" name="max_select_default" placeholder="最多"></label>
                                <label><span>默认项 JSON</span><input name="default_rule_json" placeholder='例如 {"option_code":"white"}'></label>
                                <label class="full"><span>物料过滤 JSON</span><input name="material_filter_json" placeholder='例如 {"formal_status":"official"}'></label>
                                <label class="full"><span>显示条件 JSON</span><input name="visibility_condition_json" placeholder='例如 {"controlled_by":"track_system"}'></label>
                            </div>
                        </div>
                        <div class="pa2-dialog__foot">
                            <span class="pa2-muted">高级 JSON 保留原样；保存后自动刷新列表。</span>
                            <div class="pa2-template-actions">
                                <button class="mc-button" type="button" data-close-behavior-edit>取消</button>
                                <button class="mc-button mc-button--primary" type="submit">保存行为</button>
                            </div>
                        </div>
                    </form>
                </dialog>
                <?php endif; ?>
                <?php if ($pa2AccessoryGroups): ?>
                <div class="pa2-jump-card">
                    <div><strong>新增配件配置组已创建</strong><br><span class="pa2-muted">配件、玻璃、蜂窝网、四叶片、光学膜在列表排序 121–125，点击可直接定位。</span></div>
                    <div class="pa2-jump-links">
                        <?php foreach ($pa2AccessoryGroups as $jumpGroup): ?>
                            <a href="#pa2-group-<?=mc_h($jumpGroup['group_code'])?>"><?=mc_h($jumpGroup['group_name'])?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                <table class="pa2-table">
                    <thead><tr><th>编码</th><th>配置组</th><th>类型</th><th>属性选项</th><th>行为 / 来源</th><th>排序/状态</th><th>编辑</th></tr></thead>
                    <tbody>
                    <?php foreach ($groups as $g): ?>
                        <tr id="pa2-group-<?=mc_h($g['group_code'])?>">
                            <td><code><?=mc_h($g['group_code'])?></code></td>
                            <td><strong><?=mc_h($g['icon'] ? $g['icon'] . ' ' : '')?><?=mc_h($g['group_name'])?></strong><br><span class="pa2-muted"><?=mc_h($g['description'] ?? '')?></span></td>
                            <td><?=mc_h($pa2GroupTypeLabels[$g['group_type']] ?? $g['group_type'])?></td>
                            <td>
                                <div class="pa2-options"><?php foreach ($g['options'] as $o): ?><span><?=mc_h($o['option_name'])?><?=((int)$o['is_default']===1?' · 默认':'')?></span><?php endforeach; ?></div>
                                <?php if ($canManageGroup && in_array($g['group_type'], ['enum_select','hybrid_select','boolean'], true)): ?>
                                <button class="mc-button" type="button" data-open-option-create data-group-id="<?=intval($g['id'])?>" data-group-name="<?=mc_h($g['group_name'])?>">新增选项</button>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php $behavior = $g['behavior'] ?? null; ?>
                                <?php if ($behavior): ?>
                                    <?php
                                    $selectionKind = (string)($behavior['selection_kind'] ?? '');
                                    $sourceMode = (string)($behavior['source_mode'] ?? '');
                                    $categoryCode = (string)($behavior['material_category_code'] ?? '');
                                    $selectionMode = (string)($behavior['selection_mode_default'] ?? '');
                                    $filterSummary = !empty($behavior['material_filter']) && is_array($behavior['material_filter']) ? pa2_material_filter_summary_cn($behavior['material_filter']) : [];
                                    ?>
                                    <div class="pa2-behavior">
                                        <div><span class="pa2-badge"><?=mc_h($pa2SelectionKindLabels[$selectionKind] ?? $selectionKind)?></span> <span class="pa2-chip"><?=mc_h($pa2SourceModeLabels[$sourceMode] ?? $sourceMode)?></span></div>
                                        <div class="pa2-muted">物料分类：<?=mc_h($categoryCode !== '' ? ($pa2MaterialCategoryLabels[$categoryCode] ?? $categoryCode) : '—')?> · <?=((int)$behavior['is_required_default']===1?'必选':'可选')?> · <?=mc_h($pa2SelectionModeLabels[$selectionMode] ?? $selectionMode)?> · <?=intval($behavior['min_select_default'])?>-<?=intval($behavior['max_select_default'])?></div>
                                        <?php if ($filterSummary): ?><details><summary>物料过滤器</summary><div class="pa2-filter-chips"><?php foreach ($filterSummary as $filterText): ?><span><?=mc_h($filterText)?></span><?php endforeach; ?></div><details><summary>查看原始条件</summary><pre class="pa2-json"><?=mc_h(pa2_json_encode($behavior['material_filter']))?></pre></details></details><?php endif; ?>
                                        <?php if (!empty($behavior['visibility_condition'])): ?><details><summary>显示条件</summary><pre class="pa2-json"><?=mc_h(pa2_json_encode($behavior['visibility_condition']))?></pre></details><?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="pa2-muted">未设置行为</span>
                                <?php endif; ?>
                                <?php if ($canManageRule): ?>
                                <button class="mc-button" type="button"
                                    data-open-behavior-edit
                                    data-group-id="<?=intval($g['id'])?>"
                                    data-group-name="<?=mc_h($g['group_name'])?>"
                                    data-selection-kind="<?=mc_h($behavior['selection_kind'] ?? 'material')?>"
                                    data-source-mode="<?=mc_h($behavior['source_mode'] ?? 'official_material')?>"
                                    data-material-category-code="<?=mc_h($behavior['material_category_code'] ?? '')?>"
                                    data-is-required="<?=intval($behavior['is_required_default'] ?? 0)?>"
                                    data-selection-mode="<?=mc_h($behavior['selection_mode_default'] ?? 'single')?>"
                                    data-min-select="<?=intval($behavior['min_select_default'] ?? 0)?>"
                                    data-max-select="<?=intval($behavior['max_select_default'] ?? 1)?>"
                                    data-default-rule="<?=mc_h(isset($behavior['default_rule']) ? pa2_json_encode($behavior['default_rule']) : '')?>"
                                    data-material-filter="<?=mc_h(isset($behavior['material_filter']) ? pa2_json_encode($behavior['material_filter']) : '')?>"
                                    data-visibility-condition="<?=mc_h(isset($behavior['visibility_condition']) ? pa2_json_encode($behavior['visibility_condition']) : '')?>">编辑行为</button>
                                <?php endif; ?>
                            </td>
                            <td><?=intval($g['sort_order'])?> · <?=((int)$g['is_enabled'] === 1 ? '启用' : '停用')?></td>
                            <td>
                                <?php if ($canManageGroup): ?>
                                <button class="mc-button" type="button"
                                    data-open-group-edit
                                    data-group-id="<?=intval($g['id'])?>"
                                    data-group-code="<?=mc_h($g['group_code'])?>"
                                    data-group-name="<?=mc_h($g['group_name'])?>"
                                    data-group-type="<?=mc_h($g['group_type'])?>"
                                    data-sort-order="<?=intval($g['sort_order'])?>"
                                    data-is-enabled="<?=intval($g['is_enabled'])?>">编辑</button>
                                <?php else: ?>只读<?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php elseif ($view === 'templates'): ?>
        <section class="pa2-panel">
            <div class="pa2-panel__head">
                <div><h2>模板中心</h2><p>模板决定产品需要哪些配置组；具体产品后续只保存差异，不从零配置。</p></div>
                <div class="pa2-template-actions">
                    <?php if ($canManageTemplate): ?><button class="mc-button mc-button--primary" type="button" data-open-template-create>新增模板</button><?php endif; ?>
                    <?php if ($selectedTemplate): ?><a class="mc-button" href="<?=mc_h(pa2_view_url('template_editor', ['template_id' => (int)$selectedTemplate['id']]))?>">打开模板编辑器</a><?php endif; ?>
                </div>
            </div>
            <div class="pa2-panel__body pa2-section-gap">
                <?php if ($canManageTemplate): ?>
                <dialog class="pa2-dialog" id="pa2-template-create-dialog">
                    <div class="pa2-dialog__head">
                        <div><h3>新增配置模板</h3><p>先建立模板基础资料，再进入模板编辑器配置结构。</p></div>
                        <button class="mc-button" type="button" data-close-template-create>关闭</button>
                    </div>
                    <form data-pa2-form action="<?=mc_h(mc_url('adaptation_v2/api/index.php?action=template_save'))?>">
                        <div class="pa2-dialog__body">
                            <div class="pa2-dialog-form">
                                <label><span>模板编码</span><input name="template_code" placeholder="例如 track_light_standard"></label>
                                <label><span>模板名称 *</span><input name="template_name" required placeholder="例如 导轨灯标准模板"></label>
                                <label><span>模板层级</span><select name="template_level"><option value="category">分类模板</option><option value="system">系统模板</option><option value="series">系列模板</option><option value="product">产品模板</option></select></label>
                                <label><span>父模板</span><select name="parent_template_id"><option value="">无父模板</option><?php foreach ($templates as $t): ?><option value="<?=intval($t['id'])?>"><?=mc_h($t['template_name'])?></option><?php endforeach; ?></select></label>
                                <label><span>适用分类</span><select name="product_category_id"><option value="">不限定</option><?php foreach ($categories as $c): ?><option value="<?=intval($c['id'])?>"><?=mc_h($c['category_name'])?></option><?php endforeach; ?></select></label>
                                <label><span>系列编码</span><input name="series_code" placeholder="例如 ARTAX"></label>
                                <label class="full"><span>说明</span><input name="description" placeholder="说明继承范围、用途和注意事项"></label>
                            </div>
                        </div>
                        <div class="pa2-dialog__foot">
                            <span class="pa2-muted">保存后自动刷新模板列表。</span>
                            <div class="pa2-template-actions">
                                <button class="mc-button" type="button" data-close-template-create>取消</button>
                                <button class="mc-button mc-button--primary" type="submit">保存模板</button>
                            </div>
                        </div>
                    </form>
                </dialog>
                <?php endif; ?>
                <div class="pa2-template-shell">
                    <aside class="pa2-template-list">
                        <?php foreach ($templates as $t): ?>
                            <a class="pa2-template-item <?=((int)$t['id']===$selectedTemplateId?'is-active':'')?>" href="<?=mc_h(pa2_view_url('templates', ['template_id'=>(int)$t['id']]))?>">
                                <strong><?=mc_h($t['template_name'])?></strong>
                                <span><?=mc_h($t['template_level'])?> · <?=mc_h($t['category_name'] ?: '全局')?> · <?=intval($t['direct_group_count'])?> 个直接组</span>
                                <span>父模板：<?=mc_h($t['parent_template_name'] ?: '无')?> · 版本：<?=mc_h($t['active_version_no'] ?: '未发布')?></span>
                            </a>
                        <?php endforeach; ?>
                    </aside>
                    <section class="pa2-panel">
                        <div class="pa2-panel__head"><div><h2><?=mc_h($selectedTemplate['template_name'] ?? '选择模板')?></h2><p><?=mc_h($selectedTemplate['description'] ?? '选择左侧模板查看继承结果。')?></p></div></div>
                        <div class="pa2-panel__body pa2-section-gap">
                            <div class="pa2-flow"><?php foreach ($selectedTemplatePreview['chain'] as $node): ?><span><?=mc_h($node['template_name'])?></span><?php endforeach; ?></div>
                            <div class="pa2-group-grid">
                                <?php foreach ($selectedTemplatePreview['groups'] as $g): ?>
                                    <article class="pa2-group-card"><div><strong><?=mc_h($g['display_name'])?></strong><br><small><?=mc_h($g['group_code'])?> · <?=mc_h($g['display_type'])?> · 来源：<?=mc_h($g['source_template_name'])?></small></div><div><span class="pa2-badge pa2-badge--<?=mc_h($g['effective_change_type'])?>"><?=mc_h($g['effective_change_type'])?></span></div></article>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </section>
                    <aside class="pa2-side-note">
                        <strong>当前模板摘要</strong>
                        <p>直接配置组：<?=count($selectedTemplateGroups)?> 个</p>
                        <p>继承后有效配置组：<?=count($selectedTemplatePreview['groups'])?> 个</p>
                        <p>状态：<?=mc_h($selectedTemplate['status'] ?? '—')?> · 版本：<?=mc_h($selectedTemplate['active_version_no'] ?? '未发布')?></p>
                        <?php if ($selectedTemplate): ?><a class="mc-button mc-button--primary" href="<?=mc_h(pa2_view_url('template_editor', ['template_id'=>(int)$selectedTemplate['id']]))?>">编辑结构</a><?php endif; ?>
                    </aside>
                </div>
            </div>
        </section>
    <?php elseif ($view === 'template_editor'): ?>
        <section class="pa2-template-shell">
            <aside class="pa2-panel">
                <div class="pa2-panel__head"><div><h2>模板导航</h2><p>先选模板，再维护结构。</p></div></div>
                <div class="pa2-panel__body pa2-template-list">
                    <?php foreach ($templates as $t): ?>
                        <a class="pa2-template-item <?=((int)$t['id']===$selectedTemplateId?'is-active':'')?>" href="<?=mc_h(pa2_view_url('template_editor', ['template_id'=>(int)$t['id']]))?>"><strong><?=mc_h($t['template_name'])?></strong><span><?=mc_h($t['template_level'])?> · <?=mc_h($t['active_version_no'] ?: '未发布')?></span></a>
                    <?php endforeach; ?>
                </div>
            </aside>
            <section class="pa2-panel">
                <div class="pa2-panel__head">
                    <div><h2><?=mc_h($selectedTemplate['template_name'] ?? '模板编辑器')?></h2><p>配置组按 group_code 合并；子模板可以新增、覆盖或禁用父模板配置组。</p></div>
                    <div class="pa2-template-actions"><?php if ($selectedTemplate && $canPublishTemplate): ?><form data-pa2-form action="<?=mc_h(mc_url('adaptation_v2/api/index.php?action=template_publish'))?>"><input type="hidden" name="template_id" value="<?=intval($selectedTemplate['id'])?>"><button class="mc-button mc-button--primary" type="submit">发布版本</button></form><?php endif; ?></div>
                </div>
                <div class="pa2-panel__body pa2-section-gap">
                    <?php if ($selectedTemplate && $canManageTemplate): ?>
                    <form class="pa2-form" data-pa2-form action="<?=mc_h(mc_url('adaptation_v2/api/index.php?action=template_save'))?>">
                        <input type="hidden" name="id" value="<?=intval($selectedTemplate['id'])?>">
                        <label><span>模板编码</span><input name="template_code" value="<?=mc_h($selectedTemplate['template_code'])?>"></label>
                        <label><span>模板名称</span><input name="template_name" value="<?=mc_h($selectedTemplate['template_name'])?>" required></label>
                        <label><span>模板层级</span><select name="template_level"><?php foreach (['system'=>'系统','category'=>'分类','series'=>'系列','product'=>'产品'] as $k=>$v): ?><option value="<?=mc_h($k)?>" <?=($selectedTemplate['template_level']===$k?'selected':'')?>><?=mc_h($v)?></option><?php endforeach; ?></select></label>
                        <label><span>父模板</span><select name="parent_template_id"><option value="">无父模板</option><?php foreach ($templates as $t): if ((int)$t['id']===(int)$selectedTemplate['id']) continue; ?><option value="<?=intval($t['id'])?>" <?=((int)($selectedTemplate['parent_template_id'] ?? 0)===(int)$t['id']?'selected':'')?>><?=mc_h($t['template_name'])?></option><?php endforeach; ?></select></label>
                        <label><span>适用分类</span><select name="product_category_id"><option value="">不限定</option><?php foreach ($categories as $c): ?><option value="<?=intval($c['id'])?>" <?=((int)($selectedTemplate['product_category_id'] ?? 0)===(int)$c['id']?'selected':'')?>><?=mc_h($c['category_name'])?></option><?php endforeach; ?></select></label>
                        <label><span>系列编码</span><input name="series_code" value="<?=mc_h($selectedTemplate['series_code'] ?? '')?>"></label>
                        <label class="wide"><span>说明</span><input name="description" value="<?=mc_h($selectedTemplate['description'] ?? '')?>"></label>
                        <label><span>状态</span><select name="is_enabled"><option value="1" <?=((int)$selectedTemplate['is_enabled']===1?'selected':'')?>>启用</option><option value="0" <?=((int)$selectedTemplate['is_enabled']===0?'selected':'')?>>停用</option></select></label>
                        <button class="mc-button" type="submit">保存模板</button>
                    </form>
                    <form class="pa2-form" data-pa2-form data-template-group-form action="<?=mc_h(mc_url('adaptation_v2/api/index.php?action=template_group_save'))?>">
                        <input type="hidden" name="template_id" value="<?=intval($selectedTemplate['id'])?>">
                        <div class="pa2-dialog-hint full"><strong data-template-group-mode>新增或覆盖配置组</strong>：点击下方已有配置组的“编辑”，会把当前设置回填到这里，修改后直接保存。</div>
                        <label><span>配置组</span><select name="group_definition_id" required><?php foreach ($groups as $g): ?><option value="<?=intval($g['id'])?>"><?=mc_h($g['group_name'])?> · <?=mc_h($g['group_code'])?></option><?php endforeach; ?></select></label>
                        <label><span>继承动作</span><select name="inheritance_action"><option value="add">新增/使用</option><option value="override">覆盖父模板</option><option value="disable">禁用父模板组</option></select></label>
                        <label><span>选择方式</span><select name="selection_mode"><option value="single">单选</option><option value="multiple">多选</option></select></label>
                        <label><span>排序</span><input type="number" name="sort_order" value="100"></label>
                        <label><span>最少</span><input type="number" name="min_select" value="0"></label>
                        <label><span>最多</span><input type="number" name="max_select" value="1"></label>
                        <label><span>必选</span><select name="is_required"><option value="0">可选</option><option value="1">必选</option></select></label>
                        <label><span>允许为空</span><select name="allow_empty"><option value="1">允许</option><option value="0">不允许</option></select></label>
                        <label><span>影响价格</span><select name="affects_price"><option value="0">否</option><option value="1">是</option></select></label>
                        <label><span>影响交期</span><select name="affects_lead_time"><option value="0">否</option><option value="1">是</option></select></label>
                        <label><span>需要审批</span><select name="requires_approval"><option value="0">否</option><option value="1">是</option></select></label>
                        <button class="mc-button" type="button" data-template-group-reset>清空为新增</button>
                        <button class="mc-button mc-button--primary" type="submit" data-template-group-submit>保存配置组设置</button>
                    </form>
                    <?php endif; ?>
                    <div class="pa2-group-grid">
                        <?php foreach ($selectedTemplateGroups as $g): ?>
                            <?php
                                $templateGroupAction = (string)$g['inheritance_action'];
                                $templateGroupBadge = $templateGroupAction === 'disable' ? 'disable' : ($templateGroupAction === 'override' ? 'override' : 'add');
                                $templateGroupNextAction = $templateGroupAction === 'disable' ? 'add' : 'disable';
                            ?>
                            <article class="pa2-group-card">
                                <div>
                                    <strong><?=mc_h($g['display_name'])?></strong><br>
                                    <small><?=mc_h($g['group_code'])?> · <?=mc_h($pa2InheritanceActionLabels[$templateGroupAction] ?? $templateGroupAction)?> · <?=($g['is_required']?'必选':'可选')?> · <?=mc_h($pa2SelectionModeLabels[$g['selection_mode']] ?? $g['selection_mode'])?> · <?=intval($g['min_select'])?>-<?=intval($g['max_select'])?> 项 · <?=((int)$g['allow_empty']===1?'允许为空':'不允许为空')?> · 价格<?=((int)$g['affects_price']===1?'是':'否')?> · 交期<?=((int)$g['affects_lead_time']===1?'是':'否')?> · 审批<?=((int)$g['requires_approval']===1?'是':'否')?> · 排序 <?=intval($g['sort_order'])?></small>
                                </div>
                                <div class="pa2-template-actions">
                                    <span class="pa2-badge pa2-badge--<?=mc_h($templateGroupBadge)?>"><?=mc_h($pa2InheritanceActionLabels[$templateGroupAction] ?? $templateGroupAction)?></span>
                                    <?php if ($canManageTemplate): ?>
                                    <button
                                        class="mc-button"
                                        type="button"
                                        data-template-group-edit
                                        data-group-name="<?=mc_h($g['display_name'])?>"
                                        data-group-definition-id="<?=intval($g['group_definition_id'])?>"
                                        data-inheritance-action="<?=mc_h($templateGroupAction)?>"
                                        data-selection-mode="<?=mc_h($g['selection_mode'])?>"
                                        data-sort-order="<?=intval($g['sort_order'])?>"
                                        data-min-select="<?=intval($g['min_select'])?>"
                                        data-max-select="<?=intval($g['max_select'])?>"
                                        data-is-required="<?=intval($g['is_required'])?>"
                                        data-allow-empty="<?=intval($g['allow_empty'])?>"
                                        data-affects-price="<?=intval($g['affects_price'])?>"
                                        data-affects-lead-time="<?=intval($g['affects_lead_time'])?>"
                                        data-requires-approval="<?=intval($g['requires_approval'])?>"
                                    >编辑</button>
                                    <form data-pa2-form data-confirm="<?=mc_h($templateGroupAction === 'disable' ? '确认重新加入这个配置组？' : '确认从当前模板移除这个配置组？右侧继承预览会立即不再显示它。')?>" action="<?=mc_h(mc_url('adaptation_v2/api/index.php?action=template_group_save'))?>">
                                        <input type="hidden" name="template_id" value="<?=intval($selectedTemplate['id'])?>">
                                        <input type="hidden" name="group_definition_id" value="<?=intval($g['group_definition_id'])?>">
                                        <input type="hidden" name="inheritance_action" value="<?=mc_h($templateGroupNextAction)?>">
                                        <input type="hidden" name="selection_mode" value="<?=mc_h($g['selection_mode'])?>">
                                        <input type="hidden" name="sort_order" value="<?=intval($g['sort_order'])?>">
                                        <input type="hidden" name="min_select" value="<?=intval($g['min_select'])?>">
                                        <input type="hidden" name="max_select" value="<?=intval($g['max_select'])?>">
                                        <input type="hidden" name="is_required" value="<?=intval($g['is_required'])?>">
                                        <input type="hidden" name="allow_empty" value="<?=intval($g['allow_empty'])?>">
                                        <input type="hidden" name="affects_price" value="<?=intval($g['affects_price'])?>">
                                        <input type="hidden" name="affects_lead_time" value="<?=intval($g['affects_lead_time'])?>">
                                        <input type="hidden" name="requires_approval" value="<?=intval($g['requires_approval'])?>">
                                        <button class="mc-button" type="submit"><?=($templateGroupAction === 'disable' ? '重新加入' : '移除')?></button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
            <aside class="pa2-panel">
                <div class="pa2-panel__head"><div><h2>继承预览</h2><p>套用前先看最终有效结构。</p></div></div>
                <div class="pa2-panel__body pa2-section-gap">
                    <div class="pa2-flow"><?php foreach ($selectedTemplatePreview['chain'] as $node): ?><span><?=mc_h($node['template_name'])?></span><?php endforeach; ?></div>
                    <div class="pa2-side-note">有效配置组 <?=count($selectedTemplatePreview['groups'])?> 个；版本 <?=mc_h($selectedTemplate['active_version_no'] ?? '未发布')?>。发布会保存当前继承结果快照。</div>
                    <div class="pa2-group-grid"><?php foreach ($selectedTemplatePreview['groups'] as $g): ?><div class="pa2-group-card"><div><strong><?=mc_h($g['display_name'])?></strong><br><small><?=mc_h($g['source_template_name'])?></small></div><span class="pa2-badge"><?=mc_h($g['group_code'])?></span></div><?php endforeach; ?></div>
                </div>
            </aside>
        </section>
    <?php elseif ($view === 'rules'): ?>
        <section class="pa2-rule-board">
            <section class="pa2-panel">
                <div class="pa2-panel__head">
                    <div><h2>规则编辑器</h2><p>用“触发配置组 → 目标配置组”的方式维护显示条件、物料过滤、默认项和数量限制；保存时自动做循环检测。</p></div>
                    <span class="pa2-pill <?=empty($cycleCheck['cycles'])?'pa2-pill--ok':'pa2-pill--warn'?>"><?=empty($cycleCheck['cycles'])?'无循环':'发现循环 '.count($cycleCheck['cycles']).' 个'?></span>
                </div>
                <div class="pa2-panel__body pa2-section-gap">
                    <?php foreach ($rules as $rule): ?>
                        <article class="pa2-rule-card <?=!empty($rule['has_cycle'])?'is-cycle':''?>">
                            <div class="pa2-rule-line">
                                <strong><?=mc_h($rule['rule_name'])?></strong>
                                <span class="pa2-chip"><?=mc_h($rule['template_name'] ?: ($rule['category_name'] ?: '全局'))?></span>
                                <span class="pa2-chip <?=in_array($rule['effect_action'], ['show','require'], true)?'pa2-chip--show':(in_array($rule['effect_action'], ['hide','optional'], true)?'pa2-chip--hide':'pa2-chip--filter')?>"><?=mc_h($rule['effect_action'])?></span>
                            </div>
                            <div class="pa2-rule-line">
                                <code><?=mc_h($rule['trigger_group_code'])?></code>
                                <span><?=mc_h($rule['trigger_operator'])?></span>
                                <code><?=mc_h($rule['trigger_value'] ?: '—')?></code>
                                <span>→</span>
                                <code><?=mc_h($rule['target_group_code'])?></code>
                            </div>
                            <p class="pa2-muted"><?=mc_h($rule['description'] ?? '')?></p>
                            <?php if (!empty($rule['effect'])): ?><pre class="pa2-json"><?=mc_h(pa2_json_encode($rule['effect']))?></pre><?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
            <aside class="pa2-panel">
                <div class="pa2-panel__head"><div><h2>新增 / 修改规则</h2><p>红色只留给循环和阻断；普通显示隐藏用柔和状态色。</p></div></div>
                <div class="pa2-panel__body pa2-section-gap">
                    <?php if ($canManageRule): ?>
                    <form class="pa2-form" data-pa2-form action="<?=mc_h(mc_url('adaptation_v2/api/index.php?action=rule_save'))?>">
                        <label class="wide"><span>规则编码</span><input name="rule_code" placeholder="例如 track_intrack_show_driver"></label>
                        <label class="wide"><span>规则名称 *</span><input name="rule_name" required placeholder="选择 INTRACK 时显示 INTRACK 电源"></label>
                        <label><span>模板范围</span><select name="template_id"><option value="">全局 / 分类</option><?php foreach ($templates as $t): ?><option value="<?=intval($t['id'])?>"><?=mc_h($t['template_name'])?></option><?php endforeach; ?></select></label>
                        <label><span>产品分类</span><select name="product_category_id"><option value="">不限定</option><?php foreach ($categories as $c): ?><option value="<?=intval($c['id'])?>"><?=mc_h($c['category_name'])?></option><?php endforeach; ?></select></label>
                        <label><span>触发配置组</span><select name="trigger_group_code" required><?php foreach ($groups as $g): ?><option value="<?=mc_h($g['group_code'])?>"><?=mc_h($g['group_name'])?> · <?=mc_h($g['group_code'])?></option><?php endforeach; ?></select></label>
                        <label><span>判断</span><select name="trigger_operator"><option value="eq">等于</option><option value="neq">不等于</option><option value="in">包含于</option><option value="not_in">不包含于</option><option value="exists">有值</option><option value="empty">为空</option></select></label>
                        <label><span>触发值</span><input name="trigger_value" placeholder="例如 intrack"></label>
                        <label><span>目标配置组</span><select name="target_group_code" required><?php foreach ($groups as $g): ?><option value="<?=mc_h($g['group_code'])?>"><?=mc_h($g['group_name'])?> · <?=mc_h($g['group_code'])?></option><?php endforeach; ?></select></label>
                        <label><span>动作</span><select name="effect_action"><option value="show">显示</option><option value="hide">隐藏</option><option value="require">设为必选</option><option value="optional">设为可选</option><option value="material_filter">物料过滤</option><option value="set_default">设置默认项</option><option value="limit_options">限制选项</option></select></label>
                        <label><span>优先级</span><input type="number" name="priority" value="100"></label>
                        <label><span>状态</span><select name="is_enabled"><option value="1">启用</option><option value="0">停用</option></select></label>
                        <label class="full"><span>效果 JSON</span><textarea name="effect_json" rows="3" placeholder='例如 {"keyword":"短款"}'></textarea></label>
                        <label class="full"><span>说明</span><input name="description" placeholder="规则业务原因，便于审批和后续排错"></label>
                        <button class="mc-button mc-button--primary" type="submit">保存规则并检测循环</button>
                    </form>
                    <?php else: ?>
                        <div class="pa2-alert">当前账号只能查看规则，没有维护规则权限。</div>
                    <?php endif; ?>
                    <div class="pa2-side-note">
                        <strong>验收示例已内置</strong>
                        <p>导轨灯选择 <code>INTRACK</code>：显示 INTRACK 接头、电源；隐藏普通接头、普通内置电源。</p>
                        <p>磁吸灯选择 <code>短款</code>：对磁吸头执行短款物料过滤。</p>
                    </div>
                    <?php if (!empty($cycleCheck['cycles'])): ?>
                        <div class="pa2-alert">循环链：<?=mc_h(pa2_json_encode($cycleCheck['cycles']))?></div>
                    <?php endif; ?>
                </div>
            </aside>
        </section>
    <?php elseif ($view === 'workspace'): ?>
        <?php if ($workspaceProductId <= 0): ?>
            <section class="pa2-panel">
                <div class="pa2-panel__head"><div><h2>选择产品进入工作台</h2><p>第 5 阶段工作台按产品打开；先从产品列表选择一个产品。</p></div></div>
                <div class="pa2-panel__body pa2-section-gap">
                    <form class="pa2-form" method="get" action="<?=mc_h(mc_url('adaptation_v2/index.php'))?>">
                        <input type="hidden" name="view" value="workspace">
                        <label class="wide"><span>搜索产品</span><input name="q" value="<?=mc_h((string)($_GET['q'] ?? ''))?>" placeholder="型号 / 名称 / 旧分类"></label>
                        <button class="mc-button" type="submit">搜索</button>
                    </form>
                    <table class="pa2-table">
                        <thead><tr><th>产品</th><th>V2 分类</th><th>系列</th><th>操作</th></tr></thead>
                        <tbody>
                        <?php foreach ($products as $p): ?>
                            <tr>
                                <td><strong><?=mc_h($p['product_code'] ?: ('#'.$p['id']))?></strong><br><?=mc_h($p['product_name'] ?? '')?></td>
                                <td><?=mc_h($p['category_name'] ?: '未映射')?></td>
                                <td><?=mc_h($p['series_code'] ?: $p['series_name'] ?: '—')?></td>
                                <td><a class="mc-button mc-button--primary" href="<?=mc_h(pa2_view_url('workspace', ['product_id'=>(int)$p['id']]))?>">打开工作台</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php elseif (!empty($workspace['error'])): ?>
            <div class="pa2-alert"><?=mc_h($workspace['error'])?></div>
        <?php else: ?>
            <?php
                $wpProduct = $workspace['product'] ?? [];
                $wpConfig = $workspace['config'] ?? null;
                $wpVersion = $workspace['version'] ?? null;
                $wpTemplate = $workspace['template'] ?? null;
                $wpGroups = $workspace['groups'] ?? [];
                $wpSummary = $workspace['check_summary'] ?? ['missing_required'=>0,'completed_required'=>0,'required_total'=>0];
                $wpEngineSummary = $wpSummary['engine'] ?? ['candidate_total'=>0,'full_match'=>0,'conditional_match'=>0,'approval_required'=>0,'incompatible'=>0,'average_score'=>0,'last_calculated_at'=>null];
                $wpLifecycle = $workspaceProductId > 0 ? pa2_product_versions($workspaceProductId) : ['versions'=>[],'events'=>[]];
                $wpVersionStatus = (string)($wpVersion['status'] ?? '');
                $wpCanEditVersion = in_array($wpVersionStatus, ['draft','rejected'], true);
                $wpVersionLabel = [
                    'draft' => '草稿',
                    'submitted' => '待审批',
                    'approved' => '已审批',
                    'rejected' => '已驳回',
                    'published' => '已发布',
                ][$wpVersionStatus] ?? ($wpVersionStatus ?: '未生成');
            ?>
            <section class="pa2-workspace">
                <div class="pa2-product-hero">
                    <?php if (!empty($wpProduct['image_url'])): ?><img src="<?=mc_h($wpProduct['image_url'])?>" alt=""><?php else: ?><div class="pa2-card" style="width:88px;height:88px;display:grid;place-items:center">无图</div><?php endif; ?>
                    <div>
                        <div class="mc-breadcrumb">Artdon ERP / 物料中心 / 产品适配 V2 / 单产品配置工作台</div>
                        <h2><?=mc_h($wpProduct['product_code'] ?: ('#'.$workspaceProductId))?>　<?=mc_h($wpProduct['product_name'] ?? '')?></h2>
                        <p class="pa2-muted">V2 分类：<?=mc_h($wpProduct['category_name'] ?: '未映射')?> · 系列：<?=mc_h($wpProduct['series_code'] ?: $wpProduct['series_name'] ?: '—')?> · 模板：<?=mc_h($wpTemplate['template_name'] ?? '待生成')?> · 版本：<?=mc_h(($wpVersion['version_no'] ?? '—').' / '.$wpVersionLabel)?></p>
                    </div>
                    <div class="pa2-template-actions">
                        <?php if ($canConfigureProduct): ?>
                            <button class="mc-button" type="button" data-open-workspace-category>设置分类</button>
                            <button class="mc-button mc-button--primary" type="button" data-open-workspace-template>套用模板</button>
                        <?php endif; ?>
                        <?php if (!$wpConfig): ?>
                            <form data-pa2-form action="<?=mc_h(mc_url('adaptation_v2/api/index.php?action=workspace_prepare'))?>"><input type="hidden" name="product_id" value="<?=intval($workspaceProductId)?>"><button class="mc-button mc-button--primary" type="submit">生成配置草稿</button></form>
                        <?php else: ?>
                            <span class="pa2-pill pa2-pill--ok"><?=mc_h($wpVersionLabel)?></span>
                            <?php if (($wpConfig['active_draft_version_id'] ?? null) === null && !empty($wpConfig['active_published_version_id'])): ?>
                                <form data-pa2-form action="<?=mc_h(mc_url('adaptation_v2/api/index.php?action=workspace_prepare'))?>"><input type="hidden" name="product_id" value="<?=intval($workspaceProductId)?>"><button class="mc-button mc-button--primary" type="submit">生成下一版草稿</button></form>
                            <?php endif; ?>
                        <?php endif; ?>
                        <a class="mc-button" href="<?=mc_h(pa2_view_url('products'))?>">返回产品列表</a>
                    </div>
                </div>
                <?php if ($canConfigureProduct): ?>
                <dialog class="pa2-dialog pa2-dialog--narrow" id="pa2-workspace-category-dialog">
                    <div class="pa2-dialog__head">
                        <div><h3>设置当前产品分类</h3><p>用于这一个产品的 V2 模板匹配，不修改旧版产品适配和旧 BOM。</p></div>
                        <button class="mc-button" type="button" data-close-workspace-category>关闭</button>
                    </div>
                    <form data-pa2-form action="<?=mc_h(mc_url('adaptation_v2/api/index.php?action=workspace_source_save'))?>">
                        <input type="hidden" name="product_id" value="<?=intval($workspaceProductId)?>">
                        <div class="pa2-dialog__body">
                            <div class="pa2-dialog-form">
                                <div class="pa2-dialog-hint full">例如嵌入式灯：先选择“嵌入式”分类；如有专用模板，可同时选择“嵌入式模板”。保存后会补齐该模板里的配置组，已选配置不清空。</div>
                                <label><span>产品分类 *</span><select name="category_id" required><option value="">请选择分类</option><?php foreach ($categories as $c): ?><option value="<?=intval($c['id'])?>" <?=((int)($wpProduct['category_id'] ?? 0)===(int)$c['id']?'selected':'')?>><?=mc_h($c['category_name'])?></option><?php endforeach; ?></select></label>
                                <label><span>系列编码</span><input name="series_code" value="<?=mc_h($wpProduct['series_code'] ?: $wpProduct['series_name'] ?: '')?>" placeholder="例如 RECESSED"></label>
                                <label class="full"><span>同时套用模板</span><select name="template_id"><option value="">按分类自动匹配模板</option><?php foreach ($templates as $t): ?><?php if ((int)($t['is_enabled'] ?? 1) !== 1) continue; ?><option value="<?=intval($t['id'])?>" <?=((int)($wpTemplate['id'] ?? 0)===(int)$t['id']?'selected':'')?>><?=mc_h($t['template_name'])?> · <?=mc_h($t['template_level'])?> · <?=mc_h($t['category_name'] ?: '全局')?></option><?php endforeach; ?></select></label>
                            </div>
                        </div>
                        <div class="pa2-dialog__foot">
                            <span class="pa2-muted">保存后自动生成/刷新当前产品草稿。</span>
                            <div class="pa2-template-actions">
                                <button class="mc-button" type="button" data-close-workspace-category>取消</button>
                                <button class="mc-button mc-button--primary" type="submit">保存并刷新工作台</button>
                            </div>
                        </div>
                    </form>
                </dialog>
                <dialog class="pa2-dialog pa2-dialog--narrow" id="pa2-workspace-template-dialog">
                    <div class="pa2-dialog__head">
                        <div><h3>套用配置模板</h3><p>把当前产品切换到指定模板；只补齐模板配置组，不删除已选物料和属性。</p></div>
                        <button class="mc-button" type="button" data-close-workspace-template>关闭</button>
                    </div>
                    <form data-pa2-form action="<?=mc_h(mc_url('adaptation_v2/api/index.php?action=workspace_source_save'))?>">
                        <input type="hidden" name="product_id" value="<?=intval($workspaceProductId)?>">
                        <input type="hidden" name="series_code" value="<?=mc_h($wpProduct['series_code'] ?: $wpProduct['series_name'] ?: '')?>">
                        <div class="pa2-dialog__body">
                            <div class="pa2-dialog-form">
                                <div class="pa2-dialog-hint full">适合你说的场景：这只灯是嵌入式，就直接在这里选择“嵌入式模板”。如果模板本身绑定了分类，系统会同步写入这只产品的 V2 分类。</div>
                                <label class="full"><span>配置模板 *</span><select name="template_id" required><option value="">请选择模板</option><?php foreach ($templates as $t): ?><?php if ((int)($t['is_enabled'] ?? 1) !== 1) continue; ?><option value="<?=intval($t['id'])?>" <?=((int)($wpTemplate['id'] ?? 0)===(int)$t['id']?'selected':'')?>><?=mc_h($t['template_name'])?> · <?=mc_h($t['template_level'])?> · <?=mc_h($t['category_name'] ?: '全局')?> · <?=intval($t['direct_group_count'] ?? 0)?> 组</option><?php endforeach; ?></select></label>
                                <label class="full"><span>处理方式</span><input value="保留当前已选配置，只补齐模板新增配置组" disabled></label>
                            </div>
                        </div>
                        <div class="pa2-dialog__foot">
                            <span class="pa2-muted">不会修改模板本身，也不会修改旧 BOM。</span>
                            <div class="pa2-template-actions">
                                <button class="mc-button" type="button" data-close-workspace-template>取消</button>
                                <button class="mc-button mc-button--primary" type="submit">套用并刷新工作台</button>
                            </div>
                        </div>
                    </form>
                </dialog>
                <?php endif; ?>
                <div class="pa2-steps">
                    <div class="pa2-step"><b>1</b><strong>确认配置来源</strong><p class="pa2-muted">来自 <?=mc_h($wpTemplate['template_name'] ?? '模板待匹配')?>，继承结果会生成草稿配置组。</p></div>
                    <div class="pa2-step"><b>2</b><strong>设置核心配置</strong><p class="pa2-muted">芯片、电源、光学、导轨系统等优先处理；复杂选择打开宽版弹窗。</p></div>
                    <div class="pa2-step"><b>3</b><strong>计算和保存</strong><p class="pa2-muted">候选物料显示适配结论、匹配度和冲突原因；审批发布留第 7 阶段。</p></div>
                </div>
                <?php if (!$wpConfig): ?>
                    <div class="pa2-alert">当前产品还没有 V2 配置草稿。点击“生成配置草稿”后，系统会按模板继承结果生成配置组，不会修改旧版适配或旧 BOM。</div>
                <?php else: ?>
                    <div class="pa2-work-grid">
                    <?php foreach ($wpGroups as $g): ?>
                        <?php
                            $settings = $g['effective_settings'] ?? [];
                            $required = !empty($settings['is_required']) || !empty($settings['is_required_default']);
                            $selected = $g['selected_options'] ?? [];
                            $done = count($selected) > 0;
                            $sourceMode = (string)($g['source_mode'] ?? '');
                            $selectionKind = (string)($g['selection_kind'] ?? '');
                            $definition = $groupsById[(int)$g['group_definition_id']] ?? null;
                            $groupResults = $g['adaptation_results'] ?? [];
                            $primaryResult = $groupResults[0] ?? null;
                            if (!$primaryResult && !empty($selected[0]['adaptation_result'])) $primaryResult = $selected[0]['adaptation_result'];
                            $status = (string)($primaryResult['result_status'] ?? '');
                            $statusLabel = $status !== '' ? ($pa2ResultLabels[$status] ?? $status) : '待计算';
                            $statusClass = $pa2ResultBadge[$status] ?? '';
                            $score = $primaryResult ? (float)($primaryResult['match_score'] ?? 0) : null;
                            $reasons = $primaryResult['reasons'] ?? [];
                        ?>
                        <article class="pa2-config-card <?=$done?'is-done':($required?'is-missing':'')?>">
                            <div class="pa2-config-card__head">
                                <div><strong><?=mc_h($g['icon'] ? $g['icon'].' ' : '')?><?=mc_h($g['display_name'])?></strong><br><small class="pa2-muted"><?=mc_h($g['group_code'])?> · <?=mc_h($selectionKind ?: $g['definition_type'])?> · <?=mc_h($required?'必选':'可选')?></small></div>
                                <span class="pa2-badge <?=$statusClass ?: ($done?'pa2-badge--add':($required?'pa2-badge--override':''))?>"><?=mc_h($primaryResult ? $statusLabel : ($done?'待计算':($required?'待补充':'可选')))?></span>
                            </div>
                            <div class="pa2-selected">
                                <?php if ($selected): foreach ($selected as $s): ?>
                                    <span><?=mc_h($s['material_code'] ?: $s['option_name'] ?: $s['numeric_value'] ?: $s['text_value'] ?: (($s['boolean_value'] ?? null) === null ? '已选择' : ((int)$s['boolean_value'] ? '是' : '否')))?> <?=mc_h($s['material_name'] ? ' · '.$s['material_name'] : '')?></span>
                                <?php endforeach; else: ?>
                                    <span class="pa2-muted">未选择，普通配置可以缺什么补什么。</span>
                                <?php endif; ?>
                            </div>
                            <div class="pa2-result-note">
                                <?php if ($primaryResult): ?>
                                    <small><strong><?=mc_h($statusLabel)?></strong><?= $score !== null ? ' · '.mc_h((string)$score).'%' : '' ?></small>
                                    <small><?=mc_h($reasons ? (string)$reasons[0] : '已完成第 6 阶段适配计算。')?></small>
                                <?php else: ?>
                                    <small>尚未计算。保存选择或点击底部“重新计算”后生成结论。</small>
                                <?php endif; ?>
                            </div>
                            <?php if (!$wpCanEditVersion): ?>
                                <span class="pa2-muted">当前版本已锁定，如需修改请生成下一版草稿。</span>
                            <?php elseif (in_array($selectionKind, ['material','hybrid'], true) || in_array($g['definition_type'], ['material_select','hybrid_select'], true)): ?>
                                <button class="mc-button mc-button--primary" type="button" data-open-material-picker data-group-id="<?=intval($g['id'])?>" data-group-code="<?=mc_h($g['group_code'])?>" data-group-name="<?=mc_h($g['display_name'])?>">选择正式物料</button>
                            <?php elseif (($definition['options'] ?? [])): ?>
                                <form class="pa2-mini-form" data-pa2-form action="<?=mc_h(mc_url('adaptation_v2/api/index.php?action=product_group_save'))?>">
                                    <input type="hidden" name="product_group_config_id" value="<?=intval($g['id'])?>">
                                    <input type="hidden" name="option_type" value="attribute">
                                    <select name="option_definition_id" required>
                                        <?php foreach ($definition['options'] as $o): ?><option value="<?=intval($o['id'])?>"><?=mc_h($o['option_name'])?></option><?php endforeach; ?>
                                    </select>
                                    <button class="mc-button" type="submit">保存</button>
                                </form>
                            <?php elseif (in_array($selectionKind, ['number'], true) || $g['definition_type'] === 'number_input'): ?>
                                <form class="pa2-mini-form" data-pa2-form action="<?=mc_h(mc_url('adaptation_v2/api/index.php?action=product_group_save'))?>">
                                    <input type="hidden" name="product_group_config_id" value="<?=intval($g['id'])?>">
                                    <input type="hidden" name="option_type" value="number">
                                    <input type="number" step="0.0001" name="numeric_value" placeholder="输入数值">
                                    <button class="mc-button" type="submit">保存</button>
                                </form>
                            <?php else: ?>
                                <form class="pa2-mini-form" data-pa2-form action="<?=mc_h(mc_url('adaptation_v2/api/index.php?action=product_group_save'))?>">
                                    <input type="hidden" name="product_group_config_id" value="<?=intval($g['id'])?>">
                                    <input type="hidden" name="option_type" value="text">
                                    <input name="text_value" placeholder="填写说明">
                                    <button class="mc-button" type="submit">保存</button>
                                </form>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                    </div>
                    <div class="pa2-footerbar">
                        <div>
                            <strong>需要补充 <?=intval($wpSummary['missing_required'] ?? 0)?> 项</strong>
                            <span class="pa2-muted">已完成 <?=intval($wpSummary['completed_required'] ?? 0)?> / <?=intval($wpSummary['required_total'] ?? 0)?> 个必选配置；可选项不阻断保存草稿。</span>
                            <div class="pa2-engine-summary">
                                <span>适配结果 <?=intval($wpEngineSummary['candidate_total'] ?? 0)?> 条</span>
                                <span>完全 <?=intval($wpEngineSummary['full_match'] ?? 0)?></span>
                                <span>条件 <?=intval($wpEngineSummary['conditional_match'] ?? 0)?></span>
                                <span>审批 <?=intval($wpEngineSummary['approval_required'] ?? 0)?></span>
                                <span>不适配 <?=intval($wpEngineSummary['incompatible'] ?? 0)?></span>
                                <?php if (!empty($wpEngineSummary['last_calculated_at'])): ?><span>最后计算 <?=mc_h($wpEngineSummary['last_calculated_at'])?></span><?php endif; ?>
                            </div>
                        </div>
                        <div class="pa2-template-actions">
                            <form data-pa2-form action="<?=mc_h(mc_url('adaptation_v2/api/index.php?action=workspace_recalculate'))?>"><input type="hidden" name="product_id" value="<?=intval($workspaceProductId)?>"><input type="hidden" name="reason" value="workspace_button"><button class="mc-button" type="submit">重新计算</button></form>
                            <a class="mc-button mc-button--primary" href="<?=mc_h(pa2_view_url('workspace', ['product_id'=>$workspaceProductId]))?>">保存草稿</a>
                            <?php if (in_array($wpVersionStatus, ['draft','rejected'], true)): ?>
                                <form data-pa2-form action="<?=mc_h(mc_url('adaptation_v2/api/index.php?action=product_version_submit'))?>"><input type="hidden" name="product_id" value="<?=intval($workspaceProductId)?>"><button class="mc-button mc-button--primary" type="submit">提交审批</button></form>
                            <?php elseif ($wpVersionStatus === 'submitted' && $canApproveProduct): ?>
                                <form data-pa2-form action="<?=mc_h(mc_url('adaptation_v2/api/index.php?action=product_version_approve'))?>"><input type="hidden" name="product_id" value="<?=intval($workspaceProductId)?>"><button class="mc-button mc-button--primary" type="submit">审批通过</button></form>
                                <form data-pa2-form action="<?=mc_h(mc_url('adaptation_v2/api/index.php?action=product_version_reject'))?>"><input type="hidden" name="product_id" value="<?=intval($workspaceProductId)?>"><button class="mc-button" type="submit">驳回</button></form>
                            <?php elseif ($wpVersionStatus === 'approved' && $canPublishProduct): ?>
                                <form data-pa2-form action="<?=mc_h(mc_url('adaptation_v2/api/index.php?action=product_version_publish'))?>"><input type="hidden" name="product_id" value="<?=intval($workspaceProductId)?>"><button class="mc-button mc-button--primary" type="submit">发布版本</button></form>
                            <?php else: ?>
                                <span class="pa2-muted">当前状态：<?=mc_h($wpVersionLabel)?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if (!empty($wpLifecycle['versions'])): ?>
                        <section class="pa2-panel">
                            <div class="pa2-panel__head"><div><h2>版本和审批记录</h2><p>产品级覆盖只保存在产品版本中，不修改模板；历史发布版本保留可回滚。</p></div></div>
                            <div class="pa2-panel__body">
                                <table class="pa2-table">
                                    <thead><tr><th>版本</th><th>状态</th><th>提交/审批/发布</th><th>操作</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($wpLifecycle['versions'] as $versionRow): ?>
                                        <tr>
                                            <td><strong><?=mc_h($versionRow['version_no'])?></strong><?=!empty($versionRow['is_active_published'])?' · 当前发布':''?><?=!empty($versionRow['is_active_draft'])?' · 当前草稿':''?></td>
                                            <td><?=mc_h($versionRow['status'])?></td>
                                            <td><?=mc_h(trim(($versionRow['submitted_at'] ?? '').' / '.($versionRow['approved_at'] ?? '').' / '.($versionRow['published_at'] ?? ''), ' /'))?></td>
                                            <td>
                                                <?php if (($versionRow['status'] ?? '') === 'published' && empty($versionRow['is_active_published']) && $canPublishProduct): ?>
                                                <form class="pa2-mini-form" data-pa2-form action="<?=mc_h(mc_url('adaptation_v2/api/index.php?action=product_version_rollback'))?>">
                                                    <input type="hidden" name="product_id" value="<?=intval($workspaceProductId)?>">
                                                    <input type="hidden" name="target_version_id" value="<?=intval($versionRow['id'])?>">
                                                    <button class="mc-button" type="submit">回滚到此版本</button>
                                                </form>
                                                <?php else: ?>
                                                    <span class="pa2-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
            <dialog class="pa2-dialog" id="pa2-material-dialog">
                <div class="pa2-dialog__head"><div><strong id="pa2-material-title">选择正式物料</strong><p class="pa2-muted">每条候选会即时显示完全适配、条件适配、需要审批或不适配，并给出明确原因。</p></div><button class="mc-button" type="button" data-close-material-picker>关闭</button></div>
                <div class="pa2-dialog__body">
                    <form class="pa2-mini-form" id="pa2-material-search"><input name="q" placeholder="搜索物料编号 / 名称 / 品牌 / 型号"><button class="mc-button" type="submit">搜索</button></form>
                    <div class="pa2-candidate-list" id="pa2-material-list"><div class="pa2-placeholder">正在等待选择配置组。</div></div>
                </div>
                <div class="pa2-dialog__foot"><span class="pa2-muted">宽版弹窗用于复杂物料选择，小屏也不挤主页面。</span><button class="mc-button" type="button" data-close-material-picker>取消</button></div>
            </dialog>
        <?php endif; ?>
    <?php elseif ($view === 'products'): ?>
        <section class="pa2-panel">
            <div class="pa2-panel__head"><div><h2>全部产品 / 分类映射</h2><p>本阶段只维护“产品 → V2 产品分类 / 系列”的基础映射，不做模板套用或工作台配置。</p></div></div>
            <div class="pa2-panel__body pa2-section-gap">
                <form class="pa2-form" method="get" action="<?=mc_h(mc_url('adaptation_v2/index.php'))?>">
                    <input type="hidden" name="view" value="products">
                    <label class="wide"><span>搜索产品</span><input name="q" value="<?=mc_h((string)($_GET['q'] ?? ''))?>" placeholder="型号 / 名称 / 旧分类"></label>
                    <button class="mc-button" type="submit">搜索</button>
                </form>
                <table class="pa2-table">
                    <thead><tr><th>产品</th><th>旧来源分类</th><th>V2 分类</th><th>系列编码</th><th>操作</th></tr></thead>
                    <tbody>
                    <?php foreach ($products as $p): ?>
                        <tr>
                            <td><strong><?=mc_h($p['product_code'] ?: ('#'.$p['id']))?></strong><br><?=mc_h($p['product_name'] ?? '')?></td>
                            <td><?=mc_h($p['legacy_category'] ?: '—')?><br><span class="pa2-muted"><?=mc_h($p['series_name'] ?: '')?></span></td>
                            <td><?=mc_h($p['category_name'] ?: '未映射')?></td>
                            <td><?=mc_h($p['series_code'] ?: '—')?></td>
                            <td>
                                <?php if ($canManageCategory): ?>
                                <form class="pa2-mini-form" data-pa2-form action="<?=mc_h(mc_url('adaptation_v2/api/index.php?action=product_map_save'))?>">
                                    <input type="hidden" name="product_id" value="<?=intval($p['id'])?>">
                                    <select name="category_id" required><option value="">选择分类</option><?php foreach ($categories as $c): ?><option value="<?=intval($c['id'])?>" <?=((int)($p['category_id'] ?? 0)===(int)$c['id']?'selected':'')?>><?=mc_h($c['category_name'])?></option><?php endforeach; ?></select>
                                    <input name="series_code" value="<?=mc_h($p['series_code'] ?: $p['series_name'])?>" placeholder="系列编码">
                                    <button class="mc-button" type="submit">保存映射</button>
                                </form>
                                <?php else: ?>只读<?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php elseif ($view === 'packages'): ?>
        <section class="pa2-package-shell">
            <aside class="pa2-panel">
                <div class="pa2-panel__head">
                    <div><h2>配置包中心</h2><p>按渠道沉淀可发布配置包；第 8 阶段只服务 V2，不暴露给旧版和正式菜单。</p></div>
                </div>
                <div class="pa2-panel__body pa2-package-list">
                    <?php foreach ($packages as $packageRow): ?>
                        <a class="pa2-package-item <?=((int)$packageRow['id']===$selectedPackageId?'is-active':'')?>" href="<?=mc_h(pa2_view_url('packages', ['package_id'=>(int)$packageRow['id']]))?>">
                            <strong><?=mc_h($packageRow['package_name'])?></strong>
                            <span class="pa2-muted"><?=mc_h($packageRow['package_code'])?> · <?=mc_h($packageRow['channel_code'])?> · <?=mc_h($packageRow['package_type'])?></span>
                            <span class="pa2-package-item__meta">
                                <span class="pa2-badge"><?=mc_h($packageRow['status'])?></span>
                                <span class="pa2-chip"><?=mc_h($packageRow['active_version_no'] ?: '无版本')?></span>
                                <span class="pa2-chip">组 <?=intval($packageRow['group_count'])?></span>
                                <span class="pa2-chip">锁定 <?=intval($packageRow['locked_group_count'])?></span>
                            </span>
                        </a>
                    <?php endforeach; ?>
                    <?php if ($canManagePackage): ?>
                    <details class="pa2-side-note">
                        <summary><strong>新增配置包</strong></summary>
                        <form class="pa2-form" data-pa2-form action="<?=mc_h(mc_url('adaptation_v2/api/index.php?action=package_save'))?>">
                            <label><span>编码</span><input name="package_code" placeholder="例如 singapore_custom"></label>
                            <label><span>名称 *</span><input name="package_name" required placeholder="例如 Singapore Custom"></label>
                            <label><span>渠道</span><input name="channel_code" value="singapore"></label>
                            <label><span>类型</span><input name="package_type" value="custom"></label>
                            <label class="full"><span>说明</span><input name="description" placeholder="说明配置包用途和限制"></label>
                            <button class="mc-button mc-button--primary" type="submit">新增配置包</button>
                        </form>
                    </details>
                    <?php endif; ?>
                </div>
            </aside>
            <section class="pa2-panel">
                <div class="pa2-panel__head">
                    <div>
                        <h2><?=mc_h($selectedPackage['package_name'] ?? '选择配置包')?></h2>
                        <p><?=mc_h($selectedPackage['description'] ?? '左侧选择配置包后查看版本、锁定规则、价格、MOQ、库存和交期。')?></p>
                    </div>
                    <?php if ($selectedPackage && $canManagePackage): ?>
                    <div class="pa2-template-actions">
                        <form data-pa2-form action="<?=mc_h(mc_url('adaptation_v2/api/index.php?action=package_version_prepare'))?>">
                            <input type="hidden" name="package_id" value="<?=intval($selectedPackage['id'])?>">
                            <button class="mc-button" type="submit">生成新草稿</button>
                        </form>
                        <?php if (($selectedPackage['active_version_status'] ?? '') === 'draft' && $canPublishPackage): ?>
                        <form data-pa2-form action="<?=mc_h(mc_url('adaptation_v2/api/index.php?action=package_publish'))?>">
                            <input type="hidden" name="package_id" value="<?=intval($selectedPackage['id'])?>">
                            <button class="mc-button mc-button--primary" type="submit">发布配置包</button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="pa2-panel__body pa2-section-gap">
                    <?php if (!$selectedPackage): ?>
                        <div class="pa2-placeholder">配置包表尚未迁移或没有配置包。执行第 8 阶段迁移后会自动生成 4 个首批配置包。</div>
                    <?php else: ?>
                        <div class="pa2-package-stats">
                            <div class="pa2-package-stat"><strong>当前版本</strong><b><?=mc_h($selectedPackage['active_version_no'] ?: '—')?></b><p class="pa2-muted"><?=mc_h($selectedPackage['active_version_status'] ?: '—')?></p></div>
                            <div class="pa2-package-stat"><strong>配置组</strong><b><?=intval($selectedPackagePreview['summary']['group_count'] ?? 0)?></b><p class="pa2-muted">选项 <?=intval($selectedPackagePreview['summary']['option_count'] ?? 0)?></p></div>
                            <div class="pa2-package-stat"><strong>锁定组</strong><b><?=intval($selectedPackagePreview['summary']['locked_group_count'] ?? 0)?></b><p class="pa2-muted">默认锁定 <?=intval($selectedPackagePreview['summary']['default_locked_group_count'] ?? 0)?></p></div>
                            <div class="pa2-package-stat"><strong>范围限定</strong><b><?=intval($selectedPackagePreview['summary']['limited_group_count'] ?? 0)?></b><p class="pa2-muted">光学/颜色等开放范围</p></div>
                        </div>
                        <?php if ($selectedPackagePreview): ?>
                        <div class="pa2-package-checks">
                            <?php foreach ($selectedPackagePreview['checks'] as $check): ?>
                                <div class="pa2-package-check <?=!empty($check['passed'])?'is-pass':'is-fail'?>"><strong><?=!empty($check['passed'])?'✓':'×'?> <?=mc_h($check['label'])?></strong><p class="pa2-muted"><?=!empty($check['passed'])?'已满足当前配置包验收规则。':'当前配置包还不满足此验收规则。'?></p></div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <?php if ($canManagePackage): ?>
                        <details class="pa2-side-note">
                            <summary><strong>编辑配置包基本信息</strong></summary>
                            <form class="pa2-form" data-pa2-form action="<?=mc_h(mc_url('adaptation_v2/api/index.php?action=package_save'))?>">
                                <input type="hidden" name="id" value="<?=intval($selectedPackage['id'])?>">
                                <label><span>编码</span><input name="package_code" value="<?=mc_h($selectedPackage['package_code'])?>"></label>
                                <label><span>名称</span><input name="package_name" value="<?=mc_h($selectedPackage['package_name'])?>" required></label>
                                <label><span>渠道</span><input name="channel_code" value="<?=mc_h($selectedPackage['channel_code'])?>"></label>
                                <label><span>类型</span><input name="package_type" value="<?=mc_h($selectedPackage['package_type'])?>"></label>
                                <label class="wide"><span>说明</span><input name="description" value="<?=mc_h($selectedPackage['description'] ?? '')?>"></label>
                                <label><span>状态</span><select name="status"><option value="draft" <?=($selectedPackage['status']==='draft'?'selected':'')?>>草稿</option><option value="published" <?=($selectedPackage['status']==='published'?'selected':'')?>>已发布</option><option value="disabled" <?=($selectedPackage['status']==='disabled'?'selected':'')?>>停用</option></select></label>
                                <button class="mc-button" type="submit">保存基本信息</button>
                            </form>
                        </details>
                        <?php endif; ?>
                        <div class="pa2-group-grid">
                            <?php foreach (($selectedPackage['groups'] ?? []) as $packageGroup): ?>
                                <article class="pa2-package-group">
                                    <div class="pa2-package-group__head">
                                        <div>
                                            <strong><?=mc_h($packageGroup['display_name'])?></strong>
                                            <p class="pa2-muted"><?=mc_h($packageGroup['group_code'])?> · <?=mc_h($packageGroup['group_type'] ?: '配置组')?> · <?=mc_h($pa2PackageLockLabels[$packageGroup['lock_mode']] ?? $packageGroup['lock_mode'])?></p>
                                        </div>
                                        <span class="pa2-badge <?=($packageGroup['lock_mode']==='locked'?'pa2-badge--block':($packageGroup['lock_mode']==='range_limited'?'pa2-badge--condition':'pa2-badge--match'))?>"><?=mc_h($packageGroup['lock_mode'])?></span>
                                    </div>
                                    <div class="pa2-package-rule-row">
                                        <div><strong>允许范围</strong><br><code><?=mc_h(pa2_json_encode($packageGroup['allowed_scope_json'] ?? []))?></code></div>
                                        <div><strong>默认项</strong><br><code><?=mc_h(pa2_json_encode($packageGroup['default_selection_json'] ?? []))?></code></div>
                                        <div><strong>MOQ</strong><br><code><?=mc_h(pa2_json_encode($packageGroup['moq_rule_json'] ?? []))?></code></div>
                                        <div><strong>库存/交期</strong><br><code><?=mc_h(pa2_json_encode(['inventory'=>$packageGroup['inventory_rule_json'] ?? [], 'lead_time'=>$packageGroup['lead_time_rule_json'] ?? []]))?></code></div>
                                    </div>
                                    <div class="pa2-package-options">
                                        <?php foreach (($packageGroup['options'] ?? []) as $option): ?>
                                            <span class="<?=((int)$option['is_locked']===1?'is-locked ':'')?><?=((int)$option['is_default']===1?'is-default':'')?>"><?=mc_h($option['option_label'])?><?=((int)$option['is_locked']===1?' · 锁定':'')?><?=((int)$option['is_default']===1?' · 默认':'')?><?=($option['moq']!==null?' · MOQ '.intval($option['moq']):'')?><?=($option['stock_qty']!==null?' · 库存 '.intval($option['stock_qty']):'')?></span>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php if ($canManagePackage && ($selectedPackage['active_version_status'] ?? '') === 'draft'): ?>
                                    <details>
                                        <summary>编辑此配置组规则</summary>
                                        <form class="pa2-form" data-pa2-form action="<?=mc_h(mc_url('adaptation_v2/api/index.php?action=package_group_save'))?>">
                                            <input type="hidden" name="package_id" value="<?=intval($selectedPackage['id'])?>">
                                            <input type="hidden" name="group_id" value="<?=intval($packageGroup['id'])?>">
                                            <input type="hidden" name="group_definition_id" value="<?=intval($packageGroup['group_definition_id'] ?? 0)?>">
                                            <label><span>显示名称</span><input name="display_name" value="<?=mc_h($packageGroup['display_name'])?>"></label>
                                            <label><span>组编码</span><input name="group_code" value="<?=mc_h($packageGroup['group_code'])?>"></label>
                                            <label><span>锁定模式</span><select name="lock_mode"><?php foreach ($pa2PackageLockLabels as $mode=>$label): ?><option value="<?=mc_h($mode)?>" <?=($packageGroup['lock_mode']===$mode?'selected':'')?>><?=mc_h($label)?></option><?php endforeach; ?></select></label>
                                            <label><span>必选</span><select name="is_required"><option value="1" <?=((int)$packageGroup['is_required']===1?'selected':'')?>>必选</option><option value="0" <?=((int)$packageGroup['is_required']===0?'selected':'')?>>可选</option></select></label>
                                            <label><span>允许为空</span><select name="allow_empty"><option value="1" <?=((int)$packageGroup['allow_empty']===1?'selected':'')?>>允许</option><option value="0" <?=((int)$packageGroup['allow_empty']===0?'selected':'')?>>不允许</option></select></label>
                                            <label><span>最少</span><input type="number" name="min_select" value="<?=intval($packageGroup['min_select'])?>"></label>
                                            <label><span>最多</span><input type="number" name="max_select" value="<?=intval($packageGroup['max_select'])?>"></label>
                                            <label><span>排序</span><input type="number" name="sort_order" value="<?=intval($packageGroup['sort_order'])?>"></label>
                                            <label class="wide"><span>允许范围 JSON</span><input name="allowed_scope_json" value="<?=mc_h(pa2_json_encode($packageGroup['allowed_scope_json'] ?? []))?>"></label>
                                            <label class="wide"><span>默认项 JSON</span><input name="default_selection_json" value="<?=mc_h(pa2_json_encode($packageGroup['default_selection_json'] ?? []))?>"></label>
                                            <label class="wide"><span>价格规则 JSON</span><input name="price_rule_json" value="<?=mc_h(pa2_json_encode($packageGroup['price_rule_json'] ?? []))?>"></label>
                                            <label class="wide"><span>MOQ 规则 JSON</span><input name="moq_rule_json" value="<?=mc_h(pa2_json_encode($packageGroup['moq_rule_json'] ?? []))?>"></label>
                                            <label class="wide"><span>库存规则 JSON</span><input name="inventory_rule_json" value="<?=mc_h(pa2_json_encode($packageGroup['inventory_rule_json'] ?? []))?>"></label>
                                            <label class="wide"><span>交期规则 JSON</span><input name="lead_time_rule_json" value="<?=mc_h(pa2_json_encode($packageGroup['lead_time_rule_json'] ?? []))?>"></label>
                                            <button class="mc-button" type="submit">保存组规则</button>
                                        </form>
                                        <form class="pa2-form" data-pa2-form action="<?=mc_h(mc_url('adaptation_v2/api/index.php?action=package_option_save'))?>">
                                            <input type="hidden" name="package_group_id" value="<?=intval($packageGroup['id'])?>">
                                            <label><span>选项键</span><input name="option_key" placeholder="例如 beam_15"></label>
                                            <label><span>选项名称 *</span><input name="option_label" required placeholder="例如 15° 光学"></label>
                                            <label><span>选项编码</span><input name="option_code" placeholder="例如 beam_15"></label>
                                            <label><span>类型</span><select name="option_type"><option value="attribute">属性</option><option value="rule">规则</option><option value="material">正式物料</option></select></label>
                                            <label><span>默认</span><select name="is_default"><option value="0">否</option><option value="1">是</option></select></label>
                                            <label><span>锁定</span><select name="is_locked"><option value="0">否</option><option value="1">是</option></select></label>
                                            <label><span>价格差异</span><input name="price_delta" placeholder="0.00"></label>
                                            <label><span>币种</span><input name="currency" placeholder="SGD"></label>
                                            <label><span>MOQ</span><input type="number" name="moq" placeholder="100"></label>
                                            <label><span>库存</span><input type="number" name="stock_qty" placeholder="50"></label>
                                            <label><span>交期天数</span><input type="number" name="lead_time_days" placeholder="14"></label>
                                            <label class="wide"><span>规则 JSON</span><input name="rule_json" placeholder='{"scope":"specified"}'></label>
                                            <button class="mc-button" type="submit">新增/更新选项</button>
                                        </form>
                                    </details>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($canManagePackage && ($selectedPackage['active_version_status'] ?? '') === 'draft'): ?>
                        <details class="pa2-side-note">
                            <summary><strong>新增配置组到当前配置包</strong></summary>
                            <form class="pa2-form" data-pa2-form action="<?=mc_h(mc_url('adaptation_v2/api/index.php?action=package_group_save'))?>">
                                <input type="hidden" name="package_id" value="<?=intval($selectedPackage['id'])?>">
                                <label><span>配置组定义</span><select name="group_definition_id"><option value="">手工编码</option><?php foreach ($groups as $groupDef): ?><option value="<?=intval($groupDef['id'])?>"><?=mc_h($groupDef['group_name'])?> · <?=mc_h($groupDef['group_code'])?></option><?php endforeach; ?></select></label>
                                <label><span>组编码</span><input name="group_code" placeholder="例如 optical"></label>
                                <label><span>显示名称</span><input name="display_name" placeholder="例如 光学 / 透镜"></label>
                                <label><span>锁定模式</span><select name="lock_mode"><?php foreach ($pa2PackageLockLabels as $mode=>$label): ?><option value="<?=mc_h($mode)?>"><?=mc_h($label)?></option><?php endforeach; ?></select></label>
                                <label><span>必选</span><select name="is_required"><option value="1">必选</option><option value="0">可选</option></select></label>
                                <label><span>允许为空</span><select name="allow_empty"><option value="0">不允许</option><option value="1">允许</option></select></label>
                                <label><span>最少</span><input type="number" name="min_select" value="1"></label>
                                <label><span>最多</span><input type="number" name="max_select" value="1"></label>
                                <label class="wide"><span>允许范围 JSON</span><input name="allowed_scope_json" placeholder='{"scope":"all_official_materials"}'></label>
                                <label class="wide"><span>默认项 JSON</span><input name="default_selection_json" placeholder='{"source":"published_product_default"}'></label>
                                <button class="mc-button" type="submit">新增配置组</button>
                            </form>
                        </details>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </section>
        </section>
    <?php elseif ($view === 'publish'): ?>
        <section class="pa2-panel">
            <div class="pa2-panel__head">
                <div><h2>渠道发布 / 下游接口</h2><p>第 9 阶段只提供 V2 下游读取能力；商务中心和新加坡网站只能读取已发布配置包版本，草稿永不暴露。</p></div>
                <span class="pa2-pill <?=pa2_phase9_tables_ready()?'pa2-pill--ok':'pa2-pill--warn'?>"><?=pa2_phase9_tables_ready()?'渠道接口表已就绪':'待执行第 9 阶段迁移'?></span>
            </div>
            <div class="pa2-panel__body pa2-section-gap">
                <div class="pa2-redline"><strong>发布红线：</strong> `channel_packages` 和 `channel_package_detail` 只查询 `p.status='published'` 且 `v.status='published'` 的配置包；草稿、已停用、未发布版本不会返回给下游。</div>
                <section class="pa2-channel-grid">
                    <article class="pa2-channel-card"><strong>渠道客户端</strong><b><?=intval($summary['channel_client_count'] ?? 0)?></b><p class="pa2-muted">商务中心 / 新加坡网站客户端，均要求 HMAC 签名。</p></article>
                    <article class="pa2-channel-card"><strong>发布包</strong><b><?=intval($summary['published_package_count'] ?? 0)?></b><p class="pa2-muted">未发布包不会出现在下游接口。</p></article>
                    <article class="pa2-channel-card"><strong>缓存 / 快照 / 订单</strong><b><?=intval($summary['channel_cache_count'] ?? 0)?> / <?=intval($summary['channel_snapshot_count'] ?? 0)?> / <?=intval($summary['channel_order_snapshot_count'] ?? 0)?></b><p class="pa2-muted">读取缓存、发布快照和订单配置快照分表记录。</p></article>
                </section>
                <section class="pa2-panel">
                    <div class="pa2-panel__head"><div><h2>客户端</h2><p>密钥不写进数据库，只记录环境变量名；服务器通过环境变量读取签名密钥。</p></div></div>
                    <div class="pa2-panel__body">
                        <table class="pa2-table">
                            <thead><tr><th>客户端</th><th>渠道</th><th>签名</th><th>密钥变量</th><th>最后使用</th></tr></thead>
                            <tbody>
                            <?php foreach ($channelClients as $client): ?>
                                <tr>
                                    <td><strong><?=mc_h($client['client_name'])?></strong><br><code><?=mc_h($client['client_code'])?></code></td>
                                    <td><?=mc_h($client['channel_code'])?></td>
                                    <td><?=((int)$client['signature_required']===1?'必须签名':'不要求')?></td>
                                    <td><code><?=mc_h($client['allowed_scope_json']['env_secret'] ?? '—')?></code></td>
                                    <td><?=mc_h($client['last_used_at'] ?: '—')?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
                <section class="pa2-panel">
                    <div class="pa2-panel__head"><div><h2>下游接口</h2><p>签名基串：`timestamp + \"\\n\" + client_code + \"\\n\" + raw_body`，算法：HMAC-SHA256。</p></div></div>
                    <div class="pa2-panel__body pa2-section-gap">
                        <div class="pa2-endpoint"><strong>读取已发布配置包列表</strong><code>GET /material_center_v1/adaptation_v2/api/index.php?action=channel_packages</code><p class="pa2-muted">Headers：X-PA2-Client、X-PA2-Timestamp、X-PA2-Signature。</p></div>
                        <div class="pa2-endpoint"><strong>读取单个已发布配置包</strong><code>GET /material_center_v1/adaptation_v2/api/index.php?action=channel_package_detail&package_code=singapore_standard</code><p class="pa2-muted">只返回当前已发布版本；未发布包返回错误。</p></div>
                        <div class="pa2-endpoint"><strong>保存下游订单配置快照</strong><code>POST /material_center_v1/adaptation_v2/api/index.php?action=channel_order_snapshot</code><p class="pa2-muted">订单快照会记录下单时使用的配置包版本，避免后续配置变更影响历史订单。</p></div>
                    </div>
                </section>
                <section class="pa2-panel">
                    <div class="pa2-panel__head"><div><h2>当前配置包发布状态</h2><p>这里只展示 V2 包状态，不自动发布；需要在配置包中心明确发布后，下游才读得到。</p></div></div>
                    <div class="pa2-panel__body">
                        <table class="pa2-table">
                            <thead><tr><th>配置包</th><th>渠道</th><th>包状态</th><th>版本</th><th>下游可见</th></tr></thead>
                            <tbody>
                            <?php foreach ($packages as $packageRow): ?>
                                <?php $isVisible = ($packageRow['status'] ?? '') === 'published' && ($packageRow['active_version_status'] ?? '') === 'published'; ?>
                                <tr>
                                    <td><strong><?=mc_h($packageRow['package_name'])?></strong><br><code><?=mc_h($packageRow['package_code'])?></code></td>
                                    <td><?=mc_h($packageRow['channel_code'])?></td>
                                    <td><?=mc_h($packageRow['status'])?></td>
                                    <td><?=mc_h(($packageRow['active_version_no'] ?: '—') . ' / ' . ($packageRow['active_version_status'] ?: '—'))?></td>
                                    <td><span class="pa2-badge <?=$isVisible?'pa2-badge--match':'pa2-badge--block'?>"><?=$isVisible?'可见':'不可见'?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </section>
    <?php elseif ($view === 'cutover'): ?>
        <section class="pa2-panel">
            <div class="pa2-panel__head">
                <div><h2>最终验收 / 切换评估</h2><p>这是 V2 切换闸门：只评估并记录，不自动修改正式菜单。</p></div>
                <?php if (pa2_phase10_tables_ready() && pa2_can_any(['adaptation_v2.manage_channel','adaptation_v2.publish','material_center.adaptation.manage'])): ?>
                <form data-pa2-form action="<?=mc_h(mc_url('adaptation_v2/api/index.php?action=cutover_audit_record'))?>">
                    <input type="hidden" name="note" value="manual cutover readiness audit">
                    <button class="mc-button mc-button--primary" type="submit">记录本次验收</button>
                </form>
                <?php endif; ?>
            </div>
            <div class="pa2-panel__body pa2-section-gap">
                <div class="pa2-cutover-banner <?=($cutoverReadiness['ready_to_switch'] ?? false)?'is-ready':''?>">
                    <div>
                        <strong><?=mc_h($cutoverReadiness['decision'] ?? '待检查')?></strong>
                        <p class="pa2-muted">当前状态：<?=mc_h($cutoverReadiness['status'] ?? 'unknown')?>；阻断项 <?=count($cutoverReadiness['blockers'] ?? [])?> 个。正式菜单仍保持旧版入口。</p>
                    </div>
                    <span class="pa2-pill <?=($cutoverReadiness['ready_to_switch'] ?? false)?'pa2-pill--ok':'pa2-pill--warn'?>"><?=($cutoverReadiness['ready_to_switch'] ?? false)?'可进入切换审批':'禁止切换'?></span>
                </div>
                <?php if (!empty($cutoverReadiness['blockers'])): ?>
                <section class="pa2-panel">
                    <div class="pa2-panel__head"><div><h2>阻断项</h2><p>以下项目未完成前，不允许把正式菜单切换到 V2。</p></div></div>
                    <div class="pa2-panel__body pa2-check-list">
                        <?php foreach ($cutoverReadiness['blockers'] as $check): ?>
                            <article class="pa2-check-item is-blocked">
                                <div><strong><?=mc_h($check['check_name'])?></strong><br><span class="pa2-muted"><?=mc_h($check['check_code'])?> · <?=mc_h($check['severity'])?></span></div>
                                <span class="pa2-badge pa2-badge--block">blocked</span>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
                <?php endif; ?>
                <section class="pa2-panel">
                    <div class="pa2-panel__head"><div><h2>全量检查项</h2><p>包含旧版边界、各阶段表、规则循环、配置包发布和真实业务回归要求。</p></div></div>
                    <div class="pa2-panel__body pa2-check-list">
                        <?php foreach (($cutoverReadiness['checks'] ?? []) as $check): ?>
                            <article class="pa2-check-item <?=($check['result']==='passed')?'is-passed':'is-blocked'?>">
                                <div><strong><?=mc_h($check['check_name'])?></strong><br><span class="pa2-muted"><?=mc_h($check['check_code'])?> · <?=mc_h(pa2_json_encode($check['evidence'] ?? []))?></span></div>
                                <span class="pa2-badge <?=($check['result']==='passed')?'pa2-badge--match':'pa2-badge--block'?>"><?=mc_h($check['result'])?></span>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            </div>
        </section>
    <?php elseif ($view === 'logs'): ?>
        <section class="pa2-panel">
            <div class="pa2-panel__head"><div><h2>执行日志与审计文档</h2><p>阶段文档保存在 V2 独立目录，便于验收和回滚。</p></div></div>
            <div class="pa2-panel__body">
                <table class="pa2-table">
                    <tbody>
                        <tr><td>第 1 阶段审计</td><td><code>adaptation_v2/docs/01_CURRENT_AUDIT.md</code></td></tr>
                        <tr><td>第 1 阶段数据库审计</td><td><code>adaptation_v2/docs/01_DATABASE_AUDIT.md</code></td></tr>
                        <tr><td>第 2 阶段执行记录</td><td><code>adaptation_v2/docs/02_FOUNDATION_MODEL.md</code></td></tr>
                        <tr><td>第 3 阶段模板继承</td><td><code>adaptation_v2/docs/03_TEMPLATE_INHERITANCE.md</code></td></tr>
                        <tr><td>第 4 阶段配置组和规则</td><td><code>adaptation_v2/docs/04_GROUP_RULE_EDITOR.md</code></td></tr>
                        <tr><td>第 5 阶段单产品工作台</td><td><code>adaptation_v2/docs/05_PRODUCT_WORKSPACE.md</code></td></tr>
                        <tr><td>第 6 阶段适配计算</td><td><code>adaptation_v2/docs/06_ADAPTATION_ENGINE.md</code></td></tr>
                        <tr><td>第 7 阶段版本审批</td><td><code>adaptation_v2/docs/07_VERSION_APPROVAL.md</code></td></tr>
                        <tr><td>第 8 阶段配置包中心</td><td><code>adaptation_v2/docs/08_CONFIG_PACKAGE_CENTER.md</code></td></tr>
                        <tr><td>第 9 阶段渠道接口</td><td><code>adaptation_v2/docs/09_CHANNEL_API.md</code></td></tr>
                        <tr><td>第 10 阶段最终验收</td><td><code>adaptation_v2/docs/10_CUTOVER_READINESS.md</code></td></tr>
                        <tr><td>总执行日志</td><td><code>adaptation_v2/docs/EXECUTION_LOG.md</code></td></tr>
                    </tbody>
                </table>
            </div>
        </section>
    <?php else: ?>
        <section class="pa2-panel">
            <div class="pa2-placeholder">
                <h2><?=mc_h($routeCards[array_search($view, array_column($routeCards, 0), true)][1] ?? '后续阶段')?></h2>
                <p>此入口已纳入 V2 路由蓝图，但当前阶段不开发业务功能。请按主说明继续进入对应阶段后再实现。</p>
            </div>
        </section>
    <?php endif; ?>
</section>
<script>
document.querySelectorAll('[data-pa2-form]').forEach((form)=>{
  form.addEventListener('submit', async (event)=>{
    event.preventDefault();
    const confirmText=form.getAttribute('data-confirm');
    if(confirmText && !window.confirm(confirmText)) return;
    const button=form.querySelector('button[type="submit"]');
    if(button) button.disabled=true;
    try{
      const res=await fetch(form.action,{method:'POST',body:new FormData(form),credentials:'same-origin'});
      const data=await res.json();
      if(!data.success) throw new Error(data.message||'保存失败');
      location.reload();
    }catch(err){
      alert(err.message||'保存失败');
      if(button) button.disabled=false;
    }
  });
});
document.querySelectorAll('.pa2-jump-links a[href^="#pa2-group-"]').forEach((link)=>{
  link.addEventListener('click',(event)=>{
    const target=document.querySelector(link.getAttribute('href'));
    if(!target)return;
    event.preventDefault();
    target.scrollIntoView({behavior:'smooth',block:'center'});
    history.replaceState(null,'',link.getAttribute('href'));
  });
});
function pa2OpenDialog(dialog){
  if(!dialog)return;
  if(typeof dialog.showModal==='function'){
    dialog.showModal();
    return;
  }
  dialog.setAttribute('open','open');
}
function pa2CloseDialog(dialog){
  if(!dialog)return;
  dialog.close ? dialog.close() : dialog.removeAttribute('open');
}
function pa2SetField(form,name,value){
  if(!form||!form.elements[name])return;
  form.elements[name].value=value??'';
}
(()=>{
  const form=document.querySelector('[data-template-group-form]');
  if(!form)return;
  const mode=document.querySelector('[data-template-group-mode]');
  const submit=form.querySelector('[data-template-group-submit]');
  function setMode(text){
    if(mode) mode.textContent=text;
    if(submit) submit.textContent='保存配置组设置';
  }
  document.querySelectorAll('[data-template-group-edit]').forEach((btn)=>{
    btn.addEventListener('click',()=>{
      pa2SetField(form,'group_definition_id',btn.getAttribute('data-group-definition-id')||'');
      pa2SetField(form,'inheritance_action',btn.getAttribute('data-inheritance-action')||'add');
      pa2SetField(form,'selection_mode',btn.getAttribute('data-selection-mode')||'single');
      pa2SetField(form,'sort_order',btn.getAttribute('data-sort-order')||'100');
      pa2SetField(form,'min_select',btn.getAttribute('data-min-select')||'0');
      pa2SetField(form,'max_select',btn.getAttribute('data-max-select')||'1');
      pa2SetField(form,'is_required',btn.getAttribute('data-is-required')||'0');
      pa2SetField(form,'allow_empty',btn.getAttribute('data-allow-empty')||'1');
      pa2SetField(form,'affects_price',btn.getAttribute('data-affects-price')||'0');
      pa2SetField(form,'affects_lead_time',btn.getAttribute('data-affects-lead-time')||'0');
      pa2SetField(form,'requires_approval',btn.getAttribute('data-requires-approval')||'0');
      setMode('正在编辑：'+(btn.getAttribute('data-group-name')||'配置组'));
      form.scrollIntoView({behavior:'smooth',block:'center'});
    });
  });
  document.querySelectorAll('[data-template-group-reset]').forEach((btn)=>{
    btn.addEventListener('click',()=>{
      form.reset();
      setMode('新增或覆盖配置组');
      if(submit) submit.textContent='保存配置组设置';
      form.scrollIntoView({behavior:'smooth',block:'center'});
    });
  });
})();
(()=>{
  const dialog=document.getElementById('pa2-category-create-dialog');
  if(!dialog)return;
  document.querySelectorAll('[data-open-category-create]').forEach((btn)=>{
    btn.addEventListener('click',()=>pa2OpenDialog(dialog));
  });
  document.querySelectorAll('[data-close-category-create]').forEach((btn)=>{
    btn.addEventListener('click',()=>pa2CloseDialog(dialog));
  });
})();
(()=>{
  const dialog=document.getElementById('pa2-category-edit-dialog');
  const form=dialog?dialog.querySelector('form'):null;
  const subtitle=document.getElementById('pa2-category-edit-subtitle');
  if(!dialog||!form)return;
  document.querySelectorAll('[data-open-category-edit]').forEach((btn)=>{
    btn.addEventListener('click',()=>{
      const currentId=btn.getAttribute('data-category-id')||'';
      pa2SetField(form,'id',currentId);
      pa2SetField(form,'category_code',btn.getAttribute('data-category-code')||'');
      pa2SetField(form,'category_name',btn.getAttribute('data-category-name')||'');
      pa2SetField(form,'parent_id',btn.getAttribute('data-parent-id')||'');
      pa2SetField(form,'sort_order',btn.getAttribute('data-sort-order')||'100');
      pa2SetField(form,'is_enabled',btn.getAttribute('data-is-enabled')||'1');
      pa2SetField(form,'description',btn.getAttribute('data-description')||'');
      Array.from(form.elements.parent_id.options).forEach((option)=>{
        option.disabled=currentId!=='' && option.value===currentId;
      });
      if(subtitle) subtitle.textContent='修改「'+(btn.getAttribute('data-category-name')||'产品分类')+'」基础资料。';
      pa2OpenDialog(dialog);
    });
  });
  document.querySelectorAll('[data-close-category-edit]').forEach((btn)=>{
    btn.addEventListener('click',()=>pa2CloseDialog(dialog));
  });
})();
(()=>{
  const dialog=document.getElementById('pa2-template-create-dialog');
  if(!dialog)return;
  document.querySelectorAll('[data-open-template-create]').forEach((btn)=>{
    btn.addEventListener('click',()=>pa2OpenDialog(dialog));
  });
  document.querySelectorAll('[data-close-template-create]').forEach((btn)=>{
    btn.addEventListener('click',()=>pa2CloseDialog(dialog));
  });
})();
(()=>{
  const dialog=document.getElementById('pa2-workspace-category-dialog');
  if(!dialog)return;
  document.querySelectorAll('[data-open-workspace-category]').forEach((btn)=>{
    btn.addEventListener('click',()=>pa2OpenDialog(dialog));
  });
  document.querySelectorAll('[data-close-workspace-category]').forEach((btn)=>{
    btn.addEventListener('click',()=>pa2CloseDialog(dialog));
  });
})();
(()=>{
  const dialog=document.getElementById('pa2-workspace-template-dialog');
  if(!dialog)return;
  document.querySelectorAll('[data-open-workspace-template]').forEach((btn)=>{
    btn.addEventListener('click',()=>pa2OpenDialog(dialog));
  });
  document.querySelectorAll('[data-close-workspace-template]').forEach((btn)=>{
    btn.addEventListener('click',()=>pa2CloseDialog(dialog));
  });
})();
(()=>{
  const dialog=document.getElementById('pa2-group-create-dialog');
  if(!dialog)return;
  document.querySelectorAll('[data-open-group-create]').forEach((btn)=>{
    btn.addEventListener('click',()=>pa2OpenDialog(dialog));
  });
  document.querySelectorAll('[data-close-group-create]').forEach((btn)=>{
    btn.addEventListener('click',()=>pa2CloseDialog(dialog));
  });
})();
(()=>{
  const dialog=document.getElementById('pa2-option-create-dialog');
  const form=dialog?dialog.querySelector('form'):null;
  const subtitle=document.getElementById('pa2-option-create-subtitle');
  if(!dialog||!form)return;
  document.querySelectorAll('[data-open-option-create]').forEach((btn)=>{
    btn.addEventListener('click',()=>{
      form.reset();
      pa2SetField(form,'group_definition_id',btn.getAttribute('data-group-id')||'');
      if(subtitle) subtitle.textContent='给「'+(btn.getAttribute('data-group-name')||'当前配置组')+'」增加可选项。';
      pa2OpenDialog(dialog);
    });
  });
  document.querySelectorAll('[data-close-option-create]').forEach((btn)=>{
    btn.addEventListener('click',()=>pa2CloseDialog(dialog));
  });
})();
(()=>{
  const dialog=document.getElementById('pa2-group-edit-dialog');
  const form=dialog?dialog.querySelector('form'):null;
  const subtitle=document.getElementById('pa2-group-edit-subtitle');
  if(!dialog||!form)return;
  document.querySelectorAll('[data-open-group-edit]').forEach((btn)=>{
    btn.addEventListener('click',()=>{
      pa2SetField(form,'id',btn.getAttribute('data-group-id')||'');
      pa2SetField(form,'group_code',btn.getAttribute('data-group-code')||'');
      pa2SetField(form,'group_name',btn.getAttribute('data-group-name')||'');
      pa2SetField(form,'group_type',btn.getAttribute('data-group-type')||'material_select');
      pa2SetField(form,'sort_order',btn.getAttribute('data-sort-order')||'100');
      pa2SetField(form,'is_enabled',btn.getAttribute('data-is-enabled')||'1');
      if(subtitle) subtitle.textContent='修改「'+(btn.getAttribute('data-group-name')||'配置组')+'」基础资料。';
      pa2OpenDialog(dialog);
    });
  });
  document.querySelectorAll('[data-close-group-edit]').forEach((btn)=>{
    btn.addEventListener('click',()=>pa2CloseDialog(dialog));
  });
})();
(()=>{
  const dialog=document.getElementById('pa2-behavior-edit-dialog');
  const form=dialog?dialog.querySelector('form'):null;
  const subtitle=document.getElementById('pa2-behavior-edit-subtitle');
  if(!dialog||!form)return;
  document.querySelectorAll('[data-open-behavior-edit]').forEach((btn)=>{
    btn.addEventListener('click',()=>{
      pa2SetField(form,'group_definition_id',btn.getAttribute('data-group-id')||'');
      pa2SetField(form,'selection_kind',btn.getAttribute('data-selection-kind')||'material');
      pa2SetField(form,'source_mode',btn.getAttribute('data-source-mode')||'official_material');
      pa2SetField(form,'material_category_code',btn.getAttribute('data-material-category-code')||'');
      pa2SetField(form,'is_required_default',btn.getAttribute('data-is-required')||'0');
      pa2SetField(form,'selection_mode_default',btn.getAttribute('data-selection-mode')||'single');
      pa2SetField(form,'min_select_default',btn.getAttribute('data-min-select')||'0');
      pa2SetField(form,'max_select_default',btn.getAttribute('data-max-select')||'1');
      pa2SetField(form,'default_rule_json',btn.getAttribute('data-default-rule')||'');
      pa2SetField(form,'material_filter_json',btn.getAttribute('data-material-filter')||'');
      pa2SetField(form,'visibility_condition_json',btn.getAttribute('data-visibility-condition')||'');
      if(subtitle) subtitle.textContent='设置「'+(btn.getAttribute('data-group-name')||'配置组')+'」的数据来源、必选规则和过滤条件。';
      pa2OpenDialog(dialog);
    });
  });
  document.querySelectorAll('[data-close-behavior-edit]').forEach((btn)=>{
    btn.addEventListener('click',()=>pa2CloseDialog(dialog));
  });
})();
(()=>{
  const dialog=document.getElementById('pa2-material-dialog');
  const list=document.getElementById('pa2-material-list');
  const title=document.getElementById('pa2-material-title');
  const search=document.getElementById('pa2-material-search');
  let currentGroupId='';
  let currentGroupCode='';
  async function loadCandidates(q=''){
    if(!dialog||!list||!currentGroupCode)return;
    list.innerHTML='<div class="pa2-placeholder">正在读取候选物料...</div>';
    try{
      const url=new URL('<?=mc_h(mc_url('adaptation_v2/api/index.php?action=material_candidates'))?>', location.origin);
      url.searchParams.set('group_code', currentGroupCode);
      url.searchParams.set('product_id', '<?=intval($workspaceProductId)?>');
      url.searchParams.set('product_group_config_id', currentGroupId);
      if(q) url.searchParams.set('q', q);
      const res=await fetch(url.toString(),{credentials:'same-origin'});
      const data=await res.json();
      if(!data.success) throw new Error(data.message||'读取失败');
      const rows=(data.data&&data.data.materials)||[];
      if(!rows.length){list.innerHTML='<div class="pa2-placeholder">没有找到候选物料。可以换关键词搜索，或在第6阶段适配规则里补过滤条件。</div>';return;}
      list.innerHTML=rows.map((m)=>{
        const r=m.adaptation_result||{};
        const cls=statusClass(r.result_status||'');
        const label=escapeHtml(r.status_label||statusLabel(r.result_status)||'待计算');
        const score=Number.isFinite(Number(r.match_score))?` · ${escapeHtml(String(r.match_score))}%`:'';
        const reason=(Array.isArray(r.reasons)&&r.reasons.length)?r.reasons[0]:'暂无原因，请重新计算或补充规格。';
        return `
        <div class="pa2-candidate">
          <div><strong>${escapeHtml(m.material_code||('#'+m.id))} ${escapeHtml(m.name||'')}</strong><br><small>${escapeHtml([m.brand,m.model,m.category_name,m.status].filter(Boolean).join(' · '))}</small><br><small>${escapeHtml(m.spec_summary||'')}</small><br><span class="pa2-badge ${cls}">${label}${score}</span> <small>${escapeHtml(reason)}</small></div>
          <form data-pa2-picker-save>
            <input type="hidden" name="product_group_config_id" value="${escapeHtml(currentGroupId)}">
            <input type="hidden" name="option_type" value="material">
            <input type="hidden" name="material_id" value="${escapeHtml(String(m.id))}">
            <button class="mc-button mc-button--primary" type="submit">选择</button>
          </form>
        </div>`}).join('');
    }catch(err){
      list.innerHTML='<div class="pa2-alert">'+escapeHtml(err.message||'读取失败')+'</div>';
    }
  }
  function statusLabel(status){return {full_match:'完全适配',conditional_match:'条件适配',approval_required:'需要审批',incompatible:'不适配'}[status]||'';}
  function statusClass(status){return {full_match:'pa2-badge--match',conditional_match:'pa2-badge--condition',approval_required:'pa2-badge--approval',incompatible:'pa2-badge--block'}[status]||'';}
  function escapeHtml(v){return String(v??'').replace(/[&<>"']/g,(ch)=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));}
  document.querySelectorAll('[data-open-material-picker]').forEach((btn)=>{
    btn.addEventListener('click',()=>{
      currentGroupId=btn.getAttribute('data-group-id')||'';
      currentGroupCode=btn.getAttribute('data-group-code')||'';
      if(title) title.textContent='选择正式物料 · '+(btn.getAttribute('data-group-name')||currentGroupCode);
      if(dialog&&typeof dialog.showModal==='function') dialog.showModal();
      loadCandidates('');
    });
  });
  document.querySelectorAll('[data-close-material-picker]').forEach((btn)=>btn.addEventListener('click',()=>dialog&&dialog.close()));
  if(search){
    search.addEventListener('submit',(event)=>{
      event.preventDefault();
      loadCandidates(new FormData(search).get('q')||'');
    });
  }
  document.addEventListener('submit',async(event)=>{
    const form=event.target;
    if(!(form instanceof HTMLFormElement)||!form.matches('[data-pa2-picker-save]'))return;
    event.preventDefault();
    const button=form.querySelector('button[type="submit"]');
    if(button)button.disabled=true;
    try{
      const res=await fetch('<?=mc_h(mc_url('adaptation_v2/api/index.php?action=product_group_save'))?>',{method:'POST',body:new FormData(form),credentials:'same-origin'});
      const data=await res.json();
      if(!data.success) throw new Error(data.message||'保存失败');
      location.reload();
    }catch(err){
      alert(err.message||'保存失败');
      if(button)button.disabled=false;
    }
  });
})();
</script>
<?php include MC_ROOT . '/components/layout_bottom.php'; ?>
