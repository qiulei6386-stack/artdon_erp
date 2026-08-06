<?php
declare(strict_types=1);

if (PHP_SAPI === 'cli' && empty($_SERVER['DOCUMENT_ROOT'])) {
    $_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 3);
}

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/adaptation_v2/lib/foundation.php';

function pa2_image_contract_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$relative = pa2_product_image_url(['image_url' => 'uploads/naming/202607/sample.png']);
$erpBase = '/' . basename(dirname(__DIR__, 2));
pa2_image_contract_assert(str_starts_with($relative, $erpBase . '/uploads/'), 'Relative product image path should include the ERP base URL.');
pa2_image_contract_assert(str_ends_with($relative, '/uploads/naming/202607/sample.png'), 'Relative product image path should point to the legacy ERP uploads directory.');
pa2_image_contract_assert(str_starts_with($relative, '/'), 'Relative product image path should become an absolute web path.');

$dotRelative = pa2_product_image_url(['source_image_url' => './uploads/naming/sample.jpg']);
pa2_image_contract_assert(str_ends_with($dotRelative, '/uploads/naming/sample.jpg'), 'Dot-relative product image path should be normalized.');

$absolute = pa2_product_image_url(['web_image_url' => '/artdon_erp/uploads/naming/sample.jpg']);
pa2_image_contract_assert($absolute === '/artdon_erp/uploads/naming/sample.jpg', 'Absolute product image path should not change.');

$remote = pa2_product_image_url(['image_path' => 'https://example.com/product.png']);
pa2_image_contract_assert($remote === 'https://example.com/product.png', 'Remote product image path should not change.');

echo "Adaptation V2 product image URL contract passed\n";
