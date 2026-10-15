<?php
declare(strict_types=1);

/** 账号管理：账号列表、新建、编辑、审核、拒绝、停用、启用、解锁、重置密码。 */

require_once __DIR__ . '/bootstrap.php';

use Artdon\Centers\Base\Auth;
use Artdon\Centers\Base\Form;
use Artdon\Centers\Base\Layout;
use Artdon\Centers\Base\Url;
use Artdon\Centers\System\AccountService;

$user = Auth::requirePage();
Auth::requirePermissionPage('users.view');

$service = new AccountService($user);
$filters = [
    'status' => (string) ($_GET['status'] ?? ''),
    'q' => trim((string) ($_GET['q'] ?? '')),
    'department_id' => (int) ($_GET['department_id'] ?? 0),
    'role_id' => (int) ($_GET['role_id'] ?? 0),
];
$result = $service->list($filters, (int) ($_GET['page'] ?? 1));
$counts = $service->statusCounts($filters);
$departments = $service->departments();
$roles = $service->roles();

$can = [
    'create' => Auth::can('users.create'),
    'dept' => Auth::can('users.assign_department'),
    'role' => Auth::can('users.assign_role'),
    'approve' => Auth::can('users.approve'),
    'reject' => Auth::can('users.reject'),
    'disable' => Auth::can('users.disable'),
    'enable' => Auth::can('users.enable'),
    'reset' => Auth::can('users.reset_password'),
];
$canEdit = $can['create'] || $can['dept'] || $can['role'];
$isSuper = Auth::isSuperAdmin();
$api = Url::center('system', 'api.php');
$self = Url::center('system', 'accounts.php');

/** 生成保留当前筛选条件的地址。 */
$link = static function (array $changes) use ($filters, $self): string {
    $query = array_filter(array_merge($filters, $changes), static function ($v): bool {
        return $v !== '' && $v !== 0 && $v !== null;
    });
    return $self . ($query ? '?' . http_build_query($query) : '');
};

$statusBadges = [
    'pending' => 'badge-warn',
    'active' => 'badge-good',
    'disabled' => 'badge-muted',
    'rejected' => 'badge-bad',
    'locked' => 'badge-bad',
];
$tabs = ['' => '全部'] + AccountService::STATUS_LABELS;

Layout::start([
    'title' => '账号管理',
    'nav' => 'accounts',
    'crumbs' => ['系统' => null, '账号管理' => null],
]);
?>
<div class="page-head">
  <div>
    <h1 class="page-title">账号管理</h1>
    <p class="page-sub">共 <?= (int) $counts['all'] ?> 个账号<?php if (!empty($counts['pending'])): ?>，<strong><?= (int) $counts['pending'] ?></strong> 个待审核<?php endif; ?>。权限跟着角色走，在权限中心调整。</p>
  </div>
  <?php if ($can['create']): ?>
  <div class="page-actions"><button type="button" class="btn btn-primary" data-open-dialog="d-create">新建账号</button></div>
  <?php endif; ?>
</div>

<nav class="tabs" aria-label="按状态筛选">
  <?php foreach ($tabs as $key => $label): $active = $filters['status'] === $key; ?>
    <a class="tab<?= $active ? ' is-active' : '' ?>" href="<?= Layout::e($link(['status' => $key, 'page' => 0])) ?>"<?= $active ? ' aria-current="page"' : '' ?>><?= Layout::e($label) ?><span class="tab-count"><?= (int) ($key === '' ? $counts['all'] : ($counts[$key] ?? 0)) ?></span></a>
  <?php endforeach; ?>
</nav>

