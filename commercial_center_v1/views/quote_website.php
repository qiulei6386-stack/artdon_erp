<?php declare(strict_types=1); $rows=array_slice($catalogRows,0,6); ?>
<section class="ref-quote ref-web" data-quote-editor data-quote-type="website">
  <header class="ref-titlebar">
    <div><a href="?page=quote_center">← 报价单中心</a><h1>网站订单报价单</h1><span class="source-badge">来源：新加坡下单网站</span></div>
    <nav><button class="danger-outline">驳回网站订单</button><button>保存审核备注</button><button class="primary">审核通过</button><button class="primary">转正式订单</button><button>打印 / 导出⌄</button></nav>
  </header>
  <div class="web-flow"><b class="done">✓ 网站传入</b><i></i><b class="active"><em>2</em> 待审核</b><i></i><b><em>3</em> 待确认</b><i></i><b><em>4</em> 已确认</b><i></i><b><em>5</em> 转订单</b></div>
  <div class="web-lock">▣　本单为网站订单型报价单，产品与配置默认锁定，仅允许审核、折扣、运费、备注等有限调整</div>
  <div class="ref-body web-body">
    <main>
      <section class="ref-panel web-order-info">
        <div><label>网站订单号</label><strong>SG<?= date('Ymd') ?>001</strong><small>♙</small><label>网站下单时间</label><strong><?= date('Y-m-d H:i:s') ?></strong><small>♙</small><label>预计交期</label><strong><?= date('Y-m-d',strtotime('+30 days')) ?></strong><small>♙</small></div>
        <div><label>内部报价号</label><strong>保存审核后生成</strong><small>♙</small><label>业务员</label><strong><?= cc_h($view['auth']['user']['display_name'] ?? '待分配') ?></strong><small>♙</small><label>付款方式</label><strong>T/T 30% 预付，70% 见提单副本</strong><small>♙</small></div>
        <div><label>客户</label><strong>待导入网站客户</strong><small>♙</small><label>国家/地区</label><strong>Singapore</strong><small>♙</small></div>
        <div><label>联系人</label><strong>待导入网站联系人</strong><small>♙</small><label>币种</label><strong>USD</strong><small>♙</small></div>
      </section>
      <section class="ref-panel web-products">
        <h2>产品明细 <small>（配置与产品已锁定） ♙</small></h2>
        <div class="ref-table-scroll">
          <table><thead><tr><th>#</th><th>图片</th><th>型号</th><th>产品名称 ♙</th><th>网站配置 ♙</th><th>数量</th><th>网站单价 (USD)</th><th>审核单价 (USD)</th><th>折扣 (%)</th><th>小计 (USD)</th><th>交期</th><th>备注</th></tr></thead>
          <tbody data-quote-lines><?php foreach(($rows?:array_fill(0,6,[])) as $i=>$row): $price=isset($row['bom_cost'])&&$row['bom_cost']!==null?round((float)$row['bom_cost']*1.65,2):0; $img=cc_legacy_asset_url($row['image_path']??''); ?><tr><td><?= $i+1 ?></td><td><div class="ref-thumb"><?php if($img):?><img src="<?=cc_h($img)?>" alt=""><?php else:?>图<?php endif;?></div></td><td><b><?=cc_h($row['model_no']??'待导入SKU')?></b> ♙</td><td><?=cc_h(preg_replace('/ · BOM成本.*$/u','',(string)($row['product_name']??'网站产品')))?> ♙</td><td><span><?=cc_h($row['category']??'网站配置快照')?></span><small>配置锁定</small></td><td><input type="number" value="<?=($i+1)*4?>" data-qty readonly></td><td><b><?=number_format($price,2)?></b> ♙</td><td><input type="number" value="<?=$price?>" data-price></td><td><input type="number" value="0" data-discount></td><td data-line-total>0.00</td><td><?=date('Y-m-d',strtotime('+'.(20+$i).' days'))?> ♙</td><td>— ♙</td></tr><?php endforeach;?></tbody></table>
        </div><footer>共 <?=count($rows?:array_fill(0,6,[]))?> 条产品明细</footer>
      </section>
    </main>
    <aside>
      <section class="ref-panel total-box"><h2>报价汇总 <small>(USD)</small></h2><dl><dt>产品金额</dt><dd data-subtotal>0.00</dd><dt>运费　♙</dt><dd><input value="180" data-shipping></dd><dt>折扣金额</dt><dd><input value="0" data-order-discount></dd><dt>税费 (0%)</dt><dd><input value="0" data-tax></dd></dl><div><b>总金额</b><strong data-grand-total>0.00</strong></div></section>
      <section class="ref-panel note-box"><h2>客户备注 <small>（来自网站）</small></h2><textarea readonly>Please ensure all items meet CE standard.&#10;Need packaging with our logo.</textarea></section>
      <section class="ref-panel note-box"><h2>内部审核备注 <small>（可见内部）</small></h2><textarea placeholder="请输入内部审核备注（如客户要求、风险提示、沟通记录等）"></textarea><small>0 / 500</small></section>
      <section class="ref-panel risk-box"><h2>风险提醒</h2><p>● MOQ 提醒：部分产品低于建议 MOQ</p><p>● 交期提醒：部分交期较晚，请与客户确认</p><p>● 库存提醒：部分库存紧张，建议尽快锁库</p></section>
    </aside>
  </div>
</section>
