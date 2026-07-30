<?php
$root = getenv('COMMISSION_CONTRACT_ROOT') ?: dirname(__DIR__);
$page = (string) file_get_contents($root . '/quotation.php');
$orderApi = (string) file_get_contents($root . '/quote_order_api.php');

$checks = [
    '选择客户对象时自动带入订单客户名称'
        => str_contains($page, "if(f==='target_type'&&v==='customer'&&ctx)")
            && str_contains($page, "ctx.order.customer_name"),
    '编辑产品佣金配置时自动启用参与佣金'
        => str_contains($page, "d.is_commission_enabled=1"),
    '已有佣金字段可识别为已配置'
        => str_contains($page, "['target_name','target_type','commission_mode','commission_value'].some"),
    '产品每件固定预计佣金按数量即时计算'
        => str_contains($page, "if(mode==='fixed_unit')return Number(it.qty||0)*v"),
    '后端产品每件固定佣金按数量复算'
        => str_contains($orderApi, "if(\$mode==='fixed_unit')return round(\$qty*\$value,2)"),
];

$failed = [];
foreach ($checks as $label => $passed) {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$passed) $failed[] = $label;
}
if ($failed) {
    file_put_contents('php://stderr', 'commission customer estimate contract failed: ' . implode('；', $failed) . PHP_EOL);
    exit(1);
}
echo "commission customer estimate contract passed.\n";
