<?php
$root = dirname(__DIR__);
$php = file_get_contents($root . '/crm_marketing.php');
$js = file_get_contents($root . '/assets/crm/crm.js');
if ($php === false || $js === false) {
    throw new RuntimeException('Promotion queue target sync sources are not readable');
}

$markers = [
    'function crm_marketing_update_target_from_queue(array $queueRow, string $status, string $failureReason = \'\'): void',
    'function crm_marketing_reconcile_task_targets_from_queue(int $taskId): array',
    'crm_marketing_reconcile_task_targets_from_queue($taskId);',
    "UPDATE crm_marketing_task_targets SET target_status='skipped'",
    "crm_marketing_update_target_from_queue($row, 'success', '')",
    'if ($next === \'failed\') crm_marketing_update_target_from_queue($row, \'failed\', $e->getMessage());',
    "q.send_status = 'sent'",
    "ml.action_key = 'queue_skipped'",
];

foreach ($markers as $marker) {
    if (!str_contains($php, $marker)) {
        throw new RuntimeException("Marketing queue target sync marker missing: {$marker}");
    }
}

$executionStart = strpos($js, 'renderExecutionCenter: function ()');
$executionEnd = strpos($js, 'openManualResultDialog: function', $executionStart === false ? 0 : $executionStart);
if ($executionStart === false || $executionEnd === false || $executionEnd <= $executionStart) {
    throw new RuntimeException('Promotion execution center function boundary is missing');
}
$execution = substr($js, $executionStart, $executionEnd - $executionStart);
foreach (['var reportTaskIds = tasks.slice(0, 12).map', 'reportTaskIds.unshift(selectedTaskId)', 'self.ensureTaskReport(taskId)'] as $marker) {
    if (!str_contains($execution, $marker)) {
        throw new RuntimeException("Promotion execution center selected-task queue marker missing: {$marker}");
    }
}

echo "crm_marketing_queue_target_sync_contract: OK\n";
