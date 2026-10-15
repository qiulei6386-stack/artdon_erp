<?php
declare(strict_types=1);

/** 权限中心 · 人员权限：某个人最终有哪些权限、个人允许/拒绝、数据范围。 */

require_once __DIR__ . '/bootstrap.php';

use Artdon\Centers\Base\Auth;
use Artdon\Centers\Base\Layout;
use Artdon\Centers\Base\Url;
use Artdon\Centers\System\AccountService;
use Artdon\Centers\System\PeopleService;
use Artdon\Centers\System\PermissionTabs;

$user = Auth::requirePage();
Auth::requirePermissionPage('users.view');
$service = new PeopleService($user);
$q = trim((string) ($_GET['q'] ?? ''));
$people = $service->users($q);
$self = Url::center('system', 'people.php');
$api = Url::center('system', 'api.php');

$selectedId = (int) ($_GET['user'] ?? 0);
$target = $selectedId > 0 ? $service->user($selectedId) : null;
if (!$target && $people) {
    $target = $service->user((int) $people[0]['id']);
}

$groups = $target ? $service->effective((int) $target['id']) : [];
$blocked = $target ? $service->editBlockedReason($target) : '';
$editable = $target && $blocked === '';
$scopes = $target ? $service->scopes((int) $target['id']) : [];
$scopeModules = $target ? $service->scopeModules((int) $target['id']) : [];
$grants = $target ? $service->activeGrants((int) $target['id']) : [];
$onCount = 0;
$overrideCount = 0;
$total = 0;
foreach ($groups as $g) {
    foreach ($g['permissions'] as $p) {
        $total++;
        if ($p['on']) $onCount++;
        if ($p['override'] !== '') $overrideCount++;
    }
}
$sourceBadge = ['角色' => '', '个人允许' => 'badge-good', '个人拒绝' => 'badge-bad', '临时授权' => 'badge-warn', '超级管理员' => 'badge-brand'];

Layout::start([
    'title' => '人员权限',
    'nav' => 'permissions',
    'crumbs' => ['系统' => null, '权限中心' => Url::center('system', 'roles.php'), '人员权限' => null],
    'scripts' => [Url::centerAsset('system', 'people.js')],
]);
?>
<div class="page-head">
  <div>
    <h1 class="page-title">权限中心</h1>
    <p class="page-sub">一般只调整角色权限。个人设置用于少数例外：单独允许某项，或单独拒绝某项（拒绝优先于角色和临时授权）。</p>
  </div>
</div>
<?= PermissionTabs::render('people') ?>

