<?php
declare(strict_types=1);

use Artdon\MaterialCenter\Services\MaterialMasterService;
use Artdon\MaterialCenter\Services\SourceSyncService;

require_once dirname(__DIR__).'/bootstrap.php';
$pages = require MC_ROOT.'/config/material_pages.php';
$key = $materialPageKey ?? 'all';
$config = $pages[$key] ?? $pages['all'];
$categoryMap = [
    'power'=>'power_supply','chip'=>'chip','optical'=>'optical','profile'=>'profile',
    'connector'=>'connector','accessories'=>'accessory','packaging'=>'packaging',
];
$category = $categoryMap[$key] ?? '';
$query = trim((string)($_GET['q'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$records = (new MaterialMasterService())->rows($query, $category, $status);
$rows = [];
foreach ($records as $record) {
    $spec = trim((string)($record['spec_summary'] ?? ''));
    if ($spec === '') {
        $spec = implode(' · ', array_filter([(string)($record['brand'] ?? ''),(string)($record['model'] ?? '')]));
    }
    $rows[] = [
        (string)$record['material_code'], (string)$record['name'], (string)($record['brand'] ?? ''),
        (string)($record['model'] ?? ''), $spec ?: '—', (string)$record['status'],
        (string)($record['source'] ?? 'material_center'), (string)($record['supplier_warranty_years'] ?? ''),
        'id'=>(int)$record['id'],
        'category_id'=>(int)$record['category_id'],
        'lock_version'=>(int)($record['lock_version']??1),
        'read_only'=>false,
    ];
}
foreach((new SourceSyncService())->materialRows($category) as$record){
    $spec=trim((string)($record['spec']??''));
    if($spec==='')$spec=implode(' · ',array_filter([(string)($record['legacy_category']??''),(string)($record['brand']??''),(string)($record['model']??'')]));
    $rows[]=[
        'BOM-'.(string)$record['source_pk'],
        (string)($record['material_name']??''),
        (string)($record['brand']??''),
        (string)($record['model']??''),
        $spec?:'—',
        $record['status']==='changed'?'异常':'待整理',
        '旧 BOM（只读）',
        '',
        'id'=>0,
        'source_record_id'=>(int)$record['source_record_id'],
        'category_id'=>0,
        'lock_version'=>0,
        'read_only'=>true,
    ];
}
$config['category_code']=$category;
$pageTitle=$config['title'];$pageDescription=$config['description'];$activeMenu=$key;
include MC_ROOT.'/components/layout_top.php';
require_once MC_ROOT.'/components/material_workspace.php';
render_material_workspace($config,$rows);
include MC_ROOT.'/components/layout_bottom.php';
