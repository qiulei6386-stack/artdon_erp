<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/website_inquiry_staging_bridge.php');
if (!is_string($source)) {
    fwrite(STDERR, "Unable to read bridge source.\n");
    exit(1);
}

$required = [
    "function gz_bridge_complete_inquiry",
    "function gz_bridge_new_desk_sync",
    "function gz_bridge_new_desk_belongs",
    "'inquiry.status_changed'",
    'Only replied, closed, new, or processing inquiry status can sync linked desk tasks.',
    "SET status='done', updated_at=NOW()",
    "linked_system='website_inquiry'",
    "linked_table='website_inquiry_staging'",
    "'new_desk_task_ids' => \$newDeskIds",
    'new_desk_error',
    'Linked desk tasks reopened.',
    "hash_hmac('sha256'",
    'Bridge signature mismatch.',
    "gz_bridge_verify(\$config, \$body)",
];
foreach ($required as $needle) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "Missing status-sync contract: {$needle}\n");
        exit(1);
    }
}
if (str_contains($source, "'new_desk_task_ids' => \$dispatchTaskIds")) {
    fwrite(STDERR, "new_desk_task_ids must stay the new ERP desk ids, not dispatch_next_tasks ids.\n");
    exit(1);
}
$completeAt = strpos($source, 'function gz_bridge_complete_inquiry');
$libraryAt = strpos($source, "if (defined('GZ_BRIDGE_LIBRARY_ONLY'))", $completeAt ?: 0);
$completeBody = $completeAt !== false && $libraryAt !== false ? substr($source, $completeAt, $libraryAt - $completeAt) : '';
if ($completeBody === '' || !str_contains($completeBody, 'gz_bridge_new_desk_sync') || !str_contains($completeBody, 'new_desk_error')) {
    fwrite(STDERR, "Status sync does not return a new-desk error from the new ERP connection.\n");
    exit(1);
}
if (str_contains($completeBody, "['new_desk_tasks'] = 0")) {
    fwrite(STDERR, "complete_inquiry still swallows new-desk failures as new_desk_tasks=0.\n");
    exit(1);
}
$verifyAt = strpos($source, 'gz_bridge_verify($config, $body)');
$statusAt = strpos($source, "=== 'inquiry.status_changed'");
if ($verifyAt === false || $statusAt === false || $verifyAt > $statusAt) {
    fwrite(STDERR, "Status sync must stay behind the existing bridge signature check.\n");
    exit(1);
}

define('GZ_BRIDGE_LIBRARY_ONLY', true);
require dirname(__DIR__) . '/website_inquiry_staging_bridge.php';

function inquiry_sync_fail(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function inquiry_sync_same(mixed $actual, mixed $expected, string $label): void
{
    if ($actual !== $expected) {
        inquiry_sync_fail($label . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE) . ' got ' . json_encode($actual, JSON_UNESCAPED_UNICODE));
    }
}

if (gz_bridge_new_desk_ids(['new_desk_task_ids' => [501, '502', 0, -3, 501], 'dispatch_next_task_ids' => [504]]) !== [501, 502]) {
    inquiry_sync_fail('new_desk_task_ids must win and drop duplicates, zero, and non-ids.');
}
if (gz_bridge_new_desk_ids(['dispatch_next_task_ids' => [504], 'crm_task_ids' => [12]]) !== []) {
    inquiry_sync_fail('Old dispatch_next_tasks ids are not new desk ids.');
}
if (gz_bridge_new_desk_ids(['new_desk_task_ids' => '503, 504']) !== [503, 504]) {
    inquiry_sync_fail('A comma-separated new_desk_task_ids string should be accepted.');
}
if (gz_bridge_new_desk_marker_id("香港询盘ID：8803380\n", '香港询盘ID') !== 8803380) {
    inquiry_sync_fail('Marker parser must read the whole inquiry id.');
}
if (gz_bridge_new_desk_marker_id("香港询盘ID：8803380\n", '香港询盘ID') === 880338) {
    inquiry_sync_fail('Marker parser matched a longer inquiry id.');
}
if (gz_bridge_new_status_choice("enum('pending_accept','in_progress','cancelled')", true) !== null) {
    inquiry_sync_fail('A status enum without done must not be written as done.');
}
if (gz_bridge_new_status_choice("enum('pending_accept','in_progress','done','cancelled')", true) !== 'done') {
    inquiry_sync_fail('done must be selected when the desk status enum allows it.');
}
if (gz_bridge_new_status_choice("enum('pending_accept','Completed')", true) !== 'Completed') {
    inquiry_sync_fail('The canonical enum spelling must be kept.');
}
if (gz_bridge_new_status_choice('varchar(40)', false) !== 'in_progress') {
    inquiry_sync_fail('Reopen must use in_progress when status is free text.');
}
if (gz_bridge_new_status_kind('completed') !== 'done' || gz_bridge_new_status_kind('cancelled') !== 'cancelled' || gz_bridge_new_status_kind('pending_accept') !== 'open') {
    inquiry_sync_fail('Desk status kinds were not recognized.');
}

