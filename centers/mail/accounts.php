<?php
declare(strict_types=1);

/**
 * 邮箱设置：绑定、修改、测试、设为默认、停用、删除；发件人名称和签名。
 * 自己的邮箱：mail.account_bind_own；帮别人维护：mail.account_manage_all（不能读别人的信）。
 */

require_once __DIR__ . '/bootstrap.php';

use Artdon\Centers\Base\Auth;
use Artdon\Centers\Base\Form;
use Artdon\Centers\Base\Layout;
use Artdon\Centers\Base\Url;
use Artdon\Centers\Base\UserDirectory;
use Artdon\Centers\Mail\MailAccounts;
use Artdon\Centers\Mail\MailView;

$user = Auth::requirePage();
$service = new MailAccounts($user);
$selfId = (int) $user['id'];
$ownerId = (int) ($_GET['user_id'] ?? 0) ?: $selfId;
if (!$service->canManage($ownerId)) {
    Layout::forbidden($ownerId === $selfId ? 'mail.account_bind_own' : 'mail.account_manage_all');
    return;
}
$isSelf = $ownerId === $selfId;
$accounts = $service->forUser($ownerId);
$stats = $service->stats($ownerId);
$api = Url::center('mail', 'api.php');
$self = Url::center('mail', 'accounts.php');
$ownerName = '';
foreach (UserDirectory::active() as $u) {
    if ($u['id'] === $ownerId) {
        $ownerName = $u['name'];
    }
}
$presets = [];
foreach (MailAccounts::PRESETS as $k => $p) {
    $presets[$k] = ['label' => $p[0], 'imap_host' => $p[1], 'imap_port' => $p[2], 'imap_secure' => $p[3], 'smtp_host' => $p[4], 'smtp_port' => $p[5], 'smtp_secure' => $p[6]];
}
$exmail = $presets['exmail'];
$newFill = json_encode(['id' => '', 'title_text' => '绑定邮箱', 'secret_hint' => '', 'imap_host' => $exmail['imap_host'], 'imap_port' => (string) $exmail['imap_port'], 'imap_secure' => 'ssl',
    'smtp_host' => $exmail['smtp_host'], 'smtp_port' => (string) $exmail['smtp_port'], 'smtp_secure' => 'ssl', 'preset' => 'exmail', 'sender_name' => $isSelf ? Auth::displayName($user) : $ownerName], JSON_UNESCAPED_UNICODE);
$secure = MailAccounts::SECURE;

Layout::start([
    'title' => '邮箱设置',
    'nav' => 'mail',
    'crumbs' => ['邮箱系统' => Url::center('mail', 'index.php'), '邮箱设置' => null],
    'styles' => [Url::centerAsset('mail', 'mail.css')],
    'scripts' => [Url::centerAsset('mail', 'mail.js')],
    'subnav' => MailView::subnav('accounts', 0, 0, true),
]);
?>
<div class="page-head">
  <div>
    <h1 class="page-title"><?= $isSelf ? '邮箱设置' : Layout::e($ownerName) . ' 的邮箱' ?></h1>
    <p class="page-sub"><?= $isSelf ? '可以绑定多个邮箱，发信时默认用“默认邮箱”。' : '你在帮同事维护邮箱账号。维护账号不能查看对方的邮件。' ?>授权码加密保存，页面上不会显示。</p>
  </div>
  <div class="page-actions">
    <?php if ($service->canManageOthers()): ?>
    <form class="mail-owner" method="get" action="<?= Layout::e($self) ?>">
      <label class="sr-only" for="ac-owner">维护谁的邮箱</label>
      <select class="input select" id="ac-owner" name="user_id" data-autosubmit>
        <option value="<?= $selfId ?>">我的邮箱</option>
        <?php foreach (UserDirectory::grouped() as $dept => $people): ?>
          <optgroup label="<?= Layout::e($dept) ?>">
            <?php foreach ($people as $uid => $name): if ((int) $uid === $selfId) { continue; } ?><option value="<?= (int) $uid ?>"<?= (int) $uid === $ownerId ? ' selected' : '' ?>><?= Layout::e($name) ?></option><?php endforeach; ?>
          </optgroup>
        <?php endforeach; ?>
      </select>
      <noscript><button type="submit" class="btn btn-sm">切换</button></noscript>
    </form>
    <?php endif; ?>
    <button type="button" class="btn btn-primary" data-open-dialog="d-account" data-fill="<?= Layout::e((string) $newFill) ?>">＋ 绑定邮箱</button>
  </div>
