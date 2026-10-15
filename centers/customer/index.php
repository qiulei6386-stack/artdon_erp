<?php
declare(strict_types=1);

/** 客户中心：客户列表（我的客户 / 全部客户 / 公海 / 已删除）。 */

require_once __DIR__ . '/bootstrap.php';

use Artdon\Centers\Base\Auth;
use Artdon\Centers\Base\Dictionary;
use Artdon\Centers\Base\Layout;
use Artdon\Centers\Base\Pager;
use Artdon\Centers\Base\Url;
use Artdon\Centers\Base\UserDirectory;
use Artdon\Centers\Customer\Country;
use Artdon\Centers\Customer\CustomerForm;
use Artdon\Centers\Customer\CustomerScope;
use Artdon\Centers\Customer\CustomerService;
use Artdon\Centers\Customer\GroupService;
use Artdon\Centers\Customer\LeadPool;
use Artdon\Centers\Customer\RuleConfig;

$user = Auth::requirePage();
Auth::requirePermissionPage('customer.view');
$service = new CustomerService($user);
$tabs = $service->tabs();
$tab = (string) ($_GET['tab'] ?? 'mine');
if (!isset($tabs[$tab])) {
    $tab = 'mine';
}
$str = static function (string $key): string {
    $v = $_GET[$key] ?? '';
    return is_string($v) ? trim($v) : '';
};
$filters = [
    'q' => $str('q'), 'country' => $str('country'), 'level' => $str('level'), 'lifecycle' => $str('lifecycle'),
    'owner_id' => (int) ($_GET['owner_id'] ?? 0), 'group_id' => (int) ($_GET['group_id'] ?? 0), 'follow' => $str('follow'), 'created' => $str('created'),
    'no_contact' => $str('no_contact') === '1' ? '1' : '', 'sort' => $str('sort'),
];
$result = $service->list($tab, $filters, (int) ($_GET['page'] ?? 1));
$counts = $service->counts();
$self = Url::center('customer', 'index.php');
$api = Url::center('customer', 'api.php');
$link = static function (array $changes) use ($filters, $self, $tab): string {
    $query = array_filter(array_merge(['tab' => $tab], $filters, $changes), static function ($v): bool {
        return $v !== '' && $v !== 0 && $v !== null;
    });
    return $self . '?' . http_build_query($query);
};
$filtered = (bool) array_filter(array_diff_key($filters, ['sort' => 1]));
$can = [
    'create' => $service->can('customer.create'),
    'assign' => $service->can('customer.assign'),
    'public' => $service->can('customer.transfer_public'),
    'claim' => $service->can('customer.claim_public'),
    'restore' => $service->can('customer.restore'),
    'merge' => $service->can('customer.merge'),
    'groups' => $service->can('customer.batch'),
    'leads' => LeadPool::canView($user),
];
$leadCount = $can['leads'] ? (new LeadPool($user))->counts()['pending'] : 0;
$bulk = in_array($tab, ['mine', 'all', 'public'], true) && ($can['assign'] || $can['merge'] || $can['groups'] || ($can['public'] && $tab !== 'public'));
$options = CustomerService::options();
$groupOptions = GroupService::options();
$lifeBadge = static function (string $key): string {
    $map = ['deal' => 'badge-good', 'quoting' => 'badge-brand', 'sampling' => 'badge-brand', 'key_follow' => 'badge-brand', 'official' => 'badge-good', 'sleeping' => 'badge-muted', 'lost' => 'badge-bad', 'blacklist' => 'badge-bad'];
    return $map[$key] ?? '';
};
$followText = static function (?string $time): array {
    if (!$time) {
        return ['从未跟进', 'muted'];
    }
    $days = (int) floor((time() - strtotime($time)) / 86400);
    $text = $days <= 0 ? '今天' : $days . ' 天前';
    return [$text, $days > 30 ? 'text-bad' : ($days > 15 ? 'text-warn' : '')];
};
$defaults = [
    'level' => (string) RuleConfig::defaultValue('customer_level', Dictionary::defaultKey('customer_level')),
    'lifecycle_key' => Dictionary::defaultKey('customer_lifecycle'),
    'source_tags' => array_values(array_filter([(string) RuleConfig::defaultValue('source', Dictionary::defaultKey('customer_source'))], static function (string $k): bool {
        return Dictionary::has('customer_source', $k);
    })),
    'promotion_channels' => array_values(array_filter((array) RuleConfig::defaultValue('promotion_channels', [Dictionary::defaultKey('promotion_channel')]), static function ($k): bool {
        return Dictionary::has('promotion_channel', (string) $k);
    })),
];