$linked = static function (int $staging, int $hk, string $system = 'website_inquiry'): array {
    return [
        'link_system' => $system,
        'link_ref' => 'staging:' . $staging . ':user:6',
        'description' => "香港询盘ID：{$hk}\n广州暂存ID：{$staging}\n",
    ];
};
if (!gz_bridge_new_desk_belongs($linked(900, 338), 900, 338, false)) {
    inquiry_sync_fail('Fallback lookup must match staging id and hk inquiry id.');
}
if (!gz_bridge_new_desk_belongs($linked(900, 338), 880321, 338, true)) {
    inquiry_sync_fail('Explicit desk ids must still match when only the hk inquiry id agrees.');
}
if (!gz_bridge_new_desk_belongs($linked(900, 338), 880321, 338, false)) {
    inquiry_sync_fail('Fallback lookup must use the hk inquiry id when staging id does not match link_ref.');
}
if (gz_bridge_new_desk_belongs($linked(111, 337), 900, 338, true)) {
    inquiry_sync_fail('An explicit id from another inquiry must be left alone.');
}
if (gz_bridge_new_desk_belongs($linked(8803380, 8803380), 900, 338, false)) {
    inquiry_sync_fail('Hong Kong id lookup must not match a longer id.');
}
if (gz_bridge_new_desk_belongs(['link_system' => '', 'link_ref' => '', 'description' => ''], 900, 338, true)) {
    inquiry_sync_fail('An unrelated personal task id must not be completed.');
}
if (gz_bridge_new_desk_belongs($linked(900, 338, 'PLM'), 900, 338, true)) {
    inquiry_sync_fail('A task from another module must not be completed.');
}
if (!gz_bridge_new_desk_belongs($linked(900, 338), 0, 0, true)) {
    inquiry_sync_fail('Explicit website inquiry ids are in scope when the payload has no other key.');
}

inquiry_sync_mysql();

echo "website inquiry status sync contract passed\n";

