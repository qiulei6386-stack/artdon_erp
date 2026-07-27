<?php
declare(strict_types=1);
$stockQuoteId = max(0, (int)($_GET['quote_id'] ?? 0));
?>
<section class="ref-quote ref-standard" data-quote-editor data-stock-quote data-quote-type="stock_product"
         data-quote-id="<?= $stockQuoteId ?>" data-api="api/v1/stock_quotes.php">
  <header class="ref-titlebar standard-title">
    <div><a href="?page=quote_center">← 返回报价单中心</a><h1>库存品报价单（快速 / 配置锁定）</h1>
      <span class="quote-state" data-stock-status>草稿</span><small data-stock-message></small>
    </div>
    <nav><button type="button" data-stock-save>保存草稿</button>
      <button type="button" data-quote-output="preview">预览</button><button type="button" data-quote-output="pdf">PDF</button>
      <button type="button" data-stock-submit>提交审核</button>
      <button type="button" class="primary" data-stock-queue-order>加入新加坡待发送</button></nav>
  </header>
  <div class="quote-info-banner locked">库存 SKU 的型号和配置已锁定；只能调整数量、报价价格和备注。选择新加坡网站时，只显示已完成模拟发布且允许下单的 SKU。</div>
  <div class="ref-body standard-body">
    <main>
      <section class="ref-panel"><h2>报价信息</h2><div class="standard-form">
        <label><span>报价单号</span><input data-stock-field="quote_no" readonly value="保存后生成"></label>
        <label><span>客户 *</span><select data-stock-field="customer_id"><option>加载 CRM 客户中…</option></select></label>
        <label><span>联系人</span><input data-stock-field="contact_name"></label>
        <label><span>国家/地区</span><input data-stock-field="country"></label>
        <label><span>销售渠道 *</span><select data-stock-field="sales_channel">
          <option value="guangzhou_direct">广州直接销售</option>
          <option value="singapore_web">新加坡网站代客下单（模拟）</option>
        </select></label>
        <label><span>币种 *</span><select data-stock-field="currency"><option>USD</option><option>SGD</option><option>CNY</option></select></label>
        <label><span>有效期</span><input type="date" data-stock-field="valid_until" value="<?= cc_h(date('Y-m-d', strtotime('+30 days'))) ?>"></label>
        <label><span>负责人</span><input readonly value="<?= cc_h((string)($view['auth']['user']['display_name'] ?? '')) ?>"></label>
        <label><span>付款方式</span><input data-stock-field="payment_terms" value="100% 下单付款"></label>
        <label><span>贸易条款</span><input data-stock-field="trade_terms" value="EXW 广州"></label>
      </div></section>
      <section class="ref-panel standard-lines">
        <div class="panel-title"><h2>库存 SKU 明细</h2><nav><button type="button" data-stock-add>＋ 添加库存 SKU</button>
          <button type="button" data-stock-batch-qty>批量数量</button></nav></div>
        <div class="ref-table-scroll"><table><thead><tr><th>#</th><th>SKU</th><th>型号 / 产品</th><th>锁定配置</th>
          <th>可售库存</th><th>数量</th><th>单价</th><th>小计</th><th>备注</th><th>操作</th></tr></thead>
          <tbody data-stock-lines></tbody></table></div>
        <footer><button type="button" data-stock-add>＋ 添加库存 SKU</button><span data-stock-line-count>共 0 项</span></footer>
      </section>
    </main>
    <aside>
      <section class="ref-panel"><h2>选择库存 SKU</h2>
        <label><span>库存 SKU</span><select data-stock-picker><option>加载中…</option></select></label>
        <label><span>数量</span><input type="number" min=".001" step=".001" value="1" data-stock-picker-qty></label>
        <button type="button" class="primary" data-stock-apply>校验并加入</button>
        <p data-stock-picker-message></p>
      </section>
      <section class="ref-panel total-box"><h2>报价汇总</h2><dl>
        <dt>产品金额</dt><dd data-stock-subtotal>0.00</dd>
        <dt>折扣金额</dt><dd><input type="number" value="0" data-stock-discount></dd>
        <dt>运费</dt><dd><input type="number" value="0" data-stock-shipping></dd>
        <dt>税费</dt><dd><input type="number" value="0" data-stock-tax></dd>
      </dl><div><b>总金额</b><strong data-stock-total>0.00</strong></div></section>
      <section class="ref-panel risk-cards">
        <article>锁<b>库存配置锁定</b><span>SKU 核心配置不能在报价中改写</span></article>
        <article>库<b>库存实时校验</b><span>保存时重新检查可销售库存</span></article>
        <article>新<b>新加坡接口</b><span>当前仅模拟，绝不发送到外网</span></article>
      </section>
    </aside>
  </div>
</section>
