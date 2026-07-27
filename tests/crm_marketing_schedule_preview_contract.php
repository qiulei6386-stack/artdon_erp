<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$js = file_get_contents($root . '/assets/crm/crm.js');
$css = file_get_contents($root . '/assets/crm/crm.css');
if ($js === false || $css === false) {
    throw new RuntimeException('CRM marketing schedule preview sources are not readable');
}

foreach ([
    'renderScheduleStepLayout: function (draft)',
    'buildSchedulePlan: function (draft)',
    "var base = draft.scheduled_at ? new Date",
    "execution_mode: executionMode",
    "execution_status: executionMode === 'mail' ? '自动邮件' : '人工执行'",
    'var blockedRows = [];',
    'var skippedRows = [];',
    "reason: '无邮箱，按规则跳过'",
    "reason: '重复邮箱，按规则跳过'",
    '自动邮件',
    '人工执行',
    '待处理',
    '修改开始时间、间隔、时区后自动重新计算',
    '人工渠道会在该时间生成待办，不会自动代发。',
    '当前没有可执行目标，请检查客户/联系人选择。',
    "var scheduleFields = ['schedule_type', 'scheduled_at', 'timezone_rule', 'send_interval_minutes', 'hourly_limit', 'daily_limit'];",
    'refreshSchedulePreviewDebounced',
] as $marker) {
    if (!str_contains($js, $marker)) {
        throw new RuntimeException("CRM marketing schedule preview marker missing: {$marker}");
    }
}

$scheduleStart = strpos($js, 'renderScheduleStepLayout: function (draft)');
$scheduleEnd = strpos($js, 'renderFailureStepLayout: function (draft)', $scheduleStart === false ? 0 : $scheduleStart);
if ($scheduleStart === false || $scheduleEnd === false || $scheduleEnd <= $scheduleStart) {
    throw new RuntimeException('schedule step source boundary is missing');
}
$schedule = substr($js, $scheduleStart, $scheduleEnd - $scheduleStart);
foreach (['schedule.mailRows', 'schedule.manualRows', 'schedule.blockedRows', 'schedule.skippedRows', '预计执行时间'] as $marker) {
    if (!str_contains($schedule, $marker)) {
        throw new RuntimeException("schedule step must show execution detail: {$marker}");
    }
}

foreach (['promo-schedule-preview-heading', 'promo-schedule-exception', 'repeat(6, minmax(0, 1fr))'] as $marker) {
    if (!str_contains($css, $marker)) {
        throw new RuntimeException("CRM schedule preview style marker missing: {$marker}");
    }
}

echo "CRM marketing schedule preview contract: OK\n";
