<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/crm_config.php';
require_once __DIR__ . '/crm_auth.php';
require_once __DIR__ . '/crm_log.php';
require_once __DIR__ . '/crm_customer.php';
require_once __DIR__ . '/crm_mail.php';
require_once __DIR__ . '/crm_marketing.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$limit = 30;
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--limit=(\d+)$/', (string)$arg, $m)) {
        $limit = max(1, min(200, (int)$m[1]));
    }
}

try {
    crm_mail_ensure_tables();
    crm_marketing_ensure_tables();
    $result = crm_marketing_queue_run_due($limit);
    $line = '[' . date('Y-m-d H:i:s') . '] marketing_queue_worker ' . json_encode($result, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    @file_put_contents(__DIR__ . '/storage/marketing_queue_worker.log', $line, FILE_APPEND);
    echo $line;
    exit(0);
} catch (Throwable $e) {
    $line = '[' . date('Y-m-d H:i:s') . '] marketing_queue_worker failed: ' . $e->getMessage() . PHP_EOL;
    @file_put_contents(__DIR__ . '/storage/marketing_queue_worker.log', $line, FILE_APPEND);
    fwrite(STDERR, $line);
    exit(1);
}
