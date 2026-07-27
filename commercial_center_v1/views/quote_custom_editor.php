<?php declare(strict_types=1); $customQuoteId=max(0,(int)($_GET['quote_id']??0)); ?>
<section class="quote-page quote-editor" data-quote-editor data-custom-quote data-quote-type="custom_product"
 data-quote-id="<?=$customQuoteId?>" data-api="api/v1/custom_quotes.php">
  <header class="quote-editor-head"><div><a href="?page=quote_center">← 返回报价单中心</a><h1>定制品报价单</h1>
    <span class="quote-state" data-custom-status>草稿</span><small data-custom-message></small></div>
    <div class="quote-actions"><button type="button" data-custom-save>保存草稿</button><button type="button" data-quote-output="preview">预览</button>
      <button type="button" data-quote-output="print">打印</button><button type="button" data-quote-output="pdf">PDF</button>
      <button type="button" data-quote-output="excel">Excel</button><button type="button" data-quote-output="send">发送</button><button type="button" data-custom-submit>提交审核</button>
      <button type="button" data-custom-approve>审核通过</button><button type="button" data-custom-handoff="project">转项目</button>
      <button type="button" data-custom-handoff="order">转订单</button></div></header>
  <div class="quote-editor-grid"><div class="quote-main">
    <section class="quote-card"><h2>基础信息</h2><div class="quote-form-grid">
      <label>报价单号<input data-custom-field="quote_no" readonly value="保存后生成"></label>
      <label>客户 *<select data-custom-field="customer_id"><option>加载中…</option></select></label>
      <label>联系人<input data-custom-field="contact_name"></label><label>国家<input data-custom-field="country"></label>
      <label>项目名称<input data-custom-field="project_name"></label><label>项目类型<input data-custom-field="project_type"></label>
      <label>销售渠道<select data-custom-field="sales_channel"><option value="guangzhou_direct">广州直接销售</option><option value="singapore_web">新加坡网站询价</option></select></label>
      <label>币种<select data-custom-field="currency"><option>USD</option><option>SGD</option><option>CNY</option><option>EUR</option></select></label>
      <label>有效期<input type="date" data-custom-field="valid_until" value="<?=cc_h(date('Y-m-d',strtotime('+30 days')))?>"></label>
      <label>负责人<input data-custom-field="owner_name" readonly value="<?=cc_h((string)($view['auth']['user']['display_name']??''))?>"></label>
      <label>付款方式<input data-custom-field="payment_terms"></label><label>贸易条款<input data-custom-field="trade_terms"></label>
      <label>CRM 商机<input data-custom-field="crm_opportunity"></label><label>关联项目<input data-custom-field="crm_project"></label>
      <label class="span-2">需求摘要<textarea data-custom-field="requirement_summary"></textarea></label>
    </div></section>
    <section class="quote-card quote-upload-card"><h2>整张报价图片与文件</h2>
      <div class="upload-grid"><?php foreach(['product_image'=>'产品图片','reference_image'=>'参考图','dimension_drawing'=>'尺寸图','structure_drawing'=>'结构图','sketch'=>'手绘图','material_image'=>'材料图','document'=>'PDF / Excel / Word / ZIP'] as $type=>$name): ?>
        <label class="upload-box"><input type="file" hidden data-custom-upload="<?=$type?>"><strong>＋ 上传<?=cc_h($name)?></strong><span>保存草稿后可上传</span></label>
      <?php endforeach;?></div><div data-custom-files></div></section>
    <section class="quote-card quote-lines-card"><div class="quote-card-title"><h2>定制报价项</h2><button type="button" data-custom-add>＋ 增加报价项</button></div>
      <div class="quote-lines-scroll"><table class="quote-lines"><thead><tr><th>#</th><th>产品名称</th><th>规格</th><th>单位</th><th>数量</th>
        <th>报价单价</th><th>目标成本</th><th>估算成本</th><th>预计毛利</th><th>交期</th><th>附件</th><th>操作</th></tr></thead>
        <tbody data-quote-lines></tbody></table></div></section>
  </div><aside class="quote-summary">
    <section class="quote-card"><h2>产品自定义字段</h2><p>每项支持材质、颜色、尺寸、功率、安装方式、特殊工艺、核价意见、审核意见及可选标准产品参考。</p></section>
    <section class="quote-card quote-total-card"><h2>报价汇总</h2><dl><dt>产品金额</dt><dd data-subtotal>0.00</dd>
      <dt>折扣金额</dt><dd><input data-order-discount type="number" value="0"></dd><dt>运费</dt><dd><input data-shipping type="number" value="0"></dd>
      <dt>税费</dt><dd><input data-tax type="number" value="0"></dd></dl><div class="grand-total"><span>总金额</span><strong data-grand-total>0.00</strong></div></section>
    <section class="quote-card"><h2>备注</h2><textarea data-custom-field="customer_note" placeholder="客户备注"></textarea>
      <textarea data-custom-field="internal_note" placeholder="内部核价与审核备注"></textarea></section>
  </aside></div>
</section>
