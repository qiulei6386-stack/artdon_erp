<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/bootstrap.php';

$userId = (int)db()->query("SELECT u.id
    FROM crm_users u
    LEFT JOIN crm_roles r ON r.id = u.role_id
    WHERE u.status = 'active' AND r.role_key IN ('super_admin', 'admin')
    ORDER BY FIELD(r.role_key, 'super_admin', 'admin'), u.id
    LIMIT 1")->fetchColumn();
if ($userId <= 0) {
    throw new RuntimeException('No active administrator is available for the marketing payload test');
}
$_SESSION['user_id'] = $userId;

foreach ([
    'crm_config.php',
    'crm_auth.php',
    'crm_log.php',
    'crm_customer.php',
    'crm_visit.php',
    'crm_task_center.php',
    'crm_opportunity.php',
    'crm_mail.php',
    'crm_marketing.php',
] as $file) {
    require_once $root . '/' . $file;
}

$results = [];
$campaignTasks = [];
$campaignAccounts = [];
foreach (['campaigns', 'customer_pool', 'group_management'] as $view) {
    $startedAt = microtime(true);
    $payload = crm_marketing_bootstrap([
        'view' => $view,
        'page' => 1,
        'page_size' => 50,
        'skip_count' => 1,
    ]);
    foreach (($payload['tasks'] ?? []) as $task) {
        if (array_key_exists('mail_body_html', $task)) {
            throw new RuntimeException("{$view} bootstrap leaked a deferred task mail body");
        }
    }
    foreach (($payload['mail_accounts'] ?? []) as $account) {
        if (array_key_exists('signature_html', $account)) {
            throw new RuntimeException("{$view} bootstrap leaked deferred personal signature HTML");
        }
    }
    if (array_key_exists('template_html', $payload['company_signature'] ?? [])) {
        throw new RuntimeException("{$view} bootstrap leaked deferred company signature HTML");
    }
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) throw new RuntimeException("{$view} bootstrap could not be encoded");
    $rawBytes = strlen($json);
    $gzip = function_exists('gzencode') ? gzencode($json, 6) : false;
    $gzipBytes = is_string($gzip) ? strlen($gzip) : null;
    if ($rawBytes > 512 * 1024) {
        throw new RuntimeException("{$view} bootstrap is still too large: {$rawBytes} bytes");
    }
    $results[$view] = [
        'raw_bytes' => $rawBytes,
        'gzip_bytes' => $gzipBytes,
        'elapsed_ms' => (int)round((microtime(true) - $startedAt) * 1000),
        'task_count' => count($payload['tasks'] ?? []),
        'pool_count' => count($payload['pool'] ?? []),
    ];
    if ($view === 'campaigns') {
        $campaignTasks = $payload['tasks'] ?? [];
        $campaignAccounts = $payload['mail_accounts'] ?? [];
    }
}

$lazy = [];
if ($campaignTasks) {
    $detail = crm_marketing_task_detail(['task_id' => (int)$campaignTasks[0]['id']]);
    $task = $detail['task'] ?? [];
    if (!array_key_exists('mail_body_html', $task) || (int)($task['_detail_loaded'] ?? 0) !== 1) {
        throw new RuntimeException('Deferred task detail did not restore the full mail content');
    }
    $lazy['task_detail_bytes'] = strlen(json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
}
$company = crm_marketing_signature_content(['signature_key' => 'company']);
if (!array_key_exists('html', $company)) {
    throw new RuntimeException('Deferred company signature did not return an HTML field');
}
$lazy['company_signature_bytes'] = strlen((string)($company['html'] ?? ''));
if ($campaignAccounts) {
    $personal = crm_marketing_signature_content([
        'signature_key' => 'personal',
        'mail_account_id' => (int)$campaignAccounts[0]['id'],
    ]);
    if (!array_key_exists('html', $personal)) {
        throw new RuntimeException('Deferred personal signature did not return an HTML field');
    }
    $lazy['personal_signature_bytes'] = strlen((string)($personal['html'] ?? ''));
}

echo json_encode(['ok' => true, 'views' => $results, 'lazy' => $lazy], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
