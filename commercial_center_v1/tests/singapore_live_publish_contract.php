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
    'published version authority' => str_contains($service, "empty(\$product['commercial_version_id'])") && !str_contains($service, "empty(\$product['commercial_version_id']) || (\$product['status']"),
    'real sender' => str_contains($service, 'public function send') && str_contains($adapter, 'X-Artdon-Signature'),
    'private storage secret' => str_contains($adapter, "CC_STORAGE . '/channel_sync_secret'"),
    'idempotency' => str_contains($adapter, 'Idempotency-Key') && str_contains($service, 'idempotency_key'),
    'api actions' => str_contains($api, 'queue_published_product') && str_contains($api, "action === 'send'"),
    'published product UI' => str_contains($view, 'data-sg-published-products') && str_contains($js, 'data-sg-publish-product'),
    'published product render target' => str_contains($js, 'const publishedBody') && str_contains($js, 'const isPublished') && str_contains($js, 'publishedBody.append(row)') && !str_contains($js, 'const published = publication.sync_status'),
    'real send UI' => str_contains($js, 'data-sg-send') && str_contains($js, "'send'"),
    'unpublish queue' => str_contains($service, 'queueUnpublishProduct') && str_contains($api, 'queue_unpublish_product'),
    'unpublish UI' => str_contains($js, 'data-sg-unpublish-product') && str_contains($js, '下架原因'),
    'withdrawn state' => str_contains($service, "'withdrawn'") && str_contains($service, 'product_unpublish'),
    'material parameters payload' => str_contains($service, 'material_center_parameters') && str_contains($service, 'technical_parameters') && str_contains($service, 'packageParameters'),
    'published material parameters display' => str_contains($service, 'publishedProductTechnicalParameters') && str_contains($service, 'fallbackTechnicalFromProduct') && str_contains($service, 'appendCustomParameters') && str_contains($service, 'productDimensionText'),
    'material parameters UI' => str_contains($js, 'parameterSummary') && str_contains($js, "technical[key]"),
];
foreach ($checks as $label => $passed) {
    if (!$passed) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); }
}
echo 'Singapore live publish contract passed (' . count($checks) . " checks).\n";
