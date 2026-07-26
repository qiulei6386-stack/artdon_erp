<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/bootstrap.php';

use Artdon\MaterialCenter\Services\AdaptationService;

$pageTitle='产品适配';
$pageDescription='产品列表、配置规则与选项详情统一维护。';
$activeMenu='adaptation';
$service=new AdaptationService();
$search=trim((string)($_GET['q']??''));
$products=$service->products($search);
$selected=(int)($_GET['product_id']??($products[0]['id']??0));
$groups=$selected?$service->groups($selected):[];
$activeGroup=(int)($_GET['group_id']??($groups[0]['id']??0));
$options=$activeGroup?$service->options($activeGroup):[];
$conditions=$activeGroup?$service->conditions($activeGroup):[];
$conflicts=$selected?$service->conflicts($selected):[];
$latestApproval=$selected?$service->latestApproval($selected):null;
$activeProduct=null;
foreach($products as$product)if((int)$product['id']===$selected){$activeProduct=$product;break;}
$activeGroupRow=null;
foreach($groups as$group)if((int)$group['id']===$activeGroup){$activeGroupRow=$group;break;}
$candidateMaterials=$activeGroupRow?$service->materialCandidates((string)$activeGroupRow['group_type']):[];
$allOptions=[];
foreach($groups as$group)$allOptions=array_merge($allOptions,$service->options((int)$group['id']));
$typeLabels=['power'=>'电源','chip'=>'芯片','optical'=>'光学','connector'=>'接头 / 安装件','accessory'=>'配件','packaging'=>'包装','custom'=>'自定义'];
$optionLabels=['required'=>'必选','optional'=>'可选','alternative'=>'替代','conditional'=>'条件','disabled'=>'禁用'];

