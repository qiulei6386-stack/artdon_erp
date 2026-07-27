<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$php = file_get_contents($root . '/crm_marketing.php');
$api = file_get_contents($root . '/crm_api.php');
$js = file_get_contents($root . '/assets/crm/crm.js');
if ($php === false || $api === false || $js === false) {
    throw new RuntimeException('CRM marketing lazy-payload sources are not readable');
}

$sliceFunction = static function (string $source, string $startMarker, string $endMarker): string {
    $start = strpos($source, $startMarker);
    $end = strpos($source, $endMarker, $start === false ? 0 : $start + strlen($startMarker));
    if ($start === false || $end === false || $end <= $start) {
        throw new RuntimeException("Function boundary missing: {$startMarker}");
    }
    return substr($source, $start, $end - $start);
};

$tasks = $sliceFunction($php, 'function crm_marketing_tasks(): array', 'function crm_marketing_task_detail(');
foreach ([
    'CASE WHEN COALESCE(t.mail_body_html',
    'AS has_mail_body',
    'AS mail_body_bytes',
] as $marker) {
    if (!str_contains($tasks, $marker)) {
        throw new RuntimeException("Lightweight task-list marker missing: {$marker}");
    }
}
if (str_contains($tasks, 'SELECT t.*')) {
    throw new RuntimeException('Marketing task list must not preload full mail bodies');
}

$accounts = $sliceFunction($php, 'function crm_marketing_mail_accounts(): array', 'function crm_marketing_company_signature(');
foreach (['AS has_signature', 'AS signature_bytes'] as $marker) {
    if (!str_contains($accounts, $marker)) {
        throw new RuntimeException("Mail-account metadata marker missing: {$marker}");
    }
}
if (str_contains($accounts, 'a.signature_html, a.is_default')) {
    throw new RuntimeException('Marketing bootstrap must not preload personal signature HTML');
}

$company = $sliceFunction($php, 'function crm_marketing_company_signature(): array', 'function crm_marketing_signature_content(');
foreach (['AS has_template', 'AS template_bytes'] as $marker) {
    if (!str_contains($company, $marker)) {
        throw new RuntimeException("Company-signature metadata marker missing: {$marker}");
    }
}
if (str_contains($company, 'SELECT id, template_name, template_html')) {
    throw new RuntimeException('Marketing bootstrap must not preload company signature HTML');
}

foreach ([
    "crm_marketing_bootstrap_cached('mail_accounts_meta_v2'",
    "crm_marketing_bootstrap_cached('company_signature_meta_v2'",
    'function crm_marketing_task_detail(array $input = []): array',
    'function crm_marketing_signature_content(array $input = []): array',
] as $marker) {
    if (!str_contains($php, $marker)) {
        throw new RuntimeException("Lazy server payload marker missing: {$marker}");
    }
}

foreach ([
    "\$action === 'marketing_task_detail'",
    "\$action === 'marketing_signature_content'",
] as $marker) {
    if (!str_contains($api, $marker)) {
        throw new RuntimeException("Lazy endpoint marker missing: {$marker}");
    }
}

foreach ([
    'ensureTaskDetail: function (taskId)',
    "post('marketing_task_detail'",
    "post('marketing_signature_content'",
    'signatureHtmlCache: {}',
    "Number(task.has_mail_body || 0) === 1",
] as $marker) {
    if (!str_contains($js, $marker)) {
        throw new RuntimeException("Lazy frontend marker missing: {$marker}");
    }
}

$rename = $sliceFunction($js, 'openRenameTaskDialog: function (taskId)', 'openTaskLogsDialog: function (taskId)');
if (str_contains($rename, 'mail_body_html:')) {
    throw new RuntimeException('Renaming a lightweight task must not overwrite its deferred mail body');
}

$update = $sliceFunction($php, 'function crm_marketing_task_update(array $input): array', 'function crm_marketing_task_delete(');
foreach ([
    "array_key_exists('mail_subject', \$input)",
    "array_key_exists('mail_body_html', \$input)",
    "\$before['mail_body_html']",
] as $marker) {
    if (!str_contains($update, $marker)) {
        throw new RuntimeException("Safe partial task-update marker missing: {$marker}");
    }
}

echo "CRM marketing lazy payload contract: OK\n";
