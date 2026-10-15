<?php
declare(strict_types=1);

/** 导入客户：上传 → 预览 → 确认导入 → 结果。 */

require_once __DIR__ . '/bootstrap.php';

use Artdon\Centers\Base\Auth;
use Artdon\Centers\Base\Csrf;
use Artdon\Centers\Base\Flash;
use Artdon\Centers\Base\Http;
use Artdon\Centers\Base\Layout;
use Artdon\Centers\Base\Url;
use Artdon\Centers\Base\ValidationException;
use Artdon\Centers\Customer\Country;
use Artdon\Centers\Customer\CustomerExport;
use Artdon\Centers\Customer\CustomerImport;
use Artdon\Centers\Customer\RuleConfig;

$user = Auth::requirePage();
Auth::requirePermissionPage('customer.import');
$import = new CustomerImport($user);
$self = Url::center('customer', 'import.php');

if (isset($_GET['template'])) {
    $sample = ['示例照明有限公司', 'Sample Lighting Ltd', '印度', 'Mumbai', '', 'www.sample-lighting.in', 'info@sample-lighting.in', '+91 22 1234 5678', '', '', '', '', '', '展会', '邮件', '', '', '',
        'Raj', '采购经理', 'raj@sample-lighting.in', '', '', '', '', '', '', ''];
    CustomerExport::send([CustomerImport::templateHeaders(), $sample], '客户导入模板.csv');
    exit;
}

$error = '';
if (Http::isPost()) {
    if (!Csrf::valid()) {
        $error = '页面已过期，请刷新后重新上传';
    } else {
        try {
            $token = $import->upload($_FILES['file'] ?? []);
            Http::redirect($self . '?token=' . $token);
        } catch (ValidationException $e) {
            $error = implode('；', $e->fieldErrors());
        }
    }
}

$token = is_string($_GET['token'] ?? null) ? (string) $_GET['token'] : '';
$preview = null;
if ($token !== '') {
    try {
        $preview = $import->load($token);
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
        $token = '';
    }
}
$show = (string) ($_GET['show'] ?? 'all');

Layout::start([
    'title' => '导入客户',
    'nav' => 'customer',
    'crumbs' => ['客户中心' => Url::center('customer', 'index.php'), '导入客户' => null],
    'styles' => [Url::centerAsset('customer', 'customer.css')],
]);
?>
<div class="page-head">
  <div>
    <h1 class="page-title">导入客户</h1>
    <p class="page-sub">支持 Excel（.xlsx）和 CSV。第一行是表头，可以用模板，也可以直接用“导出客户”导出的文件改。一次最多 5000 行。</p>
  </div>
  <div class="page-actions"><a class="btn" href="<?= Layout::e($self . '?template=1') ?>">下载模板</a></div>
</div>
<?php if ($error !== ''): ?><div class="alert alert-error"><?= Layout::e($error) ?></div><?php endif; ?>

<?php if (!$preview): ?>
<section class="card" style="max-width: 760px">
  <form method="post" enctype="multipart/form-data" action="<?= Layout::e($self) ?>">
    <?= Csrf::field() ?>
    <div class="card-head"><h2 class="card-title">第一步：选择文件</h2></div>
    <div class="card-body stack">
      <div class="field"><label class="field-label" for="im-file">客户文件</label><input class="input" id="im-file" type="file" name="file" accept=".xlsx,.csv" required style="padding-top:5px"></div>
      <ul class="muted" style="margin:0; padding-left:18px">
        <li>必填：客户名称、国家（中文、英文或代码都可以，如“印度 / India / IN”）。</li>
        <li>等级、阶段、来源、推广方式按名称填写，多个用顿号或逗号隔开；不认识的会提示并用默认值。</li>
        <li>联系人：联系人1姓名、联系人1邮箱……最多 3 个。</li>
        <li>负责人：填姓名或账号，有分配权限才生效，否则负责人是你自己。</li>
        <li>已有同名客户、客户代码重复的行不会导入；高度疑似重复的行按查重规则<?= RuleConfig::duplicateAction() === 'block' ? '不导入' : '进入暂存池' ?>。</li>
      </ul>
    </div>
    <div class="card-foot"><button type="submit" class="btn btn-primary">上传并检查</button></div>
  </form>
</section>
<?php else:
    $rows = $preview['rows'];
    $result = $preview['result'] ?? null;
    $errorRows = count(array_filter($rows, static function (array $r): bool {
        return (bool) $r['errors'];
    }));
    $filter = static function (array $r) use ($show): bool {
        if ($show === 'error') {
            return (bool) $r['errors'];
        }
        if ($show === 'warning') {
            return !$r['errors'] && $r['warnings'];
        }
        return true;
    };
    $statusText = ['created' => ['已导入', 'badge-good'], 'lead_pool' => ['进暂存池', 'badge-warn'], 'failed' => ['失败', 'badge-bad'], 'skipped' => ['未导入', 'badge-muted']]; ?>
