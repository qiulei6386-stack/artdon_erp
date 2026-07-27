<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$page = file_get_contents($root.'/adaptation/index.php');
$script = file_get_contents($root.'/assets/js/adaptation-shell.js');
$service = file_get_contents($root.'/app/Services/AdaptationService.php');

foreach ([
    '配置组 = 当前产品的一类选配',
    '“批量套用”才是把整套已配置内容复制到其他产品',
    '关键范围（快速规则）',
    'data-open-quick-rules',
    '配置用途 *',
    '候选物料来源',
    '页面显示名称 *',
    '系统会自动关联正式物料库',
] as $marker) {
    if (!str_contains($page, $marker)) throw new RuntimeException("adaptation discovery UI missing: {$marker}");
}

foreach ([
    "state.tab = 'quick_rules';",
    '已为你打开第一个组的关键范围',
    'syncGroupFormType',
    'quickRuleCount',
    '关键范围：',
    '保存关键范围',
] as $marker) {
    if (!str_contains($script, $marker)) throw new RuntimeException("adaptation discovery interaction missing: {$marker}");
}

if (str_contains($script, "const groupButton = event.target.closest('[data-select-group]');\n    if (groupButton) {\n      try {\n        state.tab = 'options';")) {
    throw new RuntimeException('selecting another group must preserve the current detail tab');
}
if (!str_contains($service, '$mappedCategory !== null')) {
    throw new RuntimeException('standard business types must enforce their mapped material category');
}
if (!str_contains($service, "'default_name' => '芯片 / 光源'")) {
    throw new RuntimeException('group purpose must provide a suggested display name');
}

echo "Product adaptation quick-rule discovery contract: OK\n";
