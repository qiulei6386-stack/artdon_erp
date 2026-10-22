<?php
declare(strict_types=1);

require_once __DIR__ . '/feed_lib.php';

date_default_timezone_set('Asia/Shanghai');

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'GET') {
        yf_json(405, array(
            'ok' => false,
            'error' => 'METHOD_NOT_ALLOWED',
            'message' => '仅支持 GET',
        ));
    }

    yf_feed_authorize($_SERVER);

    $pdo = yf_pdo();
    if (!yf_tables_ready($pdo)) {
        yf_json(503, array(
            'ok' => false,
            'error' => 'SCHEMA_MISSING',
            'message' => '请先在服务器执行 php yifei_sync/migrate.php',
        ));
    }

    $result = yf_feed_query($pdo, $_GET);
    yf_json(200, $result);
} catch (InvalidArgumentException $e) {
    yf_json(400, array('ok' => false, 'error' => 'BAD_REQUEST', 'message' => $e->getMessage()));
} catch (RuntimeException $e) {
    $code = $e->getMessage();
    if ($code === 'UNAUTHORIZED') {
        yf_json(401, array('ok' => false, 'error' => 'UNAUTHORIZED', 'message' => 'Feed 密钥不正确'));
    }
    if ($code === 'FORBIDDEN_IP') {
        yf_json(403, array('ok' => false, 'error' => 'FORBIDDEN', 'message' => '来源 IP 未授权'));
    }
    if ($code === 'TOKEN_NOT_CONFIGURED') {
        yf_json(503, array('ok' => false, 'error' => 'TOKEN_NOT_CONFIGURED', 'message' => '服务器还没有配置 Feed 密钥'));
    }
    error_log('yifei_feed: ' . $code);
    yf_json(500, array('ok' => false, 'error' => 'SERVER_ERROR', 'message' => '读取失败'));
} catch (Throwable $e) {
    error_log('yifei_feed: ' . $e->getMessage());
    yf_json(500, array('ok' => false, 'error' => 'SERVER_ERROR', 'message' => '读取失败'));
}
