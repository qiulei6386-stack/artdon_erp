<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
$map=['chips'=>['芯片','chips'],'optics'=>['光学','optics'],'profiles'=>['型材 / 散热件','profiles'],'mounting'=>['接头 / 安装件','mounting'],'accessories'=>['配件','accessories'],'packaging'=>['包装','packaging']];
$key=(string)($_GET['category']??'');if(!isset($map[$key])){http_response_code(404);$map[$key]=['类别不存在',''];}[$title,$active]=$map[$key];mc_page_start($title,$active,mc_current_user());
?><div class="ui-page-head"><div><span class="ui-eyebrow">V3 · CATEGORY WORKBENCH</span><h1><?=mc_h($title)?></h1><p>一个类别一个入口；来源、整理、确认、正式、重复和归档将在本页统一完成。</p></div><button class="ui-btn" data-ui-not-connected>新建<?=mc_h($title)?></button></div>
<div class="ui-tabs" role="tablist"><?php foreach(['全部','待整理','待确认','正式','重复候选','停用 / 归档']as$i=>$tab):?><button class="ui-tab" aria-selected="<?=$i===0?'true':'false'?>"><?=mc_h($tab)?></button><?php endforeach;?></div>
<?php mc_state('config','该类别尚未接入真实领域数据','统一工作台框架已建立；不会复制电源或旧 BOM 数据冒充该类别。');mc_page_end();
