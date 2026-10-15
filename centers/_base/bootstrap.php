<?php
declare(strict_types=1);

/**
 * 公共底座入口。每个中心的页面和接口第一行引入本文件（或本中心的 bootstrap.php）。
 *
 * 只调用旧系统 includes/ 里的现有函数（会话、数据库、登录、权限、CSRF、日志），不修改它们。
 */

require_once __DIR__ . '/autoload.php';
require_once ARTDON_APP_ROOT . '/includes/bootstrap.php';
