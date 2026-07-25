<?php declare(strict_types=1); ?>
<section class="quote-page quote-approval">
  <header class="quote-center-head"><div><span class="eyebrow">QUOTATION APPROVAL</span><h1>报价审核</h1><p>网站订单、标准品和定制品报价共用同一审核工作队列。</p></div><span class="quote-state">独立审核队列</span></header>
  <section class="quote-stats"><div><span>网站订单待审核</span><strong><?= (int)($ops['counts']['pending_approval'] ?? 0) ?></strong></div><div><span>标准品报价待审核</span><strong>0</strong></div><div><span>定制品报价待审核</span><strong>0</strong></div></section>
  <section class="quote-card"><div class="approval-filters"><select><option>全部报价类型</option><option>网站订单报价单</option><option>标准品报价单</option><option>定制品报价单</option></select><input placeholder="负责人"><input placeholder="客户"><input type="date" aria-label="提交开始时间"><select><option>全部风险等级</option><option>高风险</option><option>中风险</option><option>低风险</option></select><select><option>全部审核状态</option><option>待审核</option><option>已通过</option><option>已驳回</option></select><button>筛选</button></div><div class="quote-empty"><strong>当前没有可显示的审核任务</strong><span>提交审核后将在此按现有账号数据范围显示；点击任务会进入对应报价详情，不创建第四套页面。</span></div></section>
</section>
