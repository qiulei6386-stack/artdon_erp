<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/bootstrap.php';

use Artdon\MaterialCenter\Services\AdaptationService;

$pageTitle = '产品适配';
$pageDescription = '产品列表、配置规则与选项详情统一维护。';
$activeMenu = 'adaptation';
$service = new AdaptationService();
$search = trim((string) ($_GET['q'] ?? ''));
$products = $service->products($search);
$selected = (int) ($_GET['product_id'] ?? ($products[0]['id'] ?? 0));
$groupId = (int) ($_GET['group_id'] ?? 0);
$workspace = $selected ? $service->workspace($selected, $groupId) : null;
$bootstrap = [
    'csrf' => csrf_token(),
    'products' => $products,
    'workspace' => $workspace,
    'metadata' => $service->metadata(),
    'baseUrl' => MC_BASE_URL,
];

include MC_ROOT.'/components/layout_top.php';
?>
<section class="mc-page mc-page--adaptation-v2" data-adaptation>
    <header class="mc-adaptation-head">
        <div>
            <h1>产品适配</h1>
            <p>按“选择产品 → 建立配置组 → 添加正式物料 → 设置默认与条件 → 检查并审批”的顺序完成配置。</p>
        </div>
        <div class="mc-adaptation-head__actions">
            <button class="mc-button" type="button" data-sync-products>同步产品</button>
            <button class="mc-button" type="button" data-batch-open disabled>批量套用</button>
            <button class="mc-button mc-button--primary" type="button" data-template-open disabled>生成标准配置</button>
        </div>
    </header>

    <div class="mc-adaptation-workspace">
        <aside class="mc-adaptation-column mc-adaptation-products">
            <div class="mc-adaptation-column__head">
                <div><strong>产品列表</strong><span data-product-count>0 个产品</span></div>
            </div>
            <form class="mc-adaptation-search" data-product-search>
                <label class="mc-search mc-search--small">
                    <?=mc_icon('search', 16)?>
                    <input name="q" value="<?=mc_h($search)?>" placeholder="搜索型号 / 名称 / 系列" autocomplete="off">
                </label>
            </form>
            <div class="mc-adaptation-products__list" data-product-list></div>
        </aside>

        <section class="mc-adaptation-column mc-adaptation-rules">
            <div class="mc-adaptation-column__head">
                <div><strong>配置组（产品选配结构）</strong><span data-rule-subtitle>请选择产品</span></div>
                <button class="mc-button" type="button" data-group-create disabled>＋ 配置组</button>
            </div>
            <div data-product-summary></div>
            <div class="mc-config-group-guide">
                <strong>配置组 = 当前产品的一类选配</strong>
                <span>例如“芯片 / 光源”组：先填关键范围，再加入符合范围的候选芯片；“批量套用”才是把整套已配置内容复制到其他产品。</span>
            </div>
            <div class="mc-adaptation-groups" data-group-list></div>
        </section>

        <aside class="mc-adaptation-column mc-adaptation-options">
            <div class="mc-adaptation-column__head">
                <div><strong>选项详情</strong><span data-option-subtitle>请选择配置组</span></div>
                <div class="mc-adaptation-column__actions">
                    <button class="mc-button mc-button--soft" type="button" data-open-quick-rules disabled>填写关键范围</button>
                    <button class="mc-button mc-button--primary" type="button" data-candidate-open disabled>＋ 添加候选</button>
                </div>
            </div>
            <div class="mc-option-tabs" role="tablist" data-option-tabs hidden>
                <button type="button" class="is-active" data-adaptation-tab="options">选项列表</button>
                <button type="button" data-adaptation-tab="quick_rules">关键范围（快速规则）</button>
                <button type="button" data-adaptation-tab="default">默认设置</button>
                <button type="button" data-adaptation-tab="alternative">替代关系</button>
                <button type="button" data-adaptation-tab="conditions">适用条件</button>
                <button type="button" data-adaptation-tab="impact">价格 / 交期</button>
                <button type="button" data-adaptation-tab="approval">审批</button>
            </div>
            <div class="mc-adaptation-detail" data-option-detail></div>
        </aside>
    </div>

    <div class="mc-modal" id="template-modal" data-adaptation-modal>
        <form class="mc-modal__panel mc-modal__panel--medium" data-template-form>
            <div class="mc-modal__header">
                <div><strong>生成标准配置</strong><span>确认后一次生成；重新套用时只补齐缺失组，不重复插入。</span></div>
                <button type="button" class="mc-icon-button" data-modal-close>×</button>
            </div>
            <div class="mc-modal__body">
                <p class="mc-adaptation-guide">将按以下业务顺序建立配置组：</p>
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
                <div><strong>从物料库添加选项</strong><span>只显示当前配置组对应类别的正式物料；停用物料仅供识别，不能添加。</span></div>
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
</section>

<script type="application/json" id="adaptation-bootstrap"><?=json_encode($bootstrap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP)?></script>
<script src="<?=mc_h(mc_url(mc_ui_asset('assets/js/adaptation-shell.js')))?>" defer></script>
<?php include MC_ROOT.'/components/layout_bottom.php'; ?>
