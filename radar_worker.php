<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/crm_config.php';
require_once __DIR__ . '/crm_auth.php';
require_once __DIR__ . '/crm_log.php';
require_once __DIR__ . '/radar.php';

require_login();
crm_require('radar_task_run');

$limit = max(1, min(50, (int)($_POST['limit'] ?? $_GET['limit'] ?? 10)));
$result = radar_worker_run($limit);
radar_log('worker_manual_run', ['result' => $result]);
api_response(true, '客户雷达 Worker 已执行', $result);
