<?php
$mcCategoryDrawerMap=[
 'chip'=>['code'=>'chip','title'=>'芯片'],
 'optical'=>['code'=>'optical','title'=>'光学'],
 'profile'=>['code'=>'profile','title'=>'型材 / 散热件'],
 'connector'=>['code'=>'connector','title'=>'接头 / 安装件'],
 'accessories'=>['code'=>'accessory','title'=>'配件'],
 'packaging'=>['code'=>'packaging','title'=>'包装'],
];
$mcCategoryDrawer=$mcCategoryDrawerMap[$activeMenu??'']??null;
if($mcCategoryDrawer){
 $mcCategoryDrawer['id']=0;
 try{foreach((new \Artdon\MaterialCenter\Services\MaterialMasterService())->categories() as$category)if($category['code']===$mcCategoryDrawer['code']){$mcCategoryDrawer['id']=(int)$category['id'];break;}}catch(Throwable){}
}
?>
</main></section></div>
<div class="mc-overlay" data-overlay></div>
<div class="mc-drawer" id="filter-drawer" data-drawer><div class="mc-drawer__header"><div><strong>筛选条件</strong><span>缩小当前列表范围</span></div><button class="mc-icon-button" data-close-layer>×</button></div><div class="mc-drawer__body"><div class="mc-form-grid"><label class="mc-field"><span>来源</span><select data-filter-field="source"><option value="">全部来源</option><option>BOM旧数据</option><option>人工新建</option><option>Excel导入</option><option>供应商导入</option><option>PLM临时物料</option></select></label><label class="mc-field"><span>状态</span><select data-filter-field="status"><option value="">全部状态</option><option>待整理</option><option>待确认</option><option>正式</option><option>异常</option><option>重复候选</option><option>停用</option></select></label><label class="mc-field"><span>安装方式</span><select><option>全部</option><option>内置</option><option>外置</option><option>远置</option></select></label><label class="mc-field"><span>功率档</span><select><option>全部</option><option>1–3W</option><option>3–5W</option><option>5–8W</option><option>8–15W</option><option>15–20W</option><option>20–25W</option><option>25–35W</option><option>35–50W</option></select></label><label class="mc-field"><span>质保</span><select><option>全部</option><option>3年</option><option>5年</option><option>待确认</option></select></label><label class="mc-field"><span>字段完整度</span><select><option>全部</option><option>完整</option><option>缺关键字段</option><option>低置信度</option></select></label></div></div><div class="mc-drawer__footer"><button class="mc-button" data-reset-filters>重置</button><button class="mc-button mc-button--primary" data-apply-filters>应用筛选</button></div></div>
<div class="mc-drawer mc-drawer--medium" id="detail-drawer" data-drawer><div class="mc-drawer__header"><div><strong data-detail-title>物料详情</strong><span data-detail-subtitle>查看与编辑物料资料</span></div><button class="mc-icon-button" data-close-layer>×</button></div><div class="mc-drawer__body" data-detail-body></div><div class="mc-drawer__footer" data-material-detail-actions hidden><button class="mc-button" data-material-reference>引用检查</button><button class="mc-button" data-material-copy>复制新增</button><button class="mc-button" data-material-transition="submit">提交审核</button><button class="mc-button mc-button--primary" data-material-edit>编辑草稿</button></div></div>
<?php if($mcCategoryDrawer): ?>
<div class="mc-drawer mc-category-editor-drawer" id="category-editor-drawer" data-drawer data-category-editor data-category-code="<?=mc_h($mcCategoryDrawer['code'])?>" data-category-id="<?=intval($mcCategoryDrawer['id'])?>" data-category-title="<?=mc_h($mcCategoryDrawer['title'])?>">
 <div class="mc-drawer__header"><div><strong data-category-editor-title><?=mc_h($mcCategoryDrawer['title'])?>资料</strong><span data-category-editor-subtitle>新建、查看和编辑真实物料字段</span></div><button class="mc-icon-button" type="button" data-close-layer>×</button></div>
 <div class="mc-drawer__body mc-category-editor-body">
  <div class="mc-editor-tabs" role="tablist"><button type="button" class="is-active" data-category-tab="fields">整理字段</button><?php if($mcCategoryDrawer['code']==='chip'):?><button type="button" data-category-tab="chip_specs">规格组合</button><button type="button" data-category-tab="chip_templates">模板管理</button><?php endif;?><button type="button" data-category-tab="source">原始来源</button></div>
  <form data-category-editor-form>
  <input type="hidden" name="id"><input type="hidden" name="lock_version"><input type="hidden" name="category_id">
  <div data-category-pane="fields">
  <section class="mc-form-section"><div class="mc-form-section__head"><div><strong>基础资料</strong><span>统一编号在保存草稿时自动生成</span></div></div><div class="mc-form-grid">
   <label class="mc-field mc-field--wide"><span>名称 *</span><input name="name" required maxlength="200"></label>
   <label class="mc-field"><span>品牌</span><input name="brand" maxlength="120"></label>
   <label class="mc-field"><span>型号</span><input name="model" maxlength="160"></label>
   <label class="mc-field"><span>单位 *</span><input name="unit" required maxlength="30" value="PCS"></label>
   <label class="mc-field mc-field--wide"><span>供应商</span><input name="supplier_text" maxlength="200"></label>
   <label class="mc-field mc-field--wide"><span>规格摘要</span><textarea name="spec_summary" rows="3"></textarea></label>
   <label class="mc-field mc-field--wide"><span>备注</span><textarea name="remark" rows="3"></textarea></label>
  </div></section>
  <section class="mc-form-section"><div class="mc-form-section__head"><div><strong><?=mc_h($mcCategoryDrawer['title'])?>规格</strong><span>字段来自物料中心字段注册表并真实保存</span></div></div><div class="mc-form-grid" data-category-editor-fields></div></section>
  </div>
  </form>
  <?php if($mcCategoryDrawer['code']==='chip'):?>
  <div data-category-pane="chip_specs" hidden>
   <section class="mc-form-section mc-chip-spec-pane">
    <div class="mc-form-section__head"><div><strong>芯片规格组合</strong><span>一个芯片料号维护多个色温、显指和色容差；产品适配时再选择允许的子集</span></div><button class="mc-button mc-button--primary" type="button" data-chip-apply-open>套用模板</button></div>
    <div class="mc-chip-spec-guide">模板只生成当前芯片能提供的规格，不会自动改动已经审批的产品配置。默认出货规格只能选择一个。</div>
    <div data-chip-applied-templates></div>
    <div class="mc-chip-variant-toolbar"><button class="mc-button" type="button" data-chip-manual-open>手工添加组合</button><button class="mc-button mc-button--primary" type="button" data-chip-variant-save>保存启用状态和默认规格</button></div>
    <div data-chip-variant-list><div class="mc-empty-inline">请先保存芯片草稿，再维护规格组合。</div></div>
   </section>
  </div>
  <div data-category-pane="chip_templates" hidden>
   <section class="mc-form-section mc-chip-template-pane">
    <div class="mc-form-section__head"><div><strong>规格模板</strong><span>勾选色温、显指和色容差后自动生成组合；可逐个取消无效组合</span></div><button class="mc-button mc-button--primary" type="button" data-chip-template-new>新建模板</button></div>
    <div class="mc-chip-template-layout">
     <div class="mc-chip-template-list" data-chip-template-list></div>
     <form class="mc-chip-template-editor" data-chip-template-form>
      <input type="hidden" name="template_id">
      <label class="mc-field"><span>模板名称 *</span><input name="template_name" maxlength="160" required></label>
      <label class="mc-field"><span>用途说明</span><input name="description" maxlength="500"></label>
      <label class="mc-chip-default-toggle"><input type="checkbox" name="is_system_default" value="1"><span>设为系统默认模板</span></label>
      <div class="mc-chip-template-values">
       <fieldset><legend>色温（K，可复选）</legend><div data-chip-template-values="cct"></div><label>自定义 <input type="number" min="1000" max="20000" data-chip-template-custom="cct"><button type="button" data-chip-template-add="cct">加入</button></label></fieldset>
       <fieldset><legend>显指 CRI（可复选）</legend><div data-chip-template-values="cri"></div><label>自定义 <input type="number" min="0" max="100" step="0.1" data-chip-template-custom="cri"><button type="button" data-chip-template-add="cri">加入</button></label></fieldset>
       <fieldset><legend>色容差 SDCM（可复选）</legend><div data-chip-template-values="sdcm"></div><label>自定义 <input type="number" min="0" max="20" step="0.1" data-chip-template-custom="sdcm"><button type="button" data-chip-template-add="sdcm">加入</button></label></fieldset>
      </div>
      <div class="mc-chip-combination-head"><strong>生成的有效组合</strong><span data-chip-combination-count>0 个</span></div>
      <div class="mc-chip-combination-list" data-chip-combination-list></div>
      <label class="mc-field"><span>本次版本说明</span><input name="change_note" maxlength="500" placeholder="例如：增加 3000K CRI90 SDCM≤3"></label>
      <button class="mc-button mc-button--primary" type="submit">保存为新版本</button>
     </form>
    </div>
   </section>
  </div>
  <?php endif;?>
  <div data-category-pane="source" hidden>
   <section class="mc-form-section"><div class="mc-form-section__head"><div><strong>原始来源</strong><span>旧 BOM 资料只读，不会被物料中心修改</span></div></div>
    <div class="mc-source-detail-grid" data-category-source-fields><div class="mc-empty-inline">当前物料没有旧 BOM 来源映射。</div></div>
    <details class="mc-source-snapshot"><summary>查看来源快照</summary><pre data-category-source-snapshot>—</pre></details>
    <div class="mc-source-parse-log" data-category-parse-log></div>
   </section>
  </div>
  <div class="mc-form-error" data-category-editor-error hidden></div>
 </div>
 <div class="mc-drawer__footer mc-category-editor-footer"><span data-category-editor-state>尚未保存</span><button class="mc-button" type="button" data-category-reference hidden>引用检查</button><button class="mc-button" type="button" data-category-copy hidden>复制新增</button><button class="mc-button" type="button" data-category-submit hidden>提交确认</button><button class="mc-button mc-button--primary" type="button" data-category-approve hidden>确认并转正式</button><button class="mc-button" type="button" data-close-layer>取消</button><button class="mc-button mc-button--primary" type="button" data-category-save>保存草稿</button></div>
</div>
<?php endif; ?>
<?php if($mcCategoryDrawer&&$mcCategoryDrawer['code']==='chip'):?>
<div class="mc-modal" id="chip-template-apply-modal" data-modal>
 <form class="mc-modal__panel mc-modal__panel--medium" data-chip-apply-form>
  <div class="mc-modal__header"><div><strong>套用芯片规格模板</strong><span>多个模板会合并并自动去重；先预览影响，再明确执行</span></div><button class="mc-icon-button" type="button" data-chip-modal-close>×</button></div>
  <div class="mc-modal__body">
   <div class="mc-chip-apply-target" data-chip-apply-target></div>
   <div class="mc-chip-apply-template-list" data-chip-apply-template-list></div>
   <div class="mc-batch-mode mc-chip-apply-mode">
    <label><input type="radio" name="mode" value="fill_missing" checked><span><b>只补缺失（推荐）</b><small>保留芯片当前已有规格，只添加模板中缺少的组合。</small></span></label>
    <label><input type="radio" name="mode" value="replace"><span><b>按模板替换</b><small>模板外且未被已审批产品引用的规格会停用；已引用规格受保护。</small></span></label>
   </div>
   <div class="mc-chip-apply-preview" data-chip-apply-preview>选择模板后点击“预览影响”。</div>
  </div>
  <div class="mc-modal__footer"><button class="mc-button" type="button" data-chip-modal-close>取消</button><button class="mc-button" type="button" data-chip-apply-preview-button>预览影响</button><button class="mc-button mc-button--primary" type="submit" disabled>确认套用</button></div>
 </form>
</div>
<div class="mc-modal" id="chip-manual-variant-modal" data-modal>
 <form class="mc-modal__panel" data-chip-manual-form>
  <div class="mc-modal__header"><div><strong>手工添加芯片规格</strong><span>适合个别供应商特殊组合；已有相同组合会自动跳过</span></div><button class="mc-icon-button" type="button" data-chip-modal-close>×</button></div>
  <div class="mc-modal__body"><div class="mc-form-grid">
   <label class="mc-field"><span>色温 K *</span><input type="number" name="cct_k" min="1000" max="20000" required></label>
   <label class="mc-field"><span>显指 CRI *</span><input type="number" name="cri" min="0" max="100" step="0.1" required></label>
   <label class="mc-field"><span>色容差 SDCM *</span><input type="number" name="sdcm" min="0" max="20" step="0.1" required></label>
   <label class="mc-field"><span>R9</span><input type="number" name="r9" min="-100" max="100" step="0.1"></label>
   <label class="mc-field"><span>供应商规格号</span><input name="supplier_spec_code" maxlength="160"></label>
   <label class="mc-field"><span>采购价</span><input type="number" name="purchase_price" min="0" step="0.0001"></label>
   <label class="mc-field"><span>库存</span><input type="number" name="stock_quantity" min="0" step="0.001"></label>
   <label class="mc-field"><span>交期（天）</span><input type="number" name="lead_time_days" min="0"></label>
  </div></div>
  <div class="mc-modal__footer"><button class="mc-button" type="button" data-chip-modal-close>取消</button><button class="mc-button mc-button--primary" type="submit">添加规格</button></div>
 </form>
