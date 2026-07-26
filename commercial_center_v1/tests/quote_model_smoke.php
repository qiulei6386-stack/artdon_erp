<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Artdon\CommercialCenter\Services\QuoteAmountCalculator;
use Artdon\CommercialCenter\Services\QuoteService;

$calculator = new QuoteAmountCalculator();
$calculated = $calculator->calculate([
    ['quantity' => 2, 'unit_price' => 10, 'unit_cost' => 4, 'discount_rate' => 0.1],
], ['shipping_amount' => 2]);
if ((float)$calculated['subtotal_amount'] !== 18.0 || (float)$calculated['total_amount'] !== 20.0) {
    fwrite(STDERR, "Quote amount calculation failed.\n");
    exit(1);
}

if (($argv[1] ?? '') !== '--write-test') {
    echo "PASS: quotation model calculator; use --write-test after migration for persistence checks.\n";
    exit(0);
}

$connection = db();
$required = [
    'cc_quotes', 'cc_quote_versions', 'cc_quote_items', 'cc_quote_item_snapshots',
    'cc_quote_details', 'cc_quote_item_details', 'cc_quote_files', 'cc_quote_item_files',
    'cc_quote_snapshots', 'cc_quote_legacy_links', 'cc_quotation_logs',
];
$placeholders = implode(',', array_fill(0, count($required), '?'));
$check = $connection->prepare(
    "SELECT TABLE_NAME FROM information_schema.TABLES
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ({$placeholders})"
);
$check->execute($required);
if (count($check->fetchAll(PDO::FETCH_COLUMN)) !== count($required)) {
    fwrite(STDERR, "Unified quote migration has not been applied.\n");
    exit(1);
}

$service = new QuoteService();
$quoteIds = [];
$fixtures = [
    'website_order' => [
        'source_order_no' => 'WEB-STEP3-TEST',
        'source_snapshot' => ['order_no' => 'WEB-STEP3-TEST', 'locked' => true],
        'items' => [[
            'description' => 'Website snapshot product',
            'quantity' => 2,
            'unit_price' => 12.5,
            'unit_cost' => 6,
            'source_line_snapshot' => ['line_id' => 'TEST-1', 'quantity' => 2],
        ]],
    ],
    'standard_product' => [
        'items' => [[
            'description' => 'Standard product',
            'model_no' => 'STEP3-STANDARD',
            'quantity' => 3,
            'unit_price' => 20,
            'unit_cost' => 8,
            'configuration_snapshot' => ['driver' => 'TEST'],
        ]],
    ],
    'custom_product' => [
        'items' => [[
            'description' => 'Custom product',
            'product_name' => 'STEP3 CUSTOM',
            'quantity' => 1,
            'unit_price' => 99,
            'unit_cost' => 40,
            'custom_fields' => ['opening' => '75mm'],
        ]],
    ],
];

try {
    foreach ($fixtures as $type => $fixture) {
        $saved = $service->saveDraft(array_merge([
            'quote_type' => $type,
            'currency' => 'USD',
            'customer_snapshot' => ['company' => 'STEP 3 TEST CUSTOMER'],
            'quote_date' => date('Y-m-d'),
            'valid_until' => date('Y-m-d', strtotime('+30 days')),
            'is_test' => 1,
        ], $fixture), 0);
        $quoteId = (int)($saved['id'] ?? 0);
        $quoteIds[] = $quoteId;
        $reopened = $service->open($quoteId);
        if ($quoteId <= 0 || $reopened === null || ($reopened['quote_type'] ?? '') !== $type) {
            throw new RuntimeException("Unable to reopen {$type} quotation.");
        }
        if (count($reopened['items'] ?? []) !== 1 || (int)($reopened['current_version'] ?? 0) !== 1) {
            throw new RuntimeException("Invalid persisted {$type} quotation.");
        }
    }

    $standard = $service->open($quoteIds[1]);
    if ($standard === null) {
        throw new RuntimeException('Standard quotation disappeared before edit test.');
    }
    $edited = $service->saveDraft([
        'id' => $quoteIds[1],
        'quote_type' => 'standard_product',
        'currency' => 'USD',
        'customer_snapshot' => ['company' => 'STEP 3 TEST CUSTOMER'],
        'quote_date' => date('Y-m-d'),
        'items' => [[
            'description' => 'Standard product edited',
            'model_no' => 'STEP3-STANDARD',
            'quantity' => 4,
            'unit_price' => 20,
            'unit_cost' => 8,
        ]],
        'is_test' => 1,
    ], 0);
    if ((int)($edited['current_version'] ?? 0) !== 2 || count($edited['items'] ?? []) !== 1) {
        throw new RuntimeException('Quotation edit did not create a readable second version.');
    }

    $legacyId = (int)$connection->query('SELECT id FROM quote_orders ORDER BY id LIMIT 1')->fetchColumn();
    if ($legacyId > 0) {
        $legacy = $service->openLegacy($legacyId);
        if ($legacy === null || ($legacy['storage'] ?? '') !== 'legacy') {
            throw new RuntimeException('Legacy quotation compatibility read failed.');
        }
    }
    echo "PASS: three quotation types saved, reopened and edited; legacy quotation remained readable.\n";
} finally {
    if ($quoteIds !== []) {
        $ids = implode(',', array_map('intval', $quoteIds));
        $versionIds = $connection->query("SELECT id FROM cc_quote_versions WHERE quote_id IN ({$ids})")
            ->fetchAll(PDO::FETCH_COLUMN);
        $itemIds = [];
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
        $connection->exec("DELETE FROM cc_quote_snapshots WHERE quote_id IN ({$ids})");
        $connection->exec("DELETE FROM cc_quote_files WHERE quote_id IN ({$ids})");
        $connection->exec("DELETE FROM cc_quote_legacy_links WHERE quote_id IN ({$ids})");
        $connection->exec("DELETE FROM cc_quotation_logs WHERE quote_id IN ({$ids})");
        $connection->exec("DELETE FROM cc_quote_versions WHERE quote_id IN ({$ids})");
        $connection->exec("DELETE FROM cc_quote_details WHERE quote_id IN ({$ids})");
        $connection->exec("DELETE FROM cc_quotes WHERE id IN ({$ids}) AND is_test=1");
    }
}
