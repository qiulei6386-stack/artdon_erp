<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
use Artdon\MaterialCenter\Adapters\LegacyAuthAdapter;
use Artdon\MaterialCenter\Security\PermissionService;
use Artdon\MaterialCenter\Services\PowerWorkbenchService;

$tab=(string)($_GET['tab']??'all');$tabs=['all'=>'全部','source'=>'来源数据','organize'=>'待整理','confirm'=>'待确认','formal'=>'正式','duplicates'=>'重复候选','archived'=>'停用 / 归档'];if(!isset($tabs[$tab]))$tab='all';
$panel=(string)($_GET['panel']??'');$q=trim((string)($_GET['q']??''));$context=(new LegacyAuthAdapter())->current();$user=mc_current_user();$permission=new PermissionService();$status='ready';$errorId='';$rows=[];$formal=[];$source=[];
try{
 if(!$context)$status='permission';elseif(!$permission->allows($context,'material_center.view'))$status='permission';elseif(!mc_table_exists('mc_materials'))$status='config';else{$service=new PowerWorkbenchService();
  if($tab==='source')$rows=$service->source($q);
  elseif(in_array($tab,['organize','confirm','duplicates'],true))$rows=$service->staging($tab,$q);
  elseif(in_array($tab,['formal','archived'],true))$rows=$service->formal($tab,$q);
  else{$source=$service->source($q);$formal=$service->formal('formal',$q);}
 }
}catch(Throwable$e){$status='error';$errorId='PWB-'.date('YmdHis').'-'.substr(hash('sha256',$e->getMessage()),0,6);error_log("[{$errorId}] power_workbench {$tab}: {$e->getMessage()}");}
$csrf=function_exists('csrf_token')?csrf_token():'';header('Content-Type:text/html;charset=utf-8');header('Cache-Control:no-store');mc_page_start('电源工作台','power',$user);
?>
<div class="ui-page-head"><div><span class="ui-eyebrow">POWER · SINGLE WORKBENCH</span><h1>电源</h1><p>来源、整理、确认、正式主数据和归档在同一工作台完成；旧 BOM 始终只读。</p></div><a class="ui-btn" href="materials.php">新建电源</a></div>
<nav class="ui-tabs" aria-label="电源工作台视图"><?php foreach($tabs as$k=>$label):?><a class="ui-tab" aria-selected="<?=$tab===$k?'true':'false'?>" href="?tab=<?=$k?>"><?=mc_h($label)?></a><?php endforeach;?></nav>
<div class="ui-toolbar ui-card"><form method="get" class="ui-toolbar"><input type="hidden" name="tab" value="<?=mc_h($tab)?>"><input class="ui-input" name="q" value="<?=mc_h($q)?>" placeholder="搜索名称、品牌、型号或规格"><button class="ui-btn">搜索</button><a class="ui-btn ui-btn-secondary" href="?tab=<?=mc_h($tab)?>">重置</a></form><div class="ui-dropdown"><button class="ui-btn ui-btn-secondary" data-ui-dropdown-trigger aria-expanded="false">更多</button><div class="ui-menu" role="menu" aria-hidden="true"><a href="?tab=<?=$tab?>&panel=bands">功率档设置</a><a href="?tab=<?=$tab?>&panel=fields">字段设置</a><button data-ui-not-connected>批量导入</button><a href="?tab=<?=$tab?>&panel=export">导出</a><a href="?tab=duplicates">重复检查</a><a href="?tab=<?=$tab?>&panel=mappings">映射记录</a><a href="?tab=<?=$tab?>&panel=logs">操作日志</a><a href="bom_audit.php">BOM源审计</a><a href="product_adaptation.php?tab=power">适配产品</a><button data-ui-not-connected>解析规则</button><button data-ui-not-connected>数据完整度规则</button><button type="button" data-reset-power-view>恢复默认视图</button></div></div><button class="ui-btn ui-btn-secondary" data-ui-table-settings="#power-workbench-table">视图设置</button><button class="ui-btn ui-btn-secondary" type="button" onclick="location.reload()">刷新</button></div>
<?php if($status==='permission'):?><?php mc_state('permission','无权访问电源工作台','需要广州统一账号及物料中心查看权限。');?>
<?php elseif($status==='config'):?><?php mc_state('config','电源工作台尚未配置','请先执行物料中心迁移。');?>
<?php elseif($status==='error'):?><?php mc_state('error','电源数据加载失败',"错误编号 {$errorId}，系统没有写入旧 BOM。","?tab={$tab}",'重试');?>
<?php elseif($panel!==''):?>
  <?php if($panel==='bands'):?><section class="ui-card ui-workbench-embed"><iframe title="功率档设置" src="power_bands.php" onload="this.contentDocument?.body.classList.add('ui-presentation')"></iframe></section>
  <?php elseif($panel==='fields'): $items=(new PowerWorkbenchService())->fields();?><section class="ui-card ui-table-panel"><div class="ui-table-wrap"><table class="ui-table" id="power-workbench-table" data-ui-table><thead><tr><th>字段</th><th>代码</th><th>类型</th><th>存储位置</th><th>批量</th><th>敏感</th><th>状态</th></tr></thead><tbody><?php foreach($items as$r):?><tr><td><?=mc_h($r['label'])?></td><td><?=mc_h($r['field_key'])?></td><td><?=mc_h($r['data_type'])?></td><td><?=mc_h($r['storage_target'])?></td><td><?=$r['is_batch_editable']?'是':'否'?></td><td><?=$r['is_sensitive']?'是':'否'?></td><td><?=mc_h($r['status'])?></td></tr><?php endforeach;?></tbody></table></div></section>
  <?php elseif($panel==='mappings'): $items=(new PowerWorkbenchService())->mappings();?><section class="ui-card ui-table-panel"><div class="ui-table-wrap"><table class="ui-table" id="power-workbench-table" data-ui-table><thead><tr><th>来源</th><th>来源ID</th><th>正式物料ID</th><th>状态</th><th>数据哈希</th><th>更新时间</th></tr></thead><tbody><?php foreach($items as$r):?><tr><td><?=mc_h($r['source_table'])?></td><td><?=$r['source_id']?></td><td><?=$r['material_id']?></td><td><?=mc_h($r['mapping_status']??$r['link_type'])?></td><td><?=mc_h(substr((string)$r['raw_data_hash'],0,16))?>…</td><td><?=mc_h($r['updated_at'])?></td></tr><?php endforeach;?></tbody></table></div></section>
  <?php elseif($panel==='logs'): $items=(new PowerWorkbenchService())->activity();?><section class="ui-card ui-table-panel"><div class="ui-table-wrap"><table class="ui-table" id="power-workbench-table" data-ui-table><thead><tr><th>时间</th><th>对象</th><th>ID</th><th>操作</th><th>操作者</th></tr></thead><tbody><?php foreach($items as$r):?><tr><td><?=mc_h($r['created_at'])?></td><td><?=mc_h($r['entity_type'])?></td><td><?=mc_h($r['entity_id'])?></td><td><?=mc_h($r['action'])?></td><td><?=mc_h($r['actor_id'])?></td></tr><?php endforeach;?></tbody></table></div></section>
  <?php elseif($panel==='export'):?><section class="ui-card ui-card-body"><h2>导出</h2><p>导出必须按当前权限过滤敏感字段。</p><button class="ui-btn" type="button" data-export-power data-tab="<?=mc_h($tab)?>">导出当前结果 CSV</button></section>
  <?php endif;?>
