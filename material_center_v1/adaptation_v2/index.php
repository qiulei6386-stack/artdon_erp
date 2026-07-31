<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

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
if (!in_array($view, $allowedViews, true)) {
    $view = 'home';
}

$pageTitle = '产品适配 V2';
$pageDescription = '第 1 阶段旁路蓝图：冻结旧版、完成审计并建立独立 V2 入口。';
$routeCards = [
    ['home', '首页', '最近工作、状态概览和快速入口。'],
    ['products', '全部产品', '查看产品配置状态。'],
    ['categories', '产品分类中心', '第 2 阶段维护产品分类和映射。'],
    ['groups', '配置组定义中心', '第 2 阶段维护数据化配置组。'],
    ['templates', '模板中心', '第 3 阶段维护模板继承和版本。'],
    ['workspace', '单产品配置工作台', '第 5 阶段建立模板驱动配置。'],
    ['packages', '配置包中心', '第 8 阶段发布渠道配置包。'],
    ['publish', '渠道发布', '第 9 阶段提供下游发布接口。'],
    ['approvals', '审批中心', '第 7 阶段接入审批和发布。'],
    ['logs', '日志与版本', '查看 V2 执行记录和阶段文档。'],
];

include MC_ROOT . '/components/layout_top.php';
?>
<section class="mc-page mc-pa2-page" data-adaptation-v2 data-phase="1" data-view="<?=mc_h($view)?>">
    <header class="mc-adaptation-head">
        <div>
            <h1>产品适配 V2</h1>
            <p>独立旁路入口：当前仅用于第 1 阶段验收，不承载旧版业务操作。</p>
        </div>
        <div class="mc-adaptation-head__actions">
            <a class="mc-button" href="<?=mc_h(mc_url('adaptation/index.php'))?>">返回旧版产品适配</a>
            <a class="mc-button mc-button--primary" href="<?=mc_h(mc_url('adaptation_v2/index.php?view=logs'))?>">查看阶段日志</a>
        </div>
    </header>

    <section class="mc-dashboard-grid">
        <article class="mc-card">
            <strong>阶段状态</strong>
            <p>第 1 阶段：冻结旧版、审计和 V2 蓝图落地。</p>
            <span class="mc-badge">不写业务数据</span>
        </article>
        <article class="mc-card">
            <strong>旧版边界</strong>
            <p>旧目录 <code>adaptation/</code>、旧表和旧 BOM 保持原样，正式菜单仍指向旧版。</p>
            <span class="mc-badge">旁路开发</span>
        </article>
        <article class="mc-card">
            <strong>数据前缀</strong>
            <p>后续 V2 新表统一使用 <code>mc_pa2_</code> 前缀。本阶段不执行建表迁移。</p>
            <span class="mc-badge">mc_pa2_</span>
        </article>
    </section>

    <section class="mc-card">
        <div class="mc-section-title">V2 路由蓝图</div>
        <div class="mc-table-wrap">
            <table class="mc-table">
                <thead><tr><th>视图</th><th>入口</th><th>阶段说明</th></tr></thead>
                <tbody>
                <?php foreach ($routeCards as $card): ?>
                    <tr>
                        <td><?=mc_h($card[1])?></td>
                        <td><code>/material_center_v1/adaptation_v2/index.php?view=<?=mc_h($card[0])?></code></td>
                        <td><?=mc_h($card[2])?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</section>
<?php include MC_ROOT . '/components/layout_bottom.php'; ?>
