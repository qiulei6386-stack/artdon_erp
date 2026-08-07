<?php

$root = dirname(__DIR__);
$radar = file_get_contents($root . '/radar.php');
$js = file_get_contents($root . '/assets/crm/crm.js');

if (!str_contains($js, "['draft', 'failed', 'cancelled', 'partial_completed'].indexOf(selectedStatus) >= 0) singleItems.push('启动搜索任务')")) {
    throw new RuntimeException('Cancelled radar tasks must keep showing the start action in the UI.');
}

if (preg_match("/\\['cancelled','completed','searching','generating_keywords','fetching_pages','identifying_company','waiting_analysis'\\]/", $radar)) {
    throw new RuntimeException('Cancelled radar tasks must not be blocked by radar_task_start.');
}

if (!str_contains($radar, "['completed','searching','generating_keywords','fetching_pages','identifying_company','waiting_analysis']")) {
    throw new RuntimeException('Radar task start must still block completed and in-flight tasks.');
}

if (!str_contains($radar, "job_status IN ('pending','failed','cancelled')")) {
    throw new RuntimeException('Restarting a cancelled radar task must clear stale cancelled queue jobs before enqueueing a new run.');
}

if (!str_contains($radar, "cancelled_at=NULL")) {
    throw new RuntimeException('Restarting a cancelled radar task must clear cancelled_at on the task.');
}

echo "CRM radar cancelled task restart contract passed.\n";
