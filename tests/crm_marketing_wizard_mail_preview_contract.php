<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$php = file_get_contents($root . '/crm_marketing.php');
$page = file_get_contents($root . '/crm.php');
$js = file_get_contents($root . '/assets/crm/crm.js');
$css = file_get_contents($root . '/assets/crm/crm.css');
if ($php === false || $page === false || $js === false || $css === false) {
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
    'promo-wizard-project-actions',
    '<header>项目操作</header>',
    'data-promo-wizard-draft>保存草稿',
    'data-promo-confirm-scheduled>保存为计划',
    'data-promo-confirm-queue>生成执行队列',
    'data-promo-confirm-mail-stage data-promo-preview-build="20260727-2"',
    '邮件预览与测试发送',
    '预览修复版 20260727-2',
    'initialMailPreview = this.renderWizardMailCarousel(draft, plan);',
    "console.error('Promotion step 9 initial mail preview failed:', previewError);",
    'box.innerHTML = this.renderWizardMailCarousel(draft, plan) +',
    "var initialPreviewBox = modal.querySelector('[data-promo-wizard-preview]');",
    'if (initialPreviewBox) this.bindWizardPreviewControls(initialPreviewBox);',
    "console.error('Promotion mail preview refresh failed:', previewError);",
    "['preference', 'customer_preference', 'auto_preference', 'mixed'].indexOf(previewChannel) >= 0",
    '邮件逐封预览和测试发信固定显示在第 9 步第一屏',
];
foreach ($requiredJs as $marker) {
    if (!str_contains($js, $marker)) {
        throw new RuntimeException("CRM wizard mail preview marker missing: {$marker}");
    }
}

if (str_contains($js, '当前没有可测试的邮件队列')) {
    throw new RuntimeException('test send must not depend on a formal marketing queue');
}
if (str_contains($js, '<div data-promo-wizard-preview></div>')) {
    throw new RuntimeException('step 9 must render an initial mail preview instead of an empty deferred placeholder');
}
if (str_contains($js, '<section class="promo-confirm-actions">')) {
    throw new RuntimeException('step 9 must not render a second action bar inside the scroll area');
}
if (str_contains($js, 'data-promo-aside-check') || str_contains($js, '<summary>快捷检查</summary>')) {
    throw new RuntimeException('redundant sidebar quick checks must be replaced by persistent project actions');
}
if (str_contains($js, "footerRightClass = 'promo-wizard-foot-right'")) {
    throw new RuntimeException('project save and execution actions must not depend on the footer confirmation step');
}

$confirmStart = strpos($js, 'renderConfirmStepLayout: function (draft)');
$confirmEnd = strpos($js, 'renderExecutionRulePreview: function', $confirmStart === false ? 0 : $confirmStart);
if ($confirmStart === false || $confirmEnd === false || $confirmEnd <= $confirmStart) {
    throw new RuntimeException('wizard confirmation step source boundary is missing');
}
$confirm = substr($js, $confirmStart, $confirmEnd - $confirmStart);
$mailStagePosition = strpos($confirm, "' + mailPreviewStage + '");
$summaryPosition = strpos($confirm, '<div class="promo-step-mini-stats promo-confirm-summary">');
if ($mailStagePosition === false || $summaryPosition === false || $mailStagePosition >= $summaryPosition) {
    throw new RuntimeException('step 9 mail preview and test-send stage must render before summary and quality cards');
}

$sidebarStart = strpos($js, 'renderWizardSidebar: function (draft)');
$sidebarEnd = strpos($js, 'wizardTopCounts: function', $sidebarStart === false ? 0 : $sidebarStart);
if ($sidebarStart === false || $sidebarEnd === false || $sidebarEnd <= $sidebarStart) {
    throw new RuntimeException('wizard sidebar source boundary is missing');
}
$sidebar = substr($js, $sidebarStart, $sidebarEnd - $sidebarStart);
foreach ([
    '<section class="promo-wizard-project-actions">',
    'data-promo-wizard-draft>保存草稿',
    'data-promo-confirm-scheduled>保存为计划',
    'data-promo-confirm-queue>生成执行队列',
] as $marker) {
    if (!str_contains($sidebar, $marker)) {
        throw new RuntimeException("persistent project action is missing from wizard sidebar: {$marker}");
    }
}

$footerStart = strpos($js, '<footer class="promo-wizard-foot');
$footerEnd = strpos($js, '</footer>', $footerStart === false ? 0 : $footerStart);
if ($footerStart === false || $footerEnd === false || $footerEnd <= $footerStart) {
    throw new RuntimeException('wizard footer source boundary is missing');
}
$footer = substr($js, $footerStart, $footerEnd - $footerStart);
foreach (['data-promo-wizard-draft', 'data-promo-confirm-scheduled', 'data-promo-confirm-queue'] as $marker) {
    if (str_contains($footer, $marker)) {
        throw new RuntimeException("project action must not remain in wizard footer: {$marker}");
    }
}

$requiredCss = [
    'padding: 12px 12px 28px !important;',
    'scroll-padding-bottom: 28px !important;',
    'body.is-promo-task-editor-open .promo-wizard-project-actions',
    'nav button[data-promo-confirm-queue]',
    'body.is-promo-task-editor-open .promo-confirm-mail-stage',
    'order: -10 !important;',
    'border: 2px solid #2563eb !important;',
    'box-shadow: 0 -8px 20px rgb(15 23 42 / .06) !important;',
];
foreach ($requiredCss as $marker) {
    if (!str_contains($css, $marker)) {
        throw new RuntimeException("CRM wizard footer layout marker missing: {$marker}");
    }
}

if (!str_contains($page, "\$crmAssetBuild = 'promotion-preview-20260727-2';")) {
    throw new RuntimeException('CRM page must explicitly bust the promotion preview asset cache');
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