Layout::start([
    'title' => '客户中心',
    'nav' => 'customer',
    'crumbs' => ['客户中心' => null],
    'scripts' => [Url::centerAsset('customer', 'customer.js')],
    'styles' => [Url::centerAsset('customer', 'customer.css')],
]);
?>
<div class="page-head">
  <div>
    <h1 class="page-title">客户中心</h1>
    <p class="page-sub">你能看到的范围：<?= Layout::e(CustomerScope::describe($user)) ?>。新建客户按准入规则：<?= Layout::e(RuleConfig::ADMISSION_LABELS[RuleConfig::admissionMode()] ?? '') ?>。</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= Layout::e(Url::center('customer', 'groups.php')) ?>">分组</a>
    <?php if ($can['leads']): ?><a class="btn" href="<?= Layout::e(Url::center('customer', 'leads.php')) ?>">暂存池<?= $leadCount ? ' <span class="tab-count">' . $leadCount . '</span>' : '' ?></a><?php endif; ?>
    <?php if ($can['create']): ?><button type="button" class="btn btn-primary" data-open-dialog="d-create">新建客户</button><?php endif; ?>
  </div>
</div>

<nav class="tabs" aria-label="客户分类">
  <?php foreach ($tabs as $key => $label): $on = $key === $tab; ?>
    <a class="tab<?= $on ? ' is-active' : '' ?>" href="<?= Layout::e($self . '?tab=' . $key) ?>"<?= $on ? ' aria-current="page"' : '' ?>><?= Layout::e($label) ?><?php if (isset($counts[$key])): ?> <span class="tab-count"><?= (int) $counts[$key] ?></span><?php endif; ?></a>
  <?php endforeach; ?>
</nav>

<form class="filters" method="get" action="<?= Layout::e($self) ?>" role="search">
  <input type="hidden" name="tab" value="<?= Layout::e($tab) ?>">
  <label class="sr-only" for="fq">搜索客户</label>
  <input class="input input-search" id="fq" type="search" name="q" value="<?= Layout::e($filters['q']) ?>" placeholder="名称、代码、邮箱、电话、联系人">
  <label class="sr-only" for="fc">国家</label>
  <select class="input select" id="fc" name="country"><option value="">全部国家</option>
    <?php foreach ($options['country'] as $k => $v): ?><option value="<?= Layout::e($k) ?>"<?= $filters['country'] === $k ? ' selected' : '' ?>><?= Layout::e($v) ?></option><?php endforeach; ?>
  </select>
  <label class="sr-only" for="fl">等级</label>
  <select class="input select" id="fl" name="level"><option value="">全部等级</option>
    <?php foreach ($options['level'] as $k => $v): ?><option value="<?= Layout::e($k) ?>"<?= $filters['level'] === $k ? ' selected' : '' ?>><?= Layout::e($v) ?></option><?php endforeach; ?>
  </select>
  <label class="sr-only" for="fs">阶段</label>
  <select class="input select" id="fs" name="lifecycle"><option value="">全部阶段</option>
    <?php foreach ($options['lifecycle'] as $k => $v): ?><option value="<?= Layout::e($k) ?>"<?= $filters['lifecycle'] === $k ? ' selected' : '' ?>><?= Layout::e($v) ?></option><?php endforeach; ?>
  </select>
  <?php if ($tab === 'all' || $tab === 'deleted'): ?>
  <label class="sr-only" for="fo">负责人</label>
  <select class="input select" id="fo" name="owner_id"><option value="">全部负责人</option><?= CustomerForm::userOptions([$filters['owner_id']]) ?></select>
  <?php endif; ?>
  <?php if ($groupOptions): ?>
  <label class="sr-only" for="fg">分组</label>
  <select class="input select" id="fg" name="group_id"><option value="">全部分组</option>
    <?php foreach ($groupOptions as $k => $v): ?><option value="<?= (int) $k ?>"<?= $filters['group_id'] === (int) $k ? ' selected' : '' ?>><?= Layout::e($v) ?></option><?php endforeach; ?>
  </select>
  <?php endif; ?>
  <label class="sr-only" for="ff">跟进</label>
  <select class="input select" id="ff" name="follow"><option value="">跟进不限</option>
    <?php foreach (CustomerService::FOLLOW_FILTERS as $k => $v): ?><option value="<?= Layout::e((string) $k) ?>"<?= $filters['follow'] === (string) $k ? ' selected' : '' ?>><?= Layout::e($v) ?></option><?php endforeach; ?>
  </select>
  <label class="sr-only" for="fn">新增时间</label>
  <select class="input select" id="fn" name="created"><option value="">新增不限</option>
    <?php foreach (CustomerService::CREATED_FILTERS as $k => $v): ?><option value="<?= Layout::e($k) ?>"<?= $filters['created'] === $k ? ' selected' : '' ?>><?= Layout::e($v) ?></option><?php endforeach; ?>
  </select>
  <label class="check"><input type="checkbox" name="no_contact" value="1"<?= $filters['no_contact'] === '1' ? ' checked' : '' ?>><span>没有联系人</span></label>
  <button type="submit" class="btn btn-sm">筛选</button>
  <?php if ($filtered): ?><a class="btn btn-sm btn-ghost" href="<?= Layout::e($self . '?tab=' . $tab) ?>">清空</a><?php endif; ?>
