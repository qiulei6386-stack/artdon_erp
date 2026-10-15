<?php
declare(strict_types=1);

/**
 * 收件箱（三栏）：文件夹 | 邮件列表（搜索、分类与状态标签筛选、批量操作）| 右侧直接读信。
 * 手机上点开邮件后只显示读信栏，带返回。
 */

require_once __DIR__ . '/bootstrap.php';

use Artdon\Centers\Base\Auth;
use Artdon\Centers\Base\Layout;
use Artdon\Centers\Base\NotFoundException;
use Artdon\Centers\Base\Pager;
use Artdon\Centers\Base\Permission;
use Artdon\Centers\Base\PermissionException;
use Artdon\Centers\Base\Url;
use Artdon\Centers\Customer\Country;
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
$owner = $isSelf ? 0 : $ownerId;
$str = static function (string $key): string {
    $v = $_GET[$key] ?? '';
    return is_string($v) ? trim($v) : '';
};
$folder = $str('folder');
if (!isset(Mailbox::FOLDERS[$folder])) {
    $folder = 'inbox';
}
$filters = [
    'q' => $str('q'), 'account' => (int) ($_GET['account'] ?? 0), 'read' => $str('read'), 'period' => $str('period'),
    'cat' => isset(Mailbox::CATEGORIES[$str('cat')]) ? $str('cat') : '', 'att' => $str('att') === '1' ? '1' : '',
    'cust' => in_array($str('cust'), ['linked', 'unlinked'], true) ? $str('cust') : '', 'customer' => (int) ($_GET['customer'] ?? 0),
];
$page = max(1, (int) ($_GET['page'] ?? 1));
$openId = (int) ($_GET['id'] ?? 0);
$accounts = $box->accounts();
$result = $box->list($folder, $filters, $page);
$catCounts = $box->categoryCounts($folder, $filters);
$counts = $box->counts($filters['account']);
$self = Url::center('mail', 'inbox.php');
$api = Url::center('mail', 'api.php');
$state = array_merge(['user_id' => $owner, 'folder' => $folder === 'inbox' ? '' : $folder], $filters, ['page' => $result['page'] > 1 ? $result['page'] : 0]);
$link = static function (array $changes) use ($state, $self): string {
    $query = array_filter(array_merge($state, $changes), static function ($v): bool {
        return $v !== '' && $v !== 0 && $v !== null && $v !== '0';
    });
    return $self . ($query ? '?' . http_build_query($query) : '');
};
$toggle = static function (string $key, string $value) use ($filters, $link): string {
    return $link([$key => (string) $filters[$key] === $value ? '' : $value, 'page' => 0, 'id' => 0]);
};
$filtered = $filters['q'] !== '' || $filters['read'] !== '' || $filters['period'] !== '' || $filters['cat'] !== '' || $filters['att'] !== '' || $filters['cust'] !== '' || $filters['customer'] > 0;
$accountsSvc = new MailAccounts($user);
$names = $accountsSvc->ownerNames();
$ownerName = $isSelf ? '' : ($names[$ownerId] ?? ('#' . $ownerId));
$accountEmails = [];
foreach ($accounts as $a) {
    $accountEmails[(int) $a['id']] = (string) $a['email_address'];
}
$bulk = $isSelf && $result['rows'];
$canDelete = $box->can('mail.delete');
$canLink = $box->can('mail.link_customer');
$canDownload = Permission::allows($user, 'mail.attachment_download');

// 读信栏
$mail = null;
$mailError = '';
if ($openId > 0) {
    try {
        $mail = $box->read($openId);
    } catch (NotFoundException $e) {
        $mailError = '这封邮件不存在，或者不是这个邮箱的邮件。';
    }
}
$customerName = '';
if ($filters['customer'] > 0) {
    $stmt = \Artdon\Centers\Base\Db::pdo()->prepare('SELECT customer_name FROM crm_customers WHERE id = ?');
    $stmt->execute([$filters['customer']]);
    $customerName = (string) $stmt->fetchColumn();
}
$sub = ['sent' => 'sent', 'unreplied' => 'unreplied'][$folder] ?? 'inbox';
$title = Mailbox::FOLDERS[$folder];

