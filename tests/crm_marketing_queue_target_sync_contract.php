<?php
$root = dirname(__DIR__);
$php = file_get_contents($root . '/crm_marketing.php');
$api = file_get_contents($root . '/crm_api.php');
$js = file_get_contents($root . '/assets/crm/crm.js');
$css = file_get_contents($root . '/assets/crm/crm.css');
if ($php === false || $api === false || $js === false || $css === false) {
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
    'function crm_marketing_reconcile_email_group_followups(int $taskId): void',
    'crm_marketing_reconcile_email_group_followups($taskId);',
    '邮件无收件邮箱，转群人工执行',
    'q.sender_user_id, q.sender_email, q.receiver_email, q.subject',
    'manual_checked_by_user_id',
    'COALESCE(chk.real_name, chk.username) AS manual_checked_by_name',
    'function crm_marketing_manual_unexecute',
    'manual_execute_cancel',
    'crm_marketing_queue_bodies',
    'body_ref_id',
    'function crm_marketing_queue_body_store',
    'function crm_marketing_compact_queue_bodies',
];

foreach ($markers as $marker) {
    if (!str_contains($php, $marker)) {
        throw new RuntimeException("Marketing queue target sync marker missing: {$marker}");
    }
}
foreach (["\$action === 'marketing_manual_unexecute'", 'crm_marketing_manual_unexecute($_POST)'] as $marker) {
    if (!str_contains($api, $marker)) {
        throw new RuntimeException("Promotion manual undo API marker missing: {$marker}");
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
foreach (['var emailChannels = [\'email\', \'mail\', \'edm\'];', 'isManualCheckedEmail', "emailChannels.indexOf(channel) >= 0 && (['pending','failed','skipped'].indexOf(status) >= 0 || isManualCheckedEmail)", '邮件未自动触达', 'row.chat_group_name || row.manual_group_name || row.chat_group_id', 'row.chat_group_name || row.manual_group_name || (\'客户群 #\' + row.chat_group_id)', 'collapseEmailFollowupsWithGroupTargets', 'isGroupPromotionChannel(a.channel_key)', 'promo-manual-group-badge', 'modalClass: \'promo-manual-execution-modal\'', 'dialogClass: \'promo-manual-execution-dialog\'', 'data-promo-manual-filter="group"', 'data-promo-manual-filter="wechat_group"', 'data-promo-manual-filter="whatsapp_group"', 'data-promo-manual-filter="email"', '批量勾选当前筛选', '勾选即记录', 'data-promo-manual-empty', 'visibleCheckboxes', 'runImmediateExecute', "post('marketing_manual_execute'", "post('marketing_manual_unexecute'", 'data-promo-manual-undo', '取消执行', 'self.openManualExecutionDialog(task.id)', '<span>群推广</span>', '<span>微信群</span>', '<span>WhatsApp群</span>', '<span>邮件转人工</span>', 'promo-manual-table', 'data-promo-manual-select-all', 'manual_checked_by_name'] as $marker) {
    if (!str_contains($manual, $marker)) {
        throw new RuntimeException("Promotion manual execution email fallback marker missing: {$marker}");
    }
}
foreach (['.promo-manual-table-card', '.promo-manual-note', '.promo-manual-table-wrap', '.promo-manual-execution-modal', '.promo-manual-execution-dialog', 'display: block !important;', 'overflow-y: auto !important;', 'overflow: visible !important;', 'max-height: none !important;', '.promo-manual-table tr.is-overdue', '.promo-manual-table tr.is-group-target', '.promo-manual-group-badge', '.promo-manual-undo', '.promo-manual-summary button', '.promo-manual-summary button.is-active'] as $marker) {
    if (!str_contains($css, $marker)) {
        throw new RuntimeException("Promotion manual execution layout style missing: {$marker}");
    }
}
if (!str_contains($js, "['pending','running','partial_failed','paused','completed','failed','manual_pending'].indexOf(row.task_status) >= 0")) {
    throw new RuntimeException('Promotion manual execution action must include completed email tasks');
}

$queueListStart = strpos($php, 'function crm_marketing_queue_list(array $input): array');
$queueListEnd = strpos($php, 'function crm_marketing_queue_retry_failed(array $input): array', $queueListStart === false ? 0 : $queueListStart);
if ($queueListStart === false || $queueListEnd === false || $queueListEnd <= $queueListStart) {
    throw new RuntimeException('Promotion queue list function boundary is missing');
}
$queueList = substr($php, $queueListStart, $queueListEnd - $queueListStart);
if (str_contains($queueList, 'SELECT q.*')) {
    throw new RuntimeException('Promotion queue list must not return queue body payloads');
}
foreach (['q.id, q.task_id', 'q.sender_email, q.receiver_email, q.subject', 'q.last_error', 'c.customer_name, ct.name contact_name'] as $marker) {
    if (!str_contains($queueList, $marker)) {
        throw new RuntimeException("Promotion queue list lightweight marker missing: {$marker}");
    }
}

$queueBuildStart = strpos($php, 'function crm_marketing_queue_build(array $input): array');
$queueBuildEnd = strpos($php, 'function crm_marketing_queue_status_counts', $queueBuildStart === false ? 0 : $queueBuildStart);
if ($queueBuildStart === false || $queueBuildEnd === false || $queueBuildEnd <= $queueBuildStart) {
    throw new RuntimeException('Promotion queue build function boundary is missing');
}
$queueBuild = substr($php, $queueBuildStart, $queueBuildEnd - $queueBuildStart);
foreach (['crm_marketing_queue_body_store($taskId, $body)', "body, body_ref_id", "VALUES (?, ?, ?, ?, ?, ?, ?, ?, '', ?", 'body_ref_id=VALUES(body_ref_id)'] as $marker) {
    if (!str_contains($queueBuild, $marker)) {
        throw new RuntimeException("Promotion queue build compact body marker missing: {$marker}");
    }
}

$queueRunStart = strpos($php, 'function crm_marketing_queue_run_due(int $limit = 30): array');
$queueRunEnd = strpos($php, 'function crm_marketing_template_copy', $queueRunStart === false ? 0 : $queueRunStart);
if ($queueRunStart === false || $queueRunEnd === false || $queueRunEnd <= $queueRunStart) {
    throw new RuntimeException('Promotion queue runner function boundary is missing');
}
$queueRun = substr($php, $queueRunStart, $queueRunEnd - $queueRunStart);
foreach (['qb.body_html AS queue_body_template', 'LEFT JOIN crm_marketing_queue_bodies qb ON qb.id = q.body_ref_id', 'crm_marketing_render_queue_template($bodyTemplate, $row, $account)', '队列邮件正文为空'] as $marker) {
    if (!str_contains($queueRun, $marker)) {
        throw new RuntimeException("Promotion queue runner compact body marker missing: {$marker}");
    }
}

echo "crm_marketing_queue_target_sync_contract: OK\n";
