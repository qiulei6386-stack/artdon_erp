<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$foundation = file_get_contents($root . '/material_center_v1/adaptation_v2/lib/foundation.php');
if ($foundation === false) {
    throw new RuntimeException('Cannot read adaptation V2 foundation.php');
}

$checks = [
    'editable draft helper exists' => str_contains($foundation, 'function pa2_find_editable_product_draft(int $configId): ?array'),
    'helper searches draft or rejected versions' => str_contains($foundation, "status IN ('draft','rejected')"),
    'prepare workspace reuses existing draft' => str_contains($foundation, '$existingDraft = pa2_find_editable_product_draft((int)$config[\'id\']);'),
    'prepare workspace restores active draft pointer' => str_contains($foundation, 'active_draft_version_id=?,status="draft"'),
    'existing config branch uses generated draft number' => str_contains($foundation, '$versionNo = pa2_next_draft_version_no((int)$config[\'id\']);'),
    'existing config branch no longer inserts fixed draft-1' => !str_contains($foundation, "[(int)\$config['id'],'draft-1'"),
];

$failed = [];
foreach ($checks as $name => $ok) {
    if (!$ok) $failed[] = $name;
}
if ($failed) {
    fwrite(STDERR, "Adaptation workspace draft contract failed:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "Adaptation workspace draft contract passed (" . count($checks) . " checks)\n";
