<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$api = file_get_contents($root . '/quote_order_api.php');
$page = file_get_contents($root . '/quotation.php');
if ($api === false || $page === false) {
    fwrite(STDERR, "Cannot read order completion files\n");
    exit(1);
}

$apiMarkers = [
    '订单生命周期函数存在' => 'function qo_order_lifecycle_status',
    '已出货且已收齐自动完结' => "\$ship==='已出货' && \$pay==='已收齐') return '已完结'",
    '取消作废不被自动覆盖' => "in_array(\$current,['取消','已作废'],true)",
    '收款重算后应用完结规则' => 'qo_apply_order_completion_status($pdo,(int)$orderId,null,$status)',
    '出货重算后应用完结规则' => 'qo_apply_order_completion_status($pdo,(int)$orderId,$status,null)',
    '订单列表显示应用完结规则' => "\$o['status']=qo_order_lifecycle_status(\$o['status']??'',\$o['shipment_status']??'',\$o['payment_status']??'')",
];

foreach ($apiMarkers as $label => $needle) {
    if (strpos($api, $needle) === false) {
        fwrite(STDERR, "Missing auto complete API marker: {$label}\n");
        exit(1);
    }
}

$pageMarkers = [
    '订单筛选包含已完结' => '<option value="已完结">已完结</option>',
    '已完结使用完成样式' => '/已完成|已完结|已出货/.test(o.status||\'\')',
];

foreach ($pageMarkers as $label => $needle) {
    if (strpos($page, $needle) === false) {
        fwrite(STDERR, "Missing auto complete page marker: {$label}\n");
        exit(1);
    }
}

echo "order auto complete contract ok\n";
