<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$routes=['index.php','materials.php','power_workbench.php','power_supplies.php','power_standardization.php','formal_power_supplies.php','power_bands.php','bom_audit.php','category_workbench.php','product_adaptation.php','product_power_rules.php','power_match_simulator.php','settings.php','system_status.php','ui-gallery.php'];
foreach($routes as$route)if(!is_file($root.'/'.$route)){fwrite(STDERR,"missing route {$route}\n");exit(1);}
$nav=file_get_contents($root.'/app/Support/helpers.php');
foreach(['power_workbench.php','category_workbench.php?category=chips','category_workbench.php?category=optics','category_workbench.php?category=accessories','category_workbench.php?category=packaging','product_adaptation.php']as$route)if(!str_contains($nav,$route)){fwrite(STDERR,"navigation missing {$route}\n");exit(1);}
$workbench=file_get_contents($root.'/power_workbench.php').file_get_contents($root.'/views_power_workbench_table.php');
foreach(['power_bands.php','power_standardization.php']as$route)if(!str_contains($workbench,$route)){fwrite(STDERR,"power mapping missing {$route}\n");exit(1);}
$compat=file_get_contents($root.'/category_workbench.php').file_get_contents($root.'/product_adaptation.php').file_get_contents($root.'/module.php');
foreach(['material/chip.php','adaptation/index.php','supplier/index.php','substitute/index.php','data/index.php','documents/index.php','settings/index.php']as$route)if(!str_contains($compat,$route)){fwrite(STDERR,"compatibility redirect missing {$route}\n");exit(1);}
echo "Route mapping contract passed: old routes preserved and redirected to current business pages.\n";