</div>
<?php endif;?>
<?php if(($activeMenu??'')!=='power'): ?><div class="mc-drawer mc-drawer--medium" id="batch-drawer" data-drawer><div class="mc-drawer__header"><div><strong>批量设置</strong><span data-batch-count>已选择 0 项</span></div><button class="mc-icon-button" data-close-layer>×</button></div><div class="mc-drawer__body"><div class="mc-batch-fields"><div class="mc-batch-field"><label class="mc-field"><span>字段</span><select data-batch-field></select></label><label class="mc-field"><span>新值</span><input data-batch-value placeholder="填写新值"></label></div></div><div class="mc-section-title">覆盖策略</div><div class="mc-radio-list"><label><input type="radio" name="overwrite" value="fill_empty" checked> 只填写空值</label><label><input type="radio" name="overwrite" value="overwrite"> 覆盖已有值</label></div><output data-batch-preview></output></div><div class="mc-drawer__footer"><button class="mc-button" data-close-layer>取消</button><button class="mc-button mc-button--primary" data-batch-run>预览并执行</button></div></div><?php endif; ?>
<?php if(($activeMenu??'')==='power'): ?>
<div class="mc-drawer mc-power-drawer" id="power-editor-drawer" data-drawer data-power-editor>
 <div class="mc-drawer__header"><div><strong data-power-editor-title>电源资料</strong><span data-power-editor-subtitle>查看与编辑</span></div><button class="mc-icon-button" type="button" data-close-layer>×</button></div>
 <form class="mc-drawer__body mc-power-editor-body" data-power-form>
  <input type="hidden" name="material_id"><input type="hidden" name="lock_version">
  <div class="mc-editor-tabs" role="tablist"><button type="button" class="is-active" data-power-tab="fields">整理字段</button><button type="button" data-power-tab="source">原始来源</button></div>
  <div data-power-pane="fields">
  <div class="mc-power-source-note" data-power-source-note hidden><strong>旧 BOM 只读来源</strong><span>请在本抽屉确认电源字段并直接保存为物料中心草稿；原始记录不会被修改。</span></div>
  <section class="mc-form-section"><div class="mc-form-section__head"><div><strong>基本资料</strong><span>用于检索和识别电源</span></div></div><div class="mc-form-grid">
   <label class="mc-field mc-field--wide"><span>电源名称 *</span><input name="name" maxlength="200" required></label>
   <label class="mc-field"><span>品牌</span><input name="brand" maxlength="120"></label>
   <label class="mc-field"><span>型号</span><input name="model" maxlength="160"></label>
   <label class="mc-field"><span>单位</span><input name="unit" maxlength="30" value="PCS"></label>
   <label class="mc-field mc-field--wide"><span>供应商</span><input name="supplier_text" maxlength="200"></label>
   <label class="mc-field mc-field--wide"><span>规格摘要</span><textarea name="spec_summary" rows="3"></textarea></label>
   <label class="mc-field mc-field--wide"><span>备注</span><textarea name="remark" rows="3"></textarea></label>
  </div></section>
  <section class="mc-form-section"><div class="mc-form-section__head"><div><strong>功率与输入</strong><span>功率档按系统边界自动校验</span></div></div><div class="mc-form-grid">
   <label class="mc-field"><span>额定功率（W）</span><input type="number" step="0.01" min="0" name="nominal_power_w"></label>
   <label class="mc-field"><span>最低输出功率（W）</span><input type="number" step="0.01" min="0" name="min_output_power_w"></label>
   <label class="mc-field"><span>最大输出功率（W）</span><input type="number" step="0.01" min="0" name="max_output_power_w"></label>
   <label class="mc-field mc-field--wide"><span>功率档</span><select name="power_band_id" data-power-band></select></label>
   <label class="mc-field"><span>输入电压最小（V）</span><input type="number" step="0.01" min="0" name="input_voltage_min_v"></label>
   <label class="mc-field"><span>输入电压最大（V）</span><input type="number" step="0.01" min="0" name="input_voltage_max_v"></label>
   <label class="mc-field"><span>输入频率最小（Hz）</span><input type="number" step="0.01" min="0" name="input_frequency_min_hz"></label>
   <label class="mc-field"><span>输入频率最大（Hz）</span><input type="number" step="0.01" min="0" name="input_frequency_max_hz"></label>
   <label class="mc-field"><span>功率因数 PF</span><input type="number" step="0.0001" min="0" name="power_factor"></label>
   <label class="mc-field"><span>效率</span><input type="number" step="0.0001" min="0" name="efficiency"></label>
  </div></section>
  <section class="mc-form-section"><div class="mc-form-section__head"><div><strong>输出</strong><span>支持一个电源配置多个拨码电流</span></div></div><div class="mc-form-grid">
   <label class="mc-field"><span>输出类型</span><select name="output_type" data-output-type></select></label>
   <label class="mc-field"><span>输出电压最小（V）</span><input type="number" step="0.01" min="0" name="output_voltage_min_v"></label>
   <label class="mc-field"><span>输出电压最大（V）</span><input type="number" step="0.01" min="0" name="output_voltage_max_v"></label>
  </div>
  <div class="mc-current-editor"><div class="mc-inline-heading"><strong>输出电流（mA）</strong><button class="mc-link-button" type="button" data-add-current>＋ 添加电流</button></div><div data-current-list></div><p class="mc-field-hint">选择一个默认电流；保存后自动计算最小和最大输出电流。</p></div>
  </section>
  <section class="mc-form-section"><div class="mc-form-section__head"><div><strong>安装与尺寸</strong><span>内置、外置等安装属性与物理空间</span></div></div><div class="mc-form-grid">
   <label class="mc-field mc-field--wide"><span>安装方式</span><select name="installation_type" data-installation-type></select></label>
   <label class="mc-field"><span>长度（mm）</span><input type="number" step="0.01" min="0" name="length_mm"></label>
   <label class="mc-field"><span>宽度（mm）</span><input type="number" step="0.01" min="0" name="width_mm"></label>
   <label class="mc-field"><span>高度（mm）</span><input type="number" step="0.01" min="0" name="height_mm"></label>
   <label class="mc-field"><span>防护等级</span><input name="ip_rating" maxlength="30" placeholder="如 IP20"></label>
  </div></section>
  <section class="mc-form-section"><div class="mc-form-section__head"><div><strong>调光与认证</strong><span>可多选，并指定主调光方式</span></div></div>
   <div class="mc-choice-grid" data-dimming-choices></div>
   <label class="mc-field mc-field--wide"><span>主调光方式</span><select name="primary_dimming" data-primary-dimming></select></label>
   <label class="mc-field mc-field--wide"><span>认证</span><textarea name="certification" rows="2" placeholder="如 CE、ENEC、UL"></textarea></label>
  </section>
  <section class="mc-form-section"><div class="mc-form-section__head"><div><strong>采购与供应商质保</strong><span>供应商质保不等于客户整灯质保</span></div></div><div class="mc-form-grid">
   <label class="mc-field"><span>供应商质保（年）</span><input type="number" step="0.5" min="0" max="20" name="supplier_warranty_years" list="power-warranty-options" placeholder="待确认；常用 3 或 5"><datalist id="power-warranty-options"><option value="3"><option value="5"></datalist></label>
   <label class="mc-field" data-price-field><span>采购价</span><input type="number" step="0.0001" min="0" name="purchase_price"></label>
   <label class="mc-field" data-price-field><span>币种</span><select name="currency"><option value="">待确认</option><option>CNY</option><option>USD</option><option>EUR</option><option>GBP</option></select></label>
   <label class="mc-field"><span>MOQ</span><input type="number" step="0.001" min="0" name="moq"></label>
   <label class="mc-field"><span>交期（天）</span><input type="number" step="1" min="0" name="lead_time_days"></label>
  </div></section>
  <div class="mc-form-error" data-power-error hidden></div>
  </div>
  <div data-power-pane="source" hidden>
   <section class="mc-form-section"><div class="mc-form-section__head"><div><strong>原始来源</strong><span>旧 BOM 资料只读，不会被物料中心修改</span></div></div>
    <div class="mc-source-detail-grid" data-power-source-fields><div class="mc-empty-inline">当前物料没有旧 BOM 来源映射。</div></div>
    <details class="mc-source-snapshot"><summary>查看来源快照</summary><pre data-power-source-snapshot>—</pre></details>
    <div class="mc-source-parse-log" data-power-parse-log></div>
   </section>
  </div>
 </form>
 <div class="mc-drawer__footer mc-power-drawer-footer"><span data-power-save-state>未修改</span><button class="mc-button" type="button" data-power-submit hidden>提交确认</button><button class="mc-button mc-button--primary" type="button" data-power-approve hidden>确认并转正式</button><button class="mc-button" type="button" data-close-layer>取消</button><button class="mc-button mc-button--primary" type="button" data-power-save>保存草稿</button></div>
