<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$js = file_get_contents($root . '/assets/crm/crm.js');
$css = file_get_contents($root . '/assets/crm/crm.css');
$api = file_get_contents($root . '/crm_marketing.php');
if ($js === false || $css === false || $api === false) {
    fwrite(STDERR, "Cannot read CRM marketing sources\n");
    exit(1);
}

$markers = [
    [$api, 't.task_status, t.schedule_type, t.scheduled_at,', '推广项目列表接口返回 scheduled_at'],
    [$api, 't.remark, t.created_at, t.updated_at,', '推广项目列表接口返回创建/更新时间'],
    [$js, "var startedAt = String(row.scheduled_at || '').replace('T', ' ').slice(0, 16);", '卡片提取开始时间'],
    [$js, "var startLine = '开始 ' + (startedAt || '未设置')", '卡片单独显示开始时间'],
    [$js, "var timing = '负责人 ' + (row.created_by_name || '-') + (updatedAt ? ' · 更新 ' + updatedAt : '')", '卡片单独显示负责人和更新时间'],
    [$js, "'创建：' + (createdAt || '-')", '卡片 tooltip 显示创建时间'],
    [$js, "class=\"promo-task-start\"", '卡片开始时间样式类'],
    [$js, "class=\"promo-task-timing\"", '卡片日期详情样式类'],
    [$css, 'height: 108px !important;', '推广项目卡片加高显示四行'],
    [$css, 'grid-template-rows: 24px 18px 18px 18px !important;', '推广项目卡片四行布局'],
    [$css, '.promo-task-card-grid .promo-task-item > span.promo-task-start', '卡片开始时间样式'],
    [$css, '.promo-task-card-grid .promo-task-item > span.promo-task-timing', '卡片日期详情样式'],
];

foreach ($markers as [$source, $needle, $label]) {
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, "Missing marketing task timing marker: {$label}\n");
        exit(1);
    }
}

echo "crm_marketing_task_card_timing_contract ok\n";
