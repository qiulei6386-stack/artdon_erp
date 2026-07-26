<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$layout=file_get_contents($root.'/components/layout_bottom.php');
$workspace=file_get_contents($root.'/components/material_workspace.php');
$page=file_get_contents($root.'/material/_page.php');
$script=file_get_contents($root.'/assets/js/category-editor.js');
$service=file_get_contents($root.'/app/Services/CategoryFieldService.php');

foreach(["'chip'","'optical'","'connector'","'accessories'"]as$menu){
    if(!str_contains($layout,$menu))throw new RuntimeException("category drawer menu missing: {$menu}");
}
foreach(['data-category-editor','data-category-editor-fields','data-category-save','data-category-submit','data-category-reference']as$marker){
    if(!str_contains($layout.$script,$marker))throw new RuntimeException("category drawer marker missing: {$marker}");
}
foreach(['api/v1/category-fields.php','api/v1/material-master.php','data-open-category-editor','raw_status']as$marker){
    if(!str_contains($workspace.$page.$script,$marker))throw new RuntimeException("category editor integration missing: {$marker}");
}
if(str_contains($service,'if($value===null)continue'))throw new RuntimeException('optional category fields cannot be cleared');
echo "Category field drawer contract: OK\n";
