<?php
declare(strict_types=1);

function yf_row_synced_at(array $row): string
{
    $times = array();
    if (!empty($row['item_synced_at'])) {
        $times[] = (string)$row['item_synced_at'];
    }
    if (!empty($row['cost_synced_at'])) {
        $times[] = (string)$row['cost_synced_at'];
    }
    rsort($times);
    return $times ? $times[0] : '';
}

function yf_render_page(array $model): string
{
    $q = (string)($model['q'] ?? '');
    $code = (string)($model['code'] ?? '');
    $rows = $model['rows'] ?? array();
    $detail = $model['detail'] ?? null;
    $canCost = !empty($model['can_cost']);
    $ready = !empty($model['ready']);
    $mirror = (string)($model['mirror_synced_at'] ?? '');
    $user = (string)($model['user_label'] ?? '');
    $notice = (string)($model['notice'] ?? '');
    $recent = !empty($model['recent']);

    ob_start();
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>易飞只读数据</title>
<style>
:root{--bg:#f5f7fb;--card:#fff;--text:#1f2937;--muted:#6b7280;--line:#e5e7eb;--main:#2563eb;--danger:#b91c1c}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font-family:-apple-system,BlinkMacSystemFont,"PingFang SC","Microsoft YaHei",Arial,sans-serif;font-size:14px}
a{color:var(--main);text-decoration:none}
header{background:#fff;border-bottom:1px solid var(--line)}
.wrap{width:min(1100px,calc(100% - 32px));margin:0 auto;padding:16px 0 40px}
.top{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;padding:14px 0}
h1{font-size:22px;margin:0}
.sub{color:var(--muted);margin-top:4px}
.nav{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.nav a,.user{border:1px solid var(--line);background:#fff;border-radius:999px;padding:6px 10px;font-size:12px;font-weight:700}
.user{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe}
.banner{background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:12px;padding:10px 12px;line-height:1.6}
.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:14px;margin-top:14px;box-shadow:0 4px 16px rgba(0,0,0,.04)}
form{display:flex;gap:8px;flex-wrap:wrap}
input[type=search]{flex:1;min-width:220px;border:1px solid var(--line);border-radius:10px;padding:10px 12px;font:inherit}
button{border:0;border-radius:10px;padding:10px 14px;background:var(--main);color:#fff;cursor:pointer;font:inherit}
.meta{display:flex;gap:16px;flex-wrap:wrap;color:var(--muted);font-size:13px;margin-top:8px}
.meta b{color:var(--text)}
table{width:100%;border-collapse:collapse}
th,td{border-bottom:1px solid var(--line);padding:8px 6px;text-align:left;vertical-align:top}
th{font-size:12px;color:#374151;background:#f9fafb}
.num{text-align:right;font-variant-numeric:tabular-nums}
.cost{color:var(--danger);font-weight:800}
.code{font-weight:800}
tr.active{background:#eff6ff}
.empty{color:var(--muted);padding:18px 0}
.notice{margin-top:10px;color:#92400e;font-weight:700}
h2{font-size:16px;margin:0 0 8px}
.kv{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px;margin:10px 0}
.kv div{background:#f9fafb;border:1px solid var(--line);border-radius:10px;padding:8px 10px}
.kv span{display:block;color:var(--muted);font-size:12px}
.table-wrap{overflow:auto}
@media(max-width:640px){.wrap{width:calc(100% - 20px)}h1{font-size:18px}}
</style>
</head>
<body>
<header><div class="wrap" style="padding-bottom:0">
  <div class="top">
    <div>
      <h1>易飞只读数据</h1>
      <div class="sub">鼎捷易飞品号、BOM 与最新成本。只读镜像，不能在这里修改。</div>
    </div>
    <div class="nav">
      <a href="../bom.php">返回 BOM 成本</a>
      <a href="../index.php">首页</a>
      <?php if ($user !== ''): ?><span class="user"><?= yf_h($user) ?></span><?php endif; ?>
    </div>
  </div>
</div></header>
<main class="wrap">
  <div class="banner">数据由本地易飞（业务库 AT）只读推送。本页没有回写按钮，也不会改易飞里的任何资料。成本只显示每个品号最新期间的 CSTMB.MB005。</div>
  <section class="card">
    <form method="get" action="">
      <input id="q" type="search" name="q" value="<?= yf_h($q) ?>" placeholder="品号或品名，例如 A4-2-01-4286" maxlength="80" autofocus>
      <button type="submit">查询</button>
    </form>
    <div class="meta">
      <span>镜像最近同步 <b><?= $mirror !== '' ? yf_h($mirror) : '还没有数据' ?></b></span>
      <?php if (!$canCost): ?><span>当前账号不能查看成本（需要 bom.cost_view）</span><?php endif; ?>
    </div>
    <?php if ($notice !== ''): ?><div class="notice"><?= yf_h($notice) ?></div><?php endif; ?>
  </section>
  <?php if (!$ready): ?>
    <section class="card"><p class="empty">镜像表还没有建好。管理员在服务器执行 <b>php yifei_sync/migrate.php</b> 后再查询。</p></section>
  <?php else: ?>
    <?php if (is_array($detail)): ?>
      <?= yf_render_detail($detail, $canCost) ?>
    <?php endif; ?>
    <section class="card">
      <h2><?= $recent ? '最近同步的品号' : '查询结果' ?></h2>
      <?php if (!$rows): ?>
        <p class="empty"><?= $q === '' ? '还没有同步到品号。' : '没有匹配的品号或品名。' ?></p>
      <?php else: ?>
        <div class="table-wrap">
        <table>
          <thead><tr>
            <th>品号</th><th>品名</th><th>规格</th>
            <?php if ($canCost): ?><th class="num">最新成本</th><th>成本期间</th><?php endif; ?>
            <th>同步时间</th>
          </tr></thead>
          <tbody>
          <?php foreach ($rows as $row): ?>
            <?php $itemCode = (string)$row['item_code']; ?>
            <tr class="<?= $itemCode === $code ? 'active' : '' ?>">
              <td class="code"><a href="?q=<?= yf_h(rawurlencode($q)) ?>&amp;code=<?= yf_h(rawurlencode($itemCode)) ?>"><?= yf_h($itemCode) ?></a></td>
              <td><?= yf_h($row['item_name'] !== '' ? $row['item_name'] : '—') ?></td>
              <td><?= yf_h($row['spec'] !== '' ? $row['spec'] : '—') ?></td>
              <?php if ($canCost): ?>
                <td class="num cost"><?= $row['cost'] === null || $row['cost'] === '' ? '—' : yf_h(yf_format_decimal($row['cost'])) ?></td>
                <td><?= yf_h($row['period'] !== null && $row['period'] !== '' ? $row['period'] : '—') ?></td>
              <?php endif; ?>
              <td><?= yf_h(yf_row_synced_at($row) ?: '—') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</main>
</body>
</html>
    <?php
    return (string)ob_get_clean();
}

function yf_render_detail(array $detail, bool $canCost): string
{
    $item = $detail['item'] ?? null;
    $cost = $detail['cost'] ?? null;
    $name = is_array($item) ? (string)$item['item_name'] : '';
    $spec = is_array($item) ? (string)$item['spec'] : '';
    ob_start();
    ?>
    <section class="card">
      <h2><?= yf_h($detail['code']) ?><?= $name !== '' ? ' · ' . yf_h($name) : '' ?></h2>
      <div class="kv">
        <div><span>品名</span><b><?= yf_h($name !== '' ? $name : '主档尚未同步') ?></b></div>
        <div><span>规格</span><b><?= yf_h($spec !== '' ? $spec : '—') ?></b></div>
        <?php if ($canCost): ?>
          <div><span>最新成本（MB005）</span><b class="cost"><?= is_array($cost) ? yf_h(yf_format_decimal($cost['cost'])) : '—' ?></b></div>
          <div><span>成本期间（MB002）</span><b><?= is_array($cost) && $cost['period'] !== '' ? yf_h($cost['period']) : '—' ?></b></div>
          <div><span>单据号（MB004，不是品号）</span><b><?= is_array($cost) && $cost['doc_no'] !== '' ? yf_h($cost['doc_no']) : '—' ?></b></div>
        <?php endif; ?>
        <div><span>最后同步时间</span><b><?= yf_h($detail['synced_at'] !== '' ? $detail['synced_at'] : '—') ?></b></div>
      </div>
      <h2>BOM 子件</h2>
      <?php if (!$detail['children']): ?>
        <p class="empty">这个品号还没有同步到 BOM 子件。</p>
      <?php else: ?>
        <div class="table-wrap">
        <table>
          <thead><tr>
            <th>元件品号</th><th>品名</th><th>规格</th><th class="num">用量</th>
            <?php if ($canCost): ?><th class="num">元件最新成本</th><?php endif; ?>
          </tr></thead>
          <tbody>
          <?php foreach ($detail['children'] as $child): ?>
            <tr>
              <td class="code"><a href="?q=<?= yf_h(rawurlencode((string)$child['component_code'])) ?>&amp;code=<?= yf_h(rawurlencode((string)$child['component_code'])) ?>"><?= yf_h($child['component_code']) ?></a></td>
              <td><?= yf_h($child['item_name'] !== '' ? $child['item_name'] : '—') ?></td>
              <td><?= yf_h($child['spec'] !== '' ? $child['spec'] : '—') ?></td>
              <td class="num"><?= yf_h(yf_format_decimal($child['qty'])) ?></td>
              <?php if ($canCost): ?><td class="num cost"><?= $child['cost'] === null || $child['cost'] === '' ? '—' : yf_h(yf_format_decimal($child['cost'])) ?></td><?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php endif; ?>
      <?php if (!empty($detail['parents'])): ?>
        <h2 style="margin-top:16px">作为元件被用于</h2>
        <div class="table-wrap">
        <table>
          <thead><tr><th>主件品号</th><th>主件品名</th><th class="num">用量</th></tr></thead>
          <tbody>
          <?php foreach ($detail['parents'] as $parent): ?>
            <tr>
              <td class="code"><a href="?q=<?= yf_h(rawurlencode((string)$parent['parent_code'])) ?>&amp;code=<?= yf_h(rawurlencode((string)$parent['parent_code'])) ?>"><?= yf_h($parent['parent_code']) ?></a></td>
              <td><?= yf_h($parent['item_name'] !== '' ? $parent['item_name'] : '—') ?></td>
              <td class="num"><?= yf_h(yf_format_decimal($parent['qty'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php endif; ?>
    </section>
    <?php
    return (string)ob_get_clean();
}
