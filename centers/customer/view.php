<?php
declare(strict_types=1);

/** 客户详情：概览、联系人、跟进（只读）、日志。 */

require_once __DIR__ . '/bootstrap.php';

use Artdon\Centers\Base\Auth;
use Artdon\Centers\Base\Dictionary;
use Artdon\Centers\Base\Form;
use Artdon\Centers\Base\Layout;
use Artdon\Centers\Base\NotFoundException;
use Artdon\Centers\Base\Url;
use Artdon\Centers\Customer\ContactService;
use Artdon\Centers\Customer\Country;
use Artdon\Centers\Customer\CustomerForm;
use Artdon\Centers\Customer\CustomerLog;
use Artdon\Centers\Customer\CustomerService;
use Artdon\Centers\Customer\DuplicateFinder;

$user = Auth::requirePage();
Auth::requirePermissionPage('customer.view');
$service = new CustomerService($user);
$contactService = new ContactService($user);
$id = (int) ($_GET['id'] ?? 0);
try {
    $c = $service->get($id, $service->canSeeDeleted());
} catch (NotFoundException $e) {
    http_response_code(404);
    Layout::start(['title' => '客户不存在', 'nav' => 'customer', 'crumbs' => ['客户中心' => Url::center('customer', 'index.php'), '客户不存在' => null]]);
    echo '<div class="empty"><h1 class="empty-title">客户不存在或你没有权限查看</h1><p class="empty-text">可能已被删除、转给了别人，或不在你的查看范围内。</p><a class="btn" href="' . Layout::e(Url::center('customer', 'index.php')) . '">返回客户列表</a></div>';
    Layout::end();
    exit;
}
$canContacts = $service->can('contact.view');
$contacts = $canContacts ? $contactService->list($id) : [];
$deleted = !empty($c['deleted_at']);
$public = (bool) $c['is_public'];
$editable = $service->canEdit($c);
$maintain = $service->canMaintain($c);
$can = [
    'assign' => !$deleted && $service->can('customer.assign'),
    'public' => !$public && $maintain && $service->can('customer.transfer_public'),
    'claim' => !$deleted && $public && $service->can('customer.claim_public'),
    'delete' => !$deleted && $service->can('customer.delete'),
    'restore' => $deleted && $service->can('customer.restore'),
    'logs' => $service->can('customer.view_logs'),
    'contact_create' => $maintain && $service->can('contact.create'),
    'contact_edit' => $maintain && $service->can('contact.edit'),
    'contact_delete' => $maintain && $service->can('contact.delete'),
];
$tabs = ['overview' => '概览'];
if ($canContacts) {
    $tabs['contacts'] = '联系人 ' . count($contacts);
}
$tabs['followups'] = '跟进 ' . (int) $c['followup_count'];
if (!$deleted && $service->can('customer.merge')) {
    $tabs['duplicates'] = '查重';
}
if ($can['logs']) {
    $tabs['logs'] = '日志';
}
$tab = (string) ($_GET['tab'] ?? 'overview');
if (!isset($tabs[$tab])) {
    $tab = 'overview';
}
$self = Url::center('customer', 'view.php?id=' . $id);
$api = Url::center('customer', 'api.php');
$assistants = array_values(array_filter($c['owners'], static function (array $o): bool {
    return !$o['is_primary'];
}));
$val = static function ($v): string {
    $v = trim((string) $v);
    return $v !== '' ? Layout::e($v) : '<span class="muted">—</span>';
};
$labels = static function (string $dict, array $keys): string {
    if (!$keys) {
        return '<span class="muted">—</span>';
    }
    return implode(' ', array_map(static function (string $k) use ($dict): string {
        return '<span class="badge">' . Layout::e(Dictionary::label($dict, $k)) . '</span>';
    }, $keys));
};

Layout::start([
    'title' => (string) $c['customer_name'],
    'nav' => 'customer',
    'crumbs' => ['客户中心' => Url::center('customer', 'index.php' . ($public ? '?tab=public' : '')), (string) $c['customer_name'] => null],
    'scripts' => [Url::centerAsset('customer', 'customer.js')],
    'styles' => [Url::centerAsset('customer', 'customer.css')],
]);
?>
<?php if ($deleted): ?>
  <div class="alert alert-warning">这个客户已于 <?= Layout::e(substr((string) $c['deleted_at'], 0, 16)) ?> 删除，原因：<?= Layout::e((string) ($c['delete_reason'] ?: '未填')) ?>。</div>
