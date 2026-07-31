<?php
declare(strict_types=1);
define('MC_ROOT', __DIR__);
define('MC_LEGACY_ROOT', dirname(__DIR__));
$docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string)$_SERVER['DOCUMENT_ROOT']) : false;
$rootReal = realpath(MC_ROOT);
if ($docRoot && $rootReal && strpos($rootReal, $docRoot) === 0) {
    define('MC_BASE_URL', rtrim(str_replace(DIRECTORY_SEPARATOR, '/', substr($rootReal, strlen($docRoot))), '/'));
} else {
    define('MC_BASE_URL', '/material_center_v1');
}
date_default_timezone_set('Asia/Shanghai');
spl_autoload_register(static function (string $class): void {
    $prefix = 'Artdon\\MaterialCenter\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $file = MC_ROOT.'/app/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    if (is_file($file)) require_once $file;
});
require_once MC_ROOT . '/lib/helpers.php';
require_once MC_ROOT . '/app/Support/helpers.php';
require_once MC_LEGACY_ROOT . '/includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    $requestPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $isApiRequest = str_contains($requestPath, '/material_center_v1/api/')
        || str_contains($requestPath, '/material_center_v1/adaptation_v2/api/');
    $user = mc_current_user();

    if (!$user) {
        if ($isApiRequest) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(401);
            echo json_encode(['ok' => false, 'message' => '请先使用统一账号登录。', 'code' => 'AUTH_REQUIRED'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $legacyBaseUrl = rtrim(str_replace(DIRECTORY_SEPARATOR, '/', dirname(MC_BASE_URL)), '/');
        $returnUrl = auth_safe_redirect((string) ($_SERVER['REQUEST_URI'] ?? ''), '');
        header('Location: ' . $legacyBaseUrl . '/login.php' . ($returnUrl !== '' ? '?redirect=' . rawurlencode($returnUrl) : ''), true, 302);
        exit;
    }

    if (!has_permission('material_center.view')) {
        if ($isApiRequest) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => '没有访问物料中心的权限。', 'code' => 'PERMISSION_DENIED'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        http_response_code(403);
        exit('没有访问物料中心的权限，请在统一权限中心申请 material_center.view。');
    }
}
