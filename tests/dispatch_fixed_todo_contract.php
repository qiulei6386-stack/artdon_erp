<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$page = file_get_contents($root . '/dispatch_next.php');
$api = file_get_contents($root . '/dispatch_next_api.php');
if ($page === false || $api === false) {
    fwrite(STDERR, "Cannot read dispatch files\n");
    exit(1);
}

$pageMarkers = [
    '固定待办入口按钮' => 'data-open="fixed">固定待办',
    '固定待办弹窗文案' => "fixed:['固定待办','适合每天、工作日、每周或每月都要检查的固定工作。']",
    '固定待办标题占位' => "例如：检查工程开发工作",
    '固定待办复用周期保存' => "['recurring','fixed'].includes(m)",
    '固定待办标记传给后端' => "fixed_todo:m==='fixed'?1:0",
    '固定待办默认勾选自己' => "mode==='fixed'&&String(u.id)===String(state.me?.id||'')?' checked':''",
    '固定规则说明' => '系统会自动检查固定规则；当天未生成过才会生成一条新的待办',
    '工作日频率选项' => '<option value="workdays">工作日</option>',
];

foreach ($pageMarkers as $label => $needle) {
    if (strpos($page, $needle) === false) {
        fwrite(STDERR, "Missing fixed todo page marker: {$label}\n");
        exit(1);
    }
}

$apiMarkers = [
    '后端支持工作日频率' => "['daily','workdays','weekly','monthly']",
    '工作日仅周一到周五生成' => "(\$rule['freq'] ?? '') === 'workdays') return (int)date('N', \$ts) <= 5",
    '保存固定待办类型标记' => "'kind' => !empty(\$in['fixed_todo']) ? 'fixed_todo' : 'recurring_dispatch'",
    '保存固定截止时刻' => "'due_time' => substr(\$dueAt, 11, 5)",
    '生成时按当天日期计算截止时间' => '$dueAt = dn_recurring_due_at($g, $rule, $date)',
    '固定截止时间函数' => 'function dn_recurring_due_at(array $group, array $rule, string $date): string',
    '生成任务使用当天截止时间' => "'created_by' => (int)\$g['created_by'], 'assigned_to' => (int)\$aid, 'task_date' => \$date, 'due_at' => \$dueAt",
];

foreach ($apiMarkers as $label => $needle) {
    if (strpos($api, $needle) === false) {
        fwrite(STDERR, "Missing fixed todo API marker: {$label}\n");
        exit(1);
    }
}

$forbidden = [
    "'due_at' => \$g['due_at'], 'is_read' => 0",
    "in_array((\$in['freq'] ?? 'daily'), ['daily','weekly','monthly'], true)",
];

foreach ($forbidden as $needle) {
    if (strpos($api, $needle) !== false) {
        fwrite(STDERR, "Forbidden old recurring behavior remains: {$needle}\n");
        exit(1);
    }
}

echo "dispatch fixed todo contract ok\n";
