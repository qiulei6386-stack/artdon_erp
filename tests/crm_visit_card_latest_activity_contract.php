<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$visit = file_get_contents($root . '/crm_visit.php');
$js = file_get_contents($root . '/assets/crm/crm.js');
$css = file_get_contents($root . '/assets/crm/crm.css');

if (in_array(false, [$visit, $js, $css], true)) {
    throw new RuntimeException('CRM visit card latest activity sources are not readable');
}

foreach ([
    [$visit, 'latest_result_at', '列表接口返回最新结果时间'],
    [$visit, 'SELECT v.*, c.customer_name', '列表接口保留拜访主记录最新摘要字段'],
    [$js, 'visitLatestActivity: function', '卡片新增最新动态计算函数'],
    [$js, 'var activity = this.visitLatestActivity(row);', '卡片渲染接入最新动态'],
    [$js, 'visit-card-activity', '卡片输出最新动态区块'],
    [$js, '最近动态', '优先显示最近填写结果动态'],
    [$js, 'latest_result_at', '最近结果优先使用结果历史时间'],
    [$js, 'completed_at', '兼容已完成时间'],
    [$js, 'next_followup_time', '没有结果时显示下次跟进安排'],
    [$js, 'visitCompactTime: function', '卡片时间做紧凑显示'],
    [$css, '.visit-card-activity', '最新动态区块样式已接入'],
    [$css, '-webkit-line-clamp: 1', '图标卡片动态内容一行截断，避免卡片过高'],
    [$css, 'grid-auto-rows: 228px', '图标卡片预留最新动态高度'],
] as [$source, $needle, $label]) {
    if (!str_contains($source, $needle)) {
        throw new RuntimeException('缺少：' . $label);
    }
}

echo "crm_visit_card_latest_activity_contract: OK\n";