function inquiry_sync_mysql(): void
{
    $mysqld = '/usr/sbin/mysqld';
    if (!is_executable($mysqld)) {
        echo "new desk mysql integration skipped: mysqld is not installed\n";
        return;
    }
    $base = sys_get_temp_dir() . '/inquiry-new-desk-' . bin2hex(random_bytes(4));
    if (!mkdir($base, 0700, true) && !is_dir($base)) {
        inquiry_sync_fail('Unable to create the isolated MySQL directory.');
    }
    $data = $base . '/data';
    mkdir($data, 0700);
    $socket = $base . '/mysql.sock';
    $pidFile = $base . '/mysql.pid';
    $errorLog = $base . '/mysql.err';
    $init = proc_open(
        [$mysqld, '--no-defaults', '--initialize-insecure', '--datadir=' . $data, '--log-error=' . $errorLog],
        [1 => ['file', $base . '/init.out', 'w'], 2 => ['file', $base . '/init.err', 'w']],
        $pipes,
        $base
    );
    if (!is_resource($init) || proc_close($init) !== 0) {
        echo "new desk mysql integration skipped: initialize failed\n";
        inquiry_sync_cleanup_dir($base);
        return;
    }
    $server = proc_open(
        [
            $mysqld, '--no-defaults', '--datadir=' . $data, '--socket=' . $socket, '--pid-file=' . $pidFile,
            '--log-error=' . $errorLog, '--skip-networking', '--mysqlx=0', '--performance-schema=OFF',
            '--innodb-buffer-pool-size=32M',
        ],
        [1 => ['file', $base . '/server.out', 'w'], 2 => ['file', $base . '/server.err', 'w']],
        $serverPipes,
        $base
    );
    if (!is_resource($server)) {
        echo "new desk mysql integration skipped: server did not start\n";
        inquiry_sync_cleanup_dir($base);
        return;
    }
    $ready = false;
    $deadline = time() + 40;
    while (time() < $deadline) {
        if (file_exists($socket)) {
            $ready = true;
            break;
        }
        $status = proc_get_status($server);
        if (!$status['running']) break;
        usleep(200000);
    }
    if (!$ready) {
        proc_terminate($server);
        proc_close($server);
        echo "new desk mysql integration skipped: socket was not ready\n";
        inquiry_sync_cleanup_dir($base);
        return;
    }
    try {
        $root = new PDO('mysql:unix_socket=' . $socket . ';charset=utf8mb4', 'root', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $serverInfo = $root->query('SELECT @@skip_networking AS network_off, @@datadir AS data_path')->fetch();
        if ((int)($serverInfo['network_off'] ?? 0) !== 1 || !str_starts_with((string)$serverInfo['data_path'], $data)) {
            inquiry_sync_fail('Refused to run the desk sync test on a non-isolated MySQL server.');
        }
        $root->exec('CREATE DATABASE inquiry_status_sync');
        $root->exec('CREATE DATABASE new_artdon_erp');
        $root->exec('CREATE DATABASE new_desk_missing');
        $old = new PDO('mysql:unix_socket=' . $socket . ';dbname=inquiry_status_sync;charset=utf8mb4', 'root', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $new = new PDO('mysql:unix_socket=' . $socket . ';dbname=new_artdon_erp;charset=utf8mb4', 'root', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $missing = new PDO('mysql:unix_socket=' . $socket . ';dbname=new_desk_missing;charset=utf8mb4', 'root', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        try {
            gz_bridge_complete_inquiry($old, ['status' => 'archived', 'staging_id' => 1]);
            inquiry_sync_fail('Unknown inquiry status must be rejected.');
        } catch (InvalidArgumentException $e) {
            if (!str_contains($e->getMessage(), 'replied, closed, new, or processing')) {
                inquiry_sync_fail('Unexpected status error: ' . $e->getMessage());
            }
        }
        try {
            gz_bridge_complete_inquiry($old, ['status' => 'replied']);
            inquiry_sync_fail('Status sync without an inquiry id or desk ids must be rejected.');
        } catch (InvalidArgumentException $e) {
            if (!str_contains($e->getMessage(), 'Missing staging_id')) {
                inquiry_sync_fail('Unexpected missing-id error: ' . $e->getMessage());
            }
        }

        gz_bridge_migrate($old);
        $old->exec("CREATE TABLE crm_tasks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            status VARCHAR(40) NOT NULL,
            completed_at DATETIME NULL,
            result VARCHAR(120) NOT NULL DEFAULT '',
            source_type VARCHAR(60) NULL,
            source_id VARCHAR(80) NULL,
            deleted_at DATETIME NULL,
            updated_at DATETIME NULL
        ) ENGINE=InnoDB");
        $old->exec("CREATE TABLE dispatch_next_tasks (
            id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            status VARCHAR(40) NOT NULL,
            progress INT NOT NULL DEFAULT 0,
            linked_system VARCHAR(60) NULL,
            linked_table VARCHAR(80) NULL,
            linked_id VARCHAR(80) NULL,
            linked_json JSON NULL,
            is_deleted TINYINT NOT NULL DEFAULT 0,
            completed_at DATETIME NULL,
            updated_at DATETIME NULL
        ) ENGINE=InnoDB");
        $old->exec("INSERT INTO website_inquiry_staging (hk_inquiry_id, idempotency_key, payload_json, received_at) VALUES (880338, 'isolated-880338', '{}', NOW())");
        $stagingId = (int)$old->query('SELECT id FROM website_inquiry_staging WHERE hk_inquiry_id=880338')->fetchColumn();
        if ($stagingId <= 0) inquiry_sync_fail('Staging row was not created.');
        $old->exec("INSERT INTO website_inquiry_tasks (staging_id, hk_inquiry_id, title, status) VALUES ({$stagingId}, 880338, '旧暂存', 'pending')");
        $old->exec("INSERT INTO website_inquiry_tasks (staging_id, hk_inquiry_id, title, status) VALUES (99999, 880337, '另一张', 'pending')");
        $old->exec("INSERT INTO crm_tasks (status, result, source_type, source_id) VALUES ('pending', '', 'website_inquiry', 'staging:{$stagingId}:user:6'), ('pending', '', 'website_inquiry', 'staging:99999:user:6')");
        $insertDispatch = $old->prepare('INSERT INTO dispatch_next_tasks (id, status, linked_system, linked_table, linked_id) VALUES (?,?,?,?,?)');
        $insertDispatch->execute([701, 'pending_accept', 'website_inquiry', 'website_inquiry_staging', 'staging:' . $stagingId . ':user:6']);
        $insertDispatch->execute([702, 'pending_accept', 'website_inquiry', 'website_inquiry_staging', 'staging:99999:user:6']);

        $new->exec("CREATE TABLE desk_tasks (
            id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            status ENUM('pending_accept','in_progress','cancelled') NOT NULL,
            progress INT NOT NULL DEFAULT 0,
            link_system VARCHAR(60) NULL,
            link_ref VARCHAR(190) NULL,
            description TEXT NULL,
            deleted_at DATETIME(6) NULL,
            version INT NOT NULL DEFAULT 1,
            updated_at DATETIME(6) NULL
        ) ENGINE=InnoDB");
        $new->exec("CREATE TABLE desk_task_members (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            task_id BIGINT NOT NULL,
            user_id INT NOT NULL,
            status VARCHAR(40) NOT NULL,
            accepted_at DATETIME NULL,
            updated_at DATETIME(6) NULL
        ) ENGINE=InnoDB");
        $insertDesk = $new->prepare('INSERT INTO desk_tasks (id, status, progress, link_system, link_ref, description, deleted_at, version) VALUES (?,?,?,?,?,?,?,1)');
        $describe = static function (int $hk, int $staging): string {
            return "香港询盘ID：{$hk}\n广州暂存ID：{$staging}\n";
        };
        $insertDesk->execute([501, 'in_progress', 0, 'website_inquiry', 'staging:' . $stagingId . ':user:6', $describe(880338, $stagingId), null]);
        $insertDesk->execute([502, 'pending_accept', 0, 'website_inquiry', 'staging:' . $stagingId . ':user:7', $describe(880338, $stagingId), null]);
        $insertDesk->execute([504, 'pending_accept', 0, 'website_inquiry', 'staging:99999:user:6', $describe(880337, 99999), null]);
        $insertDesk->execute([505, 'pending_accept', 0, null, null, '个人待办', null]);
        $insertDesk->execute([506, 'cancelled', 0, 'website_inquiry', 'staging:' . $stagingId . ':user:8', $describe(880338, $stagingId), null]);
        $insertDesk->execute([507, 'in_progress', 0, 'website_inquiry', 'staging:' . $stagingId . ':user:9', $describe(880338, $stagingId), gmdate('Y-m-d H:i:s')]);
        $insertDesk->execute([508, 'pending_accept', 0, 'website_inquiry', 'staging:8803380:user:6', $describe(8803380, 8803380), null]);
        $new->exec("INSERT INTO desk_task_members (task_id, user_id, status) VALUES (501, 6, 'in_progress'), (502, 7, 'pending_accept')");

        unset($GLOBALS['gz_bridge_new_erp_override']);
        $disconnected = gz_bridge_complete_inquiry($old, [
            'status' => 'replied',
            'staging_id' => $stagingId,
            'hk_inquiry_id' => 880338,
            'new_desk_task_ids' => [501, 502],
        ]);
        inquiry_sync_same((int)$disconnected['new_desk_tasks'], 0, 'Missing new ERP config count');
        if (!str_contains((string)($disconnected['new_desk_error'] ?? ''), 'New ERP database config is missing')) {
            inquiry_sync_fail('Missing new ERP config was swallowed: ' . json_encode($disconnected, JSON_UNESCAPED_UNICODE));
        }
        inquiry_sync_same((int)$disconnected['website_tasks'], 1, 'Old staging task count');
        inquiry_sync_same((int)$disconnected['crm_tasks'], 1, 'Old CRM task count');
        inquiry_sync_same((int)$disconnected['dispatch_tasks'], 1, 'Old dispatch task count');
        inquiry_sync_same((string)$new->query('SELECT status FROM desk_tasks WHERE id=501')->fetchColumn(), 'in_progress', 'Desk task before connection');
        inquiry_sync_same((string)$old->query('SELECT status FROM website_inquiry_tasks WHERE staging_id=99999')->fetchColumn(), 'pending', 'Other staging task');
        inquiry_sync_same((string)$old->query('SELECT status FROM dispatch_next_tasks WHERE id=702')->fetchColumn(), 'pending_accept', 'Other dispatch task');

        $GLOBALS['gz_bridge_new_erp_override'] = $new;
        $enumBlocked = gz_bridge_complete_inquiry($old, [
            'status' => 'closed',
            'staging_id' => $stagingId,
            'hk_inquiry_id' => 880338,
            'new_desk_task_ids' => [501, 502],
        ]);
        inquiry_sync_same((int)$enumBlocked['new_desk_tasks'], 0, 'Enum without done count');
        if (!str_contains((string)($enumBlocked['new_desk_error'] ?? ''), 'no done value')) {
            inquiry_sync_fail('Status enum failure was swallowed: ' . json_encode($enumBlocked, JSON_UNESCAPED_UNICODE));
        }
        inquiry_sync_same((string)$new->query('SELECT status FROM desk_tasks WHERE id=501')->fetchColumn(), 'in_progress', 'Desk task after enum refusal');

        $new->exec("ALTER TABLE desk_tasks MODIFY status ENUM('pending_accept','in_progress','done','cancelled') NOT NULL");
        $legacyFailed = false;
        try {
            $new->prepare("UPDATE desk_tasks SET status='done', progress=100, completed_at=COALESCE(completed_at,UTC_TIMESTAMP(6)) WHERE id=?")->execute([501]);
        } catch (PDOException $e) {
            $legacyFailed = str_contains($e->getMessage(), 'completed_at');
        }
        if (!$legacyFailed) inquiry_sync_fail('The old complete SQL did not fail when completed_at is absent.');
        $memberLegacyFailed = false;
        try {
            $new->prepare("UPDATE desk_task_members SET status='done', progress=100, done_at=UTC_TIMESTAMP(6) WHERE task_id=?")->execute([501]);
        } catch (PDOException $e) {
            $memberLegacyFailed = str_contains($e->getMessage(), 'progress') || str_contains($e->getMessage(), 'done_at');
        }
        if (!$memberLegacyFailed) inquiry_sync_fail('The old member SQL did not fail when progress and done_at are absent.');
        inquiry_sync_same((string)$new->query('SELECT status FROM desk_tasks WHERE id=501')->fetchColumn(), 'in_progress', 'Legacy SQL must not commit a partial update');

        $done = gz_bridge_complete_inquiry($old, [
            'status' => 'Replied',
            'staging_id' => $stagingId,
            'local_inquiry_id' => 880338,
            'new_desk_task_ids' => [501, 502, 504, 505, 506, 507, 508],
            'dispatch_next_task_ids' => [504],
        ]);
        inquiry_sync_same((int)$done['new_desk_tasks'], 2, 'Replied desk count');
        inquiry_sync_same((int)$done['website_tasks'], 0, 'Already completed staging task');
        if (array_key_exists('new_desk_error', $done)) inquiry_sync_fail('Successful desk sync reported ' . $done['new_desk_error']);
        inquiry_sync_expect_desk($new, 501, 'done', 100);
        inquiry_sync_expect_desk($new, 502, 'done', 100);
        inquiry_sync_expect_desk($new, 504, 'pending_accept', 0);
        inquiry_sync_expect_desk($new, 505, 'pending_accept', 0);
        inquiry_sync_expect_desk($new, 506, 'cancelled', 0);
        inquiry_sync_expect_desk($new, 507, 'in_progress', 0);
        inquiry_sync_expect_desk($new, 508, 'pending_accept', 0);
        inquiry_sync_same((int)$new->query('SELECT version FROM desk_tasks WHERE id=501')->fetchColumn(), 2, 'Desk version');
        inquiry_sync_same((string)$new->query('SELECT status FROM desk_task_members WHERE task_id=501')->fetchColumn(), 'done', 'Member 501');
        inquiry_sync_same((string)$new->query('SELECT status FROM desk_task_members WHERE task_id=502')->fetchColumn(), 'done', 'Member 502');
        inquiry_sync_same((string)$old->query("SELECT status FROM crm_tasks WHERE source_id='staging:99999:user:6'")->fetchColumn(), 'pending', 'Other CRM task');

        $again = gz_bridge_complete_inquiry($old, [
            'status' => 'replied',
            'staging_id' => $stagingId,
            'hk_inquiry_id' => 880338,
            'new_desk_task_ids' => [501, 502],
        ]);
        inquiry_sync_same((int)$again['new_desk_tasks'], 0, 'Repeat replied count');

        $reopened = gz_bridge_complete_inquiry($old, [
            'status' => 'processing',
            'staging_id' => $stagingId,
            'hk_inquiry_id' => 880338,
            'new_desk_task_ids' => [501, 502, 504, 505, 506],
        ]);
        inquiry_sync_same((int)$reopened['new_desk_tasks'], 2, 'Reopen count');
        inquiry_sync_same((string)($reopened['inquiry_status'] ?? ''), 'processing', 'Reopen status');
        inquiry_sync_expect_desk($new, 501, 'in_progress', 0);
        inquiry_sync_expect_desk($new, 502, 'in_progress', 0);
        inquiry_sync_expect_desk($new, 504, 'pending_accept', 0);
        inquiry_sync_expect_desk($new, 506, 'cancelled', 0);
        inquiry_sync_same((string)$new->query('SELECT status FROM desk_task_members WHERE task_id=501')->fetchColumn(), 'in_progress', 'Reopened member');
        inquiry_sync_same((string)$old->query('SELECT status FROM website_inquiry_tasks WHERE hk_inquiry_id=880338')->fetchColumn(), 'done', 'Reopen rewrote the old staging task');
        inquiry_sync_same((string)$old->query('SELECT status FROM dispatch_next_tasks WHERE id=701')->fetchColumn(), 'done', 'Reopen rewrote the old dispatch task');

        $fallback = gz_bridge_complete_inquiry($old, [
            'status' => 'closed',
            'hk_inquiry_id' => 880338,
        ]);
        inquiry_sync_same((int)$fallback['new_desk_tasks'], 2, 'HK id fallback count');
        inquiry_sync_same((int)$fallback['staging_id'], $stagingId, 'HK id resolved staging id');
        inquiry_sync_expect_desk($new, 501, 'done', 100);
        inquiry_sync_expect_desk($new, 502, 'done', 100);
        inquiry_sync_expect_desk($new, 504, 'pending_accept', 0);
        inquiry_sync_expect_desk($new, 508, 'pending_accept', 0);

        $secondReopen = gz_bridge_complete_inquiry($old, [
            'status' => 'new',
            'new_desk_task_ids' => [501, 502],
        ]);
        inquiry_sync_same((int)$secondReopen['new_desk_tasks'], 2, 'Id-only reopen count');
        inquiry_sync_expect_desk($new, 504, 'pending_accept', 0);

        $ignoredDispatchIds = gz_bridge_complete_inquiry($old, [
            'status' => 'replied',
            'staging_id' => $stagingId,
            'hk_inquiry_id' => 880338,
            'dispatch_next_task_ids' => [504],
        ]);
        inquiry_sync_same((int)$ignoredDispatchIds['new_desk_tasks'], 2, 'Staging fallback count');
        inquiry_sync_expect_desk($new, 504, 'pending_accept', 0);

        $new->exec("UPDATE desk_tasks SET status='in_progress', progress=0 WHERE id=501");
        $new->exec("UPDATE desk_task_members SET status='in_progress' WHERE task_id=501");
        $wrongStaging = gz_bridge_complete_inquiry($old, [
            'status' => 'replied',
            'staging_id' => 880321,
            'hk_inquiry_id' => 880338,
            'new_desk_task_ids' => [501, 504, 505],
        ]);
        inquiry_sync_same((int)$wrongStaging['new_desk_tasks'], 1, 'Wrong staging id with matching hk id');
        inquiry_sync_expect_desk($new, 501, 'done', 100);
        inquiry_sync_expect_desk($new, 504, 'pending_accept', 0);
        inquiry_sync_expect_desk($new, 505, 'pending_accept', 0);
        inquiry_sync_same((string)$old->query('SELECT status FROM website_inquiry_tasks WHERE staging_id=99999')->fetchColumn(), 'pending', 'Wrong staging id touched another inquiry');

        $new->exec('ALTER TABLE desk_tasks ADD completed_at DATETIME(6) NULL');
        $new->exec("UPDATE desk_tasks SET status='in_progress', progress=40, completed_at=NULL WHERE id=502");
        $new->exec("UPDATE desk_task_members SET status='pending_accept' WHERE task_id=502");
        $stamped = gz_bridge_complete_inquiry($old, [
            'status' => 'closed',
            'staging_id' => $stagingId,
            'hk_inquiry_id' => 880338,
            'new_desk_task_ids' => [502],
        ]);
        inquiry_sync_same((int)$stamped['new_desk_tasks'], 1, 'Completed_at desk count');
        $stamp = $new->query('SELECT status, progress, completed_at FROM desk_tasks WHERE id=502')->fetch();
        if (($stamp['status'] ?? '') !== 'done' || (int)($stamp['progress'] ?? 0) !== 100 || ($stamp['completed_at'] ?? null) === null) {
            inquiry_sync_fail('completed_at was not filled when the column exists: ' . json_encode($stamp));
        }
        $cleared = gz_bridge_complete_inquiry($old, [
            'status' => 'processing',
            'new_desk_task_ids' => [502],
        ]);
        inquiry_sync_same((int)$cleared['new_desk_tasks'], 1, 'Clear completed_at count');
        $clearedRow = $new->query('SELECT status, progress, completed_at FROM desk_tasks WHERE id=502')->fetch();
        if (($clearedRow['status'] ?? '') !== 'in_progress' || (int)($clearedRow['progress'] ?? -1) !== 0 || $clearedRow['completed_at'] !== null) {
            inquiry_sync_fail('Reopen did not clear completed_at: ' . json_encode($clearedRow));
        }
        inquiry_sync_expect_desk($new, 501, 'done', 100);

        $GLOBALS['gz_bridge_new_erp_override'] = $missing;
        $missingTable = gz_bridge_complete_inquiry($old, [
            'status' => 'replied',
            'new_desk_task_ids' => [502],
        ]);
        inquiry_sync_same((int)$missingTable['new_desk_tasks'], 0, 'Missing desk table count');
        if (!isset($missingTable['new_desk_error']) || $missingTable['new_desk_error'] === '') {
            inquiry_sync_fail('Missing desk_tasks did not report an error.');
        }
        inquiry_sync_expect_desk($new, 502, 'in_progress', 0);
        echo "new desk mysql integration passed\n";
    } finally {
        unset($GLOBALS['gz_bridge_new_erp_override']);
        $pid = is_file($pidFile) ? (int)trim((string)file_get_contents($pidFile)) : 0;
        if ($pid > 1) {
            exec('kill -TERM ' . $pid . ' >/dev/null 2>&1');
            $waitUntil = time() + 8;
            while (time() < $waitUntil && is_dir('/proc/' . $pid)) usleep(100000);
            if (is_dir('/proc/' . $pid)) exec('kill -KILL ' . $pid . ' >/dev/null 2>&1');
        }
        if (is_resource($server)) {
            proc_terminate($server);
            proc_close($server);
        }
        inquiry_sync_cleanup_dir($base);
    }
}

function inquiry_sync_expect_desk(PDO $pdo, int $id, string $status, int $progress): void
{
    $row = $pdo->query('SELECT status, progress FROM desk_tasks WHERE id=' . $id)->fetch();
    if (!$row || $row['status'] !== $status || (int)$row['progress'] !== $progress) {
        inquiry_sync_fail('Desk task ' . $id . ' is ' . json_encode($row) . ', expected ' . $status . '/' . $progress);
    }
}

function inquiry_sync_cleanup_dir(string $base): void
{
    if (!is_dir($base)) return;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $path = $item->getPathname();
        if ($item->isDir()) @rmdir($path);
        else @unlink($path);
    }
    @rmdir($base);
}