include MC_ROOT.'/components/layout_top.php';
?>
<section class="mc-page mc-page--adaptation-v2" data-adaptation>
 <header class="mc-adaptation-head">
  <div><h1>产品适配</h1><p>产品只读来自命名系统；正式物料、规则、审批和日志保存在物料中心。</p></div>
  <div class="mc-adaptation-head__actions">
   <?php if($selected):?>
   <form data-adaptation-action><input type="hidden" name="csrf_token" value="<?=mc_h(csrf_token())?>"><input type="hidden" name="action" value="initialize_groups"><input type="hidden" name="product_id" value="<?=$selected?>"><button class="mc-button" type="submit">初始化标准配置组</button></form>
   <?php endif;?>
   <form data-adaptation-action><input type="hidden" name="csrf_token" value="<?=mc_h(csrf_token())?>"><input type="hidden" name="action" value="sync"><button class="mc-button mc-button--primary" type="submit">同步产品</button></form>
  </div>
 </header>

 <div class="mc-adaptation-workspace">
  <aside class="mc-adaptation-column mc-adaptation-products">
   <div class="mc-adaptation-column__head"><div><strong>产品列表</strong><span><?=count($products)?> 个产品</span></div></div>
   <form class="mc-adaptation-search"><label class="mc-search mc-search--small"><?=mc_icon('search',16)?><input name="q" value="<?=mc_h($search)?>" placeholder="搜索型号 / 名称"></label></form>
   <div class="mc-adaptation-products__list">
    <?php if(!$products):?><div class="mc-empty-state"><strong>没有产品</strong><span>先同步命名系统或调整搜索词。</span></div><?php endif;?>
    <?php foreach($products as$product):?>
    <a class="<?=$selected===(int)$product['id']?'is-active':''?>" href="?product_id=<?=intval($product['id'])?>">
     <span class="mc-product-thumb"><?=mc_icon('box',19)?></span>
     <span><strong><?=mc_h($product['product_code']?:'未编号')?></strong><small><?=mc_h($product['product_name']?:'未命名产品')?></small><em><?=intval($product['group_count'])?> 组 · <?=intval($product['conflict_count'])?> 冲突</em></span>
     <?php $needsApproval=(int)($product['pending_group_count']??0)>0;?>
     <b class="mc-badge mc-badge--<?=empty($product['approved_version'])||$needsApproval?'warning':'success'?>"><?=empty($product['approved_version'])?'未审批':($needsApproval?'待重审':'V'.intval($product['approved_version']))?></b>
    </a>
    <?php endforeach;?>
   </div>
  </aside>

  <section class="mc-adaptation-column mc-adaptation-rules">
   <div class="mc-adaptation-column__head">
    <div><strong>配置规则</strong><span><?=mc_h($activeProduct['product_code']??'请选择产品')?></span></div>
    <?php if($selected):?><button class="mc-button" type="button" data-open-modal="group-modal">＋ 配置组</button><?php endif;?>
   </div>
   <?php if($activeProduct):?>
   <div class="mc-adaptation-product-summary">
    <div><span>产品</span><strong><?=mc_h($activeProduct['product_code']??'—')?></strong></div>
    <div><span>配置组</span><strong><?=count($groups)?></strong></div>
    <div><span>选项</span><strong><?=count($allOptions)?></strong></div>
    <div><span>审批版本</span><strong><?=$latestApproval?'V'.intval($latestApproval['version_no']):'未审批'?></strong></div>
   </div>
   <?php endif;?>
   <div class="mc-adaptation-groups">
    <?php if(!$selected):?><div class="mc-empty-state"><strong>请选择产品</strong><span>从左侧选择产品后维护适配规则。</span></div>
    <?php elseif(!$groups):?><div class="mc-empty-state"><strong>尚无配置组</strong><span>点击“初始化标准配置组”建立九个固定业务组。</span></div><?php endif;?>
    <?php foreach($groups as$group):?>
    <a class="<?=$activeGroup===(int)$group['id']?'is-active':''?>" href="?product_id=<?=$selected?>&group_id=<?=intval($group['id'])?>">
     <span class="mc-rule-group__icon"><?=mc_icon('settings',17)?></span>
     <span><strong><?=mc_h($group['group_name'])?></strong><small>默认：<?=mc_h($group['default_material']?:'未设置')?></small></span>
     <span class="mc-adaptation-group-meta"><em><?=mc_h($typeLabels[$group['group_type']]??$group['group_type'])?></em><b><?=intval($group['option_count'])?> 个选项</b></span>
     <span class="mc-badge mc-badge--<?=$group['status']==='approved'?'success':'warning'?>"><?=$group['status']==='approved'?'已批准':'草稿'?></span>
    </a>
    <?php endforeach;?>
   </div>
  </section>

  <aside class="mc-adaptation-column mc-adaptation-options">
   <div class="mc-adaptation-column__head">
    <div><strong>选项详情</strong><span><?=mc_h($activeGroupRow['group_name']??'请选择配置组')?></span></div>
    <?php if($activeGroupRow&&$candidateMaterials):?><button class="mc-button mc-button--primary" type="button" data-open-modal="option-modal">＋ 添加物料</button><?php endif;?>
   </div>
   <?php if(!$activeGroupRow):?><div class="mc-empty-state"><strong>请选择配置组</strong><span>右侧会显示选项、默认、替代、条件和审批信息。</span></div>
   <?php else:?>
   <div class="mc-option-tabs" role="tablist">
    <button type="button" class="is-active" data-adaptation-tab="options">选项列表</button>
    <button type="button" data-adaptation-tab="default">默认设置</button>
    <button type="button" data-adaptation-tab="alternative">替代关系</button>
    <button type="button" data-adaptation-tab="conditions">适用条件</button>
    <button type="button" data-adaptation-tab="impact">价格 / 交期</button>
    <button type="button" data-adaptation-tab="approval">审批</button>
   </div>
   <div class="mc-adaptation-panel is-active" data-adaptation-panel="options">
    <?php if(!$options):?><div class="mc-empty-state"><strong>暂无正式物料选项</strong><span><?=$candidateMaterials?'点击“添加物料”建立真实选项。':'当前类别还没有正式物料；草稿不会进入产品适配。'?></span></div><?php endif;?>
    <div class="mc-adaptation-option-list"><?php foreach($options as$option):?>
     <article><div><strong><?=mc_h($option['material_code'].' '.$option['name'])?></strong><span><?=mc_h(trim(($option['brand']??'').' '.($option['model']??'')))?></span></div><div><em><?=mc_h($optionLabels[$option['option_type']]??$option['option_type'])?></em><?php if($option['is_default']):?><b>默认</b><?php endif;?><small><?=intval($option['condition_count'])?> 条条件</small></div></article>
    <?php endforeach;?></div>
   </div>
   <div class="mc-adaptation-panel" data-adaptation-panel="default">
    <div class="mc-adaptation-fact"><span>当前默认物料</span><strong><?=mc_h($activeGroupRow['default_material']?:'未设置')?></strong><p>添加或更新物料选项时勾选“设为默认”。每个配置组只能有一个默认物料。</p></div>
   </div>
   <div class="mc-adaptation-panel" data-adaptation-panel="alternative">
    <?php $alternatives=array_filter($options,static fn(array$option):bool=>$option['option_type']==='alternative');?>
    <?php if(!$alternatives):?><div class="mc-empty-state"><strong>暂无替代物料</strong><span>添加选项时选择“替代”。</span></div><?php endif;?>
    <div class="mc-adaptation-option-list"><?php foreach($alternatives as$option):?><article><div><strong><?=mc_h($option['material_code'].' '.$option['name'])?></strong><span><?=mc_h(trim(($option['brand']??'').' '.($option['model']??'')))?></span></div><div><em>替代</em></div></article><?php endforeach;?></div>
   </div>
   <div class="mc-adaptation-panel" data-adaptation-panel="conditions">
    <?php if(!$conditions):?><div class="mc-empty-state"><strong>暂无适用条件</strong><span>条件会在适配计算时返回明确原因。</span></div><?php endif;?>
    <div class="mc-condition-list"><?php foreach($conditions as$condition):?><article><strong><?=mc_h($condition['material_code'].' '.$condition['material_name'])?></strong><span><?=mc_h($condition['field_code'].' '.$condition['operator'].' '.$condition['expected_json'])?></span><p><?=mc_h($condition['failure_message'])?></p></article><?php endforeach;?></div>
   </div>
   <div class="mc-adaptation-panel" data-adaptation-panel="impact">
    <table class="mc-simple-table"><thead><tr><th>物料</th><th>价格影响</th><th>交期影响</th></tr></thead><tbody><?php foreach($options as$option):?><tr><td><?=mc_h($option['material_code'].' '.$option['name'])?></td><td><?=mc_h($option['price_impact']??'0')?></td><td><?=intval($option['lead_time_impact_days']??0)?> 天</td></tr><?php endforeach;?></tbody></table>
   </div>
   <div class="mc-adaptation-panel" data-adaptation-panel="approval">
    <div class="mc-adaptation-approval">
     <div><span>当前状态</span><strong><?=$latestApproval?'已批准 V'.intval($latestApproval['version_no']):'尚未批准'?></strong></div>
     <div><span>冲突</span><strong><?=count($conflicts)?> 条</strong></div>
     <?php if($conflicts):?><div class="mc-conflict-list"><?php foreach($conflicts as$conflict):?><p><b><?=mc_h($conflict['severity']==='block'?'阻断':'警告')?></b><?=mc_h($conflict['reason'])?></p><?php endforeach;?></div><?php endif;?>
     <?php if(count($allOptions)>1):?><button class="mc-button" type="button" data-open-modal="conflict-modal">设置冲突</button><?php endif;?>
     <?php if($groups):?><form data-adaptation-action><input type="hidden" name="csrf_token" value="<?=mc_h(csrf_token())?>"><input type="hidden" name="action" value="approve"><input type="hidden" name="product_id" value="<?=$selected?>"><button class="mc-button mc-button--primary" type="submit">批准当前适配版本</button></form><?php endif;?>
    </div>
    <?php if($allOptions):?><form class="mc-adaptation-evaluate" data-adaptation-evaluate><input type="hidden" name="csrf_token" value="<?=mc_h(csrf_token())?>"><input type="hidden" name="action" value="evaluate"><input type="hidden" name="product_id" value="<?=$selected?>"><strong>适配检查</strong><div><?php foreach($allOptions as$option):?><label><input type="checkbox" name="option_choice" value="<?=intval($option['id'])?>"><span><?=mc_h($option['material_code'].' '.$option['name'])?></span></label><?php endforeach;?></div><label class="mc-field"><span>产品 / 环境上下文（JSON，可选）</span><textarea name="context_json" rows="3" placeholder='{"space_height_mm":32,"power_w":20}'>{}</textarea></label><button class="mc-button" type="submit">计算适配</button><output data-adaptation-result></output></form><?php endif;?>
   </div>
   <?php endif;?>
  </aside>
 </div>
