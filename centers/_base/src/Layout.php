<?php
declare(strict_types=1);

namespace Artdon\Centers\Base;

/**
 * 页面外框：左侧导航 + 顶栏 + 内容区。所有中心的页面共用，保证外观一致。
 *
 *   Layout::start(['title' => '个人账号', 'nav' => 'profile', 'crumbs' => ['系统' => null, '个人账号' => null]]);
 *   ... 页面内容 ...
 *   Layout::end();
 */
final class Layout
{
    public static function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /** 系统名称：设置中心里的 system_name。 */
    public static function systemName(): string
    {
        return SystemSettings::systemName();
    }

    /** 品牌标识：有 Logo 用 Logo，没有用字母 A。 */
    private static function brandMark(bool $large = false): string
    {
        $logo = SystemSettings::logo();
        if ($logo !== '') {
            return '<img class="brand-logo' . ($large ? ' brand-logo-lg' : '') . '" src="' . self::e($logo) . '" alt="">';
        }
        return '<span class="side-mark' . ($large ? ' side-mark-lg' : '') . '" aria-hidden="true">A</span>';
    }

    /** 首页：目前是个人账号，以后有工作台后改这里。 */
    public static function homeUrl(): string
    {
        return Url::center('system', 'profile.php');
    }

    /** @var list<string> 本页额外加载的脚本地址 */
    private static $scripts = [];

    /** @var list<string> 页面自己的样式表 */
    private static $styles = [];

    /**
     * @param array{title?: string, nav?: string, crumbs?: array<string, ?string>, scripts?: list<string>, styles?: list<string>} $opts
     */
    public static function start(array $opts): void
    {
        self::$scripts = $opts['scripts'] ?? [];
        self::$styles = $opts['styles'] ?? [];
        $title = (string) ($opts['title'] ?? '');
        $crumbs = $opts['crumbs'] ?? [$title => null];
        $user = Auth::user();
        $groups = Nav::visible((string) ($opts['nav'] ?? ''));
        self::head($title, 'app');
        echo '<div class="app">';
        echo '<aside class="side" id="side" aria-label="导航">';
        echo '<a class="side-brand" href="' . self::e(self::homeUrl()) . '">' . self::brandMark() . '<span class="side-brand-text"><strong>ARTDON</strong><small>' . self::e(self::systemName()) . '</small></span></a>';
        foreach ($groups as $group) {
            if ($group['title'] !== '') {
                echo '<div class="side-title">' . self::e($group['title']) . '</div>';
            }
            echo '<nav class="side-nav" aria-label="' . self::e($group['title'] !== '' ? $group['title'] : '业务中心') . '">';
            foreach ($group['items'] as $item) {
                $class = 'side-link' . (!empty($item['active']) ? ' is-active' : '') . (empty($item['icon']) ? ' is-plain' : '') . (empty($item['ready']) ? ' is-pending' : '');
                $inner = (!empty($item['icon']) ? Icon::svg((string) $item['icon'], 16, 'side-icon') : '')
                    . '<span class="side-label">' . self::e($item['label']) . '</span>'
                    . (empty($item['ready']) ? '<span class="side-tag">建设中</span>' : '');
                if (!empty($item['ready'])) {
                    echo '<a class="' . $class . '" href="' . self::e($item['href']) . '"' . (!empty($item['active']) ? ' aria-current="page"' : '') . '>' . $inner . '</a>';
                } else {
                    echo '<span class="' . $class . '" aria-disabled="true">' . $inner . '</span>';
                }
            }
            echo '</nav>';
        }
        if ($user) {
            echo '<div class="side-user"><span class="avatar" aria-hidden="true">' . self::e(mb_substr(Auth::displayName($user), 0, 1, 'UTF-8')) . '</span><span class="side-user-text"><strong>' . self::e(Auth::displayName($user)) . '</strong><small>' . self::e(trim(($user['department_name'] ?? '') . ' · ' . ($user['role_name'] ?? ''), ' ·')) . '</small></span></div>';
        }
        echo '</aside>';
        echo '<div class="main">';
        echo '<header class="top">';
        echo '<button type="button" class="icon-btn top-menu" data-toggle-side aria-controls="side" aria-expanded="false" aria-label="打开导航">' . Icon::svg('menu', 18) . '</button>';
        echo '<nav class="crumbs" aria-label="当前位置">';
        $i = 0;
        $count = count($crumbs);
        foreach ($crumbs as $label => $href) {
            $i++;
            if ($i > 1) {
                echo '<span class="crumb-sep" aria-hidden="true">/</span>';
            }
            if ($href !== null && $i < $count) {
                echo '<a href="' . self::e($href) . '">' . self::e($label) . '</a>';
            } else {
                echo '<span' . ($i === $count ? ' class="crumb-current" aria-current="page"' : '') . '>' . self::e($label) . '</span>';
            }
        }
        echo '</nav>';
        echo '<div class="top-fill"></div>';
        if ($user) {
            $unread = Notify::unreadCount((int) $user['id']);
            echo '<a class="icon-btn top-bell" href="' . self::e(Url::center('system', 'notifications.php')) . '" aria-label="' . ($unread > 0 ? '通知，' . $unread . ' 条未读' : '通知') . '" title="通知">' . Icon::svg('bell', 18) . ($unread > 0 ? '<span class="bell-count" aria-hidden="true">' . ($unread > 99 ? '99+' : $unread) . '</span>' : '') . '</a>';
            echo '<details class="usermenu"><summary class="usermenu-btn"><span class="avatar avatar-sm" aria-hidden="true">' . self::e(mb_substr(Auth::displayName($user), 0, 1, 'UTF-8')) . '</span><span class="usermenu-name">' . self::e(Auth::displayName($user)) . '</span></summary>';
            echo '<div class="usermenu-panel">';
            echo '<div class="usermenu-head"><strong>' . self::e(Auth::displayName($user)) . '</strong><small>' . self::e((string) ($user['username'] ?? '')) . '</small></div>';
            echo '<a class="usermenu-item" href="' . self::e(Url::center('system', 'profile.php')) . '">' . Icon::svg('user', 15) . '<span>个人账号</span></a>';
            echo '<form method="post" action="' . self::e(Url::center('system', 'logout.php')) . '">' . self::csrfField() . '<button type="submit" class="usermenu-item">' . Icon::svg('logout', 15) . '<span>退出登录</span></button></form>';
            echo '</div></details>';
        }
        echo '</header>';
        echo '<main class="page" id="main">';
        self::flashes();
    }

