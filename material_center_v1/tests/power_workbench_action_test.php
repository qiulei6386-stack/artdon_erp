<?php
declare(strict_types=1);$root=dirname(__DIR__);$combined=file_get_contents($root.'/power_workbench.php').file_get_contents($root.'/assets/js/power-workbench.js');
foreach(['data-ui-table-settings','data-ui-not-connected','data-export-power','data-reset-power-view','onclick="location.reload()"']as$key)if(!str_contains($combined,$key)){fwrite(STDERR,"unbound action {$key}\n");exit(1);}
echo "Power workbench action binding test passed.\n";
