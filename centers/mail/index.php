<?php
declare(strict_types=1);

/** 邮件工作台：指标、要处理的邮件、往来最多的客户、分类分布、邮箱状态。 */

require_once __DIR__ . '/bootstrap.php';

use Artdon\Centers\Base\Auth;
use Artdon\Centers\Base\Layout;
use Artdon\Centers\Base\PermissionException;
use Artdon\Centers\Base\Url;
use Artdon\Centers\Customer\Country;
use Artdon\Centers\Mail\MailAccounts;
use Artdon\Centers\Mail\Mailbox;
use Artdon\Centers\Mail\MailDashboard;
use Artdon\Centers\Mail\MailView;

$user = Auth::requirePage();
Auth::requirePermissionPage('mail.view');
$selfId = (int) $user['id'];
$ownerId = (int) ($_GET['user_id'] ?? 0) ?: $selfId;
try {
    $dash = new MailDashboard($user, $ownerId);
} catch (PermissionException $e) {
    Layout::forbidden('mail.view_all');
    return;
}
$box = $dash->mailbox();
$isSelf = $box->isSelf();
$owner = $isSelf ? 0 : $ownerId;
$accountsSvc = new MailAccounts($user);
$canAccounts = $accountsSvc->canManage($selfId) || $accountsSvc->canManageOthers();
$accounts = $box->accounts();
$names = $accountsSvc->ownerNames();
$ownerName = $isSelf ? Auth::displayName($user) : ($names[$ownerId] ?? ('#' . $ownerId));
$api = Url::center('mail', 'api.php');
$inbox = static function (array $q = []) use ($owner): string {
    $q = array_filter($q + ['user_id' => $owner], static function ($v): bool {
        return $v !== '' && $v !== 0 && $v !== null;
    });
    return Url::center('mail', 'inbox.php') . ($q ? '?' . http_build_query($q) : '');
};
$d = $accounts ? $dash->data() : null;
$dayLabels = $d ? array_map(static function (string $day): string {
    return date('n月j日', (int) strtotime($day));
}, $d['days']) : [];

Layout::start([
    'title' => '邮件工作台',
    'nav' => 'mail',
    'crumbs' => ['邮箱系统' => Url::center('mail', 'index.php'), '邮件工作台' => null],
    'styles' => [Url::centerAsset('mail', 'mail.css')],
    'scripts' => [Url::centerAsset('mail', 'mail.js')],
    'subnav' => MailView::subnav('dashboard', $owner, $d ? (int) $d['kpi']['unread'] : 0, $canAccounts),
]);
?>
<div class="page-head">
  <div>
    <h1 class="page-title"><?= $isSelf ? '邮件工作台' : Layout::e($ownerName) . ' 的邮件工作台' ?></h1>
    <p class="page-sub">
      <?php if (!$isSelf): ?>你在查看别人的邮件，只能看不能改，打开邮件会记录在日志里。
      <?php elseif ($accounts): ?><?= count($accounts) ?> 个邮箱 · 收信由服务器每隔几分钟自动同步<?php $last = max(array_map(static function ($a) { return (string) $a['last_sync_time']; }, $accounts)); ?><?= $last !== '' ? ' · 最近同步 ' . Layout::e(MailView::ago($last)) : '' ?>
      <?php else: ?>还没有绑定邮箱。<?php endif; ?>
    </p>
  </div>
  <div class="page-actions">
    <?php if (Mailbox::canViewAll($user)): ?><?= MailView::ownerSelect($names, $selfId, $ownerId, Url::center('mail', 'index.php')) ?><?php endif; ?>
    <?php if ($accounts): ?><a class="btn btn-primary" href="<?= Layout::e($inbox()) ?>">进入收件箱</a><?php endif; ?>
  </div>
</div>

<?php if (!$d): ?>
<section class="empty">
  <div class="empty-title"><?= $isSelf ? '先绑定一个邮箱' : '这个人还没有绑定邮箱' ?></div>
  <p class="empty-text">绑定后，这里会显示今天收到的邮件、客户来信、待回复跟进和往来最多的客户。需要邮箱的授权码（不是登录密码），可以在邮箱网页版的设置里生成。</p>
  <?php if ($isSelf && $accountsSvc->canManage($selfId)): ?><a class="btn btn-primary" href="<?= Layout::e(Url::center('mail', 'accounts.php')) ?>">绑定邮箱</a><?php endif; ?>
