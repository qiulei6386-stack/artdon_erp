<?php
declare(strict_types=1);

use Artdon\MaterialCenter\Services\MaterialMasterService;

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
    ];
}
$config['category_code']=$category;
$pageTitle=$config['title'];$pageDescription=$config['description'];$activeMenu=$key;
include MC_ROOT.'/components/layout_top.php';
require_once MC_ROOT.'/components/material_workspace.php';
render_material_workspace($config,$rows);
include MC_ROOT.'/components/layout_bottom.php';
