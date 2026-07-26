<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Artdon\CommercialCenter\Services\QuotePermissionService;
use Artdon\CommercialCenter\Services\WebsiteQuoteService;

if (($argv[1] ?? '') !== '--write-test') {
    echo "PASS: website quote closure test loaded; use --write-test after migration.\n";
    exit(0);
}

$connection = db();
$service = new WebsiteQuoteService();
$actor = [
    'id' => 0,
    'username' => 'step6-test',
    'display_name' => 'STEP 6 TEST',
    'is_test_actor' => true,
    'test_permissions' => QuotePermissionService::ACTIONS,
];
$quoteIds = [];
$externalNumbers = [];

try {
    $bootstrap = $service->bootstrap(0);
    $customer = $bootstrap['customers'][0] ?? null;
    $product = $bootstrap['configuration']['products'][0] ?? null;
    if (!is_array($customer) || !is_array($product)) {
        throw new RuntimeException('Real customer or website product source is empty.');
    }
    $orderNo = 'SG-STEP6-' . date('YmdHis') . '-' . random_int(100, 999);
    $externalNumbers[] = $orderNo;
    $payload = [
        'channel' => 'singapore_web',
        'external_order_no' => $orderNo,
        'idempotency_key' => 'step6-' . hash('sha256', $orderNo),
        'customer_id' => (int)$customer['id'],
        'currency' => 'USD',
        'shipping_amount' => 18,
        'shipping' => ['method' => 'DHL', 'country' => $customer['country']],
        'payment_terms' => '30% deposit',
        'trade_terms' => 'FOB SHENZHEN',
        'customer_note' => 'Website original requirement',
        'attachments' => [['name' => 'requirement.pdf', 'hash' => hash('sha256', 'step6')]],
        'placed_at' => date('Y-m-d H:i:s'),
        'is_test' => 1,
        'items' => [[
            'line_no' => 1,
            'legacy_product_id' => (int)$product['id'],
            'sku_code' => 'WEB-' . $product['model_no'],
            'model_no' => $product['model_no'],
            'product_name' => $product['product_name'],
            'configuration' => ['cct' => '3000K', 'beam' => '24D'],
            'quantity' => 2,
            'website_unit_price' => 25.5,
            'lead_time' => '12 days',
            'customer_requirement' => 'CE required',
        ]],
    ];
    $imported = $service->import($payload, $actor);
    $quoteId = (int)$imported['quote']['id'];
    $quoteIds[] = $quoteId;
    if ($imported['duplicate'] || ($imported['quote']['status'] ?? '') !== 'pending_approval') {
        throw new RuntimeException('Website order was not imported into pending approval.');
    }
    $duplicate = $service->import($payload, $actor);
    if (!$duplicate['duplicate'] || (int)$duplicate['quote']['id'] !== $quoteId) {
        throw new RuntimeException('Website order idempotency failed.');
    }
    $item = $imported['quote']['items'][0];
    $blocked = false;
    try {
        $service->review($quoteId, ['items' => [['quantity' => 3]]], $actor, 'Unauthorized locked edit');
    } catch (RuntimeException) {
        $blocked = true;
    }
    if (!$blocked) {
        throw new RuntimeException('Locked website quantity changed without unlock approval.');
    }
    $requestId = $service->requestUnlock(
        $quoteId,
        (int)$item['id'],
        'quantity',
        3,
        'Customer requested quantity correction',
        $actor
    );
    $service->reviewUnlock($requestId, true, 'Verified against customer email', $actor);
    $reviewed = $service->review($quoteId, [
        'items' => [[
            'quantity' => 3,
            'unit_price' => 24.5,
            'discount_rate' => 0.02,
            'lead_time' => '15 days',
            'internal_note' => 'Approved adjustment',
        ]],
        'shipping_amount' => 20,
        'payment_terms' => '50% deposit',
        'trade_terms' => 'FOB SHENZHEN',
        'internal_note' => 'Website review complete',
    ], $actor, 'Approved price and quantity adjustment');
    if ((float)$reviewed['items'][0]['quantity'] !== 3.0 || ($reviewed['status'] ?? '') !== 'pending_approval') {
        throw new RuntimeException('Approved website review adjustment was not saved.');
    }
    $approved = $service->approve($quoteId, $actor, 'Website requirements verified');
    if (($approved['status'] ?? '') !== 'approved') {
        throw new RuntimeException('Website quotation approval failed.');
    }

    $proxyNo = 'PROXY-STEP6-' . date('YmdHis') . '-' . random_int(100, 999);
    $externalNumbers[] = $proxyNo;
    $proxyPayload = $payload;
    $proxyPayload['external_order_no'] = $proxyNo;
    $proxyPayload['idempotency_key'] = 'proxy-' . hash('sha256', $proxyNo);
    $proxy = $service->import($proxyPayload, $actor, 'sales_proxy');
    $proxyId = (int)$proxy['quote']['id'];
    $quoteIds[] = $proxyId;
    if (($proxy['quote']['source_type'] ?? '') !== 'sales_proxy') {
        throw new RuntimeException('Sales proxy website source was not preserved.');
    }
    $rejected = $service->reject($proxyId, $actor, 'Website SKU is not available for requested market.');
    if (($rejected['status'] ?? '') !== 'rejected') {
        throw new RuntimeException('Website quotation rejection failed.');
    }

    $snapshot = $connection->prepare(
        'SELECT payload_json,payload_hash,customer_snapshot,contact_snapshot,items_snapshot,shipping_snapshot,
                attachment_snapshot,customer_note,placed_at
         FROM cc_website_order_snapshots WHERE quote_id=?'
    );
    $snapshot->execute([$quoteId]);
    $source = $snapshot->fetch(PDO::FETCH_ASSOC);
    if (!is_array($source) || array_filter($source, static fn($value): bool => $value === null || $value === '') !== []) {
        throw new RuntimeException('Website source snapshot is incomplete.');
    }
    echo "PASS: website import idempotency, full snapshot, locks, approved unlock, review, approve, reject and sales proxy verified.\n";
} finally {
    if ($quoteIds !== []) {
        $ids = implode(',', array_map('intval', $quoteIds));
        $versionIds = $connection->query("SELECT id FROM cc_quote_versions WHERE quote_id IN ({$ids})")->fetchAll(PDO::FETCH_COLUMN);
        if ($versionIds !== []) {
            $versions = implode(',', array_map('intval', $versionIds));
            $itemIds = $connection->query("SELECT id FROM cc_quote_items WHERE quote_version_id IN ({$versions})")->fetchAll(PDO::FETCH_COLUMN);
            if ($itemIds !== []) {
                $items = implode(',', array_map('intval', $itemIds));
                $connection->exec("DELETE FROM cc_quote_item_files WHERE quote_item_id IN ({$items})");
                $connection->exec("DELETE FROM cc_quote_item_snapshots WHERE quote_item_id IN ({$items})");
                $connection->exec("DELETE FROM cc_quote_item_details WHERE quote_item_id IN ({$items})");
            }
            $connection->exec("DELETE FROM cc_quote_items WHERE quote_version_id IN ({$versions})");
        }
        $connection->exec("DELETE FROM cc_quote_unlock_requests WHERE quote_id IN ({$ids})");
        $connection->exec("DELETE FROM cc_website_order_snapshots WHERE quote_id IN ({$ids})");
        $connection->exec("DELETE FROM cc_quote_approvals WHERE quote_id IN ({$ids})");
        $connection->exec("DELETE FROM cc_quote_state_history WHERE quote_id IN ({$ids})");
        $connection->exec("DELETE FROM cc_quote_audit_logs WHERE quote_id IN ({$ids})");
        $connection->exec("DELETE FROM cc_quote_snapshots WHERE quote_id IN ({$ids})");
        $connection->exec("DELETE FROM cc_quote_files WHERE quote_id IN ({$ids})");
        $connection->exec("DELETE FROM cc_quote_legacy_links WHERE quote_id IN ({$ids})");
        $connection->exec("DELETE FROM cc_quotation_logs WHERE quote_id IN ({$ids})");
        $connection->exec("DELETE FROM cc_quote_versions WHERE quote_id IN ({$ids})");
        $connection->exec("DELETE FROM cc_quote_details WHERE quote_id IN ({$ids})");
        $connection->exec("DELETE FROM cc_quotes WHERE id IN ({$ids}) AND is_test=1");
    }
}
