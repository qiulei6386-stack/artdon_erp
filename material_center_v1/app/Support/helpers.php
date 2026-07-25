<?php
declare(strict_types=1);

function mc_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
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

function mc_page_start(string $title, string $active, ?array $user = null, string $prefix = ''): void
{
    $links = [
        'home' => [$prefix . './', '总', '物料总览'],
        'power' => [$prefix . 'power_supplies.php', '源', '电源源数据'],
        'standardize' => [$prefix . 'power_standardization.php', '标', '电源标准化'],
        'library' => [$prefix . 'formal_power_supplies.php', '库', '正式电源库'],
        'bands' => [$prefix . 'power_bands.php', '档', '功率档管理'],
        'audit' => [$prefix . 'bom_audit.php', '审', 'BOM 源审计'],
        'status' => [$prefix . 'system_status.php', '态', '系统状态'],
        'gallery' => [$prefix . 'ui/docs/component-gallery.php', 'UI', '组件展示'],
    ];
    echo '<!doctype html><html lang="zh-CN" data-theme="system"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . mc_h($title) . ' · Artdon 物料中心</title><script>document.documentElement.dataset.theme=localStorage.getItem("artdon-ui-theme")||"system";</script>';
    echo '<link rel="stylesheet" href="' . mc_h($prefix) . 'ui/index.css"><link rel="stylesheet" href="' . mc_h($prefix) . 'assets/css/app.css"></head><body>';
    echo '<div class="ui-shell"><aside class="ui-sidebar"><div class="ui-brand"><span class="ui-brand-mark">AD</span><div class="ui-brand-copy"><b>物料中心</b><small>Material Center V1</small></div></div>';
    echo '<button class="ui-btn ui-btn-ghost ui-sidebar-toggle" type="button" data-ui-sidebar-toggle aria-label="收起或展开导航">收起导航</button><nav class="ui-nav">';
    foreach ($links as $key => [$href, $icon, $label]) {
        echo '<a href="' . mc_h($href) . '"' . ($active === $key ? ' aria-current="page"' : '') . '><i class="ui-nav-icon">' . mc_h($icon) . '</i><span>' . mc_h($label) . '</span></a>';
    }
    echo '</nav><div class="ui-side-note"><b>安全旁路模式</b><span>只读旧 BOM 物料源，不写价格、供应商或旧表结构。</span></div></aside><main class="ui-main">';
    echo '<header class="ui-topbar"><div class="ui-topbar-group"><button class="ui-btn ui-btn-ghost ui-btn-icon ui-mobile-nav" type="button" data-ui-mobile-nav aria-label="打开导航">☰</button><span class="ui-muted ui-breadcrumb-extra">广州 ERP / 物料中心</span><b>/</b><strong>' . mc_h($title) . '</strong></div>';
    echo '<div class="ui-topbar-group"><button class="ui-btn ui-btn-ghost ui-btn-sm ui-page-actions" type="button" data-ui-presentation>展示模式</button><div class="ui-dropdown ui-page-actions"><button class="ui-btn ui-btn-secondary ui-btn-sm" type="button" aria-expanded="false" aria-controls="theme-menu" data-ui-dropdown-trigger>主题</button><div class="ui-menu" id="theme-menu" role="menu" aria-hidden="true"><button type="button" data-ui-theme="light">浅色</button><button type="button" data-ui-theme="dark">深色</button><button type="button" data-ui-theme="system">跟随系统</button></div></div><span class="ui-muted">' . mc_h($user['real_name'] ?? $user['username'] ?? '未登录') . '</span></div></header><section class="ui-content">';
}

function mc_page_end(string $prefix = '', string $pageScript = ''): void
{
    echo '</section></main></div><div class="ui-mask" data-ui-mask></div><div class="ui-toast-region" data-ui-toast-region role="status" aria-live="polite"></div>';
    foreach (['interaction-manager','confirm-modal','dropdown','modal','drawer','toast','table','app-shell'] as $script) {
        echo '<script src="' . mc_h($prefix) . 'ui/js/' . $script . '.js" defer></script>';
    }
    if ($pageScript !== '') {
        echo '<script src="' . mc_h($prefix . $pageScript) . '" defer></script>';
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
