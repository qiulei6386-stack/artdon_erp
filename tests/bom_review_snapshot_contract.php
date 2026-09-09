<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string) file_get_contents($root.'/bom.php');
$api = (string) file_get_contents($root.'/bom_api.php');
$workflow = (string) file_get_contents($root.'/includes/bom_workflow.php');
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($api, "'get_snapshot'=>'view_dashboard'"), 'full snapshot detail action is not permission mapped');
$assert(str_contains($workflow, "in_array(\$status,array('pending','approved'),true)"), 'pending or approved BOM can still be saved as a draft');
$assert(str_contains($workflow, "'approve_project'=>array('pending')"), 'approval does not require pending review status');
$assert(str_contains($workflow, "'reject_project'=>array('pending')"), 'rejection does not require pending review status');
$assert(str_contains($workflow, "\$submission&&!count(\$rows)"), 'empty BOM can still be submitted for review');
$assert(!str_contains($page, "alert(lines.length?lines.join('\\n'):'当前 BOM 暂无快照')"), 'snapshot history is still rendered through alert');
$assert(str_contains($page, 'id="bomSnapshotMask"') && str_contains($page, "api('get_snapshot'"), 'full snapshot viewer is missing');
$assert(str_contains($page, 'copyBomSnapshotToDraft') && str_contains($page, '复制为新草稿'), 'snapshot recovery-to-draft flow is missing');
$assert(str_contains($page, 'function updateBomWorkflowUI()') && str_contains($page, "['pending','approved'].includes(status)"), 'review status does not lock the editor');
$assert(str_contains($page, "bomReviewAction('approve_project',note)")&&str_contains($workflow,'expected_revision'), 'review must target exact submitted revision');
$assert(str_contains($workflow, "'unapprove_project'=>'draft'"), 'unapprove flow is missing');

echo "BOM review, unapprove and snapshot contract: OK\n";