Layout::start([
    'title' => $mail ? ((string) $mail['subject'] !== '' ? (string) $mail['subject'] : '（无主题）') : $title,
    'nav' => 'mail',
    'crumbs' => ['邮箱系统' => Url::center('mail', 'index.php') . ($owner ? '?user_id=' . $owner : ''), $title => $mail ? $link(['id' => 0]) : null] + ($mail ? ['读邮件' => null] : []),
    'styles' => [Url::centerAsset('mail', 'mail.css')],
    'scripts' => [Url::centerAsset('mail', 'mail.js')],
    'subnav' => MailView::subnav($sub, $owner, (int) ($counts['inbox'] ?? 0), $accountsSvc->canManage($selfId) || $accountsSvc->canManageOthers()),
]);
?>
<?php if (!$isSelf): ?><p class="alert alert-warning" style="margin:0">你在查看 <strong><?= Layout::e($ownerName) ?></strong> 的邮件：只能看不能改，打开邮件会记录在日志里。</p><?php endif; ?>
<?php if (!$accounts && $result['total'] === 0): ?>
<section class="empty">
  <div class="empty-title"><?= $isSelf ? '先绑定一个邮箱' : '这个人还没有绑定邮箱' ?></div>
  <p class="empty-text">绑定后，这里会显示收件箱和已发送邮件。</p>
  <?php if ($isSelf && $accountsSvc->canManage($selfId)): ?><a class="btn btn-primary" href="<?= Layout::e(Url::center('mail', 'accounts.php')) ?>">绑定邮箱</a><?php endif; ?>
