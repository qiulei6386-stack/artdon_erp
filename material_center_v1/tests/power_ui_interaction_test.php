<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$page=file_get_contents($root.'/power_workbench.php');
$view=file_get_contents($root.'/views_power_workbench_table.php');
$js=file_get_contents($root.'/assets/js/power-workbench.js');
$table=file_get_contents($root.'/ui/js/table.js');
foreach(['data-power-filter-toggle','data-ui-table-settings','data-power-refresh','data-ui-dropdown-trigger','power-record-drawer'] as$marker)if(strpos($page,$marker)===false){fwrite(STDERR,"page interaction missing: {$marker}\n");exit(1);}
foreach(['data-power-row','data-power-view','查看'] as$marker)if(strpos($view,$marker)===false){fwrite(STDERR,"row interaction missing: {$marker}\n");exit(1);}
if(strpos($view,'进入标准化</a>')!==false){fwrite(STDERR,"duplicate standardization row link remains\n");exit(1);}
foreach(['ResizeObserver','requestAnimationFrame','120','data-jump','data-page-size'] as$marker)if(strpos($table,$marker)===false){fwrite(STDERR,"adaptive pagination missing: {$marker}\n");exit(1);}
foreach(['300','ArtdonUI.drawer.open','ui:selection','exportRows'] as$marker)if(strpos($js,$marker)===false){fwrite(STDERR,"workbench interaction missing: {$marker}\n");exit(1);}
echo "Power UI interaction test passed.\n";