</form>

<section class="card">
  <?php if ($bulk && $result['rows']): ?>
  <div class="bulkbar" data-bulkbar hidden>
    <span>已选 <strong data-bulk-count>0</strong> 个客户</span>
    <?php if ($can['assign']): ?><button type="button" class="btn btn-sm" data-bulk-open="d-assign">分配负责人</button><?php endif; ?>
    <?php if ($can['public'] && $tab !== 'public'): ?><button type="button" class="btn btn-sm" data-bulk-open="d-public">转入公海</button><?php endif; ?>
    <?php if ($can['groups'] && $groupOptions): ?><button type="button" class="btn btn-sm" data-bulk-open="d-groups">分组</button><?php endif; ?>
    <?php if ($can['merge']): ?><button type="button" class="btn btn-sm" data-bulk-open="d-merge" data-min="2" data-max="10">合并</button><?php endif; ?>
    <button type="button" class="btn btn-sm btn-ghost" data-bulk-clear>取消选择</button>
  </div>
  <?php endif; ?>
  <?php if (!$result['rows']): ?>
    <div class="card-body muted">
      <?php if ($filtered): ?>没有符合条件的客户。<?php elseif ($tab === 'mine'): ?>你还没有负责的客户。<?php elseif ($tab === 'public'): ?>公海里没有客户。<?php elseif ($tab === 'deleted'): ?>没有已删除的客户。<?php else: ?>还没有客户。<?php endif; ?>
    </div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table" style="min-width: 1000px; table-layout: fixed">
      <thead>
        <tr>
          <?php if ($bulk): ?><th scope="col" style="width: 40px"><label class="check"><input type="checkbox" data-check-all aria-label="全选本页"></label></th><?php endif; ?>
          <th scope="col" style="width: 26%">客户</th>
          <th scope="col" style="width: 13%">国家 / 城市</th>
          <th scope="col" style="width: 9%">等级</th>
          <th scope="col" style="width: 9%">阶段</th>
          <th scope="col" style="width: 13%">负责人</th>
          <th scope="col" style="width: 7%">联系人</th>
          <?php if ($tab === 'deleted'): ?>
            <th scope="col" style="width: 15%">删除原因</th>
            <th scope="col" style="width: 90px"><span class="sr-only">操作</span></th>
          <?php else: ?>
            <th scope="col" style="width: 10%">最近跟进</th>
            <?php if ($tab === 'public'): ?><th scope="col" style="width: 90px"><span class="sr-only">操作</span></th><?php else: ?><th scope="col" style="width: 11%">更新</th><?php endif; ?>
          <?php endif; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($result['rows'] as $row):
          $cid = (int) $row['id'];
          [$fText, $fClass] = $followText($row['last_followup'] ?? null);
          $assistants = count($row['assistants']); ?>
        <tr>
          <?php if ($bulk): ?><td><label class="check"><input type="checkbox" value="<?= $cid ?>" data-row-check data-name="<?= Layout::e((string) $row['customer_name']) ?>" aria-label="选择 <?= Layout::e((string) $row['customer_name']) ?>"></label></td><?php endif; ?>
          <td class="cell-main">
            <a class="row-link" href="<?= Layout::e(Url::center('customer', 'view.php?id=' . $cid)) ?>"><?= Layout::e((string) $row['customer_name']) ?></a>
            <?php if (!empty($row['do_not_contact'])): ?><span class="badge badge-bad">勿联系</span><?php endif; ?>
            <small class="cell-sub"><?= Layout::e(trim(((string) ($row['customer_code'] ?? '') !== '' ? $row['customer_code'] . ' · ' : '') . (string) ($row['customer_name_en'] ?? ''), ' ·')) ?></small>
          </td>
          <td class="truncate"><?= Layout::e(Country::label((string) $row['country'])) ?><?php if ((string) ($row['city'] ?? '') !== ''): ?><small class="cell-sub"><?= Layout::e((string) $row['city']) ?></small><?php endif; ?></td>
          <td class="truncate"><?= Layout::e($row['level'] ? Dictionary::label('customer_level', (string) $row['level']) : '—') ?></td>
          <td><?php if ((string) $row['lifecycle_key'] !== ''): ?><span class="badge <?= $lifeBadge((string) $row['lifecycle_key']) ?>"><?= Layout::e(Dictionary::label('customer_lifecycle', (string) $row['lifecycle_key'])) ?></span><?php endif; ?></td>
          <td class="truncate"><?= $row['primary_owner'] !== '' ? Layout::e($row['primary_owner']) : '<span class="muted">公海</span>' ?><?php if ($assistants): ?> <span class="muted" title="<?= Layout::e(implode('、', array_column($row['assistants'], 'name'))) ?>">+<?= $assistants ?></span><?php endif; ?></td>
          <td><?= (int) $row['contact_count'] ?: '<span class="muted">0</span>' ?></td>
          <?php if ($tab === 'deleted'): ?>
            <td class="truncate" title="<?= Layout::e((string) $row['delete_reason']) ?>"><?= Layout::e((string) ($row['delete_reason'] ?: '—')) ?><small class="cell-sub"><?= Layout::e(substr((string) $row['deleted_at'], 0, 16)) ?></small></td>
            <td><?php if ($can['restore']): ?><form data-api action="<?= Layout::e($api) ?>?action=customer_restore"><input type="hidden" name="id" value="<?= $cid ?>"><button type="submit" class="btn btn-sm">恢复</button></form><?php endif; ?></td>
          <?php else: ?>
            <td class="<?= Layout::e($fClass) ?>"><?= Layout::e($fText) ?></td>
            <?php if ($tab === 'public'): ?>
              <td><?php if ($can['claim']): ?><form data-api action="<?= Layout::e($api) ?>?action=customer_claim"><input type="hidden" name="id" value="<?= $cid ?>"><button type="submit" class="btn btn-sm btn-primary">领取</button></form><?php endif; ?></td>
            <?php else: ?>
              <td class="nowrap muted"><?= Layout::e(substr((string) $row['updated_at'], 0, 10)) ?></td>
            <?php endif; ?>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= Pager::render($result, static function (int $p) use ($link): string {
      return $link(['page' => $p]);
  }, '个') ?>
  <?php endif; ?>
