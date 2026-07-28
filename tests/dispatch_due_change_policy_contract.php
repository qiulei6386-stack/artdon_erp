<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$api = file_get_contents($root . '/dispatch_next_api.php');
$schema = file_get_contents($root . '/dispatch_next_schema.php');
$settings = file_get_contents($root . '/settings.php');
$service = file_get_contents($root . '/includes/settings_service.php');
$page = file_get_contents($root . '/dispatch_next.php');
if ($api === false || $schema === false || $settings === false || $service === false || $page === false) {
    throw new RuntimeException('dispatch due-change sources are not readable');
}

foreach ([
    'function dn_dispatch_due_change_policy(): array',
    'function dn_due_change_block_reason(array $task, ?array $group = null): ?string',
    'function dn_assert_due_change_allowed(array $task, ?array $group = null): void',
    'function dn_record_due_change(array $task, $oldDue, $newDue, string $source, ?array $group = null): void',
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

foreach (['save_dispatch_due_change_settings', 'dispatch-rules', '每条派工每日最多修改次数', '到期前禁改天数'] as $marker) {
    if (!str_contains($settings, $marker)) {
        throw new RuntimeException("dispatch due-change settings UI marker missing: {$marker}");
    }
}

foreach (['due_change_hint', '当前不能修改截止日期', 'function renderMultiEditForm(g){let canDue=!!g.can_change_due_at'] as $marker) {
    if (!str_contains($page, $marker)) {
        throw new RuntimeException("dispatch due-change UI feedback marker missing: {$marker}");
    }
}

echo "Dispatch due-change policy contract: OK\n";
