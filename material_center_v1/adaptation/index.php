<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/bootstrap.php';

use Artdon\MaterialCenter\Services\AdaptationService;

$pageTitle = '产品配置工作台';
$pageDescription = '先完成产品技术范围，再继续后续配置。';
$activeMenu = 'adaptation';
$service = new AdaptationService();
$search = trim((string) ($_GET['q'] ?? ''));
$selected = max(0, (int) ($_GET['product_id'] ?? 0));
$groupId = max(0, (int) ($_GET['group_id'] ?? 0));
$requestedView = (string) ($_GET['view'] ?? '');
$requestedStep = max(1, min(6, (int) ($_GET['step'] ?? 1)));
$pageLoadError = null;
$products = [];
$workspace = null;
$metadata = [];

try {
    $products = $service->products($search);
    $workspace = $selected > 0 ? $service->workspace($selected, $groupId) : null;
    // The repair screen intentionally avoids template/rule metadata until the paused
    // workflow steps are brought back under separate verification.
    $metadata = [];
} catch (Throwable $e) {
    error_log('[material_center][adaptation] base page load failed: '.$e->getMessage());
    $pageLoadError = '产品适配基础资料暂时无法读取，请刷新后重试；如仍失败请联系管理员。';
}

$initialView = in_array($requestedView, ['home', 'products', 'workspace'], true)
    ? $requestedView
    : ($workspace ? 'workspace' : 'home');
if ($initialView === 'workspace' && !$workspace) {
    $initialView = 'products';
}
$bootstrap = [
    'csrf' => csrf_token(),
    'products' => $products,
    'workspace' => $workspace,
    'metadata' => $metadata,
    'baseUrl' => MC_BASE_URL,
    'view' => $initialView,
    'step' => $requestedStep,
    'pageLoadError' => $pageLoadError,
    'repairMode' => true,
];

include MC_ROOT.'/components/layout_top.php';
?>
<section class="mc-page mc-page--adaptation-baseline" data-adaptation data-view="<?=mc_h($initialView)?>">
    <header class="mc-adaptation-baseline__head">
        <div>
            <p class="mc-adaptation-baseline__eyebrow">产品适配 · 基础页面修复中</p>
            <h1>产品配置工作台</h1>
            <p>先选择产品并完成技术范围。核心物料、规则、审批与发布暂时暂停，现有配置数据不会被修改。</p>
        </div>
        <div class="mc-adaptation-baseline__actions">
            <button class="mc-button" type="button" data-v3-home>适配首页</button>
            <button class="mc-button" type="button" data-v3-products>全部产品</button>
            <button class="mc-button" type="button" data-v3-disabled>配置模板（暂未开放）</button>
            <button class="mc-button" type="button" data-v3-disabled>批量工具（暂未开放）</button>
        </div>
    </header>
    <main class="mc-adaptation-baseline__content" data-overview-dashboard aria-live="polite">
        <noscript><div class="mc-empty-state mc-empty-state--error">此页面需要启用浏览器 JavaScript 才能显示产品适配工作台。</div></noscript>
    </main>
</section>
<script type="application/json" id="adaptation-bootstrap"><?=json_encode($bootstrap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)?></script>
<script src="<?=mc_h(mc_ui_asset('assets/js/adaptation-v3.js'))?>" defer></script>
<?php include MC_ROOT.'/components/layout_bottom.php'; ?>
