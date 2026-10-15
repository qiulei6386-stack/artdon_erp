<?php
declare(strict_types=1);

/** 邮箱：文件夹、搜索、邮件列表、批量标记。 */

require_once __DIR__ . '/bootstrap.php';

use Artdon\Centers\Base\Auth;
use Artdon\Centers\Base\Layout;
use Artdon\Centers\Base\Pager;
use Artdon\Centers\Base\PermissionException;
use Artdon\Centers\Base\Url;
use Artdon\Centers\Mail\MailAccounts;
use Artdon\Centers\Mail\Mailbox;
use Artdon\Centers\Mail\MailView;

$user = Auth::requirePage();
Auth::requirePermissionPage('mail.view');
$selfId = (int) $user['id'];
$ownerId = (int) ($_GET['user_id'] ?? 0) ?: $selfId;
try {
    $box = new Mailbox($user, $ownerId);
} catch (PermissionException $e) {
    Layout::forbidden('mail.view_all');
    return;
}
$isSelf = $box->isSelf();
$str = static function (string $key): string {
    $v = $_GET[$key] ?? '';
    return is_string($v) ? trim($v) : '';
};
$folder = $str('folder');
if (!isset(Mailbox::FOLDERS[$folder])) {
    $folder = 'inbox';
}
$filters = ['q' => $str('q'), 'account' => (int) ($_GET['account'] ?? 0), 'read' => $str('read'), 'period' => $str('period')];
$accounts = $box->accounts();
$result = $box->list($folder, $filters, (int) ($_GET['page'] ?? 1));
$counts = $box->counts($filters['account']);
$self = Url::center('mail', 'index.php');
$api = Url::center('mail', 'api.php');
$base = array_filter(['user_id' => $isSelf ? 0 : $ownerId, 'folder' => $folder], static function ($v): bool {
    return $v !== 0 && $v !== '';
});
$link = static function (array $changes) use ($filters, $self, $base): string {
    $query = array_filter(array_merge($base, $filters, $changes), static function ($v): bool {
        return $v !== '' && $v !== 0 && $v !== null;
    });
    return $self . ($query ? '?' . http_build_query($query) : '');
};
$here = $link(['page' => $result['page'] > 1 ? $result['page'] : 0]);
$filtered = $filters['q'] !== '' || $filters['read'] !== '' || $filters['period'] !== '';
$canViewAll = Mailbox::canViewAll($user);
$accountManager = new MailAccounts($user);
$ownerName = $isSelf ? '' : ($accountManager->ownerNames()[$ownerId] ?? ('#' . $ownerId));
$accountEmails = [];
foreach ($accounts as $a) {
    $accountEmails[(int) $a['id']] = (string) $a['email_address'];
}
$bulk = $isSelf && $result['rows'];
$canDelete = $box->can('mail.delete');

Layout::start([
    'title' => '邮箱',
    'nav' => 'mail',
    'crumbs' => ['邮箱' => $self, Mailbox::FOLDERS[$folder] => null],
    'styles' => [Url::centerAsset('mail', 'mail.css')],
    'scripts' => [Url::centerAsset('mail', 'mail.js')],
]);
?>
<div class="page-head">
  <div>
    <h1 class="page-title"><?= $isSelf ? '邮箱' : Layout::e($ownerName) . ' 的邮件' ?></h1>
    <p class="page-sub"><?php if (!$isSelf): ?>你在查看别人的邮件，只能看不能改，每次打开邮件都会记录在日志里。<?php elseif ($accounts): ?>收信由服务器每隔几分钟自动同步。<?php else: ?>还没有绑定邮箱。<?php endif; ?></p>
  </div>
  <div class="page-actions">
    <?php if ($canViewAll): ?><?= MailView::ownerSelect($accountManager->ownerNames(), $selfId, $ownerId, $self) ?><?php endif; ?>
    <?php if ($accountManager->canManage($selfId) || $accountManager->canManageOthers()): ?><a class="btn" href="<?= Layout::e(Url::center('mail', 'accounts.php')) ?>">邮箱设置</a><?php endif; ?>
  </div>
</div>

<?php if (!$accounts && $isSelf): ?>
<section class="card">
  <div class="empty">
    <div class="empty-title">先绑定一个邮箱</div>
    <p class="empty-text">绑定后，这里会显示你的收件箱和已发送邮件。需要邮箱的授权码（不是登录密码），可以在邮箱网页版的设置里生成。</p>
    <?php if ($accountManager->canManage($selfId)): ?><a class="btn btn-primary" href="<?= Layout::e(Url::center('mail', 'accounts.php')) ?>">绑定邮箱</a><?php endif; ?>
  </div>