</div>

<?php if (!$accounts): ?>
<section class="empty">
  <div class="empty-title"><?= $isSelf ? '你还没有绑定邮箱' : '还没有绑定邮箱' ?></div>
  <p class="empty-text">需要：邮箱地址、授权码（在邮箱网页版“设置 → 账户 → 客户端专用密码 / 授权码”里生成）。腾讯企业邮的服务器已经预先填好。</p>
  <button type="button" class="btn btn-primary" data-open-dialog="d-account" data-fill="<?= Layout::e((string) $newFill) ?>">绑定邮箱</button>
</section>
<?php else:
    $selectedId = (int) ($_GET['id'] ?? 0);
    $selected = null;
    foreach ($accounts as $a) {
        if ((int) $a['id'] === $selectedId) {
            $selected = $a;
        }
    }
    $selected = $selected ?? $accounts[0];
    $selectedId = (int) $selected['id'];
    $cardUrl = static function (int $id) use ($self, $ownerId, $selfId): string {
        return $self . '?' . http_build_query(array_filter(['user_id' => $ownerId === $selfId ? 0 : $ownerId, 'id' => $id]));
    };
?>
<div class="kpi-row">
  <div class="kpi-card"><div><div class="kpi-label">已绑定邮箱</div><div class="kpi-num"><?= count($accounts) ?><small>个</small></div><div class="kpi-trend is-muted">启用 <?= count(array_filter($accounts, static function ($a) { return (int) $a['is_enabled'] === 1; })) ?> 个</div></div></div>
  <div class="kpi-card"><div><div class="kpi-label">30 天收信</div><div class="kpi-num is-brand"><?= array_sum(array_column($stats, 'received')) ?><small>封</small></div><div class="kpi-trend is-muted">未读 <?= array_sum(array_column($stats, 'unread')) ?> 封</div></div></div>
  <div class="kpi-card"><div><div class="kpi-label">30 天发信</div><div class="kpi-num"><?= array_sum(array_column($stats, 'sent')) ?><small>封</small></div><div class="kpi-trend is-muted">含从邮箱服务器同步回来的</div></div></div>
  <?php $bad = count(array_filter($accounts, static function ($a) { return MailAccounts::state($a)[1] === 'bad'; })); ?>
  <div class="kpi-card"><div><div class="kpi-label">收信出错</div><div class="kpi-num<?= $bad ? ' is-bad' : '' ?>"><?= $bad ?><small>个</small></div><div class="kpi-trend<?= $bad ? ' is-bad' : ' is-muted' ?>"><?= $bad ? '请检查授权码或服务器设置' : '全部正常' ?></div></div></div>
</div>

