<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/quotation.php');
$api = (string) file_get_contents($root . '/quote_order_api.php');

$checks = [
    '独立导航入口' => str_contains($page, 'data-page="commission-summary"'),
    '独立页面容器' => str_contains($page, 'id="page-commission-summary"'),
    '筛选条件完整' => str_contains($page, 'commissionSummaryCustomerCode')
        && str_contains($page, 'commissionSummaryDateFrom')
        && str_contains($page, 'commissionSummarySettle'),
    '页面读取数据' => str_contains($page, "if(p==='commission-summary'){loadCommissionSummaryPage();return;}"),
    '编辑页不嵌入汇总' => str_contains($page, 'renderCommissionOrderSummary=function(){$(\'commissionOrderSummary\')?.remove()}'),
    '独立接口实现' => str_contains($api, 'function qo_commission_summary_list'),
    '客户代码筛选' => str_contains($api, 'customer_code'),
    '日期筛选' => str_contains($api, 'date_from'),
    '默认排除零佣金订单' => str_contains($api, "has_commission")
        && str_contains($api, 'COALESCE(s.commission_amount,0)>0'),
    '币种汇总采用表格' => str_contains($page, 'id="commissionSummaryCurrencyRows"')
        && str_contains($page, '币种佣金汇总'),
    '接口已分发' => str_contains($api, "commission_summary_list"),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
if ($failed) {
    fwrite(STDERR, 'FAIL: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo "PASS: commission summary page contract\n";
