<?php
declare(strict_types=1);

/** 权限申请与临时授权：我的申请、待我审批、全部申请、临时授权。 */

require_once __DIR__ . '/bootstrap.php';

use Artdon\Centers\Base\Auth;
use Artdon\Centers\Base\Form;
use Artdon\Centers\Base\Layout;
use Artdon\Centers\Base\Url;
use Artdon\Centers\System\PermissionTabs;
use Artdon\Centers\System\RequestService;

$user = Auth::requirePage();
$service = new RequestService($user);
$api = Url::center('system', 'api.php');
$self = Url::center('system', 'requests.php');

$views = ['mine' => '我的申请'];
if ($service->isApprover()) {
    $views['approve'] = '待我审批';
}
if ($service->canSeeAll()) {
    $views['all'] = '全部申请';
    $views['grants'] = '临时授权';
}
$view = (string) ($_GET['view'] ?? '');
if (!isset($views[$view])) {
    $view = isset($views['approve']) && $service->pendingForMe() ? 'approve' : 'mine';
}
$status = (string) ($_GET['status'] ?? '');
$pendingCount = isset($views['approve']) ? count($service->pendingForMe()) : 0;

$rows = [];
if ($view === 'mine') {
    $rows = $service->myRequests();
} elseif ($view === 'approve') {
    $rows = $service->pendingForMe();
} elseif ($view === 'all') {
    $rows = $service->allRequests($status);
}
$grants = $view === 'grants' ? $service->activeGrants(true) : ($view === 'mine' ? $service->activeGrants(false) : []);
$requestable = $service->requestable();
$canRevoke = $service->canRevoke();

$statusBadge = ['pending' => 'badge-warn', 'approved' => 'badge-good', 'rejected' => 'badge-bad', 'cancelled' => 'badge-muted', 'expired' => 'badge-muted'];
$urgencyBadge = ['normal' => '', 'urgent' => 'badge-warn', 'critical' => 'badge-bad'];
$dayOptions = ['1' => '1 天', '3' => '3 天', '7' => '7 天', '30' => '30 天', '90' => '90 天'];

/** 一条申请的权限显示：中文名 + 编码 + 风险。 */
$permCell = static function (array $r): string {
    $html = '<span class="perm-text"><span class="perm-label">' . Layout::e((string) ($r['permission_label'] ?? '') ?: (string) $r['permission_key']) . '</span><span class="perm-key mono">' . Layout::e((string) $r['permission_key']) . '</span></span>';
    if (($r['risk_level'] ?? '') === 'dangerous') {
        $html .= ' <span class="badge badge-bad">高危</span>';
    } elseif (($r['risk_level'] ?? '') === 'high') {
        $html .= ' <span class="badge badge-warn">高</span>';
    }
    return '<span style="display:flex;align-items:center;gap:8px">' . $html . '</span>';
};

Layout::start([
    'title' => '权限申请',
    'nav' => Auth::can('users.view') ? 'permissions' : 'requests',
    'crumbs' => Auth::can('users.view')
        ? ['系统' => null, '权限中心' => Url::center('system', 'roles.php'), '申请与授权' => null]
        : ['系统' => null, '权限申请' => null],
]);
?>
<div class="page-head">
  <div>
    <h1 class="page-title"><?= Auth::can('users.view') ? '权限中心' : '权限申请' ?></h1>
    <p class="page-sub">缺少某项权限时可以在这里申请，批准后获得有期限的临时授权，到期自动失效。</p>
  </div>
  <div class="page-actions"><button type="button" class="btn btn-primary" data-open-dialog="d-req-submit">申请权限</button></div>
</div>
<?php if (Auth::can('users.view')): ?><?= PermissionTabs::render('requests') ?><?php endif; ?>

<nav class="tabs" aria-label="申请分类" style="margin-top:-4px">
  <?php foreach ($views as $key => $label): $on = $key === $view; ?>
    <a class="tab<?= $on ? ' is-active' : '' ?>" href="<?= Layout::e($self . '?view=' . $key) ?>"<?= $on ? ' aria-current="page"' : '' ?>><?= Layout::e($label) ?><?php if ($key === 'approve' && $pendingCount): ?><span class="badge badge-warn"><?= $pendingCount ?></span><?php endif; ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($view === 'all'): ?>
<nav class="filters" aria-label="按状态筛选">
  <a class="btn btn-sm<?= $status === '' ? ' btn-primary' : '' ?>" href="<?= Layout::e($self . '?view=all') ?>">全部</a>
  <?php foreach (RequestService::STATUS_LABELS as $key => $label): ?>
    <a class="btn btn-sm<?= $status === $key ? ' btn-primary' : '' ?>" href="<?= Layout::e($self . '?view=all&status=' . $key) ?>"><?= Layout::e($label) ?></a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>

