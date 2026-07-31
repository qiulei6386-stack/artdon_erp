<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/bootstrap.php';

use Artdon\MaterialCenter\Services\AdaptationService;

$activeMenu = 'adaptation';
$service = new AdaptationService();
$search = trim((string) ($_GET['q'] ?? ''));
$products = $service->products($search);
$selected = (int) ($_GET['product_id'] ?? 0);
$groupId = (int) ($_GET['group_id'] ?? 0);
$workspace = $selected ? $service->workspace($selected, $groupId) : null;
$requestedView = (string) ($_GET['view'] ?? '');
$requestedStep = (string) ($_GET['step'] ?? '');
$stepMap = ['range' => 1, 'core' => 2, 'optional' => 3, 'rules' => 4, 'approval' => 5, 'version' => 6];
$initialStep = $stepMap[$requestedStep] ?? max(1, min(6, (int) ($_GET['step'] ?? 1)));
$initialView = in_array($requestedView, ['home', 'products', 'workspace'], true) ? $requestedView : ($workspace ? 'workspace' : 'home');
if ($initialView === 'workspace' && !$workspace) $initialView = 'products';
$pageTitle = [
    'home' => '产品适配',
    'products' => '全部产品配置',
    'workspace' => '产品配置工作台',
][$initialView] ?? '产品适配';
$pageDescription = [
    'home' => '管理产品技术范围、核心物料、扩展可选、条件规则、检查审批与版本发布。',
    'products' => '管理所有产品的物料配置状态、适配规则和发布情况。',
    'workspace' => '按步骤完成产品配置、适配检查与发布。',
][$initialView] ?? '产品适配';
$bootstrap = [
    'csrf' => csrf_token(),
    'products' => $products,
    'workspace' => $workspace,
    'metadata' => $service->metadata(),
    'baseUrl' => MC_BASE_URL,
    'view' => $initialView,
    'step' => $initialStep,
    'advancedOpen' => $requestedStep !== '',
];