</section>
<?php else: ?>
<div class="mx<?= $openId > 0 ? ' is-reading' : '' ?>">
  <nav class="mx-folders" aria-label="邮件文件夹">
    <?php if (Mailbox::canViewAll($user)): ?><?= MailView::ownerSelect($names, $selfId, $ownerId, $self) ?><?php endif; ?>
    <?php foreach (Mailbox::FOLDERS as $key => $label):
        $n = ['inbox' => $counts['inbox'] ?? 0, 'unread' => $counts['unread'] ?? 0, 'starred' => $counts['starred'] ?? 0][$key] ?? 0;
        $on = $key === $folder; ?>
      <a class="mx-folder<?= $on ? ' is-active' : '' ?>" href="<?= Layout::e($link(['folder' => $key === 'inbox' ? '' : $key, 'page' => 0, 'id' => 0, 'cat' => '', 'customer' => 0])) ?>"<?= $on ? ' aria-current="page"' : '' ?>>
        <span><?= Layout::e($label) ?></span><?php if ($n > 0): ?><span class="mx-count<?= $key === 'starred' ? ' is-quiet' : '' ?>"><?= $n > 999 ? '999+' : $n ?></span><?php endif; ?>
      </a>
      <?php if ($key === 'sent' || $key === 'unreplied'): ?><span class="mx-sep" aria-hidden="true"></span><?php endif; ?>
    <?php endforeach; ?>
    <?php if (count($accounts) > 1): ?>
      <div class="mx-folder-title">邮箱</div>
      <a class="mx-folder<?= $filters['account'] === 0 ? ' is-active-soft' : '' ?>" href="<?= Layout::e($link(['account' => 0, 'page' => 0, 'id' => 0])) ?>"><span>全部邮箱</span></a>
      <?php foreach ($accounts as $a): ?>
        <a class="mx-folder<?= $filters['account'] === (int) $a['id'] ? ' is-active-soft' : '' ?>" href="<?= Layout::e($link(['account' => (int) $a['id'], 'page' => 0, 'id' => 0])) ?>" title="<?= Layout::e((string) $a['email_address']) ?>"><span class="truncate"><?= Layout::e((string) $a['email_address']) ?></span><?php if ((int) $a['is_enabled'] !== 1): ?><span class="badge badge-muted">停用</span><?php endif; ?></a>
      <?php endforeach; ?>
    <?php endif; ?>
  </nav>

  <section class="mx-list" aria-label="邮件列表">
    <div class="mx-list-head">
      <div class="mx-list-title"><h1><?= Layout::e($title) ?></h1><span class="muted"><?= (int) $result['total'] ?> 封<?= $customerName !== '' ? ' · 客户 ' . Layout::e($customerName) : '' ?></span></div>
      <form class="mx-search" method="get" action="<?= Layout::e($self) ?>" role="search">
        <?php foreach (array_filter(['user_id' => $owner, 'folder' => $folder === 'inbox' ? '' : $folder, 'account' => $filters['account'], 'cat' => $filters['cat'], 'read' => $filters['read'], 'att' => $filters['att'], 'cust' => $filters['cust'], 'customer' => $filters['customer']]) as $k => $v): ?><input type="hidden" name="<?= Layout::e($k) ?>" value="<?= Layout::e((string) $v) ?>"><?php endforeach; ?>
        <label class="sr-only" for="mq">搜索邮件</label>
        <input class="input" id="mq" type="search" name="q" value="<?= Layout::e($filters['q']) ?>" placeholder="搜索主题、发件人、收件人、正文、附件名">
        <label class="sr-only" for="mp">时间</label>
        <select class="input select" id="mp" name="period" data-autosubmit>
          <?php foreach (Mailbox::PERIODS as $k => $v): ?><option value="<?= Layout::e($k) ?>"<?= $filters['period'] === $k ? ' selected' : '' ?>><?= Layout::e($v) ?></option><?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-sm btn-primary">搜索</button>
      </form>
      <div class="mx-pills" aria-label="筛选">
        <a class="pill<?= $filters['cat'] === '' ? ' is-active' : '' ?>" href="<?= Layout::e($link(['cat' => '', 'page' => 0, 'id' => 0])) ?>"><span>全部</span><b class="pill-count"><?= (int) ($catCounts['total'] ?? 0) ?></b></a>
        <?php foreach (Mailbox::CATEGORIES as $cat => $label): if ((int) ($catCounts[$cat] ?? 0) === 0 && $filters['cat'] !== $cat) { continue; } ?>
          <a class="pill<?= $filters['cat'] === $cat ? ' is-active' : '' ?>" href="<?= Layout::e($toggle('cat', $cat)) ?>"><span><?= Layout::e($label) ?></span><b class="pill-count"><?= (int) ($catCounts[$cat] ?? 0) ?></b></a>
        <?php endforeach; ?>
        <span class="mx-pills-sep" aria-hidden="true"></span>
        <a class="pill<?= $filters['read'] === 'unread' ? ' is-active' : '' ?>" href="<?= Layout::e($toggle('read', 'unread')) ?>"><span>未读</span></a>
        <a class="pill<?= $filters['att'] === '1' ? ' is-active' : '' ?>" href="<?= Layout::e($toggle('att', '1')) ?>"><span>📎 有附件</span></a>
        <a class="pill<?= $filters['cust'] === 'linked' ? ' is-active' : '' ?>" href="<?= Layout::e($toggle('cust', 'linked')) ?>"><span>客户邮件</span></a>
        <a class="pill<?= $filters['cust'] === 'unlinked' ? ' is-active' : '' ?>" href="<?= Layout::e($toggle('cust', 'unlinked')) ?>"><span>未关联</span></a>
        <?php if ($filtered): ?><a class="pill mx-clear" href="<?= Layout::e($link(['q' => '', 'read' => '', 'period' => '', 'cat' => '', 'att' => '', 'cust' => '', 'customer' => 0, 'page' => 0, 'id' => 0])) ?>"><span>清空筛选</span></a><?php endif; ?>
      </div>
    </div>

    <?php if ($bulk): ?>
    <div class="bulkbar mx-bulk" data-bulkbar hidden>
      <label class="check"><input type="checkbox" data-check-all aria-label="全选本页"></label>
      <span>已选 <strong data-bulk-count>0</strong> 封</span>
      <form data-api action="<?= Layout::e($api) ?>?action=mail_read"><input type="hidden" name="read" value="1"><span data-bulk-ids></span><button type="submit" class="btn btn-sm">标为已读</button></form>
      <form data-api action="<?= Layout::e($api) ?>?action=mail_read"><input type="hidden" name="read" value="0"><span data-bulk-ids></span><button type="submit" class="btn btn-sm">标为未读</button></form>
      <form data-api action="<?= Layout::e($api) ?>?action=mail_star"><input type="hidden" name="starred" value="1"><span data-bulk-ids></span><button type="submit" class="btn btn-sm">加星标</button></form>
      <?php if ($canDelete): ?>
        <?php if ($folder === 'deleted'): ?>
          <form data-api action="<?= Layout::e($api) ?>?action=mail_restore"><span data-bulk-ids></span><button type="submit" class="btn btn-sm">恢复</button></form>
        <?php else: ?>
          <form data-api action="<?= Layout::e($api) ?>?action=mail_delete"><span data-bulk-ids></span><button type="submit" class="btn btn-sm btn-danger">删除</button></form>
        <?php endif; ?>
      <?php endif; ?>
      <button type="button" class="btn btn-sm btn-ghost" data-bulk-clear>取消</button>
    </div>
    <?php endif; ?>

    <?php if (!$result['rows']): ?>
      <div class="mx-empty muted">
        <?php if ($filtered): ?>没有符合条件的邮件。<?php elseif ($folder === 'unreplied'): ?>没有超过 <?= Mailbox::UNREPLIED_DAYS ?> 天没回音的邮件（只看最近 60 天发出的）。<?php else: ?>这里没有邮件。<?php endif; ?>
      </div>
    <?php else: ?>
    <ul class="mx-rows">
      <?php foreach ($result['rows'] as $row):
          $mid = (int) $row['id'];
          $unread = (int) $row['is_read'] === 0;
          $toFirst = Mailbox::addresses((string) $row['to_emails'])[0] ?? '';
          $who = $row['is_outgoing'] ? (Mailbox::displayAddresses((string) $row['to_emails'], 1) ?: '（无收件人）') : ((string) $row['from_name'] !== '' ? (string) $row['from_name'] : (string) $row['from_email']);
          $open = $mid === $openId; ?>
        <li class="mx-row<?= $unread ? ' is-unread' : '' ?><?= $open ? ' is-open' : '' ?>">
          <?php if ($bulk): ?><label class="check mx-row-check"><input type="checkbox" value="<?= $mid ?>" data-row-check aria-label="选择：<?= Layout::e((string) $row['subject']) ?>"></label><?php endif; ?>
          <a class="mx-row-link" href="<?= Layout::e($link(['id' => $mid])) ?>"<?= $open ? ' aria-current="true"' : '' ?>>
            <?= $row['is_outgoing'] ? MailView::letter($who, $toFirst) : MailView::letter((string) $row['from_name'], (string) $row['from_email']) ?>
            <span class="mx-row-body">
              <span class="mx-row-top"><span class="mx-who"><?= $unread ? '<span class="dot-unread" aria-label="未读"></span>' : '' ?><?= $row['is_outgoing'] ? '<span class="muted">发给</span> ' : '' ?><?= Layout::e($who) ?></span><time class="mx-time" datetime="<?= Layout::e(str_replace(' ', 'T', (string) $row['time'])) ?>" title="<?= Layout::e((string) $row['time']) ?>"><?= Layout::e(MailView::shortTime((string) $row['time'])) ?></time></span>
              <span class="mx-subject"><?= Layout::e((string) $row['subject'] !== '' ? (string) $row['subject'] : '（无主题）') ?></span>
              <?php if ($row['snippet'] !== ''): ?><span class="mx-snippet"><?= Layout::e((string) $row['snippet']) ?></span><?php endif; ?>
              <span class="mx-tags">
                <?= MailView::categoryPill((string) $row['category']) ?>
                <?php if (!empty($row['linked_customer_name'])): ?><span class="pill pill-dark" title="已关联客户"><span><?= Layout::e((string) $row['linked_customer_name']) ?></span></span><?php endif; ?>
                <?php if ((int) $row['visible_attachments'] > 0): ?><span class="pill" title="<?= (int) $row['visible_attachments'] ?> 个附件">📎 <?= (int) $row['visible_attachments'] ?></span><?php endif; ?>
                <?php if ((int) $row['is_replied'] === 1 && !$row['is_outgoing']): ?><span class="pill pill-good">已回复</span><?php endif; ?>
                <?php if ($folder !== 'inbox' && $folder !== 'sent' && $folder !== 'unreplied'): ?><span class="pill"><?= $row['is_outgoing'] ? '已发送' : '收件' ?></span><?php endif; ?>
                <?php if (count($accounts) > 1 && $filters['account'] === 0): ?><span class="pill"><?= Layout::e(strstr($accountEmails[(int) $row['mail_account_id']] ?? '', '@', true) ?: '') ?></span><?php endif; ?>
              </span>
            </span>
          </a>
          <?php if ($isSelf): ?>
            <form data-api action="<?= Layout::e($api) ?>?action=mail_star" class="mx-star-form" data-star-form><input type="hidden" name="ids[]" value="<?= $mid ?>"><input type="hidden" name="starred" value="<?= (int) $row['is_starred'] === 1 ? 0 : 1 ?>">
              <button type="submit" class="mail-star<?= (int) $row['is_starred'] === 1 ? ' is-on' : '' ?>" aria-label="<?= (int) $row['is_starred'] === 1 ? '取消星标' : '加星标' ?>" aria-pressed="<?= (int) $row['is_starred'] === 1 ? 'true' : 'false' ?>">★</button></form>
          <?php elseif ((int) $row['is_starred'] === 1): ?>
            <span class="mail-star is-on" aria-label="星标">★</span>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php if ($result['pages'] > 1): ?><div class="mx-pager"><?= Pager::render($result, static function (int $p) use ($link): string {
        return $link(['page' => $p > 1 ? $p : 0, 'id' => 0]);
    }, '封') ?></div><?php endif; ?>
  </section>

  <section class="mx-pane" aria-label="读邮件">
    <?php if ($mailError !== ''): ?>
      <div class="mx-pane-empty"><strong>打不开这封邮件</strong><span class="muted"><?= Layout::e($mailError) ?></span><a class="btn btn-sm" href="<?= Layout::e($link(['id' => 0])) ?>">返回列表</a></div>
    <?php elseif (!$mail): ?>
      <div class="mx-pane-empty"><span class="mx-pane-icon" aria-hidden="true">✉</span><strong>选择一封邮件阅读</strong><span class="muted">邮件会在这里打开，不用跳页。</span></div>
    <?php else:
        $deleted = (int) $mail['is_deleted'] === 1 || (string) $mail['folder'] === 'deleted';
        $starred = (int) $mail['is_starred'] === 1;
        $subject = (string) $mail['subject'] !== '' ? (string) $mail['subject'] : '（无主题）';
        $time = (string) ($mail['is_outgoing'] ? ($mail['sent_at'] ?: $mail['created_at']) : ($mail['received_at'] ?: $mail['created_at']));
        $back = $link(['id' => 0]);
        $addressList = static function (string $text): string {
            $out = [];
            foreach (preg_split('/,\s*(?=(?:[^"]*"[^"]*")*[^"]*$)/', $text) ?: [] as $p) {
                $p = trim($p);
                if ($p !== '') {
                    $out[] = '<span class="mail-addr">' . Layout::e($p) . '</span>';
                }
            }
            return implode(' ', $out);
        };
        $bodyUrl = Url::center('mail', 'body.php?' . http_build_query(array_filter(['id' => (int) $mail['id'], 'user_id' => $owner]))); ?>
      <div class="mx-pane-bar">
        <a class="btn btn-sm mx-back" href="<?= Layout::e($back) ?>">‹ 返回列表</a>
        <?php if ($isSelf): ?>
          <form data-api action="<?= Layout::e($api) ?>?action=mail_star"><input type="hidden" name="ids[]" value="<?= (int) $mail['id'] ?>"><input type="hidden" name="starred" value="<?= $starred ? 0 : 1 ?>"><button type="submit" class="btn btn-sm" aria-pressed="<?= $starred ? 'true' : 'false' ?>"><span class="mail-star<?= $starred ? ' is-on' : '' ?>" aria-hidden="true">★</span> <?= $starred ? '取消星标' : '加星标' ?></button></form>
          <form data-api action="<?= Layout::e($api) ?>?action=mail_read" data-redirect-after="<?= Layout::e($back) ?>"><input type="hidden" name="ids[]" value="<?= (int) $mail['id'] ?>"><input type="hidden" name="read" value="0"><button type="submit" class="btn btn-sm">标为未读</button></form>
          <?php if ($canDelete): ?>
            <?php if ($deleted): ?>
              <form data-api action="<?= Layout::e($api) ?>?action=mail_restore"><input type="hidden" name="ids[]" value="<?= (int) $mail['id'] ?>"><button type="submit" class="btn btn-sm">恢复</button></form>
            <?php else: ?>
              <form data-api action="<?= Layout::e($api) ?>?action=mail_delete"><input type="hidden" name="ids[]" value="<?= (int) $mail['id'] ?>"><input type="hidden" name="back" value="<?= Layout::e($back) ?>"><button type="submit" class="btn btn-sm btn-ghost">删除</button></form>
            <?php endif; ?>
          <?php endif; ?>
        <?php else: ?>
          <span class="pill pill-warn">只读 · 已记录查看</span>
        <?php endif; ?>
      </div>
      <header class="mx-pane-head">
        <h2 class="mail-title"><?= Layout::e($subject) ?></h2>
        <div class="pill-wrap">
          <?= MailView::categoryPill(Mailbox::category($mail)) ?>
          <?php if ($deleted): ?><span class="pill pill-accent">已删除</span><?php endif; ?>
          <?php if ((int) $mail['is_replied'] === 1 && !$mail['is_outgoing']): ?><span class="pill pill-good">已回复</span><?php endif; ?>
          <?php if ($mail['customer']): ?><span class="pill pill-dark"><span><?= Layout::e((string) $mail['customer']['customer_name']) ?></span></span><?php endif; ?>
        </div>
        <div class="mx-from">
          <?= $mail['is_outgoing'] ? MailView::letter((string) $mail['from_name'] ?: 'Me', (string) $mail['from_email']) : MailView::letter((string) $mail['from_name'], (string) $mail['from_email']) ?>
          <div class="mx-from-main">
            <strong><?= Layout::e((string) $mail['from_name'] !== '' ? (string) $mail['from_name'] : (string) $mail['from_email']) ?></strong>
            <small><?= Layout::e((string) $mail['from_email']) ?></small>
          </div>
          <time class="mx-from-time" datetime="<?= Layout::e(str_replace(' ', 'T', $time)) ?>"><?= Layout::e($time) ?><br><small><?= Layout::e(MailView::ago($time)) ?></small></time>
        </div>
        <dl class="mail-meta">
          <dt>收件人</dt><dd><?= $addressList((string) $mail['to_emails']) ?: '<span class="muted">（无）</span>' ?></dd>
          <?php if (trim((string) $mail['cc_emails']) !== ''): ?><dt>抄送</dt><dd><?= $addressList((string) $mail['cc_emails']) ?></dd><?php endif; ?>
          <?php if ($mail['is_outgoing'] && trim((string) $mail['bcc_emails']) !== ''): ?><dt>密送</dt><dd><?= $addressList((string) $mail['bcc_emails']) ?></dd><?php endif; ?>
          <?php if ($mail['account_email'] !== ''): ?><dt>邮箱</dt><dd><?= Layout::e((string) $mail['account_email']) ?><?= $mail['is_outgoing'] ? '（发出）' : '（收到）' ?></dd><?php endif; ?>
        </dl>
      </header>

      <div class="mx-customer">
        <?php if ($mail['customer']): $c = $mail['customer']; ?>
          <div class="mx-customer-main">
            <span class="drawer-kicker">关联客户</span>
            <?php if ($c['visible']): ?><a class="row-link" href="<?= Layout::e(Url::center('customer', 'view.php?id=' . (int) $c['id'])) ?>"><?= Layout::e((string) $c['customer_name']) ?></a><?php else: ?><strong><?= Layout::e((string) $c['customer_name']) ?></strong> <span class="muted">（你看不到这个客户）</span><?php endif; ?>
            <small class="muted"><?= Layout::e(Country::label((string) $c['country'])) ?><?= !empty($c['deleted_at']) ? ' · 客户已删除' : '' ?></small>
          </div>
          <div class="row-actions">
            <a class="btn btn-sm" href="<?= Layout::e($link(['folder' => 'linked', 'customer' => (int) $c['id'], 'cat' => '', 'cust' => '', 'id' => 0, 'page' => 0])) ?>">这个客户的全部邮件</a>
            <?php if ($canLink): ?>
              <button type="button" class="btn btn-sm" data-open-dialog="d-link">更换</button>
              <form data-api action="<?= Layout::e($api) ?>?action=mail_link"><input type="hidden" name="id" value="<?= (int) $mail['id'] ?>"><input type="hidden" name="customer_id" value="0"><button type="submit" class="btn btn-sm btn-ghost">取消关联</button></form>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="mx-customer-main"><span class="drawer-kicker">关联客户</span><span class="muted">还没有关联客户。</span></div>
          <?php if ($canLink): ?>
            <div class="mx-suggest">
              <?php foreach ($mail['suggestions'] as $s): ?>
                <form data-api action="<?= Layout::e($api) ?>?action=mail_link" class="mail-suggest-item">
                  <input type="hidden" name="id" value="<?= (int) $mail['id'] ?>"><input type="hidden" name="customer_id" value="<?= (int) $s['id'] ?>">
                  <span><strong><?= Layout::e($s['name']) ?></strong><small class="cell-sub"><?= Layout::e($s['reason']) ?></small></span>
                  <button type="submit" class="btn btn-sm btn-primary">关联</button>
                </form>
              <?php endforeach; ?>
              <button type="button" class="btn btn-sm" data-open-dialog="d-link">选择客户关联</button>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <?php if ($mail['attachments']): ?>
      <div class="mail-attachments" aria-label="附件">
        <?php foreach ($mail['attachments'] as $a): ?>
          <?php if ($canDownload): ?>
            <a class="mail-attachment" href="<?= Layout::e(MailView::attachmentUrl((int) $a['id'], $owner, false)) ?>" download><span class="mail-attachment-icon" aria-hidden="true"><?= Layout::e(strtoupper(pathinfo((string) $a['name'], PATHINFO_EXTENSION) ?: 'FILE')) ?></span><span class="mail-attachment-text"><span class="truncate"><?= Layout::e((string) $a['name']) ?></span><small><?= Layout::e(MailView::size((int) $a['file_size'])) ?> · 下载</small></span></a>
          <?php else: ?>
            <span class="mail-attachment" title="下载附件需要 mail.attachment_download 权限"><span class="mail-attachment-icon" aria-hidden="true"><?= Layout::e(strtoupper(pathinfo((string) $a['name'], PATHINFO_EXTENSION) ?: 'FILE')) ?></span><span class="mail-attachment-text"><span class="truncate"><?= Layout::e((string) $a['name']) ?></span><small><?= Layout::e(MailView::size((int) $a['file_size'])) ?> · 没有下载权限</small></span></span>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <div class="mail-body">
        <iframe class="mail-frame" src="<?= Layout::e($bodyUrl) ?>" title="邮件正文" sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer" data-mail-frame></iframe>
      </div>
    <?php endif; ?>
  </section>
