<?php
declare(strict_types=1);

/** 权限中心 · 角色与角色权限。 */

require_once __DIR__ . '/bootstrap.php';

use Artdon\Centers\Base\Auth;
use Artdon\Centers\Base\Form;
use Artdon\Centers\Base\Layout;
use Artdon\Centers\Base\Url;
use Artdon\Centers\System\PermissionTabs;
use Artdon\Centers\System\RoleService;

$user = Auth::requirePage();
Auth::requirePermissionPage('users.view');
$service = new RoleService($user);
$roles = $service->list();
$canManage = Auth::can('users.manage_roles');
$canMatrix = $service->canEditPermissions();
$isSuper = Auth::isSuperAdmin();
$api = Url::center('system', 'api.php');
$self = Url::center('system', 'roles.php');

$selectedId = (int) ($_GET['role'] ?? 0);
$selected = null;
foreach ($roles as $role) {
    if ((int) $role['id'] === $selectedId) {
        $selected = $role;
    }
}
if (!$selected && $roles) {
    $selected = $roles[0];
}
$catalog = $service->catalog();
$owned = $selected ? array_flip($service->permissionsOf((int) $selected['id'])) : [];
$total = 0;
foreach ($catalog as $group) {
    $total += count($group['permissions']);
}
$lockedRole = $selected && $selected['role_key'] === 'super_admin' && !$isSuper;
$editable = $selected && $canMatrix && !$lockedRole;

Layout::start([
    'title' => '角色与权限',
    'nav' => 'permissions',
    'crumbs' => ['系统' => null, '权限中心' => Url::center('system', 'roles.php'), '角色与权限' => null],
    'scripts' => [Url::centerAsset('system', 'roles.js')],
]);
?>
<div class="page-head">
  <div>
    <h1 class="page-title">权限中心</h1>
    <p class="page-sub">每个账号的权限来自所属角色。改了角色权限，这个角色下的所有账号马上生效。</p>
  </div>
  <?php if ($canManage): ?><div class="page-actions"><button type="button" class="btn btn-primary" data-open-dialog="d-role-create">新建角色</button></div><?php endif; ?>
</div>
<?= PermissionTabs::render('roles') ?>

