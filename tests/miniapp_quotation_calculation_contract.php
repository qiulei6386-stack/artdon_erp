<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/quote_money.php';
require_once $root . '/miniapp/api/quotation_calculation.php';

$breakdown = [
    'shell_cost_rmb' => 100.0,
    'needs_review' => false,
    'review_notes' => [],
];

function mq_test_config(array $overrides = []): array {
    return array_merge([
        'mode' => 'shell',
        'level' => ['id' => 1, 'name' => '标准', 'multiplier' => 2.0],
        'currency' => 'RMB',
        'exchange_rate' => 7.0,
        'qty' => 10,
        'review_confirmed' => false,
    ], $overrides);
}

function mq_assert_close(float $expected, float $actual, string $label, float $epsilon = 0.0001): void {
    if (abs($expected - $actual) > $epsilon) {
        throw new RuntimeException($label . ' 期望 ' . $expected . ' 实际 ' . $actual);
    }
}

$base = mq_calculate($breakdown, mq_test_config());
mq_assert_close(200.0, (float)$base['unit_price'], '空壳对外单价');
mq_assert_close(2000.0, (float)$base['amount'], '空壳对外金额');
mq_assert_close(200.0, (float)$base['list_unit_price'], '默认清单单价');
mq_assert_close(0.0, (float)$base['commission_amount'], '未启用佣金');

$custom = mq_calculate($breakdown, mq_test_config([
    'level' => ['id' => null, 'name' => '自定义倍率', 'multiplier' => 2.5],
]));
mq_assert_close(250.0, (float)$custom['unit_price'], '自定义倍率单价');
mq_assert_close(2500.0, (float)$custom['amount'], '自定义倍率金额');

$percent = mq_calculate($breakdown, mq_test_config([
    'commission' => ['enabled' => true, 'mode' => 'percent', 'value' => 10],
]));
mq_assert_close(200.0, (float)$percent['unit_price'], '百分比佣金不改变对外单价');
mq_assert_close(2000.0, (float)$percent['amount'], '百分比佣金不改变对外金额');
mq_assert_close(20.0, (float)$percent['commission_unit_price'], '百分比佣金单价');
mq_assert_close(200.0, (float)$percent['commission_amount'], '百分比佣金总额');
mq_assert_close(180.0, (float)$percent['net_unit_price'], '百分比净单价');
mq_assert_close(1800.0, (float)$percent['net_amount'], '百分比净额');

$fixed = mq_calculate($breakdown, mq_test_config([
    'commission' => ['enabled' => true, 'mode' => 'fixed', 'value' => 50],
]));
mq_assert_close(50.0, (float)$fixed['commission_amount'], '固定佣金总额');
mq_assert_close(1950.0, (float)$fixed['net_amount'], '固定佣金净额');

$perLamp = mq_calculate($breakdown, mq_test_config([
    'commission' => ['enabled' => true, 'mode' => 'per_lamp', 'value' => 3],
]));
mq_assert_close(3.0, (float)$perLamp['commission_unit_price'], '每件佣金单价');
mq_assert_close(30.0, (float)$perLamp['commission_amount'], '每件佣金总额');
mq_assert_close(1970.0, (float)$perLamp['net_amount'], '每件佣金净额');

$list = mq_calculate($breakdown, mq_test_config([
    'commission' => ['enabled' => true, 'mode' => 'percent', 'value' => 5, 'order_list_amount' => 2200],
]));
mq_assert_close(220.0, (float)$list['list_unit_price'], '清单单价');
mq_assert_close(2200.0, (float)$list['list_amount'], '清单金额');
mq_assert_close(2000.0, (float)$list['amount'], '清单金额不改变对外金额');

$api = file_get_contents($root . '/miniapp/api/quotation.php');
$calc = file_get_contents($root . '/miniapp/api/quotation_calculation.php');
foreach ([
    [$api, 'function mq_api_price_level', '自定义报价等级'],
    [$api, 'custom_multiplier', '自定义倍率入参'],
    [$api, 'mq_api_commission_input', '佣金入参解析'],
    [$calc, 'function mq_commission_money', '佣金拆分'],
    [$calc, 'list_unit_price', '清单单价字段'],
    [$calc, 'net_amount', '净额字段'],
    [$calc, 'per_lamp', '每件佣金模式'],
] as [$src, $needle, $label]) {
    if (strpos($src, $needle) === false) {
        throw new RuntimeException('契约失败：' . $label);
    }
}

echo "miniapp_quotation_calculation_contract ok\n";
