<?php declare(strict_types=1); ?>
<section class="quote-page compatibility-read-page">
  <header class="quote-center-head"><div><span class="eyebrow">PRODUCT COMPATIBILITY</span><h1>产品适配规则</h1><p>规则维护归属物料与配件中心；标准品报价仅只读调用。</p></div><span class="quote-state">维护归属：物料中心</span></header>
  <section class="quote-card"><div class="compatibility-rule-grid"><?php foreach(['默认芯片','可选芯片','默认电源','可选电源','光学','调光','配件','冲突规则','审批规则'] as $rule): ?><div><b><?= cc_h($rule) ?></b><span>由物料与配件中心统一维护</span></div><?php endforeach; ?></div></section>
</section>
