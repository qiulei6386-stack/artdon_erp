<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Artdon\CommercialCenter\Repositories\LegacyCatalogReadRepository;

$repository = new LegacyCatalogReadRepository();
$checks = [
    'products' => $repository->products('', '', 2),
    'product_categories' => $repository->productCategories(),
    'materials' => $repository->materials('', '', 2),
    'material_categories' => $repository->materialCategories(),
];
foreach ($checks as $name => $rows) {
    if (!is_array($rows)) {
        fwrite(STDERR, "Invalid catalog result: {$name}\n");
        exit(1);
    }
}
echo "PASS: M2 product and material catalog queries executed read-only.\n";
