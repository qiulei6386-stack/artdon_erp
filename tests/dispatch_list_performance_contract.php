<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$api = file_get_contents($root . '/dispatch_next_api.php');
$page = file_get_contents($root . '/dispatch_next.php');
$schema = file_get_contents($root . '/dispatch_next_schema.php');
if ($api === false || $page === false || $schema === false) {
    throw new RuntimeException('dispatch performance sources are not readable');
}

if (str_contains($api, "(SELECT COUNT(*) FROM dispatch_next_attachments a WHERE a.task_id=t.id AND a.is_deleted=0) AS attachment_count")) {
    throw new RuntimeException('per-task attachment count subquery must not return to the list query');
}
foreach ([
    'function dn_attach_list_counts(array &$rows): void',
    'GROUP BY task_id',
    "'dispatch_next_tasks' => \"SELECT MAX(updated_at) AS updated_at, COALESCE(MAX(id),0) AS max_id FROM dispatch_next_tasks\"",
    "'dispatch_next_groups' => \"SELECT MAX(updated_at) AS updated_at, COALESCE(MAX(id),0) AS max_id FROM dispatch_next_groups\"",
] as $marker) {
    if (!str_contains($api, $marker)) {
        throw new RuntimeException("dispatch list API performance marker missing: {$marker}");
    }
}

foreach ([
    "Promise.all([api('list_users'),api('me')])",
    'Promise.all([loadPeopleFilterPrefs(),loadNoticeRules(),loadTablePrefs()])',
    'lastVersionCheck>5000',
] as $marker) {
    if (!str_contains($page, $marker)) {
        throw new RuntimeException("dispatch page performance marker missing: {$marker}");
    }
}
if (str_contains($page, 'lastSilentSync>30000){state.lastSilentSync=now;load({silent:true})')) {
    throw new RuntimeException('the unconditional 30-second full list refresh must stay removed');
}

foreach ([
    'function dispatch_next_add_index_if_missing(PDO $pdo, string $table, string $index, string $definition): void',
    "idx_dispatch_next_tasks_updated', 'INDEX idx_dispatch_next_tasks_updated (updated_at)'",
    "idx_dispatch_next_groups_updated', 'INDEX idx_dispatch_next_groups_updated (updated_at)'",
] as $marker) {
    if (!str_contains($schema, $marker)) {
        throw new RuntimeException("dispatch sync index marker missing: {$marker}");
    }
}

echo "Dispatch list performance contract: OK\n";
