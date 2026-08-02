<?php

$root = dirname(__DIR__);
$js = file_get_contents($root . '/assets/crm/crm.js');
$css = file_get_contents($root . '/assets/crm/crm.css');
$customer = file_get_contents($root . '/crm_customer.php');
$task = file_get_contents($root . '/crm_task_center.php');
$page = file_get_contents($root . '/crm.php');

foreach ([
    'source_type',
    'source_id',
    'idx_follow_source',
    'INSERT INTO crm_customer_followups',
    'source_type=?, source_id=?',
] as $marker) {
    if (!str_contains($customer, $marker)) {
        throw new RuntimeException("Sample followup source marker missing in crm_customer.php: {$marker}");
    }
}

foreach ([
    "'followups' => crm_sample_followups",
    'function crm_sample_followups(array $shipment): array',
    "f.source_type='sample_shipment'",
    "f.followup_type='样品'",
    'f.content LIKE ? OR f.next_plan LIKE ?',
] as $marker) {
    if (!str_contains($task, $marker)) {
        throw new RuntimeException("Sample followup detail marker missing in crm_task_center.php: {$marker}");
    }
}

foreach ([
    'sampleFollowupsHtml',
    'sample-feedback-note',
    'sample-followup-note',
    'data-sample-followup-edit',
    'data-sample-followup-delete',
    'deleteSampleFollowup',
    'name="source_type" value="sample_shipment"',
    'name="source_id" value="',
    'TaskCenterModule.loadSelectedDetail(); TaskCenterModule.load();',
] as $marker) {
    if (!str_contains($js, $marker)) {
        throw new RuntimeException("Sample followup UI marker missing in crm.js: {$marker}");
    }
}

foreach ([
    '.sample-followup-list',
    '.sample-followup-list button.danger',
] as $marker) {
    if (!str_contains($css, $marker)) {
        throw new RuntimeException("Sample followup style marker missing in crm.css: {$marker}");
    }
}

if (!str_contains($page, "\$crmAssetBuild = 'sample-followup-detail-20260802-1';")) {
    throw new RuntimeException('CRM asset build must bust cache for sample followup detail changes');
}

echo "crm_sample_followup_detail_contract: OK\n";
