<?php
declare(strict_types=1);

$bridge = file_get_contents(dirname(__DIR__) . '/website_inquiry_staging_bridge.php');
if ($bridge === false) {
    fwrite(STDERR, "Cannot read website inquiry bridge\n");
    exit(1);
}

$markers = [
    '官网询盘列表摘要函数' => 'function gz_bridge_dispatch_project(array $payload, string $name, string $message, string $product): string',
    '列表显示客户姓名' => "'客户：' . \$name",
    '列表显示客户邮箱' => "'邮箱：' . \$email",
    '列表显示客户留言' => "'留言：' . \$message",
    '列表保留产品或页面' => "'产品／页面：' . \$product",
    '派工项目使用完整摘要' => '$project = gz_bridge_dispatch_project($payload, $name, $message, $product);',
    '联动 JSON 保存客户姓名' => "'customer_name' => \$name",
    '联动 JSON 保存邮箱' => "'email' => (string)(\$payload['email'] ?? '')",
    '联动 JSON 保存留言' => "'message' => \$message",
    '派工详情仍保存完整说明' => 'SET title=?, project=?, description=?, priority=?',
];

foreach ($markers as $label => $needle) {
    if (strpos($bridge, $needle) === false) {
        fwrite(STDERR, "Missing website inquiry dispatch marker: {$label}\n");
        exit(1);
    }
}

$oldProjectOnly = '$project = gz_bridge_clip($product !== \'\' ? $product : (($payload[\'page_title\'] ?? \'\') ?: \'香港官网询盘\'), 180);';
if (strpos($bridge, $oldProjectOnly) !== false) {
    fwrite(STDERR, "Old page-only dispatch project mapping remains\n");
    exit(1);
}

echo "website inquiry dispatch fields contract ok\n";
