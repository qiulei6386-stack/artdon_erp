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
    "CONVERT(source_type USING utf8mb4) COLLATE utf8mb4_unicode_ci = 'followup'",
    'CONVERT(source_id USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(? USING utf8mb4)',
] as $marker) {
    if (!str_contains($customer, $marker)) {
        throw new RuntimeException("Sample followup source marker missing in crm_customer.php: {$marker}");
    }
}

foreach ([
    "'followups' => crm_sample_followups",
    'function crm_sample_followups(array $shipment): array',
    "CONVERT(f.source_type USING utf8mb4) COLLATE utf8mb4_unicode_ci = 'sample_shipment'",
    "CONVERT(f.followup_type USING utf8mb4) COLLATE utf8mb4_unicode_ci = '样品'",
    'CONVERT(f.content USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE',
    "CONVERT(t.source_type USING utf8mb4) COLLATE utf8mb4_unicode_ci = 'followup'",
    "CONVERT(source_type USING utf8mb4) COLLATE utf8mb4_unicode_ci = 'sample_shipment'",
    "CONVERT(source_type USING utf8mb4) COLLATE utf8mb4_unicode_ci = 'followup'",
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
    'sample-followup-workspace',
    'data-sample-followup-workspace data-sample-followup-form',
    'sample-followup-history-panel',
    'data-sample-followup-history',
    'data-sample-followup-drop',
    'data-sample-followup-files',
    'data-sample-followup-local-files',
    'data-sample-followup-file-list',
    'bindSampleFollowupDialog',
    'mergeSampleFollowupFiles',
    'renderSampleFollowupLocalFiles',
    'uploadSampleFollowupFiles',
    'uploadSampleFiles',
    'copySampleFileLink',
    'data-sample-copy-file',
    "document.querySelector('[data-crm-file-preview-layer]')?.remove();",
    "if (event.target === layer) close();",
    "layer.addEventListener('cancel'",
    "layer.addEventListener('close'",
    "layer.showModal()",
    "dialog.addEventListener('paste'",
    'sample-row-tools',
    "var rowTools = ['编辑寄送信息', '删除样品寄送'];",
] as $marker) {
    if (!str_contains($js, $marker)) {
        throw new RuntimeException("Sample followup UI marker missing in crm.js: {$marker}");
    }
}

foreach ([
    '.sample-followup-list',
    '.sample-followup-list button.danger',
    '.customer-dialog.sample-followup-modal',
    '.sample-followup-workspace',
    '.sample-followup-drop.dragging',
    '.sample-followup-local-files',
    '.sample-followup-existing .sample-files',
    '.sample-followup-history-panel .sample-followup-list',
    '.quote-flow-card-next .sample-row-tools button[data-task-detail-action="删除样品寄送"]',
    'z-index: 120000;',
    '.visit-preview-layer::backdrop',
    '.visit-preview-layer > div',
] as $marker) {
    if (!str_contains($css, $marker)) {
        throw new RuntimeException("Sample followup style marker missing in crm.css: {$marker}");
    }
}

if (!str_contains($page, "\$crmAssetBuild = 'ai-center-dashboard-20260802-1';")) {
    throw new RuntimeException('CRM asset build must bust cache for sample followup detail changes');
}

echo "crm_sample_followup_detail_contract: OK\n";
