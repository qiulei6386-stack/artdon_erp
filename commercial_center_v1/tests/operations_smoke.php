<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Artdon\CommercialCenter\Repositories\LegacyOperationsReadRepository;

$statement = db()->query(
    "SELECT id,username,real_name,is_super_admin
     FROM crm_users
     WHERE status='active'
     ORDER BY id ASC
     LIMIT 1"
);
$legacyUser = $statement->fetch(PDO::FETCH_ASSOC);
if (!$legacyUser) {
    fwrite(STDERR, "No active legacy user is available for scoped read smoke test.\n");
    exit(1);
}
$user = [
    'id' => (int)$legacyUser['id'],
    'username' => (string)$legacyUser['username'],
    'display_name' => (string)($legacyUser['real_name'] ?: $legacyUser['username']),
    'is_super_admin' => (int)$legacyUser['is_super_admin'] === 1,
];
$repository = new LegacyOperationsReadRepository();
$results = [
    'work_queue' => $repository->dispatchQueue($user, 2),
    'delivery_queue' => $repository->quoteDeliveryQueue($user, 2),
    'orders' => $repository->orders($user, 2),
    'exceptions' => $repository->exceptions($user),
    'activity' => $repository->recentActivity($user, 2),
];
foreach ($results as $key => $rows) {
    if (!is_array($rows)) {
        fwrite(STDERR, "Invalid operations result: {$key}\n");
        exit(1);
    }
}
echo "PASS: M1 operations queries executed read-only with legacy user scope.\n";
