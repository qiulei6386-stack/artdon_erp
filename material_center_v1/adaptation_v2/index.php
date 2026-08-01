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
.pa2-workspace{display:grid;gap:16px}.pa2-product-hero{display:grid;grid-template-columns:96px minmax(0,1fr) auto;gap:18px;align-items:center;background:#fff;border:1px solid var(--pa2-border);border-radius:20px;padding:18px}.pa2-product-hero img{width:88px;height:88px;object-fit:contain;border:1px solid #e6edf5;border-radius:14px;background:#f8fafc}.pa2-steps{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.pa2-step{border:1px solid var(--pa2-border);border-radius:16px;padding:14px;background:#fff}.pa2-step b{display:inline-flex;width:24px;height:24px;align-items:center;justify-content:center;border-radius:50%;background:#e6fffb;color:#0b7773;margin-right:8px}.pa2-work-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.pa2-config-card{border:1px solid var(--pa2-border);border-radius:18px;background:#fff;padding:14px;display:grid;gap:10px;min-height:190px}.pa2-config-card.is-missing{border-color:#fedf89;background:#fffdf7}.pa2-config-card.is-done{border-color:#abefc6}.pa2-config-card__head{display:flex;justify-content:space-between;gap:8px}.pa2-selected{display:grid;gap:6px}.pa2-selected span{background:#f2f4f7;border-radius:10px;padding:7px 9px}.pa2-scheme-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.pa2-scheme-card{border:1px solid var(--pa2-border);border-radius:16px;background:#fff;padding:14px;display:grid;gap:10px}.pa2-scheme-card[data-open-scheme-editor]{cursor:pointer}.pa2-scheme-card[data-open-scheme-editor]:hover{border-color:#9dd8d6;box-shadow:0 14px 32px rgba(15,159,154,.10);transform:translateY(-1px)}.pa2-scheme-card.is-selected{border-color:#d92d20;background:#fff7f5;box-shadow:inset 0 0 0 2px #d92d20}.pa2-scheme-card__head{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}.pa2-scheme-card__head strong{font-size:18px;color:#d92d20}.pa2-scheme-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:flex-end}.pa2-scheme-lines{display:grid;gap:6px}.pa2-scheme-lines div{line-height:1.5;color:#667085}.pa2-scheme-lines b{color:#667085}.pa2-scheme-placeholder{border:1px dashed #d6e3f0;border-radius:12px;background:#fbfdff;color:#667085;padding:12px;line-height:1.6}.pa2-footerbar{display:flex;justify-content:space-between;gap:12px;align-items:center;border:1px solid var(--pa2-border);border-radius:18px;background:#fff;padding:14px 16px;position:sticky;bottom:10px;box-shadow:0 12px 32px rgba(16,24,40,.08)}.pa2-dialog{border:0;border-radius:20px;padding:0;width:min(980px,92vw);box-shadow:0 24px 80px rgba(16,24,40,.28)}.pa2-dialog--narrow{width:min(580px,92vw);overflow:hidden}.pa2-dialog::backdrop{background:rgba(15,23,42,.32)}.pa2-dialog__head,.pa2-dialog__foot{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:16px 18px;border-bottom:1px solid var(--pa2-border)}.pa2-dialog__head{background:linear-gradient(135deg,#f0fdfa,#f8fbff 55%,#fff)}.pa2-dialog__head h3{margin:0;font-size:19px;color:#122033}.pa2-dialog__head p{margin:4px 0 0;color:var(--pa2-muted);font-size:13px}.pa2-dialog__foot{border-top:1px solid var(--pa2-border);border-bottom:0;background:#fbfdff}.pa2-dialog__body{padding:16px 18px;max-height:62vh;overflow:auto}.pa2-dialog-form{display:grid;grid-template-columns:1fr 1fr;gap:12px}.pa2-dialog-form label{display:grid;gap:6px;font-weight:800;color:#344054}.pa2-dialog-form input,.pa2-dialog-form select{border:1px solid #cfd8e6;border-radius:12px;padding:10px 11px;background:#fff}.pa2-dialog-form .full{grid-column:1/-1}.pa2-dialog-hint{border:1px dashed #b7e4e2;background:#f0fdfa;border-radius:14px;padding:11px;color:#0b7773}.pa2-candidate-list{display:grid;gap:10px}.pa2-candidate{display:grid;grid-template-columns:1fr auto;gap:12px;align-items:center;border:1px solid #e6edf5;border-radius:14px;padding:12px}.pa2-candidate.is-selected{border-color:#0f9f9a;background:#f0fdfa}.pa2-candidate small{color:var(--pa2-muted)}.pa2-candidate-check{display:flex;align-items:center;gap:8px;font-weight:800;color:#0b7773}.pa2-candidate-check input{width:18px;height:18px}.pa2-picker-actions{display:flex;gap:10px;align-items:center;justify-content:flex-end;flex-wrap:wrap}.pa2-picker-summary{color:var(--pa2-muted);font-size:13px}
.pa2-package-shell{display:grid;grid-template-columns:360px minmax(0,1fr);gap:16px;align-items:start}.pa2-package-list{display:grid;gap:12px}.pa2-package-item{display:grid;gap:8px;text-decoration:none;color:inherit;border:1px solid var(--pa2-border);border-radius:18px;padding:14px;background:#fff}.pa2-package-item.is-active{border-color:var(--pa2-teal);box-shadow:0 14px 32px rgba(15,159,154,.12)}.pa2-package-item__meta{display:flex;gap:8px;flex-wrap:wrap}.pa2-package-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.pa2-package-stat{border:1px solid #e6edf5;border-radius:14px;background:#f8fafc;padding:11px}.pa2-package-checks{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.pa2-package-check{border:1px solid #e6edf5;border-radius:14px;padding:12px;background:#fff}.pa2-package-check.is-pass{border-color:#abefc6;background:#f6fef9}.pa2-package-check.is-fail{border-color:#fda29b;background:#fff7f7}.pa2-package-group{border:1px solid var(--pa2-border);border-radius:16px;padding:14px;background:#fff;display:grid;gap:10px}.pa2-package-group__head{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}.pa2-package-rule-row{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}.pa2-package-rule-row code{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.pa2-package-options{display:flex;gap:8px;flex-wrap:wrap}.pa2-package-options span{background:#eef4ff;color:#1d4ed8;border-radius:999px;padding:5px 9px;font-size:12px}.pa2-package-options span.is-locked{background:#fef3f2;color:#b42318}.pa2-package-options span.is-default{background:#ecfdf3;color:#067647}.pa2-channel-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.pa2-channel-card{border:1px solid var(--pa2-border);border-radius:18px;background:#fff;padding:15px;display:grid;gap:8px}.pa2-endpoint{border:1px dashed #c9d8e8;border-radius:14px;background:#f8fafc;padding:12px}.pa2-endpoint code{display:block;white-space:normal}.pa2-redline{border:1px solid #fda29b;background:#fff7f7;color:#b42318;border-radius:14px;padding:12px}.pa2-cutover-banner{border:1px solid #fedf89;background:#fffaeb;border-radius:18px;padding:16px;display:flex;justify-content:space-between;gap:12px;align-items:center}.pa2-cutover-banner.is-ready{border-color:#abefc6;background:#f6fef9}.pa2-check-list{display:grid;gap:10px}.pa2-check-item{display:grid;grid-template-columns:1fr auto;gap:10px;border:1px solid #e6edf5;border-radius:14px;background:#fff;padding:12px}.pa2-check-item.is-blocked{border-color:#fda29b;background:#fff7f7}.pa2-check-item.is-passed{border-color:#abefc6;background:#f6fef9}
.pa2-card-actions{display:grid;grid-template-columns:1fr 1fr;gap:8px}.pa2-logic-tags{display:flex;gap:6px;flex-wrap:wrap}.pa2-logic-tags span{background:#eef8f8;color:#0b7773;border:1px solid #c9eeeb;border-radius:999px;padding:4px 8px;font-size:12px}.pa2-dialog--logic{width:min(860px,92vw)}.pa2-logic-form{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.pa2-logic-form label{display:grid;gap:6px;font-weight:800;color:#344054}.pa2-logic-form input,.pa2-logic-form select{border:1px solid #cfd8e6;border-radius:12px;padding:10px 11px;background:#fff}.pa2-logic-form .wide{grid-column:span 2}.pa2-logic-form .full{grid-column:1/-1}.pa2-logic-section{grid-column:1/-1;border-top:1px dashed #dbe7f3;padding-top:10px;font-weight:900;color:#0b7773}
@media(max-width:1100px){.pa2-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.pa2-form{grid-template-columns:repeat(2,minmax(0,1fr))}.pa2-hero{display:grid}}@media(max-width:700px){.pa2-grid,.pa2-form{grid-template-columns:1fr}.pa2-form .wide{grid-column:auto}}
@media(max-width:1280px){.pa2-template-shell,.pa2-rule-board,.pa2-package-shell{grid-template-columns:1fr}.pa2-work-grid,.pa2-scheme-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.pa2-template-actions{justify-content:flex-start}}@media(max-width:760px){.pa2-product-hero,.pa2-footerbar{display:grid}.pa2-steps,.pa2-work-grid,.pa2-scheme-grid,.pa2-package-stats,.pa2-package-checks,.pa2-package-rule-row,.pa2-channel-grid{grid-template-columns:1fr}}
</style>
<section class="mc-page mc-pa2-page" data-adaptation-v2 data-phase="10" data-view="<?=mc_h($view)?>">
    <header class="pa2-hero">
        <div>
            <div class="mc-breadcrumb">Artdon ERP / 物料中心 / 产品适配 V2</div>
            <h1>产品适配 V2</h1>
            <p>产品适配正式入口已指向 V2；旧版目录与旧 BOM 保留不删除，V2 继续使用 <code>adaptation_v2/</code> 与 <code>mc_pa2_</code> 新表。</p>
        </div>
        <div class="pa2-actions">
            <span class="pa2-pill <?= $summary['ready'] ? 'pa2-pill--ok' : 'pa2-pill--warn' ?>"><?= $summary['ready'] ? '基础表已就绪' : '待执行 V2 迁移' ?></span>
            <a class="mc-button" href="<?=mc_h(mc_url('index.php'))?>">返回物料中心</a>
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
            <div class="pa2-panel__head"><div><h2>阶段路由和边界</h2><p>正式产品适配入口已指向 V2；旧版目录和旧 BOM 保留不删除，剩余阻断项用于继续跟踪真实业务回归。</p></div><span class="pa2-pill <?=($cutoverReadiness['ready_to_switch'] ?? false)?'pa2-pill--ok':'pa2-pill--warn'?>"><?=mc_h($cutoverReadiness['decision'] ?? '待检查')?></span></div>
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
                        <div class="pa2-dialog-hint full"><strong>模板默认逻辑</strong>：这里设置会成为产品草稿的默认判断逻辑；单产品仍可在工作台里改成自定义覆盖。</div>
                        <label><span>物料分类</span><select name="material_category_code"><option value="">跟随配置组</option><?php foreach ($pa2MaterialCategoryLabels as $code=>$label): ?><option value="<?=mc_h($code)?>"><?=mc_h($label)?></option><?php endforeach; ?></select></label>
                        <label><span>只用正式物料</span><select name="require_official"><option value="1">是</option><option value="0">否</option></select></label>
                        <label><span>关键词过滤</span><input name="keyword" placeholder="例如 外置 / 内置 / CREE"></label>
                        <label><span>电源类型</span><select name="driver_type"><option value="">不限定</option><option value="external">只要外置电源</option><option value="internal">只要内置电源</option><option value="intrack">只要 INTRACK 电源</option></select></label>
                        <label><span>功率下限 W</span><input type="number" step="0.01" min="0" name="power_min_w"></label>
                        <label><span>功率上限 W</span><input type="number" step="0.01" min="0" name="power_max_w"></label>
                        <label><span>电流下限 mA</span><input type="number" step="0.01" min="0" name="current_min_ma"></label>
                        <label><span>电流上限 mA</span><input type="number" step="0.01" min="0" name="current_max_ma"></label>
                        <label><span>电压下限 V</span><input type="number" step="0.01" min="0" name="voltage_min_v"></label>
                        <label><span>电压上限 V</span><input type="number" step="0.01" min="0" name="voltage_max_v"></label>
                        <label><span>调光方式</span><input name="dimming_mode" placeholder="例如 DALI / 0-10V"></label>
                        <label><span>色温 K</span><input type="number" min="1000" max="20000" name="cct_k"></label>
                        <label><span>最低显指 CRI</span><input type="number" step="0.1" min="0" max="100" name="cri_min"></label>
                        <label><span>光束角 °</span><input type="number" step="0.1" min="0" max="180" name="beam_angle"></label>
                        <label class="wide"><span>逻辑备注</span><input name="note" placeholder="说明模板逻辑用途，例如嵌入式只允许外置电源"></label>
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
                                $templateGroupSettings = isset($g['settings']) && is_array($g['settings']) ? $g['settings'] : [];
                                $templateLogic = isset($templateGroupSettings['template_logic']) && is_array($templateGroupSettings['template_logic']) ? $templateGroupSettings['template_logic'] : [];
                                $templateBehavior = isset($templateGroupSettings['behavior']) && is_array($templateGroupSettings['behavior']) ? $templateGroupSettings['behavior'] : [];
                                $templateFilter = isset($templateBehavior['material_filter']) && is_array($templateBehavior['material_filter']) ? $templateBehavior['material_filter'] : [];
                                $templateLogicTags = [];
                                if (($templateFilter['driver_type'] ?? '') !== '') $templateLogicTags[] = ['internal'=>'内置电源','external'=>'外置电源','intrack'=>'INTRACK 电源'][(string)$templateFilter['driver_type']] ?? (string)$templateFilter['driver_type'];
                                if (($templateFilter['formal_status'] ?? '') === 'official') $templateLogicTags[] = '正式物料';
                                if (isset($templateLogic['power_min_w'], $templateLogic['power_max_w'])) $templateLogicTags[] = '功率 '.$templateLogic['power_min_w'].'-'.$templateLogic['power_max_w'].'W';
                                if (isset($templateLogic['current_min_ma'], $templateLogic['current_max_ma'])) $templateLogicTags[] = '电流 '.$templateLogic['current_min_ma'].'-'.$templateLogic['current_max_ma'].'mA';
                            ?>
                            <article class="pa2-group-card">
                                <div>
                                    <strong><?=mc_h($g['display_name'])?></strong><br>
                                    <small><?=mc_h($g['group_code'])?> · <?=mc_h($pa2InheritanceActionLabels[$templateGroupAction] ?? $templateGroupAction)?> · <?=($g['is_required']?'必选':'可选')?> · <?=mc_h($pa2SelectionModeLabels[$g['selection_mode']] ?? $g['selection_mode'])?> · <?=intval($g['min_select'])?>-<?=intval($g['max_select'])?> 项 · <?=((int)$g['allow_empty']===1?'允许为空':'不允许为空')?> · 价格<?=((int)$g['affects_price']===1?'是':'否')?> · 交期<?=((int)$g['affects_lead_time']===1?'是':'否')?> · 审批<?=((int)$g['requires_approval']===1?'是':'否')?> · 排序 <?=intval($g['sort_order'])?></small>
                                    <?php if ($templateLogicTags): ?><div class="pa2-logic-tags"><?php foreach ($templateLogicTags as $tag): ?><span><?=mc_h($tag)?></span><?php endforeach; ?></div><?php endif; ?>
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
                                        data-template-settings="<?=mc_h(pa2_json_encode($templateGroupSettings))?>"
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
                $wpSchemes = $workspace['schemes'] ?? [];
                $wpSchemeEditableGroups = [];
                foreach ($wpGroups as $schemeGroup) {
                    $schemeOptions = [];
                    foreach ((array)($schemeGroup['selected_options'] ?? []) as $schemeOption) {
                        if (!is_array($schemeOption)) continue;
                        $schemeOptionId = (int)($schemeOption['id'] ?? 0);
                        if ($schemeOptionId <= 0) continue;
                        $schemeLabel = pa2_option_display_label($schemeOption);
                        if ($schemeLabel === '') continue;
                        $schemeOptions[] = [
                            'id' => $schemeOptionId,
                            'label' => $schemeLabel,
                        ];
                    }
                    if ($schemeOptions) {
                        $wpSchemeEditableGroups[] = [
                            'code' => (string)($schemeGroup['group_code'] ?? ''),
                            'name' => (string)($schemeGroup['display_name'] ?? $schemeGroup['group_name'] ?? $schemeGroup['group_code'] ?? '配置组'),
                            'options' => $schemeOptions,
                        ];
                    }
                }
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
                            <button class="mc-button mc-button--primary" type="button" data-open-workspace-template>添加配置模板</button>
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
                        <input type="hidden" name="apply_mode" value="append">
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
                        <div><h3>添加配置模板</h3><p>可选择只添加新模板配置组，或添加后移除当前草稿里不属于新模板的旧配置组。</p></div>
                        <button class="mc-button" type="button" data-close-workspace-template>关闭</button>
                    </div>
                    <form data-pa2-form data-confirm="确认按所选处理方式应用模板？如果选择“添加并移除旧配置组”，旧组下已选内容会从当前 V2 草稿中移除。" action="<?=mc_h(mc_url('adaptation_v2/api/index.php?action=workspace_source_save'))?>">
                        <input type="hidden" name="product_id" value="<?=intval($workspaceProductId)?>">
                        <input type="hidden" name="series_code" value="<?=mc_h($wpProduct['series_code'] ?: $wpProduct['series_name'] ?: '')?>">
                        <div class="pa2-dialog__body">
                            <div class="pa2-dialog-form">
                                <div class="pa2-dialog-hint full">适合你说的场景：这只灯是嵌入式，就直接在这里选择“嵌入式模板”。默认“添加”不会删除已有配置；需要重套结构时再选“添加并移除旧配置组”。</div>
                                <label class="full"><span>配置模板 *</span><select name="template_id" required><option value="">请选择模板</option><?php foreach ($templates as $t): ?><?php if ((int)($t['is_enabled'] ?? 1) !== 1) continue; ?><option value="<?=intval($t['id'])?>" <?=((int)($wpTemplate['id'] ?? 0)===(int)$t['id']?'selected':'')?>><?=mc_h($t['template_name'])?> · <?=mc_h($t['template_level'])?> · <?=mc_h($t['category_name'] ?: '全局')?> · <?=intval($t['direct_group_count'] ?? 0)?> 组</option><?php endforeach; ?></select></label>
                                <label class="full"><span>处理方式</span><select name="apply_mode"><option value="append">添加：保留当前配置，只补齐模板新增配置组</option><option value="replace">添加并移除旧配置组：移除当前草稿中不属于新模板的配置组</option></select></label>
                            </div>
                        </div>
                        <div class="pa2-dialog__foot">
                            <span class="pa2-muted">不会修改模板本身，也不会修改旧 BOM。</span>
                            <div class="pa2-template-actions">
                                <button class="mc-button" type="button" data-close-workspace-template>取消</button>
                                <button class="mc-button mc-button--primary" type="submit">应用并刷新工作台</button>
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
                            $productLogic = isset($settings['product_logic']) && is_array($settings['product_logic']) ? $settings['product_logic'] : [];
                            $templateLogic = isset($settings['template_logic']) && is_array($settings['template_logic']) ? $settings['template_logic'] : [];
                            $logicSource = (string)($settings['logic_source'] ?? ($productLogic ? 'custom' : 'template'));
                            $required = !empty($settings['is_required']) || !empty($settings['is_required_default']);
                            $selected = $g['selected_options'] ?? [];
                            $selectedMaterialIds = array_values(array_filter(array_map(static fn($s) => (int)($s['material_id'] ?? 0), $selected)));
                            $selectionMode = (string)($settings['selection_mode'] ?? 'single');
                            $maxSelect = (int)($settings['max_select'] ?? 1);
                            $materialPickerMax = $maxSelect > 1 ? $maxSelect : 99;
                            $allowEmpty = (int)($settings['allow_empty'] ?? ($required ? 0 : 1));
                            $behavior = isset($settings['behavior']) && is_array($settings['behavior']) ? $settings['behavior'] : [];
                            $materialFilter = isset($behavior['material_filter']) && is_array($behavior['material_filter']) ? $behavior['material_filter'] : [];
                            $logicTags = [];
                            if ($logicSource === 'template') $logicTags[] = '使用模板逻辑';
                            elseif ($logicSource === 'custom') $logicTags[] = '产品自定义逻辑';
                            elseif ($logicSource === 'blank') $logicTags[] = '不使用逻辑';
                            if (($materialFilter['driver_type'] ?? '') !== '') $logicTags[] = ['internal'=>'内置电源','external'=>'外置电源','intrack'=>'INTRACK 电源'][(string)$materialFilter['driver_type']] ?? (string)$materialFilter['driver_type'];
                            if (($materialFilter['formal_status'] ?? '') === 'official') $logicTags[] = '只用正式物料';
                            if (isset($productLogic['power_max_w'])) $logicTags[] = '功率≤'.$productLogic['power_max_w'].'W';
                            if (isset($productLogic['current_min_ma'],$productLogic['current_max_ma'])) $logicTags[] = '电流 '.$productLogic['current_min_ma'].'-'.$productLogic['current_max_ma'].'mA';
                            if (isset($productLogic['voltage_min_v'],$productLogic['voltage_max_v'])) $logicTags[] = '电压 '.$productLogic['voltage_min_v'].'-'.$productLogic['voltage_max_v'].'V';
                            if (isset($productLogic['cct_k'])) $logicTags[] = '色温 '.$productLogic['cct_k'].'K';
                            if (isset($productLogic['cri_min'])) $logicTags[] = 'CRI≥'.$productLogic['cri_min'];
                            if (isset($productLogic['beam_angle'])) $logicTags[] = '光束角 '.$productLogic['beam_angle'].'°';
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
                                <div><strong><?=mc_h($g['icon'] ? $g['icon'].' ' : '')?><?=mc_h($g['display_name'])?></strong><br><small class="pa2-muted"><?=mc_h($g['group_code'])?> · <?=mc_h($selectionKind ?: $g['definition_type'])?> · <?=mc_h($required?'必选':'可选')?><?= $selectedMaterialIds ? ' · 已选 '.count($selectedMaterialIds).' 个' : '' ?></small></div>
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
                            <?php if ($logicTags): ?>
                                <div class="pa2-logic-tags"><?php foreach ($logicTags as $tag): ?><span><?=mc_h($tag)?></span><?php endforeach; ?></div>
                            <?php endif; ?>
                            <?php if (!$wpCanEditVersion): ?>
                                <span class="pa2-muted">当前版本已锁定，如需修改请生成下一版草稿。</span>
                            <?php elseif (in_array($selectionKind, ['material','hybrid'], true) || in_array($g['definition_type'], ['material_select','hybrid_select'], true)): ?>
                                <div class="pa2-card-actions">
                                    <button class="mc-button" type="button" data-open-group-logic data-group-id="<?=intval($g['id'])?>" data-group-code="<?=mc_h($g['group_code'])?>" data-group-name="<?=mc_h($g['display_name'])?>" data-is-required="<?=intval($required ? 1 : 0)?>" data-selection-mode="<?=mc_h($selectionMode)?>" data-min-select="<?=intval($settings['min_select'] ?? 0)?>" data-max-select="<?=intval($maxSelect)?>" data-allow-empty="<?=intval($allowEmpty)?>" data-material-category-code="<?=mc_h((string)($behavior['material_category_code'] ?? $g['material_category_code'] ?? ''))?>" data-material-filter="<?=mc_h(pa2_json_encode($materialFilter))?>" data-product-logic="<?=mc_h(pa2_json_encode($productLogic))?>" data-template-logic="<?=mc_h(pa2_json_encode($templateLogic))?>" data-logic-source="<?=mc_h($logicSource)?>">设置逻辑</button>
                                    <button class="mc-button mc-button--primary" type="button" data-open-material-picker data-group-id="<?=intval($g['id'])?>" data-group-code="<?=mc_h($g['group_code'])?>" data-group-name="<?=mc_h($g['display_name'])?>" data-selection-mode="<?=mc_h($selectionMode)?>" data-max-select="<?=intval($materialPickerMax)?>" data-selected-material-ids="<?=mc_h(pa2_json_encode($selectedMaterialIds))?>">添加/调整正式物料</button>
                                </div>
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
                    <?php if ($wpSchemes): ?>
                    <section class="pa2-panel pa2-scheme-panel">
                        <div class="pa2-panel__head">
                            <div><h2>配置方案 · <?=mc_h($wpVersion['version_no'] ?? 'V1')?></h2><p>系统只给 A / B / C 空白方案位；点击“编辑方案”，从当前已加入的芯片、电源、光学等选项里自己搭配。</p></div>
                        </div>
                        <div class="pa2-panel__body">
                            <div class="pa2-scheme-grid">
                                <?php foreach ($wpSchemes as $scheme): ?>
                                <article class="pa2-scheme-card <?=!empty($scheme['is_default'])?'is-selected':''?>" <?php if ($canConfigureProduct): ?>data-open-scheme-editor data-scheme-code="<?=mc_h($scheme['code'])?>" data-scheme-name="<?=mc_h($scheme['name'] ?? ('配置 '.$scheme['code']))?>" data-scheme-selections="<?=mc_h(pa2_json_encode($scheme['selection_ids'] ?? []))?>" data-scheme-can-save="<?=intval($wpCanEditVersion ? 1 : 0)?>" title="双击编辑这个配置方案"<?php endif; ?>>
                                    <div class="pa2-scheme-card__head">
                                        <strong><?=mc_h($scheme['name'] ?? ('配置 '.$scheme['code']))?><?=!empty($scheme['is_default'])?' · 已选择':''?></strong>
                                        <?php if ($canConfigureProduct): ?>
                                        <div class="pa2-scheme-actions">
                                            <button class="mc-button" type="button" data-open-scheme-editor data-scheme-code="<?=mc_h($scheme['code'])?>" data-scheme-name="<?=mc_h($scheme['name'] ?? ('配置 '.$scheme['code']))?>" data-scheme-selections="<?=mc_h(pa2_json_encode($scheme['selection_ids'] ?? []))?>" data-scheme-can-save="<?=intval($wpCanEditVersion ? 1 : 0)?>"><?= $wpCanEditVersion ? '编辑方案' : '查看方案' ?></button>
                                            <?php if ($wpCanEditVersion): ?>
                                            <form data-pa2-form action="<?=mc_h(mc_url('adaptation_v2/api/index.php?action=product_scheme_select'))?>">
                                                <input type="hidden" name="product_id" value="<?=intval($workspaceProductId)?>">
                                                <input type="hidden" name="scheme_code" value="<?=mc_h($scheme['code'])?>">
                                                <button class="mc-button" type="submit"><?=!empty($scheme['is_default'])?'当前采用':'设为采用'?></button>
                                            </form>
                                            <?php endif; ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="pa2-scheme-lines">
                                        <?php if (!empty($scheme['selections'])): foreach ((array)($scheme['selections'] ?? []) as $selection): ?>
                                            <div><b><?=mc_h($selection['group'])?>：</b><span><?=mc_h($selection['value'])?></span></div>
                                        <?php endforeach; else: ?>
                                            <div class="pa2-scheme-placeholder">空白方案。点击“编辑方案”，自己加入芯片、电源、光学或其他已选项。</div>
                                        <?php endif; ?>
                                    </div>
                                </article>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </section>
                    <?php endif; ?>
                    <dialog class="pa2-dialog pa2-dialog--narrow" id="pa2-scheme-dialog">
                        <div class="pa2-dialog__head">
                            <div><h3 id="pa2-scheme-dialog-title">编辑配置方案</h3><p>只从当前工作台已加入的选项中选择；如需更多物料，先在上方配置卡片里“添加/调整正式物料”。</p></div>
                            <button class="mc-button" type="button" data-close-scheme-editor>关闭</button>
                        </div>
                        <form data-pa2-form action="<?=mc_h(mc_url('adaptation_v2/api/index.php?action=product_scheme_save'))?>">
                            <input type="hidden" name="product_id" value="<?=intval($workspaceProductId)?>">
                            <input type="hidden" name="scheme_code" value="A">
                            <div class="pa2-dialog__body">
                                <div class="pa2-dialog-form">
                                    <?php if (!$wpCanEditVersion): ?>
                                        <div class="pa2-alert full">当前版本不是草稿/驳回状态，不能直接保存修改。请先生成下一版草稿，再编辑 A/B/C 方案。</div>
                                    <?php endif; ?>
                                    <div class="pa2-dialog-hint full">A / B / C 都是空白方案位。你可以让 A 用某个芯片+某个电源，B 用另一个芯片+另一个电源；没有选择的组会保持空白。</div>
                                    <label class="full"><span>方案名称</span><input name="scheme_name" value="配置 A" placeholder="例如 配置 A"></label>
                                    <?php if ($wpSchemeEditableGroups): foreach ($wpSchemeEditableGroups as $schemeGroup): ?>
                                        <label class="full"><span><?=mc_h($schemeGroup['name'])?></span>
                                            <select name="scheme_selection[<?=mc_h($schemeGroup['code'])?>]" data-scheme-select="<?=mc_h($schemeGroup['code'])?>">
                                                <option value="">不加入此组</option>
                                                <?php foreach ($schemeGroup['options'] as $schemeOption): ?>
                                                    <option value="<?=intval($schemeOption['id'])?>"><?=mc_h($schemeOption['label'])?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                    <?php endforeach; else: ?>
                                        <div class="pa2-alert full">当前还没有可搭配的已选物料。请先在芯片、电源、光学等配置卡片里添加正式物料，再回来编辑 A/B/C 方案。</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="pa2-dialog__foot">
                                <span class="pa2-muted">保存只影响当前 V2 草稿，不修改旧版适配或旧 BOM。</span>
                                <div class="pa2-template-actions">
                                    <button class="mc-button" type="button" data-close-scheme-editor>取消</button>
                                    <?php if ($wpCanEditVersion): ?>
                                        <button class="mc-button" type="submit">保存方案</button>
                                        <button class="mc-button mc-button--primary" type="submit" name="set_as_default" value="1">保存并采用</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </form>
                    </dialog>
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
            <dialog class="pa2-dialog pa2-dialog--logic" id="pa2-group-logic-dialog">
                <div class="pa2-dialog__head">
                    <div><strong id="pa2-group-logic-title">设置配置逻辑</strong><p class="pa2-muted" id="pa2-group-logic-subtitle">只保存到当前产品草稿，不修改模板和旧 BOM。</p></div>
                    <button class="mc-button" type="button" data-close-group-logic>关闭</button>
                </div>
                <form data-pa2-form action="<?=mc_h(mc_url('adaptation_v2/api/index.php?action=product_group_logic_save'))?>">
                    <input type="hidden" name="product_group_config_id">
                    <div class="pa2-dialog__body">
                        <div class="pa2-dialog-hint">这里设置的是“这个产品”的判断逻辑：例如嵌入式灯具只要外置电源、芯片要求 CRI90、电源电流要覆盖 250–300mA。保存后会立即重新计算当前候选结果。</div>
                        <div class="pa2-logic-form">
                            <div class="pa2-logic-section">基础规则</div>
                            <label class="wide"><span>逻辑来源</span><select name="logic_source"><option value="template">使用模板逻辑</option><option value="custom">自定义覆盖当前产品</option><option value="blank">不使用逻辑 / 清空当前产品逻辑</option></select></label>
                            <label><span>是否必选</span><select name="is_required"><option value="1">必选</option><option value="0">可选</option></select></label>
                            <label><span>选择方式</span><select name="selection_mode"><option value="single">单选</option><option value="multiple">多选</option></select></label>
                            <label><span>最少选择</span><input type="number" min="0" name="min_select"></label>
                            <label><span>最多选择</span><input type="number" min="1" name="max_select"></label>
                            <label><span>允许为空</span><select name="allow_empty"><option value="1">允许</option><option value="0">不允许</option></select></label>
                            <label><span>物料分类</span><select name="material_category_code"><option value="">不限定</option><?php foreach ($pa2MaterialCategoryLabels as $code=>$label): ?><option value="<?=mc_h($code)?>"><?=mc_h($label)?></option><?php endforeach; ?></select></label>
                            <label><span>只用正式物料</span><select name="require_official"><option value="1">是</option><option value="0">否</option></select></label>
                            <label><span>关键词过滤</span><input name="keyword" placeholder="例如 外置 / CREE / 透镜"></label>
                            <div class="pa2-logic-section">电源逻辑</div>
                            <label><span>电源类型</span><select name="driver_type"><option value="">不限定</option><option value="external">只要外置电源</option><option value="internal">只要内置电源</option><option value="intrack">只要 INTRACK 电源</option></select></label>
                            <label><span>功率下限 W</span><input type="number" step="0.01" min="0" name="power_min_w"></label>
                            <label><span>功率上限 W</span><input type="number" step="0.01" min="0" name="power_max_w"></label>
                            <label><span>调光方式</span><input name="dimming_mode" placeholder="例如 DALI / 0-10V"></label>
                            <label><span>电流下限 mA</span><input type="number" step="0.01" min="0" name="current_min_ma"></label>
                            <label><span>电流上限 mA</span><input type="number" step="0.01" min="0" name="current_max_ma"></label>
                            <label><span>电压下限 V</span><input type="number" step="0.01" min="0" name="voltage_min_v"></label>
                            <label><span>电压上限 V</span><input type="number" step="0.01" min="0" name="voltage_max_v"></label>
                            <div class="pa2-logic-section">芯片 / 光学逻辑</div>
                            <label><span>色温 K</span><input type="number" min="1000" max="20000" name="cct_k"></label>
                            <label><span>最低显指 CRI</span><input type="number" step="0.1" min="0" max="100" name="cri_min"></label>
                            <label><span>光束角 °</span><input type="number" step="0.1" min="0" max="180" name="beam_angle"></label>
                            <label class="wide"><span>备注</span><input name="note" placeholder="说明这条产品级逻辑的原因"></label>
                        </div>
                    </div>
                    <div class="pa2-dialog__foot">
                        <span class="pa2-muted">产品级覆盖只影响当前草稿；要沉淀为通用规则，可后续再同步到模板。</span>
                        <div class="pa2-picker-actions"><button class="mc-button" type="button" data-close-group-logic>取消</button><button class="mc-button mc-button--primary" type="submit">保存并重新计算</button></div>
                    </div>
                </form>
            </dialog>
            <dialog class="pa2-dialog" id="pa2-material-dialog">
                <div class="pa2-dialog__head"><div><strong id="pa2-material-title">选择正式物料</strong><p class="pa2-muted">每条候选会即时显示完全适配、条件适配、需要审批或不适配，并给出明确原因。</p></div><button class="mc-button" type="button" data-close-material-picker>关闭</button></div>
                <div class="pa2-dialog__body">
                    <form class="pa2-mini-form" id="pa2-material-search"><input name="q" placeholder="搜索物料编号 / 名称 / 品牌 / 型号"><button class="mc-button" type="submit">搜索</button></form>
                    <div class="pa2-candidate-list" id="pa2-material-list"><div class="pa2-placeholder">正在等待选择配置组。</div></div>
                </div>
                <div class="pa2-dialog__foot">
                    <span class="pa2-picker-summary" id="pa2-material-summary">可勾选多个物料后统一保存。</span>
                    <div class="pa2-picker-actions">
                        <button class="mc-button" type="button" data-close-material-picker>取消</button>
                        <button class="mc-button mc-button--primary" type="button" id="pa2-material-confirm">保存所选物料</button>
                    </div>
                </div>
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
                    <div><h2>配置包中心</h2><p>按渠道沉淀可发布配置包；第 8 阶段只服务 V2，不暴露给旧版适配。</p></div>
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
                <div><h2>最终验收 / 切换评估</h2><p>正式入口已切到 V2；这里继续记录业务回归、配置包和下游联动验收。</p></div>
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
                        <p class="pa2-muted">当前状态：<?=mc_h($cutoverReadiness['status'] ?? 'unknown')?>；阻断项 <?=count($cutoverReadiness['blockers'] ?? [])?> 个。正式产品适配入口已指向 V2。</p>
                    </div>
                    <span class="pa2-pill <?=($cutoverReadiness['ready_to_switch'] ?? false)?'pa2-pill--ok':'pa2-pill--warn'?>"><?=($cutoverReadiness['ready_to_switch'] ?? false)?'可进入切换审批':'禁止切换'?></span>
                </div>
                <?php if (!empty($cutoverReadiness['blockers'])): ?>
                <section class="pa2-panel">
                    <div class="pa2-panel__head"><div><h2>阻断项</h2><p>以下项目用于继续跟踪真实业务回归，不影响当前产品适配入口已切到 V2。</p></div></div>
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
    const clickedButton=event.submitter instanceof HTMLButtonElement ? event.submitter : null;
    const button=clickedButton || form.querySelector('button[type="submit"]');
    if(button) button.disabled=true;
    try{
      const formData=new FormData(form);
      if(clickedButton && clickedButton.name) formData.append(clickedButton.name, clickedButton.value || '1');
      const res=await fetch(form.action,{method:'POST',body:formData,credentials:'same-origin'});
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
function pa2ParseJson(raw, fallback={}){
  try{
    const value=JSON.parse(raw||'{}');
    return value&&typeof value==='object'?value:fallback;
  }catch(err){return fallback;}
}
(()=>{
  const dialog=document.getElementById('pa2-scheme-dialog');
  if(!dialog)return;
  const form=dialog.querySelector('form');
  const title=document.getElementById('pa2-scheme-dialog-title');
  const codeInput=form?.elements['scheme_code'];
  const nameInput=form?.elements['scheme_name'];
  function setSchemeFormEnabled(canSave){
    dialog.querySelectorAll('[data-scheme-select]').forEach((select)=>{
      if(select instanceof HTMLSelectElement) select.disabled=!canSave;
    });
    if(nameInput) nameInput.disabled=!canSave;
  }
  function openSchemeEditor(source){
      const code=source.getAttribute('data-scheme-code')||'A';
      const name=source.getAttribute('data-scheme-name')||('配置 '+code);
      const selections=pa2ParseJson(source.getAttribute('data-scheme-selections')||'{}',{});
      const canSave=(source.getAttribute('data-scheme-can-save')||'1')==='1';
      if(title) title.textContent='编辑配置方案 · '+code;
      if(codeInput) codeInput.value=code;
      if(nameInput) nameInput.value=name;
      setSchemeFormEnabled(canSave);
      dialog.querySelectorAll('[data-scheme-select]').forEach((select)=>{
        if(!(select instanceof HTMLSelectElement))return;
        const groupCode=select.getAttribute('data-scheme-select')||'';
        select.value=selections[groupCode] ? String(selections[groupCode]) : '';
      });
      pa2OpenDialog(dialog);
  }
  document.addEventListener('click',(event)=>{
    const button=event.target instanceof Element ? event.target.closest('button[data-open-scheme-editor]') : null;
    if(!button)return;
    event.preventDefault();
    openSchemeEditor(button);
  });
  document.addEventListener('dblclick',(event)=>{
    const card=event.target instanceof Element ? event.target.closest('.pa2-scheme-card[data-open-scheme-editor]') : null;
    if(!card)return;
    if(event.target instanceof Element && event.target.closest('button,form,a,input,select,textarea')) return;
    event.preventDefault();
    openSchemeEditor(card);
  });
  document.querySelectorAll('[data-close-scheme-editor]').forEach((btn)=>btn.addEventListener('click',()=>pa2CloseDialog(dialog)));
})();
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
      const settings=pa2ParseJson(btn.getAttribute('data-template-settings')||'{}');
      const logic=settings.template_logic||{};
      const behavior=settings.behavior||{};
      const filter=behavior.material_filter||{};
      pa2SetField(form,'material_category_code',behavior.material_category_code||'');
      pa2SetField(form,'require_official',filter.formal_status==='official'?'1':'0');
      pa2SetField(form,'keyword',filter.keyword||'');
      pa2SetField(form,'driver_type',filter.driver_type||'');
      ['power_min_w','power_max_w','current_min_ma','current_max_ma','voltage_min_v','voltage_max_v','cct_k','cri_min','beam_angle','dimming_mode','note'].forEach((key)=>{
        pa2SetField(form,key,logic[key]||'');
      });
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
  const dialog=document.getElementById('pa2-group-logic-dialog');
  const form=dialog?dialog.querySelector('form'):null;
  const title=document.getElementById('pa2-group-logic-title');
  const subtitle=document.getElementById('pa2-group-logic-subtitle');
  if(!dialog||!form)return;
  function parseJsonAttr(btn,name){
    return pa2ParseJson(btn.getAttribute(name)||'{}',{});
  }
  function value(obj,key,def=''){
    return obj&&Object.prototype.hasOwnProperty.call(obj,key)?obj[key]:def;
  }
  document.querySelectorAll('[data-open-group-logic]').forEach((btn)=>{
    btn.addEventListener('click',()=>{
      form.reset();
      const groupCode=btn.getAttribute('data-group-code')||'';
      const filter=parseJsonAttr(btn,'data-material-filter');
      const logic=parseJsonAttr(btn,'data-product-logic');
      const templateLogic=parseJsonAttr(btn,'data-template-logic');
      pa2SetField(form,'product_group_config_id',btn.getAttribute('data-group-id')||'');
      pa2SetField(form,'logic_source',btn.getAttribute('data-logic-source')||'template');
      pa2SetField(form,'is_required',btn.getAttribute('data-is-required')||'0');
      pa2SetField(form,'selection_mode',btn.getAttribute('data-selection-mode')||'single');
      pa2SetField(form,'min_select',btn.getAttribute('data-min-select')||'0');
      pa2SetField(form,'max_select',btn.getAttribute('data-max-select')||'1');
      pa2SetField(form,'allow_empty',btn.getAttribute('data-allow-empty')||'1');
      pa2SetField(form,'material_category_code',btn.getAttribute('data-material-category-code')||'');
      pa2SetField(form,'require_official',value(filter,'formal_status','official')==='official'?'1':'0');
      pa2SetField(form,'keyword',value(filter,'keyword',''));
      let driverType=value(filter,'driver_type','');
      if(!driverType&&groupCode==='external_driver')driverType='external';
      if(!driverType&&groupCode==='intrack_driver')driverType='intrack';
      if(!driverType&&groupCode==='driver')driverType='';
      pa2SetField(form,'driver_type',driverType);
      const visibleLogic=Object.keys(logic).length?logic:templateLogic;
      ['power_min_w','power_max_w','current_min_ma','current_max_ma','voltage_min_v','voltage_max_v','cct_k','cri_min','beam_angle','dimming_mode','note'].forEach((key)=>{
        pa2SetField(form,key,value(visibleLogic,key,''));
      });
      if(title) title.textContent='设置逻辑 · '+(btn.getAttribute('data-group-name')||groupCode);
      if(subtitle) subtitle.textContent='当前产品级覆盖，不修改模板：'+(groupCode||'配置组');
      pa2OpenDialog(dialog);
    });
  });
  document.querySelectorAll('[data-close-group-logic]').forEach((btn)=>{
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
  const summary=document.getElementById('pa2-material-summary');
  const confirmBtn=document.getElementById('pa2-material-confirm');
  let currentGroupId='';
  let currentGroupCode='';
  let currentMaxSelect=99;
  let selectedMaterialIds=new Set();
  function updateSummary(){
    if(!summary)return;
    const count=selectedMaterialIds.size;
    const maxText=currentMaxSelect>0&&currentMaxSelect<99?`最多 ${currentMaxSelect} 个`:'可选多个';
    summary.textContent=`已选 ${count} 个，${maxText}；取消勾选后保存即可移除。`;
  }
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
        const id=String(m.id);
        const checked=selectedMaterialIds.has(id);
        return `
        <div class="pa2-candidate ${checked?'is-selected':''}">
          <div><strong>${escapeHtml(m.material_code||('#'+m.id))} ${escapeHtml(m.name||'')}</strong><br><small>${escapeHtml([m.brand,m.model,m.category_name,m.status].filter(Boolean).join(' · '))}</small><br><small>${escapeHtml(m.spec_summary||'')}</small><br><span class="pa2-badge ${cls}">${label}${score}</span> <small>${escapeHtml(reason)}</small></div>
          <label class="pa2-candidate-check">
            <input type="checkbox" data-pa2-picker-check value="${escapeHtml(id)}" ${checked?'checked':''}>
            <span>${checked?'已选':'选择'}</span>
          </label>
        </div>`}).join('');
      updateSummary();
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
      currentMaxSelect=parseInt(btn.getAttribute('data-max-select')||'99',10)||99;
      selectedMaterialIds=new Set();
      try{
        const ids=JSON.parse(btn.getAttribute('data-selected-material-ids')||'[]');
        if(Array.isArray(ids)) ids.forEach((id)=>{if(parseInt(id,10)>0) selectedMaterialIds.add(String(parseInt(id,10)));});
      }catch(err){}
      if(title) title.textContent='选择正式物料 · '+(btn.getAttribute('data-group-name')||currentGroupCode);
      updateSummary();
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
  document.addEventListener('change',(event)=>{
    const input=event.target;
    if(!(input instanceof HTMLInputElement)||!input.matches('[data-pa2-picker-check]'))return;
    const id=String(parseInt(input.value||'0',10));
    if(input.checked){
      if(currentMaxSelect>0&&currentMaxSelect<99&&selectedMaterialIds.size>=currentMaxSelect&&!selectedMaterialIds.has(id)){
        input.checked=false;
        alert('当前配置组最多可选择 '+currentMaxSelect+' 个物料。');
        return;
      }
      selectedMaterialIds.add(id);
      input.closest('.pa2-candidate')?.classList.add('is-selected');
      const text=input.parentElement?.querySelector('span');
      if(text) text.textContent='已选';
    }else{
      selectedMaterialIds.delete(id);
      input.closest('.pa2-candidate')?.classList.remove('is-selected');
      const text=input.parentElement?.querySelector('span');
      if(text) text.textContent='选择';
    }
    updateSummary();
  });
  if(confirmBtn){
    confirmBtn.addEventListener('click',async()=>{
      if(!currentGroupId)return;
      if(!selectedMaterialIds.size){alert('请至少选择一个正式物料。');return;}
      if(currentMaxSelect>0&&currentMaxSelect<99&&selectedMaterialIds.size>currentMaxSelect){alert('当前配置组最多可选择 '+currentMaxSelect+' 个物料。');return;}
      confirmBtn.disabled=true;
      const formData=new FormData();
      formData.set('product_group_config_id', currentGroupId);
      formData.set('option_type', 'material');
      formData.set('replace', '1');
      Array.from(selectedMaterialIds).forEach((id)=>formData.append('material_ids[]', id));
    try{
      const res=await fetch('<?=mc_h(mc_url('adaptation_v2/api/index.php?action=product_group_save'))?>',{method:'POST',body:formData,credentials:'same-origin'});
      const data=await res.json();
      if(!data.success) throw new Error(data.message||'保存失败');
      location.reload();
    }catch(err){
      alert(err.message||'保存失败');
      confirmBtn.disabled=false;
    }
    });
  }
})();
</script>
<?php include MC_ROOT . '/components/layout_bottom.php'; ?>
