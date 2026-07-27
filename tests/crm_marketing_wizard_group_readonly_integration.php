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
    throw new RuntimeException('No active administrator is available for the marketing wizard group test');
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

$group = db()->query("SELECT g.id, g.group_name
    FROM crm_marketing_groups g
    WHERE g.deleted_at IS NULL
      AND EXISTS (SELECT 1 FROM crm_marketing_group_customers x WHERE x.group_id = g.id)
    ORDER BY (SELECT COUNT(*) FROM crm_marketing_group_customers x WHERE x.group_id = g.id) DESC, g.id
    LIMIT 1")->fetch();
if (!$group) {
    throw new RuntimeException('No populated marketing group is available for the read-only wizard test');
}
$groupId = (int)$group['id'];

$expectedCustomers = (int)db()->query("SELECT COUNT(*) FROM (
    SELECT x.customer_id
    FROM crm_marketing_group_customers x
    JOIN crm_customers c ON c.id = x.customer_id AND c.deleted_at IS NULL
    WHERE x.group_id = {$groupId}
    UNION
    SELECT ct.customer_id
    FROM crm_marketing_group_contacts x
    JOIN crm_contacts ct ON ct.id = x.contact_id AND ct.deleted_at IS NULL
    JOIN crm_customers c ON c.id = ct.customer_id AND c.deleted_at IS NULL
    WHERE x.group_id = {$groupId}
) audience_customers")->fetchColumn();
$expectedContacts = (int)db()->query("SELECT COUNT(*) FROM (
    SELECT ct.id
    FROM crm_marketing_group_customers x
    JOIN crm_contacts ct ON ct.customer_id = x.customer_id AND ct.deleted_at IS NULL
    JOIN crm_customers c ON c.id = ct.customer_id AND c.deleted_at IS NULL
    WHERE x.group_id = {$groupId}
    UNION
    SELECT ct.id
    FROM crm_marketing_group_contacts x
    JOIN crm_contacts ct ON ct.id = x.contact_id AND ct.deleted_at IS NULL
    JOIN crm_customers c ON c.id = ct.customer_id AND c.deleted_at IS NULL
    WHERE x.group_id = {$groupId}
) audience_contacts")->fetchColumn();

$startedAt = microtime(true);
$groups = crm_marketing_groups();
$groupRow = null;
foreach ($groups as $row) {
    if ((int)($row['id'] ?? 0) === $groupId) {
        $groupRow = $row;
        break;
    }
}
if (!$groupRow) throw new RuntimeException('Populated marketing group is missing from group summary');
if ((int)$groupRow['customer_count'] !== $expectedCustomers) {
    throw new RuntimeException('Marketing group customer count does not match its resolvable audience');
}
if ((int)$groupRow['contact_count'] !== $expectedContacts) {
    throw new RuntimeException('Marketing group contact count does not include contacts inherited from group customers');
}

$preview = crm_marketing_target_preview([
    'group_mode' => 'group',
    'group_key' => (string)$groupId,
]);
$elapsedMs = (int)round((microtime(true) - $startedAt) * 1000);
if ((int)($preview['audience_customer_count'] ?? -1) !== $expectedCustomers) {
    throw new RuntimeException('Wizard group preview customer count does not match the selected group');
}
if (count($preview['audience_customer_ids'] ?? []) !== $expectedCustomers) {
    throw new RuntimeException('Wizard group preview did not resolve every selected group customer');
}
if (count($preview['pool'] ?? []) !== $expectedCustomers) {
    throw new RuntimeException('Wizard group preview did not return every selected group customer row');
}

echo json_encode([
    'ok' => true,
    'group_id' => $groupId,
    'group_name' => $group['group_name'],
    'customer_count' => $expectedCustomers,
    'contact_count' => $expectedContacts,
    'promotable_contact_count' => (int)($groupRow['promotable_contact_count'] ?? 0),
    'elapsed_ms' => $elapsedMs,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
