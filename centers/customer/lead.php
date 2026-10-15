<?php
declare(strict_types=1);

/** 暂存客户详情与处理：确认入库、关联已有客户、丢弃。 */

require_once __DIR__ . '/bootstrap.php';

use Artdon\Centers\Base\Auth;
use Artdon\Centers\Base\Dictionary;
use Artdon\Centers\Base\Form;
use Artdon\Centers\Base\Layout;
use Artdon\Centers\Base\NotFoundException;
use Artdon\Centers\Base\Permission;
use Artdon\Centers\Base\Url;
use Artdon\Centers\Customer\Country;
use Artdon\Centers\Customer\CustomerForm;
use Artdon\Centers\Customer\DuplicateFinder;
use Artdon\Centers\Customer\LeadPool;
use Artdon\Centers\Customer\RuleConfig;

$user = Auth::requirePage();
if (!LeadPool::canView($user)) {
    Layout::forbidden('customer.lead_pool_view');
}
$pool = new LeadPool($user);
try {
    $lead = $pool->get((int) ($_GET['id'] ?? 0));
} catch (NotFoundException $e) {
    http_response_code(404);
    Layout::start(['title' => '暂存客户不存在', 'nav' => 'customer', 'crumbs' => ['客户中心' => Url::center('customer', 'index.php'), '暂存池' => Url::center('customer', 'leads.php'), '不存在' => null]]);
    echo '<div class="empty"><h1 class="empty-title">暂存客户不存在</h1><a class="btn" href="' . Layout::e(Url::center('customer', 'leads.php')) . '">返回暂存池</a></div>';
    Layout::end();
    exit;
}
$id = (int) $lead['id'];
$pending = $lead['status'] === 'pending';
$process = $pending && $pool->canProcess();
$canConfirm = $process && Permission::allows($user, 'customer.create');
$canAssign = Permission::allows($user, 'customer.assign');
$matches = $pending ? $pool->matches($lead) : [];
$api = Url::center('customer', 'api.php');
$f = $lead['fields'];
$owner = (int) ($lead['owner_user_id'] ?: ($lead['created_by'] ?? 0));
// 暂存里没有来源、推广方式时，按默认值预选（与新建客户一致）
if (!$f['source_tags']) {
    $default = (string) RuleConfig::defaultValue('source', Dictionary::defaultKey('customer_source'));
    $f['source_tags'] = Dictionary::has('customer_source', $default) ? [$default] : [];
}
if (!$f['promotion_channels']) {
    $f['promotion_channels'] = array_values(array_filter(array_map('strval', (array) RuleConfig::defaultValue('promotion_channels', [Dictionary::defaultKey('promotion_channel')])), static function (string $k): bool {
        return Dictionary::has('promotion_channel', $k);
    }));
}

Layout::start([
    'title' => '暂存客户 #' . $id,
    'nav' => 'customer',
    'crumbs' => ['客户中心' => Url::center('customer', 'index.php'), '暂存池' => Url::center('customer', 'leads.php'), '#' . $id => null],
    'scripts' => [Url::centerAsset('customer', 'customer.js')],
    'styles' => [Url::centerAsset('customer', 'customer.css')],
]);
?>
<div class="page-head">
  <div>
    <h1 class="page-title"><?= Layout::e($f['customer_name'] !== '' ? $f['customer_name'] : '（没有名称）') ?> <span class="badge <?= $pending ? 'badge-warn' : 'badge-muted' ?>"><?= Layout::e(LeadPool::STATUS_LABELS[$lead['status']] ?? $lead['status']) ?></span></h1>
    <p class="page-sub">暂存编号 #<?= $id ?> · <?= Layout::e(substr((string) $lead['created_at'], 0, 16)) ?> · 提交人：<?= Layout::e((string) ($lead['creator_name'] ?: '系统 / 导入')) ?><?= $lead['source_name'] !== '' ? ' · 来源：' . Layout::e($lead['source_name']) : '' ?></p>
  </div>
  <?php if ($process): ?>
  <div class="page-actions"><button type="button" class="btn" data-open-dialog="d-reject">丢弃</button></div>
  <?php endif; ?>
