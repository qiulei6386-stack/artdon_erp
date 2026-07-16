<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/crm_config.php';
require_once __DIR__ . '/crm_auth.php';
require_once __DIR__ . '/crm_log.php';
require_once __DIR__ . '/crm_customer.php';
require_once __DIR__ . '/crm_visit.php';
require_once __DIR__ . '/crm_opportunity.php';
require_once __DIR__ . '/crm_mail.php';
require_once __DIR__ . '/crm_marketing.php';
require_once __DIR__ . '/crm_ai.php';
require_once __DIR__ . '/radar.php';
require_once __DIR__ . '/crm_settings_config.php';
require_once __DIR__ . '/crm_ui.php';
require_login();
crm_require('dashboard.view');
crm_run_schema_ensures();
crm_touch_online('workspace');

$user = current_user();
$allModules = crm_allowed_modules();
$modules = crm_top_modules($allModules);
$prefs = crm_preferences((int)$user['id']);
$crmConfig = crm_config_bootstrap();
$companySettings = get_company_settings();
$moduleSettings = crm_module_settings_all();
$customerFilterUsers = db()->query("SELECT u.id, u.username, COALESCE(u.real_name, u.username) AS display_name, d.name AS department_name FROM crm_users u LEFT JOIN crm_departments d ON d.id = u.department_id WHERE u.status = 'active' ORDER BY d.sort_order, u.username")->fetchAll();
$onlineCount = crm_online_count();
$notificationUnreadCount = function_exists('notification_unread_count') ? notification_unread_count((int)$user['id']) : 0;
$topNotifications = function_exists('notification_list') ? notification_list((int)$user['id'], 8) : [];
$systemName = $companySettings['company_name_en'] ?? 'Artdon Lighting Limited';
function crm_country_flag(string $value): string
{
    $raw = trim($value);
    if ($raw === '') return '';
    $map = [
        'CN'=>'CN','CHINA'=>'CN','中国'=>'CN','中國'=>'CN',
        'US'=>'US','USA'=>'US','UNITED STATES'=>'US','AMERICA'=>'US','美国'=>'US','美國'=>'US',
        'GB'=>'GB','UK'=>'GB','UNITED KINGDOM'=>'GB','ENGLAND'=>'GB','英国'=>'GB','英國'=>'GB',
        'AE'=>'AE','UAE'=>'AE','UNITED ARAB EMIRATES'=>'AE','DUBAI'=>'AE','阿联酋'=>'AE','阿聯酋'=>'AE','迪拜'=>'AE',
        'VN'=>'VN','VIETNAM'=>'VN','VIET NAM'=>'VN','越南'=>'VN',
        'ID'=>'ID','INDONESIA'=>'ID','印度尼西亚'=>'ID','印尼'=>'ID',
        'IN'=>'IN','INDIA'=>'IN','印度'=>'IN',
        'KR'=>'KR','KOREA'=>'KR','SOUTH KOREA'=>'KR','韩国'=>'KR','韓國'=>'KR',
        'JP'=>'JP','JAPAN'=>'JP','日本'=>'JP',
        'DE'=>'DE','GERMANY'=>'DE','德国'=>'DE','德國'=>'DE',
        'FR'=>'FR','FRANCE'=>'FR','法国'=>'FR','法國'=>'FR',
        'IT'=>'IT','ITALY'=>'IT','意大利'=>'IT',
        'SG'=>'SG','SINGAPORE'=>'SG','新加坡'=>'SG',
        'MY'=>'MY','MALAYSIA'=>'MY','马来西亚'=>'MY',
        'TH'=>'TH','THAILAND'=>'TH','泰国'=>'TH',
        'PH'=>'PH','PHILIPPINES'=>'PH','菲律宾'=>'PH',
        'AU'=>'AU','AUSTRALIA'=>'AU','澳大利亚'=>'AU','澳洲'=>'AU',
        'CA'=>'CA','CANADA'=>'CA','加拿大'=>'CA',
        'SA'=>'SA','SAUDI ARABIA'=>'SA','沙特'=>'SA','沙特阿拉伯'=>'SA',
    ];
    $key = strtoupper(preg_replace('/[._]+/', ' ', $raw));
    $key = preg_replace('/\s+/', ' ', trim((string)$key));
    $code = preg_match('/^[A-Z]{2}$/', $raw) ? strtoupper($raw) : ($map[$key] ?? $map[$raw] ?? '');
    if (!preg_match('/^[A-Z]{2}$/', $code)) return '';
    $base = 127397;
    return html_entity_decode('&#' . ($base + ord($code[0])) . ';&#' . ($base + ord($code[1])) . ';', ENT_NOQUOTES, 'UTF-8');
}
$crmCanCreateDispatch = crm_can('mail.view');
$actionPermissions = [
    '新建客户' => crm_can('customer.create'),
    '暂存池' => crm_can('customer.lead_pool_view') || crm_can('customer.lead_pool'),
    '编辑暂存客户' => crm_can('customer.lead_pool'),
    '确认加入正式库' => crm_can('customer.lead_pool') && crm_can('customer.create'),
    '关联已有客户' => crm_can('customer.lead_pool'),
    '丢弃暂存客户' => crm_can('customer.lead_pool'),
    '编辑客户' => crm_can('customer.edit'),
    '删除客户' => crm_can('customer.delete'),
    '强制删除' => crm_can('customer.force_delete'),
    '恢复客户' => crm_can('customer.restore'),
    '查看客户日志' => crm_can('customer.view_logs') || crm_can('logs.view_own'),
    '新建跟进' => crm_can('follow.create'),
    '新建任务' => crm_can('task.create'),
    '新建样品寄送' => crm_can('sample.create'),
    '编辑寄送信息' => crm_can('sample.edit'),
    '上传图片' => crm_can('sample.upload_image'),
    '上传附件' => crm_can('sample.upload_file'),
    '修改快递公司' => crm_can('sample.tracking'),
    '修改快递单号' => crm_can('sample.tracking'),
    '标记已备样' => crm_can('sample.edit'),
    '标记已寄出' => crm_can('sample.shipped'),
    '标记已签收' => crm_can('sample.signed'),
    '填写客户反馈' => crm_can('sample.edit'),
    '删除样品寄送' => crm_can('sample.edit'),
    '新建商机' => crm_can('opportunity.create'),
    '查看商机' => crm_can('opportunity.view'),
    '编辑商机' => crm_can('opportunity.edit'),
    '推进阶段' => crm_can('opportunity.stage'),
    '标记赢单' => crm_can('opportunity.win'),
    '标记输单' => crm_can('opportunity.lose'),
    '删除商机' => crm_can('opportunity.delete'),
    '导入商机' => crm_can('opportunity.create'),
    '导出商机' => crm_can('opportunity.export'),
    '新建拜访' => crm_can('visit.create'),
    '新建来访' => crm_can('visit.create'),
    '查看拜访记录' => crm_can('visit.view'),
    '查看来访记录' => crm_can('visit.view'),
    '创建接待派工' => crm_can('visit.edit'),
    '填写拜访结果' => crm_can('visit.result'),
    '填写接待结果' => crm_can('visit.result'),
    '分配客户' => crm_can('customer.assign'),
    '加入分组' => crm_can('customer.batch'),
    '管理分组' => crm_can('customer.batch'),
    '转入公海' => crm_can('customer.transfer_public'),
    '领取公海' => crm_can('customer.claim_public'),
    '公海池' => crm_can('customer.view'),
    '查看日志' => crm_can('customer.view_logs') || crm_can('logs.view_own'),
    '写邮件' => crm_can('mail.send'),
    '收取邮件' => crm_can('mail.sync'),
    '回复' => crm_can('mail.view'),
    '回复全部' => crm_can('mail.view'),
    '转发' => crm_can('mail.send'),
    '关联客户' => crm_can('mail.link_customer'),
    '查看当前邮件往来' => crm_can('mail.view'),
    '转派工' => crm_can('mail.view') && $crmCanCreateDispatch,
    '邮箱设置' => crm_can('mail.account_bind_own'),
    '新建推广项目' => crm_can('promotion.task_create'),
    '新建推广任务' => crm_can('promotion.task_create'),
    '批量创建推广任务' => crm_can('promotion.task_create'),
    '创建推广任务' => crm_can('promotion.task_create'),
    '编辑项目' => crm_can('promotion.edit_project'),
    '编辑任务' => crm_can('promotion.edit_project'),
    '编辑推广任务' => crm_can('promotion.edit_project'),
    '复制项目' => crm_can('promotion.task_create'),
    '复制推广项目' => crm_can('promotion.task_create'),
    '启动项目' => crm_can('promotion.execute'),
    '暂停项目' => crm_can('promotion.execute'),
    '继续项目' => crm_can('promotion.execute'),
    '取消项目' => crm_can('promotion.execute'),
    '删除项目' => crm_can('promotion.delete_project'),
    '批量删除' => crm_can('promotion.delete_project'),
    '生成队列' => crm_can('promotion.execute'),
    '生成执行队列' => crm_can('promotion.execute'),
    '重新生成队列' => crm_can('promotion.execute'),
    '重新生成执行清单' => crm_can('promotion.execute'),
    '重试失败队列' => crm_can('promotion.execute'),
    '取消未发送队列' => crm_can('promotion.execute'),
    '批量暂停' => crm_can('promotion.execute'),
    '批量继续' => crm_can('promotion.execute'),
    '批量复制项目' => crm_can('promotion.task_create'),
    '批量重新生成执行清单' => crm_can('promotion.execute'),
    '批量重试失败队列' => crm_can('promotion.execute'),
    '批量取消未发送队列' => crm_can('promotion.execute'),
    '新建客户组' => crm_can('promotion.create_group'),
    '管理客户组' => crm_can('promotion.edit_group'),
    '编辑客户组' => crm_can('promotion.edit_group'),
    '复制客户组' => crm_can('promotion.create_group'),
    '删除客户组' => crm_can('promotion.delete_group'),
    '停用客户组' => crm_can('promotion.edit_group'),
    '归档客户组' => crm_can('promotion.edit_group'),
    '添加客户到本组' => crm_can('promotion.move_customer'),
    '从本组移出客户' => crm_can('promotion.move_customer'),
    '批量加入客户组' => crm_can('promotion.move_customer'),
    '批量移出客户组' => crm_can('promotion.move_customer'),
    '批量删除客户组' => crm_can('promotion.delete_group'),
    '设置可推广' => crm_can('promotion.manage'),
    '设置不推广' => crm_can('promotion.manage'),
    '加入黑名单' => crm_can('promotion.manage'),
    '批量设置可推广' => crm_can('promotion.manage'),
    '批量设置不推广' => crm_can('promotion.manage'),
    '批量加入黑名单' => crm_can('promotion.manage'),
    '批量移出黑名单' => crm_can('promotion.manage'),
    '设置联系人角色' => crm_can('promotion.manage'),
    '设置为主联系人' => crm_can('promotion.manage'),
    '执行策略检查' => crm_can('promotion.manage'),
    '标记完成' => crm_can('promotion.execute'),
    '填写结果' => crm_can('promotion.execute'),
    '上传截图' => crm_can('promotion.execute'),
    '标记失败' => crm_can('promotion.execute'),
    '标记跳过' => crm_can('promotion.execute'),
    '批量标记跳过' => crm_can('promotion.execute'),
    '批量改执行人' => crm_can('promotion.execute'),
    '查看执行日志' => crm_can('promotion.view'),
    '查看效果分析' => crm_can('promotion.analytics'),
    '导出联系人策略' => crm_can('promotion.export'),
    '批量导出' => crm_can('promotion.export'),
];
$crmPermissionState = crm_permission_state([
    'contact.view',
    'follow.view',
    'visit.view',
    'visit.create',
    'visit.edit',
    'visit.confirm',
    'visit.result',
    'visit.export',
    'visit.report',
    'opportunity.view',
    'opportunity.create',
    'opportunity.edit',
    'opportunity.delete',
    'opportunity.stage',
    'opportunity.win',
    'opportunity.lose',
    'opportunity.export',
    'opportunity.report',
    'customer.mail_summary',
    'customer.quote_summary',
    'customer.plm_summary',
    'customer.bom_summary',
    'customer.dispatch_summary',
    'customer.order_summary',
    'customer.material_summary',
    'customer.graph_manage',
    'customer.view_logs',
    'customer.lead_pool_view',
    'customer.lead_pool',
    'customer.merge',
    'customer.timeline_view',
    'customer.event_manage',
    'customer.file_upload',
    'customer.view',
    'customer.create',
    'customer.edit',
    'customer.delete',
    'mail.view',
    'mail.view_own',
    'mail.account_bind_own',
    'mail.sync',
    'mail.reply',
    'mail.send',
    'mail.delete',
    'mail.attachment_download',
    'mail.link_customer',
    'mail.signature_manage_own',
    'mail.signature_batch_apply',
    'mail.account_manage_all',
    'mail.view_logs',
    'promotion.view',
    'promotion.manage',
    'promotion.task_create',
    'promotion.execute',
    'promotion.analytics',
    'ai.view',
    'ai.lead_capture',
    'ai.quote_draft',
    'ai.material_draft',
    'ai.confirm_customer',
    'ai.confirm_opportunity',
    'ai.confirm_quote',
    'ai.confirm_material',
    'ai.reject',
    'ai.logs',
    'ai.settings',
    'radar_view',
    'radar_seed_manage',
    'radar_profile_manage',
    'radar_task_create',
    'radar_task_run',
    'radar_task_pause',
    'radar_candidate_view',
    'radar_candidate_review',
    'radar_candidate_delete',
    'radar_candidate_to_crm',
    'radar_rule_manage',
    'radar_cost_view',
    'radar_log_view',
    'radar_settings_manage',
    'radar_feedback_submit',
    'radar_template_view',
    'radar_template_create',
    'radar_template_edit',
    'radar_template_disable',
    'radar_template_delete',
    'radar_template_restore',
    'radar_template_export',
    'radar_template_import',
    'radar_template_create_task',
]);
$prefStyle = sprintf(
    '--crm-font-size-base:%dpx;--crm-font-size-table:%dpx;--crm-font-size-email-list:%dpx;--crm-font-size-email-body:%dpx;--crm-font-size-email-editor:%dpx;--crm-topbar-height:%dpx;--crm-tabbar-height:%dpx;--crm-actionbar-width:%dpx;--crm-row-height:%dpx;',
    $prefs['font_scale'],
    $prefs['font_scale'],
    $prefs['email_list_font_size'],
    $prefs['email_body_font_size'],
    $prefs['email_editor_font_size'],
    $prefs['topbar_height'],
    $prefs['tabbar_height'],
    $prefs['actionbar_width'],
    $prefs['table_row_height']
);
?>
<!doctype html>
<html lang="zh-CN" data-crm-theme="<?= h($prefs['theme_name']) ?>" data-crm-density="<?= h($prefs['density_mode']) ?>" data-crm-animation="<?= h($prefs['animation_mode']) ?>" data-crm-tab-label="<?= h($prefs['tab_label_mode'] ?? 'icon_short') ?>" data-crm-button-style="<?= h($prefs['module_layout']['button_style'] ?? 'rounded') ?>" data-crm-button-size="<?= h($prefs['module_layout']['button_size'] ?? 'standard') ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Artdon CRM - Artdon Office V20</title>
  <link rel="stylesheet" href="assets/crm/themes.css?v=<?= filemtime(__DIR__ . '/assets/crm/themes.css') ?>">
  <link rel="stylesheet" href="assets/crm/crm.css?v=<?= filemtime(__DIR__ . '/assets/crm/crm.css') ?>">
