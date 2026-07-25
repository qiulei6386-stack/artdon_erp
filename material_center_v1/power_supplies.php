<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use Artdon\MaterialCenter\Services\PowerSupplyReadService;

$search = trim((string)($_GET['q'] ?? ''));
$view = (new PowerSupplyReadService())->view($search);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
mc_page_start('电源只读列表', 'power', $view['user']);
?>
<div class="ui-page-head"><div><span class="ui-eyebrow">POWER SUPPLY · LEGACY READ ONLY</span><h1>电源只读列表</h1><p>从旧 BOM 物料源按“电源/驱动”分类或名称只读识别，不执行迁移、编辑或写入。</p></div><span class="ui-badge ui-badge-success">旧源只读</span></div>
<?php if ($view['status'] === 'unauthenticated'): ?>
  <?php mc_state('permission', '需要统一登录', '本页面复用广州 ERP 统一账号，不建立第二套账号。', '../login.php?redirect=/artdon_erp/material_center_v1/power_supplies.php', '前往登录'); ?>
<?php elseif ($view['status'] !== 'available'): ?>
  <?php mc_state('error', '电源数据暂时不可用', '旧 BOM 物料表不存在或读取失败；系统没有执行写操作。', './power_supplies.php', '重新加载'); ?>
<?php else: ?>
  <form class="ui-toolbar ui-card" method="get" data-search-form>
    <div class="search-field"><label class="ui-sr-only" for="power-search">搜索电源</label><input class="ui-input" id="power-search" type="search" name="q" value="<?= mc_h($search) ?>" placeholder="搜索名称、品牌、型号或规格" autocomplete="off"><button class="ui-link-button search-clear" type="button" data-search-clear aria-label="清空搜索">×</button></div>
    <button class="ui-btn" type="submit">搜索</button><a class="ui-btn ui-btn-secondary" href="./power_supplies.php">重置</a>
    <button class="ui-btn ui-btn-secondary" type="button" data-ui-table-settings="#power-table">列与密度</button><span class="ui-muted">识别到 <?= count($view['rows']) ?> 条</span>
  </form>
  <?php if ($view['rows'] === []): ?>
    <?php mc_state('empty', '没有识别到电源物料', $search === '' ? '旧 BOM 物料源中暂无分类或名称包含“电源/驱动”的有效记录。' : '当前搜索没有结果，请尝试其他关键词。', './power_supplies.php', '清除搜索'); ?>
  <?php else: ?>
  <section class="ui-card ui-table-panel">
    <div class="ui-table-wrap"><table class="ui-table" id="power-table" data-ui-table data-page-size="20">
      <thead><tr><th class="ui-select-col"><label class="ui-check ui-check-only"><input type="checkbox" data-ui-select-all aria-label="全选当前表格"><span class="ui-check-box"></span></label></th><th data-sort="number">ID</th><th data-sort>分类</th><th data-sort>名称</th><th data-sort>品牌</th><th data-sort>型号</th><th>规格</th><th data-sort>单位</th><th data-sort>更新时间</th><th class="ui-action-col">操作</th></tr></thead>
      <tbody><?php foreach ($view['rows'] as $row): $detail=['永久 ID'=>$row['id'],'分类'=>$row['category'],'名称'=>$row['name'],'品牌'=>$row['brand'],'型号'=>$row['model'],'规格'=>$row['spec'],'材料牌号'=>$row['material_grade'],'单位'=>$row['unit'],'更新时间'=>$row['updated_at']]; ?>
      <tr tabindex="0" data-detail="<?= mc_h(json_encode($detail, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?>"><td class="ui-select-col"><label class="ui-check ui-check-only"><input type="checkbox" data-ui-row-select aria-label="选择 <?= mc_h($row['name']) ?>"><span class="ui-check-box"></span></label></td><td><?= (int)$row['id'] ?></td><td><span class="ui-badge"><?= mc_h($row['category'] ?: '未分类') ?></span></td><td><b><?= mc_h($row['name']) ?></b></td><td><?= mc_h($row['brand']) ?></td><td><?= mc_h($row['model']) ?></td><td class="ui-cell-wrap"><?= mc_h($row['spec']) ?></td><td><?= mc_h($row['unit']) ?></td><td><?= mc_h($row['updated_at']) ?></td><td class="ui-action-col"><button class="ui-link-button" type="button">详情</button></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
  </section>
  <?php endif; ?>
<?php endif; ?>
<aside class="ui-drawer ui-drawer-m" id="power-detail" role="dialog" aria-modal="true" aria-labelledby="power-detail-title" aria-hidden="true" tabindex="-1"><div class="ui-drawer-header"><div><span class="ui-eyebrow">POWER DETAIL</span><h2 id="power-detail-title">电源详情</h2></div><button class="ui-btn ui-btn-secondary ui-btn-icon" type="button" data-ui-close aria-label="关闭">×</button></div><dl class="material-detail-list" data-detail-content></dl><div class="ui-drawer-footer">旧 BOM 数据只读 · 未进入正式电源编辑与迁移</div></aside>
<?php mc_page_end('', 'assets/js/power-supplies.js'); ?>