<?php if ($view !== 'grants'): ?>
<section class="card">
  <?php if (!$rows): ?>
    <div class="card-body muted"><?= $view === 'approve' ? '目前没有需要你审批的申请。' : '还没有申请记录。' ?></div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table" style="min-width: 960px">
      <thead><tr>
        <?php if ($view !== 'mine'): ?><th scope="col">申请人</th><?php endif; ?>
        <th scope="col">申请的权限</th><th scope="col">原因</th><th scope="col">紧急</th><th scope="col">天数</th><th scope="col">状态</th><th scope="col">申请时间</th><th scope="col"><span class="sr-only">操作</span></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $fill = json_encode([
              'id' => (int) $r['id'], 'days' => (string) min(90, max(1, (int) $r['expected_days'])),
              'display' => ($r['requester_real_name'] ?: $r['requester_name']) . ' 申请 ' . (($r['permission_label'] ?? '') ?: $r['permission_key']),
              'reason_text' => $r['reason'],
          ], JSON_UNESCAPED_UNICODE);
          $result = '';
          if ($r['status'] === 'approved') $result = '批准人：' . $r['approved_by_name'] . ($r['approval_note'] ? '，备注：' . $r['approval_note'] : '');
          if ($r['status'] === 'rejected') $result = '拒绝人：' . $r['rejected_by_name'] . '，原因：' . $r['reject_reason'];
      ?>
        <tr>
          <?php if ($view !== 'mine'): ?><td><strong><?= Layout::e($r['requester_real_name'] ?: $r['requester_name']) ?></strong><br><span class="muted"><?= Layout::e((string) ($r['department_name'] ?? '') ?: '无部门') ?></span></td><?php endif; ?>
          <td><?= $permCell($r) ?></td>
          <td class="cell-ellipsis" style="max-width:260px" title="<?= Layout::e($r['reason']) ?>"><?= Layout::e($r['reason']) ?></td>
          <td><span class="badge <?= Layout::e($urgencyBadge[$r['urgency']] ?? '') ?>"><?= Layout::e(RequestService::URGENCY_LABELS[$r['urgency']] ?? $r['urgency']) ?></span></td>
          <td class="num"><?= (int) $r['expected_days'] ?></td>
          <td><span class="badge <?= Layout::e($statusBadge[$r['status']] ?? '') ?>" title="<?= Layout::e($result) ?>"><?= Layout::e(RequestService::STATUS_LABELS[$r['status']] ?? $r['status']) ?></span></td>
          <td class="mono"><?= Layout::e(substr((string) $r['created_at'], 0, 16)) ?></td>
          <td>
            <div class="row-actions">
            <?php if ($r['status'] === 'pending' && $view === 'mine'): ?>
              <button type="button" class="btn btn-sm btn-ghost" data-open-dialog="d-req-cancel" data-fill="<?= Layout::e((string) $fill) ?>">取消申请</button>
            <?php elseif ($r['status'] === 'pending' && $service->approveBlockedReason($r) === ''): ?>
              <button type="button" class="btn btn-sm btn-primary" data-open-dialog="d-req-approve" data-fill="<?= Layout::e((string) $fill) ?>">批准</button>
              <button type="button" class="btn btn-sm" data-open-dialog="d-req-reject" data-fill="<?= Layout::e((string) $fill) ?>">拒绝</button>
            <?php elseif ($result !== ''): ?>
              <span class="muted cell-ellipsis" style="max-width:180px" title="<?= Layout::e($result) ?>"><?= Layout::e($result) ?></span>
            <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($view === 'grants' || ($view === 'mine' && $grants)): ?>
