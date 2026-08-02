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
    'crm_marketing_update_target_from_queue($row, \'success\', \'\')',
    'if ($next === \'failed\') crm_marketing_update_target_from_queue($row, \'failed\', $e->getMessage());',
    "q.send_status = 'sent'",
    "ml.action_key = 'queue_skipped'",
    '$limit = $taskId > 0 ? 1000 : 200;',
    '$limit = $taskId > 0 ? 1000 : 100;',
    'function crm_marketing_task_execution_summary(int $taskId): array',
    '\'execution_summary\' => crm_marketing_task_execution_summary($taskId)',
    "'wechat', 'weixin', 'wechat_group', 'whatsapp', 'whatsapp_group', 'phone', 'offline', 'visit', 'linkedin', 'email', 'mail', 'edm'",
    '$insertedChatGroupIds = [];',
    "WHERE g.customer_id = ? AND g.group_platform = ? AND g.deleted_at IS NULL AND g.status = \"active\" AND g.use_for_promotion = 1",
    '$insertedChatGroupIds[$groupId] = true;',
    '缺少可推广客户群',
    'function crm_marketing_reconcile_group_targets(int $taskId): void',
    'crm_marketing_reconcile_group_targets($taskId);',
    "DELETE FROM crm_marketing_task_targets WHERE id = ? AND target_status IN ('pending','failed') AND executed_at IS NULL",
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

$reportStart = strpos($js, 'taskExecutionReportHtml: function (task)');
$reportEnd = strpos($js, 'renderTaskProperties: function ()', $reportStart === false ? 0 : $reportStart);
if ($reportStart === false || $reportEnd === false || $reportEnd <= $reportStart) {
    throw new RuntimeException('Promotion task execution report function boundary is missing');
}
$report = substr($js, $reportStart, $reportEnd - $reportStart);
foreach (['var executionSummary = report.execution_summary || {};', '成功 \' + esc(successText(mailSummary, mailRows)) + \' / 跳过 '] as $marker) {
    if (!str_contains($report, $marker)) {
        throw new RuntimeException("Promotion task execution summary marker missing: {$marker}");
    }
}

$propertiesStart = strpos($js, 'renderTaskProperties: function ()');
$propertiesEnd = strpos($js, 'renderExecutionCenter: function ()', $propertiesStart === false ? 0 : $propertiesStart);
if ($propertiesStart === false || $propertiesEnd === false || $propertiesEnd <= $propertiesStart) {
    throw new RuntimeException('Promotion task properties function boundary is missing');
}
$properties = substr($js, $propertiesStart, $propertiesEnd - $propertiesStart);
foreach (['var mailExecutionSummary = executionSummary.mail || {};', 'var taskPendingCount = Number(mailExecutionSummary.pending || 0);', 'Number(mailExecutionSummary.total || 0)', "('已发送 ' + sentQueue + ' 条邮件')"] as $marker) {
    if (!str_contains($properties, $marker)) {
        throw new RuntimeException("Promotion task properties fallback marker missing: {$marker}");
    }
}

$manualStart = strpos($js, 'openManualExecutionDialog: function (taskId)');
$manualEnd = strpos($js, 'openStatusDialog: function', $manualStart === false ? 0 : $manualStart);
if ($manualStart === false || $manualEnd === false || $manualEnd <= $manualStart) {
    throw new RuntimeException('Promotion manual execution dialog function boundary is missing');
}
$manual = substr($js, $manualStart, $manualEnd - $manualStart);
foreach (['var emailChannels = [\'email\', \'mail\', \'edm\'];', "emailChannels.indexOf(channel) >= 0 && ['pending','failed','skipped'].indexOf(status) >= 0", '邮件未自动触达：', 'row.chat_group_name || row.manual_group_name || row.chat_group_id', 'row.chat_group_name || row.manual_group_name || (\'客户群 #\' + row.chat_group_id)'] as $marker) {
    if (!str_contains($manual, $marker)) {
        throw new RuntimeException("Promotion manual execution email fallback marker missing: {$marker}");
    }
}
if (!str_contains($js, "['pending','running','partial_failed','paused','completed','failed','manual_pending'].indexOf(row.task_status) >= 0")) {
    throw new RuntimeException('Promotion manual execution action must include completed email tasks');
}

echo "crm_marketing_queue_target_sync_contract: OK\n";
