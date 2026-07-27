<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$menu = require $root . '/config/menu.php';
$quoteItems = $menu['产品报价'] ?? [];
$labels = array_column($quoteItems, 'label');
$expected = ['报价单中心','报价产品库','报价模板','价格策略','阶梯价格','报价审核','新加坡发布'];
if ($labels !== $expected) {
    fwrite(STDERR, "FAIL: product quotation menu differs\n");
    exit(1);
}
if (count($quoteItems) !== 7) {
    fwrite(STDERR, "FAIL: product quotation menu must contain seven items\n");
    exit(1);
}
$index = (string)file_get_contents($root . '/index.php');
foreach ([
    "'standard_quote' => ['page'=>'quote_center','quote_mode'=>'standard']",
    "'quick_quote' => ['page'=>'quote_center','quote_mode'=>'standard','quick'=>'1']",
    "'quote_history' => ['page'=>'quote_center']",
    "'product_config' => ['page'=>'compatibility_rules','legacy_notice'=>'product_config']",
] as $mapping) {
    if (!str_contains($index, $mapping)) {
        fwrite(STDERR, "FAIL: missing legacy mapping {$mapping}\n");
        exit(1);
    }
}
foreach (['views/quote_center.php','views/quote_stock.php','views/quote_website.php','views/quote_standard.php','views/quote_custom.php','views/quote_approval.php','views/singapore_channel.php','views/compatibility_rules.php','assets/js/quote_center.js'] as $file) {
    if (!is_file($root . '/' . $file)) {
        fwrite(STDERR, "FAIL: missing {$file}\n");
        exit(1);
    }
}
$quoteView = (string)file_get_contents($root . '/views/quote_center.php');
$quoteHub = (string)file_get_contents($root . '/views/quote_custom.php');
foreach (["['website','stock','standard','custom']", 'data-new-quote', '库存品报价', '标准品报价', '定制品报价'] as $needle) {
    if (!str_contains($quoteView . $quoteHub, $needle)) {
        fwrite(STDERR, "FAIL: quote center routing or hub missing {$needle}\n");
        exit(1);
    }
}
if (!str_contains($quoteView, "require __DIR__ . '/quote_custom.php';")) {
    fwrite(STDERR, "FAIL: default quote center does not use full-screen dashboard\n");
    exit(1);
}
foreach ([
    'views/quote_website.php'=>['网站传入','网站订单号','内部审核备注','风险提醒'],
    'views/quote_standard.php'=>['标准品报价单（半自由）','报价明细','产品适配规则','内部流程'],
    'views/quote_custom.php'=>['报价单中心','库存品报价','标准品报价','定制品报价','新加坡渠道','真实数据'],
] as $file=>$needles) {
    $source = (string)file_get_contents($root . '/' . $file);
    foreach ($needles as $needle) {
        if (!str_contains($source, $needle)) {
            fwrite(STDERR, "FAIL: {$file} missing {$needle}\n");
            exit(1);
        }
    }
}
$standardView = (string)file_get_contents($root . '/views/quote_standard.php');
if (str_contains($standardView, 'array_slice($catalogRows')) {
    fwrite(STDERR, "FAIL: new standard quote must not preload catalog products\n");
    exit(1);
}
if (!str_contains($standardView, '$rows = [];')) {
    fwrite(STDERR, "FAIL: new standard quote must start with empty line items\n");
    exit(1);
}
$standardScript = (string)file_get_contents($root . '/assets/js/quote_center.js');
foreach (['data-summary-panel', 'data-config-panel', 'showConfiguration', 'hideConfiguration'] as $needle) {
    if (!str_contains($standardView . $standardScript, $needle)) {
        fwrite(STDERR, "FAIL: standard quote side configuration switch missing {$needle}\n");
        exit(1);
    }
}
echo "PASS: product menu, legacy mappings, real quote center, three product modes and Singapore channel entry verified.\n";