</div>

<?php if (!$pending): ?>
  <div class="alert alert-info">
    <?php if ($lead['status'] === 'rejected'): ?>已丢弃<?= ($r = $pool->rejectReason($id)) !== '' ? '，原因：' . Layout::e($r) : '' ?>。
    <?php elseif (!empty($lead['target_customer_id'])): ?><?= $lead['status'] === 'confirmed' ? '已入库为' : '已关联到' ?>客户：<a href="<?= Layout::e(Url::center('customer', 'view.php?id=' . (int) $lead['target_customer_id'])) ?>"><?= Layout::e((string) ($lead['target_name'] ?? ('#' . $lead['target_customer_id']))) ?></a>。
    <?php else: ?>已处理。<?php endif; ?>
  </div>
<?php endif; ?>

<div class="cols">
  <div class="col-main">
    <section class="card">
      <?php if ($canConfirm): ?>
      <form data-api action="<?= Layout::e($api) ?>?action=lead_confirm">
        <div class="card-head"><h2 class="card-title">核对资料后确认入库</h2></div>
        <div class="card-body form-grid">
          <input type="hidden" name="lead_id" value="<?= $id ?>">
          <?= CustomerForm::fields($f, 'l') ?>
          <?php if ($canAssign): ?>
            <div class="span-2 form-section">负责人</div>
            <?= CustomerForm::ownerFields('l', $owner) ?>
          <?php endif; ?>
        </div>
        <div class="card-foot"><button type="submit" class="btn btn-primary">确认入库</button></div>
      </form>
      <?php else: ?>
      <div class="card-head"><h2 class="card-title">暂存资料</h2></div>
      <div class="card-body">
        <dl class="dl dl-wide">
          <?php foreach (['customer_name_en' => '英文名', 'country' => '国家', 'city' => '城市', 'address' => '地址', 'website' => '网站', 'email' => '邮箱', 'phone' => '电话', 'whatsapp' => 'WhatsApp', 'remark' => '备注'] as $k => $label): ?>
            <dt><?= Layout::e($label) ?></dt><dd><?= Layout::e($k === 'country' ? Country::label($f[$k]) : (string) $f[$k]) ?: '<span class="muted">—</span>' ?></dd>
          <?php endforeach; ?>
        </dl>
      </div>
      <?php endif; ?>
    </section>
    <section class="card">
      <div class="card-head"><h2 class="card-title">联系人 <?= count($lead['contacts']) ?></h2><?php if ($lead['skipped_contacts']): ?><span class="muted"><?= (int) $lead['skipped_contacts'] ?> 条没有有效信息或重复，已略过</span><?php endif; ?></div>
      <?php if (!$lead['contacts']): ?>
        <div class="card-body muted">没有联系人。</div>
      <?php else: ?>
      <ul class="notice-list">
        <?php foreach ($lead['contacts'] as $i => $c): ?>
          <li class="notice"><div class="notice-body">
            <div class="notice-title"><?= Layout::e($c['name']) ?><?= $i === 0 ? ' <span class="badge badge-brand">主联系人</span>' : '' ?><?= $c['position'] !== '' ? ' · ' . Layout::e($c['position']) : '' ?></div>
            <p class="notice-text"><?= Layout::e(implode(' · ', array_filter([$c['email'], $c['phone'], $c['whatsapp'] !== '' ? 'WhatsApp ' . $c['whatsapp'] : '', $c['wechat'] !== '' ? '微信 ' . $c['wechat'] : '']))) ?></p>
          </div></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </section>
  </div>
  <div class="col-side">
    <?php if ($pending): ?>
    <section class="card">
      <div class="card-head"><h2 class="card-title">疑似重复 <?= count($matches) ?></h2></div>
      <?php if (!$matches): ?>
        <div class="card-body muted">现在没有找到疑似重复的客户。</div>
      <?php else: ?>
      <ul class="dup-list card-body" style="margin:0">
        <?php foreach ($matches as $m):
            $fill = json_encode(['customer_id' => $m['customer_id'], 'target_name' => $m['customer_name']], JSON_UNESCAPED_UNICODE); ?>
          <li class="dup-item">
            <div class="dup-item-body">
              <a class="row-link" href="<?= Layout::e(Url::center('customer', 'view.php?id=' . $m['customer_id'])) ?>" target="_blank" rel="noopener"><?= Layout::e($m['customer_name']) ?></a>
              <small><?= Layout::e(Country::label($m['country']) . ' · 负责人：' . ($m['owner_name'] !== '' ? $m['owner_name'] : '公海')) ?></small>
              <small><?= Layout::e(DuplicateFinder::reasonText($m['reasons'])) ?></small>
              <?php if ($process): ?><button type="button" class="btn btn-sm" style="margin-top:6px" data-open-dialog="d-attach" data-fill="<?= Layout::e((string) $fill) ?>">就是这个客户</button><?php endif; ?>
            </div>
            <span class="dup-score"><?= (int) $m['similarity'] ?>%</span>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
      <?php if ($process): ?>
      <div class="card-foot"><button type="button" class="btn btn-sm btn-ghost" data-open-dialog="d-attach" data-fill='{"customer_id":"","target_name":"按编号指定"}'>按客户编号关联</button></div>
      <?php endif; ?>
    </section>
    <?php endif; ?>
  </div>
