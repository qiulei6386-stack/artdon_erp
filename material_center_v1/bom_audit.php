<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
$user = mc_current_user();
$tableReady = mc_table_exists('bom_materials');
$fields = ['id','category','brand','name','model','spec','unit','material_grade','image','is_active','updated_at'];
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
mc_page_start('BOM 物料源审计', 'audit', $user);
?>
<div class="ui-page-head"><div><span class="ui-eyebrow">SOURCE AUDIT · NO WRITE</span><h1>旧 BOM 物料源审计</h1><p>展示新物料中心已经使用的只读来源、字段边界和保护措施，不输出数据库凭据或服务器路径。</p></div><span class="ui-badge <?= $tableReady ? 'ui-badge-success' : 'ui-badge-danger' ?>"><?= $tableReady ? '来源可用' : '来源不可用' ?></span></div>
<?php if (!$user): ?>
  <?php mc_state('permission', '需要统一登录', '审计页沿用广州 ERP 当前登录态。', '../login.php?redirect=/artdon_erp/material_center_v1/bom_audit.php', '前往登录'); ?>
<?php elseif (!$tableReady): ?>
  <?php mc_state('config', '旧物料源未配置', '未检测到 bom_materials；当前页面不会创建表或写入数据。', './bom_audit.php', '重新检查'); ?>
<?php else: ?>
<section class="audit-grid">
  <article class="ui-card ui-card-body"><span class="ui-muted">来源表</span><h2>bom_materials</h2><p>由广州旧 BOM 系统维护，新物料中心仅执行 SELECT。</p></article>
  <article class="ui-card ui-card-body"><span class="ui-muted">访问模式</span><h2>Read-only</h2><p>仓库层拒绝所有非 SELECT SQL。</p></article>
  <article class="ui-card ui-card-body"><span class="ui-muted">写入开关</span><h2>关闭</h2><p><code>write_enabled = false</code></p></article>
</section>
<section class="ui-card ui-table-panel"><div class="ui-card-header"><strong>已读取字段映射</strong><span class="ui-badge"><?= count($fields) ?> 个字段</span></div><div class="ui-table-wrap"><table class="ui-table" data-ui-table data-page-size="20"><thead><tr><th data-sort>旧源字段</th><th>新中心用途</th><th>访问方式</th></tr></thead><tbody>
<?php foreach ($fields as $field): ?><tr><td><code><?= mc_h($field) ?></code></td><td><?= mc_h(['id'=>'永久源 ID','category'=>'物料分类','brand'=>'品牌','name'=>'物料名称','model'=>'型号','spec'=>'规格','unit'=>'单位','material_grade'=>'材料牌号','image'=>'图片路径','is_active'=>'有效记录筛选','updated_at'=>'更新时间'][$field]) ?></td><td><span class="ui-badge ui-badge-success">只读</span></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<?php endif; ?>
<?php mc_page_end(); ?>
