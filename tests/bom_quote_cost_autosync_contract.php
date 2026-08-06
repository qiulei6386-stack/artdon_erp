<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$bomApi = file_get_contents($root . '/bom_api.php');
if ($bomApi === false) {
    throw new RuntimeException('Cannot read bom_api.php');
}

$checks = [
    'BOM has targeted quote snapshot sync helper' => str_contains($bomApi, 'function bom_sync_quote_cost_snapshot(PDO $pdo, $projectUid'),
    'sync reads saved BOM project rows' => str_contains($bomApi, 'SELECT * FROM bom_projects WHERE project_uid=? LIMIT 1'),
    'sync uses BOM frontend cost formula' => str_contains($bomApi, 'function bom_quote_cost_from_project_row(array $project): float'),
    'sync updates quote price policy BOM snapshot' => str_contains($bomApi, 'UPDATE quote_price_policies SET bom_cost_rmb=?,estimated_sale_price_rmb=?,bom_cost_source=?,bom_match_key=?,bom_cost_updated_at=?'),
    'sync matches by naming id or product model' => str_contains($bomApi, "TRIM(COALESCE(naming_id,''))=?") && str_contains($bomApi, "UPPER(REPLACE(COALESCE(product_model,''),' ',''))=?"),
    'save project triggers quote snapshot sync' => str_contains($bomApi, "\$quoteSync = bom_sync_quote_cost_snapshot(\$pdo, \$uid, \$who);"),
    'naming project creation triggers quote snapshot sync' => str_contains($bomApi, "\$quoteSync = bom_sync_quote_cost_snapshot(\$pdo, \$projectUid, \$who);"),
];

$failed = [];
foreach ($checks as $name => $ok) {
    if (!$ok) $failed[] = $name;
}
if ($failed) {
    fwrite(STDERR, "BOM quote cost autosync contract failed:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "BOM quote cost autosync contract passed (" . count($checks) . " checks)\n";
