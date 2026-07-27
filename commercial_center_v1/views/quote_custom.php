<?php
declare(strict_types=1);

$quoteOverview = ['rows' => [], 'counts' => ['total'=>0,'draft'=>0,'pending_approval'=>0,'sent'=>0,'pending_customer'=>0,'converted'=>0]];
if (!empty($view['auth']['authenticated']) && is_array($view['auth']['user'] ?? null)) {
    $quoteOverview = (new Artdon\CommercialCenter\Services\QuoteCenterService())->overview($view['auth']['user']);
}
$dashboardQuotes = $quoteOverview['rows'];
$counts = $quoteOverview['counts'];
?>
<section class="quote-hub" data-quote-hub>
  <header class="qhub-heading">
    <div class="qhub-title-icon">▤</div>
    <div><h1>报价单中心</h1>
      <p>按库存品、标准品、定制品创建报价；销售渠道另行选择广州或新加坡。</p></div>
    <nav><a href="?page=singapore_channel">新加坡发布</a>
      <a href="?page=quote_center&quote_mode=website">网站订单回流</a>
      <button type="button" class="primary" data-new-quote>＋ 新建报价单</button></nav>
  </header>

  <section class="quote-info-banner">
    产品类型决定配置自由度：库存品配置锁定；标准品只允许物料中心已审批范围；定制品可自由录入并走工程、核价与审批。
    新加坡网站只是销售渠道，不再作为产品类型。
  </section>

  <section class="qhub-kpis" aria-label="报价统计">
    <?php foreach ([
      ['全部报价',$counts['total'],'blue','▣'],['草稿',$counts['draft'],'amber','▤'],
      ['待审核',$counts['pending_approval'],'purple','◷'],['已发送',$counts['sent'],'teal','➤'],
      ['待客户确认',$counts['pending_customer'],'orange','♙'],['已转订单',$counts['converted'],'blue','✓'],
    ] as $kpi): ?>
      <article><i class="<?= cc_h($kpi[2]) ?>"><?= cc_h($kpi[3]) ?></i>
        <div><span><?= cc_h($kpi[0]) ?></span><strong><?= (int)$kpi[1] ?></strong><small>真实数据</small></div></article>
    <?php endforeach; ?>
  </section>

  <section class="qhub-filters">
    <label class="qhub-search"><input type="search" data-quote-search placeholder="搜索报价单号 / 客户 / 联系人 / 产品类型"><b>⌕</b></label>
    <label><span>报价类型</span><select data-quote-filter="type"><option value="">全部类型</option>
      <option value="stock_product">库存品报价</option><option value="standard_product">标准品报价</option>
      <option value="custom_product">定制品报价</option><option value="website_order">网站回流订单</option></select></label>
    <label><span>状态</span><select data-quote-filter="status"><option value="">全部状态</option>
      <option value="draft">草稿</option><option value="pending_approval">待审核</option><option value="approved">已审核</option>
      <option value="sent">已发送</option><option value="customer_confirmed">客户已确认</option></select></label>
    <label><span>渠道</span><select data-quote-filter="channel"><option value="">全部渠道</option>
      <option value="guangzhou_direct">广州直接销售</option><option value="singapore_web">新加坡网站</option></select></label>
    <button type="button" data-quote-reset>重置</button>
  </section>

  <div class="qhub-lower">
    <section class="qhub-table-card">
      <div class="qhub-table-scroll"><table>
        <thead><tr><th>报价单号</th><th>报价类型</th><th>销售渠道 / 来源</th><th>客户</th><th>联系人</th>
          <th>产品数</th><th>总金额</th><th>状态</th><th>负责人</th><th>更新时间</th><th>操作</th></tr></thead>
        <tbody data-real-quote-rows>
        <?php if ($dashboardQuotes === []): ?>
          <tr data-empty-row><td colspan="11"><div class="quote-empty"><strong>暂无可见报价</strong><span>点击“新建报价单”开始。</span></div></td></tr>
        <?php else: foreach ($dashboardQuotes as $quote): ?>
          <tr data-quote-row data-type="<?= cc_h((string)$quote['quote_type']) ?>"
              data-status="<?= cc_h((string)$quote['status']) ?>" data-channel="<?= cc_h((string)($quote['sales_channel'] ?? '')) ?>"
              data-search="<?= cc_h(mb_strtolower(implode(' ', [
                  $quote['quote_no'],$quote['type_label'],$quote['customer_name'],$quote['contact_name'] ?? '',
                  $quote['source_label'],$quote['owner_name'] ?? '',
              ]))) ?>">
            <td><b><?= cc_h((string)$quote['quote_no']) ?></b></td>
            <td><?= cc_h((string)$quote['type_label']) ?></td>
            <td><b><?= cc_h((string)$quote['source_label']) ?></b><small><?= cc_h((string)($quote['sales_channel'] ?? '旧数据未标记')) ?></small></td>
            <td><?= cc_h((string)$quote['customer_name']) ?></td><td><?= cc_h((string)($quote['contact_name'] ?? '—')) ?></td>
            <td><?= (int)$quote['item_count'] ?></td><td><b><?= cc_h((string)$quote['currency']) ?> <?= cc_h(number_format((float)$quote['total_amount'], 2)) ?></b></td>
            <td><span class="qhub-status <?= cc_h((string)$quote['status']) ?>"><?= cc_h((string)$quote['status']) ?></span>
              <?php if (!empty($quote['push_status']) && $quote['push_status'] !== 'not_required'): ?><small>推送：<?= cc_h((string)$quote['push_status']) ?></small><?php endif; ?></td>
            <td><?= cc_h((string)($quote['owner_name'] ?? '—')) ?></td><td><?= cc_h((string)$quote['updated_at']) ?></td>
            <td class="qhub-row-actions"><a href="<?= cc_h((string)$quote['edit_url']) ?>">查看 / 编辑</a></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table></div>
      <footer><span data-visible-count>共 <?= count($dashboardQuotes) ?> 条</span></footer>
    </section>

    <aside class="qhub-side">
      <section><h2>三种新建方式</h2>
        <a href="?page=quote_center&quote_mode=stock"><i class="blue">库</i><div><b>库存品报价</b><span>配置锁定、实时检查可售库存，可代客下单</span></div><em>›</em></a>
        <a href="?page=quote_center&quote_mode=standard"><i class="green">标</i><div><b>标准品报价</b><span>读取物料中心已审批适配，范围内灵活选择</span></div><em>›</em></a>
        <a href="?page=quote_center&quote_mode=custom&editor=1"><i class="amber">定</i><div><b>定制品报价</b><span>高自由度需求、附件、成本、审批与项目流转</span></div><em>›</em></a>
      </section>
      <section><h2>新加坡渠道</h2>
        <a href="?page=singapore_channel"><i class="teal">新</i><div><b>产品发布与待发送</b><span>维护网站公开套餐、模拟发送、失败重试</span></div></a>
        <a href="?page=quote_center&quote_mode=website"><i class="purple">回</i><div><b>网站订单回流</b><span>保留未来网站订单导入与原始快照审核</span></div></a>
      </section>
    </aside>
  </div>

  <div class="quote-modal" data-type-modal aria-hidden="true"><div><header><div><h2>新建报价单</h2>
    <p>先选择产品自由度；进入编辑页后再选择销售渠道。</p></div><button type="button" data-modal-close>×</button></header>
    <div class="quote-type-grid">
      <?php foreach([
        'stock'=>['库存品报价单','库存 SKU 配置锁定，数量与价格可调整；支持新加坡代客下单。','库'],
        'standard'=>['标准品报价单','读取物料中心已审批适配，在允许范围内配置。','标'],
        'custom'=>['定制品报价单','自由录入需求、附件、成本和交期，进入工程审批。','定'],
      ] as $key=>$type): ?>
        <a href="?page=quote_center&quote_mode=<?= $key ?><?= $key==='custom'?'&editor=1':'' ?>" data-quote-type-link>
          <i><?= cc_h($type[2]) ?></i><strong><?= cc_h($type[0]) ?></strong><span><?= cc_h($type[1]) ?></span><b>选择 →</b></a>
      <?php endforeach; ?>
    </div>
  </div></div>
</section>