<?php elseif ($public): ?>
  <div class="alert alert-info">这是公海客户（没有负责人）<?= !empty($c['protection']['reason']) ? '，转入原因：' . Layout::e((string) $c['protection']['reason']) : '' ?>。<?= $can['claim'] ? '领取后才能编辑和维护联系人。' : '' ?></div>
<?php endif; ?>
<div class="page-head">
  <div>
    <h1 class="page-title"><?= Layout::e((string) $c['customer_name']) ?>
      <?php if ((string) $c['lifecycle_key'] !== ''): ?><span class="badge badge-brand"><?= Layout::e(Dictionary::label('customer_lifecycle', (string) $c['lifecycle_key'])) ?></span><?php endif; ?>
      <?php if ((string) $c['level'] !== ''): ?><span class="badge"><?= Layout::e(Dictionary::label('customer_level', (string) $c['level'])) ?></span><?php endif; ?>
      <?php if (!empty($c['do_not_contact'])): ?><span class="badge badge-bad">勿联系</span><?php endif; ?>
    </h1>
    <p class="page-sub">
      <?= Layout::e(implode(' · ', array_filter([(string) ($c['customer_name_en'] ?? ''), (string) ($c['customer_code'] ?? ''), Country::label((string) $c['country']) . ((string) ($c['city'] ?? '') !== '' ? ' ' . $c['city'] : '')]))) ?>
      <br>负责人：<?= $c['primary_owner'] !== '' ? Layout::e($c['primary_owner']) : '无（公海）' ?><?= $assistants ? '；协助：' . Layout::e(implode('、', array_column($assistants, 'name'))) : '' ?>
    </p>
  </div>
  <div class="page-actions">
    <?php if ($can['claim']): ?><form data-api action="<?= Layout::e($api) ?>?action=customer_claim"><input type="hidden" name="id" value="<?= $id ?>"><button type="submit" class="btn btn-primary">领取</button></form><?php endif; ?>
    <?php if ($editable): ?><button type="button" class="btn btn-primary" data-open-dialog="d-edit">编辑</button><?php endif; ?>
    <?php if ($can['restore']): ?><form data-api action="<?= Layout::e($api) ?>?action=customer_restore"><input type="hidden" name="id" value="<?= $id ?>"><button type="submit" class="btn btn-primary">恢复客户</button></form><?php endif; ?>
    <?php if ($can['assign'] || $can['public'] || $can['delete']): ?>
    <details class="menu">
      <summary class="btn">更多</summary>
      <div class="menu-panel">
        <?php if ($can['assign']): ?><button type="button" class="menu-item" data-open-dialog="d-assign">分配负责人</button><?php endif; ?>
        <?php if ($can['public']): ?><button type="button" class="menu-item" data-open-dialog="d-public">转入公海</button><?php endif; ?>
        <?php if ($can['delete']): ?><button type="button" class="menu-item is-danger" data-open-dialog="d-delete">删除客户</button><?php endif; ?>
      </div>
    </details>
    <?php endif; ?>
  </div>
</div>

<nav class="tabs" aria-label="客户详情">
  <?php foreach ($tabs as $key => $label): $on = $key === $tab; ?>
    <a class="tab<?= $on ? ' is-active' : '' ?>" href="<?= Layout::e($self . ($key === 'overview' ? '' : '&tab=' . $key)) ?>"<?= $on ? ' aria-current="page"' : '' ?>><?= Layout::e($label) ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($tab === 'overview'): $missing = $service->missing($c, $contacts); ?>
