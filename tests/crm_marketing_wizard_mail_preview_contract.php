<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$php = file_get_contents($root . '/crm_marketing.php');
$js = file_get_contents($root . '/assets/crm/crm.js');
$css = file_get_contents($root . '/assets/crm/crm.css');
if ($php === false || $js === false || $css === false) {
    throw new RuntimeException('CRM marketing wizard preview sources are not readable');
}

$requiredJs = [
    'buildWizardMailPreviewItems: function (draft, plan)',
    'var mailItems = this.buildWizardMailPreviewItems(draft, plan);',
    "previewItems.push({",
    "_sample_preview: true",
    'data-promo-mail-count="',
    '← 上一封',
    '下一封 →',
    '% count',
    "button.textContent = '发送中...'",
    'data-promo-confirm-scheduled>保存为计划',
    "footerRightClass = 'promo-wizard-foot-right' + (isConfirmStep ? ' is-confirm' : '')",
    '保存草稿、保存为计划和生成执行队列统一固定在底部操作栏',
];
foreach ($requiredJs as $marker) {
    if (!str_contains($js, $marker)) {
        throw new RuntimeException("CRM wizard mail preview marker missing: {$marker}");
    }
}

if (str_contains($js, '当前没有可测试的邮件队列')) {
    throw new RuntimeException('test send must not depend on a formal marketing queue');
}
if (str_contains($js, '<section class="promo-confirm-actions">')) {
    throw new RuntimeException('step 9 must not render a second action bar inside the scroll area');
}

$requiredCss = [
    'padding: 12px 12px 28px !important;',
    'scroll-padding-bottom: 28px !important;',
    'body.is-promo-task-editor-open .promo-wizard-foot-right.is-confirm',
    'grid-template-columns: repeat(3, minmax(0, 1fr)) !important;',
    'box-shadow: 0 -8px 20px rgb(15 23 42 / .06) !important;',
];
foreach ($requiredCss as $marker) {
    if (!str_contains($css, $marker)) {
        throw new RuntimeException("CRM wizard footer layout marker missing: {$marker}");
    }
}

$start = strpos($php, 'function crm_marketing_test_send(array $input): array');
$end = strpos($php, 'function crm_marketing_failure_handle(array $input): array');
if ($start === false || $end === false || $end <= $start) {
    throw new RuntimeException('CRM marketing test-send function boundary is missing');
}
$testSend = substr($php, $start, $end - $start);
foreach ([
    "crm_mail_current_account(true)",
    "crm_mail_smtp_send(\$account, \$sendInput, [])",
    "'test_email' => \$testEmail",
] as $marker) {
    if (!str_contains($testSend, $marker)) {
        throw new RuntimeException("CRM marketing test-send fallback marker missing: {$marker}");
    }
}
if (str_contains($testSend, 'crm_marketing_send_queue')) {
    throw new RuntimeException('server test send must remain independent from the formal send queue');
}

echo "CRM marketing wizard mail preview contract: OK\n";
