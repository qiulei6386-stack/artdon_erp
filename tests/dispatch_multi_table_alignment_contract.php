<?php
declare(strict_types=1);

$page = file_get_contents(dirname(__DIR__) . '/dispatch_next.php');
if ($page === false) {
    throw new RuntimeException('dispatch table page is not readable');
}

$start = strpos($page, 'function groupAssignees(r)');
$end = strpos($page, 'function prio(', $start === false ? 0 : $start);
if ($start === false || $end === false) {
    throw new RuntimeException('multi-dispatch owner renderer is not readable');
}
$renderer = substr($page, $start, $end - $start);
foreach ([
    "text=names.join('、')||'多人'",
    '<span class="groupAssigneeName">${nameAlignHtml(name)}</span>',
    '<span class="groupAssigneeMore">另 ${more} 人</span>',
] as $marker) {
    if (!str_contains($renderer, $marker)) {
        throw new RuntimeException("multi-dispatch owner renderer marker missing: {$marker}");
    }
}
if (str_contains($renderer, 'groupAssigneeSep')) {
    throw new RuntimeException('multi-dispatch owner renderer must not insert slash separators');
}

foreach ([
    '.tbl td[data-field="assigned_to"] .groupAssignees{display:inline-flex!important;width:100%!important',
    'flex-direction:column!important',
    '.tbl td[data-field="assigned_to"] .groupAssigneeName{display:flex',
    '.tbl td[data-field="actions"] .rowActions{display:flex!important;width:100%!important',
    'function tableRowActions(r)',
    'const normal=`<div class="rowActions ${actionIconClass()}">${detail}${primary}${urge}${remove}</div>`;',
    "const completed=['done','cancelled'].includes(String(r.status||''));",
    'if(!completed)return normal;',
    'rowActionSlot(detail,\'detail\')',
    'rowActionSlot(primary,\'primary\')',
    'rowActionSlot(urge,\'urge\')',
    'rowActionSlot(remove,\'danger\')',
    '.tbl td[data-field="actions"] .rowActions.fixedActionRail{display:grid!important;grid-template-columns:repeat(4,32px)',
    '.tbl td[data-field="actions"] .fixedActionRail .rowActionSlot:empty{visibility:hidden}',
    '.tbl tr.done td[data-field="actions"],.tbl tr.done td[data-field="actions"] .rowActions',
] as $marker) {
    if (!str_contains($page, $marker)) {
        throw new RuntimeException("dispatch table alignment style marker missing: {$marker}");
    }
}

if (!preg_match('/function tableRowActions\\(r\\)\\{.*?if\\(!completed\\)return normal;.*?fixedActionRail/s', $page)) {
    throw new RuntimeException('only completed or cancelled tasks may use the fixed action rail');
}

echo "Dispatch multi-table alignment contract: OK\n";