<div class="cols">
  <div class="col-main">
    <section class="card">
      <div class="card-head"><h2 class="card-title">客户资料</h2></div>
      <div class="card-body">
        <dl class="dl dl-wide">
          <dt>国家 / 城市</dt><dd><?= $val(Country::label((string) $c['country']) . ((string) ($c['city'] ?? '') !== '' ? ' · ' . $c['city'] : '')) ?></dd>
          <dt>地址</dt><dd><?= $val($c['address'] ?? '') ?></dd>
          <dt>网站</dt><dd><?php if ((string) ($c['website'] ?? '') !== '' && preg_match('#^https?://#i', (string) $c['website'])): ?><a href="<?= Layout::e((string) $c['website']) ?>" target="_blank" rel="noopener noreferrer"><?= Layout::e((string) $c['website']) ?></a><?php else: ?><?= $val($c['website'] ?? '') ?><?php endif; ?></dd>
          <dt>公司邮箱</dt><dd><?= $val($c['email'] ?? '') ?></dd>
          <dt>电话</dt><dd><?= $val($c['phone'] ?? '') ?></dd>
          <dt>WhatsApp</dt><dd><?= $val($c['whatsapp'] ?? '') ?></dd>
          <dt>微信</dt><dd><?= $val($c['wechat'] ?? '') ?></dd>
          <dt>来源</dt><dd><?= $labels('customer_source', $c['source_tags']) ?></dd>
          <dt>推广方式</dt><dd><?= $labels('promotion_channel', $c['promotion_channels']) ?></dd>
          <dt>推广状态</dt><dd><?= $val($c['promotion_status'] !== '' ? Dictionary::label('promotion_status', $c['promotion_status']) : '') ?></dd>
          <dt>分组</dt><dd><?= $c['groups'] ? implode(' ', array_map(static function (array $g): string {
              return '<span class="badge">' . Layout::e((string) $g['group_name']) . '</span>';
          }, $c['groups'])) : '<span class="muted">—</span>' ?></dd>
          <dt>备注</dt><dd class="pre"><?= $val($c['remark'] ?? '') ?></dd>
          <dt>新建</dt><dd><?= Layout::e(substr((string) $c['created_at'], 0, 16)) ?><?= $c['created_by_name'] !== '' ? ' · ' . Layout::e($c['created_by_name']) : '' ?></dd>
          <dt>最近更新</dt><dd><?= Layout::e(substr((string) $c['updated_at'], 0, 16)) ?></dd>
        </dl>
      </div>
    </section>
  </div>
  <div class="col-side">
    <section class="card">
      <div class="card-head"><h2 class="card-title">负责人</h2></div>
      <div class="card-body stack">
        <?php if (!$c['owners'] && empty($c['owner_user_id'])): ?>
          <p class="muted" style="margin:0">没有负责人（公海）<?= !empty($c['protection']['public_pool_at']) ? '，' . Layout::e(substr((string) $c['protection']['public_pool_at'], 0, 10)) . ' 转入' : '' ?></p>
        <?php else: ?>
          <div><span class="badge badge-brand">第一</span> <?= Layout::e($c['primary_owner']) ?></div>
          <?php foreach ($assistants as $a): ?><div><span class="badge">协助</span> <?= Layout::e($a['name']) ?></div><?php endforeach; ?>
        <?php endif; ?>
      </div>
    </section>
    <?php if ($missing): ?>
    <section class="card">
      <div class="card-head"><h2 class="card-title">资料还缺</h2></div>
      <div class="card-body"><?= implode(' ', array_map(static function (string $m): string {
          return '<span class="badge badge-warn">' . Layout::e($m) . '</span>';
      }, $missing)) ?></div>
    </section>
    <?php endif; ?>
    <section class="card">
      <div class="card-head"><h2 class="card-title">最近跟进</h2><?php if ($c['followups']): ?><a class="card-link" href="<?= Layout::e($self . '&tab=followups') ?>">全部 <?= (int) $c['followup_count'] ?> 条</a><?php endif; ?></div>
      <div class="card-body stack">
        <?php if (!$c['followups']): ?><p class="muted" style="margin:0">还没有跟进记录。</p><?php endif; ?>
        <?php foreach (array_slice($c['followups'], 0, 3) as $f): ?>
          <div class="mini-item"><small class="muted"><?= Layout::e(substr((string) $f['followup_time'], 0, 16)) ?> · <?= Layout::e((string) ($f['user_name'] ?? '')) ?></small><div class="clamp-3"><?= Layout::e((string) $f['content']) ?></div></div>
        <?php endforeach; ?>
      </div>
    </section>
  </div>
</div>
<?php endif; ?>