<div class="gallery">
  <div class="g-grid">
  <?php foreach ($accounts as $a):
      $aid = (int) $a['id'];
      [$sText, $sKind] = MailAccounts::state($a);
      $st = $stats[$aid] ?? ['received' => 0, 'sent' => 0, 'unread' => 0, 'total' => 0]; ?>
    <a class="g-card mail-account<?= $aid === $selectedId ? ' is-selected' : '' ?>" href="<?= Layout::e($cardUrl($aid)) ?>"<?= $aid === $selectedId ? ' aria-current="true"' : '' ?>>
      <div class="g-card-head">
        <div style="min-width:0">
          <div class="pill-wrap" style="margin-bottom:6px">
            <span class="pill <?= ['good' => 'pill-good', 'warn' => 'pill-warn', 'bad' => 'pill-accent'][$sKind] ?? '' ?>"><?= Layout::e($sText) ?></span>
            <?php if ((int) $a['is_default'] === 1): ?><span class="pill pill-brand">默认发信</span><?php endif; ?>
          </div>
          <h2 class="g-card-title mail-account-title"><?= Layout::e((string) $a['email_address']) ?></h2>
          <small class="muted"><?= (string) $a['sender_name'] !== '' ? Layout::e((string) $a['sender_name']) : '未设置发件人名称' ?></small>
        </div>
        <div class="g-card-stat"><strong><?= (int) $st['received'] ?></strong><small>30 天收信</small></div>
      </div>
      <div class="thumbs">
        <div class="thumb"><strong><?= (int) $st['sent'] ?></strong><span>30 天发信</span></div>
        <div class="thumb"><strong><?= (int) $st['unread'] ?></strong><span>未读</span></div>
        <div class="thumb"><strong><?= (int) $st['total'] ?></strong><span>邮件总数</span></div>
      </div>
      <div class="g-card-foot">
        <small class="muted"><?= $a['last_sync_time'] ? '最近同步 ' . Layout::e(MailView::ago((string) $a['last_sync_time'])) : '还没有同步过' ?></small>
        <span class="card-link-text"><?= $aid === $selectedId ? '详情在右侧 →' : '查看详情' ?></span>
      </div>
    </a>
  <?php endforeach; ?>
    <button type="button" class="g-card g-card-add" data-open-dialog="d-account" data-fill="<?= Layout::e((string) $newFill) ?>"><span style="font-size:24px">＋</span><span>绑定新邮箱</span></button>
  </div>

  <?php
    $a = $selected;
    $aid = $selectedId;
    [$sText, $sKind] = MailAccounts::state($a);
    $fill = json_encode([
        'id' => $aid, 'title_text' => '修改邮箱', 'secret_hint' => '留空表示不修改授权码', 'preset' => '',
        'email_address' => $a['email_address'], 'email_username' => $a['email_username'] === $a['email_address'] ? '' : $a['email_username'],
        'imap_host' => $a['imap_host'], 'imap_port' => (string) $a['imap_port'], 'imap_secure' => $a['imap_secure'] ?: 'ssl',
        'smtp_host' => $a['smtp_host'], 'smtp_port' => (string) $a['smtp_port'], 'smtp_secure' => $a['smtp_secure'] ?: 'ssl',
        'sender_name' => (string) $a['sender_name'], 'signature_html' => (string) $a['signature_html'],
    ], JSON_UNESCAPED_UNICODE); ?>
  <aside class="drawer" aria-labelledby="acc-title">
    <div>
      <div class="drawer-kicker">当前选中的邮箱</div>
      <h2 class="drawer-title" id="acc-title"><?= Layout::e((string) $a['email_address']) ?></h2>
    </div>
    <div class="row-actions" style="justify-content:flex-start;flex-wrap:wrap">
      <button type="button" class="btn btn-sm btn-primary" data-open-dialog="d-account" data-fill="<?= Layout::e((string) $fill) ?>">修改设置</button>
      <form data-api data-test-form="<?= $aid ?>" action="<?= Layout::e($api) ?>?action=account_test"><input type="hidden" name="user_id" value="<?= $ownerId ?>"><input type="hidden" name="id" value="<?= $aid ?>"><input type="hidden" name="type" value="imap"><button type="submit" class="btn btn-sm">测试收信</button></form>
      <form data-api data-test-form="<?= $aid ?>" action="<?= Layout::e($api) ?>?action=account_test"><input type="hidden" name="user_id" value="<?= $ownerId ?>"><input type="hidden" name="id" value="<?= $aid ?>"><input type="hidden" name="type" value="smtp"><button type="submit" class="btn btn-sm">测试发信</button></form>
    </div>
    <p class="mail-test-result" data-test-result="<?= $aid ?>" role="status" hidden></p>
    <?php if (trim((string) $a['sync_error']) !== ''): ?><p class="alert alert-error" style="margin:0">最近收信出错：<?= Layout::e(mb_substr((string) $a['sync_error'], 0, 300, 'UTF-8')) ?></p><?php endif; ?>
    <?php if ((int) $a['has_secret'] !== 1): ?><p class="alert alert-error" style="margin:0">没有保存授权码，请点“修改设置”重新填写。</p><?php endif; ?>
    <div class="drawer-section">
      <div class="drawer-section-title">设置</div>
      <dl class="mail-meta">
        <dt>状态</dt><dd><span class="pill <?= ['good' => 'pill-good', 'warn' => 'pill-warn', 'bad' => 'pill-accent'][$sKind] ?? '' ?>"><?= Layout::e($sText) ?></span></dd>
        <dt>发件人</dt><dd><?= (string) $a['sender_name'] !== '' ? Layout::e((string) $a['sender_name']) : '<span class="muted">未设置（对方看到的是邮箱地址）</span>' ?></dd>
        <dt>登录账号</dt><dd><?= Layout::e((string) $a['email_username']) ?></dd>
        <dt>收信</dt><dd><?= Layout::e($a['imap_host'] . ':' . $a['imap_port'] . ' · ' . ($secure[$a['imap_secure']] ?? $a['imap_secure'])) ?></dd>
        <dt>发信</dt><dd><?= Layout::e($a['smtp_host'] . ':' . $a['smtp_port'] . ' · ' . ($secure[$a['smtp_secure']] ?? $a['smtp_secure'])) ?></dd>
        <dt>最近同步</dt><dd><?= $a['last_sync_time'] ? Layout::e((string) $a['last_sync_time']) : '<span class="muted">还没有</span>' ?></dd>
        <dt>绑定时间</dt><dd><?= Layout::e((string) $a['created_at']) ?></dd>
      </dl>
    </div>
    <div class="drawer-section">
      <div class="drawer-section-title">签名预览</div>
      <?php if (trim(strip_tags((string) $a['signature_html'], '<img>')) === ''): ?>
        <p class="muted" style="margin:0">还没有签名。点“修改设置”添加，写信时会自动加在正文下面。</p>
      <?php else: ?>
        <iframe class="mail-signature-preview" title="签名预览" sandbox="" srcdoc="<?= Layout::e('<!doctype html><meta charset="utf-8"><style>body{margin:8px;font:13px/1.5 sans-serif;color:#18181b}img{max-width:100%}</style>' . \Artdon\Centers\Mail\MailBody::clean((string) $a['signature_html'])) ?>"></iframe>
      <?php endif; ?>
    </div>
    <div class="drawer-section">
      <div class="drawer-section-title">其他操作</div>
      <div class="row-actions" style="justify-content:flex-start;flex-wrap:wrap">
        <?php if ((int) $a['is_default'] !== 1 && (int) $a['is_enabled'] === 1): ?>
          <form data-api action="<?= Layout::e($api) ?>?action=account_default"><input type="hidden" name="user_id" value="<?= $ownerId ?>"><input type="hidden" name="id" value="<?= $aid ?>"><button type="submit" class="btn btn-sm">设为默认发信</button></form>
        <?php endif; ?>
        <form data-api action="<?= Layout::e($api) ?>?action=account_enable"><input type="hidden" name="user_id" value="<?= $ownerId ?>"><input type="hidden" name="id" value="<?= $aid ?>"><input type="hidden" name="enabled" value="<?= (int) $a['is_enabled'] === 1 ? 0 : 1 ?>"><button type="submit" class="btn btn-sm"><?= (int) $a['is_enabled'] === 1 ? '停用' : '启用' ?></button></form>
        <button type="button" class="btn btn-sm btn-danger" data-open-dialog="d-account-delete" data-fill="<?= Layout::e((string) json_encode(['id' => $aid, 'email_text' => $a['email_address']], JSON_UNESCAPED_UNICODE)) ?>">删除</button>
      </div>
    </div>
  </aside>
