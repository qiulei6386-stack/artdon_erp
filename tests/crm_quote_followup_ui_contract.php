<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$js = file_get_contents($root . '/assets/crm/crm.js');
$css = file_get_contents($root . '/assets/crm/crm.css');
$service = file_get_contents($root . '/crm_task_center.php');
if ($js === false || $css === false || $service === false) {
    throw new RuntimeException('quote follow-up sources are not readable');
}

foreach ([
    "post('quote_followup_context', {quote_source:source,quote_id:quoteId})",
    '报价摘要',
    'CRM 显示只读的报价、产品与订单关联快照',
    'quote_preview',
    'quote-preview-products',
    '订单信息',
    'return response.text().then(function (text) {',
    'error.quoteFollowupSaved = true',
    '跟进已保存，但截图未上传：',
    'compressQuoteFollowupImage: function (file)',
    'uploadQuoteFollowupFiles: function (activityId, input)',
    '服务器没有完整保存截图，请在历史记录中重新上传。',
    '沟通截图（',
    '修改并补传图片',
    'quote-followup-history-file',
    '一次最多上传 4 张沟通截图。',
    'data-quote-followup-choose',
    '本次只记录客户沟通，不进入报价编辑',
] as $marker) {
    if (!str_contains($js, $marker)) {
        throw new RuntimeException("quote follow-up UI marker missing: {$marker}");
    }
}
foreach (['.customer-dialog.quote-followup-modal', '.quote-followup-modal .customer-dialog-body', 'overflow:visible!important', '.quote-preview-product'] as $marker) {
    if (!str_contains($css, $marker)) {
        throw new RuntimeException("quote follow-up CSS marker missing: {$marker}");
    }
}
foreach (['function crm_quote_followup_legacy_preview', 'function crm_quote_followup_cc_preview', '截图超过服务器允许大小，请选择较小图片后重试。', '截图临时文件无效，请重新选择图片。', 'storage/visit_files/quote_followups/', '沟通截图目录不可写', '!is_file($target)'] as $marker) {
    if (!str_contains($service, $marker)) {
        throw new RuntimeException("quote follow-up upload marker missing: {$marker}");
    }
}
if (str_contains($service, 'uploads/crm_quote_followups/')) {
    throw new RuntimeException('quote follow-up upload must not use deployment-owned uploads root');
}

echo "CRM quote follow-up UI contract: OK\n";