</div>

<?php if ($mail && $canLink): ?>
<dialog class="dialog dialog-sm" id="d-link" aria-labelledby="d-link-title">
  <form data-api action="<?= Layout::e($api) ?>?action=mail_link">
    <div class="dialog-head"><h2 class="dialog-title" id="d-link-title">关联客户</h2><button type="button" class="icon-btn" data-close-dialog aria-label="关闭">×</button></div>
    <div class="dialog-body stack">
      <p class="alert alert-error form-error" data-form-error hidden></p>
      <input type="hidden" name="id" value="<?= (int) $mail['id'] ?>"><input type="hidden" name="pick" value="1">
      <div class="field picker" data-customer-picker data-search="<?= Layout::e($api) ?>?action=customer_search">
        <label class="field-label" for="lk-search">客户<span class="field-req" aria-hidden="true">*</span></label>
        <input class="input" id="lk-search" type="search" autocomplete="off" placeholder="输入名称、代码或编号查找" data-picker-input>
        <input type="hidden" name="customer_id" value="" data-picker-value>
        <ul class="picker-list" data-picker-list hidden></ul>
      </div>
    </div>
    <div class="dialog-foot"><button type="button" class="btn" data-close-dialog>取消</button><button type="submit" class="btn btn-primary">关联</button></div>
  </form>
</dialog>
<?php endif; ?>
<?php endif; ?>
<?php Layout::end();
