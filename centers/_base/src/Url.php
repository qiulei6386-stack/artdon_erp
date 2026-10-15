<?php
declare(strict_types=1);

namespace Artdon\Centers\Base;

/**
 * 生成站内地址。系统可能部署在域名根目录，也可能在子目录（如 /artdon_erp），
 * 所以所有地址都从当前脚本路径推算，不写死。
 */
final class Url
{
    /** 应用根路径，例如 '' 或 '/artdon_erp'，不带结尾斜杠。 */
    public static function appBase(): string
    {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $pos = strpos($script, '/centers/');
        if ($pos !== false) {
            $base = substr($script, 0, $pos);
        } else {
            $base = rtrim(str_replace('\\', '/', dirname($script)), '/.');
        }
        return $base === '/' ? '' : $base;
    }

    /** 应用内地址：Url::app('centers/system/login.php') → /artdon_erp/centers/system/login.php */
    public static function app(string $path = ''): string
    {
        return self::appBase() . '/' . ltrim($path, '/');
    }

    /** 某个中心内的地址：Url::center('system', 'login.php') */
    public static function center(string $center, string $path = ''): string
    {
        return self::app('centers/' . $center . '/' . ltrim($path, '/'));
    }

    /** 公共底座静态资源，带文件修改时间作为版本号，避免浏览器缓存旧样式。 */
    public static function asset(string $path): string
    {
        $path = ltrim($path, '/');
        $file = ARTDON_CENTERS_ROOT . '/_base/assets/' . $path;
        $version = is_file($file) ? (string) filemtime($file) : '0';
        return self::app('centers/_base/assets/' . $path) . '?v=' . $version;
    }

    /** 当前请求的地址（路径 + 查询串），用于登录后跳回。 */
    public static function current(): string
    {
        return (string) ($_SERVER['REQUEST_URI'] ?? '');
    }

    /**
     * 只允许跳回本站的相对地址，防止登录页被利用跳到外站。
     * 拒绝带协议、// 开头、反斜杠的地址，以及登录/退出页本身。
     */
    public static function safeRedirect(string $url, string $fallback): string
    {
        $url = str_replace(["\r", "\n"], '', trim($url));
        if ($url === '') {
            return $fallback;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) || strpos($url, '//') === 0 || strpos($url, '\\') !== false) {
            return $fallback;
        }
        $page = basename((string) parse_url($url, PHP_URL_PATH));
        if (in_array($page, ['login.php', 'logout.php'], true)) {
            return $fallback;
        }
        return $url;
    }
}