<?php elseif($tab==='all'):?>
  <div class="stats"><article><span>旧 BOM 电源</span><b><?=count($source)?></b></article><article><span>正式结构记录</span><b><?=count($formal)?></b></article><article><span>20–25W试点</span><b><?=mc_table_exists('mc_material_import_staging')?(int)db()->query("SELECT COUNT(*) FROM mc_material_import_staging WHERE is_pilot=1")->fetchColumn():0?></b></article><article><span>数据原则</span><b class="date">旧源只读</b></article></div>
  <?php if(!$source&&!$formal):?><?php mc_state('empty','暂无电源数据','旧源和正式库均无真实记录。');?>
  <?php else:$rows=array_slice(array_merge(array_map(fn($r)=>$r+['_kind'=>'source'],$source),array_map(fn($r)=>$r+['_kind'=>'formal'],$formal)),0,300);require __DIR__.'/views_power_workbench_table.php';endif;?>
<?php elseif(!$rows):?><?php mc_state('empty','当前视图暂无数据','筛选条件下没有真实记录；没有填充假数据。');?>
<?php else:require __DIR__.'/views_power_workbench_table.php';endif;?>
<script>window.MC_POWER_CSRF=<?=json_encode($csrf)?>;</script>
<?php mc_page_end('','assets/js/power-workbench.js');?>
