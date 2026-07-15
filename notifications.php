<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_permission('notifications.view_own');
require_once __DIR__ . '/includes/layout.php';

$notifications = notification_list((int)current_user()['id']);
$unreadCount = notification_unread_count((int)current_user()['id']);

page_header('通知中心', ['ui_type' => 'system', 'page' => 'notifications', 'notifications' => $unreadCount]);
?>
<section class="settings-console notification-center">
  <div class="settings-hero">
    <div>
      <span>Notification Center</span>
      <h2>通知中心</h2>
      <p>系统底座通知入口。注册审核、权限申请、审批结果、临时权限到期、高危操作、系统修复和备份完成通知会逐步接入。</p>
    </div>
    <div class="settings-summary">
      <strong><?= h($unreadCount) ?></strong>
      <span>未读通知</span>
    </div>
  </div>

  <div class="settings-card-grid">
    <?php foreach ([
      ['注册审核通知', '用户提交注册申请后通知管理员审核。'],
      ['权限申请通知', '员工申请权限后通知审批人。'],
      ['审批结果通知', '审批通过或拒绝后通知申请人。'],
      ['临时权限到期通知', '临时授权即将到期或已过期提醒。'],
      ['高危操作通知', '权限变更、恢复备份、系统修复等高危操作提醒。'],
      ['备份完成通知', '手动备份或自动备份完成后通知管理员。'],
    ] as $item): ?>
    <article class="settings-card">
      <div><span class="risk-chip normal">预留</span></div>
      <h3><?= h($item[0]) ?></h3>
      <p><?= h($item[1]) ?></p>
    </article>
    <?php endforeach; ?>
  </div>

  <section class="settings-action-panel">
    <div>
      <h3>通知列表</h3>
      <p>当前没有真实通知时保持空列表，不报错。后续模块调用通知服务后会显示在这里。</p>
    </div>
  </section>

  <?php if (!$notifications): ?>
    <div class="linkage-empty">暂无通知。</div>
  <?php else: ?>
    <div class="log-card-grid">
      <?php foreach ($notifications as $item): ?>
      <article class="log-card">
        <span class="status-pill <?= empty($item['read_at']) ? 'wait' : 'ok' ?>"><?= empty($item['read_at']) ? '未读' : '已读' ?></span>
        <h4><?= h($item['title']) ?></h4>
        <p><?= h($item['content']) ?></p>
        <small><?= h($item['created_at']) ?></small>
      </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php page_footer(); ?>
