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
if ($userId <= 0) throw new RuntimeException('No active administrator is available for the marketing wizard transaction test');
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

// Warm all schema guards before the rollback-only transaction. MySQL DDL
// performs an implicit commit even when CREATE TABLE IF NOT EXISTS is a no-op.
crm_ensure_tables();
crm_marketing_ensure_tables();

$group = db()->query("SELECT g.id, g.group_name
    FROM crm_marketing_groups g
    WHERE g.deleted_at IS NULL
      AND EXISTS (SELECT 1 FROM crm_marketing_group_customers x WHERE x.group_id = g.id)
    ORDER BY (SELECT COUNT(*) FROM crm_marketing_group_customers x WHERE x.group_id = g.id) DESC, g.id
    LIMIT 1")->fetch();
if (!$group) throw new RuntimeException('No populated marketing group is available for the wizard transaction test');
$groupId = (int)$group['id'];

$resolved = crm_marketing_resolve_audience_customers([
    'group_mode' => 'group',
    'group_key' => (string)$groupId,
]);
$policy = crm_marketing_apply_audience_policy($resolved['rows'] ?? [], 'skip');
$expectedCustomers = count($policy['customer_ids'] ?? []);
if ($expectedCustomers <= 0) throw new RuntimeException('Selected marketing group has no executable customers');

$taskName = '__CODEX_WIZARD_GROUP_TX__' . date('YmdHis');
$pdo = db();
$pdo->beginTransaction();
try {
    $created = crm_marketing_task_create([
        'task_status' => 'draft',
        'task_name' => $taskName,
        'channel_key' => 'offline',
        'campaign_type' => 'offline',
        'schedule_type' => 'manual',
        'audience_config' => json_encode([
            'group_mode' => 'group',
            'group_key' => (string)$groupId,
            'contact_filter' => 'all_valid',
        ], JSON_UNESCAPED_UNICODE),
        'failure_policy' => json_encode([
            'blacklist_policy' => 'skip',
            'no_email_policy' => 'offline',
        ], JSON_UNESCAPED_UNICODE),
    ]);
    $taskId = (int)($created['task_id'] ?? 0);
    if ($taskId <= 0) throw new RuntimeException('Wizard group transaction test did not create a draft task');
    $stmt = $pdo->prepare('SELECT customer_count, contact_count FROM crm_marketing_tasks WHERE id = ?');
    $stmt->execute([$taskId]);
    $task = $stmt->fetch();
    if ((int)($task['customer_count'] ?? -1) !== $expectedCustomers) {
        throw new RuntimeException('Saved task customer count does not match the server-resolved group audience');
    }
    $targetStmt = $pdo->prepare('SELECT COUNT(*) FROM crm_marketing_task_targets WHERE task_id = ?');
    $targetStmt->execute([$taskId]);
    if ((int)$targetStmt->fetchColumn() !== $expectedCustomers) {
        throw new RuntimeException('Saved task target rows do not match the server-resolved group audience');
    }
    $pdo->rollBack();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

$leftover = $pdo->prepare('SELECT COUNT(*) FROM crm_marketing_tasks WHERE task_name = ?');
$leftover->execute([$taskName]);
if ((int)$leftover->fetchColumn() !== 0) {
    throw new RuntimeException('Wizard group transaction test left a task row behind');
}

echo json_encode([
    'ok' => true,
    'group_id' => $groupId,
    'group_name' => $group['group_name'],
    'resolved_customers' => count($resolved['customer_ids'] ?? []),
    'executable_customers' => $expectedCustomers,
    'rolled_back' => true,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
