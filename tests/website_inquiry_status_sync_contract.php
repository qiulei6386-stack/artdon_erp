<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/website_inquiry_staging_bridge.php');
if (!is_string($source)) {
    fwrite(STDERR, "Unable to read bridge source.\n");
    exit(1);
}

$required = [
    "function gz_bridge_complete_inquiry",
    "function gz_bridge_sync_desk_tasks",
    "function gz_bridge_desk_transition",
    "function gz_bridge_explicit_desk_ids",
    "'inquiry.status_changed'",
    "Only replied, closed, new, or processing inquiry status can sync linked desk tasks.",
    "SET status='done', updated_at=NOW()",
    "SET status='done', completed_at=COALESCE(completed_at,NOW())",
    "SET status='done', progress=100, completed_at=NOW(), updated_at=NOW()",
    "SET status='in_progress', started_at=COALESCE(started_at,NOW()), completed_at=NULL, cancelled_at=NULL, updated_at=NOW()",
    "linked_system='website_inquiry'",
    "linked_table='website_inquiry_staging'",
    "status NOT IN ('done','cancelled')",
    "'new_desk_tasks' => 0",
    "\$summary['new_desk_tasks'] = \$deskUpdated",
    "'new_desk_task_ids' => \$dispatchTaskIds",
    "gz_bridge_verify(\$config, \$body)",
    "'log_action' => 'complete'",
    "'log_action' => 'cancel_complete'",
    'website_inquiry_desk:',
];

foreach ($required as $needle) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "Missing status-sync contract: {$needle}\n");
        exit(1);
    }
}

