<?php

/** Static contract for task-center save idempotency and list stability. */
$root = dirname(__DIR__);
$task = file_get_contents($root . '/crm_task_center.php');
$customer = file_get_contents($root . '/crm_customer.php');
$js = file_get_contents($root . '/assets/crm/crm.js');
$errors = [];

foreach ([
    [$task, 'uk_quote_followup_request', '报价跟进请求去重索引'],
    [$task, 'SELECT id FROM crm_quote_followup_activities WHERE created_by=? AND request_token=?', '报价跟进服务端重复拦截'],
    [$task, 'function crm_task_quote_followup_rollups', '报价流程批量跟进汇总'],
    [$task, "'followup_count' => (int)(\$activityRollup['count'] ?? 0)", '报价流程使用批量跟进计数'],
    [$customer, 'uk_followup_request', '客户跟进请求去重索引'],
    [$customer, 'SELECT id FROM crm_customer_followups WHERE created_by=? AND request_token=?', '客户跟进服务端重复拦截'],
    [$js, 'detailRequestKey', '详情请求串位保护'],
    [$js, 'if (self.view === \'sample\' || self.view === \'sample_pending_ship\' || self.view === \'sample_follow_overdue\') self.loadSamples();', '非样品页不再重复加载样品列表'],
    [$js, 'dialog._quoteFollowupAbort', '报价跟进弹窗事件清理'],
    [$js, 'actionPending: function', '未接通操作禁用标识'],
] as [$source, $needle, $label]) {
    if (strpos($source, $needle) === false) $errors[] = '缺少：' . $label;
}

if ($errors) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}
echo "crm_task_center_stability_contract: OK\n";
