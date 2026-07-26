<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
http_response_code(410);
echo json_encode([
    'ok' => false,
    'message' => '物料中心独立授权入口已停用，请在统一权限中心维护账号、角色和 material_center.* 权限。',
    'code' => 'USE_UNIFIED_PERMISSION_CENTER',
], JSON_UNESCAPED_UNICODE);
