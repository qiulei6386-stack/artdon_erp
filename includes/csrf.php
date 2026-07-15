<?php
function csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field()
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function verify_csrf()
{
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return is_string($token) && hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

function require_csrf()
{
    if (!verify_csrf()) {
        if (function_exists('api_response')) {
            api_response(false, 'CSRF 校验失败', [], 'CSRF_INVALID');
        }
        http_response_code(400);
        exit('CSRF 校验失败');
    }
}
