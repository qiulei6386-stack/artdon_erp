<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$api = file_get_contents($root . '/quote_order_api.php');
$page = file_get_contents($root . '/quotation.php');
if ($api === false || $page === false) {
    throw new RuntimeException('quote shipment sources are not readable');
}

foreach ([
    'function ensureShipmentSplitControls()',
    'addShipmentSplitRow(this)',
    'deleteShipmentSplitRow(this)',
    "['.ship-qty','.ship-ctns','.ship-nw','.ship-gw','.ship-cbm','.ship-note']",
    'function refreshShipmentSplitButtons',
    'ensureShipmentSplitControls();let cartons=collectCartons();',
] as $marker) {
    if (!str_contains($page, $marker)) {
        throw new RuntimeException("shipment split-row UI marker missing: {$marker}");
    }
}

foreach ([
    '$checked=qo_shipment_validate_items($pdo,$orderId,0,$itemsIn);',
    '$qtyByItem=[]',
    '$qtyByItem[$oid]=qo_num($qtyByItem[$oid]??0)+$qty;',
    'foreach($qtyByItem as $oid=>$qty)',
    '$current[(int)($row[\'order_item_id\']??0)][]=$row;',
    '$item[\'shipment_items\']=$current[$itemId]??[];',
    '$expanded[]=$copy;',
] as $marker) {
    if (!str_contains($api, $marker)) {
        throw new RuntimeException("shipment split-row API marker missing: {$marker}");
    }
}

if (str_contains($api, "if(isset(\$clean[\$oid])) qo_fail('同一产品不能重复填写出货行')")) {
    throw new RuntimeException('shipment split rows must not be blocked as duplicate products');
}

echo "Quote shipment split pack rows contract: OK\n";