<div class="cols">
  <aside class="col-side col-picker" aria-label="选择人员">
    <section class="card">
      <form class="card-head" method="get" action="<?= Layout::e($self) ?>" role="search" style="gap:8px">
        <label class="sr-only" for="pq">搜索人员</label>
        <input class="input" id="pq" type="search" name="q" value="<?= Layout::e($q) ?>" placeholder="姓名、账号、邮箱" style="height:32px">
        <button type="submit" class="btn btn-sm">搜索</button>
      </form>
      <nav class="role-list">
        <?php foreach ($people as $p): $on = $target && (int) $p['id'] === (int) $target['id']; ?>
          <a class="role-item<?= $on ? ' is-active' : '' ?>" href="<?= Layout::e($self . '?' . http_build_query(array_filter(['user' => (int) $p['id'], 'q' => $q]))) ?>"<?= $on ? ' aria-current="page"' : '' ?>>
            <span class="role-item-main"><strong><?= Layout::e($p['real_name']) ?></strong><span class="muted" style="font-size:12px"><?= Layout::e(trim(($p['department_name'] ?? '') . ' · ' . ($p['role_name'] ?? '未分配角色'), ' ·')) ?></span></span>
            <span class="role-item-meta">
              <?php if ((int) $p['is_super_admin'] === 1): ?><span class="badge badge-brand">超管</span><?php endif; ?>
              <?php if ($p['status'] !== 'active'): ?><span class="badge badge-muted"><?= Layout::e(AccountService::STATUS_LABELS[$p['status']] ?? $p['status']) ?></span><?php endif; ?>
              <?php if ((int) $p['override_count'] > 0): ?><span title="个人设置数">个人 <?= (int) $p['override_count'] ?></span><?php endif; ?>
            </span>
          </a>
        <?php endforeach; ?>
        <?php if (!$people): ?><p class="muted" style="padding:10px">没有找到人员。</p><?php endif; ?>
      </nav>
    </section>
  </aside>

  <div class="col-main">
  <?php if ($target): ?>
    <section class="card">
      <div class="card-head">
        <div>
          <h2 class="card-title"><?= Layout::e($target['real_name']) ?> <span class="mono muted" style="font-weight:400"><?= Layout::e($target['username']) ?></span></h2>
          <p class="muted" style="margin:2px 0 0">角色：<?= Layout::e((string) ($target['role_name'] ?? '') ?: '未分配') ?> · 部门：<?= Layout::e((string) ($target['department_name'] ?? '') ?: '未分配') ?> · 生效 <?= $onCount ?> / <?= $total ?> 项 · 个人设置 <?= $overrideCount ?> 项</p>
        </div>
        <?php if ($target['role_name'] ?? ''): ?><a class="btn btn-sm" href="<?= Layout::e(Url::center('system', 'roles.php') . '?role=' . (int) $target['role_id']) ?>">查看角色权限</a><?php endif; ?>
      </div>
      <?php if ($blocked !== ''): ?><p class="muted" style="margin:12px 16px 0"><?= Layout::e($blocked) ?></p><?php endif; ?>
      <?php if ($grants): ?>
        <div class="card-body" style="padding-bottom:0">
          <p style="margin:0 0 6px;font-weight:500">有效的临时授权</p>
          <div style="display:flex;flex-wrap:wrap;gap:6px">
            <?php foreach ($grants as $g): ?>
              <span class="badge badge-warn" title="授权人：<?= Layout::e((string) ($g['granted_by_name'] ?? '')) ?>"><?= Layout::e($g['permission_key']) ?> · <?= $g['expires_at'] ? Layout::e(substr((string) $g['expires_at'], 0, 16)) . ' 到期' : '长期' ?></span>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <form data-api action="<?= Layout::e($api) ?>?action=people_scopes_save" class="card-body">
        <input type="hidden" name="user_id" value="<?= (int) $target['id'] ?>">
        <p class="alert alert-error form-error" data-form-error hidden></p>
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:8px">
          <h3 class="card-title" style="font-size:14px">数据范围</h3>
          <?php if ($editable): ?><button type="submit" class="btn btn-sm btn-primary">保存数据范围</button><?php endif; ?>
        </div>
        <p class="muted" style="margin:0 0 10px">决定这个人在各模块能看到哪些人的数据。没有设置的模块默认只看本人的。</p>
        <div class="scope-grid">
          <?php foreach ($scopeModules as $module => $label):
              $cur = $scopes[$module] ?? ['type' => 'self', 'value' => ''];
              $needsValue = in_array($cur['type'], ['country', 'customer_group'], true);
          ?>
            <div class="scope-row" data-scope-row>
              <label class="scope-label" for="sc-<?= Layout::e($module) ?>"><?= Layout::e($label) ?></label>
              <div class="field">
                <select class="input select" id="sc-<?= Layout::e($module) ?>" name="scope[<?= Layout::e($module) ?>][type]" data-scope-type<?= $editable ? '' : ' disabled' ?>>
                  <?php foreach (PeopleService::SCOPE_TYPES as $type => $typeLabel): ?>
                    <option value="<?= Layout::e($type) ?>"<?= $cur['type'] === $type ? ' selected' : '' ?>><?= Layout::e($typeLabel) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field" data-scope-value<?= $needsValue ? '' : ' hidden' ?>>
                <input class="input" name="scope[<?= Layout::e($module) ?>][value]" value="<?= Layout::e($cur['value']) ?>" placeholder="多个用逗号分隔" aria-label="<?= Layout::e($label) ?>范围值"<?= $editable ? '' : ' disabled' ?>>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </form>
    </section>

    <section class="card">
      <form data-api action="<?= Layout::e($api) ?>?action=people_permissions_save" data-people>
        <input type="hidden" name="user_id" value="<?= (int) $target['id'] ?>">
        <div class="matrix-bar">
          <label class="sr-only" for="pp-filter">搜索权限</label>
          <input class="input input-search" id="pp-filter" type="search" placeholder="搜索权限名称或编码" data-perm-filter>
          <label class="check"><input type="checkbox" data-only-on> 只看已生效</label>
          <label class="check"><input type="checkbox" data-only-override> 只看个人设置</label>
          <span class="muted" data-people-summary></span>
          <span class="top-fill"></span>
          <?php if ($editable): ?>
            <span class="badge badge-warn" data-perm-dirty hidden>有未保存的修改</span>
            <button type="submit" class="btn btn-primary">保存个人设置</button>
          <?php endif; ?>
        </div>
        <p class="alert alert-error form-error" data-form-error hidden style="margin: 12px 16px 0"></p>
        <div class="matrix">
          <?php foreach ($groups as $group): ?>
            <details class="perm-group" open data-perm-group>
              <summary class="perm-group-head">
                <span class="perm-group-title"><?= Layout::e($group['label']) ?> <span class="mono muted"><?= Layout::e($group['module']) ?></span></span>
                <span class="muted" data-group-on></span>
              </summary>
              <div class="people-list">
                <?php foreach ($group['permissions'] as $p):
                    $choice = $p['override'] === '' ? 'inherit' : $p['override'];
                    $allowLocked = !$editable || ($choice !== 'allow' && !$service->canAllow($p['key']));
                    $name = 'perm[' . $p['key'] . ']';
                ?>
                  <div class="people-item" data-perm-item data-search="<?= Layout::e(mb_strtolower($p['label'] . ' ' . $p['key'], 'UTF-8')) ?>" data-role="<?= $p['role'] ? '1' : '0' ?>" data-grant="<?= $p['grant'] ? '1' : '0' ?>" data-super="<?= (int) $target['is_super_admin'] ?>">
                    <span class="people-state" data-state aria-hidden="true"><?= $p['on'] ? '✓' : '—' ?></span>
                    <span class="perm-text"><span class="perm-label"><?= Layout::e($p['label']) ?><?php if ($p['risk'] === 'dangerous'): ?> <span class="badge badge-bad">高危</span><?php elseif ($p['risk'] === 'high'): ?> <span class="badge badge-warn">高</span><?php endif; ?></span><span class="perm-key mono"><?= Layout::e($p['key']) ?></span></span>
                    <span class="people-source"><span class="badge <?= Layout::e($sourceBadge[$p['source']] ?? '') ?>" data-source<?= $p['source'] === '' ? ' hidden' : '' ?> title="<?= $p['grant'] ? Layout::e('临时授权到 ' . ($p['grant_expires'] ?: '长期')) : '' ?>"><?= Layout::e($p['source']) ?></span></span>
                    <span class="seg" role="radiogroup" aria-label="<?= Layout::e($p['label']) ?>">
                      <label class="seg-opt"><input type="radio" name="<?= Layout::e($name) ?>" value="inherit"<?= $choice === 'inherit' ? ' checked' : '' ?><?= $editable ? '' : ' disabled' ?>><span>跟随角色</span></label>
                      <label class="seg-opt"><input type="radio" name="<?= Layout::e($name) ?>" value="allow"<?= $choice === 'allow' ? ' checked' : '' ?><?= $allowLocked ? ' disabled' : '' ?>><span>允许</span></label>
                      <label class="seg-opt"><input type="radio" name="<?= Layout::e($name) ?>" value="deny"<?= $choice === 'deny' ? ' checked' : '' ?><?= $editable ? '' : ' disabled' ?>><span>拒绝</span></label>
                    </span>
                  </div>
                <?php endforeach; ?>
              </div>
            </details>
          <?php endforeach; ?>
          <p class="muted" data-perm-empty hidden style="padding: 16px">没有符合条件的权限。</p>
        </div>
      </form>
    </section>
  <?php else: ?>
    <div class="empty"><p class="empty-text">请从左侧选择一个人。</p></div>
  <?php endif; ?>
  </div>
</div>
<?php
Layout::end();
