<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Artdon\CommercialCenter\Services\ConfigurationEngineService;
use Artdon\CommercialCenter\Services\QuotePermissionService;
use Artdon\CommercialCenter\Services\QuoteWorkflowService;
use Artdon\CommercialCenter\Services\SingaporeChannelService;
use Artdon\CommercialCenter\Services\StockQuoteService;

if (($argv[1] ?? '') !== '--write-test') {
    echo "PASS: stock/channel bridge test loaded; use --write-test for persistence checks.\n";
    exit(0);
}

$db = db();
$actor = [
    'id' => 0,
    'username' => 'stock-channel-test',
    'display_name' => 'STOCK CHANNEL TEST',
    'is_test_actor' => true,
    'test_permissions' => QuotePermissionService::ACTIONS,
];
$skuId = 0;
$packageId = 0;
$quoteId = 0;
$outboxIds = [];

try {
    $configuration = (new ConfigurationEngineService())->catalog(0);
    $product = $configuration['products'][0] ?? null;
    $customer = (new StockQuoteService())->bootstrap(0)['customers'][0] ?? null;
    if (!is_array($product) || !is_array($customer)) {
        throw new RuntimeException('Real product or CRM customer source is empty.');
    }
    $values = [];
    foreach ($configuration['groups'] as $group) {
        $option = null;
        foreach ($group['options'] as $candidate) {
            if ((int)$candidate['is_default'] === 1) {
                $option = $candidate;
                break;
            }
        }
        $option ??= $group['options'][0] ?? null;
        if (is_array($option)) $values[$group['group_code']] = $option['option_code'];
    }
    $now = date('Y-m-d H:i:s');
    $skuCode = 'TEST-SG-' . date('YmdHis') . '-' . random_int(100, 999);
    $hex = bin2hex(random_bytes(16));
    $uuid = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4' . substr($hex, 13, 3)
        . '-a' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
    $insert = $db->prepare(
        "INSERT INTO cc_inventory_skus
         (permanent_id,legacy_product_id,sku_code,product_type,configuration_snapshot,actual_stock,reserved_stock,
          safety_stock,sellable_stock,in_transit_stock,publishable,status,is_test,created_at,updated_at)
         VALUES (?,?,?,'stock',?,20,0,2,18,0,0,'active',1,?,?)"
    );
    $insert->execute([
        $uuid, (int)$product['id'], $skuCode,
        json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now, $now,
    ]);
    $skuId = (int)$db->lastInsertId();

    $channel = new SingaporeChannelService($db);
    $package = $channel->savePackage([
        'inventory_sku_id' => $skuId,
        'package_code' => 'PKG-' . $skuCode,
        'public_title' => 'Test publishable light',
        'english_name' => 'Test Publishable Light',
        'public_parameters' => ['test' => true],
        'public_price' => 88.5,
        'currency' => 'SGD',
        'moq' => 1,
        'lead_time_days' => 2,
        'allow_order' => 1,
        'publishable' => 1,
        'is_test' => 1,
    ], $actor);
    $packageId = (int)$package['id'];
    $publish = $channel->queueProduct($packageId, $actor);
    $outboxIds[] = (int)$publish['id'];
    $publishAgain = $channel->queueProduct($packageId, $actor);
    if ((int)$publishAgain['id'] !== (int)$publish['id']) {
        throw new RuntimeException('Product publish idempotency failed.');
    }
    $simulatedPublish = $channel->simulate((int)$publish['id'], $actor);
    if (($simulatedPublish['status'] ?? '') !== 'simulated') {
        throw new RuntimeException('Product publish simulation failed.');
    }

    $stock = new StockQuoteService($db);
    $saved = $stock->save([
        'customer_id' => (int)$customer['id'],
        'sales_channel' => 'singapore_web',
        'currency' => 'SGD',
        'valid_until' => date('Y-m-d', strtotime('+30 days')),
        'is_test' => 1,
        'items' => [[
            'configuration_request' => ['product_key' => 'stock:' . $skuId, 'mode' => 'quick', 'values' => []],
            'quantity' => 2,
            'unit_price' => 88.5,
        ]],
    ], $actor);
    $quoteId = (int)$saved['id'];
    if (($saved['sales_channel'] ?? '') !== 'singapore_web'
        || ($saved['items'][0]['configuration_level'] ?? '') !== 'locked') {
        throw new RuntimeException('Stock quote channel/configuration context was not persisted.');
    }
    $stock->submit($quoteId, $actor);
    (new QuoteWorkflowService())->transition($quoteId, 'approved', $actor, 'stock channel smoke approval');
    $order = $channel->queueAssistedOrder($quoteId, $actor);
    $outboxIds[] = (int)$order['id'];
    $simulatedOrder = $channel->simulate((int)$order['id'], $actor);
    if (($simulatedOrder['status'] ?? '') !== 'simulated') {
        throw new RuntimeException('Assisted order simulation failed.');
    }
    $context = $db->query("SELECT push_status,external_order_id FROM cc_quote_channel_context WHERE quote_id={$quoteId}")
        ->fetch(PDO::FETCH_ASSOC);
    if (($context['push_status'] ?? '') !== 'simulated' || empty($context['external_order_id'])) {
        throw new RuntimeException('Quote channel result was not recorded.');
    }
    echo "PASS: stock quote, locked snapshot, package publish, idempotent outbox and assisted-order simulation verified.\n";
} finally {
    if ($outboxIds !== []) {
        $ids = implode(',', array_map('intval', $outboxIds));
        $db->exec("DELETE FROM cc_channel_outbox WHERE id IN ({$ids}) AND is_test=1");
    }
    if ($quoteId > 0) {
        $db->exec("DELETE FROM cc_channel_entity_links WHERE entity_type='quote' AND entity_id={$quoteId}");
        $versionIds = $db->query("SELECT id FROM cc_quote_versions WHERE quote_id={$quoteId}")->fetchAll(PDO::FETCH_COLUMN);
        if ($versionIds) {
            $versions = implode(',', array_map('intval', $versionIds));
            $itemIds = $db->query("SELECT id FROM cc_quote_items WHERE quote_version_id IN ({$versions})")->fetchAll(PDO::FETCH_COLUMN);
            if ($itemIds) {
                $items = implode(',', array_map('intval', $itemIds));
                $db->exec("DELETE FROM cc_quote_item_adaptation_refs WHERE quote_item_id IN ({$items})");
                $db->exec("DELETE FROM cc_quote_item_snapshots WHERE quote_item_id IN ({$items})");
                $db->exec("DELETE FROM cc_quote_item_details WHERE quote_item_id IN ({$items})");
                $db->exec("DELETE FROM cc_quote_items WHERE id IN ({$items})");
            }
            $db->exec("DELETE FROM cc_quote_versions WHERE id IN ({$versions})");
        }
        foreach (['cc_quote_approvals','cc_quote_state_history','cc_quote_audit_logs','cc_quote_snapshots','cc_quotation_logs'] as $table) {
            $db->exec("DELETE FROM {$table} WHERE quote_id={$quoteId}");
        }
        $db->exec("DELETE FROM cc_quote_channel_context WHERE quote_id={$quoteId}");
        $db->exec("DELETE FROM cc_quote_details WHERE quote_id={$quoteId}");
        $db->exec("DELETE FROM cc_quotes WHERE id={$quoteId} AND is_test=1");
    }
    if ($packageId > 0) {
        $db->exec("DELETE FROM cc_channel_entity_links WHERE entity_type='channel_package' AND entity_id={$packageId}");
        $db->exec("DELETE FROM cc_channel_packages WHERE id={$packageId} AND is_test=1");
    }
    if ($skuId > 0) {
        $db->exec("DELETE FROM cc_inventory_skus WHERE id={$skuId} AND is_test=1");
    }
}
