<?php
declare(strict_types=1);

/** 权限中心 · 部门管理。 */

require_once __DIR__ . '/bootstrap.php';

use Artdon\Centers\Base\Auth;
use Artdon\Centers\Base\Form;
use Artdon\Centers\Base\Layout;
use Artdon\Centers\Base\Url;
use Artdon\Centers\System\DepartmentService;
use Artdon\Centers\System\PermissionTabs;

$user = Auth::requirePage();
Auth::requirePermissionPage('users.view');
$service = new DepartmentService($user);
$rows = $service->list();
$managers = $service->managerOptions();
$canManage = Auth::can('users.manage_departments');
$api = Url::center('system', 'api.php');
$active = count(array_filter($rows, static function (array $r): bool {
    return $r['status'] === 'active';
}));

Layout::start([
    'title' => '部门',
    'nav' => 'permissions',
    'crumbs' => ['系统' => null, '权限中心' => Url::center('system', 'roles.php'), '部门' => null],
]);
?>
<div class="page-head">
  <div>
    <h1 class="page-title">权限中心</h1>
    <p class="page-sub"><?= $active ?> 个启用中的部门。部门用于账号归属和按部门审批，权限由角色决定。</p>
  </div>
  <?php if ($canManage): ?><div class="page-actions"><button type="button" class="btn btn-primary" data-open-dialog="d-dept-create">新建部门</button></div><?php endif; ?>
</div>
<?= PermissionTabs::render('departments') ?>

<section class="card">
  <div class="table-wrap">
    <table class="table" style="min-width: 760px">
      <thead><tr><th scope="col">部门名称</th><th scope="col">编码</th><th scope="col">负责人</th><th scope="col">账号数</th><th scope="col">排序</th><th scope="col">状态</th><th scope="col"><span class="sr-only">操作</span></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row):
          $fill = json_encode(['id' => (int) $row['id'], 'name' => $row['name'], 'code' => $row['code'], 'sort_order' => (int) $row['sort_order'], 'manager_user_id' => $row['manager_user_id'], 'display' => $row['name']], JSON_UNESCAPED_UNICODE);
          $on = $row['status'] === 'active';
      ?>
        <tr>
          <td><strong><?= Layout::e($row['name']) ?></strong></td>
          <td class="mono muted"><?= Layout::e($row['code']) ?></td>
          <td><?= Layout::e((string) ($row['manager_name'] ?? '') ?: '—') ?></td>
          <td class="num"><?= (int) $row['user_count'] ?></td>
          <td class="num"><?= (int) $row['sort_order'] ?></td>
          <td><span class="badge <?= $on ? 'badge-good' : 'badge-muted' ?>"><?= $on ? '启用' : '已停用' ?></span></td>
          <td>
            <?php if ($canManage): ?>
            <div class="row-actions">
              <button type="button" class="btn btn-sm" data-open-dialog="d-dept-edit" data-fill="<?= Layout::e((string) $fill) ?>">编辑</button>
              <?php if ($on): ?>
                <button type="button" class="btn btn-sm btn-ghost" data-open-dialog="d-dept-disable" data-fill="<?= Layout::e((string) $fill) ?>">停用</button>
              <?php else: ?>
                <button type="button" class="btn btn-sm btn-ghost" data-open-dialog="d-dept-enable" data-fill="<?= Layout::e((string) $fill) ?>">启用</button>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php if ($canManage): ?>
