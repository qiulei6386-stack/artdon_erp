<?php
declare(strict_types=1);

/**
 * 各中心的类自动加载。只做加载，不连数据库、不启动会话，测试可以单独引入。
 *
 * 命名空间与目录对应：
 *   Artdon\Centers\Base\Url      → centers/_base/src/Url.php
 *   Artdon\Centers\System\Foo    → centers/system/src/Foo.php
 *   Artdon\Centers\Customer\Foo  → centers/customer/src/Foo.php
 */

if (!defined('ARTDON_CENTERS_ROOT')) {
    define('ARTDON_CENTERS_ROOT', dirname(__DIR__));
}
if (!defined('ARTDON_APP_ROOT')) {
    define('ARTDON_APP_ROOT', dirname(__DIR__, 2));
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Artdon\\Centers\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $parts = explode('\\', substr($class, strlen($prefix)));
    if (count($parts) < 2) {
        return;
    }
    $center = array_shift($parts);
    if (!preg_match('/^[A-Z][A-Za-z0-9]*$/', $center)) {
        return;
    }
    $dir = $center === 'Base' ? '_base' : strtolower($center);
    $file = ARTDON_CENTERS_ROOT . '/' . $dir . '/src/' . implode('/', $parts) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
