<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$quoteApi = file_get_contents($root . '/quote_api.php');
if ($quoteApi === false) {
    throw new RuntimeException('Cannot read quote_api.php');
}

$checks = [
    'naming id remains the first BOM cost key' => str_contains($quoteApi, "\$keys[]='NID'.\$namingId;"),
    'naming products also collect exact model keys' => str_contains($quoteApi, "foreach([\$p['code']??'', \$p['model']??'', \$p['model_no']??'', \$p['naming_model_no']??''] as \$v)"),
    'fallback is documented as exact full model only' => str_contains($quoteApi, '退回到完整型号精确匹配'),
    'BOM cost map still refuses fuzzy matching' => str_contains($quoteApi, '必须完整型号完全相等；不做包含、不做相似、不做名称匹配'),
    'cost map still excludes material detail tables' => str_contains($quoteApi, '禁止从物料名称、规格、备注、rows_json、bom_materials 等位置提取型号'),
];

$failed = [];
foreach ($checks as $name => $ok) {
    if (!$ok) $failed[] = $name;
}
if ($failed) {
    fwrite(STDERR, "Quote BOM naming model fallback contract failed:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "Quote BOM naming model fallback contract passed (" . count($checks) . " checks)\n";
