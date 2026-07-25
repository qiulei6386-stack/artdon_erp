<?php
$basic=[['code','物料编号','112px'],['name','名称','210px'],['brand','品牌','120px'],['model','型号','190px'],['spec','关键规格','minmax(320px,2fr)'],['status','状态','96px'],['source','来源','130px']];
return [
'all'=>['title'=>'全部物料','description'=>'统一查看全部来源和全部状态的物料主数据。','columns'=>$basic],
'power'=>['title'=>'电源','description'=>'管理电源来源、整理、确认、正式物料和异常记录。','columns'=>[['code','来源 / 编号','120px'],['name','名称','210px'],['brand','品牌','110px'],['model','型号','210px'],['spec','关键规格','minmax(330px,2fr)'],['warranty','质保','86px'],['status','状态','96px']]],
'chip'=>['title'=>'芯片','description'=>'维护芯片品牌、型号、功率、色温、显指和光效。','columns'=>[['code','物料编号','112px'],['name','名称','210px'],['brand','品牌','120px'],['model','型号','190px'],['spec','关键规格','minmax(330px,2fr)'],['warranty','质保','86px'],['status','状态','96px']]],
'optical'=>['title'=>'光学','description'=>'维护透镜、反光杯、柔光片和可调焦光学模组。','columns'=>$basic],
'profile'=>['title'=>'型材 / 散热件','description'=>'维护型材、散热器、灯体结构件和表面处理资料。','columns'=>$basic],
'connector'=>['title'=>'接头 / 安装件','description'=>'维护轨道接头、磁吸接头、吊线和安装结构件。','columns'=>$basic],
'accessories'=>['title'=>'配件','description'=>'统一管理蜂巢网、玻璃、四叶片、防眩圈和滤色片。','columns'=>$basic],
'packaging'=>['title'=>'包装','description'=>'维护彩盒、外箱、内托、标签和客户专属包装资料。','columns'=>$basic]
];
