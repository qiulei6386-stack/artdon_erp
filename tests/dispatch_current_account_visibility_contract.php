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
    '.relationBadge',
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

$statusMarkup = '${statusDots(r)}${statusIconTags(r)}';
$mobileStart = strpos($page, 'function mobileTaskCardHtml(');
$mobileEnd = strpos($page, 'function bindMobileTaskCards(', $mobileStart ?: 0);
$desktopStart = strpos($page, 'function renderEditableCell(');
$desktopEnd = strpos($page, 'function methodValue(', $desktopStart ?: 0);
if ($mobileStart === false || $mobileEnd === false || $desktopStart === false || $desktopEnd === false) {
    throw new RuntimeException('task rendering entry points are missing');
}
$mobileRenderer = substr($page, $mobileStart, $mobileEnd - $mobileStart);
$desktopRenderer = substr($page, $desktopStart, $desktopEnd - $desktopStart);
if (!str_contains($mobileRenderer, $statusMarkup) || substr_count($desktopRenderer, $statusMarkup) < 2) {
    throw new RuntimeException('due dots and status icons must remain in mobile, group, and ordinary task titles');
}

echo "Dispatch current-account visibility contract: OK\n";
