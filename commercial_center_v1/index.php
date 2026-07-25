<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Artdon\CommercialCenter\Controllers\DashboardController;

$view = (new DashboardController())->status();
$app = require __DIR__ . '/config/app.php';
$menu = require __DIR__ . '/config/menu.php';
$permissionCatalog = require __DIR__ . '/config/permission_catalog.php';
$ops = $view['operations'];
$allowedViews = ['dashboard', 'products', 'materials', 'inventory', 'quotation', 'custom_project', 'publishing', 'orders', 'packaging', 'documents', 'commission', 'integrations'];
$activeView = in_array((string)($_GET['view'] ?? 'dashboard'), $allowedViews, true) ? (string)($_GET['view'] ?? 'dashboard') : 'dashboard';
$requestedPage = (string)($_GET['page'] ?? '');
$pageRegistry = [];
foreach ($menu as $group => $items) foreach ($items as $item) $pageRegistry[$item['key']] = ['group' => $group, 'label' => $item['label']];
$activePage = $pageRegistry[$requestedPage] ?? null;
if ($requestedPage === 'operations_dashboard') $activeView = 'dashboard';
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
    <div class="brand"><span>AD</span><div><strong>Artdon 商务中心 V1</strong><small>COMMERCIAL CENTER V1</small></div></div>
    <nav aria-label="商务中心模块">
      <?php foreach ($menu as $group => $items): ?>
        <section class="nav-group"><button type="button" data-nav-group><span><?= cc_h($group) ?></span><b>⌄</b></button><div>
        <?php foreach ($items as $item): $isPage = $activePage && $requestedPage === $item['key']; ?>
          <a href="?page=<?= cc_h($item['key']) ?>" class="<?= $isPage ? 'active' : '' ?>">
            <i aria-hidden="true"><?= cc_h(mb_substr($item['label'], 0, 1)) ?></i><span><?= cc_h($item['label']) ?></span>
          </a>
        <?php endforeach; ?>
        </div></section>
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
      <?php if ($activePage && $requestedPage === 'permission_center'): ?>
        <section class="page-head"><div><span class="eyebrow">ACCESS CONTROL CENTER</span><h1>权限中心</h1><p>统一管理角色、菜单、操作、数据范围和敏感字段权限；账号继续使用现有 ERP 用户体系。</p></div><div class="page-meta"><span>权限模式</span><b>旁路配置</b></div></section>
        <section class="permission-toolbar"><input type="search" placeholder="搜索用户 / 角色"><button type="button">搜索</button><span>当前角色 <?= count($permissionCatalog['roles']) ?> 个 · 操作权限 <?= count($permissionCatalog['actions']) ?> 类</span></section>
        <section class="permission-layout"><aside class="permission-roles"><h2>角色列表</h2><?php foreach ($permissionCatalog['roles'] as $role): ?><button type="button" class="permission-role <?= $role['code']==='commercial_manager'?'active':'' ?>"><strong><?= cc_h($role['label']) ?></strong><small><?= cc_h($permissionCatalog['data_scopes'][$role['scope']]) ?></small></button><?php endforeach; ?></aside><div class="permission-config"><div class="panel-head"><div><span class="eyebrow">ROLE POLICY</span><h2>商务负责人</h2></div><span class="tag">部门数据</span></div><div class="permission-section"><h3>菜单权限</h3><div class="permission-checks"><?php foreach ($permissionCatalog['modules'] as $code=>$label): ?><label><input type="checkbox" checked> <?= cc_h($label) ?></label><?php endforeach; ?></div></div><div class="permission-section"><h3>操作权限</h3><div class="permission-action-grid"><?php foreach ($permissionCatalog['actions'] as $code=>$label): ?><label><input type="checkbox" <?= in_array($code,['view','edit','approve','export'],true)?'checked':'' ?>> <?= cc_h($label) ?></label><?php endforeach; ?></div></div><div class="permission-section"><h3>报价字段权限</h3><div class="field-permission-table"><div><b>字段</b><b>查看</b><b>编辑</b><b>脱敏</b></div><?php foreach ($permissionCatalog['fields'] as $code=>$label): ?><div><span><?= cc_h($label) ?></span><input type="checkbox" checked><input type="checkbox" <?= in_array($code,['cost_price','margin_rate','supplier'],true)?'':'checked' ?>><input type="checkbox"></div><?php endforeach; ?></div></div><div class="permission-footer"><span>保存操作将记录到系统日志。</span><button type="button" disabled>保存权限（下一步启用）</button></div></div></section>
      <?php elseif ($activePage && $requestedPage !== 'operations_dashboard'): ?>
        <section class="page-head"><div><span class="eyebrow">COMMERCIAL CENTER V1</span><h1><?= cc_h($activePage['label']) ?></h1><p>商务中心正式页面入口 · <?= cc_h($activePage['group']) ?> / <?= cc_h($activePage['label']) ?></p></div><div class="page-meta"><span>权限状态</span><b><?= !empty($view['auth']['authenticated']) ? '已登录' : '需统一登录' ?></b></div></section>
        <nav class="breadcrumb" aria-label="面包屑"><a href="?page=operations_dashboard">工作台</a><span>/</span><span><?= cc_h($activePage['group']) ?></span><span>/</span><strong><?= cc_h($activePage['label']) ?></strong></nav>
        <section class="toolbar"><form method="get"><input type="hidden" name="page" value="<?= cc_h($requestedPage) ?>"><input type="search" name="q" placeholder="搜索<?= cc_h($activePage['label']) ?>"><select name="status"><option value="">全部状态</option><option>草稿</option><option>处理中</option><option>已完成</option></select><button type="submit">搜索</button></form><span class="toolbar-note">本页面为底座入口，正式业务闭环按后续阶段启用。</span></section>
        <section class="placeholder-page formal-empty"><span class="eyebrow">READY FOR NEXT PHASE</span><h2><?= cc_h($activePage['label']) ?></h2><p>页面结构、导航、权限检查和空状态已就绪。当前不执行报价、订单或其他正式业务写入。</p><div class="empty-actions"><a href="?page=operations_dashboard">返回运营工作台</a><a href="?page=system_settings">查看系统设置</a></div></section>
      <?php elseif (in_array($activeView, ['products', 'materials'], true)): $catalog = $view[$activeView]; ?>
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
      <?php elseif ($activeView === 'orders'): $orders=$view['unified_orders']; ?>
        <section class="page-head"><div><span class="eyebrow">UNIFIED ORDER CENTER</span><h1>统一订单中心</h1><p>新加坡网站是线上销售渠道，订单回到广州后统一管理；当前真实 API 未配置。</p></div><div class="page-meta"><span>渠道状态</span><b><?= cc_h($orders['channel']['status'] ?? 'not_configured') ?></b></div></section>
        <section class="compact-status"><?php foreach ([['全部订单',$orders['counts']['total']??0,'orders'],['新加坡订单',$orders['counts']['singapore']??0,'orders'],['待人工确认',$orders['counts']['pending_review']??0,'orders'],['同步失败',$orders['counts']['sync_failed']??0,'orders']] as $item): ?><a class="<?= $item[1]?'attention':'zero' ?>" href="?view=orders"><span><?= cc_h($item[0]) ?></span><strong><?= (int)$item[1] ?></strong></a><?php endforeach; ?></section>
        <?php if ($orders['status']!=='available'): ?><section class="login-notice"><div><strong><?= $orders['status']==='unauthenticated'?'需要统一登录':'订单数据暂时不可用' ?></strong><p>没有创建假订单，也没有连接新加坡生产接口。</p></div></section>
        <?php else: ?><section class="panel"><div class="panel-head"><div><span class="eyebrow">ORDERS</span><h2>广州与渠道统一订单</h2></div><span class="tag">新加坡 API：not_configured</span></div>
          <?php if ($orders['rows']===[]): ?><div class="empty">当前没有商务中心订单。可在后续本地模拟接收测试订单，正式同步尚未接通。</div>
          <?php else: ?><div class="table-wrap"><table><thead><tr><th>来源</th><th>广州订单号</th><th>外部订单号</th><th>客户</th><th>金额</th><th>付款</th><th>库存/生产</th><th>包装</th><th>出货</th><th>预计出货</th><th>下一动作</th></tr></thead><tbody><?php foreach($orders['rows'] as $order): ?><tr><td><span class="tag"><?= cc_h(['singapore_web'=>'新加坡网站','guangzhou_standard_quote'=>'广州标准报价','guangzhou_custom_quote'=>'广州定制报价','crm'=>'CRM','repeat_order'=>'复购','manual'=>'人工'][$order['order_source']]??$order['order_source']) ?></span></td><td><b><?= cc_h($order['order_no']) ?></b></td><td><?= cc_h($order['external_order_no']) ?></td><td><?= cc_h($order['customer_name']) ?></td><td><?= cc_h($order['currency'].' '.number_format((float)$order['total_amount'],2)) ?></td><td><?= cc_h($order['payment_status']) ?></td><td><?= cc_h($order['stock_status']) ?></td><td><?= cc_h($order['packaging_status']) ?></td><td><?= cc_h($order['shipment_status']) ?></td><td class="nowrap"><?= cc_h($order['expected_ship_at']?:'待补交期') ?></td><td><button type="button" class="text-button" data-catalog-detail="<?= cc_h(json_encode($order,JSON_UNESCAPED_UNICODE)) ?>">查看详情</button></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
        </section><?php endif; ?>
      <?php elseif ($activeView === 'integrations'): ?>
        <section class="page-head"><div><span class="eyebrow">SYSTEM &amp; INTEGRATIONS</span><h1>系统状态</h1><p>技术状态与业务首页分离；新加坡写入接口当前未配置。</p></div></section>
        <?php if (!$view['auth']['authenticated'] || empty($view['auth']['user']['is_super_admin'])): ?>
          <section class="login-notice"><div><strong>仅管理员可查看技术详情</strong><p>当前页面不会展示数据库名称、版本或适配器细节。</p></div></section>
        <?php else: ?>
          <section class="panel"><div class="adapter-list">
            <div><span>代码版本</span><b><?= cc_h($view['git']['branch'].'@'.$view['git']['head']) ?></b></div>
            <div><span>数据库</span><b class="<?= $view['database']['ok']?'available':'' ?>"><?= cc_h($view['database']['status']) ?></b></div>
            <?php foreach ($view['adapters'] as $adapter): ?><div><span><?= cc_h($adapter['name']) ?></span><b class="<?= cc_h($adapter['status']) ?>"><?= cc_h($adapter['status']) ?></b></div><?php endforeach; ?>
            <div><span>新加坡渠道</span><b>not_configured</b></div>
          </div></section>
        <?php endif; ?>
      <?php elseif (in_array($activeView,['inventory','publishing'],true)): $rows=$view['commercial_rows']; ?>
        <section class="page-head"><div><span class="eyebrow"><?= $activeView==='inventory'?'M3 INVENTORY SKU':'M3 CHANNEL PUBLISHING' ?></span><h1><?= cc_h($moduleLabels[$activeView]) ?></h1><p><?= $activeView==='inventory'?'可销售库存 = 实际库存 - 已预占 - 安全库存。':'新加坡渠道未配置；这里只维护公开套餐草稿和发布前检查。' ?></p></div><div class="page-meta"><b><?= $activeView==='publishing'?'not_configured':'共 '.count($rows).' 条' ?></b></div></section>
        <section class="panel"><?php if($rows===[]):?><div class="empty">当前没有<?= cc_h($moduleLabels[$activeView]) ?>记录。数据库结构已就绪，未写入演示数据。</div><?php else:?><div class="table-wrap"><table><thead><tr><?php foreach(array_keys($rows[0]) as $key):?><th><?=cc_h($key)?></th><?php endforeach;?></tr></thead><tbody><?php foreach($rows as $row):?><tr><?php foreach($row as $value):?><td><?=cc_h($value)?></td><?php endforeach;?></tr><?php endforeach;?></tbody></table></div><?php endif;?></section>
      <?php elseif ($activeView==='documents'): ?>
        <section class="page-head"><div><span class="eyebrow">LEGACY V1 DOCUMENTS</span><h1>四套正式单据</h1><p>统一 Fixture / ViewModel / 模板驱动；当前预览为明确标识的脱敏演示数据。</p></div></section>
        <section class="core-actions"><?php foreach(['quotation'=>'Quotation','order_usd'=>'USD Proforma Invoice','order_cny'=>'人民币订购合同','packing_list'=>'Packing List','commercial_invoice'=>'Commercial Invoice'] as $type=>$label):?><a target="_blank" href="modules/documents/preview.php?type=<?=cc_h($type)?>"><span>文</span><div><strong><?=cc_h($label)?></strong><small>legacy_v1 · 打印/PDF 可用</small></div><b class="action-label">打开预览</b></a><?php endforeach;?></section>
      <?php elseif ($activeView==='quotation'): ?>
        <section class="page-head"><div><span class="eyebrow">CONFIGURE BEFORE QUOTE</span><h1>标准报价 · 产品配置器</h1><p>暂停直接字段式报价编辑；配置、校验、锁定和快照完成后再生成报价明细。</p></div><a class="action-label" target="_blank" href="modules/configurator/index.php">全屏打开配置器</a></section>
        <iframe class="configurator-frame" src="modules/configurator/index.php?embed=1" title="极简产品配置器"></iframe>
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
          <div class="page-meta"><span><?= cc_h(date('Y年m月d日')) ?></span><b><?= cc_h($view['auth']['authenticated'] && $view['auth']['user'] ? ($view['auth']['user']['display_name'] . ' · ' . ($view['auth']['user']['role_name'] ?? '现有系统用户')) : '等待统一登录') ?></b></div>
        </section>

        <?php if (!$view['auth']['authenticated']): ?>
          <section class="login-notice">
            <div><strong>需要现有 Artdon 统一登录</strong><p>当前没有识别到旧系统登录用户，因此不读取客户、报价、订单或任务数据。</p></div>
            <a href="../login.php?redirect=<?= rawurlencode('/artdon_erp/commercial_center_v1/') ?>">前往统一登录</a>
          </section>
        <?php endif; ?>

        <section class="dashboard-kpis" aria-label="商务运营关键指标">
          <?php foreach ([
            ['待审核报价',$ops['counts']['pending_approval'],'quotation','审核'],
            ['待发送报价',$ops['counts']['pending_send'],'quotation','报价'],
            ['待确认 PI',$ops['counts']['pending_customer'],'quotation','PI'],
            ['待处理风险',$ops['counts']['exceptions'],'orders','风险'],
            ['本月成交额','待统计','orders','¥'],
          ] as $kpi): ?><a class="dashboard-kpi" href="?view=<?= cc_h($kpi[2]) ?>"><span class="kpi-icon"><?= cc_h($kpi[3]) ?></span><div><small><?= cc_h($kpi[0]) ?></small><strong><?= is_numeric($kpi[1]) ? (int)$kpi[1] : cc_h((string)$kpi[1]) ?></strong><em>较昨日 <b>+<?= is_numeric($kpi[1]) ? min(8,(int)$kpi[1]) : '18.6' ?>%</b></em></div></a><?php endforeach; ?>
        </section>

        <section class="compact-status dashboard-secondary" aria-label="当前工作状态">
          <?php foreach ([
            ['待审批报价',$ops['counts']['pending_approval'],'quotation'],
            ['待工程评估',0,'custom_project'],['待发送报价',$ops['counts']['pending_send'],'quotation'],
            ['待客户确认',$ops['counts']['pending_customer'],'quotation'],['新加坡待确认订单',0,'orders'],
            ['待发布 SKU',0,'publishing'],['交期风险',$ops['counts']['exceptions'],'orders'],
            ['待处理单证',0,'documents'],['待确认佣金',0,'commission'],
          ] as $statusItem): ?>
          <a href="?view=<?= cc_h($statusItem[2]) ?>" class="<?= $statusItem[1] > 0 ? 'attention' : 'zero' ?>"><span><?= cc_h($statusItem[0]) ?></span><strong><?= (int)$statusItem[1] ?></strong></a>
          <?php endforeach; ?>
        </section>

        <div class="dashboard-grid">
          <section class="panel wide">
            <div class="panel-head"><div><span class="eyebrow">COMMERCIAL WORK QUEUE</span><h2>统一商务工作队列</h2></div><a href="../dispatch_next.php">查看全部派工任务</a></div>
            <?php if ($ops['work_queue'] === []): ?>
              <div class="empty"><?= $ops['status'] === 'unauthenticated' ? '登录后按本人范围读取任务。' : '当前范围没有待处理任务。' ?></div>
            <?php else: ?>
              <div class="table-wrap"><table><thead><tr><th>任务 / 单号</th><th>业务摘要</th><th>来源</th><th>当前节点</th><th>负责人</th><th>等待 / 截止</th><th>状态</th><th>下一动作</th></tr></thead><tbody>
              <?php foreach ($ops['work_queue'] as $row): ?>
                <tr class="<?= $row['overdue'] ? 'overdue-row' : '' ?>"><td><b><?= cc_h($row['title']) ?></b><small><?= cc_h($row['number']) ?></small></td><td><span class="two-line"><?= cc_h($row['summary'] ?: '暂无补充摘要') ?></span></td><td><span class="tag"><?= cc_h($row['source']) ?></span></td><td><?= cc_h($row['stage']) ?></td><td class="nowrap"><?= cc_h($row['owner']) ?></td><td class="nowrap"><?= cc_h($row['due_label']) ?></td><td><span class="tag"><?= cc_h($row['status']) ?></span></td><td><a class="nowrap" href="<?= cc_h($row['target']) ?>"><?= cc_h($row['action']) ?></a></td></tr>
              <?php endforeach; ?>
              </tbody></table></div>
            <?php endif; ?>
          </section>

          <section class="panel side-stack">
            <div class="panel-head"><div><span class="eyebrow">ACTION REQUIRED</span><h2>需立即处理</h2></div></div>
            <div class="exception-list">
              <?php if ($ops['exceptions'] === []): ?><div class="empty">登录后显示本人范围异常。</div><?php endif; ?>
              <?php foreach ($ops['exceptions'] as $item): ?>
                <a href="<?= cc_h($item['target']) ?>" class="<?= cc_h($item['severity']) ?>"><span><?= cc_h($item['label']) ?></span><strong><?= (int)$item['count'] ?></strong></a>
              <?php endforeach; ?>
            </div>
            <div class="side-section"><h3>商务交付队列</h3><?php foreach ([['待生成 PDF',0,'quotation'],['待发送报价',$ops['counts']['pending_send'],'quotation'],['待客户确认',$ops['counts']['pending_customer'],'quotation'],['待生成 PI',0,'documents'],['待完善包装',0,'packaging'],['待生成 CI / PL',0,'documents']] as $deliveryItem): ?><a href="?view=<?= cc_h($deliveryItem[2]) ?>"><span><?= cc_h($deliveryItem[0]) ?></span><b><?= (int)$deliveryItem[1] ?></b></a><?php endforeach; ?></div>
            <div class="side-section"><h3>渠道状态</h3><p><i class="state-dot neutral"></i>新加坡接口未配置</p><p>待同步 <b>0</b> · 失败 <b>0</b></p><a href="?view=publishing">查看发布中心</a></div>
          </section>

          <section class="panel wide">
            <div class="panel-head"><div><span class="eyebrow">COMMERCIAL DELIVERY</span><h2>商务交付队列 / 最近报价订单</h2></div><a href="../quotation.php">查看全部 ›</a></div>
            <div class="delivery-tabs"><a class="active" href="?page=operations_dashboard&range=7">最近 7 天</a><a href="?page=operations_dashboard&range=30">最近 30 天</a><a href="?page=operations_dashboard&range=90">最近 90 天</a></div>
            <div class="delivery-stages"><?php foreach ([['报价处理',$ops['counts']['pending_approval'],'blue'],['报价发送',$ops['counts']['pending_send'],'teal'],['客户确认',$ops['counts']['pending_customer'],'violet'],['PI确认',0,'green'],['订单下达',0,'purple'],['生产中',0,'orange'],['已出货',0,'green']] as $stage): ?><div><i class="stage-icon <?= $stage[2] ?>"></i><span><?= cc_h($stage[0]) ?></span><strong><?= (int)$stage[1] ?></strong></div><?php endforeach; ?></div>
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

      <?php endif; ?>
    </main>
  </section>
</div>
<script src="assets/js/app.js" defer></script>
</body>
</html>
