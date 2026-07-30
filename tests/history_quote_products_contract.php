<?php
declare(strict_types=1);

$root = getenv('QUOTATION_CONTRACT_ROOT') ?: dirname(__DIR__);
$page = (string) file_get_contents($root . '/quotation.php');
$api = (string) file_get_contents($root . '/quote_api.php');

$checks = [
    '历史报价摘要生成前 4 个产品缩略图'
        => str_contains($api, 'function quote_history_preview_items')
            && str_contains($api, 'array_slice($sourceItems,0,4)')
            && str_contains($api, 'quote_history_summary_rows($pdo)'),
    '历史报价摘要不把完整 parts_json 返回首屏'
        => str_contains($api, "'{}' AS parts_json, 0 AS _detail_loaded")
            && str_contains($api, '$r[\'items_json\']=json_encode($preview[\'items\']'),
    '历史报价保留真实产品数量'
        => str_contains($api, '$r[\'history_item_count\']')
            && str_contains($page, 'q.history_item_count'),
    '历史列表显示 4 个固定小缩略图'
        => str_contains($page, '.history-view-list .history-product-strip{display:grid!important;grid-template-columns:repeat(4,82px)')
            && str_contains($page, '.history-product-mini{border:1px solid #dbe3ef')
            && str_contains($page, 'width:82px'),
    '小屏仍保持 4 个缩略图一排'
        => str_contains($page, '@media(max-width:900px)')
            && str_contains($page, 'grid-template-columns:repeat(4,70px)')
            && str_contains($page, '@media(max-width:700px)')
            && str_contains($page, 'grid-template-columns:repeat(4,64px)'),
    '图片区靠近左侧报价信息并按内容宽度显示'
        => str_contains($page, 'grid-template-columns:minmax(360px,460px) max-content')
            && str_contains($page, 'justify-content:start')
            && str_contains($page, 'width:max-content;justify-self:start'),
];

$failed = [];
foreach ($checks as $label => $passed) {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$passed) $failed[] = $label;
}

if ($failed) {
    fwrite(STDERR, 'history quote products contract failed: ' . implode('；', $failed) . PHP_EOL);
    exit(1);
}

echo "history quote products contract passed.\n";