</section>

<div class="mc-modal" id="group-modal" data-modal><form class="mc-modal__panel" data-adaptation-action><div class="mc-modal__header"><div><strong>新建配置组</strong><span>固定业务组可直接使用“初始化标准配置组”</span></div><button type="button" class="mc-icon-button" data-close-layer>×</button></div><div class="mc-modal__body"><input type="hidden" name="csrf_token" value="<?=mc_h(csrf_token())?>"><input type="hidden" name="action" value="save_group"><input type="hidden" name="product_id" value="<?=$selected?>"><div class="mc-form-grid"><label class="mc-field"><span>组代码 *</span><input name="group_code" required pattern="[a-z0-9_]+"></label><label class="mc-field"><span>组名称 *</span><input name="group_name" required></label><label class="mc-field"><span>关联类别 *</span><select name="group_type"><option value="power">电源</option><option value="chip">芯片</option><option value="optical">光学</option><option value="connector">接头 / 安装件</option><option value="accessory">配件</option><option value="packaging">包装</option><option value="custom">自定义</option></select></label><label class="mc-field"><span>排序</span><input type="number" name="sort_order" value="100"></label></div></div><div class="mc-modal__footer"><button type="button" class="mc-button" data-close-layer>取消</button><button class="mc-button mc-button--primary" type="submit">保存配置组</button></div></form></div>

