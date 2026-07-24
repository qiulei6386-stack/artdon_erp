<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Artdon\CommercialCenter\Controllers\DashboardController;

$view = (new DashboardController())->status();
$app = require __DIR__ . '/config/app.php';
$ops = $view['operations'];
$allowedViews = ['dashboard', 'products', 'materials', 'inventory', 'quotation', 'custom_project', 'publishing', 'orders', 'packaging', 'documents', 'commission', 'integrations'];
$activeView = in_array((string)($_GET['view'] ?? 'dashboard'), $allowedViews, true) ? (string)($_GET['view'] ?? 'dashboard') : 'dashboard';
$moduleLabels = [
    'dashboard' => '运营工作台', 'products' => '产品与配置', 'materials' => '物料与配件',
    'inventory' => '库存 SKU', 'quotation' => '标准报价', 'custom_project' => '定制项目',
    'publishing' => '新加坡发布', 'orders' => '订单中心', 'packaging' => '包装中心',
    'documents' => '单证中心', 'commission' => '价格与佣金', 'integrations' => '系统集成',
];

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
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= cc_h($moduleLabels[$activeView]) ?> · <?= cc_h($app['name']) ?></title>
  <link rel="stylesheet" href="assets/css/app.css">
  <link rel="stylesheet" href="assets/css/catalog.css">
