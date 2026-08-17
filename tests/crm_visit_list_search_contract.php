<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$visit = file_get_contents($root . '/crm_visit.php');
$page = file_get_contents($root . '/crm.php');
$js = file_get_contents($root . '/assets/crm/crm.js');
$css = file_get_contents($root . '/assets/crm/crm.css');

if (in_array(false, [$visit, $page, $js, $css], true)) {
    throw new RuntimeException('CRM visit list search sources are not readable');
}

foreach ([
    [$page, 'data-visit-keyword', '拜访/来访列表页面提供关键词搜索输入框'],
    [$page, 'data-visit-search-clear', '拜访/来访列表页面提供清空搜索按钮'],
    [$visit, "\$keyword = trim((string)(\$input['keyword'] ?? ''));", '后端接收 keyword 参数'],
    [$visit, "c.customer_name LIKE ?", '后端支持按客户名称模糊搜索'],
    [$visit, "c.customer_code LIKE ?", '后端支持按客户代码模糊搜索'],
    [$visit, "ct.name LIKE ?", '后端支持按联系人模糊搜索'],
    [$visit, "ct.email LIKE ?", '后端支持按联系人邮箱模糊搜索'],
    [$visit, "u.username LIKE ?", '后端支持按负责人账号模糊搜索'],
    [$visit, "v.location LIKE ?", '后端支持按拜访地点模糊搜索'],
    [$visit, "vrk.result_note LIKE ?", '后端支持按历史结果内容模糊搜索'],
    [$visit, 'for ($i = 0; $i < 35; $i += 1)', 'keyword LIKE 参数数量与搜索字段数量保持一致'],
    [$js, "keyword: ''", '前端维护拜访列表搜索关键词状态'],
    [$js, "document.querySelector('[data-visit-keyword]')", '前端绑定搜索输入框'],
    [$js, 'setTimeout(function () { self.selectedId = 0; self.load(); }, 350)', '输入关键词后防抖刷新列表'],
    [$js, "event.key !== 'Enter'", '回车立即执行搜索'],
    [$js, "keyword: this.keyword || ''", 'visit_list 请求携带 keyword'],
    [$js, "没有匹配的拜访 / 来访记录", '搜索无结果时显示明确提示'],
    [$css, '.visit-list-search', '列表搜索框样式已接入'],
    [$css, '.visit-toolbar-actions .visit-list-search button', '搜索框清空按钮使用专用样式'],
] as [$source, $needle, $label]) {
    if (!str_contains($source, $needle)) {
        throw new RuntimeException('缺少：' . $label);
    }
}

echo "crm_visit_list_search_contract: OK\n";
