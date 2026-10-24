<?php
declare(strict_types=1);

/**
 * Contract: mini-program dispatch_list folds recurring/multi children like website dn_list.
 *
 * php tests/miniapp_dispatch_list_fold_contract.php
 */
if (PHP_SAPI !== 'cli') {
    exit(2);
}

date_default_timezone_set('Asia/Shanghai');

require_once dirname(__DIR__) . '/includes/dispatch_miniapp_list.php';

function check(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

$today = '2026-10-24';

$children = [
    ['id' => 101, 'task_date' => '2026-10-01', 'status' => 'pending_accept', 'is_deleted' => 0],
    ['id' => 102, 'task_date' => '2026-10-20', 'status' => 'pending_accept', 'is_deleted' => 0],
    ['id' => 103, 'task_date' => '2026-10-24', 'status' => 'in_progress', 'is_deleted' => 0],
    ['id' => 104, 'task_date' => '2026-11-01', 'status' => 'pending_accept', 'is_deleted' => 0],
];

check(
    dispatch_miniapp_pick_group_representative_id($children, 'open', $today) === 103,
    'open list prefers today occurrence'
);

check(
    dispatch_miniapp_pick_group_representative_id(
        array_values(array_filter($children, static fn($c) => (int)$c['id'] !== 103)),
        'open',
        $today
    ) === 104,
    'without today, pick nearest upcoming open by task_date'
);

check(
    dispatch_miniapp_pick_group_representative_id(
        array_values(array_filter($children, static fn($c) => (int)$c['id'] >= 104)),
        'open',
        $today
    ) === 104,
    'only future open children'
);

$pastOnly = [
    ['id' => 201, 'task_date' => '2026-10-01', 'status' => 'pending_accept', 'is_deleted' => 0],
    ['id' => 202, 'task_date' => '2026-10-15', 'status' => 'pending_accept', 'is_deleted' => 0],
];
check(
    dispatch_miniapp_pick_group_representative_id($pastOnly, 'open', $today) === 201,
    'past-only open picks earliest task_date'
);

$doneKids = [
    ['id' => 301, 'task_date' => '2026-10-01', 'status' => 'done', 'completed_at' => '2026-10-02 10:00:00', 'is_deleted' => 0],
    ['id' => 302, 'task_date' => '2026-10-15', 'status' => 'done', 'completed_at' => '2026-10-16 09:00:00', 'is_deleted' => 0],
];
check(
    dispatch_miniapp_pick_group_representative_id($doneKids, 'done', $today) === 302,
    'done filter picks latest completed'
);

$mixed = [
    ['id' => 401, 'task_date' => '2026-10-01', 'status' => 'done', 'completed_at' => '2026-10-02', 'is_deleted' => 0],
    ['id' => 402, 'task_date' => '2026-10-24', 'status' => 'pending_accept', 'is_deleted' => 0],
];
check(
    dispatch_miniapp_pick_group_representative_id($mixed, 'all', $today) === 402,
    'all filter prefers open over closed'
);

[$foldSql, $foldParams] = dispatch_miniapp_list_fold_sql('open', 't', $today);
check(strpos($foldSql, 'parent_group_id') !== false, 'fold SQL references parent_group_id');
check(count($foldParams) === 2, 'open fold supplies today twice for ordering');

echo "miniapp_dispatch_list_fold_contract: OK\n";
