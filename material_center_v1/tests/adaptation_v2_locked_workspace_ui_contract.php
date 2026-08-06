<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$index = file_get_contents($root . '/material_center_v1/adaptation_v2/index.php');
if ($index === false) {
    throw new RuntimeException('Cannot read adaptation V2 index.php');
}

$checks = [
    'locked active draft state is detected' => str_contains($index, '$wpHasLockedActiveDraft = $wpConfig && !empty($wpConfig[\'active_draft_version_id\']) && !$wpCanEditVersion;'),
    'next draft is allowed for locked active drafts' => str_contains($index, '$wpHasLockedActiveDraft ||'),
    'next draft action uses workspace prepare endpoint' => str_contains($index, 'action=workspace_prepare') && str_contains($index, '生成下一版草稿'),
    'locked material cards explain next draft before selecting materials' => str_contains($index, '请先点击“生成下一版草稿”后再选择物料'),
    'save draft button remains draft-only' => str_contains($index, 'if ($wpCanEditVersion): ?>') && str_contains($index, '保存草稿'),
];

$failed = [];
foreach ($checks as $name => $ok) {
    if (!$ok) $failed[] = $name;
}
if ($failed) {
    fwrite(STDERR, "Adaptation locked workspace UI contract failed:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "Adaptation locked workspace UI contract passed (" . count($checks) . " checks)\n";
