<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$page = file_get_contents($root . '/quotation.php');
if ($page === false) {
    throw new RuntimeException('quotation page is not readable');
}

$start = strpos($page, 'function shipmentPackLabel(p)');
$end = strpos($page, 'function addCartonRow(data={})', $start === false ? 0 : $start);
if ($start === false || $end === false) {
    throw new RuntimeException('shipment packing renderer is not readable');
}
$shippingUi = substr($page, $start, $end - $start);

foreach ([
    'function shipmentExactCartons(qty,pcs)',
    'Math.abs(raw-rounded)<0.000001?rounded',
    'function shipmentPartialCartonHint(qty,pcs)',
    '请手动确认箱数/重量或录入拼箱明细',
    'shipmentExactCartons(remain,pcs)',
    'shipmentPartialCartonHint(qty,pcs)',
] as $marker) {
    if (!str_contains($shippingUi, $marker)) {
        throw new RuntimeException("partial carton shipment marker missing: {$marker}");
    }
}

if (str_contains($shippingUi, 'Math.ceil(remain/num(pcs))')) {
    throw new RuntimeException('new shipment rows must not ceil partial cartons automatically');
}
if (str_contains($shippingUi, 'Math.ceil(qty/pcs)')) {
    throw new RuntimeException('shipment row recalculation must not ceil partial cartons automatically');
}

echo "Quote shipment partial carton contract: OK\n";