</section>
<?php else: ?>
<div class="mail-layout">
  <nav class="mail-folders" aria-label="邮件文件夹">
    <?php foreach (Mailbox::FOLDERS as $key => $label):
        $n = ['inbox' => $counts['inbox'] ?? 0, 'unread' => $counts['unread'] ?? 0][$key] ?? 0;
        $on = $key === $folder; ?>
      <a class="mail-folder<?= $on ? ' is-active' : '' ?>" href="<?= Layout::e($link(['folder' => $key, 'page' => 0])) ?>"<?= $on ? ' aria-current="page"' : '' ?>><span><?= Layout::e($label) ?></span><?php if ($n > 0): ?><span class="mail-count" aria-label="<?= $n ?> 封未读"><?= $n > 999 ? '999+' : $n ?></span><?php endif; ?></a>
      <?php if ($key === 'sent' || $key === 'unreplied'): ?><span class="mail-folder-sep" aria-hidden="true"></span><?php endif; ?>
    <?php endforeach; ?>
    <?php if (count($accounts) > 1): ?>
      <div class="mail-folder-title">邮箱</div>
      <a class="mail-folder<?= $filters['account'] === 0 ? ' is-active' : '' ?>" href="<?= Layout::e($link(['account' => 0, 'page' => 0])) ?>"><span>全部邮箱</span></a>
      <?php foreach ($accounts as $a): ?>
        <a class="mail-folder<?= $filters['account'] === (int) $a['id'] ? ' is-active' : '' ?>" href="<?= Layout::e($link(['account' => (int) $a['id'], 'page' => 0])) ?>" title="<?= Layout::e((string) $a['email_address']) ?>"><span class="truncate"><?= Layout::e((string) $a['email_address']) ?></span><?php if ((int) $a['is_enabled'] !== 1): ?><span class="badge badge-muted">停用</span><?php endif; ?></a>
      <?php endforeach; ?>
    <?php endif; ?>
  </nav>

  <div class="mail-main">
    <form class="filters" method="get" action="<?= Layout::e($self) ?>" role="search">
      <?php foreach ($base as $k => $v): ?><input type="hidden" name="<?= Layout::e($k) ?>" value="<?= Layout::e((string) $v) ?>"><?php endforeach; ?>
      <?php if ($filters['account']): ?><input type="hidden" name="account" value="<?= (int) $filters['account'] ?>"><?php endif; ?>
      <label class="sr-only" for="mq">搜索邮件</label>
      <input class="input input-search" id="mq" type="search" name="q" value="<?= Layout::e($filters['q']) ?>" placeholder="主题、发件人、收件人、正文、附件名">
      <label class="sr-only" for="mr">已读状态</label>
      <select class="input select" id="mr" name="read"><option value="">已读未读</option><option value="unread"<?= $filters['read'] === 'unread' ? ' selected' : '' ?>>未读</option><option value="read"<?= $filters['read'] === 'read' ? ' selected' : '' ?>>已读</option></select>
      <label class="sr-only" for="mp">时间</label>
      <select class="input select" id="mp" name="period">
        <?php foreach (Mailbox::PERIODS as $k => $v): ?><option value="<?= Layout::e($k) ?>"<?= $filters['period'] === $k ? ' selected' : '' ?>><?= Layout::e($v) ?></option><?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-sm">搜索</button>
      <?php if ($filtered): ?><a class="btn btn-sm btn-ghost" href="<?= Layout::e($link(['q' => '', 'read' => '', 'period' => '', 'page' => 0])) ?>">清空</a><?php endif; ?>
    </form>

    <section class="card">
      <?php if ($bulk): ?>
      <div class="bulkbar" data-bulkbar hidden>
        <span>已选 <strong data-bulk-count>0</strong> 封</span>
        <form data-api action="<?= Layout::e($api) ?>?action=mail_read" data-bulk-form><input type="hidden" name="read" value="1"><span data-bulk-ids></span><button type="submit" class="btn btn-sm">标为已读</button></form>
        <form data-api action="<?= Layout::e($api) ?>?action=mail_read" data-bulk-form><input type="hidden" name="read" value="0"><span data-bulk-ids></span><button type="submit" class="btn btn-sm">标为未读</button></form>
        <form data-api action="<?= Layout::e($api) ?>?action=mail_star" data-bulk-form><input type="hidden" name="starred" value="1"><span data-bulk-ids></span><button type="submit" class="btn btn-sm">加星标</button></form>
        <?php if ($canDelete): ?>
          <?php if ($folder === 'deleted'): ?>
            <form data-api action="<?= Layout::e($api) ?>?action=mail_restore" data-bulk-form><span data-bulk-ids></span><button type="submit" class="btn btn-sm">恢复</button></form>
          <?php else: ?>
            <form data-api action="<?= Layout::e($api) ?>?action=mail_delete" data-bulk-form><span data-bulk-ids></span><button type="submit" class="btn btn-sm btn-danger">删除</button></form>
          <?php endif; ?>
        <?php endif; ?>
        <button type="button" class="btn btn-sm btn-ghost" data-bulk-clear>取消选择</button>
      </div>
      <?php endif; ?>
      <?php if (!$result['rows']): ?>
        <div class="card-body muted">
          <?php if ($filtered): ?>没有符合条件的邮件。<?php elseif ($folder === 'unreplied'): ?>没有超过 <?= Mailbox::UNREPLIED_DAYS ?> 天未回复的邮件（只看最近 60 天发出的）。<?php else: ?>这里没有邮件。<?php endif; ?>
        </div>
      <?php else: ?>
      <ul class="mail-list" aria-label="邮件列表">
        <?php foreach ($result['rows'] as $row):
            $mid = (int) $row['id'];
            $unread = (int) $row['is_read'] === 0;
            $who = $row['is_outgoing']
                ? '发给 ' . (Mailbox::displayAddresses((string) $row['to_emails']) ?: '（无收件人）')
                : ((string) $row['from_name'] !== '' ? (string) $row['from_name'] : (string) $row['from_email']);
            $readUrl = Url::center('mail', 'read.php?' . http_build_query(array_filter(['id' => $mid, 'user_id' => $isSelf ? 0 : $ownerId, 'back' => $here]))); ?>
          <li class="mail-row<?= $unread ? ' is-unread' : '' ?>">
            <?php if ($bulk): ?><label class="check mail-row-check"><input type="checkbox" value="<?= $mid ?>" data-row-check aria-label="选择：<?= Layout::e((string) $row['subject']) ?>"></label><?php endif; ?>
            <?php if ($isSelf): ?>
              <form data-api action="<?= Layout::e($api) ?>?action=mail_star" class="mail-star-form" data-star-form><input type="hidden" name="ids[]" value="<?= $mid ?>"><input type="hidden" name="starred" value="<?= (int) $row['is_starred'] === 1 ? 0 : 1 ?>">
                <button type="submit" class="mail-star<?= (int) $row['is_starred'] === 1 ? ' is-on' : '' ?>" aria-label="<?= (int) $row['is_starred'] === 1 ? '取消星标' : '加星标' ?>" aria-pressed="<?= (int) $row['is_starred'] === 1 ? 'true' : 'false' ?>">★</button></form>
            <?php else: ?>
              <span class="mail-star<?= (int) $row['is_starred'] === 1 ? ' is-on' : '' ?>" aria-hidden="true">★</span>
            <?php endif; ?>
            <a class="mail-row-link" href="<?= Layout::e($readUrl) ?>">
              <span class="mail-who truncate"><?php if ($unread): ?><span class="sr-only">未读，</span><?php endif; ?><?= Layout::e($who) ?></span>
              <span class="mail-subject">
                <span class="mail-subject-text"><?= Layout::e((string) $row['subject'] !== '' ? (string) $row['subject'] : '（无主题）') ?></span>
                <?php if ($row['snippet'] !== ''): ?><span class="mail-snippet"> — <?= Layout::e((string) $row['snippet']) ?></span><?php endif; ?>
              </span>
              <span class="mail-tags">
                <?php if ($folder !== 'inbox' && $folder !== 'sent'): ?><span class="badge badge-muted"><?= $row['is_outgoing'] ? '已发送' : '收件' ?></span><?php endif; ?>
                <?php if (!empty($row['linked_customer_name'])): ?><span class="badge badge-brand" title="已关联客户"><?= Layout::e((string) $row['linked_customer_name']) ?></span><?php endif; ?>
                <?php if ((int) $row['is_replied'] === 1 && !$row['is_outgoing']): ?><span class="badge badge-good">已回复</span><?php endif; ?>
                <?php if (count($accounts) > 1 && $filters['account'] === 0): ?><span class="badge badge-muted"><?= Layout::e(strstr($accountEmails[(int) $row['mail_account_id']] ?? '', '@', true) ?: '') ?></span><?php endif; ?>
                <?php if ((int) $row['visible_attachments'] > 0): ?><span class="mail-clip" title="<?= (int) $row['visible_attachments'] ?> 个附件" aria-label="<?= (int) $row['visible_attachments'] ?> 个附件">📎</span><?php endif; ?>
              </span>
              <time class="mail-time" datetime="<?= Layout::e(str_replace(' ', 'T', (string) $row['time'])) ?>" title="<?= Layout::e((string) $row['time']) ?>"><?= Layout::e(MailView::shortTime((string) $row['time'])) ?></time>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
      <?php if ($result['total'] > 0): ?><div class="card-foot"><?= Pager::render($result, static function (int $p) use ($link): string {
          return $link(['page' => $p > 1 ? $p : 0]);
      }, '封') ?></div><?php endif; ?>
    </section>
  </div>
</div>
<?php endif; ?>
<?php Layout::end();