</section>
<?php else: $k = $d['kpi']; ?>

<div class="kpi-row">
  <a class="kpi-card" href="<?= Layout::e($inbox(['period' => 'today'])) ?>">
    <div>
      <div class="kpi-label">今日收信</div>
      <div class="kpi-num"><?= (int) $k['today'] ?><small>封</small></div>
      <?php $diff = $k['today'] - $k['yesterday']; ?>
      <div class="kpi-trend<?= $diff < 0 ? ' is-muted' : '' ?>"><?= $diff === 0 ? '和昨天一样' : ($diff > 0 ? '↑ 比昨天多 ' . $diff . ' 封' : '↓ 比昨天少 ' . (-$diff) . ' 封') ?></div>
    </div>
    <?= MailView::bars($k['in_daily'], $dayLabels) ?>
  </a>
  <a class="kpi-card" href="<?= Layout::e($inbox(['read' => 'unread'])) ?>">
    <div>
      <div class="kpi-label">收件箱未读</div>
      <div class="kpi-num is-brand"><?= (int) $k['unread'] ?><small>封</small></div>
      <div class="kpi-trend<?= $k['unread_customer'] > 0 ? ' is-bad' : ' is-muted' ?>"><?= $k['unread_customer'] > 0 ? '其中客户来信 ' . (int) $k['unread_customer'] . ' 封' : '没有未读的客户来信' ?></div>
    </div>
  </a>
  <a class="kpi-card" href="<?= Layout::e($inbox(['folder' => 'unreplied'])) ?>">
    <div>
      <div class="kpi-label">待回复跟进（发出超过 <?= Mailbox::UNREPLIED_DAYS ?> 天）</div>
      <div class="kpi-num<?= $k['unreplied'] > 0 ? ' is-bad' : '' ?>"><?= (int) $k['unreplied'] ?><small>封</small></div>
      <div class="kpi-trend<?= $k['unreplied'] > 0 ? ' is-bad' : ' is-muted' ?>"><?= $k['unreplied'] > 0 ? '最早的已经 ' . (int) $k['unreplied_oldest'] . ' 天没回音' : '发出的邮件都有回音' ?></div>
    </div>
  </a>
  <a class="kpi-card" href="<?= Layout::e($inbox(['cust' => 'linked', 'period' => '7d'])) ?>">
    <div>
      <div class="kpi-label">本周客户来信</div>
      <div class="kpi-num"><?= (int) $k['customer_week'] ?><small>封</small></div>
      <div class="kpi-trend is-muted">30 天内和 <?= (int) $k['customers_30'] ?> 个客户有往来 · 本周发出 <?= (int) $k['sent_week'] ?> 封</div>
    </div>
    <?= MailView::bars($k['customer_daily'], $dayLabels, 'is-accent') ?>
  </a>
</div>

<section class="card">
  <div class="card-head"><h2 class="card-title">最近 30 天收件</h2><span class="muted">共 <?= (int) ($d['categories']['total'] ?? 0) ?> 封 · 点标签直接筛选</span></div>
  <div class="card-body dim-matrix">
    <div class="dim-box">
      <div class="dim-title">1. 邮件分类</div>
      <div class="pill-wrap">
        <?php foreach (Mailbox::CATEGORIES as $cat => $label): ?>
          <a class="pill" href="<?= Layout::e($inbox(['cat' => $cat, 'period' => '30d'])) ?>"><span><?= Layout::e($label) ?></span><b class="pill-count"><?= (int) ($d['categories'][$cat] ?? 0) ?></b></a>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="dim-box">
      <div class="dim-title">2. 客户关联</div>
      <div class="pill-wrap">
        <a class="pill" href="<?= Layout::e($inbox(['cust' => 'linked', 'period' => '30d'])) ?>"><span>已关联客户</span></a>
        <a class="pill" href="<?= Layout::e($inbox(['cust' => 'unlinked', 'period' => '30d'])) ?>"><span>未关联客户</span></a>
        <?php if ($d['maybe_customer']): ?><span class="pill pill-warn"><span><?= count($d['maybe_customer']) ?> 封可能是客户来信</span></span><?php endif; ?>
      </div>
    </div>
    <div class="dim-box">
      <div class="dim-title">3. 处理状态</div>
      <div class="pill-wrap">
        <a class="pill" href="<?= Layout::e($inbox(['read' => 'unread'])) ?>"><span>未读</span><b class="pill-count"><?= (int) $k['unread'] ?></b></a>
        <a class="pill" href="<?= Layout::e($inbox(['folder' => 'starred'])) ?>"><span>星标</span></a>
        <a class="pill pill-accent" href="<?= Layout::e($inbox(['folder' => 'unreplied'])) ?>"><span>待回复跟进</span><b class="pill-count"><?= (int) $k['unreplied'] ?></b></a>
      </div>
    </div>
    <div class="dim-box">
      <div class="dim-title">4. 附件与时间</div>
      <div class="pill-wrap">
        <a class="pill" href="<?= Layout::e($inbox(['att' => '1', 'period' => '30d'])) ?>"><span>带附件</span></a>
        <a class="pill" href="<?= Layout::e($inbox(['period' => 'today'])) ?>"><span>今天</span></a>
        <a class="pill" href="<?= Layout::e($inbox(['period' => '7d'])) ?>"><span>最近 7 天</span></a>
      </div>
    </div>
  </div>
