<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
$user = mc_current_user();
$checks = [
    ['统一登录桥接', function_exists('current_user'), '复用广州 ERP 登录态'],
    ['旧 BOM 物料源', mc_table_exists('bom_materials'), '只读来源表'],
    ['写入功能', false, '按安全基线保持关闭'],
    ['UI 组件入口', is_file(__DIR__ . '/ui/index.css'), '统一 CSS 入口'],
    ['交互管理器', is_file(__DIR__ . '/ui/js/interaction-manager.js'), '统一浮层生命周期'],
];
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
mc_page_start('系统状态', 'status', $user);
?>
<div class="ui-page-head"><div><span class="ui-eyebrow">SYSTEM STATUS · SAFE VIEW</span><h1>物料中心系统状态</h1><p>只显示非敏感健康状态，不泄露 SQL、凭据或服务器绝对路径。</p></div><a class="ui-btn ui-btn-secondary" href="api/v1/health.php">JSON 健康检查</a></div>
<?php if (!$user): ?>
  <?php mc_state('permission', '需要统一登录', '登录后查看物料中心详细状态。', '../login.php?redirect=/artdon_erp/material_center_v1/system_status.php', '前往登录'); ?>
<?php else: ?>
<section class="ui-card ui-table-panel"><div class="ui-card-header"><strong>运行检查</strong><span class="ui-muted">检查时间 <?= mc_h(date('Y-m-d H:i:s')) ?></span></div><div class="ui-table-wrap"><table class="ui-table"><thead><tr><th>检查项</th><th>状态</th><th>说明</th></tr></thead><tbody>
<?php foreach ($checks as [$name,$ok,$note]): ?><tr><td><b><?= mc_h($name) ?></b></td><td><?php if ($name === '写入功能'): ?><span class="ui-badge">安全关闭</span><?php else: ?><span class="ui-badge <?= $ok ? 'ui-badge-success' : 'ui-badge-danger' ?>"><?= $ok ? '正常' : '不可用' ?></span><?php endif; ?></td><td><?= mc_h($note) ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<?php endif; ?>
<?php mc_page_end(); ?>
