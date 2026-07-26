<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$repository=file_get_contents($root.'/app/Repositories/ConfigurationRepository.php');
$engine=file_get_contents($root.'/app/Services/ConfigurationEngineService.php');
$script=file_get_contents($root.'/assets/js/quote_center.js');

foreach(["g.status='approved'","g.is_enabled=1","o.status='approved'","m.status='official'","m.is_official=1","m.allow_quote=1","p.legacy_table='naming_models'","gx.status<>'approved'","gx.is_enabled=0","ox.status<>'approved'"]as$gate){
    if(!str_contains($repository,$gate))throw new RuntimeException("material-center read gate missing: {$gate}");
}
foreach(['materialCenterAdaptations','source'=>'material_center',"'material_center'=>",'configurationGroups(productKey)','物料中心已审批']as$marker){
    if(!str_contains($repository.$engine.$script,$marker))throw new RuntimeException("commercial material-center bridge missing: {$marker}");
}
$writeMarkers=['INSERT'.' INTO mc_','UPDATE'.' mc_','DELETE'.' FROM mc_'];
foreach($writeMarkers as$writeMarker)if(str_contains($repository,$writeMarker))throw new RuntimeException('commercial center bridge must remain read-only');
echo "Commercial center approved-adaptation read bridge contract: OK\n";
