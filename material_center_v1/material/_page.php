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
$statusLabels = [
    'draft'=>'草稿','temporary'=>'临时物料','pending_sort'=>'待整理','pending_review'=>'待确认',
    'official'=>'正式','rejected'=>'驳回','duplicate'=>'重复候选','abnormal'=>'异常',
    'disabled'=>'停用','archived'=>'归档',
];
foreach ($records as $record) {
    $spec = trim((string)($record['spec_summary'] ?? ''));
    if ($spec === '') {
        $spec = implode(' · ', array_filter([(string)($record['brand'] ?? ''),(string)($record['model'] ?? '')]));
    }
    $rows[] = [
        (string)$record['material_code'], (string)$record['name'], (string)($record['brand'] ?? ''),
        (string)($record['model'] ?? ''), $spec ?: '—', (string)($statusLabels[$record['status']]??$record['status']),
        (string)($record['source'] ?? 'material_center'), (string)($record['supplier_warranty_years'] ?? ''),
        'id'=>(int)$record['id'],
        'category_id'=>(int)$record['category_id'],
        'source_record_id'=>(int)($record['source_record_id']??0),
        'raw_status'=>(string)$record['status'],
        'lock_version'=>(int)($record['lock_version']??1),
        'unit'=>(string)($record['unit']??'PCS'),
        'supplier_text'=>(string)($record['supplier_text']??''),
        'remark'=>(string)($record['remark']??''),
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
        'organize_url'=>mc_url('material/'.([
            '驱动'=>'power.php','电源'=>'power.php','芯片'=>'chip.php','光学'=>'optical.php',
            '型材'=>'profile.php','外壳'=>'profile.php','接头'=>'connector.php',
            '附件'=>'accessories.php','包装'=>'packaging.php',
        ][(string)($record['legacy_category']??'')]??'all.php').'?organize_source='.(int)$record['source_record_id']),
        'category_id'=>0,
        'raw_status'=>$record['status']==='changed'?'source_changed':'source',
        'lock_version'=>0,
        'unit'=>(string)($record['unit']??'PCS'),
        'read_only'=>true,
    ];
}
$config['category_code']=$category;
$pageTitle=$config['title'];$pageDescription=$config['description'];$activeMenu=$key;
include MC_ROOT.'/components/layout_top.php';
require_once MC_ROOT.'/components/material_workspace.php';
render_material_workspace($config,$rows);
include MC_ROOT.'/components/layout_bottom.php';