<form class="filters" method="get" action="<?= Layout::e($self) ?>" role="search">
  <?php if ($filters['status'] !== ''): ?><input type="hidden" name="status" value="<?= Layout::e($filters['status']) ?>"><?php endif; ?>
  <label class="sr-only" for="q">搜索账号</label>
  <input class="input input-search" id="q" type="search" name="q" value="<?= Layout::e($filters['q']) ?>" placeholder="账号、姓名、邮箱、电话">
  <label class="sr-only" for="fd">部门</label>
  <select class="input select" id="fd" name="department_id">
    <option value="">全部部门</option>
    <?php foreach ($departments as $id => $name): ?><option value="<?= (int) $id ?>"<?= $filters['department_id'] === $id ? ' selected' : '' ?>><?= Layout::e($name) ?></option><?php endforeach; ?>
  </select>
  <label class="sr-only" for="fr">角色</label>
  <select class="input select" id="fr" name="role_id">
    <option value="">全部角色</option>
    <?php foreach ($roles as $id => $name): ?><option value="<?= (int) $id ?>"<?= $filters['role_id'] === $id ? ' selected' : '' ?>><?= Layout::e($name) ?></option><?php endforeach; ?>
  </select>
  <button type="submit" class="btn btn-sm">筛选</button>
  <?php if ($filters['q'] !== '' || $filters['department_id'] || $filters['role_id']): ?>
    <a class="btn btn-sm btn-ghost" href="<?= Layout::e($link(['q' => '', 'department_id' => 0, 'role_id' => 0, 'page' => 0])) ?>">清空</a>
  <?php endif; ?>
</form>

<section class="card">
  <?php if (!$result['rows']): ?>
    <div class="card-body muted">没有符合条件的账号。</div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table" style="min-width: 980px; table-layout: fixed">
      <thead>
        <tr>
          <th scope="col" style="width: 18%">账号</th>
          <th scope="col" style="width: 12%">部门</th>
          <th scope="col" style="width: 12%">职位</th>
          <th scope="col" style="width: 12%">角色</th>
          <th scope="col" style="width: 15%">状态</th>
          <th scope="col" style="width: 14%">最近登录</th>
          <th scope="col" style="width: 17%"><span class="sr-only">操作</span></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($result['rows'] as $row):
          $status = (string) $row['status'];
          $tempLocked = !empty($row['locked_until']) && strtotime((string) $row['locked_until']) > time();
          $protected = (int) $row['is_super_admin'] === 1 && !$isSuper;
          $selfRow = (int) $row['id'] === (int) $user['id'];
          $fill = json_encode([
              'id' => (int) $row['id'], 'username' => $row['username'], 'real_name' => $row['real_name'], 'english_name' => $row['english_name'],
              'email' => $row['email'], 'phone' => $row['phone'], 'position' => $row['position'],
              'department_id' => $row['department_id'], 'role_id' => $row['role_id'],
              'display' => $row['real_name'] . '（' . $row['username'] . '）',
          ], JSON_UNESCAPED_UNICODE);
          $menu = [];
          if (!$protected) {
              if ($status === 'pending' && $can['reject']) $menu[] = ['d-reject', '拒绝申请', true];
              if ($status === 'active' && $can['reset']) $menu[] = ['d-reset', '重置密码', false];
              if ($tempLocked && $can['enable']) $menu[] = ['d-unlock', '解除锁定', false];
              if ($status === 'active' && $can['disable'] && !$selfRow) $menu[] = ['d-disable', '停用账号', true];
              if (in_array($status, ['disabled', 'rejected', 'locked'], true) && $can['enable']) $menu[] = ['d-enable', '启用账号', false];
          }
      ?>
        <tr>
          <td class="cell-ellipsis" title="<?= Layout::e($row['real_name'] . ' ' . $row['username'] . ' ' . ($row['email'] ?? '')) ?>">
            <strong><?= Layout::e($row['real_name']) ?></strong>
            <span class="mono muted" style="margin-left:6px"><?= Layout::e($row['username']) ?></span>
          </td>
          <td class="cell-ellipsis"><?= Layout::e((string) ($row['department_name'] ?? '') ?: '—') ?></td>
          <td class="cell-ellipsis"><?= Layout::e((string) ($row['position'] ?? '') ?: '—') ?></td>
          <td class="cell-ellipsis"><?= Layout::e((string) ($row['role_name'] ?? '') ?: '未分配') ?></td>
          <td>
            <span class="badge <?= Layout::e($statusBadges[$status] ?? '') ?>"><?= Layout::e(AccountService::STATUS_LABELS[$status] ?? $status) ?></span>
            <?php if ((int) $row['is_super_admin'] === 1): ?><span class="badge badge-brand">超管</span><?php endif; ?>
            <?php if ($tempLocked): ?><span class="badge badge-bad" title="锁定到 <?= Layout::e((string) $row['locked_until']) ?>">临时锁定</span><?php endif; ?>
            <?php if ((int) $row['force_password_change'] === 1 && $status === 'active'): ?><span class="badge badge-muted" title="对方下次登录时需要修改密码">待改密码</span><?php endif; ?>
          </td>
          <td class="mono"><?= Layout::e($row['last_login_at'] ? substr((string) $row['last_login_at'], 0, 16) : '从未登录') ?></td>
          <td>
            <div class="row-actions">
              <?php if ($protected): ?>
                <span class="muted">仅超管可管理</span>
              <?php elseif ($status === 'pending' && $can['approve']): ?>
                <button type="button" class="btn btn-sm" data-open-dialog="d-approve" data-fill="<?= Layout::e((string) $fill) ?>">审核</button>
              <?php elseif ($canEdit): ?>
                <button type="button" class="btn btn-sm" data-open-dialog="d-edit" data-fill="<?= Layout::e((string) $fill) ?>">编辑</button>
              <?php endif; ?>
              <?php if ($menu): ?>
                <details class="menu">
                  <summary class="btn btn-sm btn-ghost" aria-label="更多操作：<?= Layout::e($row['real_name']) ?>">更多</summary>
                  <div class="menu-panel">
                    <?php foreach ($menu as [$dialog, $label, $danger]): ?>
                      <button type="button" class="menu-item<?= $danger ? ' is-danger' : '' ?>" data-open-dialog="<?= Layout::e($dialog) ?>" data-fill="<?= Layout::e((string) $fill) ?>"><?= Layout::e($label) ?></button>
                    <?php endforeach; ?>
                  </div>
                </details>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($result['pages'] > 1): ?>
  <nav class="pager" aria-label="分页">
    <span>第 <?= (int) $result['page'] ?> / <?= (int) $result['pages'] ?> 页，共 <?= (int) $result['total'] ?> 个</span>
    <div class="pager-links">
      <?php for ($p = 1; $p <= $result['pages']; $p++): ?>
        <?php if ($p === $result['page']): ?><span class="is-current" aria-current="page"><?= $p ?></span><?php else: ?><a href="<?= Layout::e($link(['page' => $p])) ?>"><?= $p ?></a><?php endif; ?>
      <?php endfor; ?>
    </div>
  </nav>
  <?php endif; ?>
  <?php endif; ?>
