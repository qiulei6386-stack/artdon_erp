<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';

use Artdon\MaterialCenter\Adapters\LegacyAuthAdapter;
use Artdon\MaterialCenter\Security\PermissionService;
use Artdon\MaterialCenter\Services\PowerWorkbenchService;

$legacyTab=(string)($_GET['tab']??'all');
$tabMap=[
    'source'=>['all','legacy_bom',''],
    'duplicates'=>['exception','','duplicate'],
    'archived'=>['exception','','archived'],
];
$tab=$legacyTab;
$sourceFilter=trim((string)($_GET['source']??''));
$exceptionFilter=trim((string)($_GET['exception']??''));
if(isset($tabMap[$legacyTab])){
    [$tab,$mappedSource,$mappedException]=$tabMap[$legacyTab];
    $sourceFilter=$sourceFilter!==''?$sourceFilter:$mappedSource;
    $exceptionFilter=$exceptionFilter!==''?$exceptionFilter:$mappedException;
}
$tabs=['all'=>'全部','organize'=>'待整理','confirm'=>'待确认','formal'=>'正式','exception'=>'异常'];
if(!isset($tabs[$tab]))$tab='all';

$panel=(string)($_GET['panel']??'');
$q=trim((string)($_GET['q']??''));
$context=(new LegacyAuthAdapter())->current();
$user=mc_current_user();
$permission=new PermissionService();
$status='ready';$errorId='';$rows=[];

try{
    if(!$context||!$permission->allows($context,'material_center.view'))$status='permission';
    elseif(!mc_table_exists('mc_materials'))$status='config';
    else{
        $service=new PowerWorkbenchService();
        if($panel===''){
            if($tab==='organize')$rows=$service->staging('organize',$q);
            elseif($tab==='confirm')$rows=$service->staging('confirm',$q);
            elseif($tab==='formal')$rows=$service->formal('formal',$q);
            elseif($tab==='exception'){
                $rows=array_merge(
                    array_map(static fn(array$r):array=>$r+['_kind'=>'source'], $service->staging('duplicates',$q)),
                    array_map(static fn(array$r):array=>$r+['_kind'=>'formal'], $service->formal('archived',$q))
                );
                if($exceptionFilter==='duplicate')$rows=array_values(array_filter($rows,static fn(array$r):bool=>(int)($r['duplicate_risk']??0)>0));
                elseif(in_array($exceptionFilter,['disabled','archived'],true))$rows=array_values(array_filter($rows,static fn(array$r):bool=>($r['status']??'')===$exceptionFilter));
            }else{
                $source=array_map(static fn(array$r):array=>$r+['_kind'=>'source'],$service->source($q));
                $formal=array_map(static fn(array$r):array=>$r+['_kind'=>'formal'],$service->formal('all',$q));
                $rows=array_merge($source,$formal);
            }
            if($sourceFilter==='legacy_bom')$rows=array_values(array_filter($rows,static fn(array$r):bool=>(($r['_kind']??'')==='source')||isset($r['source_id'])));
            elseif($sourceFilter==='material_center')$rows=array_values(array_filter($rows,static fn(array$r):bool=>(($r['_kind']??'')==='formal')));
        }
    }
}catch(Throwable$e){
    $status='error';
    $errorId='PWB-'.date('YmdHis').'-'.substr(hash('sha256',$e->getMessage()),0,6);
    error_log("[{$errorId}] power_workbench {$tab}: {$e->getMessage()}");
}

