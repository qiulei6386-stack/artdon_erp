<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Artdon\MaterialCenter\Services\SourceSyncService;

$service = new SourceSyncService(db());
$all = $service->materialRows();
$expected = (int)db()->query("SELECT COUNT(*) FROM mc_source_records WHERE source_system='guangzhou_bom' AND source_table='bom_materials' AND matched_material_id IS NULL")->fetchColumn();
if (count($all) !== min(2000, $expected)) {
    throw new RuntimeException('all-material source row count mismatch');
}
$chip = $service->materialRows('chip');
if (!$chip || array_filter($chip, static fn(array $row): bool => $row['legacy_category'] !== '芯片')) {
    throw new RuntimeException('chip source category mapping mismatch');
}
$power = $service->materialRows('power_supply');
if (!$power || array_filter($power, static fn(array $row): bool => !in_array($row['legacy_category'], ['驱动', '电源'], true))) {
    throw new RuntimeException('power source category mapping mismatch');
}
echo "Source material listing: OK all=" . count($all) . " chip=" . count($chip) . " power=" . count($power) . "\n";
