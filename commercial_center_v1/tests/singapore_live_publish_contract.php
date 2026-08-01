<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/app/Services/SingaporeChannelService.php');
$adapter = file_get_contents($root . '/app/Adapters/SingaporeChannelAdapter.php');
$api = file_get_contents($root . '/api/v1/singapore_channel.php');
$view = file_get_contents($root . '/views/singapore_channel.php');
$js = file_get_contents($root . '/assets/js/singapore_channel.js');
$checks = [
    'published product source' => str_contains($service, 'published_products') && str_contains($service, 'queuePublishedProduct'),
    'real sender' => str_contains($service, 'public function send') && str_contains($adapter, 'X-Artdon-Signature'),
    'secret outside repository' => str_contains($adapter, '/www/secure/artdon_singapore_channel.key'),
    'idempotency' => str_contains($adapter, 'Idempotency-Key') && str_contains($service, 'idempotency_key'),
    'api actions' => str_contains($api, 'queue_published_product') && str_contains($api, "action === 'send'"),
    'published product UI' => str_contains($view, 'data-sg-published-products') && str_contains($js, 'data-sg-publish-product'),
    'real send UI' => str_contains($js, 'data-sg-send') && str_contains($js, "'send'"),
];
foreach ($checks as $label => $passed) {
    if (!$passed) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); }
}
echo 'Singapore live publish contract passed (' . count($checks) . " checks).\n";
