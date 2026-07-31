<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/quotation.php');

$checks = [
    'fallback options' => 'function commissionFallbackOptions()',
    'fallback before order load' => 'ensureCommissionFallbackOptions();refreshCommissionOrderOptions();loadCommissionOptionsInBackground();',
    'background options loader' => 'function loadCommissionOptionsInBackground()',
    'load time display' => '用时 ${((performance.now()-startedAt)/1000).toFixed(1)} 秒',
];

$failed = [];
foreach ($checks as $label => $marker) {
    if (!str_contains($page, $marker)) {
        $failed[] = $label;
    }
}

$loadStart = strpos($page, 'async function loadCommissionOrders()');
$loadEnd = $loadStart === false ? false : strpos($page, 'async function resolveCommissionOrderDrafts()', $loadStart);
$loadBody = ($loadStart !== false && $loadEnd !== false) ? substr($page, $loadStart, $loadEnd - $loadStart) : '';
if ($loadBody === '') {
    $failed[] = 'loadCommissionOrders body';
}
if (str_contains($loadBody, 'Promise.all')) {
    $failed[] = 'order list still waits for commission options with Promise.all';
}
if (str_contains($loadBody, "api('commission_options_list')")) {
    $failed[] = 'order list directly blocks on commission_options_list';
}

if ($failed) {
    file_put_contents('php://stderr', 'commission order loading contract failed: ' . implode('；', $failed) . PHP_EOL);
    exit(1);
}

echo "commission order loading contract passed\n";
