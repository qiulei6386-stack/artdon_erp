<?php
declare(strict_types=1);

/** 日志：登录日志、操作日志。只读，可筛选。有 logs.view_all 看全部，只有 logs.view_own 看自己的。 */

require_once __DIR__ . '/bootstrap.php';

use Artdon\Centers\Base\Auth;
use Artdon\Centers\Base\Layout;
use Artdon\Centers\Base\Pager;
use Artdon\Centers\Base\Url;
use Artdon\Centers\System\LogService;

$user = Auth::requirePage();
if (!Auth::can('logs.view_own') && !Auth::can('logs.view_all')) {
    Layout::forbidden('logs.view_own');
}
$service = new LogService($user);
$self = Url::center('system', 'logs.php');
$type = ($_GET['type'] ?? '') === 'audit' ? 'audit' : 'login';
$str = static function (string $key): string {
    $v = $_GET[$key] ?? '';
    return is_string($v) ? trim($v) : '';
};
$date = static function (string $key) use ($str): string {
    $v = $str($key);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
};
$filters = $type === 'login'
    ? ['q' => $str('q'), 'status' => $str('status'), 'source' => $str('source'), 'from' => $date('from'), 'to' => $date('to')]
    : ['q' => $str('q'), 'module' => $str('module'), 'action' => $str('action'), 'from' => $date('from'), 'to' => $date('to')];
$page = max(1, (int) ($_GET['page'] ?? 1));
$result = $type === 'login' ? $service->logins($filters, $page) : $service->audits($filters, $page);
$link = static function (array $changes) use ($filters, $self, $type): string {
    $query = array_filter(array_merge(['type' => $type], $filters, $changes), static function ($v): bool {
        return $v !== '' && $v !== 0 && $v !== null;
    });
    return $self . '?' . http_build_query($query);
};
$filtered = (bool) array_filter($filters, static function ($v): bool {
    return $v !== '';
});
$statusBadges = ['success' => 'badge-good', 'fail' => 'badge-bad', 'logout' => 'badge-muted'];
$pretty = static function ($json): string {
    if ($json === null || $json === '') {
        return '（无）';
    }
    $data = json_decode((string) $json, true);
    if ($data === null) {
        return (string) $json;
    }
    return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
};

Layout::start([
    'title' => '日志',
    'nav' => 'logs',
    'crumbs' => ['系统' => null, '日志' => null],
]);
?>
<div class="page-head">
  <div>
    <h1 class="page-title">日志</h1>
    <p class="page-sub"><?= $service->seesAll() ? '全部账号的记录' : '只显示你自己的记录' ?>，按时间倒序。新系统写的登录记录带“新系统”标记，旧系统的记录也一并显示。</p>
  </div>
</div>
<nav class="tabs" aria-label="日志类型">
  <?php foreach (['login' => '登录日志', 'audit' => '操作日志'] as $key => $label): $on = $key === $type; ?>
    <a class="tab<?= $on ? ' is-active' : '' ?>" href="<?= Layout::e($self . '?type=' . $key) ?>"<?= $on ? ' aria-current="page"' : '' ?>><?= Layout::e($label) ?></a>
  <?php endforeach; ?>
</nav>

<form class="filters" method="get" action="<?= Layout::e($self) ?>" role="search">
  <input type="hidden" name="type" value="<?= Layout::e($type) ?>">
  <label class="sr-only" for="lq"><?= $type === 'login' ? '账号' : '操作人' ?></label>
  <input class="input input-search" id="lq" type="search" name="q" value="<?= Layout::e($filters['q']) ?>" placeholder="<?= $type === 'login' ? '账号' : '操作人' ?>">
  <?php if ($type === 'login'): ?>
    <label class="sr-only" for="ls">结果</label>
    <select class="input select" id="ls" name="status">
      <option value="">全部结果</option>
      <?php foreach (LogService::LOGIN_STATUS as $key => $label): ?><option value="<?= Layout::e($key) ?>"<?= $filters['status'] === $key ? ' selected' : '' ?>><?= Layout::e($label) ?></option><?php endforeach; ?>
    </select>
    <label class="sr-only" for="lsrc">来源</label>
    <select class="input select" id="lsrc" name="source">
      <option value="">新旧系统</option>
      <option value="new"<?= $filters['source'] === 'new' ? ' selected' : '' ?>>只看新系统</option>
      <option value="old"<?= $filters['source'] === 'old' ? ' selected' : '' ?>>只看旧系统</option>
    </select>
  <?php else: ?>
    <label class="sr-only" for="lm">模块</label>
    <select class="input select" id="lm" name="module">
      <option value="">全部模块</option>
      <?php foreach ($service->auditModules() as $m): ?><option value="<?= Layout::e($m) ?>"<?= $filters['module'] === $m ? ' selected' : '' ?>><?= Layout::e($m) ?></option><?php endforeach; ?>
    </select>
    <label class="sr-only" for="la">动作</label>
    <input class="input" id="la" type="search" name="action" value="<?= Layout::e($filters['action']) ?>" placeholder="动作，如 account" style="width: 160px">
  <?php endif; ?>
  <label class="sr-only" for="lf">开始日期</label>
  <input class="input" id="lf" type="date" name="from" value="<?= Layout::e($filters['from']) ?>" title="开始日期">
  <span class="muted">至</span>
  <label class="sr-only" for="lt">结束日期</label>
  <input class="input" id="lt" type="date" name="to" value="<?= Layout::e($filters['to']) ?>" title="结束日期">
  <button type="submit" class="btn btn-sm">筛选</button>
  <?php if ($filtered): ?><a class="btn btn-sm btn-ghost" href="<?= Layout::e($self . '?type=' . $type) ?>">清空</a><?php endif; ?>
