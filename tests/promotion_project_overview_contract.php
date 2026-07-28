<?php

/** Static contract for the promotion project home priority layout. */
$root = dirname(__DIR__);
$js = file_get_contents($root . '/assets/crm/crm.js');
$css = file_get_contents($root . '/assets/crm/crm.css');
$errors = [];

foreach ([
    [$js, 'promo-project-overview', '项目首页优先概览'],
    [$js, 'promo-project-focus-grid', '项目目标与执行双重点卡片'],
    [$js, 'promo-project-detail-fold', '项目明细按需展开'],
    [$js, '查看目标客户与联系人', '目标客户明细入口'],
    [$js, '查看邮件与人工执行规则', '执行规则明细入口'],
    [$js, '查看完整时间计划与队列状态', '计划队列明细入口'],
    [$css, '.promo-project-overview {', '项目概览样式'],
    [$css, '.promo-project-detail-fold {', '项目折叠明细样式'],
    [$css, '@media (max-width: 780px)', '项目首页窄屏适配'],
] as [$source, $needle, $label]) {
    if (strpos($source, $needle) === false) $errors[] = '缺少：' . $label;
}

if ($errors) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}
echo "promotion_project_overview_contract: OK\n";
