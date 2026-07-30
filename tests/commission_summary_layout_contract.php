<?php
declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../quotation.php');
if ($source === false) {
    fwrite(STDERR, "cannot read quotation.php\n");
    exit(1);
}

$checks = [
    'summary table scoped styles' => '#page-commission-summary table',
    'right aligned financial values' => 'commission-summary-number',
    'currency badge' => 'commission-summary-currency',
    'subtle order link' => 'commission-summary-order-link',
    'settlement badge' => 'commission-summary-status',
    'amounts do not repeat currency' => "function commissionSummaryAmount(value,tone='')",
    'empty state styling' => 'commission-summary-empty',
];

foreach ($checks as $label => $needle) {
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, "missing {$label}: {$needle}\n");
        exit(1);
    }
}

if (strpos($source, 'commissionSummaryMoney(t.currency,t.amount)') !== false) {
    fwrite(STDERR, "order summary still repeats currency inside amount cells\n");
    exit(1);
}

echo "commission summary layout contract passed\n";
