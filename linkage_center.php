<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_permission('linkage.view');
require_once __DIR__ . '/includes/linkage_service.php';
require_once __DIR__ . '/includes/layout.php';

$modules = get_linkage_modules();
$edges = get_linkage_edges();
$events = get_recent_linkage_events();
$summary = get_linkage_health_summary();

function linkage_module_name(array $modules, string $key): string
{
    $aliases = [
        'index' => '首页',
        'schema_repair' => '字段修复',
    ];
    if (isset($aliases[$key])) {
        return $aliases[$key];
    }
    foreach ($modules as $module) {
        if ($module['module_key'] === $key) {
            return $module['module_name'];
        }
    }
    return $key;
}

function linkage_badge(string $status): string
{
    return '<span class="linkage-badge ' . h($status) . '">' . h(linkage_status_label($status)) . '</span>';
}

page_header('系统联动中心', ['ui_type' => 'monitor', 'page' => 'linkage']);
?>
<section class="linkage-page">
  <div class="linkage-hero">
    <div>
      <span>System Linkage Architecture</span>
      <h2>系统联动中心</h2>
      <p>查看 Artdon Office 各模块之间的联动设计、接入状态和预留接口。当前业务模块仍在建设中，部分联动为预留状态。</p>
    </div>
    <div class="linkage-state-card">
      <strong>当前状态</strong>
      <span>基础框架已建立，业务模块陆续接入中。</span>
    </div>
  </div>

  <div class="linkage-notice">
    当前联动中心已预留登录、权限、通知、日志、备份、CRM、邮箱、客户、推广、PLM、BOM、报价、派工等模块的联动接口。已完成的模块会显示为“已接入”，尚未完成的模块显示为“待接入”，不会假装检测真实数据。
  </div>

  <div class="acl-metrics">
    <div><span>已接入模块</span><strong><?= h($summary['connected']) ?></strong><em>Connected</em></div>
    <div><span>待接入模块</span><strong><?= h($summary['pending']) ?></strong><em>Pending</em></div>
    <div><span>预留模块</span><strong><?= h($summary['reserved']) ?></strong><em>Reserved</em></div>
    <div><span>异常数量</span><strong><?= h($summary['error']) ?></strong><em>Error</em></div>
  </div>

  <section class="linkage-section">
    <div class="acl-panel-head"><h3>模块节点图</h3><p>节点状态来自当前架构规划，不读取未完成业务模块的真实运行数据。</p></div>
    <div class="linkage-node-grid">
      <?php foreach ($modules as $module): $meta = $module['meta']; ?>
      <article class="linkage-node <?= h($module['status']) ?>" id="module-<?= h($module['module_key']) ?>">
        <div class="linkage-node-top"><span class="node-light"></span><?= linkage_badge($module['status']) ?></div>
        <h4><?= h($module['module_name']) ?></h4>
        <p><?= h($meta['note'] ?? '') ?></p>
        <a href="#detail-<?= h($module['module_key']) ?>">查看详情</a>
      </article>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="linkage-section">
    <div class="acl-panel-head"><h3>设计中的联动关系</h3><p>这里只展示联动设计和接入状态，不伪造最近调用时间或业务检测结果。</p></div>
    <div class="linkage-edge-list">
      <?php foreach ($edges as $edge): ?>
      <article class="linkage-edge <?= h($edge['status']) ?>">
        <strong><?= h(linkage_module_name($modules, $edge['from'])) ?> → <?= h(linkage_module_name($modules, $edge['to'])) ?></strong>
        <span><?= h($edge['relation_type']) ?></span>
        <?= linkage_badge($edge['status']) ?>
        <p><?= h($edge['description']) ?></p>
      </article>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="linkage-section">
    <div class="acl-panel-head"><h3>模块详情</h3><p>为后续模块接入保留文件、权限、日志和接口说明。</p></div>
    <div class="linkage-detail-grid">
      <?php foreach ($modules as $module): $meta = $module['meta']; ?>
      <article class="linkage-detail-card <?= h($module['status']) ?>" id="detail-<?= h($module['module_key']) ?>">
        <div class="linkage-node-top"><h4><?= h($module['module_name']) ?></h4><?= linkage_badge($module['status']) ?></div>
        <dl>
          <div><dt>分组</dt><dd><?= h($meta['group'] ?? '-') ?></dd></div>
          <div><dt>相关文件</dt><dd><?= h(implode(' / ', $meta['files'] ?? []) ?: '待接入') ?></dd></div>
          <div><dt>权限 key</dt><dd><?= h(implode(' / ', $meta['permissions'] ?? []) ?: '待定义') ?></dd></div>
          <div><dt>相关日志</dt><dd><?= h(implode(' / ', $meta['logs'] ?? []) ?: '待接入') ?></dd></div>
          <div><dt>预留接口</dt><dd>register_linkage_module / register_linkage_edge / record_linkage_event</dd></div>
          <div><dt>接入说明</dt><dd><?= h($meta['note'] ?? '') ?></dd></div>
        </dl>
      </article>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="linkage-section">
    <div class="acl-panel-head"><h3>最近联动事件</h3><p>业务模块完成接入后，关键联动事件会显示在这里。</p></div>
    <?php if (!$events): ?>
      <div class="linkage-empty">暂无真实联动事件，业务模块接入后将在这里显示。</div>
    <?php else: ?>
      <div class="log-card-grid">
        <?php foreach ($events as $event): ?>
        <article class="log-card"><span class="status-pill ok"><?= h($event['status']) ?></span><h4><?= h($event['title']) ?></h4><p><?= h($event['module_key'] . ' / ' . $event['event_type']) ?></p><small><?= h($event['created_at']) ?> · <?= h($event['created_by_name']) ?></small></article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="linkage-section">
    <div class="acl-panel-head"><h3>接入规范</h3><p>后续模块完成后，按以下方式接入联动中心。</p></div>
    <div class="linkage-spec">
      <ol>
        <li>在模块初始化时调用 <code>register_linkage_module</code>。</li>
        <li>在模块与其他模块产生关系时调用 <code>register_linkage_edge</code>。</li>
        <li>发生关键操作时调用 <code>record_linkage_event</code>。</li>
        <li>关键操作同时写入操作日志。</li>
        <li>如果有通知，调用通知中心。</li>
        <li>如果有权限控制，注明权限 key。</li>
      </ol>
      <pre><code>record_linkage_event(
  'customer',
  'create',
  '新增客户',
  ['customer_id' => $id],
  'success'
);</code></pre>
    </div>
  </section>
</section>
<?php page_footer(); ?>
