<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$page = file_get_contents($root . '/quotation.php');
$api = file_get_contents($root . '/quote_order_api.php');
if ($page === false || $api === false) {
    fwrite(STDERR, "Cannot read order payment files\n");
    exit(1);
}

$apiMarkers = [
    '支付表新增核销金额字段' => "'writeoff_amount'=>'DECIMAL(12,4) DEFAULT 0'",
    '支付表新增核销原因字段' => "'writeoff_reason'=>'VARCHAR(255) DEFAULT \\'\\''",
    '支付表新增核销备注字段' => "'writeoff_note'=>'TEXT NULL'",
    '余额公式包含核销' => '$receivableReduced=$paid+$deduct+$writeoff',
    '汇总返回核销金额' => "'writeoff_amount'=>\$writeoff",
    '列表汇总读取核销' => 'SUM(COALESCE(writeoff_amount,0)) AS writeoff_amount',
    '核销可零实收保存' => "if(\$amount<=0 && \$deduct<=0 && \$writeoff<=0) qo_fail('请填写实际到账、佣金抵扣或核销金额')",
    '核销不能超过未收' => "qo_fail('核销金额超过当前可核销未收款')",
    '保存核销流水字段' => 'writeoff_amount,writeoff_reason,writeoff_note',
    '核销日志动作' => 'payment_writeoff_recorded',
];

foreach ($apiMarkers as $label => $needle) {
    if (strpos($api, $needle) === false) {
        fwrite(STDERR, "Missing writeoff API marker: {$label}\n");
        exit(1);
    }
}

$pageMarkers = [
    '收款类型新增核销' => '<option>核销</option>',
    '核销金额输入框' => 'id="payWriteoffAmount"',
    '核销原因输入框' => 'id="payWriteoffReason"',
    '核销说明文案' => '核销只减少未收款，不计入实际到账，不会修改订单金额、PL 或 CI。',
    '详情新增核销按钮' => 'openWriteoffModal(${Number(o.id)})',
    '核销汇总卡' => '<b>应收核销</b>',
    '流水表核销列' => '<th>应收核销</th>',
    '核销模式函数' => 'function syncPaymentWriteoffMode()',
    '核销保存提交字段' => 'writeoff_amount:writeoff',
    '核销保存提示' => '核销已保存：',
];

foreach ($pageMarkers as $label => $needle) {
    if (strpos($page, $needle) === false) {
        fwrite(STDERR, "Missing writeoff page marker: {$label}\n");
        exit(1);
    }
}

echo "order payment writeoff contract ok\n";
