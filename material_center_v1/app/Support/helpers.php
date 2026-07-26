<?php
declare(strict_types=1);

if (!function_exists('mc_h')) {
    function mc_h(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function mc_current_user(): ?array
{
    try {
        $user = function_exists('current_user') ? current_user() : null;
        return is_array($user) && !empty($user['id']) ? $user : null;
    } catch (Throwable) {
        return null;
    }
}

function mc_table_exists(string $table): bool
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return false;
    }
    try {
        $statement = db()->prepare(
            'SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1'
        );
        $statement->execute([$table]);
        return (bool)$statement->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}

function mc_asset_url(mixed $path): string
{
    $path = ltrim(trim((string)$path), '/');
    if ($path === '' || str_contains($path, '..') || !preg_match('#^(uploads|assets)/[A-Za-z0-9_./-]+$#', $path)) {
        return '';
    }
    return '../' . $path;
}

function mc_ui_asset(string $path, string $prefix = ''): string
{
    $path = ltrim($path, '/');
    $file = MC_ROOT . '/' . $path;
    $version = is_file($file) ? (string) filemtime($file) : '1';
    return $prefix . $path . '?v=' . rawurlencode($version);
}

function mc_page_start(string $title, string $active, ?array $user = null, string $prefix = ''): void
{
    $uiValues=[];
    try {
        if (mc_table_exists('mc_ui_settings')) {
            $context=(new \Artdon\MaterialCenter\Adapters\LegacyAuthAdapter())->current();
            $uiValues=(new \Artdon\MaterialCenter\Services\SettingsService())->resolved($context)['values']??[];
        }
    } catch (Throwable) {}
    $pending=static fn(string $page)=>$prefix.'module.php?page='.$page;
    $groups = [
        '工作台'=>['home'=>[$prefix.'./','⌂','物料总览']],
        '物料库'=>['materials'=>[$prefix.'materials.php','物','全部物料'],'power'=>[$prefix.'power_workbench.php','电','电源'],'chips'=>[$prefix.'category_workbench.php?category=chips','芯','芯片'],'optics'=>[$prefix.'category_workbench.php?category=optics','光','光学'],'profiles'=>[$prefix.'category_workbench.php?category=profiles','型','型材 / 散热件'],'mounting'=>[$prefix.'category_workbench.php?category=mounting','装','接头 / 安装件'],'accessories'=>[$prefix.'category_workbench.php?category=accessories','配','配件'],'packaging'=>[$prefix.'category_workbench.php?category=packaging','包','包装']],
        '产品适配'=>['adaptation'=>[$prefix.'product_adaptation.php','适','产品适配']],
        '供应商与价格'=>['suppliers'=>[$prefix.'supplier/index.php','供','供应商资料'],'supplier_materials'=>[$prefix.'supplier/index.php','料','供应商物料'],'prices'=>[$prefix.'supplier/index.php','价','采购价管理'],'price_history'=>[$prefix.'supplier/index.php','史','价格历史'],'moq'=>[$prefix.'supplier/index.php','期','MOQ / 交期']],
        '替代与版本'=>['alternatives'=>[$prefix.'substitute/index.php','替','替代物料'],'versions'=>[$prefix.'substitute/index.php','版','物料版本'],'changes'=>[$prefix.'substitute/index.php','变','变更记录']],
        '数据接入'=>['excel_import'=>[$prefix.'data/index.php','入','Excel 导入任务'],'exports'=>[$prefix.'data/index.php','出','导出任务'],'sync_logs'=>[$prefix.'data/index.php','同','同步日志']],
        '文档与日志'=>['documents'=>[$prefix.'documents/index.php','文','规格与认证文件'],'images'=>[$prefix.'documents/index.php','图','图片资料'],'logs'=>[$prefix.'documents/index.php?tab=logs','志','操作日志']],
        '系统与设置'=>['settings'=>[$prefix.'settings/index.php','设','外观与主题'],'permissions'=>[$prefix.'../permissions.php?tab=matrix','权','统一权限中心'],'status'=>[$prefix.'system_status.php','态','系统状态'],'gallery'=>[$prefix.'ui-gallery.php','UI','组件展示'],'design_spec'=>[$prefix.'docs/UI_BASELINE.md','规','设计规范']],
    ];
    echo '<!doctype html><html lang="zh-CN" data-theme="system"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    $theme=(string)($uiValues['theme.mode']??'light');
    echo '<title>' . mc_h($title) . ' · Artdon 物料中心</title><script>document.documentElement.dataset.theme=localStorage.getItem("artdon-ui-theme")||'.json_encode($theme).';</script>';
    echo '<link rel="stylesheet" href="' . mc_h(mc_ui_asset('ui/index.css',$prefix)) . '"><link rel="stylesheet" href="' . mc_h(mc_ui_asset('assets/css/app.css',$prefix)) . '"></head><body>';
    $fontMap=['system'=>'-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC",sans-serif','noto_sans_sc'=>'"Noto Sans SC","PingFang SC",sans-serif','arial'=>'Arial,"PingFang SC",sans-serif'];
    $primary=preg_match('/^#[0-9a-f]{6}$/i',(string)($uiValues['theme.primary']??''))?$uiValues['theme.primary']:'#087f8c';
    $sidebar=preg_match('/^#[0-9a-f]{6}$/i',(string)($uiValues['theme.sidebar']??''))?$uiValues['theme.sidebar']:'#ffffff';
    $base=max(12,min(18,(float)($uiValues['font.base_px']??14)));$nav=max(12,min(18,(float)($uiValues['font.nav_px']??14)));$table=max(11,min(17,(float)($uiValues['font.table_px']??13)));
    $tokenMap=['--ui-bg'=>['color.page_bg','#f6f8fb'],'--ui-surface'=>['color.surface','#ffffff'],'--ui-border'=>['color.border','#e2e8f0'],'--ui-text'=>['color.text','#0f172a'],'--ui-text-muted'=>['color.text_muted','#64748b'],'--ui-success'=>['color.success','#168a5b'],'--ui-warning'=>['color.warning','#d97706'],'--ui-danger'=>['color.danger','#dc2626']];
    $css='--ui-primary:'.mc_h($primary).';--ui-sidebar:'.mc_h($sidebar).';--ui-font:'.mc_h($fontMap[$uiValues['font.family']??'system']??$fontMap['system']).';--mc-base-font-size:'.$base.'px;--mc-nav-font-size:'.$nav.'px;--mc-table-font-size:'.$table.'px;';
    foreach($tokenMap as$var=>[$key,$fallback]){$value=(string)($uiValues[$key]??$fallback);if(preg_match('/^#[0-9a-f]{6}$/i',$value))$css.=$var.':'.mc_h($value).';';}
    foreach(['--ui-sidebar-width'=>['layout.sidebar_width',220,200,280],'--ui-topbar-height'=>['layout.topbar_height',56,48,72],'--ui-radius-lg'=>['layout.radius',8,0,20],'--ui-drawer-width'=>['layout.drawer_width',520,460,620],'--ui-row-height'=>['table.row_height',44,32,64]]as$var=>[$key,$fallback,$min,$max])$css.=$var.':'.max($min,min($max,(float)($uiValues[$key]??$fallback))).'px;';
    echo '<style>:root{'.$css.'}.ui-content{padding:'.max(12,min(40,(float)($uiValues['layout.page_padding']??20))).'px}.ui-page-head h1{font-size:'.max(20,min(40,(float)($uiValues['font.title_px']??28))).'px}.ui-page-head p{font-size:'.max(12,min(20,(float)($uiValues['font.subtitle_px']??14))).'px}.ui-label{font-size:'.max(10,min(16,(float)($uiValues['font.label_px']??12))).'px}.ui-input,.ui-select{font-size:'.max(11,min(18,(float)($uiValues['font.input_px']??13))).'px}.ui-btn{font-size:'.max(10,min(16,(float)($uiValues['font.button_px']??12))).'px}.ui-pagination{font-size:'.max(10,min(16,(float)($uiValues['font.pagination_px']??12))).'px}</style>';
    echo '<div class="ui-shell"><aside class="ui-sidebar"><div class="ui-brand"><span class="ui-brand-mark">AD</span><div class="ui-brand-copy"><b>物料中心</b><small>Material Center V1</small></div></div>';
    echo '<button class="ui-btn ui-btn-ghost ui-sidebar-toggle" type="button" data-ui-sidebar-toggle aria-label="收起或展开导航">收起导航</button><nav class="ui-nav">';
    foreach ($groups as $group => $links) {
        echo '<details class="ui-nav-group"' . (array_key_exists($active,$links)?' open':'') . '><summary>'.mc_h($group).'<span aria-hidden="true">⌄</span></summary><div>';
        foreach ($links as $key => [$href, $icon, $label]) echo '<a href="' . mc_h($href) . '"' . ($active === $key ? ' aria-current="page"' : '') . '><i class="ui-nav-icon">' . mc_h($icon) . '</i><span>' . mc_h($label) . '</span></a>';
        echo '</div></details>';
    }
    echo '</nav><div class="ui-side-note"><b>安全旁路模式</b><span>旧 BOM 只读，新数据仅写 mc_ 表。</span></div></aside><main class="ui-main">';
    echo '<header class="ui-topbar"><div class="ui-topbar-group"><button class="ui-btn ui-btn-ghost ui-btn-icon ui-mobile-nav" type="button" data-ui-mobile-nav aria-label="打开导航">☰</button><span class="ui-muted ui-breadcrumb-extra">广州 ERP / 物料中心</span><b>/</b><strong>' . mc_h($title) . '</strong></div>';
    echo '<div class="ui-topbar-group"><button class="ui-btn ui-btn-ghost ui-btn-sm ui-page-actions" type="button" data-ui-presentation>展示模式</button><div class="ui-dropdown ui-page-actions"><button class="ui-btn ui-btn-secondary ui-btn-sm" type="button" aria-expanded="false" aria-controls="theme-menu" data-ui-dropdown-trigger>主题</button><div class="ui-menu" id="theme-menu" role="menu" aria-hidden="true"><button type="button" data-ui-theme="light">浅色</button><button type="button" data-ui-theme="dark">深色</button><button type="button" data-ui-theme="system">跟随系统</button></div></div><span class="ui-muted">' . mc_h($user['real_name'] ?? $user['username'] ?? '未登录') . '</span></div></header><section class="ui-content">';
}

function mc_page_end(string $prefix = '', string $pageScript = ''): void
{
    echo '</section></main></div><div class="ui-mask" data-ui-mask></div><div class="ui-toast-region" data-ui-toast-region role="status" aria-live="polite"></div>';
    foreach (['interaction-manager','confirm-modal','dropdown','modal','drawer','toast','table','app-shell'] as $script) {
        echo '<script src="' . mc_h(mc_ui_asset('ui/js/' . $script . '.js',$prefix)) . '" defer></script>';
    }
    if ($pageScript !== '') {
        echo '<script src="' . mc_h(mc_ui_asset($pageScript,$prefix)) . '" defer></script>';
    }
    echo '</body></html>';
}

function mc_state(string $type, string $title, string $message, string $actionHref = '', string $actionLabel = ''): void
{
    $icons = ['loading'=>'…','empty'=>'0','error'=>'!','permission'=>'锁','config'=>'⚙','offline'=>'↯'];
    echo '<section class="ui-card ui-state ui-state-' . mc_h($type) . '"><div class="ui-state-inner"><div class="ui-state-icon">' . mc_h($icons[$type] ?? 'i') . '</div><h2>' . mc_h($title) . '</h2><p>' . mc_h($message) . '</p>';
    if ($actionHref !== '') {
        echo '<div class="ui-state-actions"><a class="ui-btn ui-btn-secondary" href="' . mc_h($actionHref) . '">' . mc_h($actionLabel ?: '重试') . '</a></div>';
    }
    echo '</div></section>';
}
