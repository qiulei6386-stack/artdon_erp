<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$visit = file_get_contents($root . '/crm_visit.php');
$api = file_get_contents($root . '/crm_api.php');
$task = file_get_contents($root . '/crm_task_center.php');
$js = file_get_contents($root . '/assets/crm/crm.js');
$css = file_get_contents($root . '/assets/crm/crm.css');

if (in_array(false, [$visit, $api, $task, $js, $css], true)) {
    throw new RuntimeException('CRM visit result history sources are not readable');
}

foreach ([
    [$visit, 'CREATE TABLE IF NOT EXISTS crm_visit_results', '新增拜访结果明细表'],
    [$visit, 'result_source VARCHAR(40)', '结果明细保留来源标记'],
    [$visit, 'crm_visit_results($id, $row)', '读取拜访详情时返回结果历史'],
    [$visit, 'crm_visit_backfill_current_result($before)', '保存新结果前回填升级前当前结果'],
    [$visit, 'INSERT INTO crm_visit_results', '每次填写结果会新增明细记录'],
    [$visit, "'need_quote' => !empty(\$input['need_quote']) ? 1 : 0", '结果保存以后续勾选为准，可清除旧需求'],
    [$visit, "\$actualTime = trim((string)(\$input['actual_time'] ?? '')) ?: (trim((string)(\$before['actual_time'] ?? '')) ?: date('Y-m-d H:i:s'))", '二次填写结果保留原实际时间'],
    [$visit, 'result_history_count', '结果历史数量用于防重复回填'],
    [$api, "if (\$action === 'visit_get')", '新增按需读取拜访详情接口'],
    [$api, 'crm_visit_row((int)($_POST[\'visit_id\'] ?? 0))', '详情接口返回完整拜访记录'],
    [$task, "mb_substr(\$contentTitle, 0, 80, 'UTF-8')", '跟进任务标题使用多字节截断，避免中文截断导致写入失败'],
    [$js, 'detailCache', '前端缓存完整拜访详情'],
    [$js, "post('visit_get'", '点击记录后按需拉取结果历史'],
    [$js, 'resultHistoryHtml: function', '前端显示结果记录列表'],
    [$js, 'resultReferenceHtml: function', '再次填写结果时显示上次结果参考'],
    [$js, 'isAdditionalResult ? Object.assign', '已有结果时本次填写默认清空旧结果字段'],
    [$js, 'var uploadRow = isAdditionalResult ? Object.assign({}, row, { files: [] }) : row', '再次填写结果时图片/附件上传区默认不带出旧文件'],
    [$js, '本次新增结果默认不带出上次图片', '弹窗明确提示再次填写不会带出旧图片'],
    [$js, 'if (row.id && !isAdditionalResult) self.loadVisitFiles(row.id, dialog);', '再次填写结果时不重新加载拜访旧图片到本次表单'],
    [$js, 'data-visit-copy-last-result', '支持手动复制上次结果'],
    [$js, 'copyResultToDialog: function', '复制上次结果只在用户点击时执行'],
    [$js, 'openResultFromAction: function', '卡片和属性栏填结果统一走完整详情入口'],
    [$js, 'cached && Array.isArray(cached.result_history)', '已有完整详情缓存时直接打开结果弹窗'],
    [$js, "return this.loadDetail(id).then(function (record) { self.openResultDialog(record || row); });", '缺少完整历史时先拉完整详情再打开填结果弹窗'],
    [$js, 'return this.openResultFromAction(row);', '所有填结果按钮统一调用完整详情入口'],
    [$js, '保存会新增一条结果记录', '结果弹窗说明不再暗示覆盖'],
    [$js, '再次填写默认清空上次结果', '弹窗明确提示再次填写不会默认回填上次内容'],
    [$js, "formRow.actual_time || new Date().toISOString()", '打开结果弹窗优先带回原实际时间'],
    [$css, '.visit-result-history', '结果历史样式已接入'],
    [$css, '.visit-result-reference', '上次结果参考样式已接入'],
] as [$source, $needle, $label]) {
    if (!str_contains($source, $needle)) {
        throw new RuntimeException('缺少：' . $label);
    }
}

echo "crm_visit_result_history_contract: OK\n";
