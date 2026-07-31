<?php
declare(strict_types=1);

$root = dirname(__DIR__);

$_POST['payload'] = json_encode([
    'order_export' => 1,
    'quote_no' => 'AT-PDF-CJK-TEST',
    'order_no' => 'AT-PDF-CJK-TEST',
    'quote_date' => '2026-07-31',
    'quote_status' => 'Quotation sheet',
    'currency' => 'USD',
    'customer' => ['company' => 'Triangle Power Solutions'],
    'header' => ['company' => 'Gallin Industrial (HK) Limited'],
    'bank' => [],
    'template' => [],
    'items' => [[
        'product' => [
            'code' => 'JB-M-AR-1M',
            'name' => '1M LED Recessed Magnetic Track, without live end and end cap',
            'size' => 'JB-M-AR 嵌入式低压导轨, L1000*W70*H52.2mm, 黑色，不含尾盖',
            'image' => '',
        ],
        'specification' => "1. JB-M-AR 1M LED Recessed Magnetic Track, without live end and end cap\n2. Size: JB-M-AR 嵌入式低压导轨, L1000*W70*H52.2mm, 黑色，不含尾盖",
        'color' => 'Black',
        'qty' => 10,
        'price' => 13.55,
        'amount' => 135.50,
        'moq' => '',
    ]],
    'total' => ['qty' => 10, 'amount' => 135.50],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

ob_start();
include $root . '/crm_quote_pdf.php';
$html = ob_get_clean();

$checks = [
    'PDF keeps English product specification'
        => str_contains($html, 'JB-M-AR 1M LED Recessed Magnetic Track, without live end and end cap'),
    'PDF product Size column does not print Chinese source size'
        => !str_contains($html, '嵌入式低压导轨') && !str_contains($html, '不含尾盖') && !str_contains($html, '黑色'),
    'PDF product specification drops Chinese size line from saved snapshot'
        => !str_contains($html, 'L1000*W70*H52.2mm'),
    'PDF product fields use export sanitizer functions'
        => str_contains((string) file_get_contents($root . '/crm_quote_pdf.php'), 'quote_pdf_has_cjk')
            && str_contains((string) file_get_contents($root . '/crm_quote_pdf.php'), 'quote_pdf_spec_lines_for_export')
            && str_contains((string) file_get_contents($root . '/crm_quote_pdf.php'), 'quote_pdf_public_product_text'),
];

$failed = [];
foreach ($checks as $label => $passed) {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$passed) $failed[] = $label;
}

if ($failed) {
    fwrite(STDERR, 'quote pdf product english contract failed: ' . implode('；', $failed) . PHP_EOL);
    exit(1);
}

echo "quote pdf product english contract passed.\n";
