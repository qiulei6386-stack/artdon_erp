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

if (PM_DEMO && PHP_SAPI !== 'cli') {
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script === 'index.php') {
        header('Location: prototype/index.html');
        exit;
    }
    if ($script === 'api.php') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '演示开关不能代替登录。', 'data' => new stdClass(), 'error_code' => 'DEMO_DISABLED'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

require_once dirname(PM_ROOT) . '/includes/bootstrap.php';

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
            $returnUrl = (string) ($_SERVER['REQUEST_URI'] ?? '');
            header('Location: ../login.php' . ($returnUrl !== '' ? '?redirect=' . rawurlencode($returnUrl) : ''));
            exit;
        }
        http_response_code(403);
        exit($denied['message']);
    }
}
