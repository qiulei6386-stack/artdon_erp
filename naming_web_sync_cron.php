<?php
declare(strict_types=1);

define('NM_WEBSITE_SYNC_CRON', true);
$_GET['action'] = 'realtime_sync_v19';
$_GET['force'] = '1';

require __DIR__ . '/naming.php';