<section class="card">
  <div class="card-head"><h2 class="card-title"><?= $view === 'grants' ? '有效的临时授权' : '我当前的临时授权' ?></h2><span class="muted"><?= count($grants) ?> 项</span></div>
  <?php if (!$grants): ?>
    <div class="card-body muted">目前没有有效的临时授权。</div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table" style="min-width: 820px">
      <thead><tr><?php if ($view === 'grants'): ?><th scope="col">授权对象</th><?php endif; ?><th scope="col">权限</th><th scope="col">授权人</th><th scope="col">开始</th><th scope="col">到期</th><th scope="col"><span class="sr-only">操作</span></th></tr></thead>
      <tbody>
      <?php foreach ($grants as $g):
          $fill = json_encode(['id' => (int) $g['id'], 'display' => ($g['real_name'] ?? '') . ' · ' . (($g['permission_label'] ?? '') ?: $g['permission_key']), 'expires_on' => $g['expires_at'] ? date('Y-m-d', strtotime((string) $g['expires_at']) + 86400 * 7) : ''], JSON_UNESCAPED_UNICODE);
          $soon = $g['expires_at'] && strtotime((string) $g['expires_at']) < time() + 86400;
      ?>
        <tr>
          <?php if ($view === 'grants'): ?><td><strong><?= Layout::e((string) $g['real_name']) ?></strong> <span class="mono muted"><?= Layout::e((string) $g['username']) ?></span></td><?php endif; ?>
          <td><?= $permCell($g) ?></td>
          <td><?= Layout::e((string) ($g['granted_by_name'] ?? '')) ?></td>
          <td class="mono"><?= Layout::e(substr((string) $g['starts_at'], 0, 16)) ?></td>
          <td class="mono"><?= $g['expires_at'] ? Layout::e(substr((string) $g['expires_at'], 0, 16)) : '<span class="badge badge-brand">永久</span>' ?><?php if ($soon): ?> <span class="badge badge-warn">即将到期</span><?php endif; ?></td>
          <td>
            <?php if ($view === 'grants' && $canRevoke): ?>
            <div class="row-actions">
              <?php if ($g['expires_at']): ?><button type="button" class="btn btn-sm" data-open-dialog="d-grant-extend" data-fill="<?= Layout::e((string) $fill) ?>">延长</button><?php endif; ?>
              <button type="button" class="btn btn-sm btn-ghost" data-open-dialog="d-grant-revoke" data-fill="<?= Layout::e((string) $fill) ?>">撤销</button>
            </div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<dialog class="dialog dialog-sm" id="d-req-submit" aria-labelledby="d-req-submit-title">
  <form data-api action="<?= Layout::e($api) ?>?action=request_submit">
    <div class="dialog-head"><h2 class="dialog-title" id="d-req-submit-title">申请权限</h2><button type="button" class="icon-btn" data-close-dialog aria-label="关闭">×</button></div>
    <div class="dialog-body stack">
      <p class="alert alert-error form-error" data-form-error hidden></p>
      <div class="field">
        <label class="field-label" for="rq-permission_key">申请的权限<span class="field-req" aria-hidden="true">*</span></label>
        <select class="input select" id="rq-permission_key" name="permission_key" required>
          <option value="">请选择</option>
          <?php foreach ($requestable as $group): ?>
            <optgroup label="<?= Layout::e($group['label']) ?>">
              <?php foreach ($group['permissions'] as $p): ?>
                <option value="<?= Layout::e($p['key']) ?>"><?= Layout::e($p['label'] . '（' . $p['key'] . '）' . (in_array($p['risk'], ['high', 'dangerous'], true) ? ' · 高风险' : '')) ?></option>
              <?php endforeach; ?>
            </optgroup>
          <?php endforeach; ?>
        </select>
        <p class="field-hint">只列出你还没有的权限。高风险权限需要权限管理员审批。</p>
      </div>
      <?= Form::textarea('reason', '申请原因', '', [], ['prefix' => 'rq', 'required' => true, 'maxlength' => 2000, 'placeholder' => '要做什么工作，为什么需要这项权限']) ?>
      <div class="form-grid">
        <?= Form::select('urgency', '紧急程度', RequestService::URGENCY_LABELS, 'normal', [], ['prefix' => 'rq']) ?>
        <?= Form::select('expected_days', '需要多久', $dayOptions, '3', [], ['prefix' => 'rq']) ?>
      </div>
    </div>
    <div class="dialog-foot"><button type="button" class="btn" data-close-dialog>取消</button><button type="submit" class="btn btn-primary">提交申请</button></div>
  </form>
</dialog>

<dialog class="dialog dialog-sm" id="d-req-cancel" aria-labelledby="d-req-cancel-title">
  <form data-api action="<?= Layout::e($api) ?>?action=request_cancel">
    <input type="hidden" name="id">
    <div class="dialog-head"><h2 class="dialog-title" id="d-req-cancel-title">取消申请</h2><button type="button" class="icon-btn" data-close-dialog aria-label="关闭">×</button></div>
    <div class="dialog-body"><p class="alert alert-error form-error" data-form-error hidden></p><p style="margin:0" data-text="display"></p></div>
    <div class="dialog-foot"><button type="button" class="btn" data-close-dialog>不取消</button><button type="submit" class="btn btn-danger">取消申请</button></div>
  </form>
