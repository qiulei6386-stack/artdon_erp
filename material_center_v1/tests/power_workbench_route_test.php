<?php
declare(strict_types=1);
$root=dirname(__DIR__);$page=file_get_contents($root.'/power_workbench.php');
foreach(['all','source','organize','confirm','formal','duplicates','archived']as$tab)if(!str_contains($page,"'{$tab}'")){fwrite(STDERR,"missing tab {$tab}\n");exit(1);}
foreach(['power_supplies.php','power_standardization.php','formal_power_supplies.php','power_bands.php']as$old)if(!is_file($root.'/'.$old)){fwrite(STDERR,"missing legacy URL {$old}\n");exit(1);}
echo "Power workbench route test passed.\n";