</div>

<?php if ($process): ?>
<dialog class="dialog" id="d-attach" aria-labelledby="d-attach-title">
  <form data-api action="<?= Layout::e($api) ?>?action=lead_attach">
    <div class="dialog-head"><h2 class="dialog-title" id="d-attach-title">关联到已有客户</h2><button type="button" class="icon-btn" data-close-dialog aria-label="关闭">×</button></div>
    <div class="dialog-body stack">
      <p class="alert alert-error form-error" data-form-error hidden></p>
      <input type="hidden" name="lead_id" value="<?= $id ?>">
      <p style="margin:0">这条暂存记录就是已有客户“<strong data-text="target_name"></strong>”，不再新建。</p>
      <?= Form::input('customer_id', '客户编号', '', [], ['prefix' => 'at', 'required' => true, 'inputmode' => 'numeric', 'hint' => '客户详情页地址里的 id']) ?>
      <label class="check"><input type="checkbox" name="add_contacts" value="1" checked><span>把暂存里的联系人补到这个客户（邮箱或电话已有的跳过）</span></label>
    </div>
    <div class="dialog-foot"><button type="button" class="btn" data-close-dialog>取消</button><button type="submit" class="btn btn-primary">关联</button></div>
  </form>
</dialog>
<dialog class="dialog dialog-sm" id="d-reject" aria-labelledby="d-reject-title">
  <form data-api action="<?= Layout::e($api) ?>?action=lead_reject">
    <div class="dialog-head"><h2 class="dialog-title" id="d-reject-title">丢弃暂存客户</h2><button type="button" class="icon-btn" data-close-dialog aria-label="关闭">×</button></div>
    <div class="dialog-body stack">
      <p class="alert alert-error form-error" data-form-error hidden></p>
      <input type="hidden" name="lead_id" value="<?= $id ?>">
      <?= Form::textarea('reason', '丢弃原因', '', [], ['prefix' => 'rj', 'required' => true, 'maxlength' => 500]) ?>
      <p class="muted" style="margin:0">提交人会收到通知。</p>
    </div>
    <div class="dialog-foot"><button type="button" class="btn" data-close-dialog>取消</button><button type="submit" class="btn btn-danger">丢弃</button></div>
  </form>
</dialog>
<?php endif; ?>
<?php
Layout::end();
