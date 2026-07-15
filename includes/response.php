<?php
function api_response($success = true, $message = '', $data = [], $error_code = '')
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => (bool)$success,
        'message' => $message,
        'data' => $data ?: new stdClass(),
        'error_code' => $error_code,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function permission_denied_response()
{
    api_response(false, '权限不足', [], 'PERMISSION_DENIED');
}
