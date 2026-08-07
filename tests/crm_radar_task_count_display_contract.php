<?php

$root = dirname(__DIR__);
$js = file_get_contents($root . '/assets/crm/crm.js');

foreach ([
    'var targetCount = Number(row.target_candidate_count || 0);',
    "'关键词搜索 ' + esc(searchCurrent) + ' / ' + esc(searchTotal) + ' 次'",
    "'目标客户 ' + esc(targetCount || '-') + ' 个",
    "'完成关键词 ' + esc(row.search_done_count || 0)",
    "'候选公司 ' + esc(row.found_companies || 0)",
] as $marker) {
    if (!str_contains($js, $marker)) {
        throw new RuntimeException("Radar task count display marker missing: {$marker}");
    }
}

foreach ([
    "'第 ' + esc(searchCurrent) + ' / 共 ' + esc(searchTotal) + ' 次'",
    "'网页 ' + esc(row.searched_pages || 0) + ' / 公司 ' + esc(row.found_companies || 0) + ' / 失败 ' + esc(row.failed_count || 0)",
] as $forbidden) {
    if (str_contains($js, $forbidden)) {
        throw new RuntimeException("Radar task count display still contains ambiguous wording: {$forbidden}");
    }
}

echo "CRM radar task count display contract passed.\n";
