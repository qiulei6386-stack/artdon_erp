<?php
declare(strict_types=1);

$root = getenv('COMMISSION_CONTRACT_ROOT') ?: dirname(__DIR__);
$api = (string) file_get_contents($root . '/quote_api.php');
$page = (string) file_get_contents($root . '/quotation.php');

$checks = [
    '报价页选择客户时传入客户代码'
        => str_contains($page, "customer_code:c.code||''"),
    '客户代码兼容早期 customer_id 写法'
        => str_contains($api, "\$parts[]='customer_id=?';\$args[]=\$code;"),
    '客户代码匹配佣金对象 target_name'
        => str_contains($api, "\$parts[]='target_name=?';\$args[]=\$code;"),
    '客户代码用于历史佣金查询'
        => str_contains($api, "\$histWhere[]='(o.customer_id=? OR o.customer_json LIKE ?)'")
            && str_contains($api, "\$histArgs[]='%'.\$code.'%';"),
    '客户有规则时启用客户选择弹窗触发条件'
        => str_contains($api, "customer_has_commission_rule"),
];

$failed = [];
foreach ($checks as $label => $passed) {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$passed) $failed[] = $label;
}

if ($failed) {
    fwrite(STDERR, 'commission customer code rule contract failed: ' . implode('；', $failed) . PHP_EOL);
    exit(1);
}

echo "commission customer code rule contract passed.\n";
