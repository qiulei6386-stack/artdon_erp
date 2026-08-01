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
    'workspace',
    'packages',
    'publish',
    'approvals',
    'logs',
];
if (!in_array($view, $allowedViews, true)) $view = 'home';

$pageTitle = '产品适配 V2';
$pageDescription = '第 3 阶段：模板中心、继承引擎和版本发布。';
$summary = pa2_foundation_summary();
$categories = pa2_fetch_categories();
$groups = pa2_fetch_groups(true);
$products = $summary['ready'] ? pa2_search_products((string)($_GET['q'] ?? ''), 40) : [];
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

$routeCards = [
    ['home', '首页', 'V2 基础状态、模板状态和阶段入口。'],
    ['products', '全部产品 / 映射', '查看产品并维护产品分类映射。'],
    ['categories', '产品分类中心', '维护产品分类、父子分类、启停和排序。'],
    ['groups', '配置组定义中心', '维护数据化配置组和属性选项。'],
    ['templates', '模板中心', '维护通用、分类、系列和产品模板。'],
    ['workspace', '单产品配置工作台', '第 5 阶段建立模板驱动配置。'],
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
.pa2-template-shell{display:grid;grid-template-columns:280px minmax(0,1fr) 360px;gap:16px;align-items:start}.pa2-template-list{display:grid;gap:10px}.pa2-template-item{display:block;text-decoration:none;color:inherit;border:1px solid var(--pa2-border);border-radius:16px;padding:14px;background:#fff}.pa2-template-item.is-active{border-color:var(--pa2-teal);box-shadow:0 12px 30px rgba(15,159,154,.12)}.pa2-template-item strong{display:block}.pa2-template-item span{color:var(--pa2-muted);font-size:13px}.pa2-flow{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.pa2-flow span{background:#eef8f8;color:#0b7773;border:1px solid #c9eeeb;border-radius:999px;padding:6px 10px}.pa2-group-grid{display:grid;gap:10px}.pa2-group-card{display:grid;grid-template-columns:1fr auto;gap:10px;border:1px solid var(--pa2-border);border-radius:16px;padding:13px;background:#fff}.pa2-group-card small{color:var(--pa2-muted)}.pa2-badge{display:inline-flex;align-items:center;border-radius:999px;padding:4px 8px;font-size:12px;background:#eef4ff;color:#1d4ed8}.pa2-badge--add{background:#ecfdf3;color:#067647}.pa2-badge--override{background:#fff7ed;color:#c2410c}.pa2-badge--disable{background:#fef2f2;color:#b42318}.pa2-side-note{background:var(--pa2-soft);border:1px dashed #c9d8e8;border-radius:16px;padding:14px;color:#344054}.pa2-two-col{display:grid;grid-template-columns:1fr 1fr;gap:12px}.pa2-template-actions{display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end}
@media(max-width:1100px){.pa2-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.pa2-form{grid-template-columns:repeat(2,minmax(0,1fr))}.pa2-hero{display:grid}}@media(max-width:700px){.pa2-grid,.pa2-form{grid-template-columns:1fr}.pa2-form .wide{grid-column:auto}}
@media(max-width:1280px){.pa2-template-shell{grid-template-columns:1fr}.pa2-template-actions{justify-content:flex-start}}
</style>
<section class="mc-page mc-pa2-page" data-adaptation-v2 data-phase="3" data-view="<?=mc_h($view)?>">
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
            <article class="pa2-card"><strong>属性选项</strong><b><?=intval($summary['option_count'])?></b><p>颜色、调光、安装方式等选项不写死在页面。</p></article>
            <article class="pa2-card"><strong>模板数量</strong><b><?=count($templates)?></b><p>系统通用、分类、系列和产品模板逐层继承。</p></article>
        </section>
        <section class="pa2-panel">
            <div class="pa2-panel__head"><div><h2>阶段路由和边界</h2><p>第 3 阶段开放模板中心和继承预览；规则、工作台和配置包仍按后续阶段开发。</p></div></div>
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
            <div class="pa2-panel__head"><div><h2>配置组定义中心</h2><p>配置组可以新增、编辑、启停和排序；属性组选项在这里维护，不再写死到页面代码。</p></div></div>
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
                    <thead><tr><th>编码</th><th>配置组</th><th>类型</th><th>属性选项</th><th>排序/状态</th><th>编辑</th></tr></thead>
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
</script>
<?php include MC_ROOT . '/components/layout_bottom.php'; ?>
