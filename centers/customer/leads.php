<?php
declare(strict_types=1);

/** 客户暂存池列表。 */

require_once __DIR__ . '/bootstrap.php';

use Artdon\Centers\Base\Auth;
use Artdon\Centers\Base\Layout;
use Artdon\Centers\Base\Pager;
use Artdon\Centers\Base\Url;
use Artdon\Centers\Customer\Country;
use Artdon\Centers\Customer\LeadPool;

$user = Auth::requirePage();
if (!LeadPool::canView($user)) {
    Layout::forbidden('customer.lead_pool_view');
}
$pool = new LeadPool($user);
$view = (string) ($_GET['view'] ?? 'pending');
if (!isset(LeadPool::VIEWS[$view])) {
    $view = 'pending';
}
$q = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$result = $pool->list($view, $q, (int) ($_GET['page'] ?? 1));
$counts = $pool->counts();
$self = Url::center('customer', 'leads.php');
$statusBadge = ['pending' => 'badge-warn', 'confirmed' => 'badge-good', 'merged' => 'badge-brand', 'rejected' => 'badge-muted'];

Layout::start([
    'title' => '客户暂存池',
    'nav' => 'customer',
    'crumbs' => ['客户中心' => Url::center('customer', 'index.php'), '暂存池' => null],
    'styles' => [Url::centerAsset('customer', 'customer.css')],
]);
?>
<div class="page-head">
  <div>
    <h1 class="page-title">客户暂存池</h1>
    <p class="page-sub">按准入规则或查重规则暂存的客户，确认后才进入正式客户。<?= $pool->canProcess() ? '点开一条处理：确认入库、关联已有客户或丢弃。' : '你只能查看，处理需要“处理暂存池”权限（customer.lead_pool）。' ?></p>
  </div>
</div>
<nav class="tabs" aria-label="暂存池分类">
  <?php foreach (LeadPool::VIEWS as $key => $label): $on = $key === $view; ?>
    <a class="tab<?= $on ? ' is-active' : '' ?>" href="<?= Layout::e($self . '?view=' . $key) ?>"<?= $on ? ' aria-current="page"' : '' ?>><?= Layout::e($label) ?> <span class="tab-count"><?= (int) $counts[$key] ?></span></a>
  <?php endforeach; ?>
</nav>
<form class="filters" method="get" action="<?= Layout::e($self) ?>" role="search">
  <input type="hidden" name="view" value="<?= Layout::e($view) ?>">
  <label class="sr-only" for="lq">搜索</label>
  <input class="input input-search" id="lq" type="search" name="q" value="<?= Layout::e($q) ?>" placeholder="名称、邮箱、电话、国家、域名">
  <button type="submit" class="btn btn-sm">搜索</button>
  <?php if ($q !== ''): ?><a class="btn btn-sm btn-ghost" href="<?= Layout::e($self . '?view=' . $view) ?>">清空</a><?php endif; ?>
</form>
<section class="card">
  <?php if (!$result['rows']): ?>
    <div class="card-body muted"><?= $q !== '' ? '没有符合条件的记录。' : '这里没有记录。' ?></div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table" style="min-width: 920px; table-layout: fixed">
      <thead><tr>
        <th scope="col" style="width: 26%">客户</th><th scope="col" style="width: 12%">国家</th><th scope="col" style="width: 20%">邮箱 / 电话</th>
        <th scope="col" style="width: 11%">来源</th><th scope="col" style="width: 11%">提交人</th><th scope="col" style="width: 10%">状态</th><th scope="col" style="width: 10%">时间</th>
      </tr></thead>
      <tbody>
      <?php foreach ($result['rows'] as $row): $f = $row['fields']; ?>
        <tr>
          <td class="cell-main"><a class="row-link" href="<?= Layout::e(Url::center('customer', 'lead.php?id=' . (int) $row['id'])) ?>"><?= Layout::e($f['customer_name'] !== '' ? $f['customer_name'] : '（没有名称）') ?></a>
            <?php if ($row['match_count'] && $row['status'] === 'pending'): ?><span class="badge badge-warn">疑似重复 <?= (int) $row['match_count'] ?></span><?php endif; ?>
            <small class="cell-sub">#<?= (int) $row['id'] ?><?= $row['contact_count'] ? ' · 联系人 ' . (int) $row['contact_count'] : '' ?><?= !empty($row['target_name']) ? ' · → ' . Layout::e((string) $row['target_name']) : '' ?></small></td>
          <td class="truncate"><?= Layout::e($f['country'] !== '' ? Country::label($f['country']) : '—') ?></td>
          <td class="truncate"><?= Layout::e($f['email'] !== '' ? $f['email'] : '—') ?><small class="cell-sub"><?= Layout::e($f['phone']) ?></small></td>
          <td class="truncate"><?= Layout::e($row['source_name'] !== '' ? $row['source_name'] : '—') ?></td>
          <td class="truncate"><?= Layout::e((string) ($row['creator_name'] ?: '系统 / 导入')) ?></td>
          <td><span class="badge <?= $statusBadge[$row['status']] ?? '' ?>"><?= Layout::e(LeadPool::STATUS_LABELS[$row['status']] ?? $row['status']) ?></span></td>
          <td class="nowrap muted"><?= Layout::e(substr((string) $row['created_at'], 0, 10)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= Pager::render($result, static function (int $p) use ($self, $view, $q): string {
      return $self . '?' . http_build_query(array_filter(['view' => $view, 'q' => $q, 'page' => $p]));
  }) ?>
  <?php endif; ?>
</section>
<?php
Layout::end();