$findStart = strpos($source, 'function gz_bridge_find_desk_task_ids');
$findEnd = strpos($source, 'function gz_bridge_desk_log', $findStart ?: 0);
$findBody = $findStart !== false && $findEnd !== false ? substr($source, $findStart, $findEnd - $findStart) : '';
if ($findBody === '' || str_contains($findBody, '%\\"staging_id\\":')) {
    fwrite(STDERR, "Desk lookup still uses the escaped JSON LIKE pattern that cannot match linked_json.\n");
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

if (gz_bridge_explicit_desk_ids(['new_desk_task_ids' => [278, '279', 0, -3, 278], 'dispatch_next_task_ids' => [999]]) !== [278, 279]) {
    inquiry_sync_fail('new_desk_task_ids must win and drop duplicates, zero, and non-ids.');
}
if (gz_bridge_explicit_desk_ids(['dispatch_next_task_ids' => '280, 281']) !== [280, 281]) {
    inquiry_sync_fail('Empty new_desk_task_ids must fall through to dispatch_next_task_ids.');
}
if (gz_bridge_explicit_desk_ids(['crm_task_ids' => [12]]) !== []) {
    inquiry_sync_fail('CRM task ids are not desk task ids.');
}
if (gz_bridge_desk_transition('pending_accept', true) !== ['status' => 'done', 'progress' => 100, 'log_action' => 'complete']) {
    inquiry_sync_fail('Closing a desk task must use the desk complete status.');
}
if (gz_bridge_desk_transition('done', true) !== null || gz_bridge_desk_transition('cancelled', true) !== null) {
    inquiry_sync_fail('Closing must leave done and cancelled desk tasks untouched.');
}
if (gz_bridge_desk_transition('done', false) !== ['status' => 'in_progress', 'progress' => null, 'log_action' => 'cancel_complete']) {
    inquiry_sync_fail('Reopening must return the desk task to in_progress.');
}
if (gz_bridge_desk_transition('pending_accept', false) !== null || gz_bridge_desk_transition('cancelled', false) !== null) {
    inquiry_sync_fail('Reopening must not change tasks that are not done.');
}

$linked = static function (int $staging, int $hk, string $system = 'website_inquiry'): array {
    return [
        'linked_system' => $system,
        'linked_table' => $system === 'website_inquiry' ? 'website_inquiry_staging' : 'other',
        'linked_id' => 'staging:' . $staging . ':user:6',
        'linked_json' => json_encode(['staging_id' => $staging, 'hk_inquiry_id' => $hk], JSON_UNESCAPED_UNICODE),
    ];
};
if (!gz_bridge_desk_task_belongs($linked(880321, 880338), 880321, 880338, true)) {
    inquiry_sync_fail('A desk task linked to this inquiry must be updated.');
}
if (gz_bridge_desk_task_belongs($linked(880999, 880337), 880321, 880338, true)) {
    inquiry_sync_fail('An explicit id linked to another inquiry must be left alone.');
}
if (gz_bridge_desk_task_belongs(['linked_system' => '', 'linked_table' => '', 'linked_id' => '', 'linked_json' => ''], 880321, 880338, true)) {
    inquiry_sync_fail('An unrelated personal task id must not be completed.');
}
if (gz_bridge_desk_task_belongs($linked(880321, 880338, 'PLM'), 880321, 880338, true)) {
    inquiry_sync_fail('A task from another module must not be completed.');
}
if (!gz_bridge_desk_json_mentions('{"staging_id": 880321, "hk_inquiry_id": 880338}', 'staging_id', 880321)) {
    inquiry_sync_fail('MySQL JSON text with a space after the colon must still match.');
}
if (gz_bridge_desk_json_mentions('{"staging_id": 8803210}', 'staging_id', 880321)) {
    inquiry_sync_fail('Staging id lookup must not match a longer id.');
}

inquiry_sync_mysql();

echo "website inquiry status sync contract passed\n";

function inquiry_sync_mysql(): void
{
    $mysqld = '/usr/sbin/mysqld';
    if (!is_executable($mysqld)) {
        echo "desk mysql integration skipped: mysqld is not installed\n";
        return;
    }
    $base = sys_get_temp_dir() . '/inquiry-desk-mysql-' . bin2hex(random_bytes(4));
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
        echo "desk mysql integration skipped: initialize failed\n";
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
        echo "desk mysql integration skipped: server did not start\n";
        inquiry_sync_cleanup_dir($base);
        return;
    }
    $ready = false;
    $deadline = time() + 25;
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
        echo "desk mysql integration skipped: socket was not ready\n";
        inquiry_sync_cleanup_dir($base);
        return;
    }
    try {
        $pdo = new PDO('mysql:unix_socket=' . $socket . ';charset=utf8mb4', 'root', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $serverInfo = $pdo->query('SELECT @@skip_networking AS network_off, @@datadir AS data_path, @@socket AS socket_path')->fetch();
        if ((int)($serverInfo['network_off'] ?? 0) !== 1 || !str_starts_with((string)$serverInfo['data_path'], $data)) {
            inquiry_sync_fail('Refused to run the desk sync test on a non-isolated MySQL server.');
        }
        $pdo->exec('CREATE DATABASE inquiry_desk_sync');
        $pdo->exec('USE inquiry_desk_sync');
        try {
            gz_bridge_complete_inquiry($pdo, ['status' => 'archived', 'staging_id' => 1]);
            inquiry_sync_fail('Unknown inquiry status must be rejected.');
        } catch (InvalidArgumentException $e) {
            if (!str_contains($e->getMessage(), 'replied, closed, new, or processing')) {
                inquiry_sync_fail('Unexpected status error: ' . $e->getMessage());
            }
        }
        try {
            gz_bridge_complete_inquiry($pdo, ['status' => 'replied']);
            inquiry_sync_fail('Status sync without an inquiry id or desk ids must be rejected.');
        } catch (InvalidArgumentException $e) {
            if (!str_contains($e->getMessage(), 'Missing staging_id')) {
                inquiry_sync_fail('Unexpected missing-id error: ' . $e->getMessage());
            }
        }
        gz_bridge_migrate($pdo);
        $pdo->exec("CREATE TABLE crm_roles (id INT PRIMARY KEY, role_key VARCHAR(40)) ENGINE=InnoDB");
        $pdo->exec("CREATE TABLE crm_users (id INT PRIMARY KEY, username VARCHAR(80), email VARCHAR(190), status VARCHAR(20), role_id INT NULL, real_name VARCHAR(80) NULL, english_name VARCHAR(80) NULL) ENGINE=InnoDB");
        $pdo->exec("INSERT INTO crm_roles (id, role_key) VALUES (1, 'admin')");
        $pdo->exec("INSERT INTO crm_users (id, username, email, status, role_id, real_name) VALUES (1, 'admin', 'admin@example.test', 'active', 1, '管理员'), (6, 'sales', 'sales@example.test', 'active', 1, '业务')");
        $pdo->exec("CREATE TABLE crm_tasks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            status VARCHAR(40) NOT NULL,
            completed_at DATETIME NULL,
            result VARCHAR(120) NOT NULL DEFAULT '',
            source_type VARCHAR(60) NULL,
            source_id VARCHAR(80) NULL,
            deleted_at DATETIME NULL,
            updated_at DATETIME NULL
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TABLE dispatch_next_tasks (
            id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            status VARCHAR(40) NOT NULL,
            progress INT NOT NULL DEFAULT 0,
            created_by INT NOT NULL,
            assigned_to INT NOT NULL,
            parent_group_id BIGINT NULL,
            title VARCHAR(240) NOT NULL DEFAULT '',
            linked_system VARCHAR(60) NULL,
            linked_table VARCHAR(80) NULL,
            linked_id VARCHAR(80) NULL,
            linked_json JSON NULL,
            is_deleted TINYINT NOT NULL DEFAULT 0,
            completed_at DATETIME NULL,
            started_at DATETIME NULL,
            cancelled_at DATETIME NULL,
            updated_at DATETIME NULL
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TABLE dispatch_next_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            task_id BIGINT NULL,
            user_id INT NOT NULL,
            action_type VARCHAR(80) NOT NULL,
            field_name VARCHAR(80) NULL,
            old_value TEXT NULL,
            new_value TEXT NULL,
            note TEXT NULL,
            ip VARCHAR(80) NULL,
            user_agent VARCHAR(255) NULL,
            created_at DATETIME NULL
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TABLE dispatch_next_notifications (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            recipient_id INT NOT NULL,
            sender_id INT NULL,
            task_id BIGINT NULL,
            type ENUM('new_dispatch','urge','accepted','done','returned') NOT NULL DEFAULT 'new_dispatch',
            title VARCHAR(220) NOT NULL,
            message TEXT NULL,
            dedupe_key VARCHAR(190) NULL,
            created_at DATETIME NULL,
            UNIQUE KEY uq_notice_dedupe (dedupe_key)
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TABLE dispatch_next_groups (
            id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            total_count INT NOT NULL DEFAULT 0,
            done_count INT NOT NULL DEFAULT 0,
            status VARCHAR(40) NOT NULL DEFAULT 'active',
            updated_at DATETIME NULL
        ) ENGINE=InnoDB");

        $insertDesk = $pdo->prepare("INSERT INTO dispatch_next_tasks
            (id, status, progress, created_by, assigned_to, parent_group_id, title, linked_system, linked_table, linked_id, linked_json)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $deskRow = static function (int $id, string $status, int $staging, int $hk, int $assignee, ?int $group = null) use ($insertDesk): void {
            $insertDesk->execute([
                $id, $status, 0, 2, $assignee, $group, '官网询盘测试 ' . $id,
                'website_inquiry', 'website_inquiry_staging', 'staging:' . $staging . ':user:' . $assignee,
                json_encode(['staging_id' => $staging, 'hk_inquiry_id' => $hk], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        };
        $deskRow(880278, 'pending_accept', 880321, 880338, 6, 7);
        $deskRow(880279, 'accepted', 880321, 880338, 6, 7);
        $deskRow(880280, 'done', 880321, 880338, 6);
        $deskRow(880281, 'pending_accept', 880999, 880337, 6);
        $deskRow(880290, 'cancelled', 880321, 880338, 6);
        $insertDesk->execute([880282, 'pending_accept', 0, 2, 6, null, '个人待办', null, null, null, null]);
        $pdo->exec("INSERT INTO dispatch_next_groups (id, total_count, done_count, status) VALUES (7, 2, 0, 'active')");
        $pdo->exec("INSERT INTO website_inquiry_staging (hk_inquiry_id, idempotency_key, payload_json, received_at) VALUES (880338, 'isolated-880338', '{}', NOW())");
        $stagingId = (int)$pdo->query("SELECT id FROM website_inquiry_staging WHERE hk_inquiry_id=880338")->fetchColumn();
        $pdo->exec("INSERT INTO website_inquiry_tasks (staging_id, hk_inquiry_id, title, status) VALUES ({$stagingId}, 880338, '旧暂存', 'pending')");
        $pdo->exec("INSERT INTO website_inquiry_tasks (staging_id, hk_inquiry_id, title, status) VALUES (880999, 880337, '另一张', 'pending')");
        $pdo->exec("INSERT INTO crm_tasks (status, result, source_type, source_id) VALUES ('pending', '', 'website_inquiry', 'staging:{$stagingId}:user:6'), ('pending', '', 'website_inquiry', 'staging:880999:user:6')");

        $payload = [
            'status' => 'replied',
            'staging_id' => $stagingId,
            'hk_inquiry_id' => 880338,
            'new_desk_task_ids' => [880278, 880279, 880280, 880281, 880282, 880290],
            'dispatch_next_task_ids' => [880281],
            'crm_task_ids' => [1],
        ];
        $first = gz_bridge_complete_inquiry($pdo, $payload);
        if ((int)$first['new_desk_tasks'] !== 2 || (int)$first['dispatch_tasks'] !== 2) {
            inquiry_sync_fail('Replied sync updated ' . json_encode($first) . ', expected 2 desk tasks.');
        }
        if ((int)$first['website_tasks'] !== 1 || (int)$first['crm_tasks'] !== 1) {
            inquiry_sync_fail('Old staging or CRM sync count changed: ' . json_encode($first));
        }
        $done = $pdo->query("SELECT id, status, progress, completed_at IS NOT NULL AS has_completed FROM dispatch_next_tasks ORDER BY id")->fetchAll();
        $byId = [];
        foreach ($done as $row) $byId[(int)$row['id']] = $row;
        foreach ([880278, 880279] as $id) {
            if ($byId[$id]['status'] !== 'done' || (int)$byId[$id]['progress'] !== 100 || (int)$byId[$id]['has_completed'] !== 1) {
                inquiry_sync_fail('Desk task ' . $id . ' was not completed on the desk path.');
            }
        }
        if ($byId[880281]['status'] !== 'pending_accept' || $byId[880282]['status'] !== 'pending_accept' || $byId[880290]['status'] !== 'cancelled') {
            inquiry_sync_fail('Unrelated, personal, or cancelled desk tasks were modified.');
        }
        if ((int)$pdo->query("SELECT status='pending' FROM website_inquiry_tasks WHERE staging_id=880999")->fetchColumn() !== 1) {
            inquiry_sync_fail('Another inquiry staging task was modified.');
        }
        $logs = (int)$pdo->query("SELECT COUNT(*) FROM dispatch_next_logs WHERE action_type='complete' AND field_name='status' AND new_value='done'")->fetchColumn();
        if ($logs !== 2) inquiry_sync_fail('Desk completion did not write the complete status log.');
        $notes = (int)$pdo->query("SELECT COUNT(*) FROM dispatch_next_notifications WHERE type='done' AND title='派工已完成' AND recipient_id=2")->fetchColumn();
        if ($notes !== 2) inquiry_sync_fail('Desk completion did not notify the creator the way the desk UI does.');
        $group = $pdo->query('SELECT done_count, status FROM dispatch_next_groups WHERE id=7')->fetch();
        if ((int)$group['done_count'] !== 2 || $group['status'] !== 'done') {
            inquiry_sync_fail('Desk group counts were not refreshed.');
        }

        $second = gz_bridge_complete_inquiry($pdo, $payload);
        if ((int)$second['new_desk_tasks'] !== 0) {
            inquiry_sync_fail('Repeating replied must not update desk tasks again.');
        }

        $reopenPayload = $payload;
        $reopenPayload['status'] = 'processing';
        $reopenPayload['new_desk_task_ids'] = [880278, 880281, 880282];
        $reopened = gz_bridge_complete_inquiry($pdo, $reopenPayload);
        if ((int)$reopened['new_desk_tasks'] !== 1) {
            inquiry_sync_fail('Reopen updated ' . json_encode($reopened) . ', expected only the linked done task.');
        }
        $restored = $pdo->query("SELECT status, completed_at, progress FROM dispatch_next_tasks WHERE id=880278")->fetch();
        if ($restored['status'] !== 'in_progress' || $restored['completed_at'] !== null || (int)$restored['progress'] !== 100) {
            inquiry_sync_fail('Reopen did not use the desk in_progress transition.');
        }
        if ((int)$pdo->query("SELECT COUNT(*) FROM dispatch_next_logs WHERE task_id=880278 AND action_type='cancel_complete' AND new_value='in_progress'")->fetchColumn() !== 1) {
            inquiry_sync_fail('Reopen did not write cancel_complete.');
        }
        if ($pdo->query("SELECT status FROM dispatch_next_tasks WHERE id=880279")->fetchColumn() !== 'done') {
            inquiry_sync_fail('Reopen changed a desk task that was not in the id list.');
        }
        if ($pdo->query("SELECT status FROM website_inquiry_tasks WHERE hk_inquiry_id=880338")->fetchColumn() !== 'done') {
            inquiry_sync_fail('Reopen must not rewrite the old staging task.');
        }
        $againPayload = $payload;
        $againPayload['new_desk_task_ids'] = [880278];
        $again = gz_bridge_complete_inquiry($pdo, $againPayload);
        if ((int)$again['new_desk_tasks'] !== 1) {
            inquiry_sync_fail('Completing again after reopen failed: ' . json_encode($again));
        }
        if ((int)$pdo->query("SELECT COUNT(*) FROM dispatch_next_notifications WHERE dedupe_key='website_inquiry_desk:done:880278:2'")->fetchColumn() !== 1) {
            inquiry_sync_fail('Repeating completion duplicated the desk notification.');
        }

        $pdo->exec("UPDATE dispatch_next_tasks SET status='pending_accept', progress=0, completed_at=NULL WHERE id IN (880278, 880279)");
        $pdo->exec("DELETE FROM dispatch_next_logs");
        $fallback = gz_bridge_complete_inquiry($pdo, [
            'status' => 'closed',
            'hk_inquiry_id' => 880338,
        ]);
        if ((int)$fallback['new_desk_tasks'] !== 2 || (int)$fallback['staging_id'] !== $stagingId) {
            inquiry_sync_fail('Fallback lookup by hk inquiry id updated ' . json_encode($fallback));
        }
        if ($pdo->query("SELECT status FROM dispatch_next_tasks WHERE id=880281")->fetchColumn() !== 'pending_accept') {
            inquiry_sync_fail('Fallback lookup completed a desk task from another inquiry.');
        }
        echo "desk mysql integration passed\n";
    } finally {
        $pid = is_file($pidFile) ? (int)trim((string)file_get_contents($pidFile)) : 0;
        if ($pid > 0 && function_exists('posix_kill')) {
            posix_kill($pid, 15);
            $waitUntil = time() + 8;
            while (time() < $waitUntil && posix_kill($pid, 0)) usleep(100000);
            if (posix_kill($pid, 0)) posix_kill($pid, 9);
        } else {
            proc_terminate($server);
        }
        proc_close($server);
        inquiry_sync_cleanup_dir($base);
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
