<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$api = file_get_contents($root . '/dispatch_next_api.php');
$schema = file_get_contents($root . '/dispatch_next_schema.php');
$service = file_get_contents($root . '/includes/settings_service.php');
$page = file_get_contents($root . '/dispatch_next.php');
if ($api === false || $schema === false || $service === false || $page === false) {
    throw new RuntimeException('dispatch due-change sources are not readable');
}

foreach ([
    'function dn_dispatch_due_change_policy(): array',
    'function dn_due_change_block_reason(array $task, ?array $group = null): ?string',
    'function dn_assert_due_change_allowed(array $task, ?array $group = null): void',
    'function dn_record_due_change(array $task, $oldDue, $newDue, string $source, ?array $group = null): void',
    'function dn_get_due_change_settings(): array',
    'function dn_save_due_change_settings(array $in): array',
    "case 'get_due_change_settings': dn_ok(dn_get_due_change_settings());",
    "case 'save_due_change_settings': dn_ok(dn_save_due_change_settings(\$in));",
    'SELECT task_id,group_id,COUNT(*) AS change_count FROM dispatch_next_due_change_events WHERE policy_date=CURDATE() GROUP BY task_id,group_id',
    'dispatch_next_due_change_events',
    'dn_assert_due_change_allowed($task);',
    'dn_record_due_change($task, $change[1], $change[2], \'update_task\');',
    'dn_record_due_change($task, $old, $new, \'update_cell\');',
    'dn_record_due_change([\'task_type\' => \'dispatch\', \'parent_group_id\' => $gid]',
] as $marker) {
    if (!str_contains($api, $marker)) {
        throw new RuntimeException("dispatch due-change API marker missing: {$marker}");
    }
}
if (str_contains($api, "(string)(\$task['task_type'] ?? 'dispatch') !== 'dispatch'")) {
    throw new RuntimeException('personal tasks must not bypass the due-change policy');
}
foreach (["dn_str(\$in['project'] ?? '', 8000)", "'project' => 8000"] as $marker) {
    if (!str_contains($api, $marker)) {
        throw new RuntimeException("dispatch long work-list API marker missing: {$marker}");
    }
}

foreach ([
    'CREATE TABLE IF NOT EXISTS dispatch_next_due_change_events',
    'idx_dispatch_next_due_change_task_day',
    'idx_dispatch_next_due_change_group_day',
] as $marker) {
    if (!str_contains($schema, $marker)) {
        throw new RuntimeException("dispatch due-change schema marker missing: {$marker}");
    }
}
foreach ([
    'ALTER TABLE dispatch_next_tasks MODIFY project TEXT NULL',
    'ALTER TABLE dispatch_next_groups MODIFY project TEXT NULL',
] as $marker) {
    if (!str_contains($schema, $marker)) {
        throw new RuntimeException("dispatch long work-list schema marker missing: {$marker}");
    }
}

foreach ([
    'function get_dispatch_due_change_settings(): array',
    'function save_dispatch_due_change_settings(array $input): array',
    "'max_changes_per_day' => 2",
    "'lock_before_due_days' => 0",
    "save_json_setting('dispatch_due_change_settings'",
] as $marker) {
    if (!str_contains($service, $marker)) {
        throw new RuntimeException("dispatch due-change setting service marker missing: {$marker}");
    }
}

foreach (['due_change_hint', '当前不能修改截止日期', 'function renderMultiEditForm(g){let canDue=!!g.can_change_due_at', 'data-open="due_policy"', 'function renderDuePolicySettings()', "api('get_due_change_settings')", "api('save_due_change_settings'"] as $marker) {
    if (!str_contains($page, $marker)) {
        throw new RuntimeException("dispatch due-change UI feedback marker missing: {$marker}");
    }
}
foreach (['taskWorkList', '项目 / 工作清单', 'taskAssigneeReadOnly', '请选择至少一位执行人', '保存失败：'] as $marker) {
    if (!str_contains($page, $marker)) {
        throw new RuntimeException("dispatch unified-create-form marker missing: {$marker}");
    }
}

foreach ([
    'const dispatchBaseDefaultColorPrefs=defaultColorPrefs;',
    "due_today_bg:'#fff1f2'",
    "overdue_bg:'#ffe4e6'",
    "function normalizeDueRowFill(value)",
    "due_row_fill:'critical'",
    '整行填充范围',
    '不填充（仅保留日期和左线）',
    '只填充今天到期与逾期（推荐）',
    'data-apply-water-red',
    'function dueClass(r)',
    'due_soon_days??1',
    "return 'due-overdue'",
    '两种到期填充颜色',
    '填充快到期与已过期',
] as $marker) {
    if (!str_contains($page, $marker)) {
        throw new RuntimeException("dispatch due-color default marker missing: {$marker}");
    }
}

echo "Dispatch due-change policy contract: OK\n";
