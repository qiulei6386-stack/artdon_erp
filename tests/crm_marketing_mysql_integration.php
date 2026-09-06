<?php
declare(strict_types=1);
/**
 * Opt-in REAL MySQL integration test. No application bootstrap, SMTP or notifications.
 *
 * Required isolated instance (created/started separately by the operator):
 *   /tmp/crm-phase1-mysql-YYYYMMDD-<random>/mysql.sock
 *   /tmp/crm-phase1-mysql-YYYYMMDD-<random>/data/
 *   .crm-phase1-mysql containing: isolated-crm-phase1-mysql-v1
 *   mysqld --skip-networking; an EMPTY crm_phase1_marketing_<random> schema.
 *
 * Environment:
 *   CRM_PHASE1_MYSQL_TEST=1
 *   CRM_PHASE1_MYSQL_SOCKET=/tmp/crm-phase1-mysql-20260906-example123/mysql.sock
 *   CRM_PHASE1_MYSQL_SCHEMA=crm_phase1_marketing_example123
 *   CRM_PHASE1_MYSQL_USER=root
 *   CRM_PHASE1_MYSQL_PASSWORD=                  (isolated credentials only)
 *   CRM_PHASE1_MYSQL_PHP_EXTENSIONS_JSON=["pdo_mysql"]
 * Last setting supplies child-process "-n -d extension=..." arguments. Use
 * ["pdo","pdo_mysql"] if PDO itself is shared, or validated absolute .so paths.
 *
 * Requires PHP 7.4+/PDO_mysql/proc_open and read access to innodb_trx (PROCESS).
 * Creates 8 minimal InnoDB tables; never CREATE/DROP DATABASE, starts no server,
 * accepts no network DSN, refuses a nonempty schema. Leaves synthetic fixtures
 * and its run marker for inspection. Use a NEW empty schema for each run.
 */

