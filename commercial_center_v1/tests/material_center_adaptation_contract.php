<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$repository=file_get_contents($root.'/app/Repositories/ConfigurationRepository.php');
$engine=file_get_contents($root.'/app/Services/ConfigurationEngineService.php');
$script=file_get_contents($root.'/assets/js/quote_center.js');

foreach(["g.status='approved'","o.status='approved'","m.status='official'","m.is_official=1","m.allow_quote=1","p.legacy_table='naming_models'","gx.status<>'approved'","ox.status<>'approved'"]as$gate){
    if(!str_contains($repository,$gate))throw new RuntimeException("material-center read gate missing: {$gate}");
}
foreach(['materialCenterAdaptations','source'=>'material_center',"'material_center'=>",'configurationGroups(productKey)','物料中心已审批']as$marker){
    if(!str_contains($repository.$engine.$script,$marker))throw new RuntimeException("commercial material-center bridge missing: {$marker}");
}
if(str_contains($repository,'INSERT INTO mc_')||str_contains($repository,'UPDATE mc_')||str_contains($repository,'DELETE FROM mc_')){
    throw new RuntimeException('commercial center bridge must remain read-only');
}
echo "Commercial center approved-adaptation read bridge contract: OK\n";