<?php if ($tab === 'contacts'): ?>
<section class="card">
  <div class="card-head"><h2 class="card-title">联系人</h2><?php if ($can['contact_create']): ?><button type="button" class="btn btn-sm btn-primary" data-open-dialog="d-contact-new">添加联系人</button><?php endif; ?></div>
  <?php if (!$contacts): ?>
    <div class="card-body muted">还没有联系人。</div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table" style="min-width: 860px; table-layout: fixed">
      <thead><tr>
        <th scope="col" style="width: 20%">姓名</th><th scope="col" style="width: 14%">职位</th><th scope="col" style="width: 22%">邮箱</th>
        <th scope="col" style="width: 15%">电话</th><th scope="col" style="width: 15%">WhatsApp / 微信</th><th scope="col" style="width: 14%"><span class="sr-only">操作</span></th>
      </tr></thead>
      <tbody>
      <?php foreach ($contacts as $ct):
          $fill = json_encode(['id' => (int) $ct['id']] + array_intersect_key($ct, ContactService::FIELDS) + ['is_primary' => (int) $ct['is_primary'] ? '1' : '', 'is_left' => (int) $ct['is_left'] ? '1' : '', 'title_name' => $ct['name']], JSON_UNESCAPED_UNICODE); ?>
        <tr<?= (int) $ct['is_left'] ? ' class="is-dim"' : '' ?>>
          <td class="cell-main"><?= Layout::e((string) $ct['name']) ?>
            <?php if ((int) $ct['is_primary']): ?><span class="badge badge-brand">主联系人</span><?php endif; ?>
            <?php if ((int) $ct['is_left']): ?><span class="badge badge-muted">已离职</span><?php endif; ?>
            <?php if ((string) $ct['name_en'] !== ''): ?><small class="cell-sub"><?= Layout::e((string) $ct['name_en']) ?></small><?php endif; ?></td>
          <td class="truncate"><?= $val($ct['position']) ?></td>
          <td class="truncate"><?= (string) $ct['email'] !== '' ? '<a href="mailto:' . Layout::e((string) $ct['email']) . '">' . Layout::e((string) $ct['email']) . '</a>' : '<span class="muted">—</span>' ?></td>
          <td class="truncate"><?= $val($ct['phone']) ?></td>
          <td class="truncate"><?= $val(trim((string) $ct['whatsapp'] . ((string) $ct['wechat'] !== '' ? ' / ' . $ct['wechat'] : ''), ' /')) ?></td>
          <td class="cell-actions">
            <?php if ($can['contact_edit']): ?><button type="button" class="btn btn-sm" data-open-dialog="d-contact-edit" data-fill="<?= Layout::e((string) $fill) ?>">编辑</button><?php endif; ?>
            <?php if ($can['contact_delete']): ?><button type="button" class="btn btn-sm btn-ghost" data-open-dialog="d-contact-delete" data-fill="<?= Layout::e((string) $fill) ?>">删除</button><?php endif; ?>
          </td>
        </tr>
        <?php if ((string) $ct['remark'] !== ''): ?><tr class="row-note"><td colspan="6"><?= Layout::e((string) $ct['remark']) ?></td></tr><?php endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($tab === 'followups'): ?>
<section class="card">
  <div class="card-head"><h2 class="card-title">跟进记录</h2><span class="muted">新增和修改跟进将放在“业务跟进”中心（建设中），这里只读</span></div>
  <?php if (!$c['followups']): ?>
    <div class="card-body muted">还没有跟进记录。</div>
  <?php else: ?>
  <ul class="notice-list">
    <?php foreach ($c['followups'] as $f): ?>
      <li class="notice"><div class="notice-body">
        <div class="notice-title"><?= Layout::e(substr((string) $f['followup_time'], 0, 16)) ?> · <?= Layout::e((string) ($f['user_name'] ?? '')) ?><?= (string) $f['followup_type'] !== '' && $f['followup_type'] !== 'other' ? ' · ' . Layout::e(Dictionary::label('followup_type', (string) $f['followup_type'])) : '' ?></div>
        <p class="notice-text pre"><?= Layout::e((string) $f['content']) ?></p>
        <?php if ((string) ($f['next_plan'] ?? '') !== ''): ?><p class="notice-text">下一步：<?= Layout::e((string) $f['next_plan']) ?><?= !empty($f['next_remind_time']) ? '（' . Layout::e(substr((string) $f['next_remind_time'], 0, 16)) . '）' : '' ?></p><?php endif; ?>
      </div></li>
    <?php endforeach; ?>
  </ul>
  <?php if ((int) $c['followup_count'] > count($c['followups'])): ?><div class="card-body muted">只显示最近 <?= count($c['followups']) ?> 条，共 <?= (int) $c['followup_count'] ?> 条。</div><?php endif; ?>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($tab === 'duplicates'):
    $first = $contacts[0] ?? [];
    $dups = DuplicateFinder::find($c + ['contact_email' => $first['email'] ?? '', 'contact_phone' => $first['phone'] ?? ''], $id); ?>