</section>

<?php if ($can['create']): ?>
<dialog class="dialog dialog-wide" id="d-create" aria-labelledby="d-create-title">
  <form data-api action="<?= Layout::e($api) ?>?action=customer_create" data-create-form>
    <div class="dialog-head"><h2 class="dialog-title" id="d-create-title">新建客户</h2><button type="button" class="icon-btn" data-close-dialog aria-label="关闭">×</button></div>
    <div class="dialog-body">
      <div class="form-grid" data-create-fields>
        <?= CustomerForm::fields($defaults, 'c') ?>
        <?php if ($can['assign']): ?>
          <div class="span-2 form-section">负责人</div>
          <?= CustomerForm::ownerFields('c', (int) $user['id']) ?>
        <?php else: ?>
          <div class="span-2 muted">负责人：你自己</div>
        <?php endif; ?>
        <?= CustomerForm::contactFields('c') ?>
      </div>
      <div class="dup-panel" data-dup-panel hidden>
        <p class="alert alert-warning">找到下面这些疑似重复的客户。请先看看是不是同一个客户；确认不是再继续保存。<span data-dup-rule></span></p>
        <ul class="dup-list" data-dup-list></ul>
      </div>
      <input type="hidden" name="confirm_duplicates" value="" data-dup-confirm>
    </div>
    <div class="dialog-foot">
      <button type="button" class="btn" data-close-dialog>取消</button>
      <button type="button" class="btn" data-dup-back hidden>返回修改</button>
      <button type="submit" class="btn btn-primary" data-create-submit>保存</button>
    </div>
  </form>
</dialog>
<?php endif; ?>

<?php if ($bulk && $can['assign']): ?>
<dialog class="dialog" id="d-assign" aria-labelledby="d-assign-title">
  <form data-api action="<?= Layout::e($api) ?>?action=customer_assign">
    <div class="dialog-head"><h2 class="dialog-title" id="d-assign-title">分配负责人</h2><button type="button" class="icon-btn" data-close-dialog aria-label="关闭">×</button></div>
    <div class="dialog-body form-grid">
      <p class="alert alert-error form-error span-2" data-form-error hidden></p>
      <p class="span-2" style="margin:0">给选中的 <strong data-bulk-count>0</strong> 个客户设置负责人，原来的负责人会被替换。</p>
      <div data-bulk-ids hidden></div>
      <?= CustomerForm::ownerFields('a', 0) ?>
      <div class="span-2"><?= \Artdon\Centers\Base\Form::input('note', '说明', '', [], ['prefix' => 'a', 'maxlength' => 200, 'hint' => '可不填，会写进客户日志并通知负责人']) ?></div>
    </div>
    <div class="dialog-foot"><button type="button" class="btn" data-close-dialog>取消</button><button type="submit" class="btn btn-primary">分配</button></div>
  </form>
