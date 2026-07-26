<?php
declare(strict_types=1);
$rows = array_slice($catalogRows, 0, 8);
$standardQuoteId = max(0, (int)($_GET['quote_id'] ?? 0));
?>
<section class="ref-quote ref-standard" data-quote-editor data-quote-type="standard_product"
         data-standard-quote data-quote-id="<?= $standardQuoteId ?>" data-api="api/v1/standard_quotes.php">
  <header class="ref-titlebar standard-title">
    <div><a href="?page=quote_center">＋ 新建报价单</a><h1>标准品报价单（半自由）</h1>
      <span class="quote-state" data-quote-status><?= $quickMode ? '快速创建' : '草稿' ?></span>
      <small data-save-message></small>
    </div>
    <nav>
      <button type="button" data-draft-save>保存草稿</button>
      <button type="button">预览</button><button type="button">打印</button>
      <button type="button">导出PDF</button><button type="button">导出Excel</button>
      <button type="button" data-submit-approval>提交审核</button>
      <button type="button" class="primary" disabled title="审核通过后启用">发送报价</button>
    </nav>
  </header>
  <?php if ($quickMode): ?><div class="quick-strip">快速创建模式：保存后可补充完整客户与条款信息</div><?php endif; ?>
  <div class="ref-body standard-body">
    <main>
      <section class="ref-panel"><h2>报价单信息</h2><div class="standard-form">
        <label><span>报价单号</span><input data-field="quote_no" value="保存后生成" readonly></label>
        <label><span>客户 *</span><select data-field="customer_id" required><option value="">加载 CRM 客户中…</option></select></label>
        <label><span>联系人</span><input data-field="contact_name"></label>
        <label><span>国家/地区 *</span><input data-field="country" required></label>
        <label><span>币种 *</span><select data-field="currency"><option>USD</option><option>CNY</option><option>EUR</option></select></label>
        <label><span>有效期 *</span><input type="date" data-field="valid_until" value="<?= cc_h(date('Y-m-d', strtotime('+30 days'))) ?>"></label>
        <label><span>负责人 *</span><input data-field="owner_name" value="<?= cc_h((string)($view['auth']['user']['display_name'] ?? '')) ?>" readonly></label>
        <label><span>付款方式 *</span><input data-field="payment_terms" value="30% 预付款，70% 发货前"></label>
        <label><span>贸易条款 *</span><input data-field="trade_terms" value="FOB 深圳"></label>
        <label><span>报价日期</span><input type="date" data-field="quote_date" value="<?= cc_h(date('Y-m-d')) ?>"></label>
        <label><span>项目</span><input data-field="project_ref" placeholder="可关联 CRM 项目"></label>
        <label><span>汇率</span><input type="number" step=".00000001" data-field="exchange_rate" value="1"></label>
      </div></section>

      <section class="ref-panel standard-lines">
        <div class="panel-title"><h2>报价明细</h2><nav>
          <button type="button" data-open-product>＋ 添加产品</button>
          <button type="button" data-batch-qty>批量数量</button>
          <button type="button" data-batch-discount>批量折扣</button>
        </nav></div>
        <div class="ref-table-scroll"><table><thead><tr>
          <th>#</th><th>图片</th><th>型号</th><th>产品名称</th><th>配置摘要</th>
          <th>数量</th><th>单价</th><th>折扣</th><th>小计</th><th>交期</th><th>备注</th><th>操作</th>
        </tr></thead><tbody data-quote-lines>
        <?php foreach ($rows as $i => $row): $img = cc_legacy_asset_url($row['image_path'] ?? ''); ?>
          <tr data-product-key="standard:<?= (int)$row['id'] ?>" data-model="<?= cc_h($row['model_no']) ?>">
            <td><?= $i + 1 ?></td><td><div class="ref-thumb"><?php if ($img): ?><img src="<?= cc_h($img) ?>" alt=""><?php else: ?>图<?php endif; ?></div></td>
            <td><b data-model><?= cc_h($row['model_no']) ?></b></td>
            <td data-product-name><?= cc_h(preg_replace('/ · BOM成本.*$/u', '', (string)$row['product_name'])) ?></td>
            <td data-config-summary><span>工厂标准配置</span><small>保存时按适配规则重新校验</small></td>
            <td><input type="number" min=".001" step=".001" value="1" data-qty></td>
            <td><input type="number" min="0" step=".01" value="0" data-price></td>
            <td><input type="number" min="0" max="100" value="0" data-discount></td>
            <td data-line-total>0.00</td><td data-lead>待计算</td>
            <td><input data-line-note></td><td><button type="button" data-configure>编辑</button>
              <button type="button" class="text-danger" data-remove-line>删除</button></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <footer><button type="button" data-add-line>＋ 添加产品</button><span data-line-count>共 <?= count($rows) ?> 项</span></footer>
      </section>
    </main>
    <aside>
      <section class="ref-panel total-box"><h2>报价汇总</h2><dl>
        <dt>产品金额（未税）</dt><dd data-subtotal>0.00</dd>
        <dt>折扣金额</dt><dd><input value="0" data-order-discount></dd>
        <dt>运费</dt><dd><input value="0" data-shipping></dd>
        <dt>税费</dt><dd><input value="0" data-tax></dd>
        <dt>其他费用</dt><dd><input value="0" data-other></dd>
      </dl><div><b>总金额</b><strong data-grand-total>0.00</strong></div>
      <p>预计成本　<b data-total-cost>按权限计算</b></p><p>预计毛利　<b data-gross-profit>按权限显示</b></p>
      <p>毛利率　<b data-gross-margin>按权限显示</b></p></section>
      <section class="ref-panel risk-cards">
        <article>△<b>MOQ 提醒</b><span data-moq-warning>保存后按真实策略检查</span></article>
        <article>▣<b>交期提醒</b><span data-lead-warning>保存后按配置计算</span></article>
        <article>◇<b>佣金提醒</b><span data-commission-warning>读取客户及产品佣金规则</span></article>
      </section>
      <section class="ref-panel note-box"><h2>备注信息</h2>
        <textarea data-field="customer_note" placeholder="客户备注"></textarea>
        <textarea data-field="internal_note" placeholder="内部备注"></textarea>
      </section>
    </aside>
  </div>
  <div class="standard-workflow"><b>内部流程</b>
    <?php foreach (['草稿'=>'当前状态','内部核价'=>'待核价','待审核'=>'待提交','已发送'=>'尚未发送','转项目/转订单'=>'未执行'] as $step=>$state): ?>
      <span><strong><?= $step ?></strong><small><?= $state ?></small></span><i>›</i>
    <?php endforeach; ?>
  </div>
  <div class="quote-config-modal ref-config" data-config-modal aria-hidden="true"><div>
    <header><div><b>添加产品 / 选择标准产品与配置</b><span>按物料中心适配规则选择合法配置</span></div>
      <button type="button" data-modal-close>×</button></header>
    <div class="config-steps"><b>1 选择产品</b><span>2 选择配置</span><span>3 加入报价</span></div>
    <label>产品<select data-config-product><option value="">加载真实产品中…</option></select></label>
    <div class="config-options" data-config-options></div><div data-config-messages></div>
    <footer><span>价格、MOQ、交期和毛利由服务端计算</span><button type="button" data-modal-close>取消</button>
      <button type="button" class="primary" data-apply-config>校验并加入报价</button></footer>
  </div></div>
</section>
