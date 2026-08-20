<?php
$page = file_get_contents(__DIR__ . '/../naming.php');
if ($page === false) {
    throw new RuntimeException('naming.php is not readable');
}

$start = strpos($page, 'function initQuickFuzzySearch()');
$end = strpos($page, 'function nmModalOpen()', $start === false ? 0 : $start);
if ($start === false || $end === false || $end <= $start) {
    throw new RuntimeException('quick search function slice not found');
}
$quick = substr($page, $start, $end - $start);

$checks = [
    '局部刷新结果区' => 'applyQuickSearchDocument(doc, url, caret)',
    '型号列表节点局部替换' => "document.querySelector('main.wrap > section.grid, main.wrap > section.table-wrap')",
    '更新地址但不整页跳转' => "history.replaceState(null, '', url)",
    '恢复搜索框焦点' => "inp.focus({preventScroll:true})",
    '恢复搜索框光标' => 'inp.setSelectionRange(pos, pos)',
    '请求 HTML 片段' => "'X-Requested-With':'XMLHttpRequest'",
    '搜索状态失败不跳走' => "setSearchState('自动搜索失败，按回车重试','bad')",
];

foreach ($checks as $label => $needle) {
    if (strpos($quick, $needle) === false) {
        throw new RuntimeException("missing quick search focus marker: {$label}");
    }
}

if (strpos($quick, 'window.location.assign(url)') !== false || strpos($quick, 'location.href=url') !== false) {
    throw new RuntimeException('quick search must not navigate away while user is typing');
}

echo "naming_quick_search_focus_contract ok\n";
