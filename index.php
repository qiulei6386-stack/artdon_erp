<?php
require_once __DIR__ . '/includes/bootstrap.php';
if (!config_installed()) redirect('install.php');
require_login();
require_permission('dashboard.view');
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/artdon_sso_core.php';
artdon_dispatch_ensure_permissions();
artdon_naming_ensure_permissions();
artdon_datasheet_ensure_permissions();

$user = current_user();
$portalSettings = app_settings([
    'system_name' => 'Artdon Office V20',
    'company_name' => '中山雅大光电有限公司',
    'company_name_en' => 'Artdon Lighting Limited',
    'portal_subtitle' => '统一进入 CRM、邮箱、客户、推广、报价、PLM、BOM、派工、财务和系统管理。',
]);
$portalUi = get_ui_settings();
if (function_exists('sys_homepage_log')) {
    sys_homepage_log('view', 'home', $_SERVER['REQUEST_URI'] ?? 'index.php');
}

function dashboard_count(string $sql): int
{
    try {
        return (int)db()->query($sql)->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function dashboard_table_count(string $table, string $where = ''): int
{
    try {
        if (!db_table_exists($table)) {
            return 0;
        }
        return dashboard_count('SELECT COUNT(*) FROM ' . $table . ($where ? ' WHERE ' . $where : ''));
    } catch (Throwable $e) {
        return 0;
    }
}

function portal_can_open($permission): bool
{
    if ($permission === null || $permission === []) {
        return is_super_admin();
    }
    if (is_array($permission)) {
        foreach ($permission as $item) {
            if (has_permission($item)) {
                return true;
            }
        }
        return false;
    }
    return has_permission($permission);
}

function portal_icon(string $key): string
{
    $icons = [
        'crm' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5v9A2.5 2.5 0 0 1 17.5 18H14l-2 2-2-2H6.5A2.5 2.5 0 0 1 4 15.5v-9Z"/><path d="M8 9h8M8 13h5"/></svg>',
        'mail' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16v12H4z"/><path d="m4 7 8 6 8-6"/></svg>',
        'promotion' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 13h3l9-5v10l-9-5H4z"/><path d="M7 13v5a2 2 0 0 0 2 2h2M19 10v6"/></svg>',
        'quote' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3h8l4 4v14H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z"/><path d="M14 3v5h5M8 12h8M8 16h6"/></svg>',
        'finance' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 19h16M6 17V9m6 8V5m6 12v-6"/><path d="m5 10 7-6 7 6"/></svg>',
        'doc' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3h7l5 5v13H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z"/><path d="M13 3v6h6M8 13h8M8 17h5"/></svg>',
        'plm' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4 7v10l8 4 8-4V7l-8-4Z"/><path d="m4 7 8 4 8-4M12 11v10"/></svg>',
        'bom' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 5h6v6H5zM13 5h6v6h-6zM5 13h6v6H5zM13 13h6v6h-6z"/></svg>',
        'model' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M7 4v6M17 4v6M6 14h12M9 11v6M15 11v6M4 20h16"/></svg>',
        'task' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 5h14v14H5z"/><path d="m8 12 2 2 5-5M8 17h8"/></svg>',
        'weight' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 9h10l2 11H5L7 9Z"/><path d="M9 9a3 3 0 0 1 6 0M10 14h4"/></svg>',
        'acl' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 5 6v5c0 4.5 2.7 8 7 10 4.3-2 7-5.5 7-10V6l-7-3Z"/><path d="M9 12h6M12 9v6"/></svg>',
        'settings' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z"/><path d="M4 12h2m12 0h2M12 4v2m0 12v2M6.3 6.3l1.4 1.4m8.6 8.6 1.4 1.4m0-11.4-1.4 1.4m-8.6 8.6-1.4 1.4"/></svg>',
        'notify' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 16v-5a6 6 0 0 0-12 0v5l-2 2h16l-2-2Z"/><path d="M10 20h4"/></svg>',
        'audit' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h14v16H5z"/><path d="M8 8h8M8 12h8M8 16h5"/></svg>',
        'linkage' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 7h4v4H7zM13 13h4v4h-4zM15 7h3a2 2 0 0 1 2 2v1M9 17H6a2 2 0 0 1-2-2v-1M11 9h3M10 14l4-4"/></svg>',
        'status' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 13h4l2-6 4 12 2-6h4"/><path d="M4 5h16v14H4z"/></svg>',
    ];
    return $icons[$key] ?? '<span>' . h(strtoupper(substr($key, 0, 3))) . '</span>';
}

function render_quick_link(array $link): void
{
    if (!portal_can_open($link['permission'])) {
        return;
    }
    $disabled = $link['href'] === '#';
    $tag = $disabled ? 'span' : 'a';
    $href = $disabled ? '' : ' href="' . h($link['href']) . '"';
    echo '<' . $tag . $href . ' class="portal-quick-link' . ($disabled ? ' is-disabled' : '') . '" data-log-type="quick_click" data-log-module="' . h($link['key'] ?? $link['label']) . '">';
    echo '<span class="quick-icon">' . portal_icon($link['icon']) . '</span><span>' . h($link['label']) . '</span>';
    echo '</' . $tag . '>';
}

function render_portal_card(array $module): void
{
    if (!portal_can_open($module['permission'])) {
        return;
    }
    $href = $module['href'];
    $disabled = $href === '#';
    $status = $module['status'] ?? ($disabled ? '待接入' : '已启用');
    echo '<article class="portal-module-card accent-' . h($module['accent']) . '">';
    echo '<div class="module-orbit"></div><div class="module-head"><div class="portal-module-icon">' . portal_icon($module['icon']) . '</div><span class="module-status ' . ($disabled ? 'pending' : 'enabled') . '">' . h($status) . '</span></div>';
    echo '<div class="portal-module-copy"><span class="module-kicker">' . h($module['en']) . '</span><h3>' . h($module['title']) . '</h3><p>' . h($module['desc']) . '</p>';
    if (!empty($module['tags'])) {
        echo '<div class="portal-module-tags">';
        foreach ($module['tags'] as $tag) {
            echo '<span>' . h($tag) . '</span>';
        }
        echo '</div>';
    }
    echo '</div>';
    echo $disabled
        ? '<span class="portal-open disabled">待接入</span>'
        : '<a class="portal-open" href="' . h($href) . '" data-log-type="module_click" data-log-module="' . h($module['key'] ?? $module['title']) . '">进入系统</a>';
    if (!empty($module['children'])) {
        echo '<div class="portal-card-links">';
        foreach (array_slice($module['children'], 0, 5) as $child) {
            echo '<a href="' . h($child['href']) . '" data-log-type="quick_click" data-log-module="' . h(($module['key'] ?? $module['title']) . ':' . $child['label']) . '">' . h($child['label']) . '</a>';
        }
        echo '</div>';
    }
    echo '</article>';
}

function render_portal_list_item(array $module): void
{
    if (!portal_can_open($module['permission'])) return;
    $disabled = $module['href'] === '#';
    echo '<article class="portal-list-item accent-' . h($module['accent']) . '"><div class="portal-module-icon">' . portal_icon($module['icon']) . '</div><div><h3>' . h($module['title']) . '</h3><p>' . h($module['desc']) . '</p></div><span class="module-status ' . ($disabled ? 'pending' : 'enabled') . '">' . h($module['status'] ?? ($disabled ? '待接入' : '已启用')) . '</span>';
    echo $disabled ? '<span class="portal-open disabled">待接入</span>' : '<a class="portal-open" href="' . h($module['href']) . '" data-log-type="module_click" data-log-module="' . h($module['key'] ?? $module['title']) . '">进入</a>';
    echo '</article>';
}

$pendingApprovals = dashboard_table_count('crm_permission_requests', "status = 'pending'");
$unreadNotifications = 0;

$stats = [
    ['label' => '当前用户', 'value' => $user['real_name'] ?: $user['username'], 'icon' => 'acl'],
    ['label' => '角色数量', 'value' => dashboard_count('SELECT COUNT(*) FROM crm_roles'), 'icon' => 'settings'],
    ['label' => '权限数量', 'value' => dashboard_count('SELECT COUNT(*) FROM crm_permissions'), 'icon' => 'acl'],
    ['label' => '待审核用户', 'value' => dashboard_count("SELECT COUNT(*) FROM crm_users WHERE status = 'pending'"), 'icon' => 'notify'],
    ['label' => '待审批权限', 'value' => $pendingApprovals, 'icon' => 'audit'],
    ['label' => '未读通知', 'value' => $unreadNotifications, 'icon' => 'notify'],
];

$quickLinks = [
    ['key' => 'crm', 'label' => '进入 CRM', 'href' => 'crm.php', 'permission' => ['customer.view', 'dashboard.view'], 'icon' => 'crm'],
    ['key' => 'mail', 'label' => '邮箱中心', 'href' => 'mail.php', 'permission' => 'mail.view', 'icon' => 'mail'],
    ['key' => 'promotion', 'label' => '推广中心', 'href' => 'promotion.php', 'permission' => 'promotion.view', 'icon' => 'promotion'],
    ['key' => 'acl', 'label' => '用户权限', 'href' => 'permissions.php', 'permission' => ['users.view', 'permission_request.view_own', 'users.approve'], 'icon' => 'acl'],
    ['key' => 'settings', 'label' => '系统设置', 'href' => 'settings.php', 'permission' => 'settings.view', 'icon' => 'settings'],
    ['key' => 'backup', 'label' => '整站备份', 'href' => 'backup/full_backup.php', 'permission' => 'settings.schema_repair', 'icon' => 'doc'],
    ['key' => 'linkage', 'label' => '系统联动中心', 'href' => 'linkage_center.php', 'permission' => 'linkage.view', 'icon' => 'linkage'],
    ['key' => 'status', 'label' => '系统状态', 'href' => 'backup/system_status.php', 'permission' => 'settings.view', 'icon' => 'status'],
    ['key' => 'notify', 'label' => '通知中心', 'href' => 'notifications.php', 'permission' => 'notifications.view_own', 'icon' => 'notify'],
    ['key' => 'logs', 'label' => '日志审计', 'href' => 'logs.php', 'permission' => ['logs.view_own', 'logs.view_all'], 'icon' => 'audit'],
    ['key' => 'dispatch', 'label' => '派工待办', 'href' => 'dispatch_next.php', 'permission' => 'dispatch.view', 'icon' => 'task'],
    ['key' => 'naming', 'label' => '型号命名系统', 'href' => 'naming.php', 'permission' => 'naming.view', 'icon' => 'model'],
    ['key' => 'quote', 'label' => '报价系统', 'href' => 'quotation.php', 'permission' => 'quote.view', 'icon' => 'quote'],
    ['key' => 'datasheet', 'label' => '资料生成', 'href' => 'datasheet.php', 'permission' => 'datasheet.view', 'icon' => 'doc'],
    ['key' => 'plm', 'label' => 'PLM', 'href' => 'plm.php', 'permission' => 'plm.view', 'icon' => 'plm'],
    ['key' => 'bom', 'label' => 'BOM', 'href' => 'bom.php', 'permission' => 'bom.view', 'icon' => 'bom'],
];

$moduleGroups = [
    '业务经营' => [
        ['key' => 'crm', 'title' => 'CRM 客户经营', 'en' => 'Customer Operation', 'desc' => '客户360、邮箱、联系人、跟进、推广、客户日志、导入导出、数据分析。', 'href' => 'crm.php', 'permission' => ['customer.view', 'dashboard.view'], 'icon' => 'crm', 'accent' => 'blue', 'status' => '已启用', 'tags' => ['客户经营', '邮件协同', '推广跟进', '日志审计', '数据分析']],
        ['key' => 'mail', 'title' => '邮箱中心', 'en' => 'Mail Center', 'desc' => '收件箱、发件箱、草稿、已发送、客户关联、附件和 AI 邮件识别入口。', 'href' => 'mail.php', 'permission' => 'mail.view', 'icon' => 'mail', 'accent' => 'green', 'status' => '已启用', 'tags' => ['收件箱', '发件箱', '客户关联', 'AI识别']],
        ['key' => 'promotion', 'title' => '推广中心', 'en' => 'Marketing Center', 'desc' => '推广项目、客户分组、执行清单、群推广、邮件推广和效果分析。', 'href' => 'promotion.php', 'permission' => 'promotion.view', 'icon' => 'promotion', 'accent' => 'amber', 'status' => '已启用', 'tags' => ['推广任务', '客户分组', '执行清单', '效果分析']],
        ['key' => 'quote', 'title' => '报价系统', 'en' => 'Quotation', 'desc' => '正式报价、PDF/Excel、报价历史、客户价格记录，并可选择型号命名产品。', 'href' => 'quotation.php', 'permission' => 'quote.view', 'icon' => 'quote', 'accent' => 'cyan', 'status' => '已接入权限底座', 'tags' => ['统一登录', '统一权限', '命名产品选择', 'PDF/Excel']],
        ['key' => 'order_finance', 'title' => '订单 / 财务', 'en' => 'Order & Finance', 'desc' => '订单进度、客户对账、回款、供应商对账和财务记录。', 'href' => 'quotation.php#orders', 'permission' => 'quote.view', 'icon' => 'finance', 'accent' => 'green', 'status' => '已接入报价订单'],
        ['key' => 'datasheet', 'title' => '资料生成', 'en' => 'Document Pack', 'desc' => '联动当前型号命名和官网同步资料，生成客户资料包、PDF/Excel、配光曲线、高清图和配件资料。', 'href' => 'datasheet.php', 'permission' => 'datasheet.view', 'icon' => 'doc', 'accent' => 'amber', 'status' => '已接入权限底座', 'tags' => ['统一登录', '统一权限', '命名联动', '官网资料']],
    ],
    '研发 / 成本 / 生产' => [
        ['key' => 'plm', 'title' => 'PLM 项目/研发', 'en' => 'Product Lifecycle', 'desc' => '样品开发、测试资料、问题闭环、项目文件。', 'href' => 'plm.php', 'permission' => 'plm.view', 'icon' => 'plm', 'accent' => 'violet', 'status' => '联动入口'],
        ['title' => 'BOM 成本', 'en' => 'Bill of Materials', 'desc' => '物料、成本、加工、表面处理、包装损耗，并可联动当前型号命名系统。', 'href' => 'bom.php', 'permission' => 'bom.view', 'icon' => 'bom', 'accent' => 'blue', 'status' => '已接入权限底座', 'tags' => ['统一登录', '统一权限', '命名联动', '成本权限']],
        ['key' => 'naming', 'title' => '型号命名', 'en' => 'Model Naming', 'desc' => '型号规则、产品图片、尺寸图、开孔和分类管理。', 'href' => 'naming.php', 'permission' => 'naming.view', 'icon' => 'model', 'accent' => 'cyan', 'status' => '已接入权限底座', 'tags' => ['统一登录', '统一权限', '统一日志']],
        ['key' => 'dispatch', 'title' => '派工待办', 'en' => 'Work Dispatch', 'desc' => '派工任务、多人协作、附件、日志、实时同步。', 'href' => 'dispatch_next.php', 'permission' => 'dispatch.view', 'icon' => 'task', 'accent' => 'red', 'status' => '已接入权限底座', 'tags' => ['个人待办', '派工任务', '多人协作', '附件日志']],
        ['key' => 'profile_weight', 'title' => '重量 / 型材', 'en' => 'Profile Weight', 'desc' => '型材重量、规格、损耗、加工参数和成本核算基础资料。', 'href' => 'crm.php#linkage', 'permission' => 'linkage.view', 'icon' => 'weight', 'accent' => 'green', 'status' => '联动入口'],
    ],
    '系统治理' => [
        ['key' => 'acl', 'title' => '用户权限中心', 'en' => 'Access Control', 'desc' => '注册审核、部门、岗位、角色、权限、临时授权、审批、通知。', 'href' => 'permissions.php', 'permission' => ['users.view', 'permission_request.view_own', 'users.approve'], 'icon' => 'acl', 'accent' => 'red', 'status' => '已启用', 'children' => [['label'=>'权限中心','href'=>'permissions.php'],['label'=>'用户审核','href'=>'users.php'],['label'=>'日志审计','href'=>'logs.php']]],
        ['key' => 'settings', 'title' => '系统设置中心', 'en' => 'System Settings', 'desc' => '系统参数、主题、密度、字段检测、一键修复、容量、日志设置。', 'href' => 'settings.php', 'permission' => 'settings.view', 'icon' => 'settings', 'accent' => 'blue', 'status' => '已启用', 'children' => [['label'=>'设置','href'=>'settings.php'],['label'=>'模板备份','href'=>'backup/template_backup.php'],['label'=>'整站备份','href'=>'backup/full_backup.php']]],
        ['key' => 'backup', 'title' => '备份恢复中心', 'en' => 'Backup & Restore', 'desc' => '模板备份、数据备份、整站备份、自动备份计划和恢复审计。', 'href' => 'backup/full_backup.php', 'permission' => 'settings.schema_repair', 'icon' => 'doc', 'accent' => 'violet', 'status' => '已启用', 'children' => [['label'=>'模板','href'=>'backup/template_backup.php'],['label'=>'数据','href'=>'backup/data_backup.php'],['label'=>'整站','href'=>'backup/full_backup.php'],['label'=>'自动','href'=>'backup/backup_scheduler.php']]],
        ['key' => 'linkage', 'title' => '系统联动中心', 'en' => 'Linkage Center', 'desc' => '查看各系统之间的联动设计、接入状态和预留接口。', 'href' => 'linkage_center.php', 'permission' => 'linkage.view', 'icon' => 'linkage', 'accent' => 'cyan', 'status' => '已启用', 'children' => [['label'=>'联动中心','href'=>'linkage_center.php'],['label'=>'系统状态','href'=>'backup/system_status.php'],['label'=>'备份控制台','href'=>'backup/full_backup.php']]],
        ['key' => 'status', 'title' => '系统状态', 'en' => 'System Status', 'desc' => '配置文件、备份目录、权限表、日志表和备份表只读健康检查。', 'href' => 'backup/system_status.php', 'permission' => 'settings.view', 'icon' => 'status', 'accent' => 'green', 'status' => '已启用'],
        ['title' => '通知中心', 'en' => 'Notifications', 'desc' => '权限申请、注册审核、高危操作和系统提醒。', 'href' => 'notifications.php', 'permission' => 'notifications.view_own', 'icon' => 'notify', 'accent' => 'amber', 'status' => '已启用'],
        ['title' => '日志审计', 'en' => 'Audit Trail', 'desc' => '登录日志、操作日志、权限变更和高危操作审计。', 'href' => 'logs.php', 'permission' => ['logs.view_own', 'logs.view_all'], 'icon' => 'audit', 'accent' => 'green', 'status' => '已启用'],
    ],
];

page_header($portalSettings['system_name'], ['portal' => true, 'notifications' => $unreadNotifications]);
?>
<div data-portal-view-root data-portal-view="cards">
<section class="portal-hero">
  <div class="portal-hero-main">
    <div class="hero-grid-art" aria-hidden="true"></div>
    <div class="portal-brand-row">
      <div class="portal-logo"><span>AD</span></div>
      <div>
        <div class="portal-system-name"><?= h($portalSettings['system_name']) ?></div>
        <div class="portal-company"><?= h($portalSettings['company_name']) ?></div>
        <div class="portal-company-en"><?= h($portalSettings['company_name_en']) ?></div>
      </div>
    </div>
    <h1>工厂数字化工作台</h1>
    <p><?= h($portalSettings['portal_subtitle']) ?></p>
    <div class="hero-signal-row">
      <span>Lighting Manufacturing</span>
      <span>Workflow Portal</span>
      <span>Secure Access</span>
    </div>
    <section class="portal-quickbar portal-quickbar-hero" aria-label="快捷入口">
      <?php foreach ($quickLinks as $link) render_quick_link($link); ?>
    </section>
  </div>

  <aside class="portal-user-card">
    <div class="time-panel">
      <span>当前时间</span>
      <strong data-live-time><?= h(date('H:i')) ?></strong>
      <em><?= h(date('Y-m-d')) ?></em>
    </div>
    <div class="portal-user-top">
      <span>当前用户</span>
      <strong><?= h($user['username']) ?> / <?= h($user['real_name'] ?: '未填写姓名') ?></strong>
    </div>
    <dl>
      <div><dt>部门</dt><dd><?= h($user['department_name'] ?: '未分配部门') ?></dd></div>
      <div><dt>角色</dt><dd><?= h($user['role_name'] ?: '未分配角色') ?></dd></div>
      <div><dt>未读通知</dt><dd><?= h($unreadNotifications) ?></dd></div>
      <div><dt>待审批权限</dt><dd><?= h($pendingApprovals) ?></dd></div>
    </dl>
  </aside>
</section>

<?php if ((int)$portalUi['show_world_time'] === 1): ?>
<section class="portal-world-strip" aria-label="世界时间">
  <?php foreach ($portalUi['world_clocks'] as $clock): ?>
  <div class="world-clock-card"><span><?= h(($clock['emoji'] ?? '') . ' ' . ($clock['label'] ?? '')) ?></span><strong data-world-time="<?= h($clock['timezone'] ?? 'UTC') ?>">--:--:--</strong></div>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<section class="portal-view-toolbar">
  <div><strong>系统入口</strong><span>切换卡片模式或轻量列表模式</span></div>
  <div class="portal-view-toggle">
    <button type="button" class="active" data-portal-view="cards">卡片模式</button>
    <button type="button" data-portal-view="list">列表模式</button>
  </div>
</section>

<section class="portal-stats" aria-label="工作台统计">
  <?php foreach ($stats as $stat): ?>
    <div><span class="stat-icon"><?= portal_icon($stat['icon']) ?></span><span><?= h($stat['label']) ?></span><strong><?= h($stat['value']) ?></strong></div>
  <?php endforeach; ?>
</section>

<?php foreach ($moduleGroups as $groupTitle => $modules): ?>
  <section class="portal-module-section portal-card-mode">
    <div class="portal-section-title">
      <h2><?= h($groupTitle) ?></h2>
      <span>System Entry</span>
    </div>
    <div class="portal-module-grid">
      <?php foreach ($modules as $module) render_portal_card($module); ?>
    </div>
  </section>
<?php endforeach; ?>
<section class="portal-list-mode">
  <?php foreach ($moduleGroups as $groupTitle => $modules): ?>
    <div class="portal-list-group">
      <h2><?= h($groupTitle) ?></h2>
      <div class="portal-list-grid"><?php foreach ($modules as $module) render_portal_list_item($module); ?></div>
    </div>
  <?php endforeach; ?>
</section>
</div>
<?php page_footer(['portal' => true]); ?>
