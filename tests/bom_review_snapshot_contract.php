<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string) file_get_contents($root.'/bom.php');
$api = (string) file_get_contents($root.'/bom_api.php');
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($api, "'get_snapshot'=>'view_dashboard'"), 'full snapshot detail action is not permission mapped');
$assert(str_contains($api, "if(\$existingStatus === 'pending')") && str_contains($api, "if(\$existingStatus === 'approved')"), 'pending or approved BOM can still be saved as a draft');
$assert(str_contains($api, "!== 'pending') json_out(array('ok'=>false,'error'=>'只有已提交、待审核的 BOM 才能审核通过')"), 'approval does not require pending review status');
$assert(str_contains($api, "只有待审核 BOM 才能驳回"), 'rejection does not require pending review status');
$assert(str_contains($api, "count(bom_project_rows(\$p)) < 1"), 'empty BOM can still be submitted for review');
$assert(!str_contains($page, "alert(lines.length?lines.join('\\n'):'当前 BOM 暂无快照')"), 'snapshot history is still rendered through alert');
$assert(str_contains($page, 'id="bomSnapshotMask"') && str_contains($page, "api('get_snapshot'"), 'full snapshot viewer is missing');
$assert(str_contains($page, 'copyBomSnapshotToDraft') && str_contains($page, '复制为新草稿'), 'snapshot recovery-to-draft flow is missing');
$assert(str_contains($page, 'function updateBomWorkflowUI()') && str_contains($page, "['pending','approved'].includes(status)"), 'review status does not lock the editor');
$assert(str_contains($page, "api('approve_project',{project_uid:p.id,review_note:note})"), 'reviewer can still overwrite submitted BOM content during approval');
$assert(str_contains($api, "退审：") && str_contains($api, "review_status='draft'"), 'unapprove flow is missing');

echo "BOM review, unapprove and snapshot contract: OK\n";