<div class="cols">
  <aside class="col-side" style="flex: 1 1 260px; max-width: 320px" aria-label="角色列表">
    <section class="card">
      <div class="card-head"><h2 class="card-title">角色</h2><span class="muted"><?= count($roles) ?> 个</span></div>
      <nav class="role-list">
        <?php foreach ($roles as $role): $on = $selected && (int) $role['id'] === (int) $selected['id']; ?>
          <a class="role-item<?= $on ? ' is-active' : '' ?>" href="<?= Layout::e($self . '?role=' . (int) $role['id']) ?>"<?= $on ? ' aria-current="page"' : '' ?>>
            <span class="role-item-main"><strong><?= Layout::e($role['role_name']) ?></strong><span class="mono muted"><?= Layout::e($role['role_key']) ?></span></span>
            <span class="role-item-meta">
              <?php if ($role['status'] !== 'active'): ?><span class="badge badge-muted">已停用</span><?php endif; ?>
              <span title="使用这个角色的账号数"><?= (int) $role['user_count'] ?> 人</span>
            </span>
          </a>
        <?php endforeach; ?>
      </nav>
    </section>
  </aside>

  <div class="col-main">
  <?php if ($selected):
      $fill = json_encode(['id' => (int) $selected['id'], 'role_name' => $selected['role_name'], 'description' => $selected['description'], 'role_key' => $selected['role_key'], 'display' => $selected['role_name']], JSON_UNESCAPED_UNICODE);
  ?>
    <section class="card">
      <div class="card-head">
        <div>
          <h2 class="card-title"><?= Layout::e($selected['role_name']) ?> <span class="mono muted" style="font-weight:400"><?= Layout::e($selected['role_key']) ?></span></h2>
          <p class="muted" style="margin:2px 0 0"><?= Layout::e((string) ($selected['description'] ?? '') ?: '没有说明') ?> · <?= (int) $selected['user_count'] ?> 个账号<?= (int) $selected['is_system'] === 1 ? ' · 系统内置' : '' ?></p>
        </div>
        <?php if ($canManage && !$lockedRole): ?>
        <div class="row-actions">
          <button type="button" class="btn btn-sm" data-open-dialog="d-role-edit" data-fill="<?= Layout::e((string) $fill) ?>">编辑</button>
          <?php if ($selected['status'] === 'active'): ?>
            <button type="button" class="btn btn-sm btn-ghost" data-open-dialog="d-role-disable" data-fill="<?= Layout::e((string) $fill) ?>">停用</button>
          <?php else: ?>
            <button type="button" class="btn btn-sm btn-ghost" data-open-dialog="d-role-enable" data-fill="<?= Layout::e((string) $fill) ?>">启用</button>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>

      <form data-api action="<?= Layout::e($api) ?>?action=role_permissions_save" id="matrix" data-matrix>
        <input type="hidden" name="role_id" value="<?= (int) $selected['id'] ?>">
        <span data-array="permissions" hidden></span>
        <div class="matrix-bar">
          <label class="sr-only" for="perm-filter">搜索权限</label>
          <input class="input input-search" id="perm-filter" type="search" placeholder="搜索权限名称或编码" data-perm-filter>
          <label class="check"><input type="checkbox" data-perm-only-checked> 只看已勾选</label>
          <span class="muted" data-perm-summary>已选 <?= count($owned) ?> / <?= $total ?> 项</span>
          <span class="top-fill"></span>
          <?php if ($editable): ?>
            <span class="badge badge-warn" data-perm-dirty hidden>有未保存的修改</span>
            <button type="submit" class="btn btn-primary">保存权限</button>
          <?php elseif ($lockedRole): ?>
            <span class="muted">只有超级管理员可以修改这个角色</span>
          <?php elseif (!$canMatrix): ?>
            <span class="muted">只读：修改角色权限需要“权限管理员”权限</span>
          <?php endif; ?>
        </div>
        <p class="alert alert-error form-error" data-form-error hidden style="margin: 0 16px 12px"></p>
        <?php if ($editable && !$isSuper): ?>
          <p class="muted" style="margin: 0 16px 12px">灰色不可勾选的是你自己没有的权限，不能授予给角色。</p>
        <?php endif; ?>
        <div class="matrix">
          <?php foreach ($catalog as $group):
              $checkedInGroup = 0;
              foreach ($group['permissions'] as $p) {
                  if (isset($owned[$p['key']])) $checkedInGroup++;
              }
          ?>
            <details class="perm-group" open data-perm-group>
              <summary class="perm-group-head">
                <span class="perm-group-title"><?= Layout::e($group['label']) ?> <span class="mono muted"><?= Layout::e($group['module']) ?></span></span>
                <span class="muted" data-perm-group-count><?= $checkedInGroup ?> / <?= count($group['permissions']) ?></span>
                <?php if ($editable): ?><label class="check perm-all" onclick="event.stopPropagation()"><input type="checkbox" data-perm-all> 全选</label><?php endif; ?>
              </summary>
              <div class="perm-grid">
                <?php foreach ($group['permissions'] as $p):
                    $has = isset($owned[$p['key']]);
                    $disabled = !$editable || (!$has && !$service->grantable($p['key']));
                ?>
                  <label class="perm-item<?= $disabled ? ' is-disabled' : '' ?>" data-perm-item data-search="<?= Layout::e(mb_strtolower($p['label'] . ' ' . $p['key'], 'UTF-8')) ?>">
                    <input type="checkbox" name="permissions[]" value="<?= Layout::e($p['key']) ?>"<?= $has ? ' checked' : '' ?><?= $disabled ? ' disabled' : '' ?>>
                    <?php if ($disabled && $has): ?><input type="hidden" name="permissions[]" value="<?= Layout::e($p['key']) ?>"><?php endif; ?>
                    <span class="perm-text"><span class="perm-label"><?= Layout::e($p['label']) ?></span><span class="perm-key mono"><?= Layout::e($p['key']) ?></span></span>
                    <?php if ($p['risk'] === 'dangerous'): ?><span class="badge badge-bad">高危</span><?php elseif ($p['risk'] === 'high'): ?><span class="badge badge-warn">高</span><?php endif; ?>
                  </label>
                <?php endforeach; ?>
              </div>
            </details>
          <?php endforeach; ?>
          <p class="muted" data-perm-empty hidden style="padding: 16px">没有符合条件的权限。</p>
        </div>
      </form>
    </section>
  <?php else: ?>
    <div class="empty"><p class="empty-text">还没有角色。</p></div>
  <?php endif; ?>
  </div>