</section>

<?php
$deptOptions = ['placeholder' => '请选择部门', 'required' => true];
$roleOptions = ['placeholder' => '请选择角色', 'required' => true];

if ($can['create']): ?>
<dialog class="dialog" id="d-create" aria-labelledby="d-create-title">
  <form data-api action="<?= Layout::e($api) ?>?action=account_create">
    <div class="dialog-head"><h2 class="dialog-title" id="d-create-title">新建账号</h2><button type="button" class="icon-btn" data-close-dialog aria-label="关闭">×</button></div>
    <div class="dialog-body form-grid">
      <p class="alert alert-error form-error span-2" data-form-error hidden></p>
      <?= Form::input('username', '账号', '', [], ['prefix' => 'c', 'required' => true, 'maxlength' => 80, 'autocomplete' => 'off', 'hint' => '用于登录：字母、数字、点、下划线或横线']) ?>
      <?= Form::input('real_name', '姓名', '', [], ['prefix' => 'c', 'required' => true, 'maxlength' => 120]) ?>
      <?= Form::input('english_name', '英文名', '', [], ['prefix' => 'c', 'maxlength' => 120]) ?>
      <?= Form::input('email', '邮箱', '', [], ['prefix' => 'c', 'type' => 'email', 'maxlength' => 190]) ?>
      <?= Form::input('phone', '手机 / 电话', '', [], ['prefix' => 'c', 'type' => 'tel', 'maxlength' => 60]) ?>
      <?= Form::input('position', '职位', '', [], ['prefix' => 'c', 'maxlength' => 120]) ?>
      <?= Form::select('department_id', '部门', $departments, '', [], ['prefix' => 'c'] + $deptOptions) ?>
      <?= Form::select('role_id', '角色', $roles, '', [], ['prefix' => 'c'] + $roleOptions) ?>
      <div class="span-2"><?= Form::input('password', '初始密码', '', [], ['prefix' => 'c', 'type' => 'password', 'autocomplete' => 'new-password', 'hint' => '留空则自动生成。对方首次登录时必须修改。']) ?></div>
    </div>
    <div class="dialog-foot"><button type="button" class="btn" data-close-dialog>取消</button><button type="submit" class="btn btn-primary">创建账号</button></div>
  </form>
