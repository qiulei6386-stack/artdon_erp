<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$visit = file_get_contents($root . '/crm_visit.php');
$api = file_get_contents($root . '/miniapp/api/index.php');
$js = file_get_contents($root . '/assets/crm/crm.js');
if (in_array(false, [$visit, $api, $js], true)) {
    throw new RuntimeException('跟进记录来源文件不可读');
}

function followup_contract_extract(string $src, string $name): string
{
    $needle = 'function ' . $name;
    $start = strpos($src, $needle);
    if ($start === false) throw new RuntimeException('缺少函数 ' . $name);
    $open = strpos($src, '{', $start);
    if ($open === false) throw new RuntimeException($name . ' 没有函数体');
    $depth = 0;
    $len = strlen($src);
    for ($i = $open; $i < $len; $i++) {
        $ch = $src[$i];
        if ($ch === '{') $depth++;
        elseif ($ch === '}') {
            $depth--;
            if ($depth === 0) return substr($src, $start, $i - $start + 1);
        }
    }
    throw new RuntimeException($name . ' 函数体没有结束');
}

$fns = '';
foreach ([
    'crm_visit_followup_category',
    'crm_visit_followup_purposes',
    'crm_visit_is_followup_record',
    'crm_visit_followup_title',
    'crm_visit_category_terms',
] as $name) {
    $fns .= followup_contract_extract($visit, $name) . "\n";
}
eval($fns);

if (crm_visit_followup_category() !== '跟进记录') throw new RuntimeException('跟进分类常量不对');
if (crm_visit_followup_purposes() !== ['电话', '视频电话', '视频会议', '当面', '其他']) {
    throw new RuntimeException('跟进方式不是约定的五项');
}
if (crm_visit_followup_title('海湾灯饰', '视频会议') !== '跟进记录：海湾灯饰（视频会议）') {
    throw new RuntimeException('跟进标题格式不对');
}
if (crm_visit_followup_title('', '') !== '跟进记录：客户') throw new RuntimeException('空客户标题不对');
if (!crm_visit_is_followup_record(['visit_category' => '跟进记录', 'visit_type' => 'customer_visit'])) {
    throw new RuntimeException('跟进记录没有被识别');
}
if (crm_visit_is_followup_record(['visit_category' => '初次拜访', 'visit_type' => 'customer_visit'])) {
    throw new RuntimeException('普通拜访被当成跟进');
}
if (crm_visit_category_terms('跟进记录') !== ['跟进记录']) throw new RuntimeException('单个排除项解析不对');
if (crm_visit_category_terms(['跟进记录', ' 跟进记录 ', '']) !== ['跟进记录']) throw new RuntimeException('重复排除项没有去重');
if (crm_visit_category_terms('') !== []) throw new RuntimeException('空排除项不应该生成条件');

foreach ([
    [$visit, "\$where[] = 'v.visit_category = ?';", '拜访列表支持 visit_category 精确筛选'],
    [$visit, "v.visit_category NOT IN (", '拜访列表支持 exclude_visit_category'],
    [$visit, "v.visit_category <> '", '今日拜访统计排除跟进记录'],
    [$visit, "throw new RuntimeException('请选择跟进方式');", '保存跟进记录时跟进方式必填'],
    [$visit, "\$message = \$category === crm_visit_followup_category() ? '创建跟进记录'", '新建跟进写入客户时间轴'],
    [$visit, "\$type = 'customer_visit';", '跟进记录固定为 customer_visit'],
    [$api, 'function mx_visit_list_args', '小程序列表复用拜访列表参数'],
    [$api, "\$base['exclude_visit_category'] = crm_visit_followup_category();", '小程序拜访/来访默认排除跟进记录'],
    [$api, "if (\$category === crm_visit_followup_category()) \$base['visit_type'] = 'customer_visit';", '跟进列表固定拜访类型'],
    [$api, "if (!in_array(\$purpose, crm_visit_followup_purposes(), true)) mapi_out(false, '请选择跟进方式');", '小程序保存跟进时校验方式'],
    [$api, "mapi_out(false, '跟进记录不需要签到');", '跟进记录不签到'],
    [$api, "'record_kind' => 'followup', 'type_label' => '跟进'", '列表和详情用跟进标签'],
    [$api, "'location_required' => false", '跟进地点不是必填'],
    [$api, "\$stats['followup']++", '今日统计单独计跟进'],
    [$js, "filters.exclude_visit_category = '跟进记录'", '网站拜访/来访页排除跟进记录'],
    [$js, "var isFollowup = String(row.visit_category || '') === '跟进记录';", '网站编辑已有跟进记录时保留分类'],
    [$js, "['电话', '视频电话', '视频会议', '当面', '其他']", '网站编辑跟进时提供五项跟进方式'],
    [$js, '地点（选填）', '网站编辑跟进时地点选填'],
    [$js, 'visitRecordKind', '客户详情把跟进记录标成跟进'],
] as [$source, $needle, $label]) {
    if (!str_contains($source, $needle)) throw new RuntimeException('缺少：' . $label);
}

if (str_contains($api, 'crm_customer_followups') && preg_match("/case 'visit_save':.*crm_customer_followups/s", $api)) {
    throw new RuntimeException('跟进记录不应该双写 crm_customer_followups');
}
if (preg_match("/case 'followup_/", $api)) throw new RuntimeException('不应该新增 followup API');

echo "crm_visit_followup_category_contract: OK\n";