</div>
<div class="mc-drawer mc-power-drawer" id="power-batch-drawer" data-drawer data-power-batch>
 <div class="mc-drawer__header"><div><strong>批量设置电源</strong><span data-power-batch-count>已选择 0 项</span></div><button class="mc-icon-button" type="button" data-close-layer>×</button></div>
 <div class="mc-drawer__body mc-power-editor-body">
  <div class="mc-batch-intro"><strong>只修改你明确启用的项目</strong><span>每个项目独立开启；未开启的字段保持原样。执行前会先显示影响数量和跳过原因。</span></div>
  <div class="mc-batch-policy"><strong>覆盖策略</strong><label><input type="radio" name="power_batch_policy" value="fill_empty" checked> 只填空值</label><label><input type="radio" name="power_batch_policy" value="overwrite"> 覆盖已有值</label></div>
  <div class="mc-batch-card-list" data-power-batch-cards></div>
  <div class="mc-batch-preview" data-power-batch-preview hidden></div>
  <div class="mc-form-error" data-power-batch-error hidden></div>
 </div>
 <div class="mc-drawer__footer mc-power-drawer-footer"><span data-power-batch-state>等待预览</span><button class="mc-button" type="button" data-close-layer>取消</button><button class="mc-button" type="button" data-power-batch-preview-button>预览影响</button><button class="mc-button mc-button--primary" type="button" data-power-batch-execute disabled>确认执行</button></div>