</dialog>
<?php endif; ?>

<?php if ($canEdit): ?>
<dialog class="dialog" id="d-edit" aria-labelledby="d-edit-title">
  <form data-api action="<?= Layout::e($api) ?>?action=account_update">
    <input type="hidden" name="id">
    <div class="dialog-head"><h2 class="dialog-title" id="d-edit-title">编辑账号：<span data-text="display"></span></h2><button type="button" class="icon-btn" data-close-dialog aria-label="关闭">×</button></div>
    <div class="dialog-body form-grid">
      <p class="alert alert-error form-error span-2" data-form-error hidden></p>
      <?php if ($can['create']): ?>
        <?= Form::input('username', '账号', '', [], ['prefix' => 'e', 'required' => true, 'maxlength' => 80, 'autocomplete' => 'off']) ?>
        <?= Form::input('real_name', '姓名', '', [], ['prefix' => 'e', 'required' => true, 'maxlength' => 120]) ?>
        <?= Form::input('english_name', '英文名', '', [], ['prefix' => 'e', 'maxlength' => 120]) ?>
        <?= Form::input('email', '邮箱', '', [], ['prefix' => 'e', 'type' => 'email', 'maxlength' => 190]) ?>
        <?= Form::input('phone', '手机 / 电话', '', [], ['prefix' => 'e', 'type' => 'tel', 'maxlength' => 60]) ?>
        <?= Form::input('position', '职位', '', [], ['prefix' => 'e', 'maxlength' => 120]) ?>
      <?php endif; ?>
      <?php if ($can['dept']): ?><?= Form::select('department_id', '部门', $departments, '', [], ['prefix' => 'e'] + $deptOptions) ?><?php endif; ?>
      <?php if ($can['role']): ?><?= Form::select('role_id', '角色', $roles, '', [], ['prefix' => 'e'] + $roleOptions) ?><?php endif; ?>
    </div>
    <div class="dialog-foot"><button type="button" class="btn" data-close-dialog>取消</button><button type="submit" class="btn btn-primary">保存</button></div>
  </form>
</dialog>
<?php endif; ?>

<?php if ($can['approve']): ?>
<dialog class="dialog dialog-sm" id="d-approve" aria-labelledby="d-approve-title">
  <form data-api action="<?= Layout::e($api) ?>?action=account_approve">
    <input type="hidden" name="id">
    <div class="dialog-head"><h2 class="dialog-title" id="d-approve-title">审核通过：<span data-text="display"></span></h2><button type="button" class="icon-btn" data-close-dialog aria-label="关闭">×</button></div>
    <div class="dialog-body stack">
      <p class="alert alert-error form-error" data-form-error hidden></p>
      <p class="muted" style="margin:0">通过后账号即可登录。请分配部门和角色，权限跟着角色走。</p>
      <?= Form::select('department_id', '部门', $departments, '', [], ['prefix' => 'a'] + $deptOptions) ?>
      <?= Form::select('role_id', '角色', $roles, '', [], ['prefix' => 'a'] + $roleOptions) ?>
      <?= Form::input('position', '职位', '', [], ['prefix' => 'a', 'maxlength' => 120]) ?>
    </div>
    <div class="dialog-foot"><button type="button" class="btn" data-close-dialog>取消</button><button type="submit" class="btn btn-primary">审核通过</button></div>
  </form>
</dialog>
<?php endif; ?>

<?php if ($can['reject']): ?>
<dialog class="dialog dialog-sm" id="d-reject" aria-labelledby="d-reject-title">
  <form data-api action="<?= Layout::e($api) ?>?action=account_reject">
    <input type="hidden" name="id">
    <div class="dialog-head"><h2 class="dialog-title" id="d-reject-title">拒绝申请：<span data-text="display"></span></h2><button type="button" class="icon-btn" data-close-dialog aria-label="关闭">×</button></div>
    <div class="dialog-body stack">
      <p class="alert alert-error form-error" data-form-error hidden></p>
      <?= Form::textarea('reason', '拒绝原因', '', [], ['prefix' => 'r', 'required' => true, 'maxlength' => 500]) ?>
    </div>
    <div class="dialog-foot"><button type="button" class="btn" data-close-dialog>取消</button><button type="submit" class="btn btn-danger">拒绝</button></div>
  </form>
