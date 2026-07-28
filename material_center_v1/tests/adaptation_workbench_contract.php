<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$page=file_get_contents($root.'/adaptation/index.php');
$script=file_get_contents($root.'/assets/js/adaptation-shell.js');
$css=file_get_contents($root.'/assets/css/app.css');
$service=file_get_contents($root.'/app/Services/AdaptationService.php');
$api=file_get_contents($root.'/api/v1/adaptation.php');

foreach(['产品列表','配置规则','选项详情','芯片 / 光源','电源 / 驱动','调光方式','光学 / 透镜','附件配件','外观颜色','安装方式','特殊要求','自定义配置组']as$label){
    if(!str_contains($page.$service,$label))throw new RuntimeException("adaptation business group missing: {$label}");
}
foreach(['data-adaptation-tab="options"','data-adaptation-tab="default"','data-adaptation-tab="alternative"','data-adaptation-tab="conditions"','data-adaptation-tab="impact"','data-adaptation-tab="approval"']as$marker){
    if(!str_contains($page,$marker))throw new RuntimeException("adaptation detail tab missing: {$marker}");
}
foreach(['initialize_groups','save_conditions','expected_json','failure_message','price_impact','lead_time_impact_days','approveProduct']as$marker){
    if(!str_contains($page.$script.$service.$api,$marker))throw new RuntimeException("adaptation workflow missing: {$marker}");
}
foreach(["m.status='official'","m.is_official=1",'必选组尚未添加选项','暂不能提交审批：']as$gate){
    if(!str_contains($service,$gate))throw new RuntimeException("adaptation approval gate missing: {$gate}");
}
foreach(['powerCompatibilityReasons','comparePower','功率超出产品允许范围','输出电流高于芯片允许值或范围不相交','输出电压范围不匹配','超过灯体内部空间','supplier_warranty_years','调光方式不匹配','certification_required']as$marker){
    if(!str_contains($service,$marker))throw new RuntimeException("power adaptation check missing: {$marker}");
}
foreach(['Start with a real product catalogue','grid-template-columns:repeat(auto-fill,minmax(305px,1fr))','mc-product-row{min-height:94px','mc-page--adaptation-v2 .mc-adaptation-workspace{grid-template-columns:250px minmax(620px,1fr) minmax(310px,360px)}'] as $marker){
    if(!str_contains($css,$marker))throw new RuntimeException("adaptation progressive layout marker missing: {$marker}");
}
echo "Product adaptation three-column workflow contract: OK\n";