<div class="mc-modal" id="option-modal" data-modal><form class="mc-modal__panel" data-adaptation-action data-option-form><div class="mc-modal__header"><div><strong>添加正式物料选项</strong><span>草稿、停用和归档物料不会出现在这里</span></div><button type="button" class="mc-icon-button" data-close-layer>×</button></div><div class="mc-modal__body"><input type="hidden" name="csrf_token" value="<?=mc_h(csrf_token())?>"><input type="hidden" name="action" value="save_option"><input type="hidden" name="group_id" value="<?=$activeGroup?>"><div class="mc-form-grid"><label class="mc-field mc-field--wide"><span>物料 *</span><select name="material_id" required><?php foreach($candidateMaterials as$material):?><option value="<?=intval($material['id'])?>"><?=mc_h($material['material_code'].' '.$material['name'].' '.($material['model']??''))?></option><?php endforeach;?></select></label><label class="mc-field"><span>选项类型</span><select name="option_type"><option value="required">必选</option><option value="optional">可选</option><option value="alternative">替代</option><option value="conditional">条件</option><option value="disabled">禁用</option></select></label><label class="mc-field"><span>设为默认</span><select name="is_default"><option value="0">否</option><option value="1">是</option></select></label><label class="mc-field"><span>价格影响</span><input type="number" step="0.0001" name="price_impact"></label><label class="mc-field"><span>交期影响（天）</span><input type="number" name="lead_time_impact_days"></label><label class="mc-field"><span>排序</span><input type="number" name="sort_order" value="100"></label></div><div class="mc-section-title">适用条件（可选）</div><div class="mc-form-grid"><label class="mc-field"><span>产品 / 环境字段</span><input name="condition_field_code" placeholder="如 power_w、space_height_mm"></label><label class="mc-field"><span>判断方式</span><select name="condition_operator"><option value="">不设置条件</option><option value="eq">等于</option><option value="neq">不等于</option><option value="gte">大于等于</option><option value="lte">小于等于</option><option value="contains">包含</option><option value="in">属于列表</option></select></label><label class="mc-field"><span>期望值</span><input name="condition_expected" placeholder="数值、文本或逗号分隔列表"></label><label class="mc-field"><span>级别</span><select name="condition_severity"><option value="block">阻断</option><option value="warn">警告</option></select></label><label class="mc-field mc-field--wide"><span>不适配原因</span><input name="condition_failure_message" maxlength="500" placeholder="例如：电源高度超过灯体空间 4mm"></label></div></div><div class="mc-modal__footer"><button type="button" class="mc-button" data-close-layer>取消</button><button class="mc-button mc-button--primary" type="submit" <?=$candidateMaterials?'':'disabled'?>>保存物料选项</button></div></form></div>

<div class="mc-modal" id="conflict-modal" data-modal><form class="mc-modal__panel" data-adaptation-action><div class="mc-modal__header"><div><strong>设置选项冲突</strong><span>冲突必须返回具体原因</span></div><button type="button" class="mc-icon-button" data-close-layer>×</button></div><div class="mc-modal__body"><input type="hidden" name="csrf_token" value="<?=mc_h(csrf_token())?>"><input type="hidden" name="action" value="save_conflict"><input type="hidden" name="product_id" value="<?=$selected?>"><div class="mc-form-grid"><label class="mc-field"><span>选项 A</span><select name="left_option_id"><?php foreach($allOptions as$option):?><option value="<?=intval($option['id'])?>"><?=mc_h($option['material_code'].' '.$option['name'])?></option><?php endforeach;?></select></label><label class="mc-field"><span>选项 B</span><select name="right_option_id"><?php foreach(array_reverse($allOptions) as$option):?><option value="<?=intval($option['id'])?>"><?=mc_h($option['material_code'].' '.$option['name'])?></option><?php endforeach;?></select></label><label class="mc-field"><span>级别</span><select name="severity"><option value="block">阻断</option><option value="warn">警告</option></select></label><label class="mc-field mc-field--wide"><span>明确原因 *</span><input name="reason" required maxlength="500" placeholder="例如：电源高度超过灯体空间 4mm"></label></div></div><div class="mc-modal__footer"><button type="button" class="mc-button" data-close-layer>取消</button><button class="mc-button mc-button--primary" type="submit">保存冲突</button></div></form></div>

<script src="<?=mc_h(mc_url(mc_ui_asset('assets/js/adaptation-shell.js')))?>" defer></script>
<?php include MC_ROOT.'/components/layout_bottom.php';?>
