<?php

$root = dirname(__DIR__);
$visit = file_get_contents($root . '/crm_visit.php');
$settings = file_get_contents($root . '/crm_settings_config.php');
$page = file_get_contents($root . '/crm.php');
$js = file_get_contents($root . '/assets/crm/crm.js');

$checks = [
    '拜访表保存前端请求唯一标识' => str_contains($visit, "client_request_id VARCHAR(64)")
        && str_contains($visit, 'uk_visit_client_request'),
    '服务端重复请求返回原记录' => str_contains($visit, 'crm_visit_find_by_request_id')
        && str_contains($visit, "'duplicate_request'")
        && str_contains($visit, '$duplicateRequest ? 1 : 0'),
    '并发唯一键冲突可回读原记录' => str_contains($visit, 'catch (PDOException $e)')
        && substr_count($visit, 'crm_visit_find_by_request_id($clientRequestId, $userId)') >= 2,
    '旧页面无请求标识时也阻止短时间完全相同记录' => str_contains($visit, 'function crm_visit_find_recent_duplicate')
        && str_contains($visit, 'INTERVAL 120 SECOND')
        && str_contains($visit, '$clientRequestId ==='),
    '新建弹窗生成并提交同一请求标识' => str_contains($js, 'this.createRequestId()')
        && str_contains($js, 'name="client_request_id"'),
    '草稿与正式保存按钮使用同一提交锁' => str_contains($js, "form.dataset.submitting === '1'")
        && str_contains($js, "[data-visit-save],[data-visit-draft]"),
    '拜访状态已加入字典类型' => str_contains($settings, "['visit_status', '拜访 / 来访状态'")
        && str_contains($settings, "'visit_status' => ["),
    '已有系统会自动补齐拜访状态字典' => str_contains($settings, 'function crm_sync_visit_status_presets')
        && str_contains($settings, 'crm_sync_visit_status_presets();'),
    '拜访接口下发可配置状态' => str_contains($visit, "'statuses' => crm_visit_status_options(false)"),
    '前端优先显示字典中文名称' => str_contains($js, 'configuredVisitStatus.name_cn')
        && str_contains($js, "pending_confirm: '待确认'")
        && str_contains($js, "followup_pending: '待后续跟进'"),
    '设置中心明确展示拜访状态入口' => str_contains($page, '国家 / 渠道 / 拜访状态 / 阶段')
        && str_contains($page, '拜访/来访状态、阶段和业务下拉'),
];

$failed = [];
foreach ($checks as $label => $ok) {
    if (!$ok) $failed[] = $label;
}

if ($failed) {
    fwrite(STDERR, 'crm visit dedup/status contract failed: ' . implode('；', $failed) . PHP_EOL);
    exit(1);
}

echo 'crm visit dedup/status contract ok (' . count($checks) . ' checks)' . PHP_EOL;
