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
    'ai-main-grid',
    'ai-bottom-grid',
    'renderWorkflow: function',
    'data-radar-home-candidate',
    "renderGroup({ title: '客户探索'",
    "renderGroup({ title: '数据与资料'",
    "renderGroup({ title: '任务与执行'",
    "'线索客户池': 'candidates'",
    "if (view === 'records') { self.renderKpis(); return self.renderLogs(); }",
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

if (!str_contains($page, "\$crmAssetBuild = 'ai-task-workbench-layout-20260802-1';")) {
    throw new RuntimeException('CRM asset build must bust cache for AI center workbench changes');
}

echo "crm_ai_center_workbench_contract: OK\n";