<section class="card">
  <div class="card-head">
    <h2 class="card-title"><?= $result ? '导入结果' : '第二步：检查结果' ?> · <?= Layout::e((string) $preview['file_name']) ?></h2>
    <a class="btn btn-sm btn-ghost" href="<?= Layout::e($self) ?>">重新上传</a>
  </div>
  <div class="card-body stack">
    <?php if ($result): ?>
      <div class="alert alert-success">已导入 <?= (int) $result['created'] ?> 个客户<?= $result['lead_pool'] ? '，' . (int) $result['lead_pool'] . ' 个进入暂存池' : '' ?><?= $result['failed'] ? '，' . (int) $result['failed'] . ' 个失败' : '' ?><?= $result['skipped'] ? '，' . (int) $result['skipped'] . ' 个未导入' : '' ?>。</div>
    <?php else: ?>
      <p style="margin:0">共 <?= count($rows) ?> 行：<strong class="text-good"><?= (int) $preview['valid'] ?></strong> 行可以导入（其中 <?= (int) $preview['warnings'] ?> 行有提示），<strong class="<?= $errorRows ? 'text-bad' : '' ?>"><?= $errorRows ?></strong> 行有问题不会导入。</p>
      <?php if ($preview['unknown']): ?><p class="muted" style="margin:0">这些列没有认出来，会被忽略：<?= Layout::e(implode('、', $preview['unknown'])) ?></p><?php endif; ?>
    <?php endif; ?>
  </div>
  <nav class="tabs" aria-label="筛选" style="padding: 0 16px">
    <?php foreach (['all' => '全部', 'error' => '有问题', 'warning' => '有提示'] as $k => $v): ?>
      <a class="tab<?= $show === $k ? ' is-active' : '' ?>" href="<?= Layout::e($self . '?token=' . $token . '&show=' . $k) ?>"><?= Layout::e($v) ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="table-wrap">
    <table class="table" style="min-width: 900px; table-layout: fixed">
      <thead><tr><th scope="col" style="width: 60px">行</th><th scope="col" style="width: 26%">客户</th><th scope="col" style="width: 12%">国家</th><th scope="col" style="width: 10%">联系人</th><th scope="col"><?= $result ? '结果' : '检查' ?></th></tr></thead>
      <tbody>
      <?php $shown = 0; foreach ($rows as $r): if (!$filter($r)) { continue; } if (++$shown > 500) { break; } $d = $r['data']; ?>
        <tr>
          <td class="muted"><?= (int) $r['row_no'] ?></td>
          <td class="cell-main"><?= Layout::e($d['customer_name'] !== '' ? $d['customer_name'] : '（空）') ?><?php if ($d['customer_name_en'] !== ''): ?><small class="cell-sub"><?= Layout::e($d['customer_name_en']) ?></small><?php endif; ?></td>
          <td class="truncate"><?= Layout::e($d['country'] !== '' ? Country::label($d['country']) : '—') ?></td>
          <td><?= count($d['contacts']) ?></td>
          <td class="cell-main">
            <?php if ($result && isset($r['result'])): [$label, $badge] = $statusText[$r['result']['status']] ?? [$r['result']['status'], '']; ?>
              <span class="badge <?= $badge ?>"><?= Layout::e($label) ?></span>
              <?php if (!empty($r['result']['customer_id'])): ?><a href="<?= Layout::e(Url::center('customer', 'view.php?id=' . (int) $r['result']['customer_id'])) ?>">查看</a><?php elseif ($r['result']['status'] !== 'created'): ?><small class="cell-sub" style="white-space:normal"><?= Layout::e((string) $r['result']['message']) ?></small><?php endif; ?>
            <?php elseif ($r['errors']): ?>
              <span class="badge badge-bad">不导入</span><small class="cell-sub" style="white-space:normal"><?= Layout::e(implode('；', $r['errors'])) ?></small>
            <?php else: ?>
              <span class="badge <?= $r['high_risk'] ? 'badge-warn' : 'badge-good' ?>"><?= $r['high_risk'] ? (RuleConfig::duplicateAction() === 'block' ? '不导入' : '进暂存池') : '可导入' ?></span>
              <?php if ($r['warnings']): ?><small class="cell-sub" style="white-space:normal"><?= Layout::e(implode('；', $r['warnings'])) ?></small><?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$shown): ?><tr><td colspan="5" class="muted">没有这类行。</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($shown > 500): ?><div class="card-body muted">只显示前 500 行。</div><?php endif; ?>
  <?php if (!$result && $preview['valid'] > 0): ?>
  <div class="card-foot">
    <form data-api action="<?= Layout::e(Url::center('customer', 'api.php')) ?>?action=import_commit">
      <input type="hidden" name="token" value="<?= Layout::e($token) ?>">
      <button type="submit" class="btn btn-primary">导入 <?= (int) $preview['valid'] ?> 行</button>
    </form>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php
Layout::end();
