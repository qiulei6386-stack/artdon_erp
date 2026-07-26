<?php
declare(strict_types=1);
$websiteQuoteId = max(0, (int)($_GET['quote_id'] ?? 0));
?>
<section class="ref-quote ref-web" data-quote-editor data-quote-type="website"
 data-website-quote data-quote-id="<?= $websiteQuoteId ?>" data-api="api/v1/website_quotes.php">
  <header class="ref-titlebar">
    <div><a href="?page=quote_center">← 报价单中心</a><h1>网站订单报价单</h1>
      <span class="source-badge">来源：新加坡下单网站</span><small data-web-message></small></div>
    <nav><button type="button" data-new-website>导入 / 代客户下单</button>
      <button type="button" class="danger-outline" data-web-reject>驳回网站订单</button>
      <button type="button" data-web-save>保存审核调整</button>
      <button type="button" class="primary" data-web-approve>审核通过</button>
      <button type="button" data-quote-output="preview">预览</button><button type="button" data-quote-output="pdf">PDF</button>
      <button type="button" data-quote-output="excel">Excel</button><button type="button" data-quote-output="send">发送</button>
      <button type="button" disabled title="后续步骤开放">转正式订单</button></nav>
  </header>
  <div class="web-flow"><b class="done">✓ 网站传入</b><i></i><b class="active"><em>2</em> 待审核</b><i></i>
    <b><em>3</em> 待确认</b><i></i><b><em>4</em> 已确认</b><i></i><b><em>5</em> 转订单</b></div>
  <div class="web-lock">▣　本单为网站订单型报价单，来源产品、SKU、配置、原始数量、网站价格及客户要求已冻结；锁定字段修改必须申请解锁。</div>
  <div class="ref-body web-body"><main>
    <section class="ref-panel web-order-info">
      <div><label>网站订单号</label><strong data-web-field="source_order_no">尚未导入</strong><label>网站下单时间</label><strong data-web-field="placed_at">—</strong></div>
      <div><label>内部报价号</label><strong data-web-field="quote_no">—</strong><label>业务员</label><strong data-web-field="owner_name">—</strong></div>
      <div><label>客户</label><strong data-web-field="customer_name">—</strong><label>国家/地区</label><strong data-web-field="country">—</strong></div>
      <div><label>联系人</label><strong data-web-field="contact_name">—</strong><label>币种</label><strong data-web-field="currency">USD</strong></div>
    </section>
    <section class="ref-panel web-products"><h2>产品明细 <small>（来源字段已锁定）</small></h2>
      <div class="ref-table-scroll"><table><thead><tr><th>#</th><th>型号 / SKU</th><th>产品名称</th><th>网站配置</th>
        <th>原始数量</th><th>网站单价</th><th>审核单价</th><th>折扣 (%)</th><th>小计</th><th>交期</th><th>操作</th>
      </tr></thead><tbody data-quote-lines></tbody></table></div><footer><span data-line-count>共 0 项</span></footer>
    </section>
  </main><aside>
    <section class="ref-panel total-box"><h2>报价汇总 <small data-web-field="currency">USD</small></h2><dl>
      <dt>产品金额</dt><dd data-subtotal>0.00</dd><dt>运费</dt><dd><input type="number" min="0" step=".01" value="0" data-shipping></dd>
      <dt>折扣金额</dt><dd><input type="number" min="0" step=".01" value="0" data-order-discount></dd>
      <dt>税费</dt><dd><input type="number" min="0" step=".01" value="0" data-tax></dd>
    </dl><div><b>总金额</b><strong data-grand-total>0.00</strong></div></section>
    <section class="ref-panel note-box"><h2>客户备注 <small>（来自网站，锁定）</small></h2><textarea data-web-customer-note readonly></textarea></section>
    <section class="ref-panel note-box"><h2>内部审核备注 <small>（可编辑）</small></h2><textarea data-web-internal-note maxlength="500"></textarea></section>
    <section class="ref-panel note-box"><h2>审核条款</h2><input data-web-payment placeholder="付款方式"><input data-web-trade placeholder="贸易条款"></section>
    <section class="ref-panel risk-box"><h2>风险提醒</h2><p>● 产品、SKU、配置、原始数量及客户要求不可直接修改</p>
      <p>● 实时渠道 API 未配置时，仅接受鉴权载荷导入或业务员代下单</p></section>
  </aside></div>
  <div class="quote-config-modal ref-config" data-web-import-modal aria-hidden="true"><div>
    <header><div><b>网站订单导入 / 业务员代客户下单</b><span>保存后形成来源快照并进入待审核</span></div><button type="button" data-modal-close>×</button></header>
    <div class="standard-form">
      <label><span>来源 *</span><select data-import-field="action"><option value="sales_proxy">业务员代客户下单</option><option value="import">新加坡网站载荷导入</option></select></label>
      <label><span>网站订单号 *</span><input data-import-field="external_order_no"></label>
      <label><span>CRM 客户 *</span><select data-import-field="customer_id"><option value="">加载中…</option></select></label>
      <label><span>网站销售产品 *</span><select data-import-field="product"><option value="">加载中…</option></select></label>
      <label><span>SKU *</span><input data-import-field="sku_code"></label>
      <label><span>配置</span><input data-import-field="configuration" placeholder="如 White / 3000K / 24°"></label>
      <label><span>数量 *</span><input type="number" min=".001" step=".001" value="1" data-import-field="quantity"></label>
      <label><span>网站单价 *</span><input type="number" min="0" step=".01" value="0" data-import-field="price"></label>
      <label><span>运费</span><input type="number" min="0" step=".01" value="0" data-import-field="shipping"></label>
      <label><span>下单时间</span><input type="datetime-local" data-import-field="placed_at"></label>
      <label><span>客户要求</span><input data-import-field="requirement"></label>
      <label><span>客户备注</span><input data-import-field="customer_note"></label>
    </div><footer><span data-channel-status>检查渠道状态中…</span><button type="button" data-modal-close>取消</button>
      <button type="button" class="primary" data-import-submit>建立网站报价</button></footer>
  </div></div>
</section>
