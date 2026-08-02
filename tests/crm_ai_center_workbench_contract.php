<?php

$root = dirname(__DIR__);
$page = file_get_contents($root . '/crm.php');
$js = file_get_contents($root . '/assets/crm/crm.js');
$css = file_get_contents($root . '/assets/crm/crm.css');
$api = file_get_contents($root . '/crm_api.php');

foreach ([
    'data-ai-workbench',
    'data-ai-kpis',
    'data-ai-content',
] as $marker) {
    if (!str_contains($page, $marker)) {
        throw new RuntimeException("AI center mount marker missing in crm.php: {$marker}");
    }
}

foreach ([
    "post('ai_bootstrap'",
    'renderWorkbench: function',
    'ai-dashboard-hero',
    'ai-dashboard-kpis',
    'ai-home-layout',
    'ai-home-main',
    'renderQuickActionsRail: function',
    "{ title: '客户探索', items: [['客户雷达','发现高潜在客户','R']] }",
    'data-ai-home-action',
    'radar-task-workbench-v2',
    'data-radar-task-total',
    'radar-task-card-v2',
    'radar-task-country-v2',
    'radar-candidate-workbench-v2',
    'radar-candidate-list-v2',
    'radar-candidate-card-v2',
    'data-radar-candidate-total',
    'data-radar-candidate-visible',
    'radar-log-workbench-v2',
    'radar-log-hero-v2',
    'radar-log-kpis-v2',
    'radar-log-grid-v2',
    'radar-log-list-v2',
    'radar-log-detail-v2',
    'data-radar-log-q',
    'radar-settings-workbench-v2',
    'radar-settings-hero-v2',
    'radar-settings-summary-v2',
    'radar-settings-grid-v2',
    'radar-setting-block-v2',
    'radar-service-workbench-v2',
    'radar-service-card-v2',
    'radar-service-fields-v2',
    'selectedTemplateIds',
    'radar-template-actionbar-v2',
    'data-radar-template-check-all',
    'data-radar-template-action',
    'data-radar-template-row',
    'handleTemplateAction: function',
    'templateActionMany: function',
    'radar-task-editor-v2',
    'radar-task-editor-hero-v2',
    'radar-task-editor-grid-v2',
    'data-task-country-preset',
    'data-task-city-preset',
    'data-task-model-preset',
    'data-task-chip',
    'data-task-city-chips',
    'radar-task-detail-v2',
    'radar-task-detail-hero-v2',
    'radar-task-detail-kpis-v2',
    'radar-task-result-list-v2',
    'radar-task-log-list-v2',
    'ai-main-grid',
    'ai-bottom-grid',
    'renderWorkflow: function',
    'data-radar-home-candidate',
    "renderGroup({ title: '客户探索'",
    "renderGroup({ title: '数据与资料'",
    "renderGroup({ title: '任务与执行'",
    "'线索客户池': 'candidates'",
    "if (view === 'records') { if (kpis) kpis.hidden = true; return self.renderLogs(); }",
    "if (view === 'settings') { self.renderKpis(); return self.renderSettings(); }",
    "if (this.view === 'quote') return rows.filter",
    "if (this.view === 'material') return rows.filter",
    'data-ai-new-analysis',
    'AI任务工作台',
    'ai-task-page-v2',
    'ai-task-hero-v2',
    'ai-task-kpis-v2',
    'ai-task-board-v2',
    'ai-task-queue-v2',
    'ai-task-detail-v2',
    'data-ai-task-filter',
    'data-ai-to-quote',
    'data-ai-copy-json',
    'ai-log-page-v2',
    'ai-log-hero-v2',
    'ai-log-kpis-v2',
    'ai-log-layout-v2',
    'ai-log-list-v2',
    'ai-log-detail-v2',
    'data-ai-log-search',
    'data-ai-copy-log',
    'ai-settings-page-v2',
    'ai-settings-hero-v2',
    'ai-settings-summary-v2',
    'ai-settings-layout-v2',
    'ai-setting-card-v2',
    'ai-settings-side-v2',
    'data-ai-open-tasks',
    'data-ai-open-logs',
    'data-ai-settings-save',
    "post('ai_settings_save'",
] as $marker) {
    if (!str_contains($js, $marker)) {
        throw new RuntimeException("AI center logic marker missing in crm.js: {$marker}");
    }
}

