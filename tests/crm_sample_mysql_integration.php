<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Opt-in, disposable-instance test. Requires PHP 8.1+, pdo_mysql and pcntl.
 * Root must supply an EMPTY schema; this test never creates/drops a database.
 * CRM_PHASE1_MYSQL_TEST=1
 * CRM_PHASE1_MYSQL_SOCKET=/tmp/crm-phase1-mysql-<unique>/mysql.sock
 * CRM_PHASE1_MYSQL_SCHEMA=crm_phase1_sample_<6_to_32_lowercase_or_digits>
 * CRM_PHASE1_MYSQL_USER=root         (optional)
 * CRM_PHASE1_MYSQL_PASSWORD=         (optional)
 * The socket directory must contain .crm-phase1-mysql with exactly
 * isolated-crm-phase1-mysql-v1. The server must use that directory's data/
 * and have skip_networking=1. All fixtures remain for inspection afterwards.
 * PROCESS visibility is needed to observe both actual named-lock waiters.
 * No application bootstrap/config/ensure, SMTP, or real notification is loaded.
 */

function sample_mysql_assert(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

function sample_mysql_configuration(): array
{
    sample_mysql_assert(getenv('CRM_PHASE1_MYSQL_TEST') === '1', 'Explicit CRM_PHASE1_MYSQL_TEST=1 is required');
    sample_mysql_assert(!getenv('CRM_PHASE1_MYSQL_DSN'), 'DSN overrides/network connections are not accepted');
    sample_mysql_assert(class_exists('PDO') && in_array('mysql', PDO::getAvailableDrivers(), true), 'pdo_mysql is required');
    sample_mysql_assert(function_exists('pcntl_fork') && function_exists('pcntl_waitpid') && function_exists('pcntl_alarm'), 'pcntl is required');
    sample_mysql_assert(function_exists('stream_socket_pair'), 'Unix stream_socket_pair is required');
    $socket = (string)getenv('CRM_PHASE1_MYSQL_SOCKET');
    $schema = (string)getenv('CRM_PHASE1_MYSQL_SCHEMA');
    sample_mysql_assert((bool)preg_match('/^crm_phase1_sample_[a-z0-9]{6,32}$/D', $schema), 'Only a dedicated crm_phase1_sample_* test schema is allowed');
    sample_mysql_assert($socket !== '' && $socket[0] === '/' && basename($socket) === 'mysql.sock' && strpos($socket, ';') === false, 'An absolute dedicated mysql.sock is required');
    $directory = realpath(dirname($socket));
    sample_mysql_assert($directory !== false && in_array(dirname($directory), ['/tmp', '/private/tmp'], true), 'Socket directory must be a dedicated temporary directory');
    sample_mysql_assert((bool)preg_match('/^crm-phase1-mysql-[0-9]{8}-[A-Za-z0-9_-]{6,64}$/D', basename($directory)), 'Socket directory is not a phase1 disposable instance');
    $socket = $directory . '/mysql.sock';
    sample_mysql_assert(file_exists($socket) && filetype($socket) === 'socket' && !is_link($socket), 'Dedicated MySQL socket is unavailable');
    $marker = $directory . '/.crm-phase1-mysql';
    sample_mysql_assert(is_file($marker) && !is_link($marker) && trim((string)file_get_contents($marker)) === 'isolated-crm-phase1-mysql-v1', 'Disposable instance marker is missing or invalid');
    $dataDirectory = realpath($directory . '/data');
    sample_mysql_assert($dataDirectory === $directory . '/data', 'Dedicated data directory must not resolve outside the instance');
    return ['socket' => $socket, 'schema' => $schema, 'directory' => $directory, 'data_directory' => $dataDirectory,
        'user' => getenv('CRM_PHASE1_MYSQL_USER') === false ? 'root' : (string)getenv('CRM_PHASE1_MYSQL_USER'),
        'password' => getenv('CRM_PHASE1_MYSQL_PASSWORD') === false ? '' : (string)getenv('CRM_PHASE1_MYSQL_PASSWORD')];
}

$sampleMysqlConfig = [];
$sampleMysqlPdo = null;
$sampleMysqlFailTimeline = false;
$sampleMysqlNotifications = [];
$sampleMysqlNotificationInsideTransaction = 0;

function db(): PDO
{
    if ($GLOBALS['sampleMysqlPdo'] instanceof PDO) return $GLOBALS['sampleMysqlPdo'];
    $c = $GLOBALS['sampleMysqlConfig'];
    sample_mysql_assert(!empty($c['socket']) && !empty($c['schema']), 'Test configuration was not validated');
    $pdo = new PDO('mysql:unix_socket=' . $c['socket'] . ';dbname=' . $c['schema'] . ';charset=utf8mb4', $c['user'], $c['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 5,
        PDO::ATTR_PERSISTENT => false, PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
    ]);
    $server = $pdo->query('SELECT @@socket AS socket, @@datadir AS datadir, @@skip_networking AS skip_networking, DATABASE() AS schema_name')->fetch();
    $serverSocket = (string)($server['socket'] ?? '');
    sample_mysql_assert($serverSocket !== '' && $serverSocket[0] === '/' && realpath(dirname($serverSocket)) === $c['directory'] && basename($serverSocket) === 'mysql.sock', 'Connected server socket is not the dedicated test socket');
    sample_mysql_assert(realpath(rtrim((string)($server['datadir'] ?? ''), '/')) === $c['data_directory'], 'Connected server data directory is not disposable');
    sample_mysql_assert((int)($server['skip_networking'] ?? 0) === 1 && ($server['schema_name'] ?? '') === $c['schema'], 'Connected server/schema failed isolation checks');
    $pdo->exec('SET SESSION innodb_lock_wait_timeout=5');
    $pdo->exec('SET SESSION lock_wait_timeout=5');
    $pdo->exec('SET SESSION MAX_EXECUTION_TIME=5000');
    $GLOBALS['sampleMysqlPdo'] = $pdo;
    return $pdo;
}

function sample_mysql_load_functions(): void
{
    $source = file_get_contents(dirname(__DIR__) . '/crm_task_center.php');
    sample_mysql_assert(is_string($source), 'Original sample source is unavailable');
    $names = ['crm_sample_shipment_save', 'crm_sample_submission_key', 'crm_sample_payload', 'crm_sample_create_task',
        'crm_sample_insert_row', 'crm_sample_create_followup_task', 'crm_sample_create_signed_followup',
        'crm_sample_notify_followup_task', 'crm_task_datetime', 'crm_task_date'];
    foreach ($names as $name) {
        sample_mysql_assert((bool)preg_match('/^function ' . preg_quote($name, '/') . '\(/m', $source, $match, PREG_OFFSET_CAPTURE), 'Required original function missing');
        $start = $match[0][1];
        $end = strpos($source, "\nfunction ", $start + 1);
        eval(substr($source, $start, $end === false ? null : $end - $start));
    }
}

function sample_mysql_create_fixture_tables(): void
{
    $pdo = db();
    foreach (['TABLES', 'ROUTINES', 'TRIGGERS', 'EVENTS'] as $kind) {
        $schemaColumn = ['TABLES' => 'TABLE_SCHEMA', 'ROUTINES' => 'ROUTINE_SCHEMA', 'TRIGGERS' => 'TRIGGER_SCHEMA', 'EVENTS' => 'EVENT_SCHEMA'][$kind];
        $check = $pdo->prepare('SELECT COUNT(*) FROM information_schema.' . $kind . ' WHERE ' . $schemaColumn . '=?');
        $check->execute([$GLOBALS['sampleMysqlConfig']['schema']]);
        sample_mysql_assert((int)$check->fetchColumn() === 0, 'Test schema is not empty; refusing to touch existing objects');
    }
    // Real InnoDB tables with all columns used by the extracted INSERTs.
    $pdo->exec("CREATE TABLE crm_tasks (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        task_type VARCHAR(60) NOT NULL, title VARCHAR(255) NOT NULL, description TEXT NULL,
        source_type VARCHAR(60) NOT NULL DEFAULT '', source_id VARCHAR(80) NOT NULL DEFAULT '',
        customer_id INT UNSIGNED NULL, contact_id INT UNSIGNED NULL, opportunity_id INT UNSIGNED NULL,
        quote_id VARCHAR(80) NULL, assigned_user_id INT UNSIGNED NULL, collaborator_user_ids_json JSON NULL,
        priority VARCHAR(30) NOT NULL, status VARCHAR(40) NOT NULL, due_at DATETIME NULL, reminder_at DATETIME NULL,
        request_token VARCHAR(100) NULL, created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL, deleted_at DATETIME NULL,
        UNIQUE KEY uk_task_request (created_by, request_token)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE crm_sample_shipments (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, task_id BIGINT UNSIGNED NULL,
        customer_id INT UNSIGNED NOT NULL, contact_id INT UNSIGNED NULL, opportunity_id INT UNSIGNED NULL,
        quote_id VARCHAR(80) NULL, sample_name VARCHAR(255) NOT NULL, product_model VARCHAR(160) NOT NULL DEFAULT '',
        customer_model VARCHAR(160) NOT NULL DEFAULT '', product_category VARCHAR(120) NOT NULL DEFAULT '',
        quantity DECIMAL(12,2) NOT NULL DEFAULT 1.00, unit VARCHAR(30) NOT NULL DEFAULT 'pcs',
        color VARCHAR(80) NOT NULL DEFAULT '', power VARCHAR(80) NOT NULL DEFAULT '', cct VARCHAR(80) NOT NULL DEFAULT '',
        cri VARCHAR(80) NOT NULL DEFAULT '', beam_angle VARCHAR(80) NOT NULL DEFAULT '', is_custom TINYINT NOT NULL DEFAULT 0,
        recipient_name VARCHAR(160) NOT NULL DEFAULT '', recipient_phone VARCHAR(120) NOT NULL DEFAULT '',
        recipient_email VARCHAR(160) NOT NULL DEFAULT '', recipient_whatsapp VARCHAR(120) NOT NULL DEFAULT '',
        country VARCHAR(120) NOT NULL DEFAULT '', city VARCHAR(120) NOT NULL DEFAULT '', address VARCHAR(500) NOT NULL DEFAULT '',
        postal_code VARCHAR(80) NOT NULL DEFAULT '', courier_company VARCHAR(80) NOT NULL DEFAULT '', tracking_no VARCHAR(160) NOT NULL DEFAULT '',
        shipping_date DATE NULL, expected_arrival_date DATE NULL, freight_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        currency VARCHAR(20) NOT NULL DEFAULT 'USD', freight_payer VARCHAR(80) NOT NULL DEFAULT '', sender_user_id INT UNSIGNED NULL,
        status VARCHAR(40) NOT NULL DEFAULT 'preparing', owner_user_id INT UNSIGNED NULL, followup_time DATETIME NULL,
        remind_customer_sign TINYINT NOT NULL DEFAULT 0, remind_owner_follow TINYINT NOT NULL DEFAULT 1,
        create_followup_task TINYINT NOT NULL DEFAULT 0, create_dispatch_task TINYINT NOT NULL DEFAULT 0,
        feedback_note TEXT NULL, remark TEXT NULL, created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL, deleted_at DATETIME NULL, KEY idx_sample_task (task_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function current_user(): array { return ['id' => 7]; }
function crm_require(string $permission): void
{
    sample_mysql_assert(in_array($permission, ['sample.create', 'sample.edit', 'sample.view'], true), 'Unexpected permission path');
}
function crm_task_center_ensure_tables(): void { sample_mysql_assert(!db()->inTransaction(), 'Task schema ensure attempted inside transaction'); }
function crm_customer_ensure_tables(): void { sample_mysql_assert(!db()->inTransaction(), 'Customer schema ensure attempted inside transaction'); }
function crm_ensure_tables(): void { sample_mysql_assert(!db()->inTransaction(), 'Log schema ensure attempted inside transaction'); }
function crm_sample_shipment_detail(int $id): array
{
    crm_require('sample.view');
    $stmt = db()->prepare('SELECT * FROM crm_sample_shipments WHERE id=? AND created_by=? AND deleted_at IS NULL');
    $stmt->execute([$id, 7]);
    $row = $stmt->fetch();
    sample_mysql_assert((bool)$row, 'Fixture shipment unavailable');
    return ['shipment' => $row, 'files' => [], 'logs' => [], 'followups' => []];
}
function crm_sample_update_row(int $id, array $data): void { throw new RuntimeException('New submission unexpectedly overwrote an existing shipment'); }
function crm_log_event(...$args): void {}
function crm_customer_timeline_add(...$args): void
{
    if ($GLOBALS['sampleMysqlFailTimeline']) throw new RuntimeException('Injected timeline failure');
}
function crm_sample_dispatch_placeholder(...$args): void { throw new RuntimeException('Dispatch is outside this isolated test'); }
function notification_create_task_assigned(array $task): void
{
    if (db()->inTransaction()) $GLOBALS['sampleMysqlNotificationInsideTransaction']++;
    sample_mysql_assert(!db()->inTransaction(), 'Notification invoked before sample commit');
    $GLOBALS['sampleMysqlNotifications'][] = (int)$task['id'];
}

function sample_mysql_count(string $table): int
{
    sample_mysql_assert(in_array($table, ['crm_tasks', 'crm_sample_shipments'], true), 'Unexpected count table');
    return (int)db()->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
}
function sample_mysql_line($stream): string
{
    $line = fgets($stream);
    sample_mysql_assert($line !== false, 'Concurrent worker IPC timed out or closed');
    return trim($line);
}

function sample_mysql_concurrent_submission(array $input): array
{
    // Never fork with a live MySQL connection: each process gets its own PDO.
    $GLOBALS['sampleMysqlPdo'] = null;
    $pairs = [];
    $pids = [];
    $results = [];
    $operationFailure = null;
    $allChildrenSucceeded = true;
    for ($i = 0; $i < 2; $i++) {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        sample_mysql_assert($pair !== false, 'Cannot create bounded worker IPC');
        foreach ($pair as $stream) stream_set_timeout($stream, 8);
        $pairs[] = $pair;
    }
    try {
        for ($i = 0; $i < 2; $i++) {
            $pid = pcntl_fork();
            sample_mysql_assert($pid !== -1, 'Cannot fork concurrent worker');
            if ($pid === 0) {
                pcntl_alarm(20);
                foreach ($pairs as $j => $pair) foreach ($pair as $side => $stream) {
                    if (($j !== $i || $side !== 1) && is_resource($stream)) fclose($stream);
                }
                $pipe = $pairs[$i][1];
                try {
                    $connectionId = (int)db()->query('SELECT CONNECTION_ID()')->fetchColumn();
                    fwrite($pipe, 'READY ' . $connectionId . "\n");
                    sample_mysql_assert(sample_mysql_line($pipe) === 'GO', 'Worker start barrier failed');
                    $detail = crm_sample_shipment_save($input);
                    fwrite($pipe, json_encode(['ok' => true, 'id' => (int)$detail['shipment']['id'], 'replay' => !empty($detail['idempotent_replay'])]) . "\n");
                    $GLOBALS['sampleMysqlPdo'] = null;
                    fclose($pipe);
                    exit(0);
                } catch (Throwable $e) {
                    $error = $e instanceof PDOException ? 'SQLSTATE ' . $e->getCode() : $e->getMessage();
                    fwrite($pipe, json_encode(['ok' => false, 'error' => $error]) . "\n");
                    $GLOBALS['sampleMysqlPdo'] = null;
                    fclose($pipe);
                    exit(1);
                }
            }
            $pids[] = $pid;
            fclose($pairs[$i][1]);
        }
        $connectionIds = [];
        foreach ($pairs as $pair) {
            $ready = sample_mysql_line($pair[0]);
            sample_mysql_assert((bool)preg_match('/^READY ([0-9]+)$/D', $ready, $match), 'Worker did not establish its isolated connection');
            $connectionIds[] = (int)$match[1];
        }
        sample_mysql_assert(count(array_unique($connectionIds)) === 2, 'Workers must use different database sessions');
        $data = crm_sample_payload($input, 'preparing', '', (int)$input['customer_id'], trim($input['sample_name']));
        $key = crm_sample_submission_key($input['request_token'], $data);
        $lockName = 'crm_sample:' . substr(hash('sha256', '7:' . substr($key, 0, 35)), 0, 52);
        $hold = db()->prepare('SELECT GET_LOCK(?, 2)');
        $hold->execute([$lockName]);
        sample_mysql_assert((int)$hold->fetchColumn() === 1, 'Could not establish concurrency barrier');
        try {
            foreach ($pairs as $pair) fwrite($pair[0], "GO\n");
            // Confirm both actual MySQL sessions are waiting on the named lock,
            // rather than merely making two sequential calls look concurrent.
            $waiting = db()->prepare("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID IN (?,?) AND LOWER(STATE) LIKE '%lock%'");
            $bothWaiting = false;
            $deadline = microtime(true) + 2.0;
            do {
                $waiting->execute($connectionIds);
                if ((int)$waiting->fetchColumn() === 2) { $bothWaiting = true; break; }
                usleep(25000);
            } while (microtime(true) < $deadline);
            sample_mysql_assert($bothWaiting, 'Both workers were not observed waiting on the real MySQL lock');
        } finally {
            db()->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
        }
        foreach ($pairs as $pair) {
            $result = json_decode(sample_mysql_line($pair[0]), true);
            sample_mysql_assert(is_array($result) && !empty($result['ok']), 'Concurrent original-function save failed: ' . (string)($result['error'] ?? 'invalid protocol'));
            $results[] = $result;
        }
    } catch (Throwable $e) {
        $operationFailure = $e;
    } finally {
        foreach ($pairs as $pair) foreach ($pair as $stream) if (is_resource($stream)) fclose($stream);
        // Children have a 20-second alarm even when the parent encounters an
        // assertion failure. Reap them; do not leave background test writers.
        $deadline = microtime(true) + 22.0;
        foreach ($pids as $pid) {
            $status = 0;
            do {
                $waited = pcntl_waitpid($pid, $status, WNOHANG);
                if ($waited !== 0) break;
                usleep(10000);
            } while (microtime(true) < $deadline);
            $allChildrenSucceeded = ($waited === $pid && pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0) && $allChildrenSucceeded;
        }
    }
    if ($operationFailure !== null) throw $operationFailure;
    sample_mysql_assert($allChildrenSucceeded, 'Concurrent worker exited unsuccessfully');
    return $results;
}

try {
    $sampleMysqlConfig = sample_mysql_configuration();
    sample_mysql_load_functions();
    sample_mysql_create_fixture_tables();
    $base = ['customer_id' => 42, 'sample_name' => 'Disposable integration sample', 'product_model' => 'FIXTURE-M1', 'quantity' => '2', 'request_token' => 'phase1-sample-concurrent-0001'];
    $results = sample_mysql_concurrent_submission($base);
    sample_mysql_assert($results[0]['id'] === $results[1]['id'] && (int)$results[0]['replay'] + (int)$results[1]['replay'] === 1, 'Concurrent requests did not resolve to one original and one replay');
    sample_mysql_assert(sample_mysql_count('crm_sample_shipments') === 1 && sample_mysql_count('crm_tasks') === 1, 'Concurrent same-token requests created duplicate/orphan rows');

    $before = sample_mysql_count('crm_sample_shipments');
    $a = crm_sample_shipment_save(array_merge($base, ['request_token' => 'phase1-sample-distinct-0001']));
    $b = crm_sample_shipment_save(array_merge($base, ['request_token' => 'phase1-sample-distinct-0002']));
    sample_mysql_assert((int)$a['shipment']['id'] !== (int)$b['shipment']['id'] && sample_mysql_count('crm_sample_shipments') === $before + 2, 'Different form tokens did not preserve two identical shipments');

    $conflictRejected = false;
    try { crm_sample_shipment_save(array_merge($base, ['quantity' => '3'])); }
    catch (RuntimeException $e) { $conflictRejected = strpos($e->getMessage(), '不同的样品内容') !== false; }
    sample_mysql_assert($conflictRejected && sample_mysql_count('crm_sample_shipments') === 3, 'Same token with different payload was not rejected safely');
    $original = crm_sample_shipment_detail($results[0]['id']);
    sample_mysql_assert((float)$original['shipment']['quantity'] === 2.0, 'Conflicting replay changed the original row');

    $tasksBefore = sample_mysql_count('crm_tasks');
    $shipmentsBefore = sample_mysql_count('crm_sample_shipments');
    $rollbackInput = array_merge($base, ['request_token' => 'phase1-sample-rollback-0001']);
    $sampleMysqlFailTimeline = true;
    $rolledBack = false;
    try { crm_sample_shipment_save($rollbackInput); }
    catch (RuntimeException $e) { $rolledBack = $e->getMessage() === 'Injected timeline failure'; }
    $sampleMysqlFailTimeline = false;
    sample_mysql_assert($rolledBack && !db()->inTransaction() && sample_mysql_count('crm_tasks') === $tasksBefore && sample_mysql_count('crm_sample_shipments') === $shipmentsBefore, 'Real transaction did not roll back task and shipment together');
    crm_sample_shipment_save($rollbackInput);
    sample_mysql_assert(sample_mysql_count('crm_tasks') === $tasksBefore + 1 && sample_mysql_count('crm_sample_shipments') === $shipmentsBefore + 1, 'Retry after rollback did not succeed once');

    $notificationInput = array_merge($base, ['request_token' => 'phase1-sample-notify-0001', 'create_followup_task' => '1', 'followup_time' => '2026-09-09T10:00', 'status' => 'signed', 'tracking_no' => 'FIXTURE-TRACK']);
    $tasksBefore = sample_mysql_count('crm_tasks');
    $saved = crm_sample_shipment_save($notificationInput);
    sample_mysql_assert(!db()->inTransaction() && count($sampleMysqlNotifications) === 1 && $sampleMysqlNotificationInsideTransaction === 0, 'Notification was not deferred until after the real commit');
    sample_mysql_assert(sample_mysql_count('crm_tasks') === $tasksBefore + 2, 'Shared followup and signed paths must create just one followup task');
    $replay = crm_sample_shipment_save($notificationInput);
    sample_mysql_assert((int)$replay['shipment']['id'] === (int)$saved['shipment']['id'] && !empty($replay['idempotent_replay']) && count($sampleMysqlNotifications) === 1, 'Replay repeated notification or shipment');
    echo "crm_sample_mysql_integration: OK (real concurrent lock, token identity, rollback, post-commit notification; isolated fixtures retained)\n";
    $sampleMysqlPdo = null;
} catch (Throwable $e) {
    $message = $e instanceof PDOException ? 'Database operation failed, SQLSTATE ' . $e->getCode() : $e->getMessage();
    fwrite(STDERR, 'crm_sample_mysql_integration: FAIL: ' . $message . "\n");
    $sampleMysqlPdo = null;
    exit(1);
}
