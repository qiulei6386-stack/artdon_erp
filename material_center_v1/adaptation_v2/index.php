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
    'logs',
];
if (!in_array($view, $allowedViews, true)) $view = 'home';

$pageTitle = '产品适配 V2';
$pageDescription = '第 7 阶段：产品差异、审批和版本。';
$summary = pa2_foundation_summary();
$categories = pa2_fetch_categories();
$groups = pa2_fetch_groups(true);
$groupsById = [];
foreach ($groups as $groupRow) $groupsById[(int)$groupRow['id']] = $groupRow;
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
$canApproveProduct = pa2_can_any(['adaptation_v2.approve_product', 'material_center.adaptation.manage']);
$canPublishProduct = pa2_can_any(['adaptation_v2.publish_product', 'material_center.adaptation.manage']);
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

$routeCards = [
    ['home', '首页', 'V2 基础状态、模板状态和阶段入口。'],
    ['products', '全部产品 / 映射', '查看产品并维护产品分类映射。'],
    ['categories', '产品分类中心', '维护产品分类、父子分类、启停和排序。'],
    ['groups', '配置组定义中心', '维护数据化配置组和属性选项。'],
    ['templates', '模板中心', '维护通用、分类、系列和产品模板。'],
    ['rules', '规则编辑器', '第 4 阶段维护显示条件、物料过滤、默认项和循环检测。'],
    ['workspace', '单产品配置工作台', '第 7 阶段支持草稿、提交、审批、发布、版本差异和回滚。'],
    ['packages', '配置包中心', '第 8 阶段发布渠道配置包。'],
    ['publish', '渠道发布', '第 9 阶段提供下游发布接口。'],
    ['approvals', '审批中心', '第 7 阶段接入审批和发布。'],
    ['logs', '日志与版本', '查看 V2 执行记录和阶段文档。'],
];

if (!function_exists('pa2_view_url')) {
    function pa2_view_url(string $view, array $query = []): string
    {
        $query = array_merge(['view' => $view], $query);
        return mc_url('adaptation_v2/index.php?' . http_build_query($query));
    }
}

