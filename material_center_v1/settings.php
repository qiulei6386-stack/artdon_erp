<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
use Artdon\MaterialCenter\Adapters\LegacyAuthAdapter;
use Artdon\MaterialCenter\Security\PermissionService;
use Artdon\MaterialCenter\Services\SettingsService;
$context=(new LegacyAuthAdapter())->current();$user=mc_current_user();$ready=mc_table_exists('mc_ui_settings');
$allowed=$context&&(new PermissionService())->allows($context,'material_center.settings.view');
$settings=$allowed&&$ready?(new SettingsService())->resolved($context):[];$values=$settings['values']??[];
header('Content-Type:text/html;charset=utf-8');header('Cache-Control:no-store');mc_page_start('设置中心','settings',$user);
?>
<div class="ui-page-head"><div><span class="ui-eyebrow">A7 · SETTINGS CENTER</span><h1>设置中心</h1><p>个人偏好覆盖角色和全局值；全部设置经过校验并转换为受控设计令牌。</p></div><div><button class="ui-btn ui-btn-secondary" type="button" data-settings-export>导出</button><label class="ui-btn ui-btn-secondary" for="settings-import">导入</label><input id="settings-import" type="file" accept="application/json" hidden><button class="ui-btn ui-btn-secondary" type="button" data-settings-reset>恢复个人默认</button><button class="ui-btn" type="submit" form="settings-form">保存设置</button></div></div>
<?php if(!$context):?><?php mc_state('permission','需要统一登录','设置中心复用广州 ERP 账号。','../login.php','前往登录');?>
<?php elseif(!$ready):?><?php mc_state('config','设置中心尚未安装','数据库迁移未完成，未写入任何设置。');?>
<?php elseif(!$allowed):?><?php mc_state('permission','无权查看设置','需要物料中心设置查看权限。');?>
<?php else:?>
<form id="settings-form" class="settings-grid">
<input type="hidden" name="csrf_token" value="<?=mc_h(function_exists('csrf_token')?csrf_token():'')?>">
<section class="ui-card ui-card-body"><h2>字体与字号</h2>
<label class="ui-field"><span class="ui-label">字体</span><select class="ui-select" name="font.family"><option value="system">系统字体</option><option value="noto_sans_sc">Noto Sans SC</option><option value="arial">Arial</option></select></label>
<?php foreach(['font.base_px'=>'正文','font.nav_px'=>'导航','font.topbar_px'=>'顶部栏','font.title_px'=>'页面标题','font.subtitle_px'=>'页面副标题','font.table_px'=>'表格正文','font.label_px'=>'表单标签','font.input_px'=>'输入框','font.button_px'=>'按钮','font.modal_title_px'=>'弹窗标题','font.drawer_title_px'=>'抽屉标题','font.pagination_px'=>'分页'] as $key=>$label):?><label class="ui-field"><span class="ui-label"><?=$label?>字号</span><input class="ui-input" type="number" min="10" max="40" name="<?=$key?>" value="<?=mc_h($values[$key]??14)?>"></label><?php endforeach;?>
</section>
<section class="ui-card ui-card-body"><h2>主题与颜色</h2>
<label class="ui-field"><span class="ui-label">主题</span><select class="ui-select" name="theme.mode"><option value="light">专业白</option><option value="dark">深色</option><option value="system">跟随系统</option></select></label>
<label class="ui-field"><span class="ui-label">主操作色</span><input class="ui-input ui-color-input" type="color" name="theme.primary" value="<?=mc_h($values['theme.primary']??'#d60000')?>"></label>
<label class="ui-field"><span class="ui-label">侧栏底色</span><input class="ui-input ui-color-input" type="color" name="theme.sidebar" value="<?=mc_h($values['theme.sidebar']??'#ffffff')?>"></label>
<?php foreach(['color.page_bg'=>'页面背景','color.surface'=>'卡片背景','color.border'=>'边框','color.text'=>'主文字','color.text_muted'=>'次文字','color.success'=>'成功','color.warning'=>'警告','color.danger'=>'错误']as$key=>$label):?><label class="ui-field"><span class="ui-label"><?=$label?></span><input class="ui-input ui-color-input" type="color" name="<?=$key?>" value="<?=mc_h($values[$key]??'#ffffff')?>"></label><?php endforeach;?>
</section>
<section class="ui-card ui-card-body"><h2>布局与表格</h2>
<label class="ui-field"><span class="ui-label">界面密度</span><select class="ui-select" name="layout.density"><option value="compact">紧凑</option><option value="comfortable">舒适</option><option value="spacious">宽松</option></select></label>
<label class="ui-field"><span class="ui-label">默认侧栏</span><select class="ui-select" name="layout.sidebar"><option value="expanded">展开</option><option value="collapsed">收起</option></select></label>
<label class="ui-field"><span class="ui-label">每页行数</span><input class="ui-input" type="number" min="10" max="100" name="table.page_size" value="<?=mc_h($values['table.page_size']??20)?>"></label>
<?php foreach(['layout.sidebar_width'=>['导航宽度',200,280],'layout.topbar_height'=>['顶部栏高度',48,72],'layout.page_padding'=>['页面边距',12,40],'layout.card_padding'=>['卡片内边距',8,32],'layout.radius'=>['圆角',0,20],'layout.drawer_width'=>['抽屉宽度',460,620],'table.row_height'=>['表格行高',32,64]]as$key=>[$label,$min,$max]):?><label class="ui-field"><span class="ui-label"><?=$label?> px</span><input class="ui-input" type="number" min="<?=$min?>" max="<?=$max?>" name="<?=$key?>" value="<?=mc_h($values[$key]??$min)?>"></label><?php endforeach;?>
<label class="ui-field"><span class="ui-label">分页模式</span><select class="ui-select" name="table.page_mode"><option value="auto">自动</option><option value="10">10</option><option value="20">20</option><option value="30">30</option><option value="50">50</option><option value="100">100</option></select></label>
</section>
<section class="ui-card ui-card-body"><h2>动画与反馈</h2>
<label class="ui-switch"><input type="checkbox" name="motion.enabled" value="1"><span class="ui-switch-track"></span><span>启用克制动画</span></label>
<label class="ui-switch"><input type="checkbox" name="feedback.toast" value="1"><span class="ui-switch-track"></span><span>启用 Toast 反馈</span></label>
<label class="ui-field"><span class="ui-label">动画强度</span><select class="ui-select" name="motion.intensity"><option value="minimal">极简</option><option value="standard">标准</option><option value="none">关闭</option></select></label>
<label class="ui-switch"><input type="checkbox" name="feedback.card_hover" value="1"><span class="ui-switch-track"></span><span>卡片悬停反馈</span></label>
<label class="ui-switch"><input type="checkbox" name="presentation.hide_sensitive" value="1"><span class="ui-switch-track"></span><span>展示模式隐藏敏感字段</span></label>
<label class="ui-switch"><input type="checkbox" name="presentation.hide_actions" value="1"><span class="ui-switch-track"></span><span>展示模式隐藏操作按钮</span></label>
<p class="ui-help">保存过程包含 Loading、成功或失败反馈；不会显示虚假成功。</p>
</section></form>
<script type="application/json" id="settings-values"><?=json_encode($values,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?></script>
<?php endif;?>
<?php mc_page_end('','assets/js/settings.js');?>
