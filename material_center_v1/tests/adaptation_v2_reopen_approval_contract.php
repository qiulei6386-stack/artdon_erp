<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$v2 = $root . '/material_center_v1/adaptation_v2';

$foundation = file_get_contents($v2 . '/lib/foundation.php');
$api = file_get_contents($v2 . '/api/index.php');
$index = file_get_contents($v2 . '/index.php');

if ($foundation === false || $api === false || $index === false) {
    throw new RuntimeException('Cannot read adaptation V2 files');
}

$checks = [
    'foundation implements reopen approval action' => str_contains($foundation, 'function pa2_product_version_reopen_approval'),
    'reopen requires approve permission' => str_contains($foundation, '没有撤回产品配置审批的权限。'),
    'only approved versions can reopen' => str_contains($foundation, '只有已审批且未发布的版本可以撤回审批。'),
    'published versions remain protected' => str_contains($foundation, '已发布版本不能撤回审批，请生成下一版草稿。'),
    'approved audit fields are cleared' => str_contains($foundation, 'approved_by=NULL,approved_at=NULL'),
    'reopen returns version to draft' => str_contains($foundation, 'status="draft"') && str_contains($foundation, "'approval_reopened', 'approved', 'draft'"),
    'api exposes reopen endpoint' => str_contains($api, "product_version_reopen_approval") && str_contains($api, '已撤回审批，版本已回到草稿'),
    'workspace computes reopen visibility' => str_contains($index, '$wpCanReopenApproval'),
    'workspace shows reopen button' => str_contains($index, '撤回审批') && str_contains($index, '确认撤回审批并回到草稿'),
    'workspace keeps next draft path for published edits' => str_contains($index, '生成下一版草稿'),
];

$failed = [];
foreach ($checks as $name => $ok) {
    if (!$ok) $failed[] = $name;
}

if ($failed) {
    fwrite(STDERR, "Adaptation V2 reopen approval contract failed:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "Adaptation V2 reopen approval contract passed (" . count($checks) . " checks)\n";
