<?php
declare(strict_types=1);

$dashboardQuotes = [
    ['AT-260725EX053-01','标准品报价单','网站订单','Horizons Lumiere','—',3,'32.63','USD','pending','邱磊','2025-07-25 14:32'],
    ['AT-260725EX147','标准品报价单','网站订单','LED-UNIPROF','—',5,'47.50','USD','approved','邱磊','2025-07-25 11:18'],
    ['AT-260725EX053','标准品报价单','网站订单','Horizons Lumiere','—',2,'17.62','USD','approved','邱磊','2025-07-25 10:07'],
    ['AT-260704EX001-01','标准品报价单','网站订单','JAFIROE Marketing Incorporated (JMI)','—',4,'31.00','USD','approved','邱磊','2025-07-04 16:34'],
    ['AT-260630EX136','标准品报价单','网站订单','Dazzy Dekors Ltd','—',8,'1,350.00','USD','approved','邱磊','2025-06-30 09:41'],
    ['AT-260724EX028','标准品报价单','网站订单','成都照明','—',12,'3,250.00','RMB','approved','邱磊','2025-06-24 17:09'],
    ['AT-260710CN069','标准品报价单','网站订单','江门市华彩光电有限公司','—',15,'27,605.88','RMB','approved','邱磊','2025-06-10 15:22'],
    ['AT-260721CN069','标准品报价单','网站订单','江门市华彩光电有限公司','—',6,'1,042.50','RMB','approved','邱磊','2025-06-21 10:11'],
    ['AT-260722CN069','标准品报价单','网站订单','江门市华彩光电有限公司','—',10,'2,958.45','RMB','approved','邱磊','2025-06-22 14:08'],
    ['AT-260723EX144','标准品报价单','网站订单','Winsan LED','—',9,'6,159.00','USD','approved','邱磊','2025-06-23 09:55'],
];
?>
<section class="quote-hub" data-quote-hub>
  <header class="qhub-heading">
    <div class="qhub-title-icon">▤</div>
    <div>
      <h1>报价单中心</h1>
      <p>统一管理网站订单、标准品与定制品报价，跟踪报价状态与转化进度。</p>
    </div>
    <nav>
      <button type="button">↧ 导入</button>
      <button type="button">↧ 导出</button>
      <button type="button">ⓘ 帮助</button>
      <button type="button" class="primary" data-new-quote>＋ 新建报价单</button>
    </nav>
  </header>

  <section class="qhub-kpis" aria-label="报价统计">
    <?php foreach ([
      ['全部报价','10','—','blue','▣'],
      ['草稿','0','—','amber','▤'],
      ['待审核','1','较昨日 +1','purple','◷'],
      ['已发送','9','较昨日 +2','teal','➤'],
      ['待客户确认','9','较昨日 +2','orange','♙'],
      ['已转订单','0','较昨日 —','blue','✓'],
    ] as $kpi): ?>
      <article>
        <i class="<?= cc_h($kpi[3]) ?>"><?= cc_h($kpi[4]) ?></i>
        <div><span><?= cc_h($kpi[0]) ?></span><strong><?= cc_h($kpi[1]) ?></strong><small><?= cc_h($kpi[2]) ?></small></div>
      </article>
    <?php endforeach; ?>
  </section>

  <section class="qhub-filters">
    <label class="qhub-search"><input type="search" placeholder="搜索报价单号 / 客户 / 联系人 / 产品型号 / 网站订单号"><b>⌕</b></label>
    <?php foreach (['报价类型'=>['全部类型','网站订单报价单','标准品报价单','定制品报价单'],'状态'=>['全部状态','pending','approved'],'币种'=>['全部币种','USD','RMB'],'负责人'=>['全部负责人','邱磊']] as $label=>$options): ?>
      <label><span><?= cc_h($label) ?></span><select><?php foreach($options as $option): ?><option><?= cc_h($option) ?></option><?php endforeach; ?></select></label>
    <?php endforeach; ?>
    <label class="qhub-date"><span>更新时间</span><div>▣　开始日期　 ~　 结束日期　⌄</div></label>
    <button type="button" class="filter-button">⌕ 筛选</button>
    <button type="button">↻ 重置</button>
  </section>

  <nav class="qhub-tabs">
    <button type="button" class="active">全部报价</button>
    <button type="button">网站订单</button>
    <button type="button">标准品</button>
    <button type="button">定制品</button>
  </nav>

  <div class="qhub-lower">
    <section class="qhub-table-card">
      <div class="qhub-table-scroll">
        <table>
          <thead><tr><th>报价单号</th><th>网站类型</th><th>来源</th><th>客户</th><th>联系人</th><th>产品数</th><th>总金额</th><th>币种</th><th>状态</th><th>负责人</th><th>更新时间</th><th>操作</th></tr></thead>
          <tbody>
          <?php foreach($dashboardQuotes as $quote): ?>
            <tr>
              <td><b><?= cc_h($quote[0]) ?></b></td>
              <td><?= cc_h($quote[1]) ?></td><td><?= cc_h($quote[2]) ?></td><td><?= cc_h($quote[3]) ?></td>
              <td><?= cc_h($quote[4]) ?></td><td><?= (int)$quote[5] ?></td><td><b><?= cc_h($quote[6]) ?></b></td>
              <td><?= cc_h($quote[7]) ?></td><td><span class="qhub-status <?= cc_h($quote[8]) ?>"><?= cc_h($quote[8]) ?></span></td>
              <td><?= cc_h($quote[9]) ?></td><td><?= cc_h($quote[10]) ?></td>
              <td class="qhub-row-actions"><a href="?page=quote_center&quote_mode=website">查看</a><a href="?page=quote_center&quote_mode=standard">编辑</a><button>复制</button><button>PDF</button><button>Excel</button><button>更多⌄</button></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <footer><span>共 10 条</span><div><select><option>10 条/页</option></select><button disabled>‹</button><button class="active">1</button><button>›</button><span>前往</span><input value="1"><span>页</span></div></footer>
    </section>

    <aside class="qhub-side">
      <section>
        <h2>快速开始</h2>
        <a href="?page=quote_center&quote_mode=website"><i class="blue">▤</i><div><b>新建网站订单报价</b><span>基于网站订单创建报价</span></div><em>›</em></a>
        <a href="?page=quote_center&quote_mode=standard"><i class="green">▤</i><div><b>新建标准品报价</b><span>快速创建标准品报价</span></div><em>›</em></a>
        <a href="?page=quote_center&quote_mode=custom&editor=1"><i class="amber">▤</i><div><b>新建定制品报价</b><span>为定制产品创建报价</span></div><em>›</em></a>
        <a href="?page=quote_approval"><i class="purple">♧</i><div><b>待审核网站订单</b><span>查看待审核的报价单</span></div><mark>1</mark><em>›</em></a>
      </section>
      <section>
        <h2>帮助与支持</h2>
        <a href="#"><i class="blue">?</i><div><b>查看报价管理指南</b><span>了解报价流程与操作说明</span></div></a>
        <a href="#"><i class="teal">▤</i><div><b>常见问题</b><span>获取常见问题解答</span></div></a>
      </section>
    </aside>
  </div>

  <div class="quote-modal" data-type-modal aria-hidden="true"><div><header><div><h2>新建报价单</h2><p>选择需要创建的报价类型</p></div><button type="button" data-modal-close>×</button></header><div class="quote-type-grid"><?php foreach(['website'=>['网站订单报价单','导入或代建新加坡网站订单。'],'standard'=>['标准品报价单','从产品库选品并读取价格规则。'],'custom'=>['定制品报价单','自由录入产品与项目需求。']] as $key=>$type): ?><a href="?page=quote_center&quote_mode=<?= $key ?><?= $key==='custom'?'&editor=1':'' ?>"><i><?= $key==='website'?'网':($key==='standard'?'标':'定') ?></i><strong><?= cc_h($type[0]) ?></strong><span><?= cc_h($type[1]) ?></span><b>选择 →</b></a><?php endforeach; ?></div></div></div>
</section>
