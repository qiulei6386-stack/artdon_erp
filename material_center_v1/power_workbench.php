<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
use Artdon\MaterialCenter\Services\PowerSupplyReadService;use Artdon\MaterialCenter\Services\PowerStandardizationService;
$tab=(string)($_GET['tab']??'all');$allowed=['all','source','organize','confirm','formal','duplicates','archived','bands'];if(!in_array($tab,$allowed,true))$tab='all';
$user=mc_current_user();$ready=mc_table_exists('mc_materials');$standard=$user&&$ready?new PowerStandardizationService():null;$rows=[];
if($tab==='source')$rows=(new PowerSupplyReadService())->view(trim((string)($_GET['q']??'')))['rows']??[];
elseif(in_array($tab,['organize','confirm','duplicates'],true))$rows=$standard?->workbenchRows()??[];
elseif(in_array($tab,['all','formal','archived'],true))$rows=$standard?->formalRows(trim((string)($_GET['q']??'')))??[];
$tabs=['all'=>'全部','source'=>'来源数据','organize'=>'待整理','confirm'=>'待确认','formal'=>'正式','duplicates'=>'重复候选','archived'=>'停用 / 归档'];
$legacyPanels=['source'=>'power_supplies.php','confirm'=>'power_standardization.php','formal'=>'formal_power_supplies.php','bands'=>'power_bands.php'];
header('Content-Type:text/html;charset=utf-8');mc_page_start('电源工作台','power',$user);
?><div class="ui-page-head"><div><span class="ui-eyebrow">V3 · POWER WORKBENCH</span><h1>电源</h1><p>来源、标准化、正式库、重复、字段和功率档集中在一个业务对象入口。</p></div><a class="ui-btn" href="materials.php">新建电源</a></div>
<nav class="ui-tabs"><?php foreach($tabs as$k=>$label):?><a class="ui-tab" aria-selected="<?=$tab===$k?'true':'false'?>" href="?tab=<?=$k?>"><?=mc_h($label)?></a><?php endforeach;?></nav>
<div class="ui-toolbar ui-card"><form method="get" class="ui-toolbar"><input type="hidden" name="tab" value="<?=mc_h($tab)?>"><input class="ui-input" name="q" value="<?=mc_h($_GET['q']??'')?>" placeholder="搜索电源"><button class="ui-btn">搜索</button></form><div class="ui-dropdown"><button class="ui-btn ui-btn-secondary" data-ui-dropdown-trigger>更多</button><div class="ui-menu" role="menu" aria-hidden="true"><a href="?tab=bands">功率档设置</a><a href="module.php?page=field_mapping">字段设置</a><a href="bom_audit.php">BOM源审计</a><a href="module.php?page=activity_logs">操作日志</a><a href="product_adaptation.php?tab=power">适配产品</a></div></div><button class="ui-btn ui-btn-secondary" data-ui-table-settings="#power-workbench-table">视图设置</button><button class="ui-btn ui-btn-secondary" onclick="location.reload()">刷新</button></div>
<?php if(!$user):?><?php mc_state('permission','需要统一登录','复用广州 ERP 统一账号。');?>
<?php elseif(isset($legacyPanels[$tab])):?><section class="ui-card ui-workbench-embed"><iframe title="<?=mc_h($tabs[$tab]??'功率档设置')?>" src="<?=mc_h($legacyPanels[$tab])?>" onload="this.contentDocument?.body.classList.add('ui-presentation')"></iframe></section>
<?php elseif(!$rows):?><?php mc_state('empty','当前视图暂无数据','没有写死或伪造电源数据。');?>
<?php else:?><section class="ui-card ui-table-panel"><div class="ui-table-wrap"><table class="ui-table" id="power-workbench-table" data-ui-table><thead><tr><th data-sort>ID / 编号</th><th data-sort>名称</th><th data-sort>品牌</th><th data-sort>型号</th><th>规格 / 状态</th><th class="ui-action-col">操作</th></tr></thead><tbody><?php foreach($rows as$r):?><tr><td><?=mc_h($r['source_id']??$r['material_code']??$r['id']??'')?></td><td><?=mc_h($r['raw_name']??$r['name']??'')?></td><td><?=mc_h($r['raw_brand']??$r['brand']??'')?></td><td><?=mc_h($r['raw_model']??$r['model']??'')?></td><td class="ui-cell-wrap"><?=mc_h($r['raw_spec']??$r['mapping_status']??$r['status']??'')?></td><td class="ui-action-col"><?php if(isset($r['source_id'])):?><a class="ui-link-button" href="power_standardization.php">人工确认</a><?php else:?><a class="ui-link-button" href="materials.php">管理</a><?php endif;?></td></tr><?php endforeach;?></tbody></table></div></section><?php endif;mc_page_end();?>
