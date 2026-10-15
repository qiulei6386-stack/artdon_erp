<?php
declare(strict_types=1);

/** 读一封邮件：收发件人、附件、关联客户、正文（沙箱 iframe）。 */

require_once __DIR__ . '/bootstrap.php';

use Artdon\Centers\Base\Auth;
use Artdon\Centers\Base\Layout;
use Artdon\Centers\Base\NotFoundException;
use Artdon\Centers\Base\Permission;
use Artdon\Centers\Base\PermissionException;
use Artdon\Centers\Base\Url;
use Artdon\Centers\Customer\Country;
use Artdon\Centers\Mail\Mailbox;
use Artdon\Centers\Mail\MailView;

$user = Auth::requirePage();
Auth::requirePermissionPage('mail.view');
$ownerParam = (int) ($_GET['user_id'] ?? 0);
try {
    $box = new Mailbox($user, $ownerParam);
} catch (PermissionException $e) {
    Layout::forbidden('mail.view_all');
    return;
}
$id = (int) ($_GET['id'] ?? 0);
try {
    $mail = $box->read($id);
} catch (NotFoundException $e) {
    Layout::errorPage(404, '邮件不存在', '这封邮件不存在，或者不是你的邮件。');
    return;
}
$isSelf = $box->isSelf();
$owner = $isSelf ? 0 : $box->ownerId();
$inbox = Url::center('mail', 'index.php') . ($owner ? '?user_id=' . $owner : '');
$back = Url::safeRedirect(is_string($_GET['back'] ?? null) ? (string) $_GET['back'] : '', $inbox);
$api = Url::center('mail', 'api.php');
$deleted = (int) $mail['is_deleted'] === 1 || (string) $mail['folder'] === 'deleted';
$starred = (int) $mail['is_starred'] === 1;
$canDownload = Permission::allows($user, 'mail.attachment_download');
$canLink = $box->can('mail.link_customer');
$subject = (string) $mail['subject'] !== '' ? (string) $mail['subject'] : '（无主题）';
$time = (string) ($mail['is_outgoing'] ? ($mail['sent_at'] ?: $mail['created_at']) : ($mail['received_at'] ?: $mail['created_at']));
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
$bodyUrl = Url::center('mail', 'body.php?' . http_build_query(array_filter(['id' => $id, 'user_id' => $owner])));

Layout::start([
    'title' => $subject,
    'nav' => 'mail',
    'crumbs' => ['邮箱' => $inbox, '读邮件' => null],
    'styles' => [Url::centerAsset('mail', 'mail.css')],
    'scripts' => [Url::centerAsset('mail', 'mail.js')],
]);
?>
<div class="mail-read-bar">
  <a class="btn btn-sm" href="<?= Layout::e($back) ?>">‹ 返回</a>
  <?php if ($isSelf): ?>
    <form data-api action="<?= Layout::e($api) ?>?action=mail_star"><input type="hidden" name="ids[]" value="<?= $id ?>"><input type="hidden" name="starred" value="<?= $starred ? 0 : 1 ?>"><button type="submit" class="btn btn-sm" aria-pressed="<?= $starred ? 'true' : 'false' ?>"><span class="mail-star<?= $starred ? ' is-on' : '' ?>" aria-hidden="true">★</span> <?= $starred ? '取消星标' : '加星标' ?></button></form>
    <form data-api action="<?= Layout::e($api) ?>?action=mail_read" data-redirect-after="<?= Layout::e($back) ?>"><input type="hidden" name="ids[]" value="<?= $id ?>"><input type="hidden" name="read" value="0"><button type="submit" class="btn btn-sm">标为未读</button></form>
    <?php if ($box->can('mail.delete')): ?>
      <?php if ($deleted): ?>
        <form data-api action="<?= Layout::e($api) ?>?action=mail_restore"><input type="hidden" name="ids[]" value="<?= $id ?>"><button type="submit" class="btn btn-sm">恢复</button></form>
      <?php else: ?>
        <form data-api action="<?= Layout::e($api) ?>?action=mail_delete"><input type="hidden" name="ids[]" value="<?= $id ?>"><input type="hidden" name="back" value="<?= Layout::e($back) ?>"><button type="submit" class="btn btn-sm btn-ghost">删除</button></form>
      <?php endif; ?>
    <?php endif; ?>
  <?php else: ?>
    <span class="badge badge-warn">正在查看别人的邮件（只读，已记录）</span>
  <?php endif; ?>
</div>

