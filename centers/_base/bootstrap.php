<?php
declare(strict_types=1);

/**
 * 新系统入口。每个中心的页面和接口第一行引入本文件（或本中心的 bootstrap.php）。
 * 新系统完全独立：不引用旧系统任何代码，只读写数据库里的现有表。
 */

require_once __DIR__ . '/autoload.php';

use Artdon\Centers\Base\Config;
use Artdon\Centers\Base\ConfigMissingException;
use Artdon\Centers\Base\ErrorCode;
use Artdon\Centers\Base\Http;
use Artdon\Centers\Base\Layout;
use Artdon\Centers\Base\Session;

set_exception_handler(static function (Throwable $e): void {
    $missing = $e instanceof ConfigMissingException;
    if (!$missing) {
        error_log('centers: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    }
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
    if (Http::wantsJson()) {
        Http::fail(ErrorCode::SERVER_ERROR, $missing ? $e->getMessage() : '系统出错了，请稍后重试');
    }
    if ($missing) {
        Layout::errorPage(503, '新系统还没有完成配置', '缺少数据库连接配置 centers/config.php。请管理员在服务器上运行 php centers/_base/tools/init_config.php 生成配置。');
    }
    Layout::errorPage(500, '系统出错了', '请稍后重试。如果一直出现，请把出错时间告诉管理员。');
});

if (Config::exists()) {
    date_default_timezone_set((string) Config::get('app.timezone', 'Asia/Shanghai'));
    Session::start();
} else {
    date_default_timezone_set('Asia/Shanghai');
}
