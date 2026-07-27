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
    throw new RuntimeException('No active administrator is available for the read-only marketing pool test');
}
$_SESSION['user_id'] = $userId;

require_once $root . '/crm_config.php';
require_once $root . '/crm_auth.php';
require_once $root . '/crm_log.php';
require_once $root . '/crm_customer.php';
require_once $root . '/crm_visit.php';
require_once $root . '/crm_task_center.php';
require_once $root . '/crm_opportunity.php';
require_once $root . '/crm_mail.php';
require_once $root . '/crm_marketing.php';

$pageSize = 50;
$startedAt = microtime(true);
$result = crm_marketing_pool_view([
    'page' => 1,
    'page_size' => $pageSize,
    'skip_count' => 1,
]);
$elapsedMs = (int)round((microtime(true) - $startedAt) * 1000);
$rows = $result['pool'] ?? [];
$pager = $result['pool_pager'] ?? [];

if (count($rows) > $pageSize) {
    throw new RuntimeException('Marketing pool hydrated more customer rows than the requested page size');
}
if ((int)($pager['shown_count'] ?? -1) !== count($rows)) {
    throw new RuntimeException('Marketing pool shown_count does not match the returned current page');
}
if ((int)($pager['total_is_exact'] ?? 1) !== 0) {
    throw new RuntimeException('Marketing pool endpoint unexpectedly performed an exact total count');
}
if (array_key_exists('contacts', $result)) {
    throw new RuntimeException('Marketing pool endpoint unexpectedly preloaded contact strategy rows');
}

echo json_encode([
    'ok' => true,
    'page_size' => $pageSize,
    'returned_rows' => count($rows),
    'has_more' => (int)($pager['has_more'] ?? 0),
    'elapsed_ms' => $elapsedMs,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
