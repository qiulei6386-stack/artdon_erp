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
  <script>document.documentElement.dataset.theme=localStorage.getItem('artdon-ui-theme')||'system';</script>
  <link rel="stylesheet" href="ui/index.css">
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<div class="ui-shell">
  <aside class="ui-sidebar">
    <div class="ui-brand"><span class="ui-brand-mark">AD</span><div class="ui-brand-copy"><b>物料中心</b><small>Material Center V1</small></div></div>
    <button class="ui-btn ui-btn-ghost ui-sidebar-toggle" type="button" data-ui-sidebar-toggle aria-label="收起或展开导航">收起导航</button>
    <nav class="ui-nav">
      <a aria-current="page" href="./"><i class="ui-nav-icon">总</i><span>物料总览</span></a>
      <a href="power_supplies.php"><i class="ui-nav-icon">电</i><span>电源列表</span></a>
      <a href="power_standardization.php"><i class="ui-nav-icon">标</i><span>电源标准化</span></a>
      <a href="formal_power_supplies.php"><i class="ui-nav-icon">库</i><span>正式电源库</span></a>
      <a href="power_bands.php"><i class="ui-nav-icon">档</i><span>功率档管理</span></a>
      <a href="bom_audit.php"><i class="ui-nav-icon">审</i><span>BOM 源审计</span></a>
      <a href="system_status.php"><i class="ui-nav-icon">态</i><span>系统状态</span></a>
      <a href="ui/docs/component-gallery.php"><i class="ui-nav-icon">UI</i><span>组件库</span></a>
    </nav>
    <div class="ui-side-note"><b>安全旁路模式</b><span>当前阶段只读旧物料表，不开放新增、编辑、价格或供应商字段。</span></div>
  </aside>

  <main class="ui-main">
    <header class="ui-topbar">
      <div class="ui-topbar-group"><button class="ui-btn ui-btn-ghost ui-btn-icon ui-mobile-nav" type="button" data-ui-mobile-nav aria-label="打开导航">☰</button><span class="ui-muted ui-breadcrumb-extra">广州 Artdon ERP</span><b class="ui-breadcrumb-extra">/</b><strong>物料中心 V1</strong></div>
      <div class="ui-topbar-group">
        <a class="ui-btn ui-btn-ghost ui-btn-sm" href="api/v1/health.php">健康检查</a>
        <button class="ui-btn ui-btn-ghost ui-btn-sm ui-page-actions" type="button" data-ui-presentation>展示模式</button>
        <div class="ui-dropdown ui-page-actions">
          <button class="ui-btn ui-btn-secondary ui-btn-sm" type="button" aria-expanded="false" aria-controls="theme-menu" data-ui-dropdown-trigger>主题</button>
          <div class="ui-menu" id="theme-menu" role="menu" aria-hidden="true">
            <button type="button" data-ui-theme="light">浅色</button>
            <button type="button" data-ui-theme="dark">深色</button>
            <button type="button" data-ui-theme="system">跟随系统</button>
          </div>
        </div>
        <span class="ui-muted"><?= mc_h($view['user']['real_name'] ?? $view['user']['username'] ?? '未登录') ?></span>
      </div>
    </header>

    <section class="ui-content">
      <div class="ui-page-head">
        <div><span class="ui-eyebrow">MATERIAL MASTER · READ ONLY FOUNDATION</span><h1>统一物料总览</h1><p>先建立稳定、可追溯的物料读取入口；后续再逐步接入分类治理、属性模板、替代料、供应商与成本权限。</p></div>
        <span class="ui-badge ui-badge-success">只读基础版</span>
      </div>

      <?php if ($view['status'] === 'unauthenticated'): ?>
        <section class="ui-card ui-state"><div class="ui-state-inner"><div class="ui-state-icon">!</div><h2>需要统一登录</h2><p>登录后才会读取物料数据。</p><div class="ui-state-actions"><a class="ui-btn" href="../login.php?redirect=<?= rawurlencode('/artdon_erp/material_center_v1/') ?>">前往登录</a></div></div></section>
      <?php elseif ($view['status'] !== 'available'): ?>
        <section class="ui-card ui-state"><div class="ui-state-inner"><div class="ui-state-icon">!</div><h2>物料数据暂时不可用</h2><p>未找到旧物料表或读取失败，本页面没有执行任何写入。</p><div class="ui-state-actions"><a class="ui-btn ui-btn-secondary" href="./">重新加载</a></div></div></section>
      <?php else: ?>
        <section class="stats">
          <article><span>有效物料</span><b><?= (int)$summary['total'] ?></b></article>
          <article><span>物料分类</span><b><?= (int)$summary['categories'] ?></b></article>
          <article><span>今日更新</span><b><?= (int)$summary['updated_today'] ?></b></article>
          <article><span>最近更新</span><b class="date"><?= mc_h($summary['last_updated_at'] ?: '—') ?></b></article>
        </section>

        <form class="filters ui-card" method="get" data-search-form>
          <div class="search-field"><label class="ui-sr-only" for="material-search">搜索物料</label><input class="ui-input" id="material-search" type="search" name="q" value="<?= mc_h($search) ?>" placeholder="搜索名称、品牌、型号、规格或材料牌号" autocomplete="off"><button class="ui-link-button search-clear" type="button" data-search-clear aria-label="清空搜索">×</button></div>
          <label class="ui-sr-only" for="material-category">物料分类</label><select class="ui-select" id="material-category" name="category">
            <option value="">全部分类</option>
            <?php foreach ($view['categories'] as $item): ?>
              <option value="<?= mc_h($item['category']) ?>" <?= $category === $item['category'] ? 'selected' : '' ?>><?= mc_h($item['category']) ?>（<?= (int)$item['total'] ?>）</option>
            <?php endforeach; ?>
          </select>
          <button class="ui-btn" type="submit">筛选</button>
          <a class="ui-btn ui-btn-secondary" href="./">重置</a>
          <button class="ui-btn ui-btn-secondary" type="button" data-ui-table-settings="#material-table">列与密度</button>
          <span class="ui-muted">当前 <?= count($view['rows']) ?> 条</span>
        </form>

        <section class="ui-card ui-table-panel">
          <?php if ($view['rows'] === []): ?>
            <div class="ui-state"><div class="ui-state-inner"><div class="ui-state-icon">0</div><h2>没有符合条件的物料</h2><p>请减少筛选条件或尝试其他关键词。</p><div class="ui-state-actions"><a class="ui-btn ui-btn-secondary" href="./">清除筛选</a></div></div></div>
          <?php else: ?>
            <div class="ui-table-wrap">
              <table class="ui-table" id="material-table" data-ui-table data-page-size="20">
                <thead><tr><th class="ui-select-col"><label class="ui-check ui-check-only"><input type="checkbox" data-ui-select-all aria-label="全选当前表格"><span class="ui-check-box"></span></label></th><th data-sort="number">ID</th><th data-sort>分类</th><th data-sort>物料名称</th><th data-sort>品牌</th><th data-sort>型号</th><th>规格</th><th data-sort>材料牌号</th><th data-sort>单位</th><th data-sort>更新时间</th><th class="ui-action-col">详情</th></tr></thead>
                <tbody>
                <?php foreach ($view['rows'] as $row):
                  $detail = ['永久 ID'=>$row['id'],'分类'=>$row['category'],'物料名称'=>$row['name'],'品牌'=>$row['brand'],'型号'=>$row['model'],'规格'=>$row['spec'],'材料牌号'=>$row['material_grade'],'单位'=>$row['unit'],'更新时间'=>$row['updated_at']];
                ?>
                  <tr tabindex="0" data-detail="<?= mc_h(json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
                    <td class="ui-select-col"><label class="ui-check ui-check-only"><input type="checkbox" data-ui-row-select aria-label="选择 <?= mc_h($row['name']) ?>"><span class="ui-check-box"></span></label></td>
                    <td><?= (int)$row['id'] ?></td>
                    <td><span class="ui-badge"><?= mc_h($row['category'] ?: '未分类') ?></span></td>
                    <td><b><?= mc_h($row['name']) ?></b></td>
                    <td><?= mc_h($row['brand']) ?></td>
                    <td><?= mc_h($row['model']) ?></td>
                    <td class="ui-cell-wrap"><?= mc_h($row['spec']) ?></td>
                    <td><?= mc_h($row['material_grade']) ?></td>
                    <td><?= mc_h($row['unit']) ?></td>
                    <td class="nowrap"><?= mc_h($row['updated_at']) ?></td>
                    <td class="ui-action-col"><button type="button" class="ui-link-button">查看</button></td>
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
<aside class="ui-drawer" id="material-detail" role="dialog" aria-modal="true" aria-labelledby="material-detail-title" aria-hidden="true" tabindex="-1"><div class="ui-drawer-header"><div><span class="ui-eyebrow">MATERIAL DETAIL</span><h2 id="material-detail-title">物料详情</h2></div><button class="ui-btn ui-btn-secondary ui-btn-icon" type="button" data-ui-close aria-label="关闭">×</button></div><dl class="material-detail-list" data-detail-content></dl><div class="ui-drawer-footer">只读数据 · 不显示价格和供应商</div></aside>
<div class="ui-mask" data-ui-mask></div>
<div class="ui-toast-region" data-ui-toast-region role="status" aria-live="polite"></div>
<script src="ui/js/interaction-manager.js" defer></script>
<script src="ui/js/confirm-modal.js" defer></script>
<script src="ui/js/dropdown.js" defer></script>
<script src="ui/js/modal.js" defer></script>
<script src="ui/js/drawer.js" defer></script>
<script src="ui/js/toast.js" defer></script>
<script src="ui/js/table.js" defer></script>
<script src="ui/js/app-shell.js" defer></script>
<script src="assets/js/app.js" defer></script>
</body>
</html>