</div>
<?php endif; ?>

<dialog class="dialog dialog-wide" id="d-account" aria-labelledby="d-account-title">
  <form data-api action="<?= Layout::e($api) ?>?action=account_save" data-account-form data-presets="<?= Layout::e((string) json_encode($presets, JSON_UNESCAPED_UNICODE)) ?>">
    <div class="dialog-head"><h2 class="dialog-title" id="d-account-title" data-text="title_text">绑定邮箱</h2><button type="button" class="icon-btn" data-close-dialog aria-label="关闭">×</button></div>
    <div class="dialog-body form-grid">
      <p class="alert alert-error form-error span-2" data-form-error hidden></p>
      <input type="hidden" name="id" value="">
      <input type="hidden" name="user_id" value="<?= $ownerId ?>">
      <?= Form::input('email_address', '邮箱地址', '', [], ['prefix' => 'ac', 'required' => true, 'type' => 'email', 'maxlength' => 190, 'autocomplete' => 'off']) ?>
      <div class="field">
        <label class="field-label" for="ac-mail_secret">授权码<span class="field-req" aria-hidden="true" data-new-only>*</span></label>
        <input class="input" id="ac-mail_secret" name="mail_secret" type="password" autocomplete="new-password" maxlength="200" aria-describedby="ac-secret-hint">
        <p class="field-hint" id="ac-secret-hint"><span data-text="secret_hint"></span> 授权码不是邮箱登录密码，在邮箱网页版设置里生成。</p>
      </div>
      <?= Form::input('sender_name', '发件人名称', '', [], ['prefix' => 'ac', 'maxlength' => 190, 'hint' => '对方收件箱里显示的名字，例如 Lei Qiu | Artdon Lighting']) ?>
      <div class="field">
        <label class="field-label" for="ac-preset">邮箱类型</label>
        <select class="input select" id="ac-preset" name="preset" data-preset>
          <option value="">自定义 / 保持当前设置</option>
          <?php foreach ($presets as $k => $p): ?><option value="<?= Layout::e($k) ?>"><?= Layout::e($p['label']) ?></option><?php endforeach; ?>
        </select>
        <p class="field-hint">选择后自动填好下面的服务器设置。</p>
      </div>
      <details class="span-2 mail-server">
        <summary>服务器设置（一般不用改）</summary>
        <div class="form-grid">
          <?= Form::input('email_username', '登录账号', '', [], ['prefix' => 'ac', 'maxlength' => 190, 'hint' => '和邮箱地址不同时才需要填写']) ?>
          <div></div>
          <?= Form::input('imap_host', '收信服务器（IMAP）', '', [], ['prefix' => 'ac', 'maxlength' => 190, 'required' => true]) ?>
          <div class="mail-port">
            <?= Form::input('imap_port', '端口', '993', [], ['prefix' => 'ac', 'inputmode' => 'numeric', 'maxlength' => 5]) ?>
            <?= Form::select('imap_secure', '加密', $secure, 'ssl', [], ['prefix' => 'ac']) ?>
          </div>
          <?= Form::input('smtp_host', '发信服务器（SMTP）', '', [], ['prefix' => 'ac', 'maxlength' => 190, 'required' => true]) ?>
          <div class="mail-port">
            <?= Form::input('smtp_port', '端口', '465', [], ['prefix' => 'ac', 'inputmode' => 'numeric', 'maxlength' => 5]) ?>
            <?= Form::select('smtp_secure', '加密', $secure, 'ssl', [], ['prefix' => 'ac']) ?>
          </div>
        </div>
      </details>
      <div class="field span-2">
        <label class="field-label" for="ac-signature_html">签名</label>
        <textarea class="input mail-signature-input" id="ac-signature_html" name="signature_html" rows="6" maxlength="60000" aria-describedby="ac-sig-hint"></textarea>
        <p class="field-hint" id="ac-sig-hint">写信时自动加在正文下面。可以直接粘贴 HTML；可用变量：{mail_user_name} 姓名、{mail_user_mobile} 手机、{mail_user_position} 职位、{send_email} 发信邮箱、{customer_name} 客户名称。</p>
      </div>
      <label class="check span-2"><input type="checkbox" name="is_default" value="1"><span>设为默认发信邮箱</span></label>
    </div>
    <div class="dialog-foot"><button type="button" class="btn" data-close-dialog>取消</button><button type="submit" class="btn btn-primary">保存</button></div>
  </form>
</dialog>

<dialog class="dialog dialog-sm" id="d-account-delete" aria-labelledby="d-account-delete-title">
  <form data-api action="<?= Layout::e($api) ?>?action=account_delete">
    <div class="dialog-head"><h2 class="dialog-title" id="d-account-delete-title">删除邮箱</h2><button type="button" class="icon-btn" data-close-dialog aria-label="关闭">×</button></div>
    <div class="dialog-body stack">
      <p class="alert alert-error form-error" data-form-error hidden></p>
      <input type="hidden" name="id" value=""><input type="hidden" name="user_id" value="<?= $ownerId ?>">
      <p style="margin:0">确定删除 <strong data-text="email_text"></strong> 吗？删除后不再收信、不能用它发信；已经收到的邮件仍然保留。</p>
    </div>
    <div class="dialog-foot"><button type="button" class="btn" data-close-dialog>取消</button><button type="submit" class="btn btn-danger">删除</button></div>
  </form>
</dialog>
<?php Layout::end();
