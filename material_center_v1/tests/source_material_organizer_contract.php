<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$workspace = file_get_contents($root . '/components/material_workspace.php');
$layout = file_get_contents($root . '/components/layout_bottom.php');
$categoryJs = file_get_contents($root . '/assets/js/category-editor.js');
$powerJs = file_get_contents($root . '/assets/js/power-editor.js');
$service = file_get_contents($root . '/app/Services/SourceMaterialOrganizerService.php');
$api = file_get_contents($root . '/api/v1/source-material.php');
$migration = file_get_contents($root . '/database/migrations/20260726_016_source_material_organizing.php');

foreach ([
    "'source'=>'整理'", "'pending_sort'=>'整理'", "'pending_review'=>'确认'", "'draft'=>'编辑'",
    "'official','disabled','archived'=>'查看'", "'abnormal','rejected'=>'处理'", "'duplicate'=>'对比合并'",
] as $marker) {
    if (!str_contains($workspace, $marker)) {
        throw new RuntimeException("row action status missing: {$marker}");
    }
}
if (str_contains($workspace, ">设置</button>") || str_contains($workspace, '>查看来源</button>')) {
    throw new RuntimeException('legacy source primary actions still exist');
}
foreach (['新建仅用于 BOM 中不存在的全新物料', 'data-organize-source'] as $marker) {
    if (!str_contains($workspace, $marker)) {
        throw new RuntimeException("manual/source purpose marker missing: {$marker}");
    }
}
foreach (["'chip'", "'optical'", "'profile'", "'connector'", "'accessories'", "'packaging'"] as $menu) {
    if (!str_contains($layout, $menu)) {
        throw new RuntimeException("category organizing drawer missing: {$menu}");
    }
}
foreach (['整理字段', '原始来源', '保存草稿', '提交确认', '确认并转正式', 'data-category-source-snapshot', 'data-power-source-snapshot'] as $marker) {
    if (!str_contains($layout, $marker)) {
        throw new RuntimeException("organizing drawer marker missing: {$marker}");
    }
}
foreach (['source-material.php', 'source_record_id', 'sourceDetail', 'parse_result', 'changed'] as $marker) {
    if (!str_contains($categoryJs . $powerJs, $marker)) {
        throw new RuntimeException("source editor integration missing: {$marker}");
    }
}
foreach (['source_system', 'source_table', 'source_pk', 'FOR UPDATE', 'mc_source_mappings', 'source_snapshot_hash', "status='confirmed'", 'legacy_bom'] as $marker) {
    if (!str_contains($service, $marker)) {
        throw new RuntimeException("source idempotency service marker missing: {$marker}");
    }
}
foreach (['material_center.approve', 'material_center.material.create', 'material_center.material.edit'] as $permission) {
    if (!str_contains($api, $permission)) {
        throw new RuntimeException("server permission check missing: {$permission}");
    }
}
foreach (['uq_mc_source_mapping_record', 'supplier_text', 'series_name', 'compatible_les', 'is_focusable', 'die_file_text'] as $marker) {
    if (!str_contains($migration, $marker)) {
        throw new RuntimeException("migration field missing: {$marker}");
    }
}

echo "Source material organizer contract: OK\n";