</form>

<section class="card">
  <?php if (!$result['rows']): ?>
    <div class="card-body muted">没有符合条件的记录。</div>
  <?php elseif ($type === 'login'): ?>
  <div class="table-wrap">
    <table class="table" style="min-width: 900px; table-layout: fixed">
      <thead>
        <tr>
          <th scope="col" style="width: 160px">时间</th>
          <th scope="col" style="width: 14%">账号</th>
          <th scope="col" style="width: 100px">结果</th>
          <th scope="col" style="width: 80px">来源</th>
          <th scope="col" style="width: 130px">IP</th>
          <th scope="col">说明</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($result['rows'] as $row):
          $message = (string) ($row['message'] ?? '');
          $isNew = strpos($message, '[新系统]') === 0;
          $message = $isNew ? trim(substr($message, strlen('[新系统]'))) : $message;
          $status = (string) $row['status']; ?>
        <tr>
          <td class="nowrap"><?= Layout::e((string) $row['created_at']) ?></td>
          <td class="truncate" title="<?= Layout::e((string) $row['username']) ?>"><?= Layout::e((string) ($row['username'] ?: '—')) ?></td>
          <td><span class="badge <?= Layout::e($statusBadges[$status] ?? '') ?>"><?= Layout::e(LogService::LOGIN_STATUS[$status] ?? $status) ?></span></td>
          <td><?= $isNew ? '<span class="badge badge-brand">新系统</span>' : '<span class="muted">旧系统</span>' ?></td>
          <td class="nowrap"><?= Layout::e((string) ($row['ip'] ?: '—')) ?></td>
          <td class="truncate" title="<?= Layout::e(trim($message . "\n" . (string) ($row['user_agent'] ?? ''))) ?>"><?= Layout::e($message !== '' ? $message : '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table" style="min-width: 900px; table-layout: fixed">
      <thead>
        <tr>
          <th scope="col" style="width: 160px">时间</th>
          <th scope="col" style="width: 14%">操作人</th>
          <th scope="col" style="width: 12%">模块</th>
          <th scope="col">动作</th>
          <th scope="col" style="width: 18%">对象</th>
          <th scope="col" style="width: 130px">IP</th>
          <th scope="col" style="width: 80px"><span class="sr-only">详情</span></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($result['rows'] as $row):
          $target = trim((string) ($row['target_type'] ?? '') . ' ' . (string) ($row['target_id'] ?? ''));
          $fill = json_encode([
              'd_time' => (string) $row['created_at'],
              'd_operator' => (string) ($row['operator_name'] ?: '—'),
              'd_action' => (string) $row['module'] . ' / ' . (string) $row['action'],
              'd_target' => $target !== '' ? $target : '—',
              'd_before' => $pretty($row['before_json'] ?? null),
              'd_after' => $pretty($row['after_json'] ?? null),
          ], JSON_UNESCAPED_UNICODE); ?>
        <tr>
          <td class="nowrap"><?= Layout::e((string) $row['created_at']) ?></td>
          <td class="truncate"><?= Layout::e((string) ($row['operator_name'] ?: '—')) ?></td>
          <td class="truncate"><?= Layout::e((string) $row['module']) ?></td>
          <td class="truncate" title="<?= Layout::e((string) $row['action']) ?>"><?= Layout::e((string) $row['action']) ?></td>
          <td class="truncate" title="<?= Layout::e($target) ?>"><?= Layout::e($target !== '' ? $target : '—') ?></td>
          <td class="nowrap"><?= Layout::e((string) ($row['ip'] ?: '—')) ?></td>
          <td><button type="button" class="btn btn-sm" data-open-dialog="d-audit" data-fill="<?= Layout::e((string) $fill) ?>">详情</button></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <?php if ($result['rows']): ?>
    <?= Pager::render($result, static function (int $p) use ($link): string {
        return $link(['page' => $p]);
    }) ?>
  <?php endif; ?>
</section>

<?php if ($type === 'audit'): ?>
<dialog class="dialog dialog-wide" id="d-audit" aria-labelledby="d-audit-title">
  <div class="dialog-head"><h2 class="dialog-title" id="d-audit-title">操作详情</h2><button type="button" class="icon-btn" data-close-dialog aria-label="关闭">×</button></div>
  <div class="dialog-body stack">
    <dl class="dl">
      <dt>时间</dt><dd data-text="d_time"></dd>
      <dt>操作人</dt><dd data-text="d_operator"></dd>
      <dt>动作</dt><dd data-text="d_action"></dd>
      <dt>对象</dt><dd data-text="d_target"></dd>
    </dl>
    <div class="json-cols">
      <div><p class="field-label">修改前</p><pre class="json" data-text="d_before"></pre></div>
      <div><p class="field-label">修改后</p><pre class="json" data-text="d_after"></pre></div>
    </div>
  </div>
  <div class="dialog-foot"><button type="button" class="btn" data-close-dialog>关闭</button></div>
</dialog>
<?php endif; ?>
<?php
Layout::end();
