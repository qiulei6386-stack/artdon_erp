<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$api = (string)file_get_contents($root . '/quote_api.php');
$page = (string)file_get_contents($root . '/quotation.php');

function need_summary(bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

foreach ([
    'quotation_summary_filters',
    'quotation_summary_overview',
    'quotation_summary_trend',
    'quotation_summary_pie',
    'quotation_summary_rank',
    'quotation_summary_list',
    'quotation_summary_export_excel',
    'quote_summary_filtered_sql',
    'quote_summary_overview_data',
    'quote_summary_trend_data',
] as $token) {
    need_summary(strpos($api, $token) !== false, "报价总结接口或服务端聚合缺失：{$token}");
}
need_summary(strpos($api, 'quote_sales_orders') !== false, '报价总结没有读取订单数据');
need_summary(strpos($api, 'quote_order_payments') !== false, '报价总结没有读取收款数据');
need_summary(strpos($api, 'quote_shipments') !== false, '报价总结没有读取出货数据');
need_summary(strpos($api, 'LIMIT 10000') !== false, '报价总结导出没有读取完整筛选明细的上限');
need_summary(strpos($api, "['RMB'=>(float)") !== false, '金额统计没有保留 RMB / USD 分开口径');
need_summary(strpos($api, 'ksort($owners);$owners=array_values(array_keys($owners));') !== false, '报价总结筛选负责人没有返回 JSON 列表');
need_summary(strpos($api, 'ksort($customers);$customers=array_values(array_keys($customers));') !== false, '报价总结筛选客户没有返回 JSON 列表');
need_summary(strpos($api, 'ksort($countries);$countries=array_values(array_keys($countries));') !== false, '报价总结筛选国家没有返回 JSON 列表');
need_summary(strpos($page, 'data-page="summary"') !== false, '报价总结没有进入报价二级菜单');
need_summary(strpos($page, 'id="page-summary"') !== false, '报价总结页面缺失');
need_summary(strpos($page, 'summaryBusinessTrend') !== false, '数量趋势图容器缺失');
need_summary(strpos($page, 'summaryAmountTrend') !== false, '金额趋势图容器缺失');
need_summary(strpos($page, 'summaryOwnerRank') !== false && strpos($page, 'summaryCustomerRank') !== false, '排行容器缺失');
need_summary(strpos($page, 'summaryTableRows') !== false && strpos($page, 'summaryPager') !== false, '分页明细缺失');
need_summary(strpos($page, 'exportQuotationSummary') !== false, '导出入口缺失');
need_summary(strpos($page, 'function quotationSummaryArray(values)') !== false, '报价总结缺少列表响应兼容处理');
need_summary(strpos($page, 'quotationSummaryArray(values).map') !== false, '报价总结筛选下拉未使用列表响应兼容处理');
need_summary(strpos($page, "if(p==='summary')loadQuotationSummary();") !== false, '进入总结页没有加载数据');

echo "quotation summary contract: OK\n";