<dialog class="dialog dialog-sm" id="d-dept-create" aria-labelledby="d-dept-create-title">
  <form data-api action="<?= Layout::e($api) ?>?action=department_create">
    <div class="dialog-head"><h2 class="dialog-title" id="d-dept-create-title">新建部门</h2><button type="button" class="icon-btn" data-close-dialog aria-label="关闭">×</button></div>
    <div class="dialog-body stack">
      <p class="alert alert-error form-error" data-form-error hidden></p>
      <?= Form::input('name', '部门名称', '', [], ['prefix' => 'dc', 'required' => true, 'maxlength' => 100]) ?>
      <?= Form::input('code', '部门编码', '', [], ['prefix' => 'dc', 'maxlength' => 60, 'hint' => '选填，小写字母、数字、下划线。留空自动生成，建好后不能改。']) ?>
      <?= Form::select('manager_user_id', '负责人', $managers, '', [], ['prefix' => 'dc', 'placeholder' => '不指定']) ?>
      <?= Form::input('sort_order', '排序', '100', [], ['prefix' => 'dc', 'inputmode' => 'numeric', 'hint' => '数字越小越靠前']) ?>
    </div>
    <div class="dialog-foot"><button type="button" class="btn" data-close-dialog>取消</button><button type="submit" class="btn btn-primary">创建</button></div>
  </form>
</dialog>
<dialog class="dialog dialog-sm" id="d-dept-edit" aria-labelledby="d-dept-edit-title">
  <form data-api action="<?= Layout::e($api) ?>?action=department_update">
    <input type="hidden" name="id">
    <div class="dialog-head"><h2 class="dialog-title" id="d-dept-edit-title">编辑部门：<span data-text="display"></span></h2><button type="button" class="icon-btn" data-close-dialog aria-label="关闭">×</button></div>
    <div class="dialog-body stack">
      <p class="alert alert-error form-error" data-form-error hidden></p>
      <?= Form::input('name', '部门名称', '', [], ['prefix' => 'de', 'required' => true, 'maxlength' => 100]) ?>
      <div class="field"><span class="field-label">部门编码</span><div class="field-static mono" data-text="code"></div><p class="field-hint">编码建好后不能改。</p></div>
      <?= Form::select('manager_user_id', '负责人', $managers, '', [], ['prefix' => 'de', 'placeholder' => '不指定']) ?>
      <?= Form::input('sort_order', '排序', '', [], ['prefix' => 'de', 'inputmode' => 'numeric']) ?>
    </div>
    <div class="dialog-foot"><button type="button" class="btn" data-close-dialog>取消</button><button type="submit" class="btn btn-primary">保存</button></div>
  </form>
</dialog>
<dialog class="dialog dialog-sm" id="d-dept-disable" aria-labelledby="d-dept-disable-title">
  <form data-api action="<?= Layout::e($api) ?>?action=department_disable">
    <input type="hidden" name="id">
    <div class="dialog-head"><h2 class="dialog-title" id="d-dept-disable-title">停用部门：<span data-text="display"></span></h2><button type="button" class="icon-btn" data-close-dialog aria-label="关闭">×</button></div>
    <div class="dialog-body"><p class="alert alert-error form-error" data-form-error hidden></p><p style="margin:0">停用后不能再把账号分到这个部门。部门下还有账号时不能停用。</p></div>
    <div class="dialog-foot"><button type="button" class="btn" data-close-dialog>取消</button><button type="submit" class="btn btn-danger">停用</button></div>
  </form>
</dialog>
<dialog class="dialog dialog-sm" id="d-dept-enable" aria-labelledby="d-dept-enable-title">
  <form data-api action="<?= Layout::e($api) ?>?action=department_enable">
    <input type="hidden" name="id">
    <div class="dialog-head"><h2 class="dialog-title" id="d-dept-enable-title">启用部门：<span data-text="display"></span></h2><button type="button" class="icon-btn" data-close-dialog aria-label="关闭">×</button></div>
    <div class="dialog-body"><p class="alert alert-error form-error" data-form-error hidden></p><p style="margin:0">启用后可以重新把账号分到这个部门。</p></div>
    <div class="dialog-foot"><button type="button" class="btn" data-close-dialog>取消</button><button type="submit" class="btn btn-primary">启用</button></div>
  </form>
</dialog>
<?php endif; ?>
<?php
Layout::end();
