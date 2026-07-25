<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$menu = require $root . '/config/menu.php';
$quoteItems = $menu['产品报价'] ?? [];
$labels = array_column($quoteItems, 'label');
$expected = ['报价单中心','报价产品库','报价模板','价格策略','阶梯价格','报价审核'];
if ($labels !== $expected) {
    fwrite(STDERR, "FAIL: product quotation menu differs\n");
    exit(1);
}
if (count($quoteItems) !== 6) {
    fwrite(STDERR, "FAIL: product quotation menu must contain six items\n");
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
foreach (['views/quote_center.php','views/quote_approval.php','views/compatibility_rules.php','assets/js/quote_center.js'] as $file) {
    if (!is_file($root . '/' . $file)) {
        fwrite(STDERR, "FAIL: missing {$file}\n");
        exit(1);
    }
}
$quoteView = (string)file_get_contents($root . '/views/quote_center.php');
foreach (['网站订单报价单','标准品报价单','定制品报价单','data-new-quote','data-quote-lines','data-config-modal'] as $needle) {
    if (!str_contains($quoteView, $needle)) {
        fwrite(STDERR, "FAIL: quote center missing {$needle}\n");
        exit(1);
    }
}
echo "PASS: six-item menu, legacy mappings, shared quote center and three quote modes verified.\n";
