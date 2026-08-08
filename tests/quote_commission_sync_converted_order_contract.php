<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$api = file_get_contents($root . '/quote_api.php');
if ($api === false) {
    fwrite(STDERR, "Cannot read quote_api.php\n");
    exit(1);
}

$markers = [
    '同步函数存在' => 'function quote_commission_sync_converted_orders',
    '按 source_quote_id 匹配订单' => "source_quote_id=?",
    '按 quote_no 兜底匹配旧订单' => "quote_no=?",
    '已结算不覆盖' => "settle_status",
    '逐项佣金汇总为固定金额' => "\$ruleName='报价产品佣金汇总'",
    '同步写入订单快照' => 'quote_commission_snapshots',
    '保存报价佣金后同步订单' => 'quote_commission_sync_converted_orders($pdo,$q,$after,$actor)',
    '保存报价产品佣金后同步订单' => 'quote_commission_sync_converted_orders($pdo,$q,$c,$actor)',
    '同步结果返回给前端' => "'order_sync'=>\$sync",
];

foreach ($markers as $label => $needle) {
    if (strpos($api, $needle) === false) {
        fwrite(STDERR, "Missing quote commission sync marker: {$label}\n");
        exit(1);
    }
}

echo "quote_commission_sync_converted_order_contract ok\n";
