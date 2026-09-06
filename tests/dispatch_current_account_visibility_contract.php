<?php
declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/dispatch_next_api.php');
if ($source === false) {
    throw new RuntimeException('dispatch_next_api.php is not readable');
}
$page = file_get_contents(dirname(__DIR__) . '/dispatch_next.php');
if ($page === false) {
    throw new RuntimeException('dispatch_next.php is not readable');
}

$required = [
    'function dn_task_user_relation_sql',
    '{$alias}.created_by = ?',
    '{$alias}.assigned_to = ?',
    '{$alias}.transfer_from_user_id = ?',
    "helper_ids_json,'[]'",
    'current_relation_step.owner_id=?',
    '[$ownSql, $ownParams] = dn_task_user_relation_sql($alias, $uid);',
    'return ["({$ownSql} OR ({$scopeSql}))"',
    'function dn_task_has_current_user_relation',
    "(int)(\$task['created_by'] ?? 0) === \$uid",
    "(int)(\$task['assigned_to'] ?? 0) === \$uid",
    "(int)(\$task['transfer_from_user_id'] ?? 0) === \$uid",
    "\$task['helper_ids_json'] ?? '[]'",
    "!empty(\$task['current_user_step_owner'])",
    'if (dn_task_has_current_user_relation($task, dn_uid())) return true;',
    "dn_task_user_relation_sql('mt', dn_uid())",
    "'current_user_relation_labels'",
];

foreach ($required as $marker) {
    if (!str_contains($source, $marker)) {
        throw new RuntimeException("current-account visibility rule missing: {$marker}");
    }
}

$matcherStart = strpos($source, 'function dn_task_matches_people');
$forcedMatch = strpos($source, 'if (dn_task_has_current_user_relation($task, dn_uid())) return true;', $matcherStart ?: 0);
$selectedPeopleMatch = strpos($source, '$ids = array_map', $matcherStart ?: 0);
if ($matcherStart === false || $forcedMatch === false || $selectedPeopleMatch === false || $forcedMatch > $selectedPeopleMatch) {
    throw new RuntimeException('current-account relation must override selected-person filtering');
}

$pageForbidden = [
    'function relationBadge(r)',
    'current_user_relation_labels',
    '当前账号与此待办的关系',
    '${relationBadge(r)}${statusDots(r)}${statusIconTags(r)}',
];
foreach ($pageForbidden as $marker) {
    if (str_contains($page, $marker)) {
        throw new RuntimeException("removed relation badge remains in page: {$marker}");
    }
}

echo "Dispatch current-account visibility contract: OK\n";
