<?php
$root = dirname(__DIR__);
$quote = file_get_contents($root . '/quotation.php');
$pdf = file_get_contents($root . '/crm_quote_pdf.php');
$excel = file_get_contents($root . '/crm_quote_excel.php');
$orderApi = file_get_contents($root . '/quote_order_api.php');
$preview = file_get_contents($root . '/crm_quote_preview.php');

$checks = [
    'quotation UI exposes virtual charge button' =>
        str_contains($quote, 'openVirtualItemModal()') && str_contains($quote, '添加费用项'),
    'virtual line is saved as non-shippable quotation item' =>
        str_contains($quote, "item_type:'virtual'") && str_contains($quote, 'shippable:false'),
    'quotation totals exclude virtual quantity by default' =>
        str_contains($quote, 'function quoteItemQtyForTotal') && str_contains($quote, 'qty=items.reduce((s,it)=>s+quoteItemQtyForTotal(it),0)'),
    'PI conversion preserves virtual flags' =>
        str_contains($quote, 'function piOrderItemFromQuote') && str_contains($quote, "item_type:virtual?'virtual'"),
    'order detail does not allow packaging virtual items' =>
        str_contains($quote, "费用项</span>':`<button class=\"gray\"") && str_contains($quote, "不出货</span>':fmtNum(remain)"),
    'order API skips virtual items for shipment' =>
        str_contains($orderApi, 'function qo_is_virtual_item') && str_contains($orderApi, 'if(!$it || qo_is_virtual_item($it)) continue'),
    'PDF export supports virtual quantity rule' =>
        str_contains($pdf, 'function quote_pdf_is_virtual_item') && str_contains($pdf, 'quote_pdf_item_qty_for_total'),
    'Excel export uses computed total quantity' =>
        str_contains($excel, 'function qe_is_virtual_item') && str_contains($excel, "qe_cell(7,\$r,\$totalQty,9,true)"),
    'legacy preview supports virtual quantity rule' =>
        str_contains($preview, 'function is_virtual_quote_item') && str_contains($preview, 'quote_item_qty_for_total'),
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failed[] = $label;
}
if ($failed) {
    fwrite(STDERR, 'quote virtual items contract failed: ' . implode('; ', $failed) . PHP_EOL);
    exit(1);
}
echo "quote virtual items contract passed.\n";
