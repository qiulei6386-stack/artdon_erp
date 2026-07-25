<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$page=file_get_contents($root.'/power_workbench.php');
foreach(['source'=>"'all','legacy_bom'","duplicates"=>"'exception','','duplicate'","archived"=>"'exception','','archived'"] as $old=>$contract){
    if(strpos($page,"'{$old}'")===false||strpos($page,$contract)===false){fwrite(STDERR,"legacy mapping missing: {$old}\n");exit(1);}
}
foreach(['power_supplies.php','power_standardization.php','formal_power_supplies.php','power_bands.php'] as $url){
    if(!is_file($root.'/'.$url)){fwrite(STDERR,"legacy URL missing: {$url}\n");exit(1);}
}
echo "Power UI route test passed; legacy URL mappings=7.\n";
