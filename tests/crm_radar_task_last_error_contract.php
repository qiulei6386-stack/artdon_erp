<?php

$root = dirname(__DIR__);
$radar = file_get_contents($root . '/radar.php');

foreach ([
    '$errorStmt = db()->prepare("SELECT last_error FROM crm_radar_job_queue',
    "job_status IN ('pending','running','failed')",
    '$activeError = (string)($errorStmt->fetchColumn() ?: \'\');',
    'failed_count=?, last_error=?, updated_at=NOW()',
    '$activeError !== \'\' ? $activeError : null',
] as $marker) {
    if (!str_contains($radar, $marker)) {
        throw new RuntimeException("Radar task last_error clearing marker missing: {$marker}");
    }
}

if (!preg_match('/function radar_worker_update_task\\(int \\$taskId\\).*?last_error=\\?.*?\\$activeError !== \'\' \\? \\$activeError : null/s', $radar)) {
    throw new RuntimeException('Radar worker update must clear stale task last_error when no active queue error remains.');
}

echo "CRM radar task last_error contract passed.\n";
