<?php
$root = getenv('COMMISSION_CONTRACT_ROOT') ?: dirname(__DIR__);
$orderApi = (string) file_get_contents($root . '/quote_order_api.php');
$quoteApi = (string) file_get_contents($root . '/quote_api.php');
$page = (string) file_get_contents($root . '/quotation.php');

$checks = [
    '订单接口的佣金 INSERT 明确按 quote_id/order_id/quote_no/order_no 排列参数'
        => str_contains($orderApi, '$insertParams=array_merge([$params[0],$orderId,$params[1],$params[2]],array_slice($params,3))'),
    '报价接口的佣金 INSERT 使用相同的安全参数排列'
        => str_contains($quoteApi, '$insertParams=array_merge([$params[0],$orderId,$params[1],$params[2]],array_slice($params,3))'),
    '佣金接口跳过全量订单结构扫描'
        => str_contains($orderApi, '$commissionActions=') && str_contains($orderApi, "if(!in_array(\$action,\$commissionActions,true))qo_ensure_schema(\$pdo)"),
    '佣金结构使用版本状态快速检查'
        => str_contains($orderApi, "SELECT schema_version FROM quote_commission_schema_state WHERE module_code='commission' LIMIT 1"),
    '客户 ID 优先读取 CRM 原始 ID'
        => str_contains($page, 'let candidates=[c?.crm_customer_id,c?.id]'),
    '客户 ID 兼容 crm_数字 标识'
        => str_contains($page, "/^(?:crm_)?(\\d+)$/i"),
    '佣金报价订单页只保留稳定版单数据源读取'
        => substr_count($page, 'async function loadCommissionOrders()') === 1
            && !str_contains($page, 'const quoteRequest=')
            && !str_contains($page, '正在补充报价')
            && !str_contains($page, '含报价与订单'),
    '订单中心列表读取具备并发复用保护'
        => str_contains($page, 'let ORDERS_LOADING_PROMISE=null;')
            && str_contains($page, 'if(ORDERS_LOADING_PROMISE)return ORDERS_LOADING_PROMISE;'),
];

$failed = [];
foreach ($checks as $label => $passed) {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$passed) $failed[] = $label;
}

if ($failed) {
    file_put_contents('php://stderr', 'commission order path contract failed: ' . implode('；', $failed) . PHP_EOL);
    exit(1);
}

echo "commission order path contract passed.\n";