</dialog>

<?php if ($service->isApprover()): ?>
<dialog class="dialog dialog-sm" id="d-req-approve" aria-labelledby="d-req-approve-title">
  <form data-api action="<?= Layout::e($api) ?>?action=request_approve">
    <input type="hidden" name="id">
    <div class="dialog-head"><h2 class="dialog-title" id="d-req-approve-title">批准申请</h2><button type="button" class="icon-btn" data-close-dialog aria-label="关闭">×</button></div>
    <div class="dialog-body stack">
      <p class="alert alert-error form-error" data-form-error hidden></p>
      <p style="margin:0;font-weight:500" data-text="display"></p>
      <p class="muted" style="margin:0" data-text="reason_text"></p>
      <?= Form::select('days', '授权期限', $dayOptions, '3', [], ['prefix' => 'ap', 'hint' => '到期后自动失效']) ?>
      <?php if ($service->isSuper()): ?><label class="check"><input type="checkbox" name="permanent" value="1"> 永久授权（只有超级管理员可以给）</label><?php endif; ?>
      <?= Form::textarea('note', '审批备注', '', [], ['prefix' => 'ap', 'maxlength' => 2000, 'hint' => '高风险权限必须填写']) ?>
    </div>
    <div class="dialog-foot"><button type="button" class="btn" data-close-dialog>取消</button><button type="submit" class="btn btn-primary">批准</button></div>
  </form>
</dialog>
<dialog class="dialog dialog-sm" id="d-req-reject" aria-labelledby="d-req-reject-title">
  <form data-api action="<?= Layout::e($api) ?>?action=request_reject">
    <input type="hidden" name="id">
    <div class="dialog-head"><h2 class="dialog-title" id="d-req-reject-title">拒绝申请</h2><button type="button" class="icon-btn" data-close-dialog aria-label="关闭">×</button></div>
    <div class="dialog-body stack">
      <p class="alert alert-error form-error" data-form-error hidden></p>
      <p style="margin:0;font-weight:500" data-text="display"></p>
      <?= Form::textarea('reason', '拒绝原因', '', [], ['prefix' => 'rj', 'required' => true, 'maxlength' => 500]) ?>
    </div>
    <div class="dialog-foot"><button type="button" class="btn" data-close-dialog>取消</button><button type="submit" class="btn btn-danger">拒绝</button></div>
  </form>
</dialog>
<?php endif; ?>

<?php if ($canRevoke): ?>
<dialog class="dialog dialog-sm" id="d-grant-revoke" aria-labelledby="d-grant-revoke-title">
  <form data-api action="<?= Layout::e($api) ?>?action=grant_revoke">
    <input type="hidden" name="id">
    <div class="dialog-head"><h2 class="dialog-title" id="d-grant-revoke-title">撤销临时授权</h2><button type="button" class="icon-btn" data-close-dialog aria-label="关闭">×</button></div>
    <div class="dialog-body stack">
      <p class="alert alert-error form-error" data-form-error hidden></p>
      <p style="margin:0;font-weight:500" data-text="display"></p>
      <?= Form::textarea('reason', '撤销原因', '', [], ['prefix' => 'gr', 'required' => true, 'maxlength' => 500]) ?>
    </div>
    <div class="dialog-foot"><button type="button" class="btn" data-close-dialog>取消</button><button type="submit" class="btn btn-danger">撤销</button></div>
  </form>
</dialog>
<dialog class="dialog dialog-sm" id="d-grant-extend" aria-labelledby="d-grant-extend-title">
  <form data-api action="<?= Layout::e($api) ?>?action=grant_extend">
    <input type="hidden" name="id">
    <div class="dialog-head"><h2 class="dialog-title" id="d-grant-extend-title">延长临时授权</h2><button type="button" class="icon-btn" data-close-dialog aria-label="关闭">×</button></div>
    <div class="dialog-body stack">
      <p class="alert alert-error form-error" data-form-error hidden></p>
      <p style="margin:0;font-weight:500" data-text="display"></p>
      <?= Form::input('expires_on', '新的到期日', '', [], ['prefix' => 'ge', 'type' => 'date', 'required' => true, 'hint' => '到这一天 23:59 失效，最多到今天起 90 天内']) ?>
      <?= Form::textarea('note', '备注', '', [], ['prefix' => 'ge', 'maxlength' => 500]) ?>
    </div>
    <div class="dialog-foot"><button type="button" class="btn" data-close-dialog>取消</button><button type="submit" class="btn btn-primary">延长</button></div>
  </form>
</dialog>
<?php endif; ?>
<?php
Layout::end();