include MC_ROOT.'/components/layout_top.php';
?>
<section class="mc-page mc-page--adaptation-v3" data-adaptation data-stage="<?=$workspace?($workspace['active_group']?'options':'groups'):'products'?>" data-view="<?=mc_h($initialView)?>">
    <header class="mc-adaptation-head">
        <div>
            <h1><?=mc_h($pageTitle)?></h1>
            <p><?=mc_h($pageDescription)?></p>
        </div>
        <div class="mc-adaptation-head__actions">
            <button class="mc-button" type="button" data-v3-home>适配首页</button>
            <button class="mc-button" type="button" data-v3-products>全部产品</button>
            <button class="mc-button" type="button" data-v3-select-product>选择产品</button>
            <button class="mc-button" type="button" data-v3-template>配置模板</button>
            <button class="mc-button" type="button" data-v3-batch>批量工具</button>
            <button class="mc-button mc-button--primary" type="button" data-v3-approve hidden>检查并提交审批</button>
        </div>
    </header>

    <div class="mc-adaptation-workspace">
        <section class="mc-adaptation-overview" data-overview-dashboard hidden></section>
        <div class="mc-adaptation-groups" data-group-list hidden aria-hidden="true"></div>
        <aside class="mc-adaptation-column mc-adaptation-options" aria-label="当前配置抽屉">
            <div class="mc-workbench-drawer-resizer" data-workbench-drawer-resizer aria-hidden="true"></div>
            <div class="mc-adaptation-column__head">
                <div><strong data-option-title>选项详情</strong><span data-option-subtitle>请选择配置组</span></div>
                <div class="mc-adaptation-column__actions">
                    <button class="mc-icon-button" type="button" data-workbench-drawer-close title="关闭配置抽屉">×</button>
                    <button class="mc-button mc-button--soft" type="button" data-open-quick-rules disabled>填写关键范围</button>
                    <button class="mc-button mc-button--primary" type="button" data-candidate-open disabled>＋ 添加候选</button>
                    <button class="mc-button" type="button" data-open-approval disabled>审批 / 发布</button>
                </div>
            </div>
            <div data-product-summary></div>
            <div class="mc-configuration-overview" data-configuration-overview></div>
            <div class="mc-option-tabs" role="tablist" data-option-tabs hidden>
                <button type="button" class="is-active" data-adaptation-tab="options">选项列表</button>
                <button type="button" data-adaptation-tab="quick_rules">关键范围（快速规则）</button>
                <button type="button" data-adaptation-tab="default">默认设置</button>
                <button type="button" data-adaptation-tab="alternative">替代关系</button>
                <button type="button" data-adaptation-tab="conditions">适用条件</button>
                <button type="button" data-adaptation-tab="impact">价格 / 交期</button>
                <button type="button" data-adaptation-tab="approval">审批</button>
            </div>
            <section class="mc-candidate-discovery" data-candidate-discovery hidden></section>
            <section class="mc-selected-configuration" data-selected-configuration hidden></section>
            <div class="mc-adaptation-detail" data-option-detail></div>
        </aside>
    </div>

    <div class="mc-modal" id="overview-product-modal" data-adaptation-modal>
        <section class="mc-modal__panel mc-modal__panel--wide mc-overview-product-modal">
            <div class="mc-modal__header">
                <div><strong>切换产品</strong><span>选择后锁定在工作台顶部；已保存的配置不会丢失。</span></div>
                <button type="button" class="mc-icon-button" data-modal-close>×</button>
            </div>
            <div class="mc-modal__body">
                <form class="mc-adaptation-search" data-product-search><label class="mc-search"><span><?=mc_icon('search', 16)?></span><input name="q" value="<?=mc_h($search)?>" placeholder="搜索型号 / 名称 / 系列" autocomplete="off"></label></form>
                <div class="mc-product-filter"><select data-product-type-filter aria-label="按产品类型筛选"><option value="all">全部类型</option></select><select data-product-status-filter aria-label="按配置状态筛选"><option value="all">全部状态</option><option value="unconfigured">未配置</option><option value="configured">已配置</option><option value="pending_approval">待审批</option><option value="needs_review">待重审</option><option value="enabled">已启用</option><option value="conflict">有冲突</option></select><button class="mc-button" type="button" data-product-select-visible>全选当前</button></div>
                <div class="mc-product-selection" data-product-selection-bar hidden><strong data-product-selection-count>已选择 0 个</strong><button type="button" data-selected-template>批量生成配置</button><button type="button" data-selected-reuse-template>套用配置模板</button><button type="button" data-selected-batch>用当前产品套用</button><button type="button" data-product-selection-clear>清空</button></div>
                <div class="mc-adaptation-products__list" data-product-list></div>
                <div class="mc-overview-product-list" data-overview-product-list hidden></div>
            </div>
        </section>
    </div>

    <div class="mc-modal" id="template-modal" data-adaptation-modal>
        <form class="mc-modal__panel mc-modal__panel--medium" data-template-form>
            <div class="mc-modal__header">
                <div><strong>完整标准配置模板</strong><span>默认勾选全部标准组；确认后补齐缺失组，不重复插入已有配置。</span></div>
                <button type="button" class="mc-icon-button" data-modal-close>×</button>
            </div>
            <div class="mc-modal__body">
                <div class="mc-template-target" data-template-target></div>
                <div class="mc-template-toolbar">
                    <span>默认建立完整选配结构；如有特殊产品再取消不需要的组：</span>
                    <button class="mc-button" type="button" data-template-select-all>全选</button>
                    <button class="mc-button" type="button" data-template-select-core>仅必选核心</button>
                    <button class="mc-button" type="button" data-template-clear>清空</button>
                    <b data-template-selection>已选择 0 组</b>
                </div>
                <div class="mc-template-preview" data-template-preview></div>
            </div>
            <div class="mc-modal__footer">
                <button type="button" class="mc-button" data-modal-close>取消</button>
                <button class="mc-button mc-button--primary" type="submit">确认生成</button>
            </div>
        </form>
    </div>

    <div class="mc-modal" id="batch-modal" data-adaptation-modal>
        <form class="mc-modal__panel mc-modal__panel--wide" data-batch-form>
            <div class="mc-modal__header">
                <div><strong>批量套用产品适配</strong><span>先把当前产品设置好，再一次套到同系列或所选产品；执行后目标产品进入待重审。</span></div>
                <button type="button" class="mc-icon-button" data-modal-close>×</button>
            </div>
            <div class="mc-modal__body mc-adaptation-batch">
                <div class="mc-batch-source" data-batch-source></div>
                <div class="mc-batch-mode">
                    <strong>1. 选择套用方式</strong>
                    <label><input type="radio" name="mode" value="fill_missing" checked><span><b>只补空白（推荐）</b><small>目标产品已有的同名配置不改，只补缺少的配置组。</small></span></label>
                    <label><input type="radio" name="mode" value="replace_matching"><span><b>覆盖同名配置组</b><small>用当前产品覆盖目标产品的同名组、选项、快速规则和冲突；原审批不会继续生效。</small></span></label>
                    <label class="mc-batch-power"><input type="checkbox" name="include_power_rule" value="1" checked><span><b>同时套用电源范围</b><small>外置 / 内置、功率、电流、电压、空间、调光和质保一起复制。</small></span></label>
                </div>
                <div class="mc-batch-targets">
                    <div class="mc-batch-targets__head">
                        <div><strong>2. 选择目标产品</strong><span data-batch-selection>已选择 0 个</span></div>
                        <label class="mc-search mc-search--small"><?=mc_icon('search', 16)?><input type="search" data-batch-search placeholder="搜索型号 / 名称 / 系列"></label>
                        <button class="mc-button" type="button" data-batch-same-series>选择同系列</button>
                        <button class="mc-button" type="button" data-batch-select-visible>全选当前结果</button>
                        <button class="mc-button" type="button" data-batch-clear>清空</button>
                    </div>
                    <div class="mc-batch-product-list" data-batch-product-list></div>
                </div>
                <div class="mc-batch-preview-card" data-batch-preview>
                    <strong>3. 先预览，再执行</strong>
                    <span>选择目标产品后点击“预览影响”，系统会先计算新增、覆盖和跳过数量。</span>
                </div>
            </div>
            <div class="mc-modal__footer">
                <span data-batch-footer-selection>已选择 0 个产品</span>
                <span class="mc-modal__spacer"></span>
                <button type="button" class="mc-button" data-modal-close>取消</button>
                <button type="button" class="mc-button" data-batch-preview-button>预览影响</button>
                <button class="mc-button mc-button--primary" type="submit" data-batch-apply disabled>确认批量套用</button>
            </div>
        </form>
    </div>

    <div class="mc-modal" id="reuse-modal" data-adaptation-modal>
        <form class="mc-modal__panel mc-modal__panel--medium" data-reuse-form>
            <div class="mc-modal__header">
                <div><strong>套用现有配置</strong><span>从已经配置好的产品选择需要的配置组，套到当前产品。</span></div>
                <button type="button" class="mc-icon-button" data-modal-close>×</button>
            </div>
            <div class="mc-modal__body mc-reuse-config">
                <div class="mc-reuse-target" data-reuse-target></div>
                <label class="mc-field">
                    <span>1. 选择来源产品</span>
                    <select data-reuse-source required></select>
                </label>
                <div class="mc-reuse-groups">
                    <div><strong>2. 选择要套用的配置组</strong><button type="button" data-reuse-select-all>全选</button></div>
                    <div data-reuse-group-list></div>
                </div>
                <div class="mc-batch-mode mc-reuse-mode">
                    <strong>3. 选择套用方式</strong>
                    <label><input type="radio" name="mode" value="fill_missing" checked><span><b>只补空白（推荐）</b><small>当前产品已有的同名组不变。</small></span></label>
                    <label><input type="radio" name="mode" value="replace_matching"><span><b>覆盖同名配置组</b><small>所选同名组、选项、关键范围和冲突由来源产品替换。</small></span></label>
                    <label class="mc-batch-power"><input type="checkbox" name="include_power_rule" value="1"><span><b>同时套用电源范围</b><small>来源产品有电源范围时一并复制。</small></span></label>
                </div>
                <div class="mc-batch-preview-card" data-reuse-preview>
                    <strong>4. 先预览，再执行</strong>
                    <span>系统会先显示新增、覆盖和保留数量。</span>
                </div>
            </div>
            <div class="mc-modal__footer">
                <button type="button" class="mc-button" data-modal-close>取消</button>
                <button type="button" class="mc-button" data-reuse-preview-button>预览影响</button>
                <button class="mc-button mc-button--primary" type="submit" data-reuse-apply disabled>确认套用</button>
            </div>
        </form>
    </div>

    <div class="mc-modal" id="reuse-template-modal" data-adaptation-modal>
        <form class="mc-modal__panel mc-modal__panel--wide" data-reuse-template-form>
            <div class="mc-modal__header">
                <div><strong>配置模板库</strong><span>模板可自由包含电源、芯片、光学或其他任意配置组；套用时不影响模板未包含的组。</span></div>
                <button type="button" class="mc-icon-button" data-modal-close>×</button>
            </div>
            <div class="mc-modal__body mc-reuse-template-library">
                <section class="mc-reuse-template-create" data-reuse-template-create></section>
                <section class="mc-reuse-template-saved">
                    <div class="mc-reuse-template-saved__head"><div><strong>已保存模板</strong><span>选择模板后可映射到一个或多个产品。</span></div><button class="mc-button" type="button" data-reuse-template-refresh>刷新</button></div>
                    <div data-reuse-template-list></div>
                </section>
            </div>
            <div class="mc-modal__footer">
                <span>模板来源产品更新后，下一次套用自动使用最新配置。</span>
                <span class="mc-modal__spacer"></span>
                <button type="button" class="mc-button" data-modal-close>关闭</button>
                <button class="mc-button mc-button--primary" type="submit" data-reuse-template-save>保存为模板</button>
            </div>
        </form>
    </div>

    <div class="mc-modal" id="group-modal" data-adaptation-modal>
        <form class="mc-modal__panel" data-group-form>
            <div class="mc-modal__header">
                <div><strong data-group-form-title>新建配置组</strong><span>选择“配置用途”即可，候选物料来源由系统自动确定。</span></div>
                <button type="button" class="mc-icon-button" data-modal-close>×</button>
            </div>
            <div class="mc-modal__body">
                <input type="hidden" name="id">
                <div class="mc-form-grid">
                    <label class="mc-field">
                        <span>配置用途 *</span>
                        <select name="business_type" required data-business-type></select>
                        <small>决定可填写的关键范围和筛选逻辑。</small>
                    </label>
                    <div class="mc-field">
                        <span>候选物料来源</span>
                        <input type="hidden" name="material_category_code" data-material-category>
                        <strong class="mc-field-readonly" data-material-category-label>选择配置用途后自动确定</strong>
                        <select data-custom-material-category hidden>
                            <option value="">请选择物料类别</option>
                            <option value="power_supply">电源</option>
                            <option value="chip">芯片</option>
                            <option value="optical">光学</option>
                            <option value="profile">型材 / 散热件</option>
                            <option value="connector">接头 / 安装件</option>
                            <option value="accessory">配件</option>
                            <option value="packaging">包装</option>
                        </select>
                        <small data-material-category-help>无需重复选择，系统会自动关联正式物料库。</small>
                    </div>
                    <label class="mc-field mc-field--wide">
                        <span>页面显示名称 *</span>
                        <input name="group_name" maxlength="120" required placeholder="选择配置用途后自动填写，也可以改名">
                        <small>只是页面上看到的名称；需要区分主芯片、备用芯片时再修改。</small>
                    </label>
                    <label class="mc-field">
                        <span>业务要求</span>
                        <select name="is_required"><option value="1">必选</option><option value="0">可选</option></select>
                    </label>
                    <label class="mc-field">
                        <span>选择方式</span>
                        <select name="selection_mode"><option value="single">单选</option><option value="multi">多选</option></select>
                    </label>
                    <label class="mc-field">
                        <span>最少选择</span>
                        <input type="number" name="min_select" min="0" value="0">
                    </label>
                    <label class="mc-field">
                        <span>最多选择</span>
                        <input type="number" name="max_select" min="1" value="1">
                    </label>
                    <label class="mc-field">
                        <span>排序</span>
                        <input type="number" name="sort_order" value="100">
                    </label>
                    <label class="mc-field">
                        <span>当前状态</span>
                        <select name="status"><option value="draft">待配置 / 待审批</option><option value="disabled">已停用</option></select>
                    </label>
                </div>
            </div>
            <div class="mc-modal__footer">
                <button type="button" class="mc-button mc-button--danger" data-group-delete hidden>删除配置组</button>
                <span class="mc-modal__spacer"></span>
                <button type="button" class="mc-button" data-modal-close>取消</button>
                <button class="mc-button mc-button--primary" type="submit">保存配置组</button>
            </div>
        </form>
    </div>

    <div class="mc-modal" id="candidate-modal" data-adaptation-modal>
        <form class="mc-modal__panel mc-modal__panel--wide" data-candidate-form>
            <div class="mc-modal__header">
                <div><strong>从物料库添加选项</strong><span>只显示当前配置组对应类别的正式物料；不适配项可以强制加入，但必须写明原因并进入审批。</span></div>
                <button type="button" class="mc-icon-button" data-modal-close>×</button>
            </div>
            <div class="mc-modal__body mc-candidate-body">
                <div class="mc-candidate-filters">
                    <input name="brand" placeholder="品牌">
                    <input name="model" placeholder="型号">
                    <input name="power_band" placeholder="功率档">
                    <input name="installation_type" placeholder="安装方式">
                    <input name="output_type" placeholder="输出类型">
                    <input name="output_current" placeholder="输出电流">
                    <input name="output_voltage" placeholder="输出电压">
                    <input name="dimming_mode" placeholder="调光方式">
                    <input name="warranty" placeholder="质保年限">
                    <input name="supplier" placeholder="供应商">
                    <select name="status"><option value="official">正式</option><option value="all">正式 + 停用</option><option value="disabled">停用</option></select>
                    <button class="mc-button" type="button" data-candidate-filter>筛选</button>
                </div>
                <div class="mc-candidate-summary" data-candidate-summary></div>
                <div class="mc-candidate-list" data-candidate-list></div>
                <label class="mc-field mc-candidate-exception-reason"><span>强制添加说明 <small>仅选择“不适配”物料时必填；保存后会进入审批并写入操作日志。</small></span><textarea name="force_exception_reason" rows="2" placeholder="例如：客户指定型号，工程已确认可采用，待主管审批"></textarea></label>
            </div>
            <div class="mc-modal__footer">
                <span data-candidate-selection>已选择 0 项</span>
                <span class="mc-modal__spacer"></span>
                <button type="button" class="mc-button" data-modal-close>取消</button>
                <button class="mc-button mc-button--primary" type="submit">批量添加所选</button>
            </div>
        </form>
    </div>

    <div class="mc-modal" id="condition-modal" data-adaptation-modal>
        <form class="mc-modal__panel mc-modal__panel--wide" data-condition-form>
            <div class="mc-modal__header">
                <div><strong>可视化适用条件</strong><span>使用字段、运算符和值组合 AND / OR 条件，不允许填写代码表达式。</span></div>
                <button type="button" class="mc-icon-button" data-modal-close>×</button>
            </div>
            <div class="mc-modal__body">
                <div class="mc-condition-editor-head"><span>组合</span><span>物料选项</span><span>字段</span><span>运算符</span><span>值</span><span>失败原因</span><span></span></div>
                <div class="mc-condition-editor" data-condition-rows></div>
                <button class="mc-button mc-button--soft" type="button" data-condition-add>＋ 添加条件</button>
            </div>
            <div class="mc-modal__footer">
                <button type="button" class="mc-button" data-modal-close>取消</button>
                <button class="mc-button mc-button--primary" type="submit">保存适用条件</button>
            </div>
        </form>
    </div>

    <div class="mc-modal" id="configuration-overview-modal" data-adaptation-modal>
        <div class="mc-modal__panel mc-modal__panel--wide">
            <div class="mc-modal__header">
                <div><strong>当前产品配置总览</strong><span>标准默认、可选范围、禁用规则、芯片具体规格和审批状态集中查看</span></div>
                <button type="button" class="mc-icon-button" data-modal-close>×</button>
            </div>
            <div class="mc-modal__body" data-configuration-overview-full></div>
            <div class="mc-modal__footer"><button type="button" class="mc-button" data-modal-close>关闭</button></div>
        </div>
    </div>

    <div class="mc-modal" id="chip-variant-modal" data-adaptation-modal>
        <form class="mc-modal__panel mc-modal__panel--medium" data-chip-variant-form>
            <div class="mc-modal__header">
                <div><strong>选择产品允许的芯片规格</strong><span>芯片物料提供全部能力；这个产品只保留可用子集，并指定一个默认规格</span></div>
                <button type="button" class="mc-icon-button" data-modal-close>×</button>
            </div>
            <div class="mc-modal__body">
                <input type="hidden" name="option_id">
                <div class="mc-chip-option-material" data-chip-option-material></div>
                <div class="mc-chip-option-toolbar"><button class="mc-button" type="button" data-chip-option-select-all>全选可用</button><button class="mc-button" type="button" data-chip-option-clear>清空</button><span data-chip-option-count></span></div>
                <div class="mc-chip-option-variants" data-chip-option-variants></div>
            </div>
            <div class="mc-modal__footer">
                <button type="button" class="mc-button" data-modal-close>取消</button>
                <button class="mc-button mc-button--primary" type="submit">保存产品规格范围</button>
            </div>
        </form>
    </div>
</section>

<script type="application/json" id="adaptation-bootstrap"><?=json_encode($bootstrap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP)?></script>
<script src="<?=mc_h(mc_url(mc_ui_asset('assets/js/adaptation-v3.js')))?>" defer></script>
<?php include MC_ROOT.'/components/layout_bottom.php'; ?>
