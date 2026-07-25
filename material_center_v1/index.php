<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Artdon\MaterialCenter\Services\MaterialDashboardService;

$search = trim((string)($_GET['q'] ?? ''));
$category = trim((string)($_GET['category'] ?? ''));
$view = (new MaterialDashboardService())->view($search, $category);
$summary = $view['summary'];

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store, max-age=0');
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Artdon 物料中心 V1</title>
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<div class="shell">
  <aside class="sidebar">
    <div class="brand"><span>AD</span><div><b>物料中心</b><small>Material Center V1</small></div></div>
    <nav>
      <a class="active" href="./"><i>总</i><span>物料总览</span></a>
      <a href="../bom.php"><i>B</i><span>返回 BOM</span></a>
      <a href="../commercial_center_v1/?view=materials"><i>商</i><span>商务中心物料</span></a>
    </nav>
    <div class="side-note"><b>安全旁路模式</b><span>当前阶段只读旧物料表，不开放新增、编辑、价格或供应商字段。</span></div>
  </aside>

  <main>
    <header class="topbar">
      <div><span>广州 Artdon ERP</span><b>/</b><strong>物料中心 V1</strong></div>
      <div class="top-actions"><a href="api/v1/health.php">健康检查</a><span><?= mc_h($view['user']['real_name'] ?? $view['user']['username'] ?? '未登录') ?></span></div>
    </header>

    <section class="content">
      <div class="page-head">
        <div><span class="eyebrow">MATERIAL MASTER · READ ONLY FOUNDATION</span><h1>统一物料总览</h1><p>先建立稳定、可追溯的物料读取入口；后续再逐步接入分类治理、属性模板、替代料、供应商与成本权限。</p></div>
        <span class="mode-tag">只读基础版</span>
      </div>

      <?php if ($view['status'] === 'unauthenticated'): ?>
        <section class="notice"><div><b>需要统一登录</b><p>登录后才会读取物料数据。</p></div><a href="../login.php?redirect=<?= rawurlencode('/artdon_erp/material_center_v1/') ?>">前往登录</a></section>
      <?php elseif ($view['status'] !== 'available'): ?>
        <section class="notice danger"><div><b>物料数据暂时不可用</b><p>未找到旧物料表或读取失败，本页面没有执行任何写入。</p></div></section>
      <?php else: ?>
        <section class="stats">
          <article><span>有效物料</span><b><?= (int)$summary['total'] ?></b></article>
          <article><span>物料分类</span><b><?= (int)$summary['categories'] ?></b></article>
          <article><span>今日更新</span><b><?= (int)$summary['updated_today'] ?></b></article>
          <article><span>最近更新</span><b class="date"><?= mc_h($summary['last_updated_at'] ?: '—') ?></b></article>
        </section>

        <form class="filters" method="get">
          <input type="search" name="q" value="<?= mc_h($search) ?>" placeholder="搜索名称、品牌、型号、规格或材料牌号">
          <select name="category">
            <option value="">全部分类</option>
            <?php foreach ($view['categories'] as $item): ?>
              <option value="<?= mc_h($item['category']) ?>" <?= $category === $item['category'] ? 'selected' : '' ?>><?= mc_h($item['category']) ?>（<?= (int)$item['total'] ?>）</option>
            <?php endforeach; ?>
          </select>
          <button type="submit">筛选</button>
          <a href="./">清除</a>
          <span>当前 <?= count($view['rows']) ?> 条</span>
        </form>

        <section class="panel">
          <?php if ($view['rows'] === []): ?>
            <div class="empty">没有符合条件的物料。</div>
          <?php else: ?>
            <div class="table-wrap">
              <table>
                <thead><tr><th>ID</th><th>分类</th><th>物料名称</th><th>品牌</th><th>型号</th><th>规格</th><th>材料牌号</th><th>单位</th><th>更新时间</th><th>详情</th></tr></thead>
                <tbody>
                <?php foreach ($view['rows'] as $row):
                  $detail = ['永久 ID'=>$row['id'],'分类'=>$row['category'],'物料名称'=>$row['name'],'品牌'=>$row['brand'],'型号'=>$row['model'],'规格'=>$row['spec'],'材料牌号'=>$row['material_grade'],'单位'=>$row['unit'],'更新时间'=>$row['updated_at']];
                ?>
                  <tr>
                    <td><?= (int)$row['id'] ?></td>
                    <td><span class="category"><?= mc_h($row['category'] ?: '未分类') ?></span></td>
                    <td><b><?= mc_h($row['name']) ?></b></td>
                    <td><?= mc_h($row['brand']) ?></td>
                    <td><?= mc_h($row['model']) ?></td>
                    <td class="spec"><?= mc_h($row['spec']) ?></td>
                    <td><?= mc_h($row['material_grade']) ?></td>
                    <td><?= mc_h($row['unit']) ?></td>
                    <td class="nowrap"><?= mc_h($row['updated_at']) ?></td>
                    <td><button type="button" class="text-button" data-detail="<?= mc_h(json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">查看</button></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>
      <?php endif; ?>
    </section>
  </main>
</div>
<aside class="drawer" data-drawer aria-hidden="true"><div class="drawer-head"><div><span class="eyebrow">MATERIAL DETAIL</span><h2>物料详情</h2></div><button type="button" data-close>×</button></div><dl data-detail-content></dl><div class="drawer-foot">只读数据 · 不显示价格和供应商</div></aside>
<div class="mask" data-close></div>
<script src="assets/js/app.js" defer></script>
</body>
</html>
