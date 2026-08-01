<?php
declare(strict_types=1);
?>
<section class="quote-page singapore-channel" data-singapore-channel data-api="api/v1/singapore_channel.php">
  <header class="quote-center-head">
    <div><span class="eyebrow">SINGAPORE CHANNEL BRIDGE</span><h1>新加坡产品发布与代客订单</h1>
      <p>从物料中心正式发布版本生成渠道任务，并由商务中心签名推送到新加坡网站。</p></div>
    <div class="page-meta"><span>真实接口</span><b data-sg-adapter>not_configured</b></div>
  </header>
  <div class="quote-info-banner">真实发布使用 HMAC 签名、幂等键和状态回传；重复发布会按 SKU 更新。</div>
  <section class="quote-card"><div class="quote-card-title"><h2>物料中心已发布产品</h2><span>点击后生成任务，再在下方执行真实发送。</span></div>
    <div class="table-wrap"><table><thead><tr><th>型号</th><th>英文名称</th><th>分类 / 系列</th><th>版本</th><th>配置</th><th>操作</th></tr></thead>
      <tbody data-sg-published-products><tr><td colspan="6">加载中…</td></tr></tbody></table></div></section>
  <section class="quote-stats">
    <div><span>套餐草稿</span><strong data-sg-count="draft_packages">0</strong></div>
    <div><span>待发送</span><strong data-sg-count="pending">0</strong></div>
    <div><span>失败待重试</span><strong data-sg-count="failed">0</strong></div>
    <div><span>连接模式</span><strong class="sg-mode">模拟</strong></div>
  </section>
  <div class="singapore-grid">
    <section class="quote-card">
      <h2>维护公开套餐</h2>
      <form data-sg-package-form>
        <input type="hidden" name="id">
        <label>库存 SKU *<select name="inventory_sku_id" required><option>加载中…</option></select></label>
        <label>公开套餐编码 *<input name="package_code" required placeholder="例如 SG-NOVLIGHT-001"></label>
        <label>公开标题 *<input name="public_title" required placeholder="网站展示标题"></label>
        <label>英文名称 *<input name="english_name" required placeholder="English product name"></label>
        <label>SGD 售价 *<input name="public_price" type="number" min=".01" step=".01" required></label>
        <label>MOQ *<input name="moq" type="number" min=".001" step=".001" value="1"></label>
        <label>交期（天）<input name="lead_time_days" type="number" min="0" value="0"></label>
        <label>公开参数<textarea name="public_parameters" placeholder="每行：参数名=参数值"></textarea></label>
        <label class="check-label"><input name="publishable" type="checkbox" checked> 允许发布此库存 SKU</label>
        <label class="check-label"><input name="allow_order" type="checkbox"> 允许客户直接下单（否则仅询价）</label>
        <footer><button type="reset">清空</button><button type="submit" class="primary">保存套餐草稿</button></footer>
      </form>
      <p data-sg-message></p>
    </section>
    <section class="quote-card singapore-packages">
      <h2>公开套餐与发布状态</h2>
      <div class="table-wrap"><table><thead><tr><th>套餐 / SKU</th><th>公开名称</th><th>价格</th><th>可售</th><th>下单</th><th>状态</th><th>操作</th></tr></thead>
        <tbody data-sg-packages><tr><td colspan="7">加载中…</td></tr></tbody></table></div>
    </section>
  </div>
  <section class="quote-card">
    <div class="quote-card-title"><h2>发送与重试记录</h2><span>成功后记录新加坡产品编号和响应；失败可重试。</span></div>
    <div class="table-wrap"><table><thead><tr><th>ID</th><th>业务</th><th>对象</th><th>状态</th><th>尝试</th><th>模拟/外部编号</th><th>错误</th><th>时间</th><th>操作</th></tr></thead>
      <tbody data-sg-outbox><tr><td colspan="9">加载中…</td></tr></tbody></table></div>
  </section>
</section>
