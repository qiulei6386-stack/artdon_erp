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
    '组行方式不再硬编码多派' => 'r.method_label||tableMethodLabel(methodValue(r))',
    '详情方式使用组标签' => 'function groupMethodText(g={},t={})',
    '固定待办停用按钮' => 'data-stop-recurring="${esc(gid)}"',
    '固定待办手机菜单停用按钮' => 'data-can-stop-recurring="${r.can_stop_recurring?1:0}"',
    '固定待办详情停用按钮' => 'g.can_stop_recurring?`<button class="btn dangerAction" type="button" data-stop-recurring="${esc(g.id)}">停用固定</button>`',
    '固定待办停用确认' => '只停止以后自动生成，已经生成的历史待办会保留。',
    '固定待办停用调用' => "api('stop_recurring',{group_id:gid})",
    '固定待办停用点击监听' => "e.target.closest('[data-stop-recurring]')",
    '固定待办组行查找' => 'function findGroupRowById(gid)',
    '固定待办表格保存带日期' => "payload=fixed?{group_id:gid,date:state.date}:{group_id:gid}",
    '固定待办表格保存专用接口' => "api(fixed?'update_fixed_occurrence':'update_multi',payload)",
    '固定待办当天保存提示' => "已保存当天固定待办",
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
    '固定待办组行显示固定' => "return (string)(\$rule['kind'] ?? '') === 'fixed_todo' ? '固定' : '周派'",
    '组行可按当前日期显示截止日期' => 'function dn_group_display_due_at(array $group, array $children = [], ?string $displayDate = null): string',
    '列表组行传入当前日期' => '$g = dn_group_row($gid, $dispatchPersonIds, $date)',
    '固定待办旧错日期自动修复' => 'DATE(due_at)<task_date',
    '固定列表优先当天实例显示' => '固定待办列表显示优先用当天实例内容',
    '固定待办编辑母板只改规则时间' => "\$rule['due_time'] = substr(\$newDue, 11, 5)",
    '固定待办编辑按子任务日期重算截止' => "\$childUp->execute([dn_recurring_due_at(['due_at' => \$newDue], \$rule, \$childDate), (int)\$child['id']])",
    '新固定待办组截止按开始日保存' => "\$groupDueAt = dn_recurring_due_at(['due_at' => \$dueAt], \$rule, \$startDate)",
    '生成时按当天日期计算截止时间' => '$dueAt = dn_recurring_due_at($g, $rule, $date)',
    '固定截止时间函数' => 'function dn_recurring_due_at(array $group, array $rule, string $date): string',
    '生成任务使用当天截止时间' => "'created_by' => (int)\$g['created_by'], 'assigned_to' => (int)\$aid, 'task_date' => \$date, 'due_at' => \$dueAt",
    '停用固定规则接口' => 'function dn_stop_recurring(array $in): array',
    '停用只改规则不删历史' => "UPDATE dispatch_next_groups SET is_active=0,status='stopped'",
    '停止后生成器不再扫描' => "WHERE group_type='recurring' AND is_active=1",
    '停用按钮仅固定待办组行显示' => '&& $isFixedTodo',
    '停用按钮仅固定待办详情显示' => "&& (int)\$group['is_fixed_todo'] === 1",
    '停用接口路由' => "case 'stop_recurring': dn_ok(dn_stop_recurring(\$in));",
    '组行返回停用权限' => "'can_stop_recurring' => \$canStopRecurring ? 1 : 0",
    '详情返回停用权限' => "\$group['can_stop_recurring']",
    '固定当天实例更新接口' => 'function dn_update_fixed_todo_occurrence(array $in): array',
    '固定当天实例更新不改母板' => "修改当天固定待办实例，不更新母板",
    '旧更新组接口固定待办兜底' => "return dn_update_fixed_todo_occurrence_for_group(\$g, \$in);",
    '固定当天实例接口路由' => "case 'update_fixed_occurrence': dn_ok(dn_update_fixed_todo_occurrence(\$in));",
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
    "if(key==='dispatch_mode')return `<span class=\"tableMethodText\">多派</span>`",
    "'method_label' => '多派',",
];

foreach ($forbidden as $needle) {
    if (strpos($api, $needle) !== false) {
        fwrite(STDERR, "Forbidden old recurring behavior remains: {$needle}\n");
        exit(1);
    }
}

echo "dispatch fixed todo contract ok\n";
