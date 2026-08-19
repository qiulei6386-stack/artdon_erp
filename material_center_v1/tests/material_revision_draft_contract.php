<?php
declare(strict_types=1);

$root = dirname(__DIR__);

$service = file_get_contents($root . '/app/Services/MaterialMasterService.php');
foreach ([
    'revisionDraft',
    'cloneDraft',
    "source='material_revision'",
    "source_type='revision'",
    'source_snapshot_json',
    '只有正式物料可以生成修订草稿',
    'revision_draft_created',
    'revision_draft_source',
] as $marker) {
    if (!str_contains($service, $marker)) {
        fwrite(STDERR, "MaterialMasterService missing revision marker: {$marker}\n");
        exit(1);
    }
}

$api = file_get_contents($root . '/api/v1/material-master.php');
foreach ([
    "\$action==='revision_draft'",
    'revisionDraft',
    '已生成修订草稿',
] as $marker) {
    if (!str_contains($api, $marker)) {
        fwrite(STDERR, "material-master api missing revision marker: {$marker}\n");
        exit(1);
    }
}

$materials = file_get_contents($root . '/materials.php');
foreach (['revision_draft', '生成修订草稿'] as $marker) {
    if (!str_contains($materials, $marker)) {
        fwrite(STDERR, "materials list missing revision marker: {$marker}\n");
        exit(1);
    }
}

$layout = file_get_contents($root . '/components/layout_bottom.php');
foreach (['data-material-revision', 'data-category-revision', 'data-power-revision', '生成修订草稿'] as $marker) {
    if (!str_contains($layout, $marker)) {
        fwrite(STDERR, "layout missing revision marker: {$marker}\n");
        exit(1);
    }
}

foreach ([
    '/assets/js/materials.js' => ['revision_draft', '旧正式物料不会被修改'],
    '/assets/js/material-workspace-actions.js' => ['data-material-revision', 'revision_draft'],
    '/assets/js/category-editor.js' => ['data-category-revision', 'revision_draft'],
    '/assets/js/power-editor.js' => ['data-power-revision', 'revision_draft', '旧正式电源不会被修改'],
] as $file => $markers) {
    $text = file_get_contents($root . $file);
    foreach ($markers as $marker) {
        if (!str_contains($text, $marker)) {
            fwrite(STDERR, "{$file} missing revision marker: {$marker}\n");
            exit(1);
        }
    }
}

echo "material revision draft contract: OK\n";