</div>

<?php if ($canManage): ?>
<dialog class="dialog dialog-sm" id="d-role-create" aria-labelledby="d-role-create-title">
  <form data-api action="<?= Layout::e($api) ?>?action=role_create">
    <div class="dialog-head"><h2 class="dialog-title" id="d-role-create-title">新建角色</h2><button type="button" class="icon-btn" data-close-dialog aria-label="关闭">×</button></div>
    <div class="dialog-body stack">
      <p class="alert alert-error form-error" data-form-error hidden></p>
      <?= Form::input('role_name', '角色名称', '', [], ['prefix' => 'rc', 'required' => true, 'maxlength' => 120]) ?>
      <?= Form::input('role_key', '角色编码', '', [], ['prefix' => 'rc', 'required' => true, 'maxlength' => 80, 'hint' => '小写字母、数字、下划线，例如 sales_manager。建好后不能改。']) ?>
      <?= Form::textarea('description', '说明', '', [], ['prefix' => 'rc', 'maxlength' => 255]) ?>
      <p class="muted" style="margin:0">新角色没有任何权限，创建后在右侧勾选。</p>
    </div>
    <div class="dialog-foot"><button type="button" class="btn" data-close-dialog>取消</button><button type="submit" class="btn btn-primary">创建</button></div>
  </form>
</dialog>
<dialog class="dialog dialog-sm" id="d-role-edit" aria-labelledby="d-role-edit-title">
  <form data-api action="<?= Layout::e($api) ?>?action=role_update">
    <input type="hidden" name="id">
    <div class="dialog-head"><h2 class="dialog-title" id="d-role-edit-title">编辑角色：<span data-text="display"></span></h2><button type="button" class="icon-btn" data-close-dialog aria-label="关闭">×</button></div>
    <div class="dialog-body stack">
      <p class="alert alert-error form-error" data-form-error hidden></p>
      <?= Form::input('role_name', '角色名称', '', [], ['prefix' => 're', 'required' => true, 'maxlength' => 120]) ?>
      <div class="field"><span class="field-label">角色编码</span><div class="field-static mono" data-text="role_key"></div></div>
      <?= Form::textarea('description', '说明', '', [], ['prefix' => 're', 'maxlength' => 255]) ?>
    </div>
    <div class="dialog-foot"><button type="button" class="btn" data-close-dialog>取消</button><button type="submit" class="btn btn-primary">保存</button></div>
  </form>
</dialog>
<dialog class="dialog dialog-sm" id="d-role-disable" aria-labelledby="d-role-disable-title">
  <form data-api action="<?= Layout::e($api) ?>?action=role_disable">
    <input type="hidden" name="id">
    <div class="dialog-head"><h2 class="dialog-title" id="d-role-disable-title">停用角色：<span data-text="display"></span></h2><button type="button" class="icon-btn" data-close-dialog aria-label="关闭">×</button></div>
    <div class="dialog-body"><p class="alert alert-error form-error" data-form-error hidden></p><p style="margin:0">停用后不能再把这个角色分给账号。还有账号在用时不能停用。</p></div>
    <div class="dialog-foot"><button type="button" class="btn" data-close-dialog>取消</button><button type="submit" class="btn btn-danger">停用</button></div>
  </form>
</dialog>
<dialog class="dialog dialog-sm" id="d-role-enable" aria-labelledby="d-role-enable-title">
  <form data-api action="<?= Layout::e($api) ?>?action=role_enable">
    <input type="hidden" name="id">
    <div class="dialog-head"><h2 class="dialog-title" id="d-role-enable-title">启用角色：<span data-text="display"></span></h2><button type="button" class="icon-btn" data-close-dialog aria-label="关闭">×</button></div>
    <div class="dialog-body"><p class="alert alert-error form-error" data-form-error hidden></p><p style="margin:0">启用后可以重新把这个角色分给账号。</p></div>
    <div class="dialog-foot"><button type="button" class="btn" data-close-dialog>取消</button><button type="submit" class="btn btn-primary">启用</button></div>
  </form>
</dialog>
<?php endif; ?>
<?php
Layout::end();