<section class="card">
  <div class="card-head"><h2 class="card-title">疑似重复的客户 <?= count($dups) ?></h2><span class="muted">和全部客户比对：名称相似、邮箱、电话、WhatsApp、网站域名、联系人邮箱和电话</span></div>
  <?php if (!$dups): ?>
    <div class="card-body muted">没有找到疑似重复的客户。</div>
  <?php else: ?>
  <ul class="dup-list card-body" style="margin:0">
    <?php foreach ($dups as $m): ?>
      <li class="dup-item">
        <div class="dup-item-body">
          <a class="row-link" href="<?= Layout::e(Url::center('customer', 'view.php?id=' . $m['customer_id'])) ?>"><?= Layout::e($m['customer_name']) ?></a>
          <small><?= Layout::e('#' . $m['customer_id'] . ' · ' . Country::label($m['country']) . ($m['city'] !== '' ? ' ' . $m['city'] : '') . ' · 负责人：' . ($m['owner_name'] !== '' ? $m['owner_name'] : '公海') . ' · ' . DuplicateFinder::reasonText($m['reasons'])) ?></small>
        </div>
        <span class="dup-score"><?= (int) $m['similarity'] ?>%</span>
        <button type="button" class="btn btn-sm" data-open-dialog="d-merge-one" data-fill="<?= Layout::e((string) json_encode(['other_id' => $m['customer_id'], 'other_name' => $m['customer_name']], JSON_UNESCAPED_UNICODE)) ?>">合并进来</button>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<dialog class="dialog dialog-sm" id="d-merge-one" aria-labelledby="d-merge-one-title">
  <form data-api action="<?= Layout::e($api) ?>?action=customer_merge">
    <div class="dialog-head"><h2 class="dialog-title" id="d-merge-one-title">合并客户</h2><button type="button" class="icon-btn" data-close-dialog aria-label="关闭">×</button></div>
    <div class="dialog-body stack">
      <p class="alert alert-error form-error" data-form-error hidden></p>
      <input type="hidden" name="ids[]" value="<?= $id ?>"><input type="hidden" name="other_id" value=""><input type="hidden" name="master_id" value="<?= $id ?>">
      <p style="margin:0">把“<strong data-text="other_name"></strong>”合并到当前客户“<?= Layout::e((string) $c['customer_name']) ?>”。它的联系人、跟进、拜访、商机、邮件、报价订单等都会转过来，然后它被删除。</p>
      <p class="alert alert-warning" style="margin:0">合并后不能撤销。</p>
    </div>
    <div class="dialog-foot"><button type="button" class="btn" data-close-dialog>取消</button><button type="submit" class="btn btn-danger">合并</button></div>
  </form>
</dialog>
<?php endif; ?>

