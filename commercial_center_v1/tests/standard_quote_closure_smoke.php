<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Artdon\CommercialCenter\Services\QuotePermissionService;
use Artdon\CommercialCenter\Services\StandardQuoteService;

if (($argv[1] ?? '') !== '--write-test') {
    echo "PASS: standard quote closure test loaded; use --write-test for real-source persistence checks.\n";
    exit(0);
}

$connection = db();
$service = new StandardQuoteService();
$actor = [
    'id' => 0,
    'username' => 'step5-test',
    'display_name' => 'STEP 5 TEST',
    'is_test_actor' => true,
    'test_permissions' => QuotePermissionService::ACTIONS,
];
$quoteIds = [];

try {
    $bootstrap = $service->bootstrap(0);
    $customer = $bootstrap['customers'][0] ?? null;
    $product = $bootstrap['configuration']['products'][0] ?? null;
    if (!is_array($customer) || !is_array($product)) {
        throw new RuntimeException('Real CRM customer or product source is empty.');
    }
    $values = [];
    foreach ($bootstrap['configuration']['groups'] as $group) {
        $option = null;
        foreach ($group['options'] as $candidate) {
            if ((int)$candidate['is_default'] === 1) {
                $option = $candidate;
                break;
            }
        }
        $option ??= $group['options'][0] ?? null;
        if (is_array($option)) {
            $values[$group['group_code']] = $option['option_code'];
        }
    }
    $prepared = $service->prepareItem([
        'configuration_request' => [
            'product_key' => 'standard:' . $product['id'],
            'mode' => 'professional',
            'values' => $values,
        ],
        'quantity' => 2,
        'discount_rate' => 0.05,
        'customer_note' => 'STEP 5 real-source verification',
    ], 0, (int)$customer['id']);
    if (($prepared['configuration_snapshot']['passport_hash'] ?? '') === '') {
        throw new RuntimeException('Configuration passport was not generated.');
    }
    $saved = $service->save([
        'customer_id' => (int)$customer['id'],
        'currency' => $prepared['pricing']['currency'] ?: 'USD',
        'quote_date' => date('Y-m-d'),
        'valid_until' => date('Y-m-d', strtotime('+30 days')),
        'payment_terms' => '30% deposit, balance before shipment',
        'trade_terms' => 'FOB SHENZHEN',
        'shipping_amount' => 25,
        'tax_amount' => 0,
        'customer_note' => 'STEP 5 closure acceptance',
        'internal_note' => 'Uses real CRM, product, configuration, price and BOM sources.',
        'is_test' => 1,
        'items' => [[
            'configuration_request' => [
                'product_key' => 'standard:' . $product['id'],
                'mode' => 'professional',
                'values' => $values,
            ],
            'quantity' => 2,
            'discount_rate' => 0.05,
            'customer_note' => 'STEP 5 real-source verification',
        ]],
    ], $actor);
    $quoteId = (int)$saved['id'];
    $quoteIds[] = $quoteId;
    $opened = $service->open($quoteId, $actor);
    if ($opened === null || count($opened['items'] ?? []) !== 1) {
        throw new RuntimeException('Saved standard quotation could not be reopened.');
    }
    $snapshot = $opened['items'][0]['configuration_snapshot'] ?? [];
    if (($snapshot['passport_hash'] ?? '') === '' || ($snapshot['request']['values'] ?? []) !== $values) {
        throw new RuntimeException('Product configuration was lost after reopen.');
    }
    $line = $opened['items'][0];
    $expected = round((float)$line['quantity'] * (float)$line['unit_price'] * (1 - (float)$line['discount_rate']), 4);
    if (abs($expected - (float)$line['line_amount']) > 0.0001) {
        throw new RuntimeException('Line price calculation is incorrect.');
    }
    if ((float)$opened['total_amount'] <= 0 || !isset($opened['gross_profit'], $opened['gross_margin'])) {
        throw new RuntimeException('Quotation total or margin was not calculated.');
    }
    $submitted = $service->submit($quoteId, $actor, 'STEP 5 standard quotation acceptance');
    if (($submitted['status'] ?? '') !== 'pending_approval') {
        throw new RuntimeException('Standard quotation was not submitted for approval.');
    }
    $audit = $connection->prepare('SELECT COUNT(*) FROM cc_quote_audit_logs WHERE quote_id=?');
    $audit->execute([$quoteId]);
    $states = $connection->prepare('SELECT COUNT(*) FROM cc_quote_state_history WHERE quote_id=?');
    $states->execute([$quoteId]);
    if ((int)$audit->fetchColumn() < 2 || (int)$states->fetchColumn() < 1) {
        throw new RuntimeException('Standard quotation audit trail is incomplete.');
    }
    echo "PASS: real CRM customer, product, configuration, price/BOM, margin, save, reopen and submit flow verified.\n";
} finally {
    if ($quoteIds !== []) {
        $ids = implode(',', array_map('intval', $quoteIds));
        $versionIds = $connection->query("SELECT id FROM cc_quote_versions WHERE quote_id IN ({$ids})")
            ->fetchAll(PDO::FETCH_COLUMN);
        if ($versionIds !== []) {
            $versions = implode(',', array_map('intval', $versionIds));
            $itemIds = $connection->query("SELECT id FROM cc_quote_items WHERE quote_version_id IN ({$versions})")
                ->fetchAll(PDO::FETCH_COLUMN);
            if ($itemIds !== []) {
                $items = implode(',', array_map('intval', $itemIds));
                $connection->exec("DELETE FROM cc_quote_item_files WHERE quote_item_id IN ({$items})");
                $connection->exec("DELETE FROM cc_quote_item_snapshots WHERE quote_item_id IN ({$items})");
                $connection->exec("DELETE FROM cc_quote_item_details WHERE quote_item_id IN ({$items})");
            }
            $connection->exec("DELETE FROM cc_quote_items WHERE quote_version_id IN ({$versions})");
        }
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