</dialog>
<?php endif; ?>

<?php if ($bulk && $can['groups'] && $groupOptions): ?>
<dialog class="dialog dialog-sm" id="d-groups" aria-labelledby="d-groups-title">
  <form data-api action="<?= Layout::e($api) ?>?action=group_apply">
    <div class="dialog-head"><h2 class="dialog-title" id="d-groups-title">调整分组</h2><button type="button" class="icon-btn" data-close-dialog aria-label="关闭">×</button></div>
    <div class="dialog-body stack">
      <p class="alert alert-error form-error" data-form-error hidden></p>
      <p style="margin:0">选中的 <strong data-bulk-count>0</strong> 个客户：</p>
      <div data-bulk-ids hidden></div>
      <?= \Artdon\Centers\Base\Form::select('group_id', '分组', $groupOptions, '', [], ['prefix' => 'gp', 'required' => true, 'placeholder' => '请选择分组']) ?>
      <fieldset class="field check-group"><legend class="field-label">怎么调整</legend>
        <?php $first = true; foreach (GroupService::MODES as $k => $v): ?><label class="check"><input type="radio" name="mode" value="<?= Layout::e($k) ?>"<?= $first ? ' checked' : '' ?>><span><?= Layout::e($v) ?></span></label><?php $first = false; endforeach; ?>
      </fieldset>
    </div>
    <div class="dialog-foot"><button type="button" class="btn" data-close-dialog>取消</button><button type="submit" class="btn btn-primary">确定</button></div>
  </form>
</dialog>
<?php endif; ?>

<?php if ($bulk && $can['merge']): ?>
<dialog class="dialog" id="d-merge" aria-labelledby="d-merge-title">
  <form data-api action="<?= Layout::e($api) ?>?action=customer_merge">
    <div class="dialog-head"><h2 class="dialog-title" id="d-merge-title">合并客户</h2><button type="button" class="icon-btn" data-close-dialog aria-label="关闭">×</button></div>
    <div class="dialog-body stack">
      <p class="alert alert-error form-error" data-form-error hidden></p>
      <div data-bulk-ids hidden></div>
      <p style="margin:0">选一个要<strong>保留</strong>的主客户。其他客户的联系人、地址、跟进、拜访、商机、邮件、报价订单等记录都会转到主客户，负责人变成主客户的协助负责人，然后其他客户被删除（可在“已删除”里看到）。主客户空着的资料会用其他客户的补上。</p>
      <fieldset class="field check-group" data-merge-list><legend class="field-label">保留哪个</legend></fieldset>
      <p class="alert alert-warning" style="margin:0">合并后不能撤销，请确认是同一个客户。</p>
    </div>
    <div class="dialog-foot"><button type="button" class="btn" data-close-dialog>取消</button><button type="submit" class="btn btn-danger">合并</button></div>
  </form>
</dialog>
<?php endif; ?>

<?php if ($bulk && $can['public'] && $tab !== 'public'): ?>
<dialog class="dialog dialog-sm" id="d-public" aria-labelledby="d-public-title">
  <form data-api action="<?= Layout::e($api) ?>?action=customer_transfer_public">
    <div class="dialog-head"><h2 class="dialog-title" id="d-public-title">转入公海</h2><button type="button" class="icon-btn" data-close-dialog aria-label="关闭">×</button></div>
    <div class="dialog-body stack">
      <p class="alert alert-error form-error" data-form-error hidden></p>
      <p style="margin:0">选中的 <strong data-bulk-count>0</strong> 个客户会去掉所有负责人（包括协助负责人），进入公海，别人可以领取。</p>
      <div data-bulk-ids hidden></div>
      <?= \Artdon\Centers\Base\Form::textarea('reason', '原因', '', [], ['prefix' => 'p', 'required' => true, 'maxlength' => 500]) ?>
    </div>
    <div class="dialog-foot"><button type="button" class="btn" data-close-dialog>取消</button><button type="submit" class="btn btn-primary">转入公海</button></div>
  </form>
</dialog>
<?php endif; ?>
<?php
Layout::end();