</head>
<body>
<div class="app-shell" data-shell>
  <aside class="sidebar" data-sidebar>
    <div class="brand"><span>AD</span><div><strong>商务运营中心</strong><small>Commercial Center V1</small></div></div>
    <nav aria-label="商务中心模块">
      <?php foreach ($moduleLabels as $key => $label): ?>
        <a href="?view=<?= cc_h($key) ?>" class="<?= $activeView === $key ? 'active' : '' ?>">
          <i><?= cc_h(mb_substr($label, 0, 1)) ?></i><span><?= cc_h($label) ?></span>
          <?php if ($key !== 'dashboard'): ?><em>规划</em><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-foot"><span>只读旁路模块</span><small>旧系统零修改</small></div>
  </aside>

  <section class="workspace">
    <header class="topbar">
      <button type="button" class="icon-button" data-sidebar-toggle aria-label="折叠菜单">☰</button>
      <div class="crumb"><span>Artdon 商务运营中心 V1</span><b>/</b><strong><?= cc_h($moduleLabels[$activeView]) ?></strong></div>
      <div class="top-actions">
        <a href="api/v1/health.php">健康检查</a>
        <span class="system-state <?= $view['isolation']['ok'] ? 'ok' : 'warn' ?>"><?= $view['isolation']['ok'] ? '隔离正常' : '需检查' ?></span>
        <span class="user"><?= cc_h($view['auth']['authenticated'] && $view['auth']['user'] ? $view['auth']['user']['display_name'] : '未登录') ?></span>
      </div>
    </header>

    <main class="content">
      <?php if (in_array($activeView, ['products', 'materials'], true)): $catalog = $view[$activeView]; ?>
        <section class="page-head">
          <div><span class="eyebrow">M2 READ-ONLY FOUNDATION</span><h1><?= cc_h($moduleLabels[$activeView]) ?></h1><p>数据来自广州旧系统只读适配器；本阶段不提供新增、编辑、导入或停用操作。</p></div>
          <div class="page-meta"><span>权限：<?= cc_h($catalog['permission']) ?></span><b><?= cc_h($catalog['status']) ?></b></div>
        </section>
        <?php if ($catalog['status'] !== 'available'): ?>
          <section class="login-notice"><div><strong><?= $catalog['status'] === 'unauthenticated' ? '需要统一登录' : '当前账号无只读权限' ?></strong><p>未读取或展示产品、物料数据。</p></div><?php if ($catalog['status'] === 'unauthenticated'): ?><a href="../login.php?redirect=<?= rawurlencode('/artdon_erp/commercial_center_v1/?view=' . $activeView) ?>">前往统一登录</a><?php endif; ?></section>
        <?php else: ?>
          <form class="catalog-filters" method="get">
            <input type="hidden" name="view" value="<?= cc_h($activeView) ?>">
            <input type="search" name="q" value="<?= cc_h($view['filters']['q']) ?>" placeholder="<?= $activeView === 'products' ? '搜索型号、产品名或系列' : '搜索物料、型号、品牌或规格' ?>">
            <select name="category"><option value="">全部分类</option><?php foreach ($catalog['categories'] as $category): ?><option value="<?= cc_h($category['category']) ?>" <?= $view['filters']['category'] === $category['category'] ? 'selected' : '' ?>><?= cc_h($category['category']) ?>（<?= (int)$category['total'] ?>）</option><?php endforeach; ?></select>
            <button type="submit">筛选</button><a href="?view=<?= cc_h($activeView) ?>">清除</a>
            <span>当前返回 <?= count($catalog['rows']) ?> 条</span>
          </form>
          <section class="panel catalog-panel">
            <?php if ($catalog['rows'] === []): ?><div class="empty">没有符合条件的数据。</div>
            <?php elseif ($activeView === 'products'): ?>
              <div class="catalog-grid">
                <?php foreach ($catalog['rows'] as $row): $detail=['永久ID'=>$row['id'],'型号'=>$row['model_no'],'产品'=>$row['product_name'],'分类'=>$row['category'],'系列'=>$row['series_name'],'灯具类型'=>$row['lamp_type'],'状态'=>$row['status'],'开孔'=>$row['dim_opening'],'外径'=>$row['dim_outer_d'],'长度'=>$row['dim_length'],'宽度'=>$row['dim_width'],'高度'=>$row['dim_height'],'允许BOM'=>$row['bom_allowed']?'是':'否','更新时间'=>$row['updated_at']]; ?>
                  <article class="product-card"><div class="product-thumb"><?php $imageUrl=cc_legacy_asset_url($row['image_path']); if ($imageUrl): ?><img src="<?= cc_h($imageUrl) ?>" alt="" loading="lazy"><?php else: ?><span>NO IMAGE</span><?php endif; ?></div><div><small><?= cc_h($row['category']) ?></small><strong><?= cc_h($row['model_no']) ?></strong><p><?= cc_h($row['product_name']) ?></p><footer><span><?= cc_h($row['series_name']) ?></span><button type="button" data-catalog-detail="<?= cc_h(json_encode($detail,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?>">查看详情</button></footer></div></article>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div class="table-wrap"><table><thead><tr><th>永久 ID</th><th>分类</th><th>物料</th><th>品牌/型号</th><th>规格</th><th>单位</th><th>更新时间</th><th>详情</th></tr></thead><tbody>
                <?php foreach ($catalog['rows'] as $row): $detail=['永久ID'=>$row['id'],'分类'=>$row['category'],'名称'=>$row['name'],'品牌'=>$row['brand'],'型号'=>$row['model'],'规格'=>$row['spec'],'单位'=>$row['unit'],'材料牌号'=>$row['material_grade'],'供应商'=>'受限字段，V1不展示','价格'=>'受限字段，V1不展示','更新时间'=>$row['updated_at']]; ?>
                  <tr><td><?= (int)$row['id'] ?></td><td><?= cc_h($row['category']) ?></td><td><b><?= cc_h($row['name']) ?></b></td><td><?= cc_h(trim($row['brand'].' '.$row['model'])) ?></td><td><?= cc_h($row['spec']) ?></td><td><?= cc_h($row['unit']) ?></td><td><?= cc_h($row['updated_at']) ?></td><td><button class="text-button" type="button" data-catalog-detail="<?= cc_h(json_encode($detail,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?>">查看</button></td></tr>
                <?php endforeach; ?>
              </tbody></table></div>
            <?php endif; ?>
          </section>
          <aside class="detail-drawer" data-detail-drawer aria-hidden="true"><div><span class="eyebrow">READ-ONLY DETAIL</span><h2><?= cc_h($moduleLabels[$activeView]) ?>详情</h2></div><button type="button" data-detail-close>×</button><dl data-detail-content></dl></aside><div class="drawer-mask" data-detail-close></div>
        <?php endif; ?>
      <?php elseif ($activeView !== 'dashboard'): ?>
        <section class="placeholder-page">
          <span class="eyebrow">PLANNED MODULE</span>
          <h1><?= cc_h($moduleLabels[$activeView]) ?></h1>
          <p>此入口已纳入商务中心外壳，但本阶段尚未开发正式业务逻辑，也不会显示伪造数据。</p>
          <a href="?view=dashboard">返回运营工作台</a>
        </section>
      <?php else: ?>
        <section class="page-head">
          <div><span class="eyebrow">OPERATIONS HOME</span><h1>运营工作台</h1><p>聚合旧系统只读状态，帮助识别下一步；所有正式操作仍返回原系统完成。</p></div>
          <div class="page-meta"><span><?= cc_h(date('Y-m-d H:i')) ?></span><b><?= cc_h($view['git']['branch'] . '@' . $view['git']['head']) ?></b></div>
        </section>

        <?php if (!$view['auth']['authenticated']): ?>
          <section class="login-notice">
            <div><strong>需要现有 Artdon 统一登录</strong><p>当前没有识别到旧系统登录用户，因此不读取客户、报价、订单或任务数据。</p></div>
            <a href="../login.php?redirect=<?= rawurlencode('/artdon_erp/commercial_center_v1/') ?>">前往统一登录</a>
          </section>
        <?php endif; ?>

        <section class="core-actions" aria-label="核心业务入口">
          <a href="?view=publishing"><span>01</span><div><strong>发布库存产品到新加坡</strong><small>规划入口 · 尚未开发发布功能</small></div><b>→</b></a>
          <a href="?view=quotation"><span>02</span><div><strong>创建标准产品报价</strong><small>规划入口 · 旧报价继续正常运行</small></div><b>→</b></a>
          <a href="?view=custom_project"><span>03</span><div><strong>创建定制产品报价</strong><small>规划入口 · 尚未开发工程流程</small></div><b>→</b></a>
        </section>

        <section class="compact-status" aria-label="当前工作状态">
          <div><span>我的工作队列</span><strong><?= (int)$ops['counts']['work_queue'] ?></strong></div>
          <div><span>商务交付队列</span><strong><?= (int)$ops['counts']['delivery_queue'] ?></strong></div>
          <div><span>近期订单</span><strong><?= (int)$ops['counts']['orders'] ?></strong></div>
          <div class="<?= $ops['counts']['exceptions'] > 0 ? 'attention' : '' ?>"><span>异常提醒</span><strong><?= (int)$ops['counts']['exceptions'] ?></strong></div>
          <div><span>旧适配器</span><strong><?= count($view['adapters']) ?>/<?= count($view['adapters']) ?></strong></div>
        </section>

        <div class="dashboard-grid">
          <section class="panel wide">
            <div class="panel-head"><div><span class="eyebrow">WORK QUEUE</span><h2>我的工作队列</h2></div><a href="../dispatch_next.php">打开派工待办</a></div>
            <?php if ($ops['work_queue'] === []): ?>
              <div class="empty"><?= $ops['status'] === 'unauthenticated' ? '登录后按本人范围读取任务。' : '当前范围没有待处理任务。' ?></div>
            <?php else: ?>
              <div class="table-wrap"><table><thead><tr><th>任务</th><th>项目</th><th>负责人</th><th>优先级</th><th>进度</th><th>截止</th><th>下一动作</th></tr></thead><tbody>
              <?php foreach ($ops['work_queue'] as $row): ?>
                <tr><td><b><?= cc_h($row['task_no']) ?></b><small><?= cc_h($row['title']) ?></small></td><td><?= cc_h($row['project'] ?: $row['linked_title']) ?></td><td><?= cc_h($row['assignee_name'] ?: '未指定') ?></td><td><span class="tag"><?= cc_h($row['priority']) ?></span></td><td><?= (int)$row['progress'] ?>%</td><td><?= cc_h($row['due_at'] ?: '未设置') ?></td><td><a href="../dispatch_next.php">处理任务</a></td></tr>
              <?php endforeach; ?>
              </tbody></table></div>
            <?php endif; ?>
          </section>

          <section class="panel">
            <div class="panel-head"><div><span class="eyebrow">EXCEPTIONS</span><h2>异常提醒</h2></div></div>
            <div class="exception-list">
              <?php if ($ops['exceptions'] === []): ?><div class="empty">登录后显示本人范围异常。</div><?php endif; ?>
              <?php foreach ($ops['exceptions'] as $item): ?>
                <a href="<?= cc_h($item['target']) ?>" class="<?= cc_h($item['severity']) ?>"><span><?= cc_h($item['label']) ?></span><strong><?= (int)$item['count'] ?></strong></a>
              <?php endforeach; ?>
            </div>
          </section>

          <section class="panel wide">
            <div class="panel-head"><div><span class="eyebrow">COMMERCIAL DELIVERY</span><h2>商务交付队列</h2></div><a href="../quotation.php">打开旧报价</a></div>
            <?php if ($ops['delivery_queue'] === []): ?><div class="empty">当前范围没有可显示的交付事项。</div><?php else: ?>
              <div class="table-wrap"><table><thead><tr><th>报价编号</th><th>客户</th><th>金额</th><th>审批</th><th>下一动作</th><th>更新时间</th></tr></thead><tbody>
              <?php foreach ($ops['delivery_queue'] as $row): ?>
                <tr><td><b><?= cc_h($row['quote_no']) ?></b></td><td><?= cc_h($row['customer_name']) ?></td><td><?= cc_h($row['currency']) ?> <?= cc_h(number_format((float)$row['amount'], 2)) ?></td><td><span class="tag"><?= cc_h($row['approval_status']) ?></span></td><td><?= cc_h($row['next_action']) ?></td><td><?= cc_h($row['updated_at']) ?></td></tr>
              <?php endforeach; ?>
              </tbody></table></div>
            <?php endif; ?>
          </section>

          <section class="panel">
            <div class="panel-head"><div><span class="eyebrow">RECENT ACTIVITY</span><h2>近期动态</h2></div></div>
            <div class="timeline">
              <?php if ($ops['activity'] === []): ?><div class="empty">当前范围没有近期动态。</div><?php endif; ?>
              <?php foreach ($ops['activity'] as $item): ?>
                <div><i></i><p><strong><?= cc_h($item['event'] ?: $item['action']) ?></strong><span><?= cc_h($item['quote_no'] . ' ' . $item['customer_name']) ?></span><small><?= cc_h($item['created_at']) ?></small></p></div>
              <?php endforeach; ?>
            </div>
          </section>

          <section class="panel full">
            <div class="panel-head"><div><span class="eyebrow">ORDER DELIVERY</span><h2>订单交付进度</h2></div><a href="../quotation.php">进入旧订单</a></div>
            <?php if ($ops['orders'] === []): ?><div class="empty">当前范围没有订单记录。</div><?php else: ?>
              <div class="order-grid">
              <?php foreach ($ops['orders'] as $order): $qty=(float)$order['qty']; $shipped=(float)$order['shipped_qty']; $percent=$qty>0?min(100,(int)round($shipped/$qty*100)):0; ?>
                <article><div><b><?= cc_h($order['order_no'] ?: '未编号') ?></b><span><?= cc_h($order['customer_name']) ?></span></div><small><?= cc_h($order['status'] ?: '处理中') ?> · <?= cc_h($order['shipment_status'] ?: '未出货') ?></small><div class="progress"><i style="width:<?= $percent ?>%"></i></div><footer><span>出货 <?= cc_h((string)$shipped) ?>/<?= cc_h((string)$qty) ?></span><strong><?= cc_h($order['currency']) ?> <?= cc_h(number_format((float)$order['amount'], 2)) ?></strong></footer></article>
              <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </section>
        </div>

        <details class="technical">
          <summary>安全与适配器状态</summary>
          <div class="adapter-list"><?php foreach ($view['adapters'] as $adapter): ?><div><span><?= cc_h($adapter['name']) ?></span><b class="<?= cc_h($adapter['status']) ?>"><?= cc_h($adapter['status']) ?></b></div><?php endforeach; ?></div>
        </details>
      <?php endif; ?>
    </main>
  </section>
</div>
<script src="assets/js/app.js" defer></script>
</body>
</html>
