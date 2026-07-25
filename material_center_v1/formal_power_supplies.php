<?php
declare(strict_types=1);require_once __DIR__.'/bootstrap.php';use Artdon\MaterialCenter\Services\PowerStandardizationService;
$user=mc_current_user();$ready=mc_table_exists('mc_materials');$q=trim((string)($_GET['q']??''));$rows=$user&&$ready?(new PowerStandardizationService())->formalRows($q):[];
header('Content-Type:text/html;charset=utf-8');header('Cache-Control:no-store');mc_page_start('正式电源库','library',$user);
?>
<div class="ui-page-head"><div><span class="ui-eyebrow">MM3 · STRUCTURED POWER MATERIALS</span><h1>正式电源库</h1><p>仅展示已经人工确认建立的结构化电源；当前试点默认不允许 BOM、报价或客户显示。</p></div><span class="ui-badge ui-badge-success"><?=count($rows)?> 条</span></div>
<?php if(!$user):?><?php mc_state('permission','需要统一登录','正式库复用广州 ERP 登录态。','../login.php','前往登录');?>
<?php elseif(!$ready):?><?php mc_state('config','正式物料结构尚未安装','请先执行 MM2–MM4 迁移。');?>
<?php else:?><form class="ui-toolbar ui-card" method="get"><input class="ui-input" name="q" value="<?=mc_h($q)?>" placeholder="搜索物料编号、品牌、型号、名称"><button class="ui-btn">搜索</button><a class="ui-btn ui-btn-secondary" href="./formal_power_supplies.php">重置</a></form>
<?php if(!$rows):?><?php mc_state('empty','正式电源库暂无记录','请先在标准化工作台人工确认并建立试点正式电源。','power_standardization.php','进入标准化');?>
<?php else:?><section class="ui-card ui-table-panel"><div class="ui-table-wrap"><table class="ui-table" data-ui-table data-page-size="20"><thead><tr><th data-sort>物料编号</th><th data-sort>品牌</th><th data-sort>型号</th><th data-sort>名称</th><th data-sort>功率档</th><th data-sort>安装</th><th data-sort>输出</th><th data-sort>电流选项</th><th data-sort>调光</th><th data-sort>状态</th></tr></thead><tbody><?php foreach($rows as$r):?><tr><td><b><?=mc_h($r['material_code'])?></b></td><td><?=mc_h($r['brand'])?></td><td><?=mc_h($r['model'])?></td><td><?=mc_h($r['name'])?></td><td><?=mc_h($r['power_band'])?></td><td><?=mc_h($r['installation_type'])?></td><td><?=mc_h($r['output_type'])?></td><td><?=mc_h($r['current_options']?:$r['output_current_ma'])?>mA</td><td><?=mc_h($r['dimming_modes']?:'待确认')?></td><td><span class="ui-badge"><?=$r['is_pilot']?'试点':'正式'?> / <?=mc_h($r['status'])?></span></td></tr><?php endforeach;?></tbody></table></div></section><?php endif;endif;?>
<?php mc_page_end();?>
