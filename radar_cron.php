<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/crm_config.php';
require_once __DIR__ . '/crm_log.php';
require_once __DIR__ . '/radar.php';

$settings = radar_settings_get();
$expected = (string)($settings['task']['cron_secret'] ?? '');
$given = PHP_SAPI === 'cli' ? (string)($argv[1] ?? '') : (string)($_GET['key'] ?? $_POST['key'] ?? '');
if ($expected === '' || !hash_equals($expected, $given)) {
    radar_log('cron_denied', ['has_key' => $given !== ''], false, 'Cron安全密钥无效或未设置');
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Cron安全密钥无效或未设置'], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit;
}

$limit = PHP_SAPI === 'cli' ? (int)($argv[2] ?? 10) : (int)($_GET['limit'] ?? $_POST['limit'] ?? 10);
$limit = max(1, min(50, $limit));

try {
    $result = radar_worker_run($limit);
    radar_log('cron_run', ['result' => $result]);
    echo json_encode(['success' => true, 'message' => '客户雷达后台队列已执行', 'data' => $result], JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $e) {
    radar_log('cron_failed', ['limit' => $limit], false, $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
