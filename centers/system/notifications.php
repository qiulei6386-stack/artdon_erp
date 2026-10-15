<?php
declare(strict_types=1);

/** 通知：新系统发给我的站内通知。点开一条即标为已读并跳到相关页面。 */

require_once __DIR__ . '/bootstrap.php';

use Artdon\Centers\Base\Auth;
use Artdon\Centers\Base\Http;
use Artdon\Centers\Base\Layout;
use Artdon\Centers\Base\Notify;
use Artdon\Centers\Base\Pager;
use Artdon\Centers\Base\Url;

$user = Auth::requirePage();
$userId = (int) $user['id'];
$self = Url::center('system', 'notifications.php');

// 打开一条：标为已读后跳到相关页面（只能打开自己的）
$open = (int) ($_GET['open'] ?? 0);
if ($open > 0) {
    $row = Notify::find($userId, $open);
    if ($row) {
        Notify::markRead($userId, [$open]);
        Http::redirect($row['url'] !== '' ? $row['url'] : $self);
    }
    Http::redirect($self);
}

$unreadOnly = ($_GET['view'] ?? '') === 'unread';
$result = Notify::list($userId, max(1, (int) ($_GET['page'] ?? 1)), $unreadOnly);
$unread = Notify::unreadCount($userId);

Layout::start([
    'title' => '通知',
    'nav' => '',
    'crumbs' => ['通知' => null],
]);
?>
<div class="page-head">
  <div>
    <h1 class="page-title">通知</h1>
    <p class="page-sub"><?= $unread > 0 ? '有 <strong>' . $unread . '</strong> 条未读。' : '没有未读通知。' ?>点开一条会自动标为已读。</p>
  </div>
  <?php if ($unread > 0): ?>
  <div class="page-actions">
    <form data-api action="<?= Layout::e(Url::center('system', 'api.php')) ?>?action=notification_read_all"><button type="submit" class="btn">全部标为已读</button></form>
  </div>
  <?php endif; ?>
</div>
<nav class="tabs" aria-label="通知筛选">
  <a class="tab<?= !$unreadOnly ? ' is-active' : '' ?>" href="<?= Layout::e($self) ?>"<?= !$unreadOnly ? ' aria-current="page"' : '' ?>>全部</a>
  <a class="tab<?= $unreadOnly ? ' is-active' : '' ?>" href="<?= Layout::e($self . '?view=unread') ?>"<?= $unreadOnly ? ' aria-current="page"' : '' ?>>未读<?= $unread > 0 ? ' ' . $unread : '' ?></a>
</nav>
<section class="card">
  <?php if (!$result['rows']): ?>
    <div class="card-body muted"><?= $unreadOnly ? '没有未读通知。' : '还没有通知。权限申请的提醒和审批结果会出现在这里。' ?></div>
  <?php else: ?>
  <ul class="notice-list">
    <?php foreach ($result['rows'] as $row): $isUnread = $row['read_at'] === null; ?>
      <li class="notice<?= $isUnread ? ' is-unread' : '' ?>">
        <span class="notice-dot" aria-hidden="true"></span>
        <div class="notice-body">
          <a class="notice-link" href="<?= Layout::e($self . '?open=' . (int) $row['id']) ?>">
            <span class="notice-title"><?= Layout::e((string) $row['title']) ?></span><?= $isUnread ? '<span class="sr-only">（未读）</span>' : '' ?>
          </a>
          <?php if ((string) ($row['content'] ?? '') !== ''): ?><p class="notice-text"><?= Layout::e((string) $row['content']) ?></p><?php endif; ?>
        </div>
        <span class="notice-meta"><?= Layout::e(substr((string) $row['created_at'], 0, 16)) ?></span>
      </li>
    <?php endforeach; ?>
  </ul>
  <?= Pager::render($result, static function (int $p) use ($self, $unreadOnly): string {
      return $self . '?' . http_build_query(array_filter(['view' => $unreadOnly ? 'unread' : '', 'page' => $p]));
  }) ?>
  <?php endif; ?>
</section>
<?php
Layout::end();