</dialog>
<?php endif; ?>

<?php if ($can['reset']): ?>
<dialog class="dialog dialog-sm" id="d-reset" aria-labelledby="d-reset-title">
  <form data-api action="<?= Layout::e($api) ?>?action=account_reset_password">
    <input type="hidden" name="id">
    <div class="dialog-head"><h2 class="dialog-title" id="d-reset-title">重置密码：<span data-text="display"></span></h2><button type="button" class="icon-btn" data-close-dialog aria-label="关闭">×</button></div>
    <div class="dialog-body stack">
      <p class="alert alert-error form-error" data-form-error hidden></p>
      <?= Form::input('new_password', '新密码', '', [], ['prefix' => 'p', 'type' => 'password', 'autocomplete' => 'new-password', 'hint' => '留空则自动生成。重置后对方下次登录时必须修改，同时解除临时锁定。']) ?>
    </div>
    <div class="dialog-foot"><button type="button" class="btn" data-close-dialog>取消</button><button type="submit" class="btn btn-primary">重置密码</button></div>
  </form>
</dialog>
<?php endif; ?>

<?php if ($can['disable']): ?>
<dialog class="dialog dialog-sm" id="d-disable" aria-labelledby="d-disable-title">
  <form data-api action="<?= Layout::e($api) ?>?action=account_disable">
    <input type="hidden" name="id">
    <div class="dialog-head"><h2 class="dialog-title" id="d-disable-title">停用账号：<span data-text="display"></span></h2><button type="button" class="icon-btn" data-close-dialog aria-label="关闭">×</button></div>
    <div class="dialog-body stack">
      <p class="alert alert-error form-error" data-form-error hidden></p>
      <p style="margin:0">停用后对方不能再登录新系统，已经打开的新系统页面会被退出。可以随时重新启用。</p>
      <?= Form::textarea('reason', '原因（选填）', '', [], ['prefix' => 'x', 'maxlength' => 500]) ?>
    </div>
    <div class="dialog-foot"><button type="button" class="btn" data-close-dialog>取消</button><button type="submit" class="btn btn-danger">停用</button></div>
  </form>
</dialog>
<?php endif; ?>

<?php if ($can['enable']): ?>
<dialog class="dialog dialog-sm" id="d-enable" aria-labelledby="d-enable-title">
  <form data-api action="<?= Layout::e($api) ?>?action=account_enable">
    <input type="hidden" name="id">
    <div class="dialog-head"><h2 class="dialog-title" id="d-enable-title">启用账号：<span data-text="display"></span></h2><button type="button" class="icon-btn" data-close-dialog aria-label="关闭">×</button></div>
    <div class="dialog-body"><p class="alert alert-error form-error" data-form-error hidden></p><p style="margin:0">启用后对方可以用原来的密码登录。</p></div>
    <div class="dialog-foot"><button type="button" class="btn" data-close-dialog>取消</button><button type="submit" class="btn btn-primary">启用</button></div>
  </form>
</dialog>
<dialog class="dialog dialog-sm" id="d-unlock" aria-labelledby="d-unlock-title">
  <form data-api action="<?= Layout::e($api) ?>?action=account_unlock">
    <input type="hidden" name="id">
    <div class="dialog-head"><h2 class="dialog-title" id="d-unlock-title">解除锁定：<span data-text="display"></span></h2><button type="button" class="icon-btn" data-close-dialog aria-label="关闭">×</button></div>
    <div class="dialog-body"><p class="alert alert-error form-error" data-form-error hidden></p><p style="margin:0">对方因多次输错密码被临时锁定。解除后可以马上重新登录。</p></div>
    <div class="dialog-foot"><button type="button" class="btn" data-close-dialog>取消</button><button type="submit" class="btn btn-primary">解除锁定</button></div>
  </form>
</dialog>
<?php endif; ?>
<?php
Layout::end();
