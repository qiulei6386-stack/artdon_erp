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
    'CRM 只显示与跟进有关的报价资料',
    'return response.text().then(function (text) {',
    'error.quoteFollowupSaved = true',
    '跟进已保存，但截图未上传：',
    'compressQuoteFollowupImage: function (file)',
    'uploadQuoteFollowupFiles: function (activityId, input)',
    '一次最多上传 4 张沟通截图。',
    'data-quote-followup-choose',
    '本次只记录客户沟通，不进入报价编辑',
] as $marker) {
    if (!str_contains($js, $marker)) {
        throw new RuntimeException("quote follow-up UI marker missing: {$marker}");
    }
}
foreach (['.customer-dialog.quote-followup-modal', '.quote-followup-upload-card', '.quote-preview-summary-dialog'] as $marker) {
    if (!str_contains($css, $marker)) {
        throw new RuntimeException("quote follow-up CSS marker missing: {$marker}");
    }
}
foreach (['截图超过服务器允许大小，请选择较小图片后重试。', '截图临时文件无效，请重新选择图片。'] as $marker) {
    if (!str_contains($service, $marker)) {
        throw new RuntimeException("quote follow-up upload marker missing: {$marker}");
    }
}

echo "CRM quote follow-up UI contract: OK\n";