function mit_assert(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

function mit_config(): array
{
    mit_assert(PHP_SAPI === 'cli', 'CLI only');
    mit_assert(getenv('CRM_PHASE1_MYSQL_TEST') === '1', 'Refused: CRM_PHASE1_MYSQL_TEST=1 is required');
    mit_assert(!getenv('CRM_PHASE1_MYSQL_DSN'), 'Refused: DSN overrides/network connections are not accepted');
    mit_assert(extension_loaded('pdo_mysql') && class_exists('PDO'), 'PDO_mysql is required');
    mit_assert(function_exists('proc_open'), 'proc_open is required for genuine separate-connection races');
    $socket = (string)getenv('CRM_PHASE1_MYSQL_SOCKET');
    $schema = (string)getenv('CRM_PHASE1_MYSQL_SCHEMA');
    mit_assert((bool)preg_match('/^crm_phase1_marketing_[a-z0-9]{6,32}$/D', $schema), 'Refused: invalid dedicated test schema name');
    mit_assert($socket !== '' && $socket[0] === '/' && basename($socket) === 'mysql.sock', 'Refused: explicit local mysql.sock path required');
    $directory = realpath(dirname($socket));
    $temporaryRoot = realpath(sys_get_temp_dir());
    mit_assert($directory !== false && $temporaryRoot !== false && dirname($directory) === $temporaryRoot, 'Refused: instance must be an immediate child of the system temporary directory');
    mit_assert((bool)preg_match('/^crm-phase1-mysql-[0-9]{8}-[A-Za-z0-9_-]{6,64}$/D', basename($directory)), 'Refused: instance directory is not a dedicated phase-1 temporary instance');
    mit_assert(!is_link($socket) && realpath($socket) === $directory . '/mysql.sock' && filetype($socket) === 'socket', 'Refused: socket is missing, symlinked, or outside the dedicated directory');
    $marker = $directory . '/.crm-phase1-mysql';
    mit_assert(is_file($marker) && !is_link($marker) && trim((string)file_get_contents($marker)) === 'isolated-crm-phase1-mysql-v1', 'Refused: independent-instance marker is missing');
    $data = realpath($directory . '/data');
    mit_assert($data === $directory . '/data' && is_dir($data), 'Refused: expected independent data directory is missing or symlinked');
    $user = (string)(getenv('CRM_PHASE1_MYSQL_USER') ?: 'root');
    mit_assert((bool)preg_match('/^[A-Za-z0-9_]{1,32}$/D', $user), 'Refused: invalid isolated database user');
    $extensions = json_decode((string)(getenv('CRM_PHASE1_MYSQL_PHP_EXTENSIONS_JSON') ?: '[]'), true);
    mit_assert(is_array($extensions) && count($extensions) <= 8, 'Invalid child extension list');
    foreach ($extensions as $extension) {
        mit_assert(is_string($extension) && (bool)preg_match('#^[A-Za-z0-9_./-]+$#D', $extension) && strpos($extension, '..') === false, 'Invalid child extension argument');
    }
    return ['socket'=>$directory . '/mysql.sock','directory'=>$directory,'data'=>$data,'schema'=>$schema,
        'user'=>$user,'password'=>(string)getenv('CRM_PHASE1_MYSQL_PASSWORD'),'extensions'=>$extensions];
}

function mit_connect(array $config): PDO
{
    // This is the ONLY PDO construction. No host/port/TCP/DSN override exists.
    $pdo = new PDO('mysql:unix_socket=' . $config['socket'] . ';dbname=' . $config['schema'] . ';charset=utf8mb4',
        $config['user'], $config['password'], [
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
            PDO::ATTR_PERSISTENT=>false,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS=>false,
        ]);
    // No write, not even SET SESSION, may precede independent-instance checks.
    $server = $pdo->query('SELECT @@socket AS socket_path, @@datadir AS data_path, @@skip_networking AS network_off, DATABASE() AS schema_name')->fetch();
    mit_assert(realpath((string)$server['socket_path']) === $config['socket'], 'Refused: server socket does not match independent instance');
    mit_assert(realpath((string)$server['data_path']) === $config['data'], 'Refused: server datadir is not the independent temporary datadir');
    mit_assert((int)$server['network_off'] === 1 && $server['schema_name'] === $config['schema'], 'Refused: network-enabled server or wrong schema');
    $pdo->exec('SET SESSION innodb_lock_wait_timeout=12');
    $pdo->exec("SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ");
    return $pdo;
}

function db(): PDO
{
    return $GLOBALS['mitPdo'];
}

// Shared schema maintenance is deliberately disabled. The test owns exact DDL.
function crm_marketing_ensure_tables(): void {}
function crm_mail_execute_send_job(...$arguments): array { throw new LogicException('SMTP is forbidden in this test'); }
function create_system_notification(...$arguments): void { throw new LogicException('Notifications are forbidden in this test'); }

function mit_load_real_functions(): string
{
    $source = file_get_contents(dirname(__DIR__) . '/crm_marketing.php');
    mit_assert(is_string($source), 'Marketing source is unavailable');
    $names = ['crm_marketing_normalize_channel','crm_marketing_is_email_channel',
        'crm_marketing_with_task_lock','crm_marketing_assert_task_executable','crm_marketing_saved_task_status',
        'crm_marketing_assert_targets_rebuildable','crm_marketing_change_task_status',
        'crm_marketing_queue_status_counts','crm_marketing_queue_update_task_status',
        'crm_marketing_email_suppression_sql','crm_marketing_queue_skip_suppressed','crm_marketing_queue_claim'];
    foreach ($names as $name) {
        // Selected application functions are top-level and close at column zero.
        // Extracting only this whitelist never executes require/bootstrap lines.
        mit_assert((bool)preg_match('/^function ' . preg_quote($name, '/') . '\(/m', $source, $match, PREG_OFFSET_CAPTURE), 'Missing real function: ' . $name);
        $start = $match[0][1];
        $end = strpos($source, "\n}", $start);
        mit_assert($end !== false, 'Missing function end: ' . $name);
        eval(substr($source, $start, $end + 2 - $start));
    }
    return hash('sha256', $source);
}

function mit_create_tables(string $token): void
{
    $existing = db()->query('SELECT
        (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE())
        + (SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema=DATABASE())
        + (SELECT COUNT(*) FROM information_schema.events WHERE event_schema=DATABASE())')->fetchColumn();
    mit_assert((int)$existing === 0, 'Refused: dedicated schema is not empty; use a fresh schema');
    $ddl = [
        "CREATE TABLE _crm_marketing_it_guard (id INT PRIMARY KEY, run_token CHAR(64) NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE crm_marketing_tasks (id BIGINT UNSIGNED PRIMARY KEY, task_name VARCHAR(100) NOT NULL DEFAULT 'Synthetic task', task_status VARCHAR(40) NOT NULL, success_count INT NOT NULL DEFAULT 0, failed_count INT NOT NULL DEFAULT 0, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB",
        "CREATE TABLE crm_customers (id INT UNSIGNED PRIMARY KEY, customer_name VARCHAR(100) NOT NULL DEFAULT 'Synthetic customer', do_not_contact TINYINT NOT NULL DEFAULT 0, deleted_at DATETIME NULL) ENGINE=InnoDB",
        "CREATE TABLE crm_contacts (id INT UNSIGNED PRIMARY KEY, customer_id INT UNSIGNED NOT NULL, name VARCHAR(100) NOT NULL DEFAULT 'Synthetic contact', is_left TINYINT NOT NULL DEFAULT 0, do_not_contact TINYINT NOT NULL DEFAULT 0, unsubscribe_email TINYINT NOT NULL DEFAULT 0, deleted_at DATETIME NULL) ENGINE=InnoDB",
        "CREATE TABLE crm_customer_promotion_status (customer_id INT UNSIGNED PRIMARY KEY, status VARCHAR(40) NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE crm_contact_promotions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, contact_id INT UNSIGNED NOT NULL, channel VARCHAR(100) NOT NULL, status VARCHAR(40) NOT NULL, UNIQUE KEY contact_channel (contact_id,channel)) ENGINE=InnoDB",
        "CREATE TABLE crm_marketing_task_targets (id BIGINT UNSIGNED PRIMARY KEY, task_id BIGINT UNSIGNED NOT NULL, channel_key VARCHAR(100) NOT NULL, target_status VARCHAR(40) NOT NULL DEFAULT 'pending', executed_at DATETIME NULL, KEY task_id (task_id)) ENGINE=InnoDB",
        "CREATE TABLE crm_marketing_send_queue (id BIGINT UNSIGNED PRIMARY KEY, task_id BIGINT UNSIGNED NOT NULL, customer_id INT UNSIGNED NOT NULL, contact_id INT UNSIGNED NULL, planned_server_time DATETIME NOT NULL, send_status VARCHAR(40) NOT NULL, send_attempts INT NOT NULL DEFAULT 0, last_error TEXT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY task_due (task_id,send_status,planned_server_time)) ENGINE=InnoDB",
    ];
    foreach ($ddl as $sql) db()->exec($sql . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    db()->prepare('INSERT INTO _crm_marketing_it_guard (id,run_token) VALUES (1,?)')->execute([$token]);
}

function mit_verify_run(string $token): void
{
    mit_assert((bool)preg_match('/^[a-f0-9]{64}$/D', $token), 'Invalid test run token');
    $stored = db()->query('SELECT run_token FROM _crm_marketing_it_guard WHERE id=1')->fetchColumn();
    mit_assert(is_string($stored) && hash_equals($stored, $token), 'Refused: schema is not owned by this integration run');
}

function mit_reset(string $status = 'running'): void
{
    mit_assert(!db()->inTransaction(), 'Fixture reset attempted during transaction');
    mit_verify_run($GLOBALS['mitToken']);
    // The guard proves these tables were created by this run in an empty test schema.
    foreach (['crm_marketing_send_queue','crm_marketing_task_targets','crm_contact_promotions',
        'crm_customer_promotion_status','crm_contacts','crm_customers','crm_marketing_tasks'] as $table) db()->exec('DELETE FROM ' . $table);
    db()->prepare('INSERT INTO crm_marketing_tasks (id,task_status) VALUES (1,?)')->execute([$status]);
    db()->exec("INSERT INTO crm_customers (id) VALUES (1),(2)");
    db()->exec("INSERT INTO crm_contacts (id,customer_id) VALUES (1,1)");
    db()->exec("INSERT INTO crm_marketing_task_targets (id,task_id,channel_key,target_status) VALUES (1,1,'email','pending')");
    db()->exec("INSERT INTO crm_marketing_send_queue (id,task_id,customer_id,contact_id,planned_server_time,send_status) VALUES (1,1,1,1,DATE_SUB(NOW(),INTERVAL 1 MINUTE),'pending')");
}

function mit_queue(): array
{
    return db()->query('SELECT * FROM crm_marketing_send_queue WHERE id=1')->fetch();
}

function mit_task(): array
{
    return db()->query('SELECT * FROM crm_marketing_tasks WHERE id=1')->fetch();
}

function mit_rejects(callable $operation, string $expectedText): void
{
    try { $operation(); }
    catch (RuntimeException $e) {
        mit_assert(strpos($e->getMessage(), $expectedText) !== false, 'Unexpected rejection: ' . $e->getMessage());
        return;
    }
    throw new RuntimeException('Expected rejection did not occur: ' . $expectedText);
}

function mit_line($stream, float $seconds = 8.0): array
{
    $deadline = microtime(true) + $seconds;
    $line = '';
    while (microtime(true) < $deadline) {
        $read = [$stream]; $write = null; $except = null;
        if (stream_select($read, $write, $except, 0, 100000) > 0) {
            $part = fgets($stream);
            mit_assert($part !== false, 'Child closed its protocol before a result');
            $line .= $part;
            if (substr($line, -1) === "\n") {
                $decoded = json_decode($line, true);
                mit_assert(is_array($decoded), 'Invalid child protocol: ' . trim($line));
                mit_assert(!isset($decoded['error']), 'Child error: ' . (string)($decoded['error'] ?? ''));
                return $decoded;
            }
        }
    }
    throw new RuntimeException('Timed out waiting for child protocol');
}

function mit_child_start(string $operation): array
{
    $command = [PHP_BINARY, '-n'];
    foreach ($GLOBALS['mitConfig']['extensions'] as $extension) { $command[] = '-d'; $command[] = 'extension=' . $extension; }
    $command[] = __FILE__; $command[] = '--child'; $command[] = $operation; $command[] = $GLOBALS['mitToken'];
    $process = proc_open($command, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, __DIR__, null, ['bypass_shell'=>true]);
    mit_assert(is_resource($process), 'Unable to start isolated child PHP');
    stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
    $child = ['process'=>$process,'pipes'=>$pipes];
    $GLOBALS['mitChild'] = $child;
    $ready = mit_line($pipes[1]);
    mit_assert(($ready['event'] ?? '') === 'ready' && (int)($ready['connection_id'] ?? 0) > 0, 'Child did not open a separate verified connection');
    $child['connection_id'] = (int)$ready['connection_id'];
    mit_assert($child['connection_id'] !== (int)db()->query('SELECT CONNECTION_ID()')->fetchColumn(), 'Race reused the parent connection');
    fwrite($pipes[0], "GO\n"); fflush($pipes[0]);
    $started = mit_line($pipes[1]);
    mit_assert(($started['event'] ?? '') === 'started', 'Child did not begin its real SQL operation');
    return $child;
}

function mit_lock_snapshot(int $parentId, int $childId): array
{
    // Read only the two synthetic test connections; never dump credentials,
    // arbitrary sessions, server configuration, or the whole InnoDB monitor.
    $snapshot = ['parent_connection_id'=>$parentId,'child_connection_id'=>$childId,
        'parent_in_transaction'=>db()->inTransaction(),'innodb_trx'=>[],'processlist'=>[],'lock_waits'=>[],'errors'=>[]];
    $queries = [
        'innodb_trx' => 'SELECT trx_id AS trx_id,trx_state AS trx_state,trx_mysql_thread_id AS trx_mysql_thread_id,
            trx_started AS trx_started,trx_wait_started AS trx_wait_started,
            trx_requested_lock_id AS trx_requested_lock_id,trx_operation_state AS trx_operation_state,
            trx_rows_locked AS trx_rows_locked,trx_rows_modified AS trx_rows_modified,
            LEFT(trx_query,320) AS query_excerpt
            FROM information_schema.innodb_trx WHERE trx_mysql_thread_id IN (?,?) ORDER BY trx_mysql_thread_id',
        'processlist' => 'SELECT ID,COMMAND,TIME,STATE,LEFT(INFO,320) AS query_excerpt
            FROM information_schema.PROCESSLIST WHERE ID IN (?,?) ORDER BY ID',
        'lock_waits' => 'SELECT r.trx_mysql_thread_id AS requesting_connection_id,
            b.trx_mysql_thread_id AS blocking_connection_id,w.requested_lock_id,w.blocking_lock_id
            FROM information_schema.innodb_lock_waits w
            JOIN information_schema.innodb_trx r ON r.trx_id=w.requesting_trx_id
            JOIN information_schema.innodb_trx b ON b.trx_id=w.blocking_trx_id
            WHERE r.trx_mysql_thread_id IN (?,?)',
    ];
    foreach ($queries as $key => $sql) {
        $query = null;
        try {
            $query = db()->prepare($sql);
            $query->execute([$parentId, $childId]);
            $snapshot[$key] = $query->fetchAll();
        } catch (Throwable $e) {
            $snapshot['errors'][$key] = $e->getMessage();
        } finally {
            // Consume/close every PDO result explicitly before another sample.
            if ($query instanceof PDOStatement) $query->closeCursor();
        }
    }
    return $snapshot;
}

function mit_assert_lock_wait(array $child): void
{
    // Observe a real InnoDB wait, not a timing-only imitation of a race.
    $identity = db()->query('SELECT CONNECTION_ID()');
    $parentId = (int)$identity->fetchColumn();
    $identity->closeCursor();
    $last = []; $observations = 0;
    $deadline = microtime(true) + 5.0;
    do {
        $last = mit_lock_snapshot($parentId, (int)$child['connection_id']);
        $observations++;
        foreach ($last['innodb_trx'] as $transaction) {
            if ((int)$transaction['trx_mysql_thread_id'] === (int)$child['connection_id'] && $transaction['trx_state'] === 'LOCK WAIT') return;
        }
        $read = [$child['pipes'][1]]; $write = null; $except = null;
        if (stream_select($read, $write, $except, 0, 0) !== 0) {
            throw new RuntimeException('Competing operation finished without the expected InnoDB lock wait; last_snapshot='
                . json_encode($last, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        // MySQL 5.7 refreshes its TRX/LOCKS cache only >0.1 seconds after
        // the LAST READ. Polling every 50 ms can indefinitely reuse the first
        // pre-wait snapshot. This is independent of PDO's cursor and the
        // application's REPEATABLE READ transaction isolation.
        // https://dev.mysql.com/doc/refman/5.7/en/innodb-information-schema-internal-data.html
        usleep(250000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Could not observe real InnoDB LOCK WAIT; observations=' . $observations . '; last_snapshot='
        . json_encode($last, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function mit_child_finish(array $child): array
{
    $result = mit_line($child['pipes'][1]);
    mit_assert(($result['event'] ?? '') === 'result', 'Missing child result');
    fclose($child['pipes'][0]);
    $stderr = stream_get_contents($child['pipes'][2]);
    fclose($child['pipes'][1]); fclose($child['pipes'][2]);
    $code = proc_close($child['process']);
    $GLOBALS['mitChild'] = null;
    mit_assert($code === 0 && trim((string)$stderr) === '', 'Child failed or emitted warnings: ' . trim((string)$stderr));
    return $result;
}

function mit_child_main(string $operation, string $token): void
{
    mit_assert(in_array($operation, ['claim','pause','cancel'], true), 'Invalid child operation');
    mit_verify_run($token);
    echo json_encode(['event'=>'ready','connection_id'=>(int)db()->query('SELECT CONNECTION_ID()')->fetchColumn()]) . "\n"; flush();
    mit_assert(trim((string)fgets(STDIN)) === 'GO', 'Missing parent start signal');
    echo "{\"event\":\"started\"}\n"; flush();
    $value = $operation === 'claim' ? crm_marketing_queue_claim(1)
        : crm_marketing_change_task_status(1, $operation === 'pause' ? 'paused' : 'cancelled');
    echo json_encode(['event'=>'result','value'=>$value], JSON_UNESCAPED_UNICODE) . "\n"; flush();
}

function mit_tests(): void
{
    mit_reset();
    mit_assert(crm_marketing_queue_claim(1), 'Eligible real UPDATE JOIN did not claim');
    mit_assert(!crm_marketing_queue_claim(1) && (int)mit_queue()['send_attempts'] === 1, 'Duplicate claim changed attempts');
    foreach (['paused','cancelled','completed','draft'] as $status) {
        mit_reset($status);
        mit_assert(!crm_marketing_queue_claim(1) && (int)mit_queue()['send_attempts'] === 0, 'Stopped parent claimable: ' . $status);
    }
    mit_reset(); db()->exec('UPDATE crm_marketing_send_queue SET planned_server_time=DATE_ADD(NOW(),INTERVAL 1 DAY)');
    mit_assert(!crm_marketing_queue_claim(1), 'Future queue was claimable');
    echo "PASS real claim eligibility, scheduling, duplicate-claim protection\n";

    $policies = [
        "UPDATE crm_customers SET deleted_at=NOW() WHERE id=1",
        "UPDATE crm_customers SET do_not_contact=1 WHERE id=1",
        "INSERT INTO crm_customer_promotion_status VALUES (1,'blacklist')",
        "UPDATE crm_contacts SET deleted_at=NOW() WHERE id=1",
        "UPDATE crm_contacts SET customer_id=2 WHERE id=1",
        "UPDATE crm_contacts SET is_left=1 WHERE id=1",
        "UPDATE crm_contacts SET do_not_contact=1 WHERE id=1",
        "UPDATE crm_contacts SET unsubscribe_email=1 WHERE id=1",
        "INSERT INTO crm_contact_promotions (contact_id,channel,status) VALUES (1,'email','stopped')",
        "INSERT INTO crm_contact_promotions (contact_id,channel,status) VALUES (1,'email','no_contact')",
        "INSERT INTO crm_contact_promotions (contact_id,channel,status) VALUES (1,'email','paused')",
        "INSERT INTO crm_contact_promotions (contact_id,channel,status) VALUES (1,'邮件','paused')",
        "INSERT INTO crm_contact_promotions (contact_id,channel,status) VALUES (1,'no_promotion','active')",
        "INSERT INTO crm_contact_promotions (contact_id,channel,status) VALUES (1,'maintenance_only','active')",
    ];
    foreach ($policies as $index => $sql) {
        mit_reset(); db()->exec($sql);
        mit_assert(!crm_marketing_queue_claim(1), 'Suppressed recipient was claimed, case ' . $index);
        mit_assert(crm_marketing_queue_skip_suppressed(1), 'Suppressed recipient did not become skipped, case ' . $index);
        $row = mit_queue();
        mit_assert($row['send_status'] === 'skipped' && (int)$row['send_attempts'] === 0 && $row['last_error'] !== '', 'Suppression consumed an attempt or lost its reason');
    }
    mit_reset(); db()->exec('UPDATE crm_marketing_send_queue SET contact_id=NULL');
    mit_assert(crm_marketing_queue_claim(1), 'Customer-only recipient with no prohibition was rejected');
    echo "PASS real correlated contact-policy SQL and no-attempt skip\n";

    foreach (['paused','cancelled'] as $status) {
        // Lifecycle wins: uncommitted stop locks the parent/queue; competing claim
        // actually waits, then sees the committed stopped state.
        mit_reset(); db()->beginTransaction();
        crm_marketing_change_task_status(1, $status);
        $child = mit_child_start('claim');
        mit_assert_lock_wait($child);
        db()->commit();
        mit_assert(mit_child_finish($child)['value'] === false, 'Claim bypassed a committed lifecycle change');
        mit_assert((int)mit_queue()['send_attempts'] === 0 && mit_task()['task_status'] === $status, 'Stopped race mutated attempts or parent');

        // Claim wins: the later stop reports in-flight work without retracting or
        // re-labelling the already claimed queue entry.
        mit_reset(); db()->beginTransaction();
        mit_assert(crm_marketing_queue_claim(1), 'Initial race claim failed');
        $child = mit_child_start($status === 'paused' ? 'pause' : 'cancel');
        mit_assert_lock_wait($child);
        db()->commit();
        $stopped = mit_child_finish($child)['value'];
        mit_assert((int)$stopped['in_flight_count'] === 1 && mit_queue()['send_status'] === 'sending', 'Later stop rewrote in-flight mail');
    }
    mit_reset(); db()->beginTransaction(); mit_assert(crm_marketing_queue_claim(1), 'First competing claim failed');
    $child = mit_child_start('claim'); mit_assert_lock_wait($child); db()->commit();
    mit_assert(mit_child_finish($child)['value'] === false && (int)mit_queue()['send_attempts'] === 1, 'Two connections claimed the same queue');
    echo "PASS genuine two-connection lock-wait races: stop-first, claim-first, duplicate claim\n";

    mit_reset();
    foreach (['scheduled','waiting_retry','failed','sent','sending'] as $index => $status) {
        db()->prepare('INSERT INTO crm_marketing_send_queue (id,task_id,customer_id,contact_id,planned_server_time,send_status) VALUES (?,1,1,1,NOW(),?)')->execute([$index + 2, $status]);
    }
    $cancelled = crm_marketing_change_task_status(1, 'cancelled');
    mit_assert((int)$cancelled['cancelled_queue_count'] === 4 && (int)$cancelled['in_flight_count'] === 1, 'Real cancellation missed unsent states');
    $counts = crm_marketing_queue_status_counts(1);
    mit_assert($counts['sent'] === 1 && $counts['sending'] === 1 && $counts['cancelled'] === 4, 'Cancellation rewrote sent/sending');

    mit_reset(); db()->exec("UPDATE crm_marketing_send_queue SET send_status='sent'");
    db()->exec("INSERT INTO crm_marketing_task_targets (id,task_id,channel_key,target_status) VALUES (2,1,'wechat_group','pending')");
    foreach (['pending'=>['manual_pending',1,0], 'failed'=>['partial_failed',1,1], 'success'=>['completed',2,0], 'handled'=>['completed',1,0]] as $manual => $expected) {
        db()->prepare('UPDATE crm_marketing_task_targets SET target_status=? WHERE id=2')->execute([$manual]);
        crm_marketing_queue_update_task_status(1); $task = mit_task();
        mit_assert([$task['task_status'],(int)$task['success_count'],(int)$task['failed_count']] === $expected, 'Mixed summary misclassified manual ' . $manual);
    }
    foreach (['paused','cancelled'] as $status) {
        db()->prepare('UPDATE crm_marketing_tasks SET task_status=? WHERE id=1')->execute([$status]);
        crm_marketing_queue_update_task_status(1);
        mit_assert(mit_task()['task_status'] === $status, 'Real summary revived stopped task');
    }
    echo "PASS real cancellation and mixed mail/manual summaries\n";

    foreach ([['pending','2026-09-06 10:00:00'],['failed','2026-09-06 10:00:00'],
        ['success',null],['handled',null],['skipped',null],['cancelled',null]] as $history) {
        mit_reset('manual_pending'); db()->exec('DELETE FROM crm_marketing_send_queue');
        db()->prepare("UPDATE crm_marketing_task_targets SET channel_key='wechat_group',target_status=?,executed_at=? WHERE id=1")->execute($history);
        mit_rejects(static function (): void {
            crm_marketing_with_task_lock(1, static function (array $task): array {
                crm_marketing_assert_targets_rebuildable(1);
                db()->exec('DELETE FROM crm_marketing_task_targets WHERE task_id=1');
                return [];
            });
        }, '已有执行或处理记录');
        mit_assert(!db()->inTransaction() && (int)db()->query('SELECT COUNT(*) FROM crm_marketing_task_targets')->fetchColumn() === 1, 'Historical targets were removed or transaction leaked');
    }
    foreach (['pending','failed'] as $unexecuted) {
        mit_reset(); db()->exec('DELETE FROM crm_marketing_send_queue');
        db()->prepare('UPDATE crm_marketing_task_targets SET target_status=? WHERE id=1')->execute([$unexecuted]);
        crm_marketing_with_task_lock(1, static function (array $task): array { crm_marketing_assert_targets_rebuildable(1); return []; });
        mit_assert(!db()->inTransaction(), 'Successful history guard leaked transaction');
    }
    mit_reset();
    mit_rejects(static function (): void {
        crm_marketing_with_task_lock(1, static function (array $task): array { crm_marketing_assert_targets_rebuildable(1); return []; });
    }, '已有发送队列');
    mit_rejects(static function (): void { crm_marketing_assert_targets_rebuildable(1); }, '保存事务内');
    mit_reset(); db()->exec('DELETE FROM crm_marketing_send_queue');
    mit_rejects(static function (): void {
        crm_marketing_with_task_lock(1, static function (array $task): array {
            crm_marketing_assert_targets_rebuildable(1);
            db()->exec('DELETE FROM crm_marketing_task_targets WHERE task_id=1');
            throw new RuntimeException('intentional transaction rollback');
        });
    }, 'intentional transaction rollback');
    mit_assert(!db()->inTransaction() && (int)db()->query('SELECT COUNT(*) FROM crm_marketing_task_targets')->fetchColumn() === 1, 'Real rollback did not restore deleted synthetic target');
    db()->beginTransaction();
    crm_marketing_change_task_status(1, 'paused');
    mit_assert(db()->inTransaction(), 'Nested helper committed its caller transaction');
    db()->rollBack();
    mit_assert(mit_task()['task_status'] === 'running', 'Caller rollback did not restore nested lifecycle change');
    echo "PASS real historical-target protection and owned/nested transaction rollback\n";
}

$mitPdo = null; $mitChild = null; $mitToken = '';
try {
    $mitConfig = mit_config();
    $sourceHash = mit_load_real_functions();
    $mitPdo = mit_connect($mitConfig);
    if (($argv[1] ?? '') === '--child') {
        mit_child_main((string)($argv[2] ?? ''), (string)($argv[3] ?? ''));
        exit(0);
    }
    mit_assert(count($argv) === 1, 'Unexpected command-line arguments');
    $mitToken = bin2hex(random_bytes(32));
    mit_create_tables($mitToken);
    echo "Marketing MySQL integration: verified independent local instance; source SHA256 {$sourceHash}\n";
    mit_tests();
    echo "PASS all integration scenarios. Only synthetic data in {$mitConfig['schema']}; tables retained for inspection.\n";
} catch (Throwable $e) {
    if ($mitPdo instanceof PDO && $mitPdo->inTransaction()) $mitPdo->rollBack();
    if (is_array($mitChild)) {
        foreach ($mitChild['pipes'] as $pipe) if (is_resource($pipe)) fclose($pipe);
        if (is_resource($mitChild['process'])) { proc_terminate($mitChild['process']); proc_close($mitChild['process']); }
    }
    if (($argv[1] ?? '') === '--child') echo json_encode(['error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE) . "\n";
    else fwrite(STDERR, 'FAIL (isolated fixtures retained): ' . $e->getMessage() . "\n");
    exit(1);
}
