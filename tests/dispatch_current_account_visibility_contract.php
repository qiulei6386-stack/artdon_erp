<?php
declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/dispatch_next_api.php');
if ($source === false) {
    throw new RuntimeException('dispatch_next_api.php is not readable');
}

$required = [
    '$ownSql = "({$alias}.created_by = ? OR {$alias}.assigned_to = ?)"',
    'return ["({$ownSql} OR ({$scopeSql}))"',
    'function dn_task_has_user_in_primary_columns',
    "(int)(\$task['created_by'] ?? 0) === \$uid",
    "(int)(\$task['assigned_to'] ?? 0) === \$uid",
    'if (dn_task_has_user_in_primary_columns($task, dn_uid())) return true;',
    'OR mt.created_by = ? OR mt.assigned_to = ?',
];

foreach ($required as $marker) {
    if (!str_contains($source, $marker)) {
        throw new RuntimeException("current-account visibility rule missing: {$marker}");
    }
}

$matcherStart = strpos($source, 'function dn_task_matches_people');
$forcedMatch = strpos($source, 'if (dn_task_has_user_in_primary_columns($task, dn_uid())) return true;', $matcherStart ?: 0);
$selectedPeopleMatch = strpos($source, '$ids = array_map', $matcherStart ?: 0);
if ($matcherStart === false || $forcedMatch === false || $selectedPeopleMatch === false || $forcedMatch > $selectedPeopleMatch) {
    throw new RuntimeException('current-account relation must override selected-person filtering');
}

echo "Dispatch current-account visibility contract: OK\n";
