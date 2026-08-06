<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$page = file_get_contents($root . '/dispatch_next.php');
if ($page === false) {
    throw new RuntimeException('dispatch page is not readable');
}

$start = strpos($page, 'function renderEditableCell(r,c)');
$end = strpos($page, 'function methodValue(r)', $start === false ? 0 : $start);
if ($start === false || $end === false) {
    throw new RuntimeException('dispatch editable-cell renderer is not readable');
}
$renderer = substr($page, $start, $end - $start);

foreach ([
    'data-cell-field="project">${renderLinkedText(plain)}</div>',
    'data-cell-field="title">${renderLinkedText(plain)}</div>',
    'data-raw-value="${esc(plain)}"',
] as $marker) {
    if (!str_contains($renderer, $marker)) {
        throw new RuntimeException("desktop linked preview renderer marker missing: {$marker}");
    }
}

if (str_contains($renderer, 'data-cell-field="project">${esc(plain)}</div>')) {
    throw new RuntimeException('desktop project cells must not render @BOM mentions as plain escaped text');
}
if (str_contains($renderer, 'data-cell-field="title">${esc(plain)}</div>')) {
    throw new RuntimeException('desktop title cells must not render linked mentions as plain escaped text');
}

foreach ([
    '@(时间|日期|客户|customer|邮件|mail|email|命名|naming|BOM|bom|报价|quote|PLM|plm|快照|snapshot|datasheet|资料|material|派工|dispatch|商机|opportunity)',
    'data-linked-system="${esc(c.system)}"',
    'openLinkedPreview(token.dataset.linkedSystem,token.dataset.linkedCode)',
    'function renderTaskText(value){return looksLikeRichText(value)?sanitizeRichHtml(value):renderLinkedText(value)}',
] as $marker) {
    if (!str_contains($page, $marker)) {
        throw new RuntimeException("linked preview behavior marker missing: {$marker}");
    }
}

echo "Dispatch BOM linked preview contract: OK\n";
