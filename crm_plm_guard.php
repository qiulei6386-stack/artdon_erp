<?php
/**
 * CRM API 权限守卫。
 * 插入 crm_api.php 顶部后生效。
 */
if (session_status() === PHP_SESSION_NONE) session_start();

$config = __DIR__ . '/config.php';
if (file_exists($config)) require_once $config;
require_once __DIR__ . '/crm_plm_auth_lib.php';

if (!function_exists('crm_plm_guard_json')) {
    function crm_plm_guard_json(array $data, int $code=200): void {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

try {
    $pdo = crm_plm_pdo();
    crm_plm_ensure_tables($pdo);
    $action = crm_plm_s($_GET['action'] ?? $_POST['action'] ?? '', 80);

    // 旧 CRM 的 login/me/logout 放行，避免旧入口报错；前端已改用 crm_auth_plm_api.php。
    if (in_array($action, ['login','me','logout'], true)) return;

    $u = crm_plm_current_user($pdo);
    if (!$u) crm_plm_guard_json(['ok'=>false,'msg'=>'CRM已改用权限中心/PLM账号登录，请先登录。'], 401);

    $am = crm_plm_action_to_module($action);
    $module = $am['module'] ?? '';
    $edit = !empty($am['edit']);

    if ($action === 'init') {
        if (empty($u['allowed_pages'])) crm_plm_guard_json(['ok'=>false,'msg'=>'当前账号没有任何 CRM 模块权限。'], 403);
        return;
    }

    if ($module !== '' && !crm_plm_can($u, $module, $edit)) {
        $mods = crm_plm_modules();
        $label = $mods[$module] ?? $module;
        crm_plm_guard_json(['ok'=>false,'msg'=>'没有权限访问 CRM 模块：'.$label], 403);
    }
} catch (Throwable $e) {
    crm_plm_guard_json(['ok'=>false,'msg'=>'CRM权限检查失败：'.$e->getMessage()], 500);
}
?>