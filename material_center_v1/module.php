<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
$pages=['materials'=>['通用物料库','materials','F6 数据结构已建立；通用编辑流程将在后续完整里程碑接入。'],'categories'=>['物料分类','categories','分类结构已建立；分类维护界面尚未接入。'],'suppliers'=>['供应商资料','suppliers','供应商结构已建立；供应商维护界面尚未接入。'],'activity_logs'=>['活动日志','logs','日志持续记录；可视化查询界面尚未接入。'],'permissions'=>['权限说明','permissions','物料中心复用广州统一账号，所有写操作在服务端验证权限；授权维护由系统管理员执行。']];
$key=(string)($_GET['page']??'');if(!isset($pages[$key])){http_response_code(404);$pages[$key]=['页面不存在','', '请求的物料中心页面不存在。'];}
[$title,$active,$message]=$pages[$key];header('Content-Type:text/html;charset=utf-8');mc_page_start($title,$active,mc_current_user());
?><div class="ui-page-head"><div><span class="ui-eyebrow">MATERIAL CENTER</span><h1><?=mc_h($title)?></h1></div></div><?php mc_state('config','该功能尚未接入',$message);mc_page_end();
