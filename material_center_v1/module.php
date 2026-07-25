<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
$labels=['materials'=>'全部物料','incomplete'=>'待完善资料','pending_maps'=>'待确认映射','duplicates'=>'重复候选','price_changes'=>'最近价格变化','recent_changes'=>'最近修改','chips'=>'芯片','optics'=>'光学','profiles'=>'型材 / 散热件','mounting'=>'接头 / 安装件','accessories'=>'其他配件','packaging'=>'包装','temporary'=>'临时物料','chip_standardize'=>'芯片标准化','optics_standardize'=>'光学标准化','duplicate_clean'=>'重复清洗','field_mapping'=>'字段映射','fit_overview'=>'适配总览','chip_rules'=>'芯片适配规则','optics_rules'=>'光学适配规则','conflicts'=>'适配冲突','suppliers'=>'供应商资料','supplier_materials'=>'供应商物料','prices'=>'采购价管理','price_history'=>'价格历史','moq'=>'MOQ / 交期','alternatives'=>'替代物料','versions'=>'物料版本','changes'=>'变更记录','excel_import'=>'Excel 导入任务','exports'=>'导出任务','sync_logs'=>'同步日志','documents'=>'规格与认证文件','images'=>'图片资料','activity_logs'=>'操作日志','permissions'=>'权限与角色','design_spec'=>'设计规范'];
$pages=[];foreach($labels as$slug=>$label)$pages[$slug]=[$label,$slug,'该模块已纳入正式导航，业务能力尚未接入，不会伪造成功。'];
$key=(string)($_GET['page']??'');if(!isset($pages[$key])){http_response_code(404);$pages[$key]=['页面不存在','', '请求的物料中心页面不存在。'];}
[$title,$active,$message]=$pages[$key];header('Content-Type:text/html;charset=utf-8');mc_page_start($title,$active,mc_current_user());
?><div class="ui-page-head"><div><span class="ui-eyebrow">MATERIAL CENTER</span><h1><?=mc_h($title)?></h1></div></div><?php mc_state('config','该功能尚未接入',$message);mc_page_end();
