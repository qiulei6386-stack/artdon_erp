<?php
declare(strict_types=1);

/**
 * Mini-program dispatch_list: fold recurring/multi group children (align with website dn_list).
 */

function dispatch_miniapp_task_occurrence_date(array $task): string
{
    $date = (string)($task['task_date'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return $date;
    }
    $due = (string)($task['due_at'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $due)) {
        return substr($due, 0, 10);
    }
    return date('Y-m-d');
}

/**
 * Pick one representative child task id per recurring/multi group for list display.
 *
 * @param list<array<string,mixed>> $children
 */
function dispatch_miniapp_pick_group_representative_id(array $children, string $statusFilter, ?string $today = null): int
{
    $today = $today ?: date('Y-m-d');
    $eligible = [];
    foreach ($children as $c) {
        if ((int)($c['is_deleted'] ?? 0) === 1) {
            continue;
        }
        $st = (string)($c['status'] ?? '');
        if ($statusFilter === 'open' && in_array($st, ['done', 'cancelled'], true)) {
            continue;
        }
        if ($statusFilter === 'done' && $st !== 'done') {
            continue;
        }
        if ($statusFilter === 'cancelled' && $st !== 'cancelled') {
            continue;
        }
        $eligible[] = $c;
    }
    if (!$eligible) {
        return 0;
    }

    if ($statusFilter === 'done') {
        usort($eligible, static function (array $a, array $b): int {
            $cmp = strcmp((string)($b['completed_at'] ?? ''), (string)($a['completed_at'] ?? ''));
            if ($cmp !== 0) {
                return $cmp;
            }
            return ((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0));
        });
        return (int)($eligible[0]['id'] ?? 0);
    }

    if ($statusFilter === 'cancelled') {
        usort($eligible, static function (array $a, array $b): int {
            $ca = (string)($a['cancelled_at'] ?? $a['updated_at'] ?? '');
            $cb = (string)($b['cancelled_at'] ?? $b['updated_at'] ?? '');
            $cmp = strcmp($cb, $ca);
            if ($cmp !== 0) {
                return $cmp;
            }
            return ((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0));
        });
        return (int)($eligible[0]['id'] ?? 0);
    }

    usort($eligible, static function (array $a, array $b) use ($today, $statusFilter): int {
        $ka = dispatch_miniapp_rep_sort_key($a, $today, $statusFilter);
        $kb = dispatch_miniapp_rep_sort_key($b, $today, $statusFilter);
        foreach ($ka as $i => $part) {
            if ($part === $kb[$i]) {
                continue;
            }
            return $part <=> $kb[$i];
        }
        return 0;
    });

    return (int)($eligible[0]['id'] ?? 0);
}

/**
 * @return list<int|string>
 */
function dispatch_miniapp_rep_sort_key(array $task, string $today, string $statusFilter): array
{
    $st = (string)($task['status'] ?? '');
    $isClosed = in_array($st, ['done', 'cancelled'], true);
    $occ = dispatch_miniapp_task_occurrence_date($task);
    $openTier = ($statusFilter === 'all' && $isClosed) ? 1 : 0;
    $todayTier = ($occ === $today) ? 0 : 1;
    $futureTier = ($occ >= $today) ? 0 : 1;
    return [$openTier, $todayTier, $futureTier, $occ, -((int)($task['id'] ?? 0))];
}

/**
 * SQL fragment + bound params: keep standalone tasks; for parent_group_id>0 keep only the representative child.
 *
 * @return array{0:string,1:list<mixed>}
 */
function dispatch_miniapp_list_fold_sql(string $statusFilter, string $tableAlias = 't', ?string $today = null): array
{
    $today = $today ?: date('Y-m-d');
    $c = 'c';
    $occ = "COALESCE(NULLIF({$c}.task_date,''), DATE({$c}.due_at), '1970-01-01')";

    $childStatus = '';
    if ($statusFilter === 'open') {
        $childStatus = " AND {$c}.status NOT IN ('done','cancelled')";
    } elseif ($statusFilter === 'done') {
        $childStatus = " AND {$c}.status = 'done'";
    } elseif ($statusFilter === 'cancelled') {
        $childStatus = " AND {$c}.status = 'cancelled'";
    }

    $extraParams = [];
    if ($statusFilter === 'done') {
        $order = "{$c}.completed_at DESC, {$c}.id DESC";
    } elseif ($statusFilter === 'cancelled') {
        $order = "COALESCE({$c}.cancelled_at, {$c}.updated_at) DESC, {$c}.id DESC";
    } else {
        $openTier = $statusFilter === 'all'
            ? "CASE WHEN {$c}.status IN ('done','cancelled') THEN 1 ELSE 0 END ASC, "
            : '';
        $order = "{$openTier}(CASE WHEN {$occ} = ? THEN 0 ELSE 1 END) ASC, (CASE WHEN {$occ} >= ? THEN 0 ELSE 1 END) ASC, {$occ} ASC, {$c}.id DESC";
        $extraParams = [$today, $today];
    }

    $sql = "(COALESCE({$tableAlias}.parent_group_id, 0) = 0 OR {$tableAlias}.id = (
        SELECT {$c}.id FROM dispatch_next_tasks {$c}
        WHERE {$c}.parent_group_id = {$tableAlias}.parent_group_id
          AND {$c}.is_deleted = 0
          {$childStatus}
        ORDER BY {$order}
        LIMIT 1
    ))";

    return [$sql, $extraParams];
}