include MC_ROOT . '/components/layout_top.php';
?>
<style>
.mc-pa2-page{--pa2-teal:#0f9f9a;--pa2-blue:#2563eb;--pa2-border:#dbe7f3;--pa2-muted:#667085;--pa2-soft:#f7fbfc;display:grid;gap:18px}
.pa2-hero{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;background:radial-gradient(circle at 12% 8%,#e7fffb 0,#fff 35%,#f7fbff 100%);border:1px solid var(--pa2-border);border-radius:22px;padding:24px;box-shadow:0 18px 45px rgba(15,159,154,.08)}
.pa2-hero h1{margin:0 0 6px;font-size:28px;color:#122033}.pa2-hero p{margin:0;color:var(--pa2-muted)}
.pa2-actions{display:flex;gap:10px;flex-wrap:wrap}.pa2-pill{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--pa2-border);border-radius:999px;padding:6px 10px;background:#fff;color:#344054;font-size:13px}.pa2-pill--ok{background:#ecfdf3;color:#067647;border-color:#abefc6}.pa2-pill--warn{background:#fffaeb;color:#b54708;border-color:#fedf89}
.pa2-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.pa2-card{background:linear-gradient(180deg,#fff,#fbfdff);border:1px solid var(--pa2-border);border-radius:18px;padding:17px;box-shadow:0 10px 28px rgba(16,24,40,.05)}.pa2-card strong{display:block;color:#122033}.pa2-card b{font-size:26px}.pa2-card span,.pa2-card p{color:var(--pa2-muted)}
.pa2-tabs{display:flex;gap:10px;flex-wrap:wrap}.pa2-tabs a{border:1px solid var(--pa2-border);background:#fff;border-radius:999px;padding:9px 14px;color:#344054;text-decoration:none;font-weight:700}.pa2-tabs a.is-active{background:var(--pa2-teal);border-color:var(--pa2-teal);color:#fff}
.pa2-panel{background:#fff;border:1px solid var(--pa2-border);border-radius:18px;overflow:hidden}.pa2-panel__head{display:flex;justify-content:space-between;gap:14px;align-items:center;padding:16px 18px;border-bottom:1px solid var(--pa2-border)}.pa2-panel__head h2{margin:0;font-size:20px}.pa2-panel__head p{margin:4px 0 0;color:var(--pa2-muted)}
.pa2-panel__body{padding:18px}.pa2-form{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;align-items:end}.pa2-form label{display:grid;gap:6px;color:#344054;font-weight:700}.pa2-form input,.pa2-form select,.pa2-form textarea{width:100%;border:1px solid #cfd8e6;border-radius:10px;padding:9px 10px;background:#fff}.pa2-form .wide{grid-column:span 2}.pa2-form .full{grid-column:1/-1}
.pa2-table{width:100%;border-collapse:separate;border-spacing:0}.pa2-table th,.pa2-table td{border-bottom:1px solid #e6edf5;padding:11px;text-align:left;vertical-align:top}.pa2-table th{background:#f8fafc;color:#344054;font-size:13px}.pa2-table code{background:#f1f5f9;border-radius:6px;padding:2px 6px}.pa2-mini-form{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.pa2-mini-form input,.pa2-mini-form select{border:1px solid #cfd8e6;border-radius:9px;padding:7px 9px;min-width:110px}.pa2-options{display:flex;gap:6px;flex-wrap:wrap}.pa2-options span{background:#eef4ff;color:#1d4ed8;border-radius:999px;padding:4px 8px;font-size:12px}
.pa2-alert{border:1px solid #fedf89;background:#fffaeb;color:#93370d;border-radius:14px;padding:14px}.pa2-muted{color:var(--pa2-muted)}.pa2-section-gap{display:grid;gap:16px}.pa2-placeholder{padding:34px;text-align:center;color:var(--pa2-muted)}
.pa2-template-shell{display:grid;grid-template-columns:280px minmax(0,1fr) 360px;gap:16px;align-items:start}.pa2-template-list{display:grid;gap:10px}.pa2-template-item{display:block;text-decoration:none;color:inherit;border:1px solid var(--pa2-border);border-radius:16px;padding:14px;background:#fff}.pa2-template-item.is-active{border-color:var(--pa2-teal);box-shadow:0 12px 30px rgba(15,159,154,.12)}.pa2-template-item strong{display:block}.pa2-template-item span{color:var(--pa2-muted);font-size:13px}.pa2-flow{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.pa2-flow span{background:#eef8f8;color:#0b7773;border:1px solid #c9eeeb;border-radius:999px;padding:6px 10px}.pa2-group-grid{display:grid;gap:10px}.pa2-group-card{display:grid;grid-template-columns:1fr auto;gap:10px;border:1px solid var(--pa2-border);border-radius:16px;padding:13px;background:#fff}.pa2-group-card small{color:var(--pa2-muted)}.pa2-badge{display:inline-flex;align-items:center;border-radius:999px;padding:4px 8px;font-size:12px;background:#eef4ff;color:#1d4ed8}.pa2-badge--add{background:#ecfdf3;color:#067647}.pa2-badge--override{background:#fff7ed;color:#c2410c}.pa2-badge--disable{background:#fef2f2;color:#b42318}.pa2-badge--match{background:#ecfdf3;color:#067647}.pa2-badge--condition{background:#fffaeb;color:#b54708}.pa2-badge--approval{background:#eef4ff;color:#1d4ed8}.pa2-badge--block{background:#fef2f2;color:#b42318}.pa2-side-note{background:var(--pa2-soft);border:1px dashed #c9d8e8;border-radius:16px;padding:14px;color:#344054}.pa2-two-col{display:grid;grid-template-columns:1fr 1fr;gap:12px}.pa2-template-actions{display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end}.pa2-result-note{display:grid;gap:5px;border-top:1px dashed #dbe7f3;padding-top:8px}.pa2-result-note small{color:var(--pa2-muted)}.pa2-engine-summary{display:flex;gap:8px;flex-wrap:wrap;margin-top:6px}.pa2-engine-summary span{font-size:12px;color:#344054}
.pa2-rule-board{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(360px,.9fr);gap:16px;align-items:start}.pa2-rule-card{border:1px solid var(--pa2-border);border-radius:16px;padding:14px;background:linear-gradient(180deg,#fff,#fbfdff);display:grid;gap:8px}.pa2-rule-card.is-cycle{border-color:#fda29b;background:#fff7f7}.pa2-rule-line{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.pa2-chip{display:inline-flex;align-items:center;border-radius:999px;padding:5px 9px;background:#f2f4f7;color:#344054;font-size:12px}.pa2-chip--show{background:#ecfdf3;color:#067647}.pa2-chip--hide{background:#fef3f2;color:#b42318}.pa2-chip--filter{background:#eff8ff;color:#175cd3}.pa2-behavior{display:grid;gap:8px}.pa2-behavior summary{cursor:pointer;color:#0b7773;font-weight:800}.pa2-json{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:12px;white-space:pre-wrap;background:#f8fafc;border:1px solid #e6edf5;border-radius:10px;padding:8px;max-width:360px}
.pa2-workspace{display:grid;gap:16px}.pa2-product-hero{display:grid;grid-template-columns:96px minmax(0,1fr) auto;gap:18px;align-items:center;background:#fff;border:1px solid var(--pa2-border);border-radius:20px;padding:18px}.pa2-product-hero img{width:88px;height:88px;object-fit:contain;border:1px solid #e6edf5;border-radius:14px;background:#f8fafc}.pa2-steps{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.pa2-step{border:1px solid var(--pa2-border);border-radius:16px;padding:14px;background:#fff}.pa2-step b{display:inline-flex;width:24px;height:24px;align-items:center;justify-content:center;border-radius:50%;background:#e6fffb;color:#0b7773;margin-right:8px}.pa2-work-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.pa2-config-card{border:1px solid var(--pa2-border);border-radius:18px;background:#fff;padding:14px;display:grid;gap:10px;min-height:190px}.pa2-config-card.is-missing{border-color:#fedf89;background:#fffdf7}.pa2-config-card.is-done{border-color:#abefc6}.pa2-config-card__head{display:flex;justify-content:space-between;gap:8px}.pa2-selected{display:grid;gap:6px}.pa2-selected span{background:#f2f4f7;border-radius:10px;padding:7px 9px}.pa2-footerbar{display:flex;justify-content:space-between;gap:12px;align-items:center;border:1px solid var(--pa2-border);border-radius:18px;background:#fff;padding:14px 16px;position:sticky;bottom:10px;box-shadow:0 12px 32px rgba(16,24,40,.08)}.pa2-dialog{border:0;border-radius:20px;padding:0;width:min(980px,92vw);box-shadow:0 24px 80px rgba(16,24,40,.28)}.pa2-dialog::backdrop{background:rgba(15,23,42,.32)}.pa2-dialog__head,.pa2-dialog__foot{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:16px 18px;border-bottom:1px solid var(--pa2-border)}.pa2-dialog__foot{border-top:1px solid var(--pa2-border);border-bottom:0}.pa2-dialog__body{padding:16px 18px;max-height:62vh;overflow:auto}.pa2-candidate-list{display:grid;gap:10px}.pa2-candidate{display:grid;grid-template-columns:1fr auto;gap:12px;align-items:center;border:1px solid #e6edf5;border-radius:14px;padding:12px}.pa2-candidate small{color:var(--pa2-muted)}
@media(max-width:1100px){.pa2-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.pa2-form{grid-template-columns:repeat(2,minmax(0,1fr))}.pa2-hero{display:grid}}@media(max-width:700px){.pa2-grid,.pa2-form{grid-template-columns:1fr}.pa2-form .wide{grid-column:auto}}
@media(max-width:1280px){.pa2-template-shell,.pa2-rule-board{grid-template-columns:1fr}.pa2-work-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.pa2-template-actions{justify-content:flex-start}}@media(max-width:760px){.pa2-product-hero,.pa2-footerbar{display:grid}.pa2-steps,.pa2-work-grid{grid-template-columns:1fr}}
</style>
<section class="mc-page mc-pa2-page" data-adaptation-v2 data-phase="7" data-view="<?=mc_h($view)?>">
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
            <article class="pa2-card"><strong>已发布版本</strong><b><?=intval($summary['published_version_count'] ?? 0)?></b><p>审批事件 <?=intval($summary['approval_event_count'] ?? 0)?> 条；结果缓存 <?=intval($summary['adaptation_result_count'] ?? 0)?> 条。</p></article>
        </section>
        <section class="pa2-panel">
            <div class="pa2-panel__head"><div><h2>阶段路由和边界</h2><p>第 7 阶段开放产品版本生命周期：草稿、提交、审批、驳回、发布、差异、快照和回滚；配置包与下游接口仍按后续阶段开发。</p></div><span class="pa2-pill <?=intval($summary['rule_cycle_count'])===0?'pa2-pill--ok':'pa2-pill--warn'?>">规则循环 <?=intval($summary['rule_cycle_count'])?> 个</span></div>
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
            <div class="pa2-panel__head"><div><h2>产品分类中心</h2><p>维护 V2 产品业务分类；分类不是物料分类，后续用于模板继承和配置包发布。</p></div></div>
            <div class="pa2-panel__body pa2-section-gap">
                <?php if ($canManageCategory): ?>
                <form class="pa2-form" data-pa2-form action="<?=mc_h(mc_url('adaptation_v2/api/index.php?action=category_save'))?>">
                    <label><span>分类编码</span><input name="category_code" placeholder="例如 track_light"></label>
                    <label><span>分类名称 *</span><input name="category_name" required placeholder="例如 导轨灯"></label>
                    <label><span>父分类</span><select name="parent_id"><option value="">无父分类</option><?php foreach ($categories as $c): ?><option value="<?=intval($c['id'])?>"><?=mc_h($c['category_name'])?></option><?php endforeach; ?></select></label>
                    <label><span>排序</span><input type="number" name="sort_order" value="100"></label>
                    <label class="wide"><span>说明</span><input name="description" placeholder="分类用途和适用范围"></label>
                    <label><span>状态</span><select name="is_enabled"><option value="1">启用</option><option value="0">停用</option></select></label>
                    <button class="mc-button mc-button--primary" type="submit">新增分类</button>
                </form>
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
                                <form class="pa2-mini-form" data-pa2-form action="<?=mc_h(mc_url('adaptation_v2/api/index.php?action=category_save'))?>">
                                    <input type="hidden" name="id" value="<?=intval($c['id'])?>">
                                    <input name="category_code" value="<?=mc_h($c['category_code'])?>">
                                    <input name="category_name" value="<?=mc_h($c['category_name'])?>" required>
                                    <select name="parent_id"><option value="">无父分类</option><?php foreach ($categories as $p): if ((int)$p['id'] === (int)$c['id']) continue; ?><option value="<?=intval($p['id'])?>" <?=((int)($c['parent_id'] ?? 0)===(int)$p['id']?'selected':'')?>><?=mc_h($p['category_name'])?></option><?php endforeach; ?></select>
                                    <input type="number" name="sort_order" value="<?=intval($c['sort_order'])?>">
                                    <select name="is_enabled"><option value="1" <?=((int)$c['is_enabled']===1?'selected':'')?>>启用</option><option value="0" <?=((int)$c['is_enabled']===0?'selected':'')?>>停用</option></select>
                                    <button class="mc-button" type="submit">保存</button>
                                </form>
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
            <div class="pa2-panel__head"><div><h2>配置组定义中心</h2><p>第 4 阶段：配置组可以设置物料来源、属性来源、默认项、必选/可选、单选/多选和数量限制。</p></div><a class="mc-button mc-button--primary" href="<?=mc_h(pa2_view_url('rules'))?>">打开规则编辑器</a></div>
            <div class="pa2-panel__body pa2-section-gap">
                <?php if ($canManageGroup): ?>
                <form class="pa2-form" data-pa2-form action="<?=mc_h(mc_url('adaptation_v2/api/index.php?action=group_save'))?>">
                    <label><span>组编码</span><input name="group_code" placeholder="例如 chip"></label>
                    <label><span>配置组名称 *</span><input name="group_name" required placeholder="例如 芯片 / 光源"></label>
                    <label><span>组类型</span><select name="group_type"><option value="material_select">物料选择</option><option value="enum_select">属性选择</option><option value="hybrid_select">混合选择</option><option value="number_input">数值输入</option><option value="text_input">文本输入</option><option value="boolean">布尔开关</option></select></label>
                    <label><span>排序</span><input type="number" name="sort_order" value="100"></label>
                    <label><span>图标</span><input name="icon" maxlength="40"></label>
                    <label class="wide"><span>说明</span><input name="description" placeholder="组用途、来源和业务含义"></label>
                    <label><span>状态</span><select name="is_enabled"><option value="1">启用</option><option value="0">停用</option></select></label>
                    <button class="mc-button mc-button--primary" type="submit">新增配置组</button>
                </form>
                <?php endif; ?>
                <table class="pa2-table">
                    <thead><tr><th>编码</th><th>配置组</th><th>类型</th><th>属性选项</th><th>行为 / 来源</th><th>排序/状态</th><th>编辑</th></tr></thead>
                    <tbody>
                    <?php foreach ($groups as $g): ?>
                        <tr>
                            <td><code><?=mc_h($g['group_code'])?></code></td>
                            <td><strong><?=mc_h($g['icon'] ? $g['icon'] . ' ' : '')?><?=mc_h($g['group_name'])?></strong><br><span class="pa2-muted"><?=mc_h($g['description'] ?? '')?></span></td>
                            <td><?=mc_h($g['group_type'])?></td>
                            <td>
                                <div class="pa2-options"><?php foreach ($g['options'] as $o): ?><span><?=mc_h($o['option_name'])?><?=((int)$o['is_default']===1?' · 默认':'')?></span><?php endforeach; ?></div>
                                <?php if ($canManageGroup && in_array($g['group_type'], ['enum_select','hybrid_select','boolean'], true)): ?>
                                <form class="pa2-mini-form" data-pa2-form action="<?=mc_h(mc_url('adaptation_v2/api/index.php?action=group_option_save'))?>">
                                    <input type="hidden" name="group_definition_id" value="<?=intval($g['id'])?>">
                                    <input name="option_code" placeholder="选项编码">
                                    <input name="option_name" placeholder="选项名称" required>
                                    <label><input type="checkbox" name="is_default" value="1"> 默认</label>
                                    <button class="mc-button" type="submit">新增选项</button>
                                </form>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php $behavior = $g['behavior'] ?? null; ?>
                                <?php if ($behavior): ?>
                                    <div class="pa2-behavior">
                                        <div><span class="pa2-badge"><?=mc_h($behavior['selection_kind'])?></span> <span class="pa2-chip"><?=mc_h($behavior['source_mode'])?></span></div>
                                        <div class="pa2-muted">物料分类：<?=mc_h($behavior['material_category_code'] ?: '—')?> · <?=((int)$behavior['is_required_default']===1?'必选':'可选')?> · <?=mc_h($behavior['selection_mode_default'])?> · <?=intval($behavior['min_select_default'])?>-<?=intval($behavior['max_select_default'])?></div>
                                        <?php if (!empty($behavior['material_filter'])): ?><details><summary>物料过滤器</summary><pre class="pa2-json"><?=mc_h(pa2_json_encode($behavior['material_filter']))?></pre></details><?php endif; ?>
                                        <?php if (!empty($behavior['visibility_condition'])): ?><details><summary>显示条件</summary><pre class="pa2-json"><?=mc_h(pa2_json_encode($behavior['visibility_condition']))?></pre></details><?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="pa2-muted">未设置行为</span>
                                <?php endif; ?>
                                <?php if ($canManageRule): ?>
                                <details class="pa2-behavior">
                                    <summary>编辑行为</summary>
                                    <form class="pa2-mini-form" data-pa2-form action="<?=mc_h(mc_url('adaptation_v2/api/index.php?action=group_behavior_save'))?>">
                                        <input type="hidden" name="group_definition_id" value="<?=intval($g['id'])?>">
                                        <select name="selection_kind">
                                            <?php foreach (['material'=>'物料选择组','attribute'=>'属性选择组','hybrid'=>'混合选择组','number'=>'数值组','text'=>'文本组'] as $k=>$v): ?><option value="<?=mc_h($k)?>" <?=($behavior && $behavior['selection_kind']===$k?'selected':'')?>><?=mc_h($v)?></option><?php endforeach; ?>
                                        </select>
                                        <select name="source_mode">
                                            <?php foreach (['official_material'=>'正式物料','static_options'=>'静态属性选项','manual_input'=>'手工输入','mixed'=>'混合来源'] as $k=>$v): ?><option value="<?=mc_h($k)?>" <?=($behavior && $behavior['source_mode']===$k?'selected':'')?>><?=mc_h($v)?></option><?php endforeach; ?>
                                        </select>
                                        <input name="material_category_code" value="<?=mc_h($behavior['material_category_code'] ?? '')?>" placeholder="物料分类编码">
                                        <select name="is_required_default"><option value="0" <?=(!$behavior || (int)$behavior['is_required_default']===0?'selected':'')?>>可选</option><option value="1" <?=($behavior && (int)$behavior['is_required_default']===1?'selected':'')?>>必选</option></select>
                                        <select name="selection_mode_default"><option value="single" <?=(!$behavior || $behavior['selection_mode_default']==='single'?'selected':'')?>>单选</option><option value="multiple" <?=($behavior && $behavior['selection_mode_default']==='multiple'?'selected':'')?>>多选</option></select>
                                        <input type="number" name="min_select_default" value="<?=intval($behavior['min_select_default'] ?? 0)?>" placeholder="最少">
                                        <input type="number" name="max_select_default" value="<?=intval($behavior['max_select_default'] ?? 1)?>" placeholder="最多">
                                        <input name="default_rule_json" value="<?=mc_h(isset($behavior['default_rule']) ? pa2_json_encode($behavior['default_rule']) : '')?>" placeholder='默认项 JSON，例如 {"option_code":"white"}'>
                                        <input name="material_filter_json" value="<?=mc_h(isset($behavior['material_filter']) ? pa2_json_encode($behavior['material_filter']) : '')?>" placeholder='物料过滤 JSON'>
                                        <input name="visibility_condition_json" value="<?=mc_h(isset($behavior['visibility_condition']) ? pa2_json_encode($behavior['visibility_condition']) : '')?>" placeholder='显示条件 JSON'>
                                        <button class="mc-button" type="submit">保存行为</button>
                                    </form>
                                </details>
                                <?php endif; ?>
                            </td>
                            <td><?=intval($g['sort_order'])?> · <?=((int)$g['is_enabled'] === 1 ? '启用' : '停用')?></td>
                            <td>
                                <?php if ($canManageGroup): ?>
                                <form class="pa2-mini-form" data-pa2-form action="<?=mc_h(mc_url('adaptation_v2/api/index.php?action=group_save'))?>">
                                    <input type="hidden" name="id" value="<?=intval($g['id'])?>">
                                    <input name="group_code" value="<?=mc_h($g['group_code'])?>">
                                    <input name="group_name" value="<?=mc_h($g['group_name'])?>" required>
                                    <select name="group_type"><?php foreach (['material_select'=>'物料选择','enum_select'=>'属性选择','hybrid_select'=>'混合选择','number_input'=>'数值输入','text_input'=>'文本输入','boolean'=>'布尔开关'] as $k=>$v): ?><option value="<?=mc_h($k)?>" <?=($g['group_type']===$k?'selected':'')?>><?=mc_h($v)?></option><?php endforeach; ?></select>
                                    <input type="number" name="sort_order" value="<?=intval($g['sort_order'])?>">
                                    <select name="is_enabled"><option value="1" <?=((int)$g['is_enabled']===1?'selected':'')?>>启用</option><option value="0" <?=((int)$g['is_enabled']===0?'selected':'')?>>停用</option></select>
                                    <button class="mc-button" type="submit">保存</button>
                                </form>
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
                <div class="pa2-template-actions"><?php if ($selectedTemplate): ?><a class="mc-button mc-button--primary" href="<?=mc_h(pa2_view_url('template_editor', ['template_id' => (int)$selectedTemplate['id']]))?>">打开模板编辑器</a><?php endif; ?></div>
            </div>
            <div class="pa2-panel__body pa2-section-gap">
                <?php if ($canManageTemplate): ?>
                <form class="pa2-form" data-pa2-form action="<?=mc_h(mc_url('adaptation_v2/api/index.php?action=template_save'))?>">
                    <label><span>模板编码</span><input name="template_code" placeholder="例如 track_light_standard"></label>
                    <label><span>模板名称 *</span><input name="template_name" required placeholder="例如 导轨灯标准模板"></label>
                    <label><span>模板层级</span><select name="template_level"><option value="category">分类模板</option><option value="system">系统模板</option><option value="series">系列模板</option><option value="product">产品模板</option></select></label>
                    <label><span>父模板</span><select name="parent_template_id"><option value="">无父模板</option><?php foreach ($templates as $t): ?><option value="<?=intval($t['id'])?>"><?=mc_h($t['template_name'])?></option><?php endforeach; ?></select></label>
                    <label><span>适用分类</span><select name="product_category_id"><option value="">不限定</option><?php foreach ($categories as $c): ?><option value="<?=intval($c['id'])?>"><?=mc_h($c['category_name'])?></option><?php endforeach; ?></select></label>
                    <label><span>系列编码</span><input name="series_code" placeholder="例如 ARTAX"></label>
                    <label class="wide"><span>说明</span><input name="description" placeholder="说明继承范围、用途和注意事项"></label>
                    <button class="mc-button mc-button--primary" type="submit">新增模板</button>
                </form>
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
                    <form class="pa2-form" data-pa2-form action="<?=mc_h(mc_url('adaptation_v2/api/index.php?action=template_group_save'))?>">
                        <input type="hidden" name="template_id" value="<?=intval($selectedTemplate['id'])?>">
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
                        <button class="mc-button mc-button--primary" type="submit">加入 / 覆盖配置组</button>
                    </form>
                    <?php endif; ?>
                    <div class="pa2-group-grid">
                        <?php foreach ($selectedTemplateGroups as $g): ?><article class="pa2-group-card"><div><strong><?=mc_h($g['display_name'])?></strong><br><small><?=mc_h($g['group_code'])?> · <?=mc_h($g['inheritance_action'])?> · <?=($g['is_required']?'必选':'可选')?> · <?=mc_h($g['selection_mode'])?> · 排序 <?=intval($g['sort_order'])?></small></div><span class="pa2-badge pa2-badge--<?=mc_h($g['inheritance_action']==='disable'?'disable':($g['inheritance_action']==='override'?'override':'add'))?>"><?=mc_h($g['inheritance_action'])?></span></article><?php endforeach; ?>
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
