<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_permission('settings.view');
if (file_exists(__DIR__ . '/services/backup_service.php')) {
    require_once __DIR__ . '/services/backup_service.php';
}
require_once __DIR__ . '/crm_config.php';
require_once __DIR__ . '/crm_auth.php';
require_once __DIR__ . '/crm_log.php';
require_once __DIR__ . '/crm_settings_config.php';

crm_ensure_tables();
crm_settings_ensure_tables();

$message = $error = '';
function save_settings_customer_tabs(array $input): array
{
    $config = crm_rule_config('customer_detail_tabs');
    $tabs = $config['tabs'] ?? [];
    $enabled = $input['tab_enabled'] ?? [];
    $sort = $input['tab_sort'] ?? [];
    $icon = $input['tab_icon'] ?? [];
    $short = $input['tab_short'] ?? [];
    $permission = $input['tab_permission'] ?? [];
    $lifecycles = $input['tab_lifecycles'] ?? [];
    $readonly = $input['tab_readonly'] ?? [];
    foreach ($tabs as &$tab) {
        $key = (string)($tab['key'] ?? '');
        if ($key === '') continue;
        $tab['enabled'] = isset($enabled[$key]) ? 1 : 0;
        $tab['sort'] = max(1, min(999, (int)($sort[$key] ?? ($tab['sort'] ?? 100))));
        $tab['icon'] = trim((string)($icon[$key] ?? ($tab['icon'] ?? '')));
        $tab['short'] = trim((string)($short[$key] ?? ($tab['short'] ?? ($tab['name'] ?? $key))));
        $tab['permission'] = trim((string)($permission[$key] ?? ($tab['permission'] ?? '')));
        $lifeText = trim((string)($lifecycles[$key] ?? implode(',', $tab['lifecycles'] ?? ['*'])));
        $tab['lifecycles'] = array_values(array_filter(array_map('trim', explode(',', $lifeText)))) ?: ['*'];
        $tab['readonly'] = isset($readonly[$key]) ? 1 : 0;
    }
    unset($tab);
    $config['tabs'] = $tabs;
    $config['label_mode'] = in_array(($input['label_mode'] ?? ''), ['icon_short', 'short', 'icon'], true) ? $input['label_mode'] : 'icon_short';
    $config['overflow_after'] = max(4, min(16, (int)($input['overflow_after'] ?? ($config['overflow_after'] ?? 10))));
    return crm_rule_save('customer_detail_tabs', $config);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_company_settings') {
            require_permission('settings.edit');
            save_company_settings($_POST);
            $message = '公司信息已保存。';
        } elseif ($action === 'save_ui_settings') {
            require_permission('settings.edit');
            save_ui_settings($_POST);
            $message = '界面与主题设置已保存。';
        } elseif ($action === 'save_crm_customer_tabs') {
            require_permission('settings.edit');
            save_settings_customer_tabs($_POST);
            $message = 'CRM 客户中心选项卡已保存。';
        } elseif ($action === 'save_security_settings') {
            require_permission('settings.edit');
            save_security_settings($_POST);
            $message = '安全设置已保存。';
        } elseif ($action === 'save_notification_settings') {
            require_permission('settings.edit');
            save_notification_settings($_POST);
            $message = '通知设置已保存，未接入业务会在后续模块接入后生效。';
        } elseif ($action === 'save_log_settings') {
            require_permission('settings.edit');
            save_log_settings($_POST);
            $message = '日志设置已保存。';
        } elseif ($action === 'save_backup_settings') {
            require_permission('settings.schema_repair');
            save_backup_settings($_POST);
            $message = '备份策略已保存。';
        } elseif ($action === 'refresh_storage') {
            require_permission('settings.view');
            refresh_storage_usage();
            $message = '存储统计已刷新。';
        } elseif ($action === 'schema_scan') {
            require_permission('settings.schema_scan');
            get_schema_scan();
            $message = '字段检测已完成，未修改数据库。';
        } elseif ($action === 'schema_repair') {
            require_permission('settings.schema_repair');
            require_once __DIR__ . '/includes/schema.php';
            repair_permission_request_schema(db());
            save_app_setting('schema_last_repaired_at', date('Y-m-d H:i:s'), 'schema', '最近修复时间');
            save_app_setting('schema_last_repair_result', '安全修复完成', 'schema', '最近修复结果');
            audit_log('schema_repair', 'settings', 'schema', 'v18_base');
            if (function_exists('sys_action_log')) sys_action_log('settings', 'schema_repair', 'schema', 'v18_base', null, null, '一键安全修复', 'danger');
            $message = '一键安全修复已完成。';
        } elseif (in_array($action, ['backup_template', 'backup_data', 'backup_full'], true)) {
            require_permission('settings.schema_repair');
            $type = ['backup_template' => 'template', 'backup_data' => 'data', 'backup_full' => 'full'][$action];
            create_system_backup($type, 'all', '设置中心立即执行');
            $message = strtoupper($type) . ' 备份已创建。';
        } else {
            throw new RuntimeException('未知操作。');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$overview = get_system_overview();
$company = get_company_settings();
$ui = get_ui_settings();
$security = get_security_settings();
$notifications = get_notification_settings();
$logSettings = get_log_settings();
$storage = get_storage_usage();
$breakdown = get_storage_breakdown();
$logStats = get_log_stats();
$backupStats = get_backup_stats();
$schemaScan = json_decode((string)app_setting('schema_last_scan_json', '{}'), true) ?: get_schema_scan();
$crmCustomerTabConfig = crm_rule_config('customer_detail_tabs');
$crmCustomerTabs = $crmCustomerTabConfig['tabs'] ?? [];
usort($crmCustomerTabs, fn($a, $b) => ((int)($a['sort'] ?? 100) <=> (int)($b['sort'] ?? 100)));

function checked_setting($value): string { return (int)$value === 1 ? 'checked' : ''; }
function status_chip($label, $type = 'normal'): string { return '<span class="settings-status ' . h($type) . '">' . h($label) . '</span>'; }
function pie_style(array $items): string
{
    $total = max(1, array_sum(array_map(fn($i) => (float)$i['value'], $items)));
    $cursor = 0;
    $parts = [];
    foreach ($items as $item) {
        $start = $cursor;
        $cursor += ((float)$item['value'] / $total) * 100;
        $parts[] = $item['color'] . ' ' . number_format($start, 2) . '% ' . number_format($cursor, 2) . '%';
    }
    return 'background: conic-gradient(' . implode(', ', $parts) . ');';
}

require_once __DIR__ . '/includes/layout.php';
page_header('系统设置中心', ['ui_type' => 'system', 'page' => 'settings']);
if ($message) flash($message, 'success');
if ($error) flash($error, 'error');
?>
<section class="settings-console settings-control">
  <div class="settings-hero">
    <div>
      <span>System Control</span>
      <h2>系统设置中心</h2>
      <p>系统信息、公司资料、界面主题、安全策略、存储统计、备份、字段检测、通知和日志策略统一管理。</p>
    </div>
    <div class="settings-summary">
      <strong><?= h($company['system_name']) ?> <?= h($overview['version']) ?></strong>
      <span>DB <?= h($overview['database_name']) ?> · <?= $overview['runtime_writable'] ? '运行存储可写' : '运行存储不可写' ?></span>
    </div>
  </div>

  <div class="settings-health-grid">
    <div><span>PHP</span><strong><?= h($overview['php_version']) ?></strong><em><?= h($overview['server_time']) ?></em></div>
    <div><span>MySQL</span><strong><?= h($overview['mysql_version']) ?></strong><em><?= h($overview['database_name']) ?></em></div>
    <div><span>存储</span><strong><?= h(settings_format_bytes($storage['site_total_size'])) ?></strong><em><?= h($storage['last_scanned_at']) ?></em></div>
    <div><span>风险提醒</span><strong><?= $overview['install_lock_exists'] ? '安装已锁定' : '安装未锁定' ?></strong><em><?= $overview['runtime_writable'] ? 'storage 可写' : 'storage 不可写' ?></em></div>
  </div>

  <div class="settings-workspace">
    <nav class="settings-control-nav" aria-label="设置分类">
      <?php foreach (['system'=>'系统信息','company'=>'公司信息','ui'=>'界面与主题','crm-tabs'=>'CRM客户Tab','security'=>'安全设置','storage'=>'存储使用','backup'=>'备份设置','schema'=>'字段检测','notification'=>'通知设置','logs'=>'日志设置'] as $id => $label): ?>
      <a href="#<?= h($id) ?>"><?= h($label) ?></a>
      <?php endforeach; ?>
    </nav>

    <div class="settings-control-main">
      <section id="system" class="settings-control-section">
        <header><div><span>System</span><h3>系统信息</h3></div><?= status_chip($overview['installed'] ? '已安装' : '未安装', $overview['installed'] ? 'ok' : 'danger') ?></header>
        <div class="settings-metric-grid">
          <div><span>系统名称</span><strong><?= h($overview['system_name']) ?></strong></div>
          <div><span>当前版本</span><strong><?= h($overview['version']) ?></strong></div>
          <div><span>站点根目录</span><strong><?= h($overview['site_root']) ?></strong></div>
          <div><span>根目录写入</span><strong><?= $overview['root_writable'] ? '可写' : '不可写（安全）' ?></strong></div>
          <div><span>运行存储目录</span><strong><?= $overview['storage_writable'] ? '可写' : '不可写' ?></strong></div>
          <div><span>备份目录</span><strong><?= $overview['backup_writable'] ? '可写' : '不可写' ?></strong></div>
          <div><span>config.php</span><strong><?= $overview['config_exists'] ? '存在' : '缺失' ?></strong></div>
          <div><span>install.lock</span><strong><?= $overview['install_lock_exists'] ? '存在' : '缺失' ?></strong></div>
        </div>
      </section>

      <section id="company" class="settings-control-section">
        <header><div><span>Company</span><h3>公司信息</h3></div><?= has_permission('settings.edit') ? status_chip('可编辑', 'ok') : status_chip('只读', 'wait') ?></header>
        <form method="post" class="settings-form-grid">
          <?= csrf_field() ?><input type="hidden" name="action" value="save_company_settings">
          <label>系统名称<input name="system_name" required maxlength="80" value="<?= h($company['system_name']) ?>"></label>
          <label>公司中文名<input name="company_name" required maxlength="120" value="<?= h($company['company_name']) ?>"></label>
          <label>公司英文名<input name="company_name_en" maxlength="160" value="<?= h($company['company_name_en']) ?>"></label>
          <label class="wide">首页副标题<textarea name="portal_subtitle" rows="3" maxlength="240"><?= h($company['portal_subtitle']) ?></textarea></label>
          <button type="submit" <?= has_permission('settings.edit') ? '' : 'disabled' ?>>保存公司信息</button>
        </form>
      </section>

      <section id="ui" class="settings-control-section">
        <header><div><span>Interface</span><h3>界面与主题</h3></div><?= status_chip('已配置', 'ok') ?></header>
        <form method="post" class="settings-form-grid">
          <?= csrf_field() ?><input type="hidden" name="action" value="save_ui_settings">
          <label>默认主题<select name="theme"><?php foreach (['office-light'=>'Office Light','ocean-blue'=>'Ocean Blue','graphite'=>'Graphite','mint'=>'Mint','warm-sand'=>'Warm Sand','dark'=>'Dark'] as $k=>$v): ?><option value="<?= h($k) ?>" <?= $ui['theme']===$k?'selected':'' ?>><?= h($v) ?></option><?php endforeach; ?></select></label>
          <label>默认密度<select name="density"><?php foreach (['comfortable'=>'舒适','standard'=>'标准','compact'=>'紧凑'] as $k=>$v): ?><option value="<?= h($k) ?>" <?= $ui['density']===$k?'selected':'' ?>><?= h($v) ?></option><?php endforeach; ?></select></label>
          <label>首页默认模式<select name="portal_view"><option value="cards" <?= $ui['portal_view']==='cards'?'selected':'' ?>>卡片模式</option><option value="list" <?= $ui['portal_view']==='list'?'selected':'' ?>>列表模式</option></select></label>
          <div class="settings-check-grid wide">
            <label><input type="checkbox" name="show_world_time" value="1" <?= checked_setting($ui['show_world_time']) ?>> 显示世界时间</label>
            <label><input type="checkbox" name="show_system_stats" value="1" <?= checked_setting($ui['show_system_stats']) ?>> 显示系统统计</label>
            <label><input type="checkbox" name="show_module_status" value="1" <?= checked_setting($ui['show_module_status']) ?>> 显示模块状态</label>
            <label><input type="checkbox" name="show_background_art" value="1" <?= checked_setting($ui['show_background_art']) ?>> 显示背景装饰</label>
          </div>
          <div class="settings-clock-editor wide">
            <strong>全球时间配置模块</strong>
            <p>填写国家/城市、时区和排序；清空名称或时区后保存即可删除该项。</p>
            <?php foreach ($ui['world_clocks'] as $clock): ?>
            <div><input name="clock_emoji[]" value="<?= h($clock['emoji'] ?? '') ?>" placeholder="图标"><input name="clock_label[]" value="<?= h($clock['label'] ?? '') ?>" placeholder="城市/国家"><input name="clock_timezone[]" value="<?= h($clock['timezone'] ?? '') ?>" placeholder="Asia/Shanghai"><input name="clock_sort[]" type="number" value="<?= h($clock['sort'] ?? 0) ?>" placeholder="排序"></div>
            <?php endforeach; ?>
            <div><input name="clock_emoji[]" placeholder="图标"><input name="clock_label[]" placeholder="新增城市"><input name="clock_timezone[]" placeholder="时区，例如 Europe/London"><input name="clock_sort[]" type="number" placeholder="排序"></div>
          </div>
          <button type="submit" <?= has_permission('settings.edit') ? '' : 'disabled' ?>>保存界面设置</button>
        </form>
      </section>

      <section id="crm-tabs" class="settings-control-section">
        <header><div><span>CRM Customer Tabs</span><h3>CRM 客户中心选项卡</h3></div><?= has_permission('settings.edit') ? status_chip('可直接勾选', 'ok') : status_chip('只读', 'wait') ?></header>
        <p class="settings-note">这里控制客户详情顶部的选项卡。勾选就是显示，取消勾选就是隐藏；保存后重新打开客户详情即可生效。权限和客户生命周期仍会在后端继续校验。</p>
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="save_crm_customer_tabs">
          <div class="settings-form-grid">
            <label>显示方式<select name="label_mode"><option value="icon_short" <?= ($crmCustomerTabConfig['label_mode'] ?? '') === 'icon_short' ? 'selected' : '' ?>>图标 + 简写</option><option value="short" <?= ($crmCustomerTabConfig['label_mode'] ?? '') === 'short' ? 'selected' : '' ?>>只显示简写</option><option value="icon" <?= ($crmCustomerTabConfig['label_mode'] ?? '') === 'icon' ? 'selected' : '' ?>>只显示图标</option></select></label>
            <label>超过几个放入“更多”<input type="number" name="overflow_after" min="4" max="16" value="<?= h($crmCustomerTabConfig['overflow_after'] ?? 10) ?>"></label>
          </div>
          <div class="settings-table-wrap">
            <table class="data-table settings-crm-tab-table">
              <thead><tr><th>显示</th><th>选项卡</th><th>排序</th><th>图标</th><th>简称</th><th>权限控制</th><th>生命周期</th><th>只读</th></tr></thead>
              <tbody>
              <?php foreach ($crmCustomerTabs as $tab): $key = (string)($tab['key'] ?? ''); if ($key === '') continue; ?>
                <tr>
                  <td><label class="settings-switch-line"><input type="checkbox" name="tab_enabled[<?= h($key) ?>]" value="1" <?= !empty($tab['enabled']) ? 'checked' : '' ?>> 显示</label></td>
                  <td><strong><?= h($tab['name'] ?? $key) ?></strong><br><span><?= h($key) ?></span></td>
                  <td><input type="number" name="tab_sort[<?= h($key) ?>]" min="1" max="999" value="<?= h($tab['sort'] ?? 100) ?>"></td>
                  <td><input name="tab_icon[<?= h($key) ?>]" value="<?= h($tab['icon'] ?? '') ?>"></td>
                  <td><input name="tab_short[<?= h($key) ?>]" value="<?= h($tab['short'] ?? ($tab['name'] ?? $key)) ?>"></td>
                  <td><input name="tab_permission[<?= h($key) ?>]" value="<?= h($tab['permission'] ?? '') ?>" placeholder="例如 customer.bom_summary"></td>
                  <td><input name="tab_lifecycles[<?= h($key) ?>]" value="<?= h(implode(',', $tab['lifecycles'] ?? ['*'])) ?>" placeholder="* 或 lead,deal"></td>
                  <td><label class="settings-switch-line"><input type="checkbox" name="tab_readonly[<?= h($key) ?>]" value="1" <?= !empty($tab['readonly']) ? 'checked' : '' ?>> 只读</label></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="settings-inline-actions">
            <button type="submit" <?= has_permission('settings.edit') ? '' : 'disabled' ?>>保存客户中心选项卡</button>
            <span>常用：联系人、跟进、邮件、报价、PLM、BOM、派工、订单、资料、关系、日志都在这里直接打勾控制。</span>
          </div>
        </form>
      </section>

      <section id="security" class="settings-control-section">
        <header><div><span>Security</span><h3>安全设置</h3></div><?= status_chip('高危配置', 'warn') ?></header>
        <form method="post" class="settings-form-grid" data-danger-form>
          <?= csrf_field() ?><input type="hidden" name="action" value="save_security_settings">
          <label>登录失败锁定次数<input type="number" name="login_lock_threshold" min="1" max="20" value="<?= h($security['login_lock_threshold']) ?>"></label>
          <label>锁定分钟数<input type="number" name="login_lock_minutes" min="1" max="1440" value="<?= h($security['login_lock_minutes']) ?>"></label>
          <label>密码最小长度<input type="number" name="password_min_length" min="6" max="32" value="<?= h($security['password_min_length']) ?>"></label>
          <label>Session 超时分钟<input type="number" name="session_timeout_minutes" min="10" max="1440" value="<?= h($security['session_timeout_minutes']) ?>"></label>
          <div class="settings-check-grid wide">
            <label><input type="checkbox" name="require_strong_password" value="1" <?= checked_setting($security['require_strong_password']) ?>> 要求强密码</label>
            <label><input type="checkbox" name="danger_confirm" value="1" <?= checked_setting($security['danger_confirm']) ?>> 高危操作二次确认</label>
            <label><input type="checkbox" name="permission_change_confirm" value="1" <?= checked_setting($security['permission_change_confirm']) ?>> 权限变更二次确认</label>
            <label><input type="checkbox" name="block_unapproved_login" value="1" <?= checked_setting($security['block_unapproved_login']) ?>> 禁止未审核用户登录</label>
            <label><input type="checkbox" name="lock_installer_after_install" value="1" <?= checked_setting($security['lock_installer_after_install']) ?>> 安装后锁定 install.php</label>
          </div>
          <button type="submit" class="danger" <?= has_permission('settings.edit') ? '' : 'disabled' ?>>保存安全设置</button>
        </form>
      </section>

      <section id="storage" class="settings-control-section">
        <header><div><span>Storage</span><h3>存储使用</h3></div><?= status_chip('真实统计', 'ok') ?></header>
        <div class="settings-metric-grid">
          <div><span>磁盘总容量</span><strong><?= h(settings_format_bytes($storage['total_disk_space'])) ?></strong></div>
          <div><span>已用容量</span><strong><?= h(settings_format_bytes($storage['used_disk_space'])) ?></strong></div>
          <div><span>剩余容量</span><strong><?= h(settings_format_bytes($storage['free_disk_space'])) ?></strong></div>
          <div><span>站点目录</span><strong><?= h(settings_format_bytes($storage['site_total_size'])) ?></strong></div>
          <div><span>数据库</span><strong><?= h(settings_format_bytes($storage['database_size'])) ?></strong></div>
          <div><span>备份</span><strong><?= h(settings_format_bytes($storage['backup_size'])) ?></strong></div>
          <div><span>日志</span><strong><?= h(settings_format_bytes($storage['log_size'])) ?></strong></div>
        </div>
        <div class="settings-storage-layout">
          <div class="settings-pie" style="<?= h(pie_style($breakdown)) ?>"><span><?= h(settings_format_bytes($storage['site_total_size'] + $storage['database_size'])) ?></span></div>
          <div class="settings-legend">
            <?php $pieTotal = max(1, array_sum(array_map(fn($i)=>(float)$i['value'], $breakdown))); foreach ($breakdown as $item): ?>
            <div><i style="background:<?= h($item['color']) ?>"></i><strong><?= h($item['label']) ?></strong><span><?= h(settings_format_bytes($item['value'])) ?> · <?= h(number_format($item['value'] / $pieTotal * 100, 1)) ?>%</span></div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="settings-table-wrap"><table class="data-table"><thead><tr><th>类型</th><th>路径或表</th><th>大小</th><th>文件数</th><th>最近更新</th><th>状态</th></tr></thead><tbody><?php foreach ($storage['details'] as $row): ?><tr><td><?= h($row['type']) ?></td><td><?= h($row['path']) ?></td><td><?= h(settings_format_bytes($row['size'])) ?></td><td><?= h($row['files'] ?? '-') ?></td><td><?= h($row['updated_at'] ?? '-') ?></td><td><?= h($row['status']) ?></td></tr><?php endforeach; ?></tbody></table></div>
        <form method="post" class="settings-inline-actions"><?= csrf_field() ?><input type="hidden" name="action" value="refresh_storage"><button type="submit">刷新存储统计</button><span>最近扫描：<?= h($storage['last_scanned_at']) ?></span></form>
      </section>

      <section id="backup" class="settings-control-section">
        <header><div><span>Backup</span><h3>备份设置</h3></div><?= status_chip($backupStats['status'], $backupStats['status']==='已接入'?'ok':'wait') ?></header>
        <div class="settings-metric-grid">
          <div><span>备份数量</span><strong><?= h($backupStats['backup_count']) ?></strong></div>
          <div><span>备份总大小</span><strong><?= h(settings_format_bytes($backupStats['backup_size'])) ?></strong></div>
          <div><span>最近备份</span><strong><?= h($backupStats['recent_backup_time']) ?></strong></div>
          <div><span>模板/数据/整站</span><strong><?= h($backupStats['template_count']) ?> / <?= h($backupStats['data_count']) ?> / <?= h($backupStats['full_count']) ?></strong></div>
          <div><span>自动备份</span><strong><?= $backupStats['auto_enabled'] ? '启用' : '关闭' ?></strong></div>
          <div><span>下次执行</span><strong><?= h($backupStats['next_run_at']) ?></strong></div>
        </div>
        <form method="post" class="settings-form-grid" data-danger-form>
          <?= csrf_field() ?><input type="hidden" name="action" value="save_backup_settings">
          <label>启用自动备份<select name="backup_enabled"><option value="1" <?= $backupStats['auto_enabled']?'selected':'' ?>>启用</option><option value="0" <?= !$backupStats['auto_enabled']?'selected':'' ?>>关闭</option></select></label>
          <label>执行时间<input name="run_time" value="02:00" pattern="\d{2}:\d{2}"></label>
          <label>保留份数<input type="number" name="retain_count" min="1" max="120" value="14"></label>
          <div class="settings-check-grid wide"><label><input type="checkbox" name="backup_type[]" value="template" checked> 模板备份</label><label><input type="checkbox" name="backup_type[]" value="data" checked> 数据备份</label><label><input type="checkbox" name="backup_type[]" value="full"> 整站备份</label></div>
          <button type="submit" class="danger" <?= has_permission('settings.schema_repair') ? '' : 'disabled' ?>>保存备份策略</button>
        </form>
        <div class="settings-inline-actions">
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="backup_template"><button <?= has_permission('settings.schema_repair') ? '' : 'disabled' ?>>立即模板备份</button></form>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="backup_data"><button <?= has_permission('settings.schema_repair') ? '' : 'disabled' ?>>立即数据备份</button></form>
          <form method="post" data-danger-form><?= csrf_field() ?><input type="hidden" name="action" value="backup_full"><button class="danger" <?= has_permission('settings.schema_repair') ? '' : 'disabled' ?>>立即整站备份</button></form>
          <a class="button secondary" href="backup/full_backup.php">查看备份中心</a><a class="button secondary" href="backup/restore.php">恢复中心</a>
        </div>
      </section>

      <section id="schema" class="settings-control-section">
        <header><div><span>Schema</span><h3>字段检测 / 一键修复</h3></div><?= $schemaScan['missing_tables'] || $schemaScan['missing_fields'] ? status_chip('存在缺失', 'warn') : status_chip('结构正常', 'ok') ?></header>
        <div class="settings-metric-grid">
          <div><span>已安装表</span><strong><?= h($schemaScan['installed_tables']) ?></strong></div>
          <div><span>缺失表</span><strong><?= h($schemaScan['missing_tables']) ?></strong></div>
          <div><span>缺失字段</span><strong><?= h($schemaScan['missing_fields']) ?></strong></div>
          <div><span>缺失索引</span><strong><?= h($schemaScan['missing_indexes']) ?></strong></div>
          <div><span>最近检测</span><strong><?= h($schemaScan['last_scanned_at']) ?></strong></div>
          <div><span>最近修复</span><strong><?= h($schemaScan['last_repaired_at']) ?></strong></div>
        </div>
        <p class="settings-note">只检测不会修改数据库；一键安全修复仅执行安装 SQL 中允许的 CREATE TABLE IF NOT EXISTS / 幂等 INSERT / 安全 ALTER，不允许 DROP、TRUNCATE、DELETE。</p>
        <div class="settings-inline-actions"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="schema_scan"><button>只检测</button></form><form method="post" data-danger-form><?= csrf_field() ?><input type="hidden" name="action" value="schema_repair"><button class="danger" <?= has_permission('settings.schema_repair') ? '' : 'disabled' ?>>一键安全修复</button></form></div>
      </section>

      <section id="notification" class="settings-control-section">
        <header><div><span>Notification</span><h3>通知设置</h3></div><?= status_chip('已配置 / 部分待接入', 'wait') ?></header>
        <form method="post" class="settings-notice-form">
          <?= csrf_field() ?><input type="hidden" name="action" value="save_notification_settings">
          <table class="data-table"><thead><tr><th>通知类型</th><th>站内</th><th>弹窗</th><th>角标</th><th>声音</th><th>提前小时</th><th>状态</th></tr></thead><tbody>
            <?php $noticeLabels = ['register_review'=>'注册审核','permission_request'=>'权限申请','approval_result'=>'审批结果','temporary_expire'=>'临时权限到期','danger_operation'=>'高危操作','backup_done'=>'备份完成','schema_repair_done'=>'字段修复完成','abnormal_login'=>'登录异常']; foreach ($notifications as $key => $row): ?>
            <tr><td><?= h($noticeLabels[$key] ?? $key) ?></td><td><input type="checkbox" name="<?= h($key) ?>_site" value="1" <?= checked_setting($row['site']) ?>></td><td><input type="checkbox" name="<?= h($key) ?>_popup" value="1" <?= checked_setting($row['popup']) ?>></td><td><input type="checkbox" name="<?= h($key) ?>_badge" value="1" <?= checked_setting($row['badge']) ?>></td><td><input type="checkbox" name="<?= h($key) ?>_sound" value="1" <?= checked_setting($row['sound']) ?>></td><td><input type="number" name="<?= h($key) ?>_advance_hours" value="<?= h($row['advance_hours']) ?>" min="0" max="720"></td><td><?= in_array($key, ['register_review','permission_request','approval_result'], true) ? '已接入' : '配置已保存，业务接入后生效' ?></td></tr>
            <?php endforeach; ?>
          </tbody></table>
          <button type="submit" <?= has_permission('settings.edit') ? '' : 'disabled' ?>>保存通知设置</button>
        </form>
      </section>

      <section id="logs" class="settings-control-section">
        <header><div><span>Logs</span><h3>日志设置</h3></div><?= status_chip('真实统计', 'ok') ?></header>
        <div class="settings-metric-grid">
          <div><span>登录日志</span><strong><?= h($logStats['login_logs']) ?></strong></div>
          <div><span>操作日志</span><strong><?= h($logStats['action_logs']) ?></strong></div>
          <div><span>权限日志</span><strong><?= h($logStats['permission_logs']) ?></strong></div>
          <div><span>首页日志</span><strong><?= h($logStats['homepage_logs']) ?></strong></div>
          <div><span>高危日志</span><strong><?= h($logStats['danger_logs']) ?></strong></div>
          <div><span>日志表大小</span><strong><?= h(settings_format_bytes($logStats['log_table_size'])) ?></strong></div>
          <div><span>最近日志</span><strong><?= h($logStats['recent_log_time']) ?></strong></div>
        </div>
        <form method="post" class="settings-form-grid">
          <?= csrf_field() ?><input type="hidden" name="action" value="save_log_settings">
          <label>登录日志保留天数<input type="number" name="login_retention_days" value="<?= h($logSettings['login_retention_days']) ?>" min="7" max="3650"></label>
          <label>操作日志保留天数<input type="number" name="action_retention_days" value="<?= h($logSettings['action_retention_days']) ?>" min="7" max="3650"></label>
          <div class="settings-check-grid wide"><label><input type="checkbox" name="keep_danger_forever" value="1" <?= checked_setting($logSettings['keep_danger_forever']) ?>> 高危日志永久保留</label><label><input type="checkbox" name="homepage_log_enabled" value="1" <?= checked_setting($logSettings['homepage_log_enabled']) ?>> 首页日志</label><label><input type="checkbox" name="module_click_log_enabled" value="1" <?= checked_setting($logSettings['module_click_log_enabled']) ?>> 模块点击日志</label><label><input type="checkbox" name="settings_change_log_enabled" value="1" <?= checked_setting($logSettings['settings_change_log_enabled']) ?>> 设置修改日志</label><label><input type="checkbox" name="permission_change_log_enabled" value="1" <?= checked_setting($logSettings['permission_change_log_enabled']) ?>> 权限变更日志</label></div>
          <button type="submit" <?= has_permission('settings.edit') ? '' : 'disabled' ?>>保存日志设置</button>
        </form>
        <div class="settings-inline-actions"><a class="button secondary" href="logs.php">查看日志中心</a><button type="button" disabled>导出日志（待接入）</button><button type="button" disabled>清理过期普通日志（待接入）</button><span>清理策略已保存；执行清理功能后续接入，且不会删除高危日志。</span></div>
      </section>
    </div>
  </div>
</section>
<?php page_footer(); ?>
