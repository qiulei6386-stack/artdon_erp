<?php
declare(strict_types=1);

define('MC_ROOT', __DIR__);
define('MC_LEGACY_ROOT', dirname(__DIR__));

date_default_timezone_set('Asia/Shanghai');

spl_autoload_register(static function (string $class): void {
    $prefix = 'Artdon\\MaterialCenter\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = MC_ROOT . '/app/' . $relative . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

require_once MC_ROOT . '/app/Support/helpers.php';
require_once MC_LEGACY_ROOT . '/includes/bootstrap.php';