if (str_contains($js, "renderGroup({ title: '客户探索', items: ['客户雷达', '雷达首页'] });")) {
    throw new RuntimeException('AI center action rail should keep only one customer radar entry');
}

foreach ([
    '.ai-workbench',
    '.ai-workbench-panel',
    '.ai-panel-head',
    'body.is-ai-home .crm-shell',
    'body.is-ai-home .crm-actionbar',
    'body.is-ai-module:not(.is-ai-home) .crm-shell:not(.is-actionbar-collapsed)',
    'grid-template-columns: minmax(0, 1fr) 260px !important;',
    'body.is-ai-module:not(.is-ai-home) .crm-actionbar',
    'body.is-ai-module .crm-action-list',
    '.radar-task-workbench-v2',
    '.radar-task-hero-v2',
    '.radar-task-card-v2',
    '.radar-task-board-v2',
    '.radar-candidate-workbench-v2',
    '.radar-candidate-hero-v2',
    '.radar-candidate-filter-v2',
    '.radar-candidate-card-v2',
    '.radar-candidate-actions-v2',
    '.radar-log-workbench-v2',
    '.radar-log-hero-v2',
    '.radar-log-kpis-v2',
    '.radar-log-grid-v2',
    '.radar-log-table-v2',
    '.radar-log-detail-v2',
    '.radar-settings-workbench-v2',
    '.radar-settings-hero-v2',
    '.radar-settings-summary-v2',
    '.radar-settings-grid-v2',
    '.radar-setting-block-v2',
    '.radar-service-workbench-v2',
    '.radar-service-card-v2',
    '.radar-service-fields-v2',
    '.radar-template-actionbar-v2',
    '.radar-template-table-v2',
    '.radar-template-table-v2 tbody tr.selected',
    '.radar-task-editor-v2',
    '.radar-task-editor-hero-v2',
    '.radar-task-editor-grid-v2',
    '.radar-task-editor-card-v2',
    '.radar-task-chip-v2',
    '.radar-task-detail-v2',
    '.radar-task-detail-kpis-v2',
    '.radar-task-result-list-v2',
    '.radar-task-log-list-v2',
    '.ai-home-layout',
    'grid-template-columns: minmax(0, 1fr) 260px;',
    '.ai-home-main',
    '.ai-home-right',
    '.ai-kpi-section',
    '.ai-dashboard-hero',
    '.ai-dashboard-kpis',
    '.ai-main-grid',
    '.ai-bottom-grid',
    '.ai-task-page-v2',
    '.ai-task-hero-v2',
    '.ai-task-kpis-v2',
    '.ai-task-board-v2',
    '.ai-task-queue-v2',
    '.ai-task-detail-v2',
    '.ai-task-bottom-grid-v2',
    '.ai-result-grid-v2',
    '.ai-log-page-v2',
    '.ai-log-hero-v2',
    '.ai-log-kpis-v2',
    '.ai-log-layout-v2',
    '.ai-log-table-v2',
    '.ai-log-detail-v2',
    '.ai-log-json-v2',
    '.ai-settings-page-v2',
    '.ai-settings-hero-v2',
    '.ai-settings-summary-v2',
    '.ai-settings-layout-v2',
    '.ai-setting-card-v2',
    '.ai-settings-side-v2',
    'body.is-ai-module .action_command::after',
    '.crm-modal.ai-dialog',
    '.radar-drawer-backdrop',
    'z-index: 120000 !important',
    'place-items: center !important',
    'overflow-y: auto !important',
    'overflow-y: visible !important',
] as $marker) {
    if (!str_contains($css, $marker)) {
        throw new RuntimeException("AI center style marker missing in crm.css: {$marker}");
    }
}

foreach ([
    "if (\$action === 'ai_bootstrap')",
    "if (\$action === 'ai_settings_save')",
] as $marker) {
    if (!str_contains($api, $marker)) {
        throw new RuntimeException("AI center API marker missing in crm_api.php: {$marker}");
    }
}

if (!str_contains($page, "\$crmAssetBuild = 'radar-task-detail-redesign-20260802-1';")) {
    throw new RuntimeException('CRM asset build must bust cache for AI center workbench changes');
}

echo "crm_ai_center_workbench_contract: OK\n";