</div>
<?php endif; ?>
<?php if(($activeMenu??'')!=='power'&&!$mcCategoryDrawer): ?><div class="mc-modal" id="new-modal" data-modal><form class="mc-modal__panel" data-material-create><div class="mc-modal__header"><div><strong>新建物料</strong><span>选择类别并创建真实草稿</span></div><button class="mc-icon-button" type="button" data-close-layer>×</button></div><div class="mc-modal__body"><input type="hidden" name="csrf_token" value="<?=mc_h(function_exists('csrf_token')?csrf_token():'')?>"><div class="mc-form-grid"><label class="mc-field"><span>类别 *</span><select name="category_id" required data-material-category><?php try{foreach((new \Artdon\MaterialCenter\Services\MaterialMasterService())->categories() as $category):?><option value="<?=intval($category['id'])?>" data-category-code="<?=mc_h($category['code'])?>"><?=mc_h($category['name'])?></option><?php endforeach;}catch(Throwable){ }?></select></label><label class="mc-field"><span>名称 *</span><input name="name" required maxlength="200" placeholder="物料名称"></label><label class="mc-field"><span>品牌</span><input name="brand" maxlength="120" placeholder="品牌"></label><label class="mc-field"><span>型号</span><input name="model" maxlength="160" placeholder="型号"></label><label class="mc-field"><span>单位 *</span><input name="unit" required maxlength="30" value="PCS"></label></div><div class="mc-form-grid" data-category-fields></div><div class="mc-form-error" data-material-form-error hidden></div></div><div class="mc-modal__footer"><button class="mc-button" type="button" data-close-layer>取消</button><button class="mc-button mc-button--primary" type="submit">保存草稿</button></div></form></div><?php endif; ?>
<div class="mc-toast-region" data-toast-region></div><script>window.MC_BASE_URL=<?=json_encode(MC_BASE_URL,JSON_UNESCAPED_UNICODE)?>;window.MC_CSRF=<?=json_encode(function_exists('csrf_token')?csrf_token():'')?>;</script><script src="<?=mc_h(mc_url(mc_ui_asset('assets/js/app.js')))?>"></script><script src="<?=mc_h(mc_url(mc_ui_asset('assets/js/material-shell-data.js')))?>"></script><script src="<?=mc_h(mc_url(mc_ui_asset('assets/js/material-workspace-actions.js')))?>"></script><?php if(($activeMenu??'')==='power'):?><script src="<?=mc_h(mc_url(mc_ui_asset('assets/js/power-editor.js')))?>"></script><?php elseif($mcCategoryDrawer):?><script src="<?=mc_h(mc_url(mc_ui_asset('assets/js/category-editor.js')))?>"></script><?php if($mcCategoryDrawer['code']==='chip'):?><script src="<?=mc_h(mc_url(mc_ui_asset('assets/js/chip-specifications.js')))?>"></script><?php endif;?><?php endif;?></body></html>
