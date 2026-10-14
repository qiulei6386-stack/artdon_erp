<?php
declare(strict_types=1);

define('PM_ROOT', __DIR__);

$pmConfigFile = PM_ROOT . '/config.php';
$pmConfig = is_file($pmConfigFile) ? require $pmConfigFile : ['demo' => false];
define('PM_DEMO', !empty($pmConfig['demo']));

spl_autoload_register(static function (string $class): void {
    $prefix = 'Artdon\\PromotionCenter\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = PM_ROOT . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

require_once dirname(PM_ROOT) . '/includes/bootstrap.php';

function pm_login_url(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $levels = basename(dirname($script)) === 'prototype' ? 3 : 2;
    $root = dirname($script, $levels);
    if ($root === '/' || $root === '.' || $root === '\\') {
        $root = '';
    }
    return rtrim((string) $root, '/') . '/login.php';
}

function pm_module_access_error(): ?array
{
    if (!current_user()) {
        return ['status' => 401, 'error_code' => 'AUTH_REQUIRED', 'message' => '请先登录'];
    }
    if (!has_permission('promotion.view')) {
        return ['status' => 403, 'error_code' => 'PERMISSION_DENIED', 'message' => '没有推广中心查看权限（promotion.view）'];
    }
    return null;
}

if (PHP_SAPI !== 'cli') {
    $denied = pm_module_access_error();
    if ($denied !== null) {
        $isApi = basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) === 'api.php';
        if ($isApi) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code((int) $denied['status']);
            echo json_encode([
                'success' => false,
                'message' => $denied['message'],
                'data' => new stdClass(),
                'error_code' => $denied['error_code'],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ((int) $denied['status'] === 401) {
            $returnUrl = function_exists('auth_safe_redirect') ? auth_safe_redirect((string) ($_SERVER['REQUEST_URI'] ?? ''), '') : '';
            header('Location: ' . pm_login_url() . ($returnUrl !== '' ? '?redirect=' . rawurlencode($returnUrl) : ''));
            exit;
        }
        http_response_code(403);
        exit($denied['message']);
    }
}
