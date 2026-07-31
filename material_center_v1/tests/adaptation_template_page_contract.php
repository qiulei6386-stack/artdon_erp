<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$script = (string) file_get_contents($root . '/assets/js/adaptation-v3.js');
$style = (string) file_get_contents($root . '/assets/css/app.css');

$checks = [
    '配置模板页使用截图式页面壳'
        => str_contains($script, 'mc-v3-template-page')
            && str_contains($script, '通过清晰、紧凑的模板配置')
            && str_contains($style, '.mc-v3-template-page'),
    '当前产品卡片和更换产品入口存在'
        => str_contains($script, 'mc-v3-template-product-card')
            && str_contains($script, '当前产品：')
            && str_contains($script, '更换产品'),
    '左侧三类模板标签存在'
        => str_contains($script, '通用模板')
            && str_contains($script, '按产品分类')
            && str_contains($script, '自定义分类模板'),
    '模板模块按卡片选择并保留真实提交字段'
        => str_contains($script, 'mc-v3-template-card-grid')
            && str_contains($script, 'mc-v3-template-module')
            && str_contains($script, 'name="template_key"')
            && str_contains($script, 'data-v3-template-form'),
    '十个标准模块完整显示'
        => str_contains($script, "'light_source'")
            && str_contains($script, "'power_driver'")
            && str_contains($script, "'optical'")
            && str_contains($script, "'installation'")
            && str_contains($script, "'dimming'")
            && str_contains($script, "'honeycomb'")
            && str_contains($script, "'protective_glass'")
            && str_contains($script, "'accessories'")
            && str_contains($script, "'finish_color'")
            && str_contains($script, "'special_requirements'"),
    '重置和全选可更新选择数量'
        => str_contains($script, 'data-v3-template-reset')
            && str_contains($script, 'data-v3-template-all')
            && str_contains($script, 'updateTemplateCount')
            && str_contains($script, 'data-v3-template-count'),
    '右侧自定义分类规则面板存在'
        => str_contains($script, '自定义分类 / 分类规则')
            && str_contains($script, '新增分类')
            && str_contains($script, '已创建的自定义分类')
            && str_contains($style, '.mc-v3-template-rule-panel'),
    '底部操作栏按截图展示并继续套用模板'
        => str_contains($script, 'mc-v3-template-footer')
            && str_contains($script, '保存草稿')
            && str_contains($script, '套用配置模板')
            && str_contains($script, "api('apply_template'")
            && str_contains($style, '.mc-v3-template-footer'),
    '页面为双栏布局并有小屏降级'
        => str_contains($style, 'grid-template-columns:minmax(520px,1.15fr) minmax(420px,.85fr)')
            && str_contains($style, '@media(max-width:1280px)')
            && str_contains($style, '@media(max-width:760px)'),
];

$failed = [];
foreach ($checks as $label => $passed) {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$passed) $failed[] = $label;
}

if ($failed) {
    fwrite(STDERR, 'adaptation template page contract failed: ' . implode('；', $failed) . PHP_EOL);
    exit(1);
}

echo "adaptation template page contract passed.\n";