</section>

<div class="grid-2">
  <section class="card">
    <div class="card-head"><h2 class="card-title">客户来信 · 还没回复</h2><a class="card-link" href="<?= Layout::e($inbox(['cust' => 'linked'])) ?>">全部客户来信 →</a></div>
    <?php if (!$d['customer_waiting']): ?>
      <div class="card-body muted">最近 14 天的客户来信都处理了。</div>
    <?php else: ?>
    <ul class="panel-list">
      <?php foreach ($d['customer_waiting'] as $m): ?>
        <li><a class="panel-row<?= (int) $m['is_read'] === 0 ? ' is-unread' : '' ?>" href="<?= Layout::e($inbox(['cust' => 'linked', 'id' => (int) $m['id']])) ?>">
          <?= MailView::letter((string) $m['from_name'], (string) $m['from_email']) ?>
          <span class="panel-row-main"><strong><?= (int) $m['is_read'] === 0 ? '<span class="dot-unread" aria-label="未读"></span>' : '' ?><?= Layout::e((string) $m['subject'] !== '' ? (string) $m['subject'] : '（无主题）') ?></strong><small><?= Layout::e((string) $m['customer_name']) ?> · <?= Layout::e((string) ($m['from_name'] ?: $m['from_email'])) ?></small></span>
          <span class="panel-row-side"><?= MailView::categoryPill((string) $m['category']) ?><?= Layout::e(MailView::ago((string) $m['time'])) ?></span>
        </a></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </section>
  <section class="card">
    <div class="card-head"><h2 class="card-title">待回复跟进 · 发出后没回音</h2><a class="card-link" href="<?= Layout::e($inbox(['folder' => 'unreplied'])) ?>">全部 <?= (int) $k['unreplied'] ?> 封 →</a></div>
    <?php if (!$d['unreplied']): ?>
      <div class="card-body muted">没有超过 <?= Mailbox::UNREPLIED_DAYS ?> 天没回音的邮件。</div>
    <?php else: ?>
    <ul class="panel-list">
      <?php foreach ($d['unreplied'] as $m): $days = (int) floor((time() - (int) strtotime((string) $m['time'])) / 86400); ?>
        <li><a class="panel-row" href="<?= Layout::e($inbox(['folder' => 'unreplied', 'id' => (int) $m['id']])) ?>">
          <?= MailView::letter(Mailbox::displayAddresses((string) $m['to_emails'], 1), (string) (Mailbox::addresses((string) $m['to_emails'])[0] ?? '')) ?>
          <span class="panel-row-main"><strong><?= Layout::e((string) $m['subject'] !== '' ? (string) $m['subject'] : '（无主题）') ?></strong><small>发给 <?= Layout::e(Mailbox::displayAddresses((string) $m['to_emails'])) ?><?= !empty($m['linked_customer_name']) ? ' · ' . Layout::e((string) $m['linked_customer_name']) : '' ?></small></span>
          <span class="panel-row-side"><span class="action-badge <?= $days >= 7 ? 'action-bad' : 'action-warn' ?>"><?= $days ?> 天没回音</span></span>
        </a></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </section>
</div>

