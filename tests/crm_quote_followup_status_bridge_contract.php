<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$api = file_get_contents($root . '/crm_api.php');
$service = file_get_contents($root . '/crm_task_center.php');
$js = file_get_contents($root . '/assets/crm/crm.js');
if ($api === false || $service === false || $js === false) {
    throw new RuntimeException('CRM quote follow-up status bridge sources are not readable');
}

foreach ([
    "\$action === 'quote_followup_status_set'",
    'crm_quote_followup_status_set($_POST)',
] as $marker) {
    if (!str_contains($api, $marker)) {
        throw new RuntimeException("CRM API route marker missing: {$marker}");
    }
}

foreach ([
    'function crm_quote_followup_lifecycle_ensure',
    "`followup_status` VARCHAR(40) NOT NULL DEFAULT 'active'",
    "`followup_closed_reason` TEXT NULL",
    'function crm_quote_followup_status_set',
    "UPDATE quote_orders SET followup_status='closed'",
    "UPDATE quote_orders SET followup_status='active'",
    "UPDATE crm_tasks SET status='closed'",
    "UPDATE crm_tasks SET status='pending'",
    '结束跟进需要填写事由。',
    'crm_quote_followup_append_quote_log',
    'followup_closed_reason FROM quote_orders',
] as $marker) {
    if (!str_contains($service, $marker)) {
        throw new RuntimeException("CRM quote status bridge marker missing: {$marker}");
    }
}

foreach ([
    '结束跟进',
    '恢复跟进',
    'quote_followup_status_set',
    'setQuoteFollowupStatus: function',
    'isQuoteFollowupClosed: function',
    '结束跟进必须填写事由。',
    '已结束跟进',
] as $marker) {
    if (!str_contains($js, $marker)) {
        throw new RuntimeException("CRM quote status UI marker missing: {$marker}");
    }
}

echo "CRM quote follow-up status bridge contract: OK\n";
