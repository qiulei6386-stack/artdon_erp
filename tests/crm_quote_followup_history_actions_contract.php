<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/crm_task_center.php');
$api = file_get_contents($root . '/crm_api.php');
$script = file_get_contents($root . '/assets/crm/crm.js');
$style = file_get_contents($root . '/assets/crm/crm.css');
if ($service === false || $api === false || $script === false || $style === false) {
    throw new RuntimeException('quote follow-up history action sources are not readable');
}

foreach ([
    'function crm_quote_followup_authorized_activity',
    'function crm_quote_followup_sync_timeline',
    'function crm_quote_followup_refresh_task',
    'function crm_quote_followup_update',
    'function crm_quote_followup_delete',
    "UPDATE crm_quote_followup_activities SET deleted_at=NOW()",
    "UPDATE crm_quote_followup_files SET deleted_at=NOW()",
    "DELETE FROM crm_customer_timeline WHERE related_type='quote_followup'",
    "crm_log_event('tasks', 'quote_followup_update'",
    "crm_log_event('tasks', 'quote_followup_delete'",
] as $marker) {
    if (!str_contains($service, $marker)) throw new RuntimeException("quote follow-up backend action missing: {$marker}");
}

foreach (["'quote_followup_update'", "'quote_followup_delete'"] as $marker) {
    if (!str_contains($api, $marker)) throw new RuntimeException("quote follow-up API action missing: {$marker}");
}

foreach ([
    'data-quote-followup-history-view',
    'data-quote-followup-history-edit',
    'data-quote-followup-history-delete',
    'openQuoteFollowupHistoryView',
    'startQuoteFollowupEdit',
    "post(isEdit ? 'quote_followup_update' : 'quote_followup_save'",
    "post('quote_followup_delete'",
    'data-quote-followup-edit-cancel',
    "saveButton.textContent = isEdit ? '正在保存修改…' : '正在保存…'",
] as $marker) {
    if (!str_contains($script, $marker)) throw new RuntimeException("quote follow-up history UI action missing: {$marker}");
}

foreach (['.quote-followup-history-actions', '.quote-followup-history-dialog', '.quote-followup-history-detail'] as $marker) {
    if (!str_contains($style, $marker)) throw new RuntimeException("quote follow-up history style missing: {$marker}");
}

foreach (['crm_quote_followup_update', 'crm_quote_followup_delete'] as $functionName) {
    $start = strpos($service, "function {$functionName}");
    $next = $start === false ? false : strpos($service, "\nfunction ", $start + 10);
    if ($start === false || $next === false) throw new RuntimeException("cannot inspect {$functionName}");
    $function = substr($service, $start, $next - $start);
    $commit = strpos($function, '$pdo->commit();');
    $log = strpos($function, "crm_log_event('tasks'");
    if ($commit === false || $log === false || $log < $commit) {
        throw new RuntimeException("{$functionName} must write its operation log after the business transaction");
    }
}

echo "CRM quote follow-up history actions contract: OK\n";
