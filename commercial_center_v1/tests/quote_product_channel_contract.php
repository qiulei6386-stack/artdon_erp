<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$quoteService = file_get_contents($root . '/app/Services/QuoteService.php');
$stockService = file_get_contents($root . '/app/Services/StockQuoteService.php');
$standardService = file_get_contents($root . '/app/Services/StandardQuoteService.php');
$channelService = file_get_contents($root . '/app/Services/SingaporeChannelService.php');
$adapter = file_get_contents($root . '/app/Adapters/SingaporeChannelAdapter.php');
$repository = file_get_contents($root . '/app/Repositories/ConfigurationRepository.php');
$hub = file_get_contents($root . '/views/quote_custom.php');
$migration = file_get_contents($root . '/database/migrations/016_quote_channel_bridge.sql');

foreach ([
    "'stock_product'", "'standard_product'", "'custom_product'",
    "'guangzhou_direct'", "'singapore_web'",
] as $marker) {
    if (!str_contains($quoteService . $stockService . $standardService, $marker)) {
        throw new RuntimeException("quote product/channel marker missing: {$marker}");
    }
}
foreach ([
    '库存品报价单', '标准品报价单', '定制品报价单',
    '新加坡网站只是销售渠道', '网站订单回流',
] as $marker) {
    if (!str_contains($hub, $marker)) {
        throw new RuntimeException("quote-center UX marker missing: {$marker}");
    }
}
foreach ([
    'cc_quote_channel_context', 'cc_quote_item_adaptation_refs',
    'cc_channel_outbox', 'cc_channel_entity_links',
] as $table) {
    if (!str_contains($migration, "CREATE TABLE IF NOT EXISTS {$table}")) {
        throw new RuntimeException("channel bridge table missing: {$table}");
    }
}
foreach ([
    'g.status=\'approved\'', 'g.is_enabled=1', 'approved_version',
    'quick_rules', 'adaptation_product_id', 'match_level',
] as $marker) {
    if (!str_contains($repository, $marker)) {
        throw new RuntimeException("approved adaptation passport marker missing: {$marker}");
    }
}
foreach ([
    "'product_publish'", "'assisted_order'", "'pending'", "'simulated'",
    'idempotency_key', 'payload_hash', 'retry',
] as $marker) {
    if (!str_contains($channelService, $marker)) {
        throw new RuntimeException("Singapore outbox marker missing: {$marker}");
    }
}
// The adapter now implements an explicit, authenticated publish operation.
// Inspect source only: never invoke publish or read its private configuration.
foreach (['not_configured', "\$secret === '' || !function_exists('curl_init')", "hash_hmac('sha256'", 'Idempotency-Key:', 'CURLOPT_TIMEOUT => 30', "\$status < 200 || \$status >= 300", "empty(\$response['ok'])"] as $marker) {
    if (!str_contains($adapter, $marker)) throw new RuntimeException('Configured publisher safety missing: ' . $marker);
}
echo "Quote product types, approved-adaptation passport and authenticated Singapore outbox contract: OK\n";
