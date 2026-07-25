<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Artdon\CommercialCenter\Repositories\LegacyCatalogReadRepository;

$repository = new LegacyCatalogReadRepository();
$checks = [
    'products' => $repository->products('', '', 2),
    'products_page_2' => $repository->products('', '', 1, 1),
    'product_count' => $repository->productCount('', ''),
    'product_status_counts' => $repository->productStatusCounts('', ''),
    'product_categories' => $repository->productCategories(),
    'materials' => $repository->materials('', '', 2),
    'material_categories' => $repository->materialCategories(),
];
foreach ($checks as $name => $rows) {
    if ($name === 'product_count' ? !is_int($rows) : !is_array($rows)) {
        fwrite(STDERR, "Invalid catalog result: {$name}\n");
        exit(1);
    }
}
$sample = $checks['products'][0] ?? null;
if (is_array($sample) && (string)($sample['model_no'] ?? '') !== '') {
    $needle = substr((string)$sample['model_no'], 1, 4);
    if ($needle !== '' && $repository->products($needle, '', 5) === []) {
        fwrite(STDERR, "Product fuzzy search returned no rows for model fragment.\n");
        exit(1);
    }
}
echo "PASS: M2 product and material catalog queries executed read-only.\n";
