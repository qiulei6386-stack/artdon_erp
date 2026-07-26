<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$page=file_get_contents($root.'/adaptation/index.php');
$script=file_get_contents($root.'/assets/js/adaptation-shell.js');
$service=file_get_contents($root.'/app/Services/AdaptationService.php');
$api=file_get_contents($root.'/api/v1/adaptation.php');

foreach(['产品列表','配置规则','选项详情','芯片 / 光源','电源 / 驱动','调光方式','光学 / 透镜','附件配件','外观颜色','安装方式','特殊要求','自定义配置组']as$label){
    if(!str_contains($page.$service,$label))throw new RuntimeException("adaptation business group missing: {$label}");
}
foreach(['data-adaptation-tab="options"','data-adaptation-tab="default"','data-adaptation-tab="alternative"','data-adaptation-tab="conditions"','data-adaptation-tab="impact"','data-adaptation-tab="approval"']as$marker){
    if(!str_contains($page,$marker))throw new RuntimeException("adaptation detail tab missing: {$marker}");
}
foreach(['initialize_groups','conditions_json','condition_failure_message','price_impact','lead_time_impact_days','approveProduct']as$marker){
    if(!str_contains($page.$script.$service.$api,$marker))throw new RuntimeException("adaptation workflow missing: {$marker}");
}
foreach(["m.status='official'","m.is_official=1",'至少添加一个正式物料选项后才能审批']as$gate){
    if(!str_contains($service,$gate))throw new RuntimeException("adaptation approval gate missing: {$gate}");
}
foreach(['powerCompatibilityReasons','最大功率','输出电流','输出电压','超过灯体空间','供应商质保','调光方式','认证']as$marker){
    if(!str_contains($service,$marker))throw new RuntimeException("power adaptation check missing: {$marker}");
}
echo "Product adaptation three-column workflow contract: OK\n";