<div class="cols mail-read">
  <div class="col-main">
    <article class="card">
      <header class="card-body mail-head">
        <h1 class="mail-title"><?= Layout::e($subject) ?><?php if ($deleted): ?> <span class="badge badge-bad">已删除</span><?php endif; ?></h1>
        <dl class="mail-meta">
          <dt>发件人</dt><dd><?= $addressList(trim(((string) $mail['from_name'] !== '' ? '"' . $mail['from_name'] . '" ' : '') . '<' . $mail['from_email'] . '>', ' <>') ?: '<span class="muted">（未知）</span>') ?></dd>
          <dt>收件人</dt><dd><?= $addressList((string) $mail['to_emails']) ?: '<span class="muted">（无）</span>' ?></dd>
          <?php if (trim((string) $mail['cc_emails']) !== ''): ?><dt>抄送</dt><dd><?= $addressList((string) $mail['cc_emails']) ?></dd><?php endif; ?>
          <?php if ($mail['is_outgoing'] && trim((string) $mail['bcc_emails']) !== ''): ?><dt>密送</dt><dd><?= $addressList((string) $mail['bcc_emails']) ?></dd><?php endif; ?>
          <dt>时间</dt><dd><time datetime="<?= Layout::e(str_replace(' ', 'T', $time)) ?>"><?= Layout::e($time) ?></time></dd>
          <?php if ($mail['account_email'] !== ''): ?><dt>邮箱</dt><dd><?= Layout::e((string) $mail['account_email']) ?><?= $mail['is_outgoing'] ? '（发出）' : '（收到）' ?></dd><?php endif; ?>
        </dl>
      </header>
      <?php if ($mail['attachments']): ?>
      <div class="mail-attachments" aria-label="附件">
        <?php foreach ($mail['attachments'] as $a): ?>
          <?php if ($canDownload): ?>
            <a class="mail-attachment" href="<?= Layout::e(MailView::attachmentUrl((int) $a['id'], $owner, false)) ?>" download><span aria-hidden="true">📎</span><span class="truncate"><?= Layout::e((string) $a['name']) ?></span><small><?= Layout::e(MailView::size((int) $a['file_size'])) ?></small></a>
          <?php else: ?>
            <span class="mail-attachment" title="下载附件需要 mail.attachment_download 权限"><span aria-hidden="true">📎</span><span class="truncate"><?= Layout::e((string) $a['name']) ?></span><small><?= Layout::e(MailView::size((int) $a['file_size'])) ?></small></span>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <div class="mail-body">
        <iframe class="mail-frame" src="<?= Layout::e($bodyUrl) ?>" title="邮件正文" sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer" data-mail-frame></iframe>
      </div>
    </article>
  </div>
  <aside class="col-side">
    <section class="card">
      <div class="card-head"><h2 class="card-title">关联客户</h2></div>
      <div class="card-body stack">
        <?php if ($mail['customer']): $c = $mail['customer']; ?>
          <div>
            <?php if ($c['visible']): ?><a class="row-link" href="<?= Layout::e(Url::center('customer', 'view.php?id=' . (int) $c['id'])) ?>"><?= Layout::e((string) $c['customer_name']) ?></a><?php else: ?><strong><?= Layout::e((string) $c['customer_name']) ?></strong> <span class="muted">（你看不到这个客户）</span><?php endif; ?>
            <small class="cell-sub"><?= Layout::e(Country::label((string) $c['country'])) ?><?= !empty($c['deleted_at']) ? ' · 客户已删除' : '' ?></small>
          </div>
          <?php if ($canLink): ?>
            <div class="row-actions">
              <button type="button" class="btn btn-sm" data-open-dialog="d-link">更换</button>
              <form data-api action="<?= Layout::e($api) ?>?action=mail_link"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="customer_id" value="0"><button type="submit" class="btn btn-sm btn-ghost">取消关联</button></form>
            </div>
          <?php endif; ?>
        <?php else: ?>
          <p class="muted" style="margin:0">还没有关联客户。</p>
          <?php if ($canLink && $mail['suggestions']): ?>
            <div class="stack mail-suggest">
              <span class="field-label">可能是这些客户</span>
              <?php foreach ($mail['suggestions'] as $s): ?>
                <form data-api action="<?= Layout::e($api) ?>?action=mail_link" class="mail-suggest-item">
                  <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="customer_id" value="<?= (int) $s['id'] ?>">
                  <span><strong><?= Layout::e($s['name']) ?></strong><small class="cell-sub"><?= Layout::e($s['reason']) ?></small></span>
                  <button type="submit" class="btn btn-sm">关联</button>
                </form>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <?php if ($canLink): ?><div><button type="button" class="btn btn-sm" data-open-dialog="d-link">选择客户关联</button></div><?php endif; ?>
        <?php endif; ?>
      </div>
    </section>
  </aside>
</div>

<?php if ($canLink): ?>
<dialog class="dialog dialog-sm" id="d-link" aria-labelledby="d-link-title">
  <form data-api action="<?= Layout::e($api) ?>?action=mail_link">
    <div class="dialog-head"><h2 class="dialog-title" id="d-link-title">关联客户</h2><button type="button" class="icon-btn" data-close-dialog aria-label="关闭">×</button></div>
    <div class="dialog-body stack">
      <p class="alert alert-error form-error" data-form-error hidden></p>
      <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="pick" value="1">
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
<?php Layout::end();
