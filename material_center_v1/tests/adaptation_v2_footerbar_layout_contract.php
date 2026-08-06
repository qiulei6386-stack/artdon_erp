<?php
declare(strict_types=1);

$page = file_get_contents(dirname(__DIR__) . '/adaptation_v2/index.php');
if ($page === false) {
    throw new RuntimeException('Cannot read adaptation V2 page.');
}

if (!preg_match('/\\.pa2-footerbar\\{([^}]*)\\}/', $page, $matches)) {
    throw new RuntimeException('Missing .pa2-footerbar style.');
}

$style = $matches[1];
foreach (['position:sticky', 'position:fixed', 'bottom:'] as $blocked) {
    if (str_contains($style, $blocked)) {
        throw new RuntimeException(".pa2-footerbar should not float over workspace content: {$blocked}");
    }
}

foreach (['重新计算', '保存草稿', '提交审批', '需要补充'] as $label) {
    if (!str_contains($page, $label)) {
        throw new RuntimeException("Footer action label is missing: {$label}");
    }
}

echo "Adaptation V2 footerbar layout contract passed\n";
