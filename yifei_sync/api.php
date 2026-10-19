<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

date_default_timezone_set('Asia/Shanghai');

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'POST') {
        yf_json(405, array(
            'ok' => false,
            'error' => 'METHOD_NOT_ALLOWED',
            'message' => '请用 POST JSON，type 为 invmb、bommd、cstmb 或 feature',
        ));
    }
    $length = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
    if ($length > YF_BODY_MAX) {
        yf_json(413, array('ok' => false, 'error' => 'BODY_TOO_LARGE', 'message' => '请求体超过 2MB'));
    }
    $typeHeader = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if (stripos((string)$typeHeader, 'application/json') === false) {
        yf_json(415, array('ok' => false, 'error' => 'UNSUPPORTED_MEDIA', 'message' => 'Content-Type 需要 application/json'));
    }
    yf_authorize(yf_request_token($_SERVER));
    $raw = file_get_contents('php://input');
    if ($raw === false) {
        $raw = '';
    }
    $payload = yf_decode_payload($raw);
    $pdo = yf_pdo();
    if (!yf_tables_ready($pdo)) {
        yf_json(503, array(
            'ok' => false,
            'error' => 'SCHEMA_MISSING',
            'message' => '请先在服务器执行 php yifei_sync/migrate.php',
        ));
    }
    yf_json(200, yf_apply($pdo, $payload, (string)($_SERVER['REMOTE_ADDR'] ?? '')));
} catch (InvalidArgumentException $e) {
    yf_json(400, array('ok' => false, 'error' => 'BAD_REQUEST', 'message' => $e->getMessage()));
} catch (RuntimeException $e) {
    $code = $e->getMessage();
    if ($code === 'UNAUTHORIZED') {
        yf_json(401, array('ok' => false, 'error' => 'UNAUTHORIZED', 'message' => '共享密钥不正确'));
    }
    if ($code === 'TOKEN_NOT_CONFIGURED') {
        yf_json(503, array('ok' => false, 'error' => 'TOKEN_NOT_CONFIGURED', 'message' => '服务器还没有配置易飞同步密钥'));
    }
    error_log('yifei_sync: ' . $code);
    yf_json(500, array('ok' => false, 'error' => 'SERVER_ERROR', 'message' => '写入失败'));
} catch (Throwable $e) {
    error_log('yifei_sync: ' . $e->getMessage());
    yf_json(500, array('ok' => false, 'error' => 'SERVER_ERROR', 'message' => '写入失败'));
}