<?php if ($tab === 'logs'): $logs = CustomerLog::forCustomer($id); ?>
<section class="card">
  <div class="card-head"><h2 class="card-title">客户日志</h2><span class="muted">新旧系统的操作都在这里</span></div>
  <?php if (!$logs): ?>
    <div class="card-body muted">还没有日志。</div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table" style="min-width: 760px; table-layout: fixed">
      <thead><tr><th scope="col" style="width: 150px">时间</th><th scope="col" style="width: 14%">操作人</th><th scope="col" style="width: 16%">操作</th><th scope="col">内容</th></tr></thead>
      <tbody>
      <?php foreach ($logs as $l): $msg = (string) ($l['message'] ?? ''); $isNew = strpos($msg, '[新系统]') === 0; $msg = $isNew ? trim(substr($msg, strlen('[新系统]'))) : $msg; ?>
        <tr>
          <td class="nowrap"><?= Layout::e(substr((string) $l['created_at'], 0, 16)) ?></td>
          <td class="truncate"><?= $val($l['user_name'] ?? '') ?></td>
          <td class="truncate"><?= Layout::e(CustomerLog::label((string) $l['action'])) ?></td>
          <td class="truncate" title="<?= Layout::e($msg) ?>"><?= $val($msg) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($editable): ?>
<dialog class="dialog dialog-wide" id="d-edit" aria-labelledby="d-edit-title">
  <form data-api action="<?= Layout::e($api) ?>?action=customer_update">
    <div class="dialog-head"><h2 class="dialog-title" id="d-edit-title">编辑客户</h2><button type="button" class="icon-btn" data-close-dialog aria-label="关闭">×</button></div>
    <div class="dialog-body form-grid">
      <input type="hidden" name="id" value="<?= $id ?>">
      <?= CustomerForm::fields($c, 'e') ?>
      <p class="span-2 muted" style="margin:0"><?= $can['assign'] ? '负责人请用“更多 → 分配负责人”修改。' : '负责人由有分配权限的人修改。' ?></p>
    </div>
    <div class="dialog-foot"><button type="button" class="btn" data-close-dialog>取消</button><button type="submit" class="btn btn-primary">保存</button></div>
  </form>
</dialog>
<?php endif; ?>

<?php if ($can['assign']): ?>
<dialog class="dialog" id="d-assign" aria-labelledby="d-assign-title">
  <form data-api action="<?= Layout::e($api) ?>?action=customer_assign">
    <div class="dialog-head"><h2 class="dialog-title" id="d-assign-title">分配负责人</h2><button type="button" class="icon-btn" data-close-dialog aria-label="关闭">×</button></div>
    <div class="dialog-body form-grid">
      <p class="alert alert-error form-error span-2" data-form-error hidden></p>
      <input type="hidden" name="ids[]" value="<?= $id ?>">
      <?= CustomerForm::ownerFields('a', (int) ($c['owner_user_id'] ?? 0)) ?>
      <div class="span-2"><?= Form::input('note', '说明', '', [], ['prefix' => 'a', 'maxlength' => 200, 'hint' => '可不填，会写进客户日志并通知负责人']) ?></div>
    </div>
    <div class="dialog-foot"><button type="button" class="btn" data-close-dialog>取消</button><button type="submit" class="btn btn-primary">分配</button></div>
  </form>
</dialog>
<?php endif; ?>

<?php if ($can['public']): ?>
<dialog class="dialog dialog-sm" id="d-public" aria-labelledby="d-public-title">
  <form data-api action="<?= Layout::e($api) ?>?action=customer_transfer_public">
    <div class="dialog-head"><h2 class="dialog-title" id="d-public-title">转入公海</h2><button type="button" class="icon-btn" data-close-dialog aria-label="关闭">×</button></div>
    <div class="dialog-body stack">
      <p class="alert alert-error form-error" data-form-error hidden></p>
      <p style="margin:0">会去掉这个客户的所有负责人（包括协助负责人），别人可以领取。</p>
      <input type="hidden" name="ids[]" value="<?= $id ?>"><input type="hidden" name="back" value="1">
      <?= Form::textarea('reason', '原因', '', [], ['prefix' => 'p', 'required' => true, 'maxlength' => 500]) ?>
    </div>
    <div class="dialog-foot"><button type="button" class="btn" data-close-dialog>取消</button><button type="submit" class="btn btn-primary">转入公海</button></div>
  </form>
</dialog>
<?php endif; ?>

