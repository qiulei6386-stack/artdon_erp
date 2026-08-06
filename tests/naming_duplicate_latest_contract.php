<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$src = file_get_contents($root . '/naming.php');
if ($src === false) {
    throw new RuntimeException('Cannot read naming.php');
}

$checks = [
    'duplicate key helper exists' => str_contains($src, 'function nm_model_duplicate_key(array $row): string'),
    'duplicate key includes product name sources' => str_contains($src, "array('product_name','series_name','web_series','website_display_name')"),
    'duplicate key includes type sources' => str_contains($src, "array('item_name','lamp_type','web_size_name','category')"),
    'duplicate key includes dimension fields' => str_contains($src, "nm_key_num(\$row['dim_opening'] ?? '')") && str_contains($src, "nm_key_num(\$row['dim_height'] ?? '')"),
    'model list dedupes before pagination' => str_contains($src, '$all = nm_dedupe_latest_model_rows($st->fetchAll() ?: array(), $sort);') && str_contains($src, "array_slice(\$all, \$offset, \$per)"),
    'model list keeps latest row per duplicate group' => str_contains($src, 'function nm_dedupe_latest_model_rows(array $rows') && str_contains($src, 'nm_sort_latest_rows($group);'),
    'website feed dedupes by duplicate key' => str_contains($src, '$key = nm_model_duplicate_key($it);') && str_contains($src, '$dedup[$key] = $pair[0];'),
    'website upsert finds same-spec row' => str_contains($src, 'if (!$old) $old = nm_find_duplicate_model_row($pdo, $item);'),
    'active filter excludes archived website rows' => str_contains($src, 'function nm_active_model_condition_sql(array $cols): string') && str_contains($src, "COALESCE(`website_deleted`,0)=0"),
];

$failed = [];
foreach ($checks as $name => $ok) {
    if (!$ok) $failed[] = $name;
}
if ($failed) {
    fwrite(STDERR, "Naming duplicate latest contract failed:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "Naming duplicate latest contract passed (" . count($checks) . " checks)\n";
