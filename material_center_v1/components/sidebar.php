<?php
$menu=[
 ['group'=>'工作台','items'=>[['dashboard','工作台','home','index.php']]],
 ['group'=>'物料库','items'=>[
  ['all','全部物料','layers','material/all.php'],['power','电源','zap','material/power.php'],['chip','芯片','cpu','material/chip.php'],
  ['optical','光学','aperture','material/optical.php'],['profile','型材 / 散热件','box','material/profile.php'],
  ['connector','接头 / 安装件','plug','material/connector.php'],['accessories','配件','puzzle','material/accessories.php'],['packaging','包装','package','material/packaging.php']]],
 ['group'=>'业务','items'=>[
  ['adaptation','产品适配','branch','adaptation/index.php'],['supplier','供应商与价格','users','supplier/index.php'],
  ['substitute','替代与版本','repeat','substitute/index.php'],['data','数据接入','upload','data/index.php'],['documents','文档与日志','file','documents/index.php']]],
 ['group'=>'系统','items'=>array_values(array_filter([
  ['settings','系统与设置','settings','settings/index.php'],
  has_permission('material_center.permissions.manage')?['permissions','统一权限中心','users','../permissions.php?tab=matrix']:null,
 ]))],
];
?>
<div class="mc-brand"><div class="mc-brand__mark">A</div><div class="mc-brand__text"><strong>物料中心</strong><span>Material Center</span></div></div>
<nav class="mc-nav">
<?php foreach($menu as $section): ?><div class="mc-nav__group"><div class="mc-nav__group-title"><?=mc_h($section['group'])?></div>
<?php foreach($section['items'] as $item): ?><a class="mc-nav__item <?=($activeMenu??'')===$item[0]?'is-active':''?>" href="<?=mc_h(mc_url($item[3]))?>"><span class="mc-nav__icon"><?=mc_icon($item[2])?></span><span class="mc-nav__label"><?=mc_h($item[1])?></span></a><?php endforeach; ?>
</div><?php endforeach; ?>
</nav>
<button class="mc-sidebar-collapse" type="button" data-sidebar-toggle><?=mc_icon('chevron',16)?><span class="mc-sidebar-collapse__label">收起侧边栏</span></button>
