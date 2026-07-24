<?php
declare(strict_types=1);

define('CC_ROOT', __DIR__);
define('CC_LEGACY_ROOT', dirname(__DIR__));
define('CC_STORAGE', CC_ROOT . '/storage');

date_default_timezone_set('Asia/Shanghai');

spl_autoload_register(static function (string $class): void {
    $prefix = 'Artdon\\CommercialCenter\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = CC_ROOT . '/app/' . $relative . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

require_once CC_ROOT . '/app/Support/helpers.php';

use Artdon\CommercialCenter\Support\Logger;

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    Logger::error('PHP error', [
        'severity' => $severity,
        'message' => $message,
        'file' => basename($file),
        'line' => $line,
    ]);
    return true;
});

set_exception_handler(static function (Throwable $error): void {
    Logger::error('Unhandled exception', [
        'type' => get_class($error),
        'message' => $error->getMessage(),
        'file' => basename($error->getFile()),
        'line' => $error->getLine(),
    ]);
    http_response_code(500);
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<h1>商务运营中心暂时不可用</h1><p>详细错误已写入独立模块日志，请联系管理员。</p>';
    exit(1);
});

try {
    require_once CC_LEGACY_ROOT . '/includes/bootstrap.php';
} catch (Throwable $error) {
    Logger::error('Legacy bootstrap unavailable', [
        'type' => get_class($error),
        'message' => $error->getMessage(),
    ]);
    throw new RuntimeException('无法安全加载现有统一登录环境。', 0, $error);
}
