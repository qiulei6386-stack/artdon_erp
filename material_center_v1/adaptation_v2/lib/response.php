<?php
declare(strict_types=1);

function pa2_request_id(): string
{
    try {
        return 'pa2_' . bin2hex(random_bytes(8));
    } catch (Throwable) {
        return 'pa2_' . str_replace('.', '', uniqid('', true));
    }
}

function pa2_json_response(array $data = [], string $message = '操作成功', bool $success = true, array $errors = [], int $statusCode = 200): void
{
    if (!headers_sent()) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }

    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
        'errors' => array_values($errors),
        'request_id' => pa2_request_id(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