<?php if ($can['delete']): ?>
<dialog class="dialog dialog-sm" id="d-delete" aria-labelledby="d-delete-title">
  <form data-api action="<?= Layout::e($api) ?>?action=customer_delete">
    <div class="dialog-head"><h2 class="dialog-title" id="d-delete-title">删除客户</h2><button type="button" class="icon-btn" data-close-dialog aria-label="关闭">×</button></div>
    <div class="dialog-body stack">
      <p class="alert alert-error form-error" data-form-error hidden></p>
      <p style="margin:0">删除后可以在“已删除”里恢复。联系人、跟进等记录都会保留。</p>
      <input type="hidden" name="id" value="<?= $id ?>">
      <?= Form::textarea('reason', '删除原因', '', [], ['prefix' => 'd', 'required' => true, 'maxlength' => 500]) ?>
    </div>
    <div class="dialog-foot"><button type="button" class="btn" data-close-dialog>取消</button><button type="submit" class="btn btn-danger">删除</button></div>
  </form>
</dialog>
<?php endif; ?>

<?php
$contactForm = static function (string $prefix): string {
    $p = ['prefix' => $prefix];
    $h = '<p class="alert alert-error form-error span-2" data-form-error hidden></p>';
    foreach (ContactService::FIELDS as $key => [$label, $max]) {
        if ($key === 'remark') {
            $h .= '<div class="span-2">' . Form::textarea($key, $label, '', [], $p + ['maxlength' => $max]) . '</div>';
        } else {
            $h .= Form::input($key, $label, '', [], $p + ['maxlength' => $max, 'type' => $key === 'email' ? 'email' : ($key === 'phone' ? 'tel' : 'text')]);
        }
    }
    $h .= '<div class="span-2 check-list"><label class="check"><input type="checkbox" name="is_primary" value="1"><span>设为主联系人</span></label>'
        . '<label class="check"><input type="checkbox" name="is_left" value="1"><span>已离职</span></label></div>';
    return $h;
};
?>
<?php if ($can['contact_create']): ?>
<dialog class="dialog dialog-wide" id="d-contact-new" aria-labelledby="d-contact-new-title">
  <form data-api action="<?= Layout::e($api) ?>?action=contact_create">
    <div class="dialog-head"><h2 class="dialog-title" id="d-contact-new-title">添加联系人</h2><button type="button" class="icon-btn" data-close-dialog aria-label="关闭">×</button></div>
    <div class="dialog-body form-grid"><input type="hidden" name="customer_id" value="<?= $id ?>"><?= $contactForm('cn') ?><p class="span-2 field-hint" style="margin:0">姓名、邮箱、电话、WhatsApp、微信、LinkedIn 至少填一项。</p></div>
    <div class="dialog-foot"><button type="button" class="btn" data-close-dialog>取消</button><button type="submit" class="btn btn-primary">添加</button></div>
  </form>
</dialog>
<?php endif; ?>
<?php if ($can['contact_edit']): ?>
<dialog class="dialog dialog-wide" id="d-contact-edit" aria-labelledby="d-contact-edit-title">
  <form data-api action="<?= Layout::e($api) ?>?action=contact_update">
    <div class="dialog-head"><h2 class="dialog-title" id="d-contact-edit-title">编辑联系人</h2><button type="button" class="icon-btn" data-close-dialog aria-label="关闭">×</button></div>
    <div class="dialog-body form-grid"><input type="hidden" name="id" value=""><?= $contactForm('ce') ?></div>
    <div class="dialog-foot"><button type="button" class="btn" data-close-dialog>取消</button><button type="submit" class="btn btn-primary">保存</button></div>
  </form>
</dialog>
<?php endif; ?>
<?php if ($can['contact_delete']): ?>
<dialog class="dialog dialog-sm" id="d-contact-delete" aria-labelledby="d-contact-delete-title">
  <form data-api action="<?= Layout::e($api) ?>?action=contact_delete">
    <div class="dialog-head"><h2 class="dialog-title" id="d-contact-delete-title">删除联系人</h2><button type="button" class="icon-btn" data-close-dialog aria-label="关闭">×</button></div>
    <div class="dialog-body stack">
      <p class="alert alert-error form-error" data-form-error hidden></p>
      <input type="hidden" name="id" value="">
      <p style="margin:0">确定删除联系人“<strong data-text="title_name"></strong>”吗？</p>
    </div>
    <div class="dialog-foot"><button type="button" class="btn" data-close-dialog>取消</button><button type="submit" class="btn btn-danger">删除</button></div>
  </form>
</dialog>
<?php endif; ?>
<?php
Layout::end();