$queryBase=['tab'=>$tab];
if($q!=='')$queryBase['q']=$q;
if($sourceFilter!=='')$queryBase['source']=$sourceFilter;
if($exceptionFilter!=='')$queryBase['exception']=$exceptionFilter;
$csrf=function_exists('csrf_token')?csrf_token():'';
header('Content-Type:text/html;charset=utf-8');
header('Cache-Control:no-store');
mc_page_start('电源','power',$user);
?>
<div class="power-workbench-shell" data-power-workbench>
  <header class="power-page-head">
    <div><h1>电源</h1><p>管理来源数据、整理、确认、正式物料和异常记录。</p></div>
    <a class="ui-btn" href="materials.php?action=create&category=power_supply">新建电源</a>
  </header>

  <nav class="ui-tabs power-tabs" aria-label="电源工作台视图">
    <?php foreach($tabs as$k=>$label):$params=['tab'=>$k];?>
      <a class="ui-tab" aria-selected="<?=$tab===$k?'true':'false'?>" href="?<?=mc_h(http_build_query($params))?>"><?=mc_h($label)?></a>
    <?php endforeach;?>
  </nav>

  <section class="power-toolbar" aria-label="电源工具栏">
    <form method="get" class="power-search" data-power-search-form>
      <input type="hidden" name="tab" value="<?=mc_h($tab)?>">
      <?php if($sourceFilter!==''):?><input type="hidden" name="source" value="<?=mc_h($sourceFilter)?>"><?php endif;?>
      <?php if($exceptionFilter!==''):?><input type="hidden" name="exception" value="<?=mc_h($exceptionFilter)?>"><?php endif;?>
      <label class="search-field"><span class="sr-only">搜索电源</span><input class="ui-input" name="q" value="<?=mc_h($q)?>" placeholder="搜索品牌、型号、名称或规格" autocomplete="off"><button type="button" class="search-clear" data-power-search-clear aria-label="清空搜索" <?=$q===''?'hidden':''?>>×</button></label>
    </form>
    <button class="ui-btn ui-btn-secondary" type="button" data-power-filter-toggle aria-expanded="false">筛选</button>
    <div class="ui-dropdown"><button class="ui-btn ui-btn-secondary" type="button" data-ui-dropdown-trigger aria-expanded="false">更多</button>
      <div class="ui-menu" role="menu" aria-hidden="true">
        <a href="?<?=mc_h(http_build_query($queryBase+['panel'=>'bands']))?>">功率档设置</a>
        <a href="?<?=mc_h(http_build_query($queryBase+['panel'=>'fields']))?>">字段设置</a>
        <a href="data/index.php">批量导入</a>
        <a href="?<?=mc_h(http_build_query($queryBase+['panel'=>'export']))?>">导出</a>
        <a href="?tab=exception&exception=duplicate">重复检查</a>
        <a href="?<?=mc_h(http_build_query($queryBase+['panel'=>'mappings']))?>">映射记录</a>
        <a href="?<?=mc_h(http_build_query($queryBase+['panel'=>'logs']))?>">操作日志</a>
        <a href="power_standardization.php">解析规则</a>
        <button type="button" data-reset-power-view>恢复默认视图</button>
      </div>
    </div>
    <button class="ui-btn ui-btn-secondary" type="button" data-ui-table-settings="#power-workbench-table">视图设置</button>
    <button class="ui-btn ui-btn-icon ui-btn-secondary" type="button" data-power-refresh aria-label="刷新" title="刷新"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M20 6v5h-5M4 18v-5h5M6.1 9A7 7 0 0 1 18.7 7M17.9 15A7 7 0 0 1 5.3 17"/></svg></button>
  </section>

  <form method="get" class="power-filter-panel" data-power-filter-panel hidden>
    <input type="hidden" name="tab" value="<?=mc_h($tab)?>">
    <?php if($q!==''):?><input type="hidden" name="q" value="<?=mc_h($q)?>"><?php endif;?>
    <label class="ui-field"><span class="ui-label">来源</span><select class="ui-select" name="source"><option value="">全部来源</option><option value="legacy_bom" <?=$sourceFilter==='legacy_bom'?'selected':''?>>BOM旧数据</option><option value="material_center" <?=$sourceFilter==='material_center'?'selected':''?>>物料中心新建</option></select></label>
    <?php if($tab==='exception'):?><label class="ui-field"><span class="ui-label">异常类型</span><select class="ui-select" name="exception"><option value="">全部异常</option><option value="duplicate" <?=$exceptionFilter==='duplicate'?'selected':''?>>重复候选</option><option value="disabled" <?=$exceptionFilter==='disabled'?'selected':''?>>停用</option><option value="archived" <?=$exceptionFilter==='archived'?'selected':''?>>归档</option></select></label><?php endif;?>
    <button class="ui-btn" type="submit">应用筛选</button><a class="ui-btn ui-btn-secondary" href="?tab=<?=mc_h($tab)?>">清空全部</a>
  </form>

  <?php if($sourceFilter!==''||$exceptionFilter!==''):?><div class="power-filter-tags" aria-label="当前筛选">
    <?php if($sourceFilter!==''):?><a href="?<?=mc_h(http_build_query(array_diff_key($queryBase,['source'=>true])))?>" class="ui-badge">来源：<?=$sourceFilter==='legacy_bom'?'BOM旧数据':'物料中心新建'?> ×</a><?php endif;?>
    <?php if($exceptionFilter!==''):?><a href="?<?=mc_h(http_build_query(array_diff_key($queryBase,['exception'=>true])))?>" class="ui-badge">异常：<?=mc_h(['duplicate'=>'重复候选','disabled'=>'停用','archived'=>'归档'][$exceptionFilter]??$exceptionFilter)?> ×</a><?php endif;?>
  </div><?php endif;?>

  <div class="ui-batch-bar" data-power-batch-bar hidden><strong>已选择 <span data-power-selected-count>0</span> 项</strong><a class="ui-btn ui-btn-sm" href="formal_power_supplies.php">批量设置</a><a class="ui-btn ui-btn-sm ui-btn-secondary" href="material/power.php">批量分类 / 停用</a><button class="ui-btn ui-btn-sm ui-btn-secondary" type="button" data-export-selected>导出所选</button><button class="ui-btn ui-btn-sm ui-btn-ghost" type="button" data-cancel-selection>取消选择</button></div>

  <?php if($status==='permission'):?><?php mc_state('permission','无权访问电源工作台','需要广州统一账号及物料中心查看权限。');?>
  <?php elseif($status==='config'):?><?php mc_state('config','电源工作台尚未配置','请先执行物料中心迁移。');?>
  <?php elseif($status==='error'):?><?php mc_state('error','电源数据加载失败',"错误编号 {$errorId}，系统没有写入旧 BOM。","?tab={$tab}",'重试');?>
  <?php elseif($panel!==''):?>
    <?php if($panel==='bands'):?><section class="ui-card ui-workbench-embed"><iframe title="功率档设置" src="power_bands.php"></iframe></section>
    <?php elseif($panel==='fields'): $items=(new PowerWorkbenchService())->fields();?><section class="ui-card ui-table-panel"><div class="ui-table-wrap"><table class="ui-table" id="power-workbench-table" data-ui-table><thead><tr><th>字段</th><th>代码</th><th>类型</th><th>存储位置</th><th>批量</th><th>敏感</th><th>状态</th></tr></thead><tbody><?php foreach($items as$r):?><tr><td><?=mc_h($r['label'])?></td><td><?=mc_h($r['field_key'])?></td><td><?=mc_h($r['data_type'])?></td><td><?=mc_h($r['storage_target'])?></td><td><?=$r['is_batch_editable']?'是':'否'?></td><td><?=$r['is_sensitive']?'是':'否'?></td><td><?=mc_h($r['status'])?></td></tr><?php endforeach;?></tbody></table></div></section>
    <?php elseif($panel==='mappings'): $items=(new PowerWorkbenchService())->mappings();?><section class="ui-card ui-table-panel"><div class="ui-table-wrap"><table class="ui-table" id="power-workbench-table" data-ui-table><thead><tr><th>来源</th><th>来源ID</th><th>正式物料ID</th><th>状态</th><th>数据哈希</th><th>更新时间</th></tr></thead><tbody><?php foreach($items as$r):?><tr><td><?=mc_h($r['source_table'])?></td><td><?=$r['source_id']?></td><td><?=$r['material_id']?></td><td><?=mc_h($r['mapping_status']??$r['link_type'])?></td><td><?=mc_h(substr((string)$r['raw_data_hash'],0,16))?>…</td><td><?=mc_h($r['updated_at'])?></td></tr><?php endforeach;?></tbody></table></div></section>
    <?php elseif($panel==='logs'): $items=(new PowerWorkbenchService())->activity();?><section class="ui-card ui-table-panel"><div class="ui-table-wrap"><table class="ui-table" id="power-workbench-table" data-ui-table><thead><tr><th>时间</th><th>对象</th><th>ID</th><th>操作</th><th>操作者</th></tr></thead><tbody><?php foreach($items as$r):?><tr><td><?=mc_h($r['created_at'])?></td><td><?=mc_h($r['entity_type'])?></td><td><?=mc_h($r['entity_id'])?></td><td><?=mc_h($r['action'])?></td><td><?=mc_h($r['actor_id'])?></td></tr><?php endforeach;?></tbody></table></div></section>
    <?php elseif($panel==='export'):?><section class="ui-card ui-card-body"><h2>导出</h2><p>导出按当前权限过滤敏感字段。</p><button class="ui-btn" type="button" data-export-power data-tab="<?=mc_h($tab)?>">导出当前结果 CSV</button></section><?php endif;?>
  <?php elseif(!$rows):?><div class="power-table-state"><?php mc_state('empty','当前视图暂无数据','筛选条件下没有真实记录；没有填充假数据。');?></div>
  <?php else:require __DIR__.'/views_power_workbench_table.php';endif;?>
</div>
<aside class="ui-drawer ui-drawer-xl power-record-drawer" id="power-record-drawer" aria-hidden="true" aria-label="电源详情">
  <header class="ui-drawer-header"><div><h2 data-drawer-title>电源详情</h2><p><span data-drawer-code></span> · <span class="ui-badge" data-drawer-status></span></p></div><button class="ui-btn ui-btn-icon ui-btn-ghost" type="button" data-ui-close aria-label="关闭"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="m6 6 12 12M18 6 6 18"/></svg></button></header>
  <div class="ui-drawer-body" data-drawer-body><div class="ui-skeleton"></div></div>
  <footer class="ui-drawer-footer"><a class="ui-btn ui-btn-secondary" data-drawer-secondary href="materials.php">关联已有</a><a class="ui-btn" data-drawer-primary href="power_standardization.php">进入整理</a></footer>
</aside>
<script>window.MC_POWER_CSRF=<?=json_encode($csrf)?>;</script>
<?php mc_page_end('','assets/js/power-workbench.js');?>
