<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
if (!has_permission('logs.view_own') && !has_permission('logs.view_all')) permission_denied_response();

$message = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'expire_check') {
    require_csrf();
    require_permission('permission_request.expire_check');
    $count = expire_old_permission_grants();
    $message = '已检查过期权限，处理 ' . $count . ' 条。';
}

$user = current_user();
$canAll = has_permission('logs.view_all');
$scopeSql = $canAll ? '' : 'WHERE user_id = ? ';
$scopeParams = $canAll ? [] : [$user['id']];

function fetch_log_rows(string $table, string $where, array $params): array
{
    if (!db_table_exists($table)) return [];
    $stmt = db()->prepare("SELECT * FROM {$table} {$where}ORDER BY id DESC LIMIT 120");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

$loginRows = db_table_exists('sys_login_logs')
    ? fetch_log_rows('sys_login_logs', $scopeSql, $scopeParams)
    : fetch_log_rows('crm_login_logs', $scopeSql, $scopeParams);
$actionRows = db_table_exists('sys_action_logs')
    ? fetch_log_rows('sys_action_logs', $scopeSql, $scopeParams)
    : fetch_log_rows('crm_audit_logs', $scopeSql, $scopeParams);
$homepageRows = fetch_log_rows('sys_homepage_logs', $scopeSql, $scopeParams);
$securityRows = db_table_exists('sys_action_logs')
    ? fetch_log_rows('sys_action_logs', ($canAll ? "WHERE risk_level = 'danger' " : "WHERE user_id = ? AND risk_level = 'danger' "), $scopeParams)
    : [];

require_once __DIR__ . '/includes/layout.php';
page_header('日志审计中心', ['ui_type' => 'system', 'page' => 'logs']);
if ($message) flash($message, 'success');
?>
<section class="log-center">
  <div class="log-hero"><h2>操作级审计系统</h2><p>集中查看登录、操作、首页行为和高危安全日志。</p></div>
  <?php if (has_permission('permission_request.expire_check')): ?>
  <form method="post" class="toolbar"><?= csrf_field() ?><input type="hidden" name="action" value="expire_check"><button type="submit">检查过期权限</button></form>
  <?php endif; ?>
  <nav class="acl-tabs">
    <a href="#login">登录日志</a><a href="#action">操作日志</a><a href="#home">首页日志</a><a href="#security">高危日志</a>
  </nav>

  <section id="login" class="acl-panel"><div class="acl-panel-head"><h3>登录日志</h3><p>登录账号、时间、IP、设备和结果。</p></div><div class="log-card-grid">
    <?php foreach ($loginRows as $row): ?><article class="log-card"><span class="status-pill <?= $row['status'] === 'success' ? 'ok' : ($row['status'] === 'fail' ? 'wait' : 'off') ?>"><?= h($row['status']) ?></span><h4><?= h($row['username']) ?></h4><p><?= h($row['message']) ?></p><small><?= h($row['created_at']) ?> · <?= h($row['ip']) ?></small><em><?= h($row['user_agent']) ?></em></article><?php endforeach; ?>
  </div></section>

  <section id="action" class="acl-panel"><div class="acl-panel-head"><h3>操作日志</h3><p>记录模块、动作、对象、变化前后、IP 和设备。</p></div><div class="log-card-grid">
    <?php foreach ($actionRows as $row): $risk = $row['risk_level'] ?? sys_log_risk($row['action'], $row['module']); ?><article class="log-card risk-<?= h($risk) ?>"><span class="risk-chip <?= h($risk === 'danger' ? 'danger' : ($risk === 'sensitive' ? 'sensitive' : 'normal')) ?>"><?= h($risk) ?></span><h4><?= h(($row['operator_name'] ?? '') . ' · ' . $row['module']) ?></h4><p><?= h($row['action']) ?> / <?= h(($row['target_type'] ?? '') . '#' . ($row['target_id'] ?? '')) ?></p><small><?= h($row['created_at']) ?> · <?= h($row['ip']) ?></small><details><summary>变化详情</summary><pre><?= h(($row['before_json'] ?? '') . "\n" . ($row['after_json'] ?? '')) ?></pre></details></article><?php endforeach; ?>
  </div></section>

  <section id="home" class="acl-panel"><div class="acl-panel-head"><h3>首页操作日志</h3><p>记录访问首页、模块卡片点击、快捷入口点击和离开页面。</p></div><div class="log-card-grid">
    <?php foreach ($homepageRows as $row): ?><article class="log-card"><span class="status-pill temp"><?= h($row['event_type']) ?></span><h4><?= h($row['operator_name']) ?></h4><p><?= h($row['module_key'] . ' → ' . $row['target_url']) ?></p><small><?= h($row['created_at']) ?> · <?= h($row['ip']) ?></small></article><?php endforeach; ?>
  </div></section>

  <section id="security" class="acl-panel"><div class="acl-panel-head"><h3>高危日志</h3><p>权限修改、删除、导出、修复、恢复、用户禁用和权限审批会标记为高危。</p></div><div class="log-card-grid">
    <?php foreach ($securityRows as $row): ?><article class="log-card risk-danger"><span class="risk-chip danger">高危</span><h4><?= h(($row['operator_name'] ?? '') . ' · ' . $row['module']) ?></h4><p><?= h($row['action']) ?> / <?= h(($row['target_type'] ?? '') . '#' . ($row['target_id'] ?? '')) ?></p><small><?= h($row['created_at']) ?> · <?= h($row['ip']) ?></small></article><?php endforeach; ?>
  </div></section>
</section>
<?php page_footer(); ?>