    public static function end(): void
    {
        echo '</main></div></div>';
        self::foot();
    }

    /** 登录等未登录页面：居中卡片布局。 */
    public static function authStart(string $title): void
    {
        self::head($title, 'auth');
        echo '<div class="auth">';
        echo '<section class="auth-brand"><div class="auth-brand-inner">' . self::brandMark(true) . '<strong class="auth-brand-name">ARTDON</strong><p class="auth-brand-sub">' . self::e(self::systemName()) . '</p></div></section>';
        echo '<main class="auth-main" id="main"><div class="auth-card">';
        self::flashes();
    }

    public static function authEnd(): void
    {
        echo '</div></main></div>';
        self::foot();
    }

    /** 无权限页，输出后结束请求。 */
    public static function forbidden(string $permission): void
    {
        http_response_code(403);
        self::start(['title' => '没有权限', 'nav' => '']);
        echo '<div class="empty"><div class="empty-icon">' . Icon::svg('lock', 28) . '</div><h1 class="empty-title">没有权限访问这个页面</h1><p class="empty-text">需要的权限：<code>' . self::e($permission) . '</code>。如需开通，请联系管理员或在权限中心提交申请。</p><a class="btn" href="' . self::e(self::homeUrl()) . '">返回首页</a></div>';
        self::end();
        exit;
    }

    public static function csrfField(): string
    {
        return Csrf::field();
    }

    /** 出错页（不显示技术细节），输出后结束请求。 */
    public static function errorPage(int $status, string $title, string $text): void
    {
        if (!headers_sent()) {
            http_response_code($status);
        }
        self::head($title, 'auth');
        echo '<main class="error-page" id="main"><div class="empty"><div class="empty-icon">' . Icon::svg('lock', 28) . '</div><h1 class="empty-title">' . self::e($title) . '</h1><p class="empty-text">' . self::e($text) . '</p></div></main>';
        self::foot();
        exit;
    }

    private static function head(string $title, string $bodyClass): void
    {
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('X-Frame-Options: SAMEORIGIN');
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: same-origin');
        }
        $full = $title !== '' ? $title . ' · ' . self::systemName() : self::systemName();
        echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<meta name="robots" content="noindex">';
        echo '<meta name="csrf-token" content="' . self::e(session_status() === PHP_SESSION_ACTIVE ? Csrf::token() : '') . '">';
        echo '<meta name="centers-login" content="' . self::e(Url::center('system', 'login.php')) . '">';
        echo '<title>' . self::e($full) . '</title>';
        echo '<link rel="stylesheet" href="' . self::e(Url::asset('base.css')) . '">';
        foreach (self::$styles as $href) {
            echo '<link rel="stylesheet" href="' . self::e($href) . '">';
        }
        echo '</head><body class="body-' . self::e($bodyClass) . '">';
        echo '<a class="skip" href="#main">跳到主要内容</a>';
    }

    private static function foot(): void
    {
        echo '<script src="' . self::e(Url::asset('base.js')) . '" defer></script>';
        foreach (self::$scripts as $src) {
            echo '<script src="' . self::e($src) . '" defer></script>';
        }
        echo '</body></html>';
    }

    private static function flashes(): void
    {
        foreach (Flash::take() as $flash) {
            self::alert($flash['type'], $flash['message']);
        }
    }

    public static function alert(string $type, string $message): void
    {
        $type = in_array($type, ['success', 'error', 'warning', 'info'], true) ? $type : 'info';
        $role = $type === 'error' ? 'alert' : 'status';
        echo '<div class="alert alert-' . $type . '" role="' . $role . '">' . self::e($message) . '</div>';
    }
}
