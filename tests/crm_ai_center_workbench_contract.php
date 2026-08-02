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
    'ai-dashboard-main',
    'ai-dashboard-bottom',
    'renderWorkflow: function',
    'data-radar-home-candidate',
    "renderGroup({ title: '客户探索'",
    "renderGroup({ title: '数据与资料'",
    "renderGroup({ title: '任务与执行'",
    "'线索客户池': 'candidates'",
    "if (view === 'records') return self.renderLogs();",
    "if (view === 'settings') return self.renderSettings();",
    "if (this.view === 'quote') return rows.filter",
    "if (this.view === 'material') return rows.filter",
    'data-ai-new-analysis',
    'data-ai-settings-save',
    "post('ai_settings_save'",
] as $marker) {
    if (!str_contains($js, $marker)) {
        throw new RuntimeException("AI center logic marker missing in crm.js: {$marker}");
    }
}

foreach ([
    '.ai-workbench',
    '.ai-workbench-panel',
    '.ai-panel-head',
    '.ai-dashboard-hero',
    '.ai-dashboard-kpis',
    '.ai-dashboard-main',
    '.ai-dashboard-bottom',
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

if (!str_contains($page, "\$crmAssetBuild = 'ai-center-dashboard-20260802-1';")) {
    throw new RuntimeException('CRM asset build must bust cache for AI center workbench changes');
}

echo "crm_ai_center_workbench_contract: OK\n";
