<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$task = (string)file_get_contents($root . '/crm_task_center.php');
$order = (string)file_get_contents($root . '/quote_order_api.php');

function require_contract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

require_contract(str_contains($task, 'function crm_task_quote_shipment_rollup_sql(): string'), 'missing order-level shipment rollup');
require_contract(str_contains($task, 'FROM quote_shipment_items si'), 'shipment rollup must read shipment item order IDs');
require_contract(str_contains($task, 'FROM quote_shipment_orders so'), 'shipment rollup must read combined-order links');
require_contract(str_contains($task, 'GROUP BY shipment_id,order_id'), 'shipment quantities must aggregate per order inside each batch');
require_contract(str_contains($task, 'LEFT JOIN {$shipmentRollup} sr ON sr.order_id=o.id'), 'quote flow must join order-level shipment rollup');
require_contract(str_contains($task, 'COALESCE(MAX(sr.shipped_qty),0) AS shipped_qty'), 'quote flow must expose rolled-up shipped quantity');
require_contract(str_contains($task, 'COALESCE(MAX(sr.pl_count),0) AS pl_count'), 'quote flow must expose combined-batch PL state');
require_contract(str_contains($task, 'COALESCE(MAX(sr.ci_count),0) AS ci_count'), 'quote flow must expose combined-batch CI state');
require_contract(!str_contains($task, 'LEFT JOIN quote_shipments s ON s.order_id=o.id'), 'quote flow must not depend only on the batch primary order');

require_contract(str_contains($order, "&& empty(\$shipment['pl_generated_at'])"), 'generated PL batch must not remain shipment-editable');
require_contract(str_contains($order, "&& empty(\$shipment['ci_generated_at'])"), 'generated CI batch must not remain shipment-editable');
require_contract(substr_count($order, "status=CASE WHEN COALESCE(status,'') IN ('','草稿') THEN '已生效' ELSE status END") >= 2, 'PL and CI generation must activate draft shipment');
require_contract(str_contains($order, "if(\$sid){ qo_mark_document_active(\$pdo,\$sid,\$type); qo_push_document_notification"), 'document generated action must use active-state helper');

echo "crm combined shipment flow contract ok\n";