<div class="gallery">
  <section class="card">
    <div class="card-head"><h2 class="card-title">往来最多的客户（30 天）</h2><span class="muted">按收发邮件数排序</span></div>
    <?php if (!$d['top_customers']): ?>
      <div class="card-body muted">最近 30 天还没有关联到客户的邮件。在邮件里点“关联客户”后，这里会统计。</div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th scope="col">客户</th><th scope="col">往来</th><th scope="col">收 / 发</th><th scope="col">最近一封</th><th scope="col"><span class="sr-only">操作</span></th></tr></thead>
        <tbody>
        <?php foreach ($d['top_customers'] as $c): ?>
          <tr>
            <td><a class="row-link" href="<?= Layout::e(Url::center('customer', 'view.php?id=' . (int) $c['id'])) ?>"><?= Layout::e((string) $c['customer_name']) ?></a><small class="cell-sub"><?= Layout::e(Country::label((string) $c['country'])) ?></small></td>
            <td><div class="rate"><div class="rate-head"><strong><?= $c['received'] + $c['sent'] ?> 封</strong></div><div class="rate-bar"><span style="width:<?= (int) $c['percent'] ?>%"></span></div></div></td>
            <td class="nowrap"><span class="action-badge action-info">收 <?= (int) $c['received'] ?></span> <span class="action-badge action-good">发 <?= (int) $c['sent'] ?></span></td>
            <td class="muted nowrap"><?= Layout::e(MailView::ago((string) $c['last_time'])) ?></td>
            <td class="cell-actions"><a class="btn btn-sm" href="<?= Layout::e($inbox(['folder' => 'linked', 'customer' => (int) $c['id']])) ?>">看邮件</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </section>

  <aside class="drawer" aria-label="提醒">
    <div>
      <div class="drawer-kicker">可能是客户的来信</div>
      <p class="muted" style="margin:4px 0 0">发件人的邮箱或域名和客户资料对得上，但还没关联。</p>
    </div>
    <?php if (!$d['maybe_customer']): ?>
      <p class="muted" style="margin:0">最近 14 天没有。</p>
    <?php else: ?>
      <div class="drawer-section">
        <?php foreach ($d['maybe_customer'] as $m): ?>
          <div class="mini-item">
            <a class="row-link" href="<?= Layout::e($inbox(['id' => (int) $m['id']])) ?>"><?= Layout::e((string) $m['subject'] !== '' ? (string) $m['subject'] : '（无主题）') ?></a>
            <small><?= Layout::e((string) ($m['from_name'] ?: $m['from_email'])) ?> · <?= Layout::e(MailView::ago((string) $m['time'])) ?></small>
            <div class="mini-item-foot"><span class="pill pill-brand"><span><?= Layout::e($m['suggestion']['name']) ?></span></span><small><?= Layout::e($m['suggestion']['reason']) ?></small>
              <?php if ($box->can('mail.link_customer')): ?>
                <form data-api action="<?= Layout::e($api) ?>?action=mail_link"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>"><input type="hidden" name="customer_id" value="<?= (int) $m['suggestion']['id'] ?>"><button type="submit" class="btn btn-sm">关联</button></form>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <div class="drawer-section">
      <div class="drawer-section-title">邮箱状态</div>
      <?php foreach ($d['accounts'] as $a): [$sText, $sKind] = $a['state']; ?>
        <div class="mini-item">
          <strong class="truncate" style="display:block"><?= Layout::e((string) $a['email_address']) ?></strong>
          <small><span class="pill <?= ['good' => 'pill-good', 'warn' => 'pill-warn', 'bad' => 'pill-accent'][$sKind] ?? '' ?>" style="height:20px"><?= Layout::e($sText) ?></span> 30 天收 <?= (int) $a['received_30'] ?> · 发 <?= (int) $a['sent_30'] ?><?= $a['last_sync_time'] ? ' · ' . Layout::e(MailView::ago((string) $a['last_sync_time'])) . '同步' : '' ?></small>
        </div>
      <?php endforeach; ?>
      <?php if ($canAccounts): ?><a class="btn btn-sm" href="<?= Layout::e(Url::center('mail', 'accounts.php')) ?>">管理邮箱</a><?php endif; ?>
    </div>
  </aside>
</div>
<?php endif; ?>
<?php Layout::end();
