<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/crm_config.php';
require_once __DIR__ . '/crm_auth.php';
require_once __DIR__ . '/crm_log.php';
require_once __DIR__ . '/crm_customer.php';
require_once __DIR__ . '/crm_mail.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CRM mail cron can only run from CLI.';
    exit;
}

crm_ensure_tables();
crm_customer_ensure_tables();
crm_mail_ensure_tables();

$interval = 3;
$limit = 30;
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (preg_match('/^--interval=(\d+)$/', $arg, $m)) {
        $interval = (int)$m[1];
    } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int)$m[1];
    }
}

$result = crm_mail_cron_sync_due_accounts($interval, $limit);
$result['send_queue'] = crm_mail_send_due_jobs($limit);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
