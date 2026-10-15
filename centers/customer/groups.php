<?php
declare(strict_types=1);

/** 客户分组管理。查看：customer.view；新建、改名、删除：customer.batch。 */

require_once __DIR__ . '/bootstrap.php';

use Artdon\Centers\Base\Auth;
use Artdon\Centers\Base\Form;
use Artdon\Centers\Base\Layout;
use Artdon\Centers\Base\Url;
use Artdon\Centers\Customer\GroupService;

$user = Auth::requirePage();
Auth::requirePermissionPage('customer.view');
$service = new GroupService($user);
$canManage = $service->canManage();
$groups = GroupService::all();
$api = Url::center('customer', 'api.php');
$colorField = static function (string $prefix): string {
    $h = '<fieldset class="field span-2 check-group"><legend class="field-label">颜色</legend><div class="check-list">';
    foreach (GroupService::COLORS as $i => $color) {
        $h .= '<label class="check"><input type="radio" name="group_color" value="' . Layout::e($color) . '"' . ($i === 0 ? ' checked' : '') . '><span class="group-dot" style="background:' . Layout::e($color) . '"></span></label>';
    }
    return $h . '</div></fieldset>';
};

Layout::start([
    'title' => '客户分组',
    'nav' => 'customer',
    'crumbs' => ['客户中心' => Url::center('customer', 'index.php'), '客户分组' => null],
    'styles' => [Url::centerAsset('customer', 'customer.css')],
    'scripts' => [Url::centerAsset('customer', 'customer.js')],
]);
?>
<div class="page-head">
  <div>
    <h1 class="page-title">客户分组</h1>
    <p class="page-sub">全公司共用。一个客户可以在多个分组；在客户列表里勾选客户后可以批量调整分组。<?= $canManage ? '' : '管理分组需要“客户批量操作”权限（customer.batch）。' ?></p>
  </div>
  <?php if ($canManage): ?><div class="page-actions"><button type="button" class="btn btn-primary" data-open-dialog="d-group" data-fill='{"id":"","title_text":"新建分组","sort_order":"100","group_color":"<?= Layout::e(GroupService::COLORS[0]) ?>"}'>新建分组</button></div><?php endif; ?>
</div>
<section class="card" style="max-width: 820px">
  <?php if (!$groups): ?>
    <div class="card-body muted">还没有分组。</div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table" style="table-layout: fixed">
      <thead><tr><th scope="col">分组</th><th scope="col" style="width: 110px">客户数</th><th scope="col" style="width: 90px">排序</th><th scope="col" style="width: 150px"><span class="sr-only">操作</span></th></tr></thead>
      <tbody>
      <?php foreach ($groups as $g):
          $fill = json_encode(['id' => (int) $g['id'], 'title_text' => '编辑分组', 'group_name' => $g['group_name'], 'group_color' => $g['group_color'] ?: GroupService::COLORS[0], 'sort_order' => (string) $g['sort_order']], JSON_UNESCAPED_UNICODE); ?>
        <tr>
          <td class="cell-main"><span class="group-dot" style="background:<?= Layout::e(preg_match('/^#[0-9a-fA-F]{6}$/', (string) $g['group_color']) ? (string) $g['group_color'] : '#64748b') ?>"></span><a class="row-link" href="<?= Layout::e(Url::center('customer', 'index.php?tab=all&group_id=' . (int) $g['id'])) ?>"><?= Layout::e((string) $g['group_name']) ?></a></td>
          <td><?= (int) $g['customer_count'] ?></td>
          <td class="muted"><?= (int) $g['sort_order'] ?></td>
          <td class="cell-actions"><?php if ($canManage): ?>
            <button type="button" class="btn btn-sm" data-open-dialog="d-group" data-fill="<?= Layout::e((string) $fill) ?>">编辑</button>
            <button type="button" class="btn btn-sm btn-ghost" data-open-dialog="d-group-delete" data-fill="<?= Layout::e((string) json_encode(['id' => (int) $g['id'], 'group_text' => $g['group_name'], 'count_text' => (string) $g['customer_count']], JSON_UNESCAPED_UNICODE)) ?>">删除</button>
          <?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php if ($canManage): ?>
<dialog class="dialog dialog-sm" id="d-group" aria-labelledby="d-group-title">
  <form data-api action="<?= Layout::e($api) ?>?action=group_create" data-switch-action="<?= Layout::e($api) ?>?action=group_update">
    <div class="dialog-head"><h2 class="dialog-title" id="d-group-title" data-text="title_text">分组</h2><button type="button" class="icon-btn" data-close-dialog aria-label="关闭">×</button></div>
    <div class="dialog-body form-grid">
      <p class="alert alert-error form-error span-2" data-form-error hidden></p>
      <input type="hidden" name="id" value="">
      <div class="span-2"><?= Form::input('group_name', '分组名称', '', [], ['prefix' => 'g', 'required' => true, 'maxlength' => 120]) ?></div>
      <?= $colorField('g') ?>
      <?= Form::input('sort_order', '排序', '100', [], ['prefix' => 'g', 'inputmode' => 'numeric', 'hint' => '数字小的排前面']) ?>
    </div>
    <div class="dialog-foot"><button type="button" class="btn" data-close-dialog>取消</button><button type="submit" class="btn btn-primary">保存</button></div>
  </form>
</dialog>
<dialog class="dialog dialog-sm" id="d-group-delete" aria-labelledby="d-group-delete-title">
  <form data-api action="<?= Layout::e($api) ?>?action=group_delete">
    <div class="dialog-head"><h2 class="dialog-title" id="d-group-delete-title">删除分组</h2><button type="button" class="icon-btn" data-close-dialog aria-label="关闭">×</button></div>
    <div class="dialog-body stack"><p class="alert alert-error form-error" data-form-error hidden></p><input type="hidden" name="id" value="">
      <p style="margin:0">确定删除分组“<strong data-text="group_text"></strong>”吗？里面的 <span data-text="count_text"></span> 个客户不会被删除，只是不再属于这个分组。</p></div>
    <div class="dialog-foot"><button type="button" class="btn" data-close-dialog>取消</button><button type="submit" class="btn btn-danger">删除</button></div>
  </form>
</dialog>
<?php endif; ?>
<?php
Layout::end();
