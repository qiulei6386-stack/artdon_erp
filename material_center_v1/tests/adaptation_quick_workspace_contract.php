<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/adaptation/index.php');
$script = (string) file_get_contents($root . '/assets/js/adaptation-v3.js');
$style = (string) file_get_contents($root . '/assets/css/app.css');

$checks = [
    '旧 step 参数映射到高级设置'
        => str_contains($page, "'range' => 1")
            && str_contains($page, "'approval' => 5")
            && str_contains($page, "'advancedOpen' => \$requestedStep !== ''"),
    '默认工作台使用快速三步'
        => str_contains($script, 'mc-v3-quick-flow')
            && str_contains($script, '确认配置来源')
            && str_contains($script, '设置核心配置')
            && str_contains($script, '检查并保存'),
    '默认只显示四个核心配置'
        => str_contains($script, "const quickCoreKeys = ['chip', 'power', 'optic', 'install'];"),
    '六步高级能力仍保留但按需打开'
        => str_contains($script, 'renderAdvancedSettings')
            && str_contains($script, '完整技术范围')
            && str_contains($script, '条件规则')
            && str_contains($script, '配置版本 / 发布历史'),
    '没有配置组时右侧抽屉不占位'
        => str_contains($script, "if (!group) return '';"),
    '物料选择改为宽版比较区域'
        => str_contains($script, 'mc-v3-wide-picker')
            && str_contains($style, '.mc-v3-picker-box')
            && str_contains($style, 'height:min(78vh,760px)'),
    '主工作台使用一屏固定工作区'
        => str_contains($style, '.mc-page--adaptation-v3 .mc-v3-workbench-page')
            && str_contains($style, 'height:calc(100vh - 116px)')
            && str_contains($style, 'overflow:hidden'),
    '缺失字段使用小弹窗填写'
        => str_contains($script, 'renderParamModal')
            && str_contains($script, 'data-v3-param-form')
            && str_contains($style, '.mc-v3-param-modal'),
    '高级设置是覆盖式面板且内部滚动'
        => str_contains($style, '.mc-v3-advanced-panel{position:fixed')
            && str_contains($style, '.mc-v3-advanced-scroll'),
    '选择物料后关闭弹窗回到主页面'
        => str_contains($script, 'return loadWorkspace(selectedProduct().id, 0, state.step);'),
    '配置来源复制不复制审批发布状态提示'
        => str_contains($script, '不会复制审批状态、发布状态、审批人、发布人或原版本号'),
    '提交确认不直接发布'
        => str_contains($script, '如需审批或发布，请由有权限人员在高级设置中处理。'),
    '快速保存草稿只建立核心组骨架'
        => str_contains($script, "template_keys: ['light_source', 'power_driver', 'optical', 'installation']"),
];

$failed = [];
foreach ($checks as $label => $passed) {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$passed) $failed[] = $label;
}

if ($failed) {
    fwrite(STDERR, 'adaptation quick workspace contract failed: ' . implode('；', $failed) . PHP_EOL);
    exit(1);
}

echo "adaptation quick workspace contract passed.\n";
