<?php
$root = dirname(__DIR__);
$quote = file_get_contents($root . '/quotation.php');
$pdf = file_get_contents($root . '/crm_quote_pdf.php');
$excel = file_get_contents($root . '/crm_quote_excel.php');
$orderApi = file_get_contents($root . '/quote_order_api.php');
$preview = file_get_contents($root . '/crm_quote_preview.php');
$quoteApi = file_get_contents($root . '/quote_api.php');

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
        str_contains($orderApi, 'function qo_is_virtual_item') && str_contains($orderApi, 'if(qo_is_virtual_item($it)){ $it[\'remain_qty\']=0'),
    'backend detects legacy shipping charge rows as virtual' =>
        str_contains($orderApi, 'function qo_is_virtual_charge_text') && str_contains($orderApi, 'shipping costs') && str_contains($orderApi, '运费'),
    'order list shipment status excludes virtual charges' =>
        str_contains($orderApi, 'function qo_virtual_item_sql_expr') && str_contains($orderApi, 'shippable_qty_calc') && str_contains($orderApi, 'CASE WHEN {$virtualSql} THEN 0 ELSE qty END'),
    'order shipped recalculation reads item text columns' =>
        str_contains($orderApi, 'SELECT id,qty,product_code,product_name,specification,item_json FROM quote_sales_order_items'),
    'frontend detects legacy shipping charge rows as virtual' =>
        str_contains($quote, 'function isVirtualChargeText') && str_contains($quote, 'shipping costs') && str_contains($quote, '运费'),
    'PDF export supports virtual quantity rule' =>
        str_contains($pdf, 'function quote_pdf_is_virtual_item') && str_contains($pdf, 'quote_pdf_item_qty_for_total'),
    'Excel export uses computed total quantity' =>
        str_contains($excel, 'function qe_is_virtual_item') && str_contains($excel, "qe_cell(7,\$r,\$totalQty,9,true)"),
    'legacy preview supports virtual quantity rule' =>
        str_contains($preview, 'function is_virtual_quote_item') && str_contains($preview, 'quote_item_qty_for_total'),
    'approval API preserves virtual discount as negative item' =>
        str_contains($quoteApi, 'function quote_review_price_value') && str_contains($quoteApi, "if(\$type==='discount') return -abs(\$raw);"),
    'approval quantity excludes non-shippable virtual items' =>
        str_contains($quoteApi, 'function quote_review_qty_for_total') && str_contains($quoteApi, 'quote_review_qty_for_total($it)'),
    'approval schema creates amount adjustment columns on old databases' =>
        str_contains($quoteApi, 'function quote_amount_adjustment_schema') && str_contains($quoteApi, "ensure_col(\$pdo,'quote_orders','subtotal_amount'") && str_contains($quoteApi, 'quote_amount_adjustment_schema($pdo);'),
    'review modal allows negative discount unit price' =>
        str_contains($quote, 'isDiscountVirtual') && str_contains($quote, 'priceMin=isDiscountVirtual'),
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
