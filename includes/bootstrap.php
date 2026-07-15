<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

date_default_timezone_set('Asia/Shanghai');

require_once __DIR__ . '/response.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/password.php';
require_once __DIR__ . '/audit.php';
if (file_exists(__DIR__ . '/../services/log_service.php')) {
    require_once __DIR__ . '/../services/log_service.php';
}
if (file_exists(__DIR__ . '/notification_service.php')) {
    require_once __DIR__ . '/notification_service.php';
}
if (file_exists(__DIR__ . '/linkage_service.php')) {
    require_once __DIR__ . '/linkage_service.php';
}
if (file_exists(__DIR__ . '/settings_service.php')) {
    require_once __DIR__ . '/settings_service.php';
}

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect($url)
{
    header('Location: ' . $url);
    exit;
}

function config_installed()
{
    $config = db_config();
    return !empty($config['installed']) && file_exists(__DIR__ . '/install.lock');
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permission.php';