</head>
<body class="crm-app" style="<?= h($prefStyle) ?>">
  <header class="crm-status-console">
    <section class="status-brand-zone">
      <a class="status-logo" href="index.php" title="返回 Office 首页">AD</a>
      <div class="status-brand-text">
        <strong><?= h($systemName) ?></strong>
        <span>Artdon Office V20</span>
      </div>
    </section>

    <section class="crm-header-right">
      <div class="status-time-anchor">
        <button type="button" class="status-time-card" data-status-menu-toggle="world-time" title="查看世界时间">
          <strong data-status-clock>--:--:--</strong>
          <span data-status-date>----</span>
          <em>GMT+8 北京</em>
        </button>
        <div class="status-panel world-time-panel" data-status-menu="world-time">
          <header><div><strong>世界时间</strong><span>按当前账号保存</span></div><button type="button" data-world-time-settings>设置</button></header>
          <div data-world-time-list></div>
        </div>
      </div>
      <button class="status-pill notice <?= $notificationUnreadCount > 0 ? 'has-unread' : '' ?>" type="button" data-notification-toggle aria-expanded="false">
        <span>通知</span><b data-notification-count><?= h($notificationUnreadCount) ?></b>
      </button>
      <div class="status-online-anchor">
        <button class="status-pill" type="button" data-status-menu-toggle="online" title="查看在线人员">在线 <strong data-online-count><?= h($onlineCount) ?></strong></button>
        <div class="status-panel online-panel" data-status-menu="online">
          <header><div><strong>在线用户</strong><span>模块 / 活跃 / 设备</span></div></header>
          <div data-online-list class="crm-online-list"></div>
        </div>
      </div>
      <div class="crm-system-anchor">
        <button type="button" class="crm-status-module" data-status-menu-toggle="systems">
          <span data-current-system>CRM</span><em>▾</em>
        </button>
        <div class="status-menu crm-system-menu" data-status-menu="systems">
          <header class="top-popover-head"><div><strong>系统链接</strong><span>CRM / Office 模块</span></div></header>
          <?php foreach ([['CRM','crm.php'],['邮箱','mail.php'],['推广','promotion.php'],['报价','quotation.php'],['资料','datasheet.php'],['BOM','bom.php'],['派工','dispatch_next.php'],['命名','naming.php'],['PLM','../plm.php'],['对账','crm.php#linkage'],['Office首页','index.php']] as $item): ?>
          <a href="<?= h($item[1]) ?>"><?= h($item[0]) ?></a>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="status-dropdown crm-user-shell">
        <button type="button" class="crm-account crm-user-mini" data-status-menu-toggle="user" title="<?= h(($user['role_name'] ?: '未分配角色') . ' / ' . ($user['department_name'] ?: '未分配部门')) ?>">
          <strong><?= h($user['username']) ?></strong>
          <span><?= h($user['role_name'] ?: '未分配角色') ?> / <?= h($user['department_name'] ?: '未分配部门') ?></span>
        </button>
        <div class="status-menu align-right" data-status-menu="user">
          <header class="top-popover-head"><div><strong>账号菜单</strong><span><?= h($user['username']) ?></span></div></header>
          <button type="button" data-self-profile-open>我的账号</button>
          <button type="button" data-self-password-open>修改密码</button>
          <button type="button" data-self-settings-open>我的设置</button>
          <button type="button" data-notification-settings-open>通知设置</button>
          <button type="button" data-office-settings-open>Office 设置</button>
          <a href="permissions.php">权限中心</a>
          <a href="logs.php">日志中心</a>
          <a href="login.php?action=logout" class="danger">退出登录</a>
        </div>
      </div>
    </section>
  </header>

  <nav class="crm-tabbar" aria-label="CRM 模块">
    <?php foreach ($modules as $key => $module): ?>
    <button type="button" data-crm-tab="<?= h($key) ?>" title="<?= h($module['full']) ?>">
      <span><?= h($module['icon']) ?></span><strong data-short="<?= h($module['short']) ?>" data-full="<?= h($module['full']) ?>"><?= h($module['short']) ?></strong>
    </button>
    <?php endforeach; ?>
  </nav>

  <main class="crm-shell">
    <section class="crm-main" data-crm-main>
      <section class="crm-module active" data-crm-module="workspace">
        <div class="workspace-console" data-workspace-console>
          <header class="workspace-hero">
            <div>
              <span>CRM 工作台</span>
              <h1>CRM 业务控制台</h1>
              <p data-workspace-subtitle>经营指标、客户跟进、报价转化、应收风险、邮件提醒。</p>
            </div>
            <div class="workspace-toolbar">
              <label>视图<select data-workspace-role><option value="auto">按角色</option><option value="boss">老板/管理员</option><option value="manager">主管/部门</option><option value="sales">业务员</option><option value="assistant">跟单/文员</option><option value="staff">普通员工</option></select></label>
              <label>范围<select data-workspace-range><option value="today">今日</option><option value="week">本周</option><option value="month" selected>本月</option><option value="quarter">季度</option></select></label>
              <label>密度<select data-workspace-density><option value="wide">舒适</option><option value="standard">标准</option><option value="compact" selected>紧凑</option></select></label>
              <button type="button" data-workspace-refresh>刷新</button>
              <button type="button" data-workspace-customize>工作台设置</button>
            </div>
          </header>

          <section class="workspace-grid" data-workspace-grid aria-label="CRM 工作台组件"></section>
        </div>
      </section>

      <section class="crm-module" data-crm-module="customers">
        <div class="crm-module-head"><div><span>客户中心</span><h1>客户中心</h1><p>客户列表、详情、联系人、跟进、报价摘要、邮件摘要、资料摘要和联动摘要的统一布局。</p></div></div>
        <div class="crm-customer-page" data-customer-module>
          <section class="crm-panel customer-filterbar">
            <div class="customer-search-row">
              <div class="customer-search-main">
                <label>模糊搜索</label>
                <input data-customer-search placeholder="客户 / 代码 / 联系人 / 邮箱 / 电话 / WhatsApp / 国家 / 网站 / 备注">
                <button type="button" data-customer-search-clear>清空</button>
              </div>
              <div class="customer-search-tools">
                <label>每页<select data-customer-page-size><option value="20">20</option><option value="50" selected>50</option><option value="100">100</option><option value="200">200</option></select></label>
                <label>视图<select data-customer-view title="客户列表排列方式"><option value="table">表格</option><option value="compact">紧凑</option><option value="card">卡片</option></select></label>
                <label>排序<select data-customer-sort title="排序字段"><option value="updated_at">按更新</option><option value="customer_code">按代码</option><option value="customer_name">按客户</option><option value="country">按国家</option><option value="created_at">按创建</option><option value="last_followup">按跟进</option></select></label>
                <label>方向<select data-customer-dir title="排序方向"><option value="DESC">倒序</option><option value="ASC">正序</option></select></label>
                <button type="button" data-customer-search-btn>立即搜索</button>
                <button type="button" data-customer-advanced-toggle>高级筛选</button>
                <button type="button" data-customer-reset>重置</button>
                <button type="button" data-customer-import-log>导入日志</button>
              </div>
              <div class="customer-search-meta">
                <span data-customer-search-status>输入即搜，280ms 自动刷新</span>
                <strong data-customer-count>0 条</strong>
              </div>
            </div>
            <div class="customer-quick-filters">
              <?php foreach (['今天新增','7天新增','我的客户','公海客户','有客户代码','有报价','有邮件','有资料'] as $filter): ?>
              <button type="button" data-customer-filter="<?= h($filter) ?>" title="<?= h($filter) ?>"><?= h($filter) ?></button>
              <?php endforeach; ?>
            </div>
          </section>
          <aside class="customer-filter-drawer" data-customer-filter-drawer aria-hidden="true" hidden>
            <header>
              <div><span>高级筛选</span><strong>高级筛选</strong></div>
              <button type="button" data-customer-filter-close>关闭</button>
            </header>
            <div class="customer-filter-groups">
              <section class="customer-filter-group">
                <h3>地理信息</h3>
                <label>国家 / 地区
                  <select data-filter-country>
                    <option value="">全部国家</option>
                    <?php foreach (($crmConfig['items']['country_region'] ?? []) as $country): ?>
                    <?php $countryFlag = crm_country_flag((string)($country['item_key'] ?? '')) ?: crm_country_flag((string)($country['name_en'] ?? '')) ?: crm_country_flag((string)($country['name_cn'] ?? '')); ?>
                    <option value="<?= h($country['item_key']) ?>"><?= h(trim($countryFlag . ' ' . (($country['name_cn'] ?? '') . ' / ' . ($country['name_en'] ?? '') . ' · ' . ($country['item_key'] ?? '')))) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label>城市 / 地区
                  <select data-filter-city>
                    <option value="">全部城市/地区</option>
                    <?php foreach (($crmConfig['items']['city_region'] ?? []) as $region): ?>
                    <option value="<?= h($region['item_key']) ?>"><?= h(($region['name_cn'] ?? '') . ' / ' . ($region['name_en'] ?? '') . ' · ' . ($region['extra_config']['country'] ?? '')) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
              </section>
              <section class="customer-filter-group">
                <h3>客户属性</h3>
                <label>客户等级
                  <select data-filter-level>
                    <option value="">全部等级</option>
                    <?php foreach (($crmConfig['items']['customer_level'] ?? []) as $item): if ((int)($item['is_enabled'] ?? 1) !== 1) continue; ?>
                    <option value="<?= h($item['item_key']) ?>"><?= h(($item['short_name'] ?: $item['name_cn']) . ' · ' . $item['name_cn']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label>生命周期
                  <select data-filter-lifecycle>
                    <option value="">全部生命周期</option>
                    <?php foreach (($crmConfig['items']['customer_lifecycle'] ?? []) as $item): if ((int)($item['is_enabled'] ?? 1) !== 1) continue; ?>
                    <option value="<?= h($item['item_key']) ?>"><?= h($item['name_cn'] ?: $item['item_key']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label>客户状态
                  <select data-filter-status>
                    <option value="">全部状态</option>
                    <?php foreach (($crmConfig['items']['customer_status'] ?? []) as $item): if ((int)($item['is_enabled'] ?? 1) !== 1) continue; ?>
                    <option value="<?= h($item['item_key']) ?>"><?= h($item['name_cn'] ?: $item['item_key']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label>数据状态
                  <select data-filter-deleted>
                    <option value="">正常客户</option>
                    <option value="1">已删除</option>
                  </select>
                </label>
              </section>
              <section class="customer-filter-group">
                <h3>来源推广</h3>
                <div class="customer-filter-chips" data-filter-source-group>
                  <?php foreach (($crmConfig['items']['customer_source'] ?? []) as $item): if ((int)($item['is_enabled'] ?? 1) !== 1) continue; ?>
                  <label class="tag-chip"><input type="checkbox" data-filter-source value="<?= h($item['item_key']) ?>"><span><?= h($item['short_name'] ?: $item['name_cn']) ?></span></label>
                  <?php endforeach; ?>
                </div>
                <label>推广状态
                  <select data-filter-promotion-status>
                    <option value="">全部推广状态</option>
                    <?php foreach (($crmConfig['items']['promotion_status'] ?? []) as $item): if ((int)($item['is_enabled'] ?? 1) !== 1) continue; ?>
                    <option value="<?= h($item['item_key']) ?>"><?= h($item['name_cn'] ?: $item['item_key']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
              </section>
              <section class="customer-filter-group">
                <h3>负责人</h3>
                <label>负责人
                  <select data-filter-owner>
                    <option value="">全部负责人</option>
                    <?php foreach ($customerFilterUsers as $owner): ?>
                    <option value="<?= h($owner['id']) ?>"><?= h(($owner['display_name'] ?: $owner['username']) . ($owner['department_name'] ? ' · ' . $owner['department_name'] : '')) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
              </section>
              <section class="customer-filter-group">
                <h3>业务状态</h3>
                <div class="customer-filter-chips">
                  <?php foreach (['有联系人','有邮箱','有报价','有邮件','有资料'] as $filter): ?>
                  <label class="tag-chip"><input type="radio" name="customer_business_filter" data-filter-business value="<?= h($filter) ?>"><span><?= h($filter) ?></span></label>
                  <?php endforeach; ?>
                </div>
              </section>
              <section class="customer-filter-group">
                <h3>时间</h3>
                <label>创建时间
                  <select data-filter-created-range>
                    <option value="">不限</option>
                    <option value="today">今天</option>
                    <option value="7d">最近7天</option>
                    <option value="month">本月</option>
                  </select>
                </label>
                <label>跟进时间
                  <select data-filter-follow-range>
                    <option value="">不限</option>
                    <option value="today">今天跟进</option>
                    <option value="7d_missing">7天未跟进</option>
                    <option value="15d_missing">15天未跟进</option>
                    <option value="30d_missing">30天未跟进</option>
                  </select>
                </label>
              </section>
            </div>
            <footer>
              <button type="button" data-customer-filter-reset>清空高级筛选</button>
              <button type="button" data-customer-filter-apply>应用筛选</button>
            </footer>
          </aside>
          <div class="customer-filter-backdrop" data-customer-filter-backdrop hidden></div>
          <aside class="customer-lead-drawer" data-lead-pool-drawer aria-hidden="true" hidden>
            <header>
              <div><span>客户暂存池</span><strong>暂存池客户</strong></div>
              <button type="button" data-lead-pool-close>关闭</button>
            </header>
            <div class="lead-pool-toolbar" hidden>
              <label>状态
                <select data-lead-pool-status>
                  <option value="pending">待确认</option>
                  <option value="confirmed">已转正式</option>
                  <option value="merged">已关联已有</option>
                  <option value="rejected">已丢弃</option>
                </select>
              </label>
              <button type="button" data-lead-pool-refresh>刷新</button>
            </div>
            <div class="lead-pool-list" data-lead-pool-list>
              <p>正在读取暂存池...</p>
            </div>
          </aside>
          <div class="customer-lead-backdrop" data-lead-pool-backdrop hidden></div>
          <div class="crm-split crm-resizable customer-split" data-customer-split>
            <section class="crm-panel crm-list-panel customer-list-panel">
              <div class="customer-list-head">
                <strong>客户列表</strong>
                <span data-customer-selection>未选择</span>
              </div>
              <div class="customer-table-wrap"><table class="crm-table customer-table" data-customer-table><thead><tr><th><input type="checkbox" data-customer-check-all></th><th title="客户代码">代码</th><th title="客户名称">客户</th><th title="国家/地区">国家</th><th title="客户等级">等级</th><th title="客户来源">来源</th><th title="负责人">负责</th><th title="联系人数量">联系</th><th title="最近跟进">跟进</th><th title="最近邮件">邮件</th><th title="最近报价">报价</th><th title="客户状态">状态</th><th title="更新时间">更新</th><th title="操作">操作</th></tr></thead><tbody data-customer-rows><tr><td colspan="14">正在加载客户...</td></tr></tbody></table></div>
              <div class="customer-pagination"><button type="button" data-page-prev>上一页</button><span data-page-info>1 / 1</span><button type="button" data-page-next>下一页</button></div>
            </section>
            <div class="customer-resizer" data-customer-resizer></div>
            <section class="crm-panel crm-detail-panel customer-detail-panel" data-customer-detail>
              <div class="customer-empty"><strong>请选择客户</strong><span>选择左侧客户后显示客户 360、联系人、跟进、联动摘要、资料摘要和日志。</span></div>
            </section>
          </div>
        </div>
      </section>

      <section class="crm-module" data-crm-module="mail">
        <div class="crm-mail-center" data-mail-module>
          <dialog class="mail-settings-dialog crm-modal" data-mail-settings-dialog>
            <button type="button" class="mail-settings-close" data-mail-account-cancel aria-label="关闭邮箱设置">×</button>
            <div class="mail-settings-shell crm-modal-panel">
              <aside class="mail-settings-sidebar">
                <header><span>已绑定邮箱</span><strong>已绑邮箱</strong></header>
                <?php if (crm_can('mail.account_manage_all') || is_super_admin()): ?>
                <label class="mail-admin-user-select">维护员工<select data-mail-target-user>
                  <?php foreach ($customerFilterUsers as $item): ?>
                  <option value="<?= h($item['id']) ?>" <?= (int)$item['id'] === (int)$user['id'] ? 'selected' : '' ?>><?= h(($item['display_name'] ?? $item['username']) . ' / ' . ($item['department_name'] ?? '')) ?></option>
                  <?php endforeach; ?>
                </select></label>
                <?php endif; ?>
                <div class="mail-settings-account-list" data-mail-settings-account-list></div>
                <button type="button" data-mail-settings-new>+ 添加邮箱</button>
              </aside>
              <section class="mail-bind-panel" data-mail-bind-panel>
                <div class="mail-settings-intro"><span>Mail Workspace</span><h2>邮箱设置</h2><p>管理发件身份、收信连接、发信连接、签名和连接测试。</p><dl><div><dt>当前邮箱</dt><dd data-mail-settings-current-email>--</dd></div><div><dt>同步状态</dt><dd data-mail-settings-sync-status>--</dd></div></dl></div>
                <form id="mail-account-form-proxy" data-mail-account-form class="mail-account-form mail-settings-form crm-modal-grid">
                  <input type="hidden" name="mail_account_id" value="">
                  <section class="mail-settings-card wide"><header><strong>账号身份</strong><span>用于显示发件人和绑定邮箱。</span></header><div class="mail-settings-fields">
                    <label>邮箱地址<input type="email" name="email_address" required placeholder="name@artdonlighting.com"></label>
                    <label>发件人名称<input name="sender_name" placeholder="Artdon Sales"></label>
                    <label>授权码 / 密码<input type="password" name="mail_secret" required autocomplete="new-password" placeholder="首次绑定必填；已绑定后留空表示不修改"></label>
                    <label>延迟发送<input name="delay_send_minutes" type="number" min="0" max="10" value="0" placeholder="0-10 分钟"></label>
                    <label class="mail-default-check"><input type="checkbox" name="is_default" value="1"> <span>设为默认邮箱</span></label>
                  </div></section>
                  <section class="mail-settings-card"><header><strong>收信 IMAP</strong><span>同步收件箱、已发送和附件。</span></header><div class="mail-settings-fields">
                    <label>IMAP 主机<input name="imap_host" value="imap.exmail.qq.com"></label>
                    <label>IMAP 端口<input name="imap_port" type="number" value="993"></label>
                    <label>IMAP 安全<select name="imap_secure"><option value="ssl">SSL/TLS</option><option value="tls">STARTTLS</option><option value="">None</option></select></label>
                  </div></section>
                  <section class="mail-settings-card"><header><strong>发信 SMTP</strong><span>CRM 写信、回复和推广发信。</span></header><div class="mail-settings-fields">
                    <label>SMTP 主机<input name="smtp_host" value="smtp.exmail.qq.com"></label>
                    <label>SMTP 端口<input name="smtp_port" type="number" value="465"></label>
                    <label>SMTP 安全<select name="smtp_secure"><option value="ssl">SSL/TLS</option><option value="tls">STARTTLS</option><option value="">None</option></select></label>
                  </div></section>
                  <input type="hidden" name="signature_html" value="">
                  <section class="mail-signature-preview wide">
                    <header><strong>个人签名</strong><button type="button" data-mail-edit-signature>制作 / 编辑签名</button></header>
                    <div data-mail-signature-preview>未设置签名</div>
                  </section>
                </form>
                <aside class="mail-settings-tools">
                  <section><span>Quick Test</span><strong>连接测试</strong><button type="button" data-mail-template>腾讯企业邮箱模板</button><button type="button" data-mail-test-imap>测试收信</button><button type="button" data-mail-test-smtp>测试发信</button></section>
                  <section><span>Signature</span><strong>签名操作</strong><button type="button" data-mail-save-company-signature>保存为公司签名</button><button type="button" data-mail-apply-company-signature>批量应用签名</button></section>
                  <section class="danger-zone"><span>Danger</span><strong>邮箱数据</strong><p>删除邮箱会清空该邮箱同步到 CRM 的邮件和附件。</p><button type="button" class="danger" data-mail-delete-account hidden>删除当前邮箱</button></section>
                  <footer><button type="button" data-mail-account-cancel>关闭</button><button type="submit" class="primary" form="mail-account-form-proxy">保存绑定</button></footer>
                </aside>
              </section>
            </div>
          </dialog>
          <section class="mail-workbench" data-mail-workbench hidden>
            <header class="mail-toolbar">
              <button type="button" data-mail-compose>写邮件</button>
              <button type="button" data-mail-sync>收取邮件</button>
              <label class="mail-auto-sync">自动收信<select data-mail-auto-sync><option value="0">关闭</option><option value="1">1分钟</option><option value="3" selected>3分钟</option><option value="5">5分钟</option></select></label>
              <label class="mail-account-switch">邮箱<select data-mail-account-switch></select></label>
              <button type="button" data-mail-add-account>添加邮箱</button>
              <div class="mail-search"><input data-mail-search placeholder="搜索主题 / 发件人 / 收件人 / 客户 / 邮箱 / 附件 / 正文关键词"></div>
              <button type="button" data-mail-filter-toggle>筛选</button>
              <button type="button" data-mail-mark-read>标记已读</button>
              <button type="button" data-mail-mark-unread>标记未读</button>
              <button type="button" data-mail-archive>归档</button>
              <button type="button" data-mail-delete>删除</button>
              <button type="button" data-mail-settings>邮箱设置</button>
              <div class="mail-status"><strong data-mail-current-account>未绑定</strong><span data-mail-sync-status>未同步</span></div>
            </header>
            <div class="mail-filter-panel" data-mail-filter-panel hidden>
              <label>阅读状态<select data-mail-filter-read><option value="">全部</option><option value="unread">未读</option><option value="read">已读</option></select></label>
              <label>附件<select data-mail-filter-attach><option value="">全部</option><option value="has">有附件</option><option value="none">无附件</option><option value="no_body_attach">无正文有附件</option></select></label>
              <label>客户关联<select data-mail-filter-link><option value="">全部</option><option value="linked">已关联</option><option value="unlinked">未关联</option></select></label>
              <label>时间<select data-mail-filter-date><option value="">全部</option><option value="today">今天</option><option value="7d">最近7天</option><option value="month">本月</option></select></label>
              <button type="button" data-mail-filter-apply>应用筛选</button>
              <button type="button" data-mail-filter-reset>重置</button>
            </div>
            <div class="mail-grid">
              <aside class="mail-folder-pane" data-mail-folders>
                <div class="mail-account-mini"><strong data-mail-account-email>--</strong><span data-mail-account-sync>最近同步：--</span></div>
                <?php foreach ([['inbox','收件箱'],['scheduled','待发送'],['unread','未读'],['starred','星标'],['unreplied','未回复'],['important','重要'],['sent','已发送'],['drafts','草稿'],['archive','已归档'],['deleted','已删除'],['attachments','有附件'],['linked','已关联客户'],['unlinked','未关联客户'],['promotion','推广邮件'],['quote','报价邮件'],['sample','样品邮件'],['service','售后邮件']] as $folder): ?>
                <button type="button" data-mail-folder="<?= h($folder[0]) ?>"><?= h($folder[1]) ?><span data-mail-folder-count="<?= h($folder[0]) ?>"></span></button>
                <?php endforeach; ?>
              </aside>
              <section class="mail-list-pane">
                <div class="mail-quick-filters">
                  <?php foreach ([['unread','未读'],['unreplied','未回复'],['attachments','有附件'],['no_body_attach','无正文有附件'],['linked','已关联客户'],['unlinked','未关联客户'],['today','今天'],['7d','7天'],['important','重要'],['todo','待处理']] as $filter): ?>
                  <button type="button" data-mail-quick="<?= h($filter[0]) ?>"><?= h($filter[1]) ?></button>
                  <?php endforeach; ?>
                </div>
                <div class="mail-pager mail-pager-top" data-mail-pager="top">
                  <button type="button" data-mail-page-prev>上一页</button>
                  <span data-mail-page-info>第 1 / 1 页 · 共 0 封</span>
                  <button type="button" data-mail-page-next>下一页</button>
                  <label>每页<select data-mail-page-size><option value="auto">自动铺满</option><option value="20">20</option><option value="50">50</option><option value="100">100</option><option value="200">200</option></select></label>
                </div>
                <div class="mail-list" data-mail-list><p>正在加载邮件...</p></div>
                <div class="mail-pager mail-pager-bottom" data-mail-pager="bottom">
                  <button type="button" data-mail-page-prev>上一页</button>
                  <span data-mail-page-info>第 1 / 1 页 · 共 0 封</span>
                  <button type="button" data-mail-page-next>下一页</button>
                </div>
              </section>
              <section class="mail-reader-pane" data-mail-reader>
                <div class="mail-empty"><strong>请选择邮件</strong><span>单击选中，双击打开正文。搜索和刷新不会重建整个邮箱模块。</span></div>
              </section>
              <aside class="mail-crm-pane" data-mail-crm-pane>
                <header><span>客户联动</span><strong>客户联动</strong></header>
                <div data-mail-crm-content><p>选择邮件后显示客户识别、候选客户和可用邮件操作；未接入联动会标记为待接入。</p></div>
              </aside>
            </div>
          </section>
        </div>
      </section>

      <section class="crm-module" data-crm-module="promotion">
        <?php if ($crmActionPermissions['新建推广项目'] ?? false): ?>
        <div class="promotion-create-toolbar">
          <button type="button" data-promo-open-wizard>新建推广项目</button>
        </div>
        <?php endif; ?>
        <section class="promo-engine-v2" data-promo-engine>
          <main class="promo-workspace">
            <nav class="marketing-subtabs" data-promo-main-tabs>
              <button type="button" class="marketing-subtab active" data-promo-view-button="campaigns" data-marketing-subtab="projects">推广项目</button>
              <button type="button" class="marketing-subtab" data-promo-view-button="customer_pool" data-marketing-subtab="pool">客户推广池</button>
              <button type="button" class="marketing-subtab" data-promo-view-button="contact_strategy" data-marketing-subtab="contacts">联系人策略</button>
              <button type="button" class="marketing-subtab" data-promo-view-button="execution" data-marketing-subtab="execution">执行中心</button>
              <button type="button" class="marketing-subtab" data-promo-view-button="analytics" data-marketing-subtab="analytics">效果分析</button>
              <button type="button" class="marketing-subtab" data-promo-view-button="settings" data-marketing-subtab="settings">推广设置</button>
              <?php if ($crmActionPermissions['新建推广项目'] ?? false): ?><button type="button" class="marketing-subtab promo-subtab-action" data-promo-open-wizard>新建推广项目</button><?php endif; ?>
            </nav>
            <section class="promo-view" data-promo-view="dashboard">
              <div class="promo-board-head"><div><span>营销控制台</span><h2>营销总览</h2><p>从推广状态、渠道效果、任务执行和风险客户四个维度管理推广。</p></div><div><?php if ($crmActionPermissions['新建推广项目'] ?? false): ?><button type="button" data-promo-open-wizard>新建推广项目</button><?php endif; ?><button type="button" data-promo-refresh>刷新</button></div></div>
              <div class="promo-kpi-grid" data-promo-analytics><p>正在加载转化数据...</p></div>
              <div class="promo-linkage-grid">
                <article><strong>邮件联动</strong><span>推广邮件、未回复、附件资料发送</span><em>执行记录</em></article>
                <article><strong>资料联动</strong><span>资料包生成、发送、下载统计</span><em>资料计划</em></article>
                <article><strong>报价联动</strong><span>推广后报价、报价转订单</span><em>报价跟进</em></article>
                <article><strong>派工联动</strong><span>推广跟进任务和异常处理</span><em>异常派工</em></article>
              </div>
            </section>
            <section class="promo-view active" data-promo-view="campaigns">
              <section class="promo-task-list promo-task-main-list">
                <header><span>推广项目</span><strong>推广项目列表</strong><?php if ($crmActionPermissions['新建推广项目'] ?? false): ?><button type="button" data-promo-open-wizard>新建</button><?php endif; ?></header>
                <div data-promo-tasks><p>正在加载推广任务...</p></div>
              </section>
              <section class="promo-task-list promo-task-properties">
                <header><span>任务属性</span><strong>任务属性</strong></header>
                <div data-promo-task-properties><p class="promo-empty">请从推广项目列表选择一个任务。</p></div>
              </section>
            </section>
            <section class="promo-view promo-wizard-view" data-promo-view="wizard">
              <div data-promo-wizard-host><p class="promo-empty">请从右侧 ACTIONS 创建推广项目。</p></div>
            </section>
            <section class="promo-view" data-promo-view="customer_pool">
              <section class="promo-pool-filter-panel">
                <div class="promo-pool-search-row">
                  <label><span>搜索</span><input data-promo-search placeholder="客户名 / 客户代码 / 国家 / 负责人 / 邮箱 / 域名"></label>
                  <label><span>客户组</span><select data-promo-group-select><option value="">全部客户组</option><option value="0">未分组</option></select></label>
                  <label><span>国家</span><input data-promo-country placeholder="国家 / 地区"></label>
                  <label><span>推广状态</span><select data-promo-status>
                    <option value="">全部状态</option>
                    <?php foreach (($crmConfig['items']['promotion_status'] ?? []) as $item): if ((int)($item['is_enabled'] ?? 1) !== 1) continue; ?>
                      <option value="<?= h($item['item_key']) ?>"><?= h($item['name_cn']) ?></option>
                    <?php endforeach; ?>
                  </select></label>
                  <label><span>客户等级</span><input data-promo-level placeholder="P0 / P1 / P3"></label>
                  <label><span>负责人</span><select data-promo-owner><option value="">全部负责人</option></select></label>
                  <label><span>邮箱</span><select data-promo-has-email><option value="">全部</option><option value="1">有邮箱</option><option value="0">无邮箱</option></select></label>
                  <label><span>联系人</span><select data-promo-has-contact><option value="">全部</option><option value="1">有联系人</option><option value="0">无联系人</option></select></label>
                </div>
                <div class="promo-pool-quick-filters">
                  <button type="button" data-promo-quick-filter="all">全部客户</button>
                  <button type="button" data-promo-quick-filter="my">我的客户</button>
                  <button type="button" data-promo-quick-filter="ungrouped">未分组</button>
                  <button type="button" data-promo-quick-filter="not_promoted">未推广</button>
                  <button type="button" data-promo-quick-filter="promoting">推广中</button>
                  <button type="button" data-promo-quick-filter="no_promotion">不推广</button>
                  <button type="button" data-promo-quick-filter="blacklist">黑名单</button>
                  <button type="button" data-promo-quick-filter="has_email">有邮箱</button>
                  <button type="button" data-promo-quick-filter="no_email">无邮箱</button>
                </div>
              </section>
              <section class="promo-group-panel" data-promo-group-home hidden>
                <header><span>推广客户分组</span><strong>推广客户分组</strong></header>
                <div class="promo-group-list" data-promo-groups><p>正在加载推广分组...</p></div>
              </section>
              <div class="promo-split-grid promo-pool-grid-full">
                <section class="promo-pool-panel">
                  <header><span>客户推广池</span><strong>客户推广池</strong><em data-promo-pool-selection>未选择客户</em></header>
                  <div class="promo-pool-pager" data-promo-pool-pager="top"><span>正在读取客户分页...</span></div>
                  <div class="promo-pool-list" data-promo-pool><p>正在加载客户推广池...</p></div>
                  <div class="promo-pool-pager" data-promo-pool-pager="bottom"><span>正在读取客户分页...</span></div>
                </section>
              </div>
            </section>
            <section class="promo-view" data-promo-view="contact_strategy">
              <div class="promo-board-head"><div><span>Contact Strategy</span><h2>联系人策略</h2><p>筛选、检查和管理联系人级推广规则；创建推广项目时会按这些规则生成目标联系人、邮件队列和人工执行清单。</p></div></div>
              <section class="promo-contact-filter-panel">
                <div class="promo-pool-search-row">
                  <label><span>搜索</span><input data-promo-contact-search placeholder="客户名 / 联系人 / 邮箱 / WhatsApp / 国家 / 负责人"></label>
                  <label><span>客户组</span><select data-promo-contact-group><option value="">全部客户组</option><option value="0">未分组</option></select></label>
                  <label><span>国家</span><input data-promo-contact-country placeholder="国家 / 地区"></label>
                  <label><span>联系人角色</span><select data-promo-contact-role><option value="">全部角色</option><option value="decision_maker">老板 / 决策人</option><option value="buyer">采购</option><option value="engineer">工程师</option><option value="finance">财务</option><option value="project_owner">项目负责人</option><option value="middleman">普通联系人</option><option value="uncategorized">未分类</option></select></label>
                  <label><span>推广状态</span><select data-promo-contact-status><option value="">全部状态</option><option value="promotable">可推广</option><option value="no_promotion">不推广</option><option value="blacklist">黑名单</option><option value="left">已离职</option><option value="invalid_email">邮箱无效</option><option value="manual">可转人工</option></select></label>
                  <label><span>邮箱状态</span><select data-promo-contact-email-status><option value="">全部</option><option value="has">有邮箱</option><option value="none">无邮箱</option><option value="invalid">无效邮箱</option></select></label>
                  <label><span>WhatsApp 状态</span><select data-promo-contact-whatsapp-status><option value="">全部</option><option value="has">有 WhatsApp</option><option value="none">无 WhatsApp</option><option value="invalid">WhatsApp 无效</option></select></label>
                  <label><span>主联系人</span><select data-promo-contact-primary><option value="">全部</option><option value="1">主联系人</option><option value="0">非主联系人</option></select></label>
                </div>
                <div class="promo-pool-quick-filters">
                  <button type="button" data-promo-contact-quick="all">全部联系人</button>
                  <button type="button" data-promo-contact-quick="promotable">可推广联系人</button>
                  <button type="button" data-promo-contact-quick="primary">主联系人</button>
                  <button type="button" data-promo-contact-quick="no_email">无邮箱联系人</button>
                  <button type="button" data-promo-contact-quick="invalid_email">无效邮箱</button>
                  <button type="button" data-promo-contact-quick="no_promotion">不推广联系人</button>
                  <button type="button" data-promo-contact-quick="blacklist">黑名单联系人</button>
                  <button type="button" data-promo-contact-quick="recent_reply">最近已回复</button>
                  <button type="button" data-promo-contact-quick="uncategorized">未分配角色</button>
                </div>
              </section>
              <section class="promo-contact-stat-grid" data-promo-contact-stats></section>
              <section class="promo-contact-panel">
                <header><span>联系人策略</span><strong>联系人策略列表</strong><em data-promo-contact-selection>未选择联系人</em></header>
                <div class="promo-contact-list" data-promo-contacts><p class="promo-empty">正在加载联系人策略...</p></div>
              </section>
              <section class="promo-contact-preview-grid">
                <article class="promo-contact-preview-card">
                  <header><span>规则预览</span><strong>本次筛选结果</strong></header>
                  <div data-promo-contact-preview><p class="promo-empty">选择客户组或筛选条件后显示策略结果，不会发送邮件。</p></div>
                </article>
                <article class="promo-contact-preview-card">
                  <header><span>跳过原因</span><strong>跳过原因统计</strong></header>
                  <div data-promo-contact-skip-reasons><p class="promo-empty">暂无跳过原因。</p></div>
                </article>
              </section>
            </section>
            <section class="promo-view" data-promo-view="group_management">
              <div class="promo-board-head"><div><span>Customer Group Management</span><h2>客户组管理</h2><p>客户组管理在中间区域完成；客户组操作统一从右侧 ACTIONS 触发。</p></div></div>
              <section class="promo-group-manage-filter">
                <div class="promo-pool-search-row">
                  <label><span>搜索</span><input data-promo-group-search placeholder="客户组名称 / 标签 / 国家 / 负责人"></label>
                  <label><span>状态</span><select data-promo-group-status><option value="">全部</option><option value="active">正常</option><option value="disabled">停用</option><option value="archived">已归档</option></select></label>
                  <label><span>类型</span><select data-promo-group-type><option value="">全部</option><option value="normal">普通组</option><option value="country">国家组</option><option value="exhibition">展会组</option><option value="key_account">重点客户组</option><option value="temporary">临时组</option></select></label>
                  <label><span>负责人</span><select data-promo-group-owner><option value="">全部负责人</option></select></label>
                  <label><span>创建时间</span><input type="date" data-promo-group-created-from></label>
                </div>
                <div class="promo-pool-quick-filters">
                  <button type="button" data-promo-group-quick="all">全部客户组</button>
                  <button type="button" data-promo-group-quick="my">我的客户组</button>
                  <button type="button" data-promo-group-quick="key_account">重点客户组</button>
                  <button type="button" data-promo-group-quick="unused">未使用客户组</button>
                  <button type="button" data-promo-group-quick="recent">最近使用</button>
                  <button type="button" data-promo-group-quick="archived">已归档</button>
                </div>
              </section>
              <section class="promo-group-management">
                <header><span>客户组列表</span><strong>客户组列表</strong><em data-promo-group-selection>未选择客户组</em></header>
                <div class="promo-pool-pager" data-promo-group-pager="top"><span>正在读取客户组...</span></div>
                <div data-promo-group-table><p class="promo-empty">正在加载客户组...</p></div>
                <div class="promo-pool-pager" data-promo-group-pager="bottom"><span>正在读取客户组...</span></div>
              </section>
              <section class="promo-group-member-preview">
                <header><span>成员预览</span><strong data-promo-group-member-title>请选择客户组</strong></header>
                <div data-promo-group-members><p class="promo-empty">选中客户组后显示前 20 个客户成员。</p></div>
              </section>
            </section>
            <section class="promo-view" data-promo-view="execution">
              <div class="promo-board-head"><div><span>Execution Center</span><h2>推广执行中心</h2><p>执行任务、记录触达、查看失败原因，并把结果写入客户流水。</p></div></div>
              <section class="promo-execution-center" data-promo-execution-center>
                <nav class="promo-execution-tabs">
                  <button type="button" class="active" data-promo-execution-tab="mail_queue">邮件发送队列</button>
                  <button type="button" data-promo-execution-tab="manual">人工执行清单</button>
                  <button type="button" data-promo-execution-tab="failures">失败处理</button>
                  <button type="button" data-promo-execution-tab="logs">执行日志</button>
                </nav>
                <div data-promo-execution-content><p class="promo-empty">正在加载推广执行中心...</p></div>
              </section>
              <div data-promo-failures hidden></div>
              <div data-promo-logs hidden></div>
            </section>
            <section class="promo-view" data-promo-view="analytics">
              <div class="promo-board-head"><div><span>Conversion Analytics</span><h2>推广效果分析</h2><p>按状态、渠道、国家、任务、成功/失败结果统计推广转化。</p></div></div>
              <div class="promo-analytics-grid" data-promo-analytics-detail><p>正在加载转化数据...</p></div>
            </section>
            <section class="promo-view" data-promo-view="settings">
              <section class="promo-settings-panel" data-promo-settings>
                <header><span>推广设置</span><strong>推广默认规则</strong><em data-promo-settings-status>未保存</em></header>
                <div class="promo-settings-grid">
                  <label><span>邮件发送上限</span><input type="number" min="1" data-promo-setting="mail_limit" value="200"></label>
                  <label><span>每小时上限</span><input type="number" min="1" data-promo-setting="hourly_limit" value="50"></label>
                  <label><span>每日上限</span><input type="number" min="1" data-promo-setting="daily_limit" value="200"></label>
                  <label><span>跳过周末</span><select data-promo-setting="skip_weekend"><option value="1">是</option><option value="0">否</option></select></label>
                  <label><span>按客户国家工作时间</span><select data-promo-setting="country_work_time"><option value="1">是</option><option value="0">否</option></select></label>
                  <label><span>默认联系人策略</span><select data-promo-setting="contact_strategy"><option value="primary">只发主联系人</option><option value="all_valid">全部可推广联系人</option><option value="max_per_customer">每客户最多 N 个</option></select></label>
                  <label><span>默认失败处理</span><select data-promo-setting="failure_policy"><option value="mark_failed">进入失败处理</option><option value="retry">自动重试</option><option value="manual">转人工确认</option></select></label>
                  <label><span>默认人工执行规则</span><select data-promo-setting="manual_rule"><option value="owner">按客户负责人</option><option value="creator">当前创建人</option><option value="balanced">多人平均分配</option></select></label>
                  <label><span>默认发件邮箱规则</span><select data-promo-setting="mail_rule"><option value="owner_mailbox">按客户负责人</option><option value="group_by_country">按国家</option><option value="balanced">多邮箱平均分配</option></select></label>
                  <label><span>客户组可见性</span><select data-promo-setting="group_visibility"><option value="public">公开</option><option value="private">仅本人</option><option value="assigned">指定人员</option></select></label>
                </div>
              </section>
            </section>
          </main>
        </section>
      </section>

      <section class="crm-module" data-crm-module="tasks">
        <div class="crm-module-head"><div><span>Tasks</span><h1>任务与提醒中心</h1><p>统一管理客户跟进、报价订单流程、推广执行、AI 待确认、样品寄送和逾期处理。</p></div></div>
        <section class="task-center-console" data-task-center>
          <main class="task-main">
            <header class="task-toolbar">
              <div><strong data-task-title>我的任务</strong><span data-task-subtitle>点击任务或样品寄送后，右侧 ACTIONS 会显示可用操作。</span></div>
              <label class="task-search"><input data-task-search placeholder="搜索客户 / 联系人 / 商机 / 标题 / 快递单号 / 型号 / 负责人"></label>
            </header>
            <section class="task-kpis" data-task-kpis>
              <article><strong>0</strong><span>今日必须处理</span></article>
              <article><strong>0</strong><span>已逾期</span></article>
              <article><strong>0</strong><span>待确认</span></article>
              <article><strong>0</strong><span>样品待寄出</span></article>
              <article><strong>0</strong><span>已签收未跟进</span></article>
              <article><strong>0</strong><span>AI 待确认</span></article>
              <article><strong>0</strong><span>报价订单流程</span></article>
              <article><strong>0</strong><span>商机待推进</span></article>
            </section>
            <section class="task-quick-groups" data-task-quick-groups></section>
            <section class="task-board task-workbench-board" data-task-workbench>
              <div class="task-list-panel task-queue-panel">
                <header><span data-task-queue-title>工作队列</span><button type="button" data-task-refresh>刷新</button></header>
                <div class="task-list" data-task-list><p>正在加载任务...</p></div>
              </div>
              <div class="task-list-panel task-detail-panel" data-task-detail-panel>
                <header><span>任务详情 / ACTIONS</span><div><button type="button" data-task-detail-toggle>展开</button><button type="button" data-sample-refresh>刷新</button></div></header>
                <div class="task-detail" data-task-detail>
                  <div class="task-empty">请选择左侧任务。未选择时，这里会显示今天必须处理、逾期、样品待寄出、AI 待确认等处理提示。</div>
                </div>
              </div>
            </section>
          </main>
        </section>
      </section>
      <section class="crm-module" data-crm-module="visits">
        <div class="crm-module-head"><div><span>Visits</span><h1>拜访 / 来访</h1><p>客户外出拜访、来访接待、结果记录、后续跟进、报价、资料和派工入口。</p></div></div>
        <section class="crm-visit-console" data-visit-module>
          <main class="visit-main">
            <header class="visit-toolbar">
              <div><strong data-visit-title>拜访计划</strong><span data-visit-subtitle>点击记录后，右侧 ACTIONS 会切换为该记录的操作。</span></div>
              <div class="visit-toolbar-actions">
                <button type="button" class="active" data-visit-filter="">全部</button>
                <button type="button" data-visit-filter="today">今天</button>
                <button type="button" data-visit-filter="week">本周</button>
                <button type="button" data-visit-filter="pending_confirm">待确认</button>
                <button type="button" data-visit-filter="overdue_result">待填结果</button>
              </div>
            </header>
            <section class="visit-kpis" data-visit-kpis></section>
            <div class="visit-nav">
              <button type="button" class="active" data-visit-view="visits">拜访计划</button>
              <button type="button" data-visit-view="arrivals">来访接待</button>
              <button type="button" data-visit-view="outside">外出记录</button>
              <button type="button" data-visit-view="report">拜访报表</button>
            </div>
            <section class="visit-list" data-visit-list><p>正在加载拜访 / 来访记录...</p></section>
          </main>
        </section>
      </section>
      <section class="crm-module" data-crm-module="opportunities">
        <div class="crm-module-head"><div><span>Opportunities</span><h1>商机中心</h1><p>从客户需求到报价、样品、订单的销售机会管理。</p></div></div>
        <section class="opportunity-console" data-opportunity-module>
          <header class="opportunity-toolbar">
            <div>
              <strong data-opportunity-title>商机列表</strong>
              <span data-opportunity-subtitle>点击商机后，右侧 ACTIONS 会切换为当前商机操作。</span>
            </div>
            <div class="opportunity-search">
              <input data-opportunity-search placeholder="搜索商机 / 客户 / 联系人 / 国家 / 型号 / 项目">
              <select data-opportunity-filter>
                <option value="">全部商机</option>
                <option value="my">我的商机</option>
                <option value="month_close">本月预计成交</option>
                <option value="overdue_follow">逾期未跟进</option>
                <option value="quoted">报价订单流程</option>
                <option value="sampling">样品中</option>
                <option value="large">大金额商机</option>
                <option value="won">赢单</option>
                <option value="lost">输单</option>
              </select>
            </div>
          </header>
          <section class="opportunity-kpis" data-opportunity-kpis></section>
          <section class="opportunity-view-switch" data-opportunity-view-switch>
            <button type="button" class="active" data-opportunity-view="list">列表视图</button>
            <button type="button" data-opportunity-view="kanban">阶段看板</button>
            <button type="button" data-opportunity-view="forecast">金额预测</button>
          </section>
          <section class="opportunity-board" data-opportunity-board><p>正在加载商机...</p></section>
        </section>
      </section>

      <section class="crm-module" data-crm-module="linkage">
        <div class="crm-module-head"><div><span>业务联动中心</span><h1>联动中心</h1><p>统一汇总客户相关报价、PLM、BOM、派工、命名、订单、对账，不替代外部系统。</p></div></div>
        <div class="crm-subtabs linkage-tabs" data-linkage-tabs><?php foreach (crm_linkage_tabs() as $key => $label): ?><button type="button" data-linkage-tab="<?= h($key) ?>"><?= h($label) ?></button><?php endforeach; ?></div>
        <section class="crm-panel" data-linkage-panel><div class="linkage-empty">正在读取联动数据...</div></section>
      </section>

      <section class="crm-module" data-crm-module="materials">
        <div class="crm-module-head"><div><span>Material Generator</span><h1>资料生成系统</h1><p>客户可见资料包、PLM 资料转换、模板、发送记录和空间统计框架。</p></div></div>
        <div class="crm-subtabs is-static"><?php foreach (crm_material_tabs() as $tab): ?><span><?= h($tab) ?> · 待接入</span><?php endforeach; ?></div>
        <div class="crm-two-col"><section class="crm-panel"><h2>资料包流程</h2><div class="crm-flow"><span>选择客户</span><span>选择产品</span><span>选择来源</span><span>预览</span><span>生成/发送</span></div></section><section class="crm-panel"><h2>客户可见规则</h2><p>允许产品图、尺寸图、参数、配光曲线、测试报告、安装说明；隐藏 BOM 成本、供应商、利润、内部问题和员工备注。</p></section></div>
      </section>

      <section class="crm-module" data-crm-module="analytics"><div class="crm-module-head"><div><span>Analytics</span><h1>数据分析</h1><p>客户增长、跟进、邮件、报价、推广、资料生成和在线员工统计框架。</p></div></div><section class="crm-panel crm-placeholder-grid"><div>客户增长</div><div>跟进统计</div><div>邮件统计</div><div>资料生成统计</div></section></section>
      <section class="crm-module" data-crm-module="logs">
        <div class="crm-module-head">
          <div><span>CRM Logs</span><h1>日志中心</h1><p>集中查看登录、操作、客户、邮件、推广、任务、商机、AI、设置和安全日志。</p></div>
        </div>
        <section class="crm-panel log-center-panel" data-log-center>
          <div class="log-filterbar">
            <label>日志类型<select data-log-filter="log_type"><option value="all">全部日志</option></select></label>
            <label>模块<select data-log-filter="module"><option value="">全部模块</option><option value="customer">客户</option><option value="customers">客户中心</option><option value="mail">邮箱</option><option value="promotion">推广</option><option value="tasks">任务</option><option value="visit">拜访/来访</option><option value="opportunity">商机</option><option value="ai">AI</option><option value="settings">设置</option><option value="permission">权限</option><option value="login">登录</option></select></label>
            <label>结果<select data-log-filter="result"><option value="">全部结果</option><option value="success">成功</option><option value="failed">失败</option></select></label>
            <label>操作人<select data-log-filter="user_id"><option value="">全部人员</option></select></label>
            <label>开始日期<input type="date" data-log-filter="date_from"></label>
            <label>结束日期<input type="date" data-log-filter="date_to"></label>
            <label class="log-search">搜索<input type="search" data-log-filter="keyword" placeholder="模块 / 动作 / 对象 / IP / 内容"></label>
            <button type="button" data-log-refresh>刷新</button>
            <button type="button" data-log-export>导出当前结果</button>
          </div>
          <div class="log-kpi-grid" data-log-kpis>
            <article><strong>0</strong><span>总日志</span></article>
            <article><strong>0</strong><span>今日</span></article>
            <article><strong>0</strong><span>失败</span></article>
            <article><strong>0</strong><span>高危</span></article>
          </div>
          <div class="log-table-wrap">
            <table class="crm-table log-center-table" data-crm-log-table>
              <thead><tr><th>时间</th><th>类型</th><th>模块</th><th>动作</th><th>对象</th><th>操作人</th><th>结果</th><th>详情</th><th>来源</th></tr></thead>
              <tbody><tr><td colspan="9">正在加载日志...</td></tr></tbody>
            </table>
          </div>
          <footer class="log-pager"><span data-log-total>共 0 条</span><div><button type="button" data-log-prev>上一页</button><span data-log-page>1 / 1</span><button type="button" data-log-next>下一页</button></div></footer>
        </section>
      </section>

      <section class="crm-module" data-crm-module="ai">
        <section class="ai-center-shell" data-ai-module>
          <nav class="ai-center-nav" aria-label="AI中心菜单">
            <button type="button" class="active" data-ai-center-view="radar">客户雷达</button>
            <button type="button" data-ai-center-view="quote">智能报价</button>
            <button type="button" data-ai-center-view="material">智能资料</button>
            <button type="button" data-ai-center-view="tasks">AI任务</button>
            <button type="button" data-ai-center-view="records">运行记录</button>
            <button type="button" data-ai-center-view="settings">AI设置</button>
          </nav>
          <main class="ai-center-main">
            <section class="radar-shell" data-radar-module>
              <section class="radar-content">
                <div data-radar-content><p class="promo-empty">正在加载客户雷达...</p></div>
              </section>
            </section>
            <section class="ai-pending-panel" data-ai-pending hidden>
              <strong data-ai-pending-title>功能待接入</strong>
              <p>该 AI 中心功能将在后续阶段接入。本步骤只建立客户雷达底座。</p>
            </section>
          </main>
        </section>
      </section>

      <section class="crm-module" data-crm-module="settings">
        <div class="crm-module-head"><div><span>CRM Control Center</span><h1>企业级 CRM 控制中心</h1><p>统一管理外观、企业信息、模块、字段、流程、权限、AI、邮箱、推广、商机和数据规则。</p></div></div>
        <section class="crm-settings-console is-preview-collapsed" data-settings-console>
          <aside class="crm-settings-nav" aria-label="CRM 设置分类">
            <button type="button" class="active" data-settings-nav="overview"><strong>控制总览</strong><span>配置状态 / 快速入口</span></button>
            <button type="button" data-settings-nav="personalization"><strong>个性化设置</strong><span>主题 / 字体 / 密度 / 布局</span></button>
            <button type="button" data-settings-nav="company"><strong>企业信息</strong><span>公司抬头 / LOGO / 默认值</span></button>
            <button type="button" data-settings-nav="dashboard"><strong>工作台设置</strong><span>组件 / 老板 / 员工视图</span></button>
            <button type="button" data-settings-nav="customer_center"><strong>客户中心设置</strong><span>Tab / 字段 / 客户规则</span></button>
            <button type="button" data-settings-nav="mail_settings"><strong>邮箱设置</strong><span>绑定 / 签名 / 收信规则</span></button>
            <button type="button" data-settings-nav="promotion_settings"><strong>推广中心设置</strong><span>渠道 / 黑名单 / 联系人规则</span></button>
            <button type="button" data-settings-nav="opportunity_settings"><strong>商机设置</strong><span>阶段 / 概率 / 赢输原因</span></button>
            <button type="button" data-settings-nav="quote_flow_settings"><strong>报价流程设置</strong><span>审核 / 未回复 / 收款 / 出货 / 单证</span></button>
            <button type="button" data-settings-nav="ai_settings"><strong>AI 设置</strong><span>获客 / 报价 / 资料 / 安全</span></button>
            <button type="button" data-settings-nav="task_settings"><strong>任务 / 派工</strong><span>状态 / 提醒 / 超期规则</span></button>
            <button type="button" data-settings-nav="permissions"><strong>权限设置</strong><span>用户 / 角色 / 数据范围</span></button>
            <button type="button" data-settings-nav="dictionary"><strong>字典配置</strong><span>国家 / 渠道 / 阶段 / 原因</span></button>
            <button type="button" data-settings-nav="fields"><strong>字段配置</strong><span>客户 / 联系人 / 商机字段</span></button>
            <button type="button" data-settings-nav="top_menu"><strong>顶部菜单</strong><span>一级入口 / 顺序 / 下沉</span></button>
            <button type="button" data-settings-nav="logs"><strong>日志中心</strong><span>操作 / 安全 / 异常</span></button>
          </aside>
          <div class="crm-settings-content">
            <section class="crm-panel settings-section active" data-settings-section="overview">
              <div class="crm-config-head">
                <div><span>Control Center</span><h2>设置中心</h2><p>右侧分类菜单用于切换设置模块，页面内容保持在左侧主工作区。</p></div>
              </div>
              <div class="settings-status-grid">
                <article><strong data-preview-enabled-count>--</strong><span>启用 Tab</span></article>
                <article><strong data-preview-visible-count>--</strong><span>客户详情可见</span></article>
                <article><strong data-preview-dictionary-count><?= h(count($crmConfig['types'] ?? [])) ?></strong><span>字典类型</span></article>
                <article><strong><?= h($prefs['density_mode'] ?? 'compact') ?></strong><span>当前密度</span></article>
              </div>
              <div class="settings-directory">
                <?php foreach ([
                  ['personalization','个性化设置','主题、字体、密度、按钮和布局','界面'],
                  ['company','企业信息','公司抬头、LOGO、默认国家、语言和币种','基础'],
                  ['dashboard','工作台设置','组件、老板视图、员工视图和首页布局','界面'],
                  ['customer_center','客户中心设置','客户详情 Tab、字段、查重和客户规则','客户'],
                  ['mail_settings','邮箱设置','邮箱绑定、签名、附件、收信和自动关联','邮箱'],
                  ['promotion_settings','推广中心设置','渠道、黑名单、联系人执行规则和冷却期','推广'],
                  ['opportunity_settings','商机设置','阶段、概率、赢单、输单和预测规则','商机'],
                  ['quote_flow_settings','报价流程设置','审核、未回复、收款、出货和单证节点','报价'],
                  ['ai_settings','AI 设置','获客、报价、资料、确认流和安全规则','AI'],
                  ['task_settings','任务 / 派工','状态、提醒、截止、超期和派工接口','任务'],
                  ['permissions','权限设置','用户、角色、数据范围和字段权限','权限'],
                  ['dictionary','字典配置','国家、渠道、阶段、原因和业务下拉','配置'],
                  ['fields','字段配置','客户、联系人、商机和规则 JSON','字段'],
                  ['top_menu','顶部菜单','一级入口、顺序、显示和下沉规则','导航'],
                  ['logs','日志中心','操作、安全、异常和业务对象日志','日志'],
                ] as $row): ?>
                <button type="button" data-settings-jump="<?= h($row[0]) ?>"><em><?= h($row[3]) ?></em><strong><?= h($row[1]) ?></strong><span><?= h($row[2]) ?></span></button>
                <?php endforeach; ?>
              </div>
            </section>

            <section class="crm-panel settings-section" data-settings-section="company">
              <div class="crm-config-head">
                <div><span>Enterprise Profile</span><h2>企业信息</h2><p>统一影响 CRM 首页、邮件签名、报价、资料、PDF/Excel 抬头和系统顶部品牌。</p></div>
              </div>
              <form class="settings-company-form" data-company-settings-form>
                <section class="settings-form-card">
                  <h3>公司抬头</h3>
                  <label>系统名称<input name="system_name" value="<?= h($companySettings['system_name'] ?? '') ?>"></label>
                  <label>公司中文名<input name="company_name" value="<?= h($companySettings['company_name'] ?? '') ?>"></label>
                  <label>公司英文名<input name="company_name_en" value="<?= h($companySettings['company_name_en'] ?? '') ?>"></label>
                  <label>公司简称<input name="company_short_name" value="<?= h($companySettings['company_short_name'] ?? '') ?>"></label>
                  <label class="wide">首页副标题<textarea name="portal_subtitle" rows="2"><?= h($companySettings['portal_subtitle'] ?? '') ?></textarea></label>
                </section>
                <section class="settings-form-card">
                  <h3>联系信息</h3>
                  <label class="wide">公司地址<input name="company_address" value="<?= h($companySettings['company_address'] ?? '') ?>"></label>
                  <label>电话<input name="company_phone" value="<?= h($companySettings['company_phone'] ?? '') ?>"></label>
                  <label>邮箱<input name="company_email" value="<?= h($companySettings['company_email'] ?? '') ?>"></label>
                  <label>网站<input name="company_website" value="<?= h($companySettings['company_website'] ?? '') ?>"></label>
                  <label>默认国家<input name="default_country" value="<?= h($companySettings['default_country'] ?? 'CN') ?>"></label>
                  <label>默认语言<input name="default_language" value="<?= h($companySettings['default_language'] ?? 'zh-CN') ?>"></label>
                  <label>默认币种<input name="default_currency" value="<?= h($companySettings['default_currency'] ?? 'USD') ?>"></label>
                </section>
                <section class="settings-form-card">
                  <h3>LOGO 路径</h3>
                  <label>公司 LOGO<input name="company_logo" placeholder="uploads/logo/company.png" value="<?= h($companySettings['company_logo'] ?? '') ?>"></label>
                  <label>登录页 LOGO<input name="login_logo" placeholder="uploads/logo/login.png" value="<?= h($companySettings['login_logo'] ?? '') ?>"></label>
                  <label>顶部 LOGO<input name="topbar_logo" placeholder="uploads/logo/topbar.png" value="<?= h($companySettings['topbar_logo'] ?? '') ?>"></label>
                  <p class="entry-muted wide">本阶段先保存 LOGO 路径，文件上传入口后续接 Office 设置上传服务；不会假装上传成功。</p>
                </section>
                <div class="display-savebar"><span data-company-save-hint>企业信息会写入 crm_system_settings，并记录设置日志。</span><button type="submit">保存企业信息</button></div>
              </form>
            </section>

            <section class="crm-panel crm-tab-settings settings-section" data-settings-section="customer_center" data-customer-tab-settings>
              <div class="crm-config-head">
                <div><span>Customer Center</span><h2>客户中心设置</h2><p>管理客户详情 Tab、客户字段、联系人字段、权限要求和客户规则。</p></div>
                <button type="button" data-customer-tab-save>保存 Tab 配置</button>
              </div>
              <div class="settings-control-map compact">
                <article><strong>客户 Tab 管理</strong><span>显示/隐藏、排序、权限控制、生命周期。</span></article>
                <article><strong>客户字段配置</strong><span>字段显示、排序、必填、隐藏、字段权限。</span></article>
                <article><strong>客户规则</strong><span>查重、暂存池、负责人、公海、风险提示。</span></article>
                <article><strong>联系人规则</strong><span>第一联系人、联系人推广第一权重、群关联。</span></article>
              </div>
              <div class="crm-tab-settings-list" data-customer-tab-list></div>
            </section>

            <section class="crm-panel settings-section" data-settings-section="personalization">
              <div class="crm-config-head">
                <div><span>Display System</span><h2>显示与主题</h2><p>调整后立即应用当前页面，保存后下次登录保持。</p></div>
              </div>
              <form class="crm-preferences" data-preferences-form>
                <div class="display-control-grid">
                  <section class="display-control-panel">
                    <div class="display-control-head"><strong>主题外观</strong><span>实时切换预览</span></div>
                    <label class="display-select">主题<select name="theme_name"><?php foreach (['compact-light'=>'默认白色紧凑','dark'=>'暗黑','office-gray'=>'灰白办公','blue-gray'=>'蓝灰商务','mono'=>'黑白极简','glass'=>'玻璃拟态','eye-care'=>'低对比护眼','high-contrast'=>'高对比清晰','deep-tech'=>'深蓝科技','soft-light'=>'浅色柔和'] as $key=>$label): ?><option value="<?= h($key) ?>" <?= $prefs['theme_name']===$key?'selected':'' ?>><?= h($label) ?></option><?php endforeach; ?></select></label>
                    <label class="display-select">密度<select name="density_mode"><option value="ultra">极紧凑</option><option value="compact" <?= $prefs['density_mode']==='compact'?'selected':'' ?>>紧凑</option><option value="standard">标准</option><option value="comfortable">舒适</option></select></label>
                    <label class="display-select">动画<select name="animation_mode"><option value="off">关闭</option><option value="subtle" <?= $prefs['animation_mode']==='subtle'?'selected':'' ?>>轻微</option><option value="standard">标准</option></select></label>
                    <label class="display-select">选项卡显示<select name="tab_label_mode"><option value="icon_short" <?= ($prefs['tab_label_mode'] ?? 'icon_short')==='icon_short'?'selected':'' ?>>图标 + 简写</option><option value="short" <?= ($prefs['tab_label_mode'] ?? '')==='short'?'selected':'' ?>>简写</option><option value="full" <?= ($prefs['tab_label_mode'] ?? '')==='full'?'selected':'' ?>>完整名称</option><option value="icon" <?= ($prefs['tab_label_mode'] ?? '')==='icon'?'selected':'' ?>>仅图标</option></select></label>
                    <label class="display-select">按钮风格<select name="button_style"><option value="rounded" <?= (($prefs['module_layout']['button_style'] ?? 'rounded') === 'rounded') ? 'selected' : '' ?>>圆角</option><option value="square" <?= (($prefs['module_layout']['button_style'] ?? '') === 'square') ? 'selected' : '' ?>>方形</option><option value="pill" <?= (($prefs['module_layout']['button_style'] ?? '') === 'pill') ? 'selected' : '' ?>>胶囊</option></select></label>
                    <label class="display-select">按钮大小<select name="button_size"><option value="small" <?= (($prefs['module_layout']['button_size'] ?? '') === 'small') ? 'selected' : '' ?>>小</option><option value="standard" <?= (($prefs['module_layout']['button_size'] ?? 'standard') === 'standard') ? 'selected' : '' ?>>标准</option><option value="large" <?= (($prefs['module_layout']['button_size'] ?? '') === 'large') ? 'selected' : '' ?>>大</option></select></label>
                  </section>
                  <section class="display-control-panel">
                    <div class="display-control-head"><strong>字号与密度</strong><span>滑动即可看效果</span></div>
                    <label class="display-slider">全局字号 <b data-range-value="font_scale"><?= h($prefs['font_scale']) ?>px</b><input type="range" name="font_scale" min="11" max="15" step="1" value="<?= h($prefs['font_scale']) ?>"></label>
                    <label class="display-slider">表格行高 <b data-range-value="table_row_height"><?= h($prefs['table_row_height']) ?>px</b><input type="range" name="table_row_height" min="24" max="38" step="1" value="<?= h($prefs['table_row_height']) ?>"></label>
                    <label class="display-slider">右栏宽度 <b data-range-value="actionbar_width"><?= h($prefs['actionbar_width']) ?>px</b><input type="range" name="actionbar_width" min="160" max="420" step="10" value="<?= h($prefs['actionbar_width']) ?>"></label>
                    <label class="display-slider">邮箱列表 <b data-range-value="email_list_font_size"><?= h($prefs['email_list_font_size']) ?>px</b><input type="range" name="email_list_font_size" min="11" max="15" step="1" value="<?= h($prefs['email_list_font_size']) ?>"></label>
                    <label class="display-slider">邮件正文 <b data-range-value="email_body_font_size"><?= h($prefs['email_body_font_size']) ?>px</b><input type="range" name="email_body_font_size" min="11" max="16" step="1" value="<?= h($prefs['email_body_font_size']) ?>"></label>
                    <label class="display-slider">编辑器字号 <b data-range-value="email_editor_font_size"><?= h($prefs['email_editor_font_size']) ?>px</b><input type="range" name="email_editor_font_size" min="11" max="16" step="1" value="<?= h($prefs['email_editor_font_size']) ?>"></label>
                  </section>
                  <section class="display-live-card" data-display-live-card>
                    <div class="display-control-head"><strong>效果预览</strong><span data-display-live-meta>紧凑 · 13px</span></div>
                    <div class="display-preview-tabs"><span>CRM</span><span>客户</span><span>报价</span><span>BOM</span></div>
                    <div class="display-preview-table">
                      <div><strong>EX003</strong><span>QIULEI Lighting</span><em>重点客户</em></div>
                      <div><strong>IN118</strong><span>Mumbai Project</span><em>报价中</em></div>
                      <div><strong>DE026</strong><span>Berlin Studio</span><em>样品中</em></div>
                    </div>
                    <p>预览会跟随字号、行高、密度和主题同步变化。</p>
                  </section>
                  <section class="display-control-panel settings-module-theme-panel">
                    <div class="display-control-head"><strong>模块主题颜色</strong><span>模块独立色彩预设</span></div>
                    <?php foreach ([['customers','客户','#2563eb'],['mail','邮箱','#059669'],['promotion','推广','#f97316'],['tasks','任务','#64748b'],['opportunities','商机','#7c3aed'],['ai','AI中心','#0f172a']] as $m): $savedColor = $prefs['module_layout']['module_colors'][$m[0]] ?? $m[2]; ?>
                    <label><span><?= h($m[1]) ?></span><input type="color" name="module_color_<?= h($m[0]) ?>" value="<?= h($savedColor) ?>"></label>
                    <?php endforeach; ?>
                    <p class="entry-muted wide">模块颜色先用于设置预览和后续模块主题规则；保存后进入个人偏好。</p>
                  </section>
                </div>
                <div class="display-savebar"><span>调整会先即时预览，点击保存后写入个人设置。</span><button type="submit">保存显示设置</button></div>
              </form>
            </section>

            <section class="crm-panel settings-section" data-settings-section="top_menu">
              <div class="crm-config-head">
                <div><span>Top Navigation</span><h2>顶部菜单配置</h2><p>默认一级菜单只保留高频主工作区；联动、资料、分析、日志已下沉到对应模块和设置。</p></div>
                <button type="button" data-top-menu-save>保存顶部菜单</button>
              </div>
              <div class="top-menu-settings" data-top-menu-settings>
                <?php foreach (crm_top_module_keys() as $key): if (!isset($allModules[$key])) continue; $module = $allModules[$key]; ?>
                <label class="top-menu-item"><input type="checkbox" data-top-menu-key="<?= h($key) ?>" checked><span><?= h($module['icon']) ?></span><strong><?= h($module['short']) ?></strong><em><?= h($module['full']) ?></em></label>
                <?php endforeach; ?>
              </div>
              <div class="settings-route-map">
                <article><strong>联动</strong><span>已下沉到客户详情 Tab、商机详情 Tab、任务/商机/客户右侧 ACTIONS。</span></article>
                <article><strong>资料</strong><span>已下沉到客户资料、商机资料、推广内容素材、AI 资料自动、任务资料任务。</span></article>
                <article><strong>分析</strong><span>已下沉到工作台总览、推广效果分析、商机漏斗、客户/邮箱/任务统计。</span></article>
                <article><strong>日志</strong><span>已下沉到设置日志中心，以及客户、商机、推广任务、邮件、拜访详情日志。</span></article>
              </div>
            </section>

            <section class="crm-panel settings-section" data-settings-section="mail_settings">
              <div class="crm-config-head">
                <div><span>Mail Control</span><h2>邮箱设置</h2><p>统一管理邮箱绑定、收信规则、附件限制、签名、自动关联客户和 AI 自动获客入口。</p></div>
                <button type="button" data-settings-action="open_mail_settings">打开邮箱绑定</button>
              </div>
              <?php $mailSet = $moduleSettings['mail_settings'] ?? []; ?>
              <form class="settings-module-form" data-module-setting-form="mail_settings">
                <section class="settings-form-card">
                  <h3>收信与列表</h3>
                  <label>自动同步间隔/分钟<input type="number" name="auto_sync_minutes" min="1" max="120" value="<?= h($mailSet['auto_sync_minutes'] ?? 5) ?>"></label>
                  <label>强制收取最近/天<input type="number" name="force_recent_days" min="1" max="30" value="<?= h($mailSet['force_recent_days'] ?? 3) ?>"></label>
                  <label>附件大小上限/MB<input type="number" name="max_attachment_mb" min="1" max="200" value="<?= h($mailSet['max_attachment_mb'] ?? 100) ?>"></label>
                  <label>签名字体颜色<input type="color" name="signature_text_color" value="<?= h($mailSet['signature_text_color'] ?? '#111827') ?>"></label>
                  <?php foreach ([['auto_fill_list','邮件列表自动铺满'],['attachment_top','正文上方显示附件'],['show_folder_counts','文件夹显示数量'],['auto_link_customer','自动关联已有客户'],['unknown_mail_to_ai','未知客户进入 AI 获客'],['signature_image_enabled','启用签名图片']] as $opt): ?>
                  <label class="tag-chip"><input type="checkbox" name="<?= h($opt[0]) ?>" value="1" <?= !empty($mailSet[$opt[0]]) ? 'checked' : '' ?>><span><?= h($opt[1]) ?></span></label>
                  <?php endforeach; ?>
                </section>
                <div class="display-savebar"><span data-module-setting-hint="mail_settings">保存后供邮箱模块读取执行。</span><button type="submit">保存邮箱设置</button></div>
              </form>
            </section>

            <section class="crm-panel settings-section" data-settings-section="promotion_settings">
              <div class="crm-config-head">
                <div><span>Marketing Control</span><h2>推广中心设置</h2><p>管理推广渠道、联系人优先规则、黑名单、不推广、邮箱规则、时区规则和自动跳过。</p></div>
                <button type="button" data-settings-action="promotion_channels">维护推广渠道</button>
              </div>
              <?php $promoSet = $moduleSettings['promotion_settings'] ?? []; $promoChannels = $promoSet['default_channels'] ?? []; ?>
              <form class="settings-module-form" data-module-setting-form="promotion_settings">
                <section class="settings-form-card">
                  <h3>推广规则</h3>
                  <label>冷却天数<input type="number" name="cooldown_days" min="0" max="365" value="<?= h($promoSet['cooldown_days'] ?? 7) ?>"></label>
                  <label class="wide">默认渠道<input name="default_channels" value="<?= h(implode(',', (array)$promoChannels)) ?>" placeholder="email,wechat,whatsapp"></label>
                  <?php foreach ([['contact_strategy_priority','联系人推广方式第一权重'],['cooldown_enabled','启用冷却期'],['allow_test_bypass_cooldown','测试模式允许绕过冷却'],['skip_blacklist','自动跳过黑名单'],['skip_no_promotion','自动跳过不推广'],['dedupe_email','重复邮箱合并'],['require_preview','必须预览后才能生成队列']] as $opt): ?>
                  <label class="tag-chip"><input type="checkbox" name="<?= h($opt[0]) ?>" value="1" <?= !empty($promoSet[$opt[0]]) ? 'checked' : '' ?>><span><?= h($opt[1]) ?></span></label>
                  <?php endforeach; ?>
                </section>
                <div class="display-savebar"><span data-module-setting-hint="promotion_settings">保存后影响推广任务向导、队列生成和联系人策略。</span><button type="submit">保存推广设置</button></div>
              </form>
            </section>

            <section class="crm-panel settings-section" data-settings-section="opportunity_settings">
              <div class="crm-config-head">
                <div><span>Opportunity Control</span><h2>商机设置</h2><p>维护商机阶段、默认成交概率、赢单/输单原因、预测金额和商机报表口径。</p></div>
                <button type="button" data-settings-action="opportunity_stage">维护商机阶段</button>
              </div>
              <?php $oppSet = $moduleSettings['opportunity_settings'] ?? []; $oppProb = $oppSet['stage_probabilities'] ?? []; ?>
              <form class="settings-module-form" data-module-setting-form="opportunity_settings">
                <section class="settings-form-card">
                  <h3>预测规则</h3>
                  <label>默认币种<input name="default_currency" value="<?= h($oppSet['default_currency'] ?? 'USD') ?>"></label>
                  <?php foreach ([['auto_probability_by_stage','按阶段自动建议概率'],['require_lost_reason','输单必须填写原因'],['require_won_amount','赢单必须填写金额'],['auto_create_followup','保存商机后自动创建跟进提醒']] as $opt): ?>
                  <label class="tag-chip"><input type="checkbox" name="<?= h($opt[0]) ?>" value="1" <?= !empty($oppSet[$opt[0]]) ? 'checked' : '' ?>><span><?= h($opt[1]) ?></span></label>
                  <?php endforeach; ?>
                </section>
                <section class="settings-form-card settings-prob-grid">
                  <h3>阶段默认成交概率</h3>
                  <?php foreach (['new_need'=>'新需求','need_confirm'=>'需求确认','quoted'=>'已报价','sample'=>'样品中','technical'=>'技术确认','negotiation'=>'谈判','waiting_confirm'=>'等待确认','won'=>'赢单','lost'=>'输单'] as $key => $label): ?>
                  <label><?= h($label) ?><input type="number" name="stage_probability[<?= h($key) ?>]" min="0" max="100" value="<?= h($oppProb[$key] ?? 0) ?>"></label>
                  <?php endforeach; ?>
                </section>
                <div class="display-savebar"><span data-module-setting-hint="opportunity_settings">预测金额固定按：预计金额 × 概率 / 100。</span><button type="submit">保存商机设置</button></div>
              </form>
            </section>

            <section class="crm-panel settings-section" data-settings-section="quote_flow_settings">
              <div class="crm-config-head">
                <div><span>Quote Order Flow</span><h2>报价流程设置</h2><p>配置报价审核、客户未回复提醒、定金/尾款、出货和单证节点。未接入外部接口时只显示待接入，不假成功。</p></div>
              </div>
              <?php $quoteFlowSet = $moduleSettings['quote_flow_settings'] ?? []; ?>
              <form class="settings-module-form" data-module-setting-form="quote_flow_settings">
                <section class="settings-form-card">
                  <h3>流程节点</h3>
                  <label>未回复提醒/天<input type="number" name="no_reply_remind_days" min="1" max="30" value="<?= h($quoteFlowSet['no_reply_remind_days'] ?? 3) ?>"></label>
                  <label>未回复逾期/天<input type="number" name="no_reply_overdue_days" min="1" max="60" value="<?= h($quoteFlowSet['no_reply_overdue_days'] ?? 7) ?>"></label>
                  <label>默认定金比例/%<input type="number" name="default_deposit_percent" min="0" max="100" value="<?= h($quoteFlowSet['default_deposit_percent'] ?? 40) ?>"></label>
                  <label>默认币种<input name="default_currency" value="<?= h($quoteFlowSet['default_currency'] ?? 'USD') ?>"></label>
                  <?php foreach ([['audit_enabled','启用报价审核'],['reject_reason_required','驳回必须填写原因'],['deposit_node_enabled','启用定金节点'],['balance_node_enabled','启用尾款节点'],['shipping_node_enabled','启用出货节点'],['document_node_enabled','启用单证节点'],['write_customer_timeline','流程变化写客户时间轴'],['write_opportunity_timeline','流程变化写商机时间轴'],['write_quote_log','流程变化写报价日志']] as $opt): ?>
                  <label class="tag-chip"><input type="checkbox" name="<?= h($opt[0]) ?>" value="1" <?= array_key_exists($opt[0], $quoteFlowSet) ? (!empty($quoteFlowSet[$opt[0]]) ? 'checked' : '') : 'checked' ?>><span><?= h($opt[1]) ?></span></label>
                  <?php endforeach; ?>
                </section>
                <section class="settings-form-card">
                  <h3>接口状态</h3>
                  <div class="settings-control-map compact wide">
                    <article><strong>转订单</strong><span>已接报价系统订单表；流程中心可显示订单状态。</span></article>
                    <article><strong>收款</strong><span>第一阶段读取订单内 paid_amount / balance_amount；财务对账接口待接入。</span></article>
                    <article><strong>出货</strong><span>已读取 quote_shipments 多批次出货；可显示部分/全部出货。</span></article>
                    <article><strong>单证</strong><span>读取 PL / CI 生成时间；单证发送客户接口待接入。</span></article>
                  </div>
                </section>
                <div class="display-savebar"><span data-module-setting-hint="quote_flow_settings">保存后供任务中心报价订单流程图读取。</span><button type="submit">保存报价流程设置</button></div>
              </form>
            </section>

            <section class="crm-panel settings-section" data-settings-section="ai_settings">
              <div class="crm-config-head">
                <div><span>AI Control</span><h2>AI 设置</h2><p>AI 只生成草稿、建议和确认任务；默认禁止自动发送报价、资料和正式邮件。</p></div>
                <button type="button" data-settings-action="open_ai_settings">进入 AI 设置</button>
              </div>
              <?php $aiSet = $moduleSettings['ai_settings'] ?? []; ?>
              <form class="settings-module-form" data-module-setting-form="ai_settings">
                <section class="settings-form-card">
                  <h3>AI 识别与草稿</h3>
                  <label>获客最低置信度<input type="number" name="lead_min_confidence" min="0" max="100" value="<?= h($aiSet['lead_min_confidence'] ?? 70) ?>"></label>
                  <label>报价最低置信度<input type="number" name="quote_min_confidence" min="0" max="100" value="<?= h($aiSet['quote_min_confidence'] ?? 75) ?>"></label>
                  <label>资料最低置信度<input type="number" name="material_min_confidence" min="0" max="100" value="<?= h($aiSet['material_min_confidence'] ?? 70) ?>"></label>
                  <?php foreach ([['lead_enabled','启用 AI 自动获客'],['mail_recognition','识别邮件线索'],['promotion_reply_recognition','识别推广回复'],['auto_deduplicate','自动查重'],['auto_create_draft','自动创建草稿'],['auto_create_confirm_task','自动创建确认任务'],['quote_enabled','启用 AI 报价草稿'],['material_enabled','启用 AI 资料草稿']] as $opt): ?>
                  <label class="tag-chip"><input type="checkbox" name="<?= h($opt[0]) ?>" value="1" <?= !empty($aiSet[$opt[0]]) ? 'checked' : '' ?>><span><?= h($opt[1]) ?></span></label>
                  <?php endforeach; ?>
                </section>
                <section class="settings-form-card">
                  <h3>安全规则</h3>
                  <p class="settings-pending-chip wide">强制规则：AI 自动发送邮件 / 报价 / 资料全部固定关闭，必须人工确认，前端不能开启。</p>
                </section>
                <div class="display-savebar"><span data-module-setting-hint="ai_settings">保存后影响 AI 任务生成、置信度校验和确认流。</span><button type="submit">保存 AI 设置</button></div>
              </form>
            </section>

            <section class="crm-panel settings-section" data-settings-section="task_settings">
              <div class="crm-config-head">
                <div><span>Task & Dispatch</span><h2>任务 / 派工设置</h2><p>管理任务状态、优先级、提醒规则、截止规则、超期规则和派工接口入口。</p></div>
              </div>
              <?php $taskSet = $moduleSettings['task_settings'] ?? []; $offsets = $taskSet['followup_offsets'] ?? [1,3,7,15,30,60]; ?>
              <form class="settings-module-form" data-module-setting-form="task_settings">
                <section class="settings-form-card">
                  <h3>提醒与超期</h3>
                  <label class="wide">跟进提醒天数<input name="followup_offsets" value="<?= h(implode(',', (array)$offsets)) ?>" placeholder="1,3,7,15,30,60"></label>
                  <label>拜访提前提醒/小时<input type="number" name="visit_remind_before_hours" min="0" max="720" value="<?= h($taskSet['visit_remind_before_hours'] ?? 24) ?>"></label>
                  <label>来访提前提醒/小时<input type="number" name="arrival_remind_before_hours" min="0" max="720" value="<?= h($taskSet['arrival_remind_before_hours'] ?? 24) ?>"></label>
                  <label>结果超期/小时<input type="number" name="overdue_result_hours" min="1" max="720" value="<?= h($taskSet['overdue_result_hours'] ?? 24) ?>"></label>
                  <label class="tag-chip"><input type="checkbox" name="auto_notification" value="1" <?= !empty($taskSet['auto_notification']) ? 'checked' : '' ?>><span>启用通知提醒</span></label>
                  <label class="tag-chip"><input type="checkbox" name="dispatch_interface_enabled" value="1" <?= !empty($taskSet['dispatch_interface_enabled']) ? 'checked' : '' ?>><span>启用派工接口</span></label>
                </section>
                <div class="display-savebar"><span data-module-setting-hint="task_settings">保存后用于拜访、来访、推广线下执行和 AI 确认任务提醒。</span><button type="submit">保存任务设置</button></div>
              </form>
            </section>

            <section class="crm-panel settings-section" data-settings-section="permissions">
              <div class="crm-config-head">
                <div><span>Permission Center</span><h2>权限设置</h2><p>用户、部门、角色、权限、数据范围和字段权限统一从权限中心管理。</p></div>
                <a class="settings-link-button" href="permissions.php">打开权限中心</a>
              </div>
              <div class="settings-control-map">
                <article><strong>用户 / 部门 / 角色</strong><span>老板、主管、员工、执行人员、系统管理员。</span></article>
                <article><strong>数据范围</strong><span>自己、本部门、全部客户、暂存池、公海池。</span></article>
                <article><strong>字段权限</strong><span>BOM 成本、利润、采购信息、报价价格按权限显示。</span></article>
                <article><strong>模块权限</strong><span>CRM、邮箱、推广、任务、商机、AI中心、BOM、报价等分系统配置。</span></article>
              </div>
            </section>

            <section class="crm-panel crm-config-center settings-section" data-settings-section="dictionary" data-config-center>
              <div class="crm-config-head">
                <div><span>Dictionary Center</span><h2>字典配置</h2><p>客户等级、来源、推广方式、联系人标签、地址类型、负责人角色都从这里维护。</p></div>
                <button type="button" data-config-reload>刷新配置</button>
              </div>
              <div class="crm-config-grid">
                <aside class="crm-config-types">
                  <?php foreach ($crmConfig['types'] as $index => $type): ?>
                  <button type="button" data-config-type="<?= h($type['type_key']) ?>" class="<?= $index === 0 ? 'active' : '' ?>"><strong><?= h($type['type_name']) ?></strong><span><?= h($type['type_key']) ?></span></button>
                  <?php endforeach; ?>
                </aside>
                <section class="crm-config-main">
                  <div class="crm-config-toolbar"><strong data-config-title>字典配置</strong><button type="button" data-config-new>新增配置项</button></div>
                  <div class="crm-config-items" data-config-items></div>
                </section>
              </div>
            </section>

            <section class="crm-panel settings-section" data-settings-section="fields">
              <div class="crm-config-head">
                <div><span>Field Matrix</span><h2>字段配置中心</h2><p>统一管理客户、联系人、商机、拜访、来访、推广和 AI 任务字段；规则 JSON 继续作为底层配置入口。</p></div>
              </div>
              <?php $fieldSet = $moduleSettings['field_settings'] ?? []; ?>
              <form class="settings-module-form" data-module-setting-form="field_settings">
                <section class="settings-form-card">
                  <h3>字段显示规则</h3>
                  <label class="wide">客户列表显示字段<input name="customer_visible_fields" value="<?= h(implode(',', (array)($fieldSet['customer_visible_fields'] ?? []))) ?>" placeholder="customer_code,customer_name,country"></label>
                  <label class="wide">客户必填字段<input name="customer_required_fields" value="<?= h(implode(',', (array)($fieldSet['customer_required_fields'] ?? []))) ?>" placeholder="customer_name,country"></label>
                  <label class="wide">联系人显示字段<input name="contact_visible_fields" value="<?= h(implode(',', (array)($fieldSet['contact_visible_fields'] ?? []))) ?>" placeholder="is_primary,name,email,phone"></label>
                  <label class="wide">商机显示字段<input name="opportunity_visible_fields" value="<?= h(implode(',', (array)($fieldSet['opportunity_visible_fields'] ?? []))) ?>" placeholder="opportunity_name,stage,expected_amount"></label>
                </section>
                <div class="display-savebar"><span data-module-setting-hint="field_settings">保存后写入字段矩阵配置，并进入设置日志。</span><button type="submit">保存字段配置</button></div>
              </form>
              <div class="settings-rules-grid">
                <aside class="crm-config-side">
                  <h3>规则配置</h3>
                  <label>规则<select data-rule-key><?php foreach ($crmConfig['rules'] as $key => $rule): ?><option value="<?= h($key) ?>"><?= h($key) ?></option><?php endforeach; ?></select></label>
                  <textarea data-rule-json rows="12"><?= h(json_encode(reset($crmConfig['rules']), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></textarea>
                  <button type="button" data-rule-save>保存规则</button>
                </aside>
                <aside class="crm-config-side">
                  <h3>字段配置</h3>
                  <div class="settings-control-map compact">
                    <article><strong>客户字段</strong><span>公司、国家、城市、来源、等级、负责人、邮箱、电话、地址。</span></article>
                    <article><strong>联系人字段</strong><span>主联系人、职位、邮箱、电话、WhatsApp、推广方式。</span></article>
                    <article><strong>商机字段</strong><span>阶段、金额、概率、产品、下一步动作、附件。</span></article>
                    <article><strong>拜访字段</strong><span>日期、参与人、准备事项、结果、提醒。</span></article>
                  </div>
                  <span class="settings-pending-chip">字段矩阵已支持保存；高级字段规则仍可通过规则 JSON 精确维护。</span>
                </aside>
              </div>
            </section>

            <section class="crm-panel settings-section" data-settings-section="dashboard">
              <div class="crm-config-head">
                <div><span>Dashboard Builder</span><h2>工作台组件控制</h2><p>在设置里管理首页工作台组件。显示/隐藏、显示模式和大小会立即影响工作台，并在右侧预览。</p></div>
              </div>
              <div class="settings-workspace-toolbar">
                <label>预览角色<select data-settings-workspace-role><option value="auto">按当前账号</option><option value="boss">老板/管理员</option><option value="sales">业务员</option><option value="assistant">跟单/文员</option><option value="staff">普通员工</option></select></label>
                <label>预览密度<select data-settings-workspace-density><option value="compact" selected>紧凑</option><option value="standard">标准</option><option value="wide">宽松</option></select></label>
                <button type="button" data-settings-workspace-reset>恢复默认组件</button>
              </div>
              <div class="workspace-config-panel settings-workspace-manager" data-workspace-config>
                <div class="workspace-config-head"><strong>组件管理器</strong><span>调整后立即预览；保存于当前浏览器个人偏好。</span></div>
                <div class="workspace-config-list" data-workspace-config-list></div>
              </div>
              <div class="settings-workspace-live">
                <div class="workspace-config-head"><strong>工作台效果预览</strong><span>组件排列、大小和模式预览</span></div>
                <div class="settings-workspace-preview-grid" data-settings-workspace-preview><span>正在加载工作台预览...</span></div>
              </div>
            </section>

            <section class="crm-panel settings-section" data-settings-section="logs">
              <div class="crm-config-head">
                <div><span>Audit Logs</span><h2>日志中心</h2><p>全局日志从一级菜单下沉到设置；业务对象自己的日志仍在对应详情页查看。</p></div>
                <button type="button" data-settings-open-logs>打开完整日志</button>
              </div>
              <div class="settings-log-grid">
                <?php foreach (['全部操作日志','登录日志','客户日志','邮件日志','推广日志','任务日志','商机日志','AI 日志','系统异常日志','导出日志'] as $label): ?>
                <article><strong><?= h($label) ?></strong><span>支持按时间、操作人、模块、对象、操作类型和成功状态筛选。</span></article>
                <?php endforeach; ?>
              </div>
              <div class="settings-log-summary" data-settings-log-summary>正在读取最近日志...</div>
            </section>
          </div>
          <aside class="crm-settings-preview is-collapsed" id="preview_panel" data-settings-preview>
            <div class="preview-head"><span>Live Preview</span><strong>实时预览区</strong><button type="button" data-settings-preview-toggle>预览</button></div>
            <section><h3>企业抬头预览</h3><div class="preview-company-card" data-preview-company><b><?= h($companySettings['company_short_name'] ?? 'AD') ?></b><strong><?= h($companySettings['company_name_en'] ?? $companySettings['company_name'] ?? 'Company') ?></strong><span><?= h(($companySettings['default_currency'] ?? 'USD') . ' · ' . ($companySettings['default_language'] ?? 'zh-CN')) ?></span></div></section>
            <section><h3>顶部菜单预览</h3><div class="preview-tabs" data-preview-top-menu><span>工作台</span><span>客户</span><span>邮箱</span><span>推广</span></div></section>
            <section><h3>模块颜色预览</h3><div class="preview-module-colors" data-preview-module-colors></div></section>
            <section><h3>客户中心预览</h3><div class="preview-tabs" data-preview-tabs><span>读取中...</span></div></section>
            <section><h3>Tab结构预览</h3><div class="preview-list" data-preview-tab-structure></div></section>
            <section><h3>客户列表预览</h3><div class="preview-table" data-preview-list><span>代码</span><span>客户</span><span>国家</span><span>等级</span><span>状态</span></div></section>
            <section><h3>工作台预览</h3><div class="preview-dashboard" data-preview-dashboard><span>指标卡</span><span>列表</span><span>饼图</span><span>趋势</span></div></section>
          </aside>
        </section>
      </section>
    </section>

    <aside class="crm-actionbar" data-actionbar>
      <button class="crm-actionbar-resizer" type="button" data-actionbar-resizer title="拖动调整右栏宽度" aria-label="拖动调整右栏宽度"></button>
      <button class="crm-actionbar-toggle" type="button" data-actionbar-toggle title="收起/展开">收</button>
      <div class="crm-actionbar-head"><span>Actions</span><strong data-actionbar-title>工作台</strong></div>
      <div class="crm-action-list" data-action-list></div>
    </aside>
  </main>

  <datalist id="crm-country-options">
    <?php foreach (($crmConfig['items']['country_region'] ?? []) as $country): ?>
    <?php $countryFlag = crm_country_flag((string)($country['item_key'] ?? '')) ?: crm_country_flag((string)($country['name_en'] ?? '')) ?: crm_country_flag((string)($country['name_cn'] ?? '')); ?>
    <option value="<?= h($country['item_key']) ?>" label="<?= h(trim($countryFlag . ' ' . (($country['item_key'] ?? '') . ' · ' . ($country['name_cn'] ?? '') . ' · ' . ($country['name_en'] ?? '') . ' · ' . (($country['extra_config']['phone_code'] ?? '') ?: '')))) ?>"></option>
    <?php endforeach; ?>
  </datalist>
  <datalist id="crm-region-options">
    <?php foreach (($crmConfig['items']['city_region'] ?? []) as $region): ?>
    <option value="<?= h($region['short_name'] ?: ($region['name_en'] ?: $region['item_key'])) ?>" label="<?= h(($region['item_key'] ?? '') . ' · ' . ($region['name_cn'] ?? '') . ' · ' . ($region['name_en'] ?? '') . ' · ' . (($region['extra_config']['country'] ?? '') ?: '')) ?>"></option>
    <?php endforeach; ?>
  </datalist>

  <button class="crm-mobile-actions" type="button" data-mobile-actions>操作</button>
  <dialog class="crm-online-dialog" data-online-dialog>
    <header><strong>谁在线</strong><button type="button" data-online-close>关闭</button></header>
    <div data-online-list class="crm-online-list"></div>
  </dialog>
  <dialog class="customer-dialog crm-modal" data-customer-dialog>
    <form method="dialog" class="customer-dialog-box crm-modal-panel" data-customer-form>
      <header class="crm-modal-header"><div><strong class="crm-modal-title" data-dialog-title>新建客户</strong><small class="crm-modal-subtitle" data-dialog-description>客户业务操作</small></div><button type="button" class="crm-modal-close" data-dialog-close>关闭</button></header>
      <div class="customer-dialog-body crm-modal-body" data-dialog-body></div>
      <footer class="crm-modal-footer"><span data-dialog-hint>带 * 为必填项</span><div data-dialog-footer-actions><button type="button" data-dialog-cancel>取消</button><button type="submit" class="primary" data-dialog-submit>保存</button></div></footer>
    </form>
  </dialog>
  <dialog class="mail-compose-dialog crm-modal" data-mail-compose-dialog>
    <form method="dialog" class="mail-compose-box crm-modal-panel" data-mail-compose-form>
      <header class="mail-compose-header crm-modal-header"><strong class="crm-modal-title" data-mail-compose-title>写邮件</strong><button type="button" class="crm-modal-close" data-mail-compose-close>关闭</button></header>
      <input type="hidden" name="body_html" data-mail-compose-body-html>
      <input type="hidden" name="forward_attachment_ids" data-mail-forward-attachment-ids>
      <section class="mail-compose-recipients">
          <h3>收件信息</h3>
          <div class="mail-compose-recipient-grid">
            <input type="hidden" name="customer_id" data-mail-compose-customer-id>
            <input type="hidden" name="contact_id" data-mail-compose-contact-id>
            <label class="mail-recipient-field mail-field-to"><span class="mail-field-label">收件人</span><input type="hidden" name="to_emails" required data-mail-recipient-field="to"><div class="mail-recipient-chipbox" data-mail-recipient-chipbox="to"><div class="mail-recipient-chips" data-mail-recipient-chips="to"></div><input placeholder="输入邮箱 / 客户名 / 联系人" autocomplete="off" data-mail-recipient-search-field="to"></div><div class="mail-recipient-status" data-mail-recipient-status="to">输入 2 个字符后搜索现有客户邮箱</div><div class="mail-recipient-results" data-mail-recipient-results="to" hidden></div></label>
            <label class="mail-recipient-field mail-field-cc"><span class="mail-field-label">抄送</span><input type="hidden" name="cc_emails" data-mail-recipient-field="cc"><div class="mail-recipient-chipbox" data-mail-recipient-chipbox="cc"><div class="mail-recipient-chips" data-mail-recipient-chips="cc"></div><input placeholder="输入邮箱 / 客户名 / 联系人" autocomplete="off" data-mail-recipient-search-field="cc"></div><div class="mail-recipient-status" data-mail-recipient-status="cc">输入 2 个字符后搜索现有客户邮箱</div><div class="mail-recipient-results" data-mail-recipient-results="cc" hidden></div></label>
            <label class="mail-recipient-field mail-field-bcc"><span class="mail-field-label">密送</span><input type="hidden" name="bcc_emails" data-mail-recipient-field="bcc"><div class="mail-recipient-chipbox" data-mail-recipient-chipbox="bcc"><div class="mail-recipient-chips" data-mail-recipient-chips="bcc"></div><input placeholder="输入邮箱 / 客户名 / 联系人" autocomplete="off" data-mail-recipient-search-field="bcc"></div><div class="mail-recipient-status" data-mail-recipient-status="bcc">密送不会显示在邮件头，输入 2 个字符后搜索</div><div class="mail-recipient-results" data-mail-recipient-results="bcc" hidden></div></label>
            <label class="mail-compose-subject-line mail-field-subject"><span class="mail-field-label">邮件主题</span><input name="subject" required placeholder="请输入邮件主题"></label>
          </div>
      </section>
      <section class="mail-compose-main">
        <div class="mail-compose-editor-column">
          <h3>正文</h3>
          <div class="mail-rich-toolbar">
            <div class="mail-toolbar-group mail-toolbar-main">
              <button type="button" data-compose-rich-cmd="bold">B</button>
              <button type="button" data-compose-rich-cmd="italic">I</button>
              <button type="button" data-compose-rich-cmd="underline">U</button>
              <button type="button" data-compose-rich-cmd="insertUnorderedList">列表</button>
              <button type="button" data-compose-rich-link>链接</button>
              <button type="button" data-compose-rich-image>图片</button>
            </div>
            <div class="mail-toolbar-group mail-toolbar-format">
              <label class="mail-toolbar-select">字号<select data-compose-font-size><option value="">字号</option><?php for ($i = 6; $i <= 24; $i++): ?><option value="<?= $i ?>px"><?= $i ?></option><?php endfor; ?></select></label>
              <label class="mail-toolbar-select">行高<select data-compose-line-height><option value="">行高</option><option value="1">1.0</option><option value="1.15">1.15</option><option value="1.3">1.3</option><option value="1.5">1.5</option><option value="1.75">1.75</option><option value="2">2.0</option></select></label>
              <label class="mail-toolbar-color">文字颜色<input type="color" data-compose-color-picker value="#111827"></label>
            </div>
            <div class="mail-toolbar-group mail-toolbar-signature">
              <button type="button" data-compose-rich-signature>签名</button>
              <label class="mail-toolbar-check"><input type="checkbox" data-mail-insert-signature checked> 自动签名</label>
            </div>
          </div>
          <div class="mail-rich-editor mail-compose-editor" contenteditable="true" data-mail-compose-editor></div>
        </div>
        <aside class="mail-compose-side-column">
          <section class="mail-side-card mail-compose-side-card mail-compose-image-card">
            <header><strong>图片附件</strong><span data-compose-image-count>0</span></header>
            <label class="visit-file-drop mail-compose-drop" data-mail-compose-image-drop><input type="file" accept="image/*" multiple data-mail-compose-images><b>点击选择图片附件</b><em>作为附件发送，不插入正文</em></label>
            <div class="visit-thumb-grid mail-compose-image-list" data-compose-image-list><p class="visit-file-empty">暂无图片附件</p></div>
          </section>
          <section class="mail-side-card mail-compose-side-card mail-compose-attachment-card">
            <header><strong>附件</strong><span data-compose-attachment-count>0</span></header>
            <label class="visit-file-drop mail-compose-drop" data-mail-compose-attach-drop><input type="file" data-mail-compose-attachments multiple><b>点击选择附件</b><em>支持拖入文件</em></label>
            <div class="visit-file-list mail-compose-attach-list" data-compose-attach-list><p class="visit-file-empty">未选择附件</p></div>
          </section>
          <section class="mail-side-card mail-compose-side-card mail-datasheet-attach">
            <header><strong>资料附件</strong><span data-mail-datasheet-count>0</span></header>
            <input type="hidden" name="datasheet_attachment_refs" data-mail-datasheet-refs value="[]"><div class="mail-datasheet-row mail-datasheet-search"><input type="text" data-mail-datasheet-model placeholder="输入一个或多个型号，逗号 / 空格 / 换行分隔"><button type="button" data-mail-datasheet-search>获取资料</button></div><div class="mail-datasheet-bulk" data-mail-datasheet-bulk hidden><button type="button" data-mail-datasheet-add-all="pdf">批量加入 PDF</button><button type="button" data-mail-datasheet-add-all="excel">批量加入 Excel</button></div><div class="mail-datasheet-results" data-mail-datasheet-results><small>资料接口待接入</small></div><div class="mail-datasheet-picked" data-mail-datasheet-picked>未选择资料附件</div>
          </section>
          <section class="mail-side-card mail-compose-side-card mail-forward-card" data-forward-attachments-wrap hidden>
            <header><strong>原邮件附件</strong><span data-forward-attachment-count>0</span></header>
            <label class="mail-forward-master"><input type="checkbox" data-forward-include-attachments checked> 带上原附件</label><div class="mail-forward-attachments" data-forward-attachments></div><small>可逐个勾选；原附件文件不存在，不能带出。</small>
          </section>
        </aside>
      </section>
      <footer class="mail-compose-footer crm-modal-footer"><span data-mail-compose-hint data-mail-compose-status>支持保存草稿；发送失败会保留正文。</span><div><button type="button" data-mail-save-draft>保存草稿</button><button type="button" data-mail-compose-cancel>取消</button><button type="submit" class="primary">发送</button></div></footer>
    </form>
  </dialog>
  <dialog class="mail-signature-dialog crm-modal" data-mail-signature-dialog>
    <form method="dialog" class="mail-signature-box crm-modal-panel" data-mail-signature-form>
      <header class="crm-modal-header"><strong class="crm-modal-title">签名制作</strong><button type="button" class="crm-modal-close" data-mail-signature-close>关闭</button></header>
      <div class="mail-signature-toolbar">
        <button type="button" data-signature-cmd="bold">B</button>
        <button type="button" data-signature-cmd="italic">I</button>
        <button type="button" data-signature-cmd="underline">U</button>
        <button type="button" data-signature-cmd="insertUnorderedList">列表</button>
        <label class="mail-signature-font-size">字号<select data-signature-font-size><option value="">字号</option><?php for ($i = 6; $i <= 24; $i++): ?><option value="<?= $i ?>px"><?= $i ?></option><?php endfor; ?></select></label>
        <label class="mail-signature-font-size">行高<select data-signature-line-height><option value="">行高</option><option value="1">1.0</option><option value="1.15">1.15</option><option value="1.3">1.3</option><option value="1.5">1.5</option><option value="1.75">1.75</option><option value="2">2.0</option></select></label>
        <span class="mail-signature-color-group" aria-label="字体颜色">
          <?php foreach (['#111827' => '黑', '#6b7280' => '灰', '#dc2626' => '红', '#2563eb' => '蓝', '#059669' => '绿', '#d97706' => '橙', '#7c3aed' => '紫', '#ffffff' => '白'] as $color => $label): ?>
          <button type="button" class="mail-signature-color" data-signature-color="<?= h($color) ?>" title="字体颜色：<?= h($label) ?>"><i style="background:<?= h($color) ?>"></i></button>
          <?php endforeach; ?>
        </span>
        <span class="mail-signature-color-group" aria-label="高亮颜色">
          <?php foreach (['#fef3c7' => '黄底', '#dbeafe' => '蓝底', '#dcfce7' => '绿底', '#fee2e2' => '红底'] as $color => $label): ?>
          <button type="button" class="mail-signature-color is-bg" data-signature-bg="<?= h($color) ?>" title="高亮颜色：<?= h($label) ?>"><i style="background:<?= h($color) ?>"></i></button>
          <?php endforeach; ?>
        </span>
        <label class="mail-signature-color-picker">自定<input type="color" data-signature-color-picker value="#111827"></label>
        <button type="button" data-signature-link>链接</button>
        <label>图片<input type="file" accept="image/*" data-signature-image></label>
        <label>附件<input type="file" multiple data-signature-attachment></label>
      </div>
      <div class="mail-signature-vars">
        <?php foreach ([
          '{customer_name}' => '客户姓名',
          '{mail_user_name}' => '邮箱使用人名',
          '{mail_user_mobile}' => '邮箱使用手机',
          '{send_email}' => '当前发信邮箱',
          '{mail_user_position}' => '邮箱人员职位',
        ] as $var => $label): ?>
        <button type="button" data-signature-var="<?= h($var) ?>" data-signature-label="<?= h($label) ?>"><code><?= h($var) ?></code><span><?= h($label) ?></span></button>
        <?php endforeach; ?>
      </div>
      <div class="mail-signature-editor" contenteditable="true" data-signature-editor><p><br></p></div>
      <div class="mail-signature-attachments" data-signature-attachments>未选择附件</div>
      <footer class="crm-modal-footer"><span>支持富文本、变量、图片和附件名占位。保存后写入当前邮箱签名。</span><div><button type="button" data-mail-signature-cancel>取消</button><button type="submit" class="primary">保存签名</button></div></footer>
    </form>
  </dialog>

  <script>
    window.CRM_BOOTSTRAP = <?= json_encode(['csrf' => csrf_token(), 'modules' => $allModules, 'top_modules' => $modules, 'actions' => crm_action_map(), 'action_permissions' => $actionPermissions, 'permissions' => $crmPermissionState, 'preferences' => $prefs, 'config' => $crmConfig, 'module_settings' => $moduleSettings, 'users' => $customerFilterUsers, 'user' => ['id' => $user['id'], 'name' => $user['username'], 'real_name' => $user['real_name'] ?? '', 'english_name' => $user['english_name'] ?? '', 'department_name' => $user['department_name'] ?? '', 'role_name' => $user['role_name'] ?? '', 'position' => $user['position'] ?? '', 'email' => $user['email'] ?? '', 'phone' => $user['phone'] ?? '', 'is_super_admin' => is_super_admin()]], JSON_UNESCAPED_UNICODE) ?>;
  </script>
  <?php $crmAssetBuild = 'workspace-today-list-20260716-1'; ?>
  <script src="assets/crm/modules.js?v=<?= $crmAssetBuild ?>-<?= filemtime(__DIR__ . '/assets/crm/modules.js') ?>"></script>
  <script src="assets/crm/preferences.js?v=<?= $crmAssetBuild ?>-<?= filemtime(__DIR__ . '/assets/crm/preferences.js') ?>"></script>
  <script src="assets/crm/crm.js?v=<?= $crmAssetBuild ?>-<?= filemtime(__DIR__ . '/assets/crm/crm.js') ?>"></script>
</body>
</html>
