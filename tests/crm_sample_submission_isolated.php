<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Isolated original-function test: no bootstrap, connection, filesystem writes,
// schema ensure, notification delivery or production data is executed.
$source = file_get_contents(dirname(__DIR__) . '/crm_task_center.php');
function sample_load_function(string $source, string $name): void
{
    if (!preg_match('/^function ' . preg_quote($name, '/') . '\(/m', $source, $match, PREG_OFFSET_CAPTURE)) throw new RuntimeException('Missing function ' . $name);
    $start = $match[0][1];
    $end = strpos($source, "\nfunction ", $start + 1);
    eval(substr($source, $start, $end === false ? null : $end - $start));
}
foreach (['crm_sample_shipment_save', 'crm_sample_submission_key', 'crm_sample_payload', 'crm_sample_create_task', 'crm_sample_insert_row', 'crm_sample_create_followup_task', 'crm_sample_create_signed_followup', 'crm_sample_notify_followup_task', 'crm_task_datetime', 'crm_task_date'] as $name) sample_load_function($source, $name);

function sample_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function sample_throws(callable $fn, string $message): void
{
    try { $fn(); } catch (RuntimeException $e) { return; }
    throw new RuntimeException($message);
}
class SampleMemoryDb
{
    public array $tasks = [];
    public array $shipments = [];
    public array $events = [];
    public array $timeline = [];
    public array $notifications = [];
    public array $snapshot = [];
    public bool $transaction = false;
    public bool $lockAvailable = true;
    public bool $locked = false;
    public bool $failTimeline = false;
    public bool $failNotification = false;
    public int $notificationInsideTransaction = 0;
    public int $lastId = 0;
    public int $releaseCount = 0;
    public int $updates = 0;
    public function inTransaction(): bool { return $this->transaction; }
    public function prepare(string $sql): SampleMemoryStatement { return new SampleMemoryStatement($this, $sql); }
    public function lastInsertId(): string { return (string)$this->lastId; }
    public function beginTransaction(): void
    {
        sample_assert(!$this->transaction, 'Must not nest transactions');
        $this->snapshot = [$this->tasks, $this->shipments, $this->events, $this->timeline];
        $this->transaction = true;
    }
    public function commit(): void { sample_assert($this->transaction, 'Commit without transaction'); $this->transaction = false; }
    public function rollBack(): void
    {
        [$this->tasks, $this->shipments, $this->events, $this->timeline] = $this->snapshot;
        $this->transaction = false;
    }
}
class SampleMemoryStatement
{
    private SampleMemoryDb $db;
    private string $sql;
    private $result = false;
    public function __construct(SampleMemoryDb $db, string $sql) { $this->db = $db; $this->sql = $sql; }
    public function execute(array $params = []): void
    {
        $db = $this->db;
        if (strpos($this->sql, 'SELECT GET_LOCK') === 0) {
            sample_assert(strlen($params[0]) <= 64, 'MySQL lock name too long');
            $this->result = $db->lockAvailable ? 1 : 0;
            if ($db->lockAvailable) $db->locked = true;
        } elseif (strpos($this->sql, 'SELECT RELEASE_LOCK') === 0) {
            $db->locked = false; $db->releaseCount++;
        } elseif (strpos($this->sql, 'SELECT id, source_id, request_token, deleted_at FROM crm_tasks') === 0) {
            sample_assert($db->locked && $db->transaction, 'Replay check must be serialized inside transaction');
            $prefix = rtrim($params[1], '%');
            foreach ($db->tasks as $task) {
                if ($task['task_type'] === 'sample_shipment' && $task['created_by'] === $params[0] && strpos((string)$task['request_token'], $prefix) === 0) { $this->result = $task; break; }
            }
        } elseif (strpos($this->sql, 'SELECT id FROM crm_tasks WHERE CONVERT(source_type') === 0) {
            foreach ($db->tasks as $task) {
                if ($task['task_type'] === 'customer_followup' && $task['source_id'] === $params[0] && $task['deleted_at'] === null) { $this->result = $task['id']; break; }
            }
        } elseif (strpos($this->sql, 'INSERT INTO crm_tasks ') === 0) {
            sample_assert(substr_count($this->sql, '?') === count($params), 'Task insert placeholder mismatch');
            $id = ++$db->lastId;
            $isSample = strpos($this->sql, "VALUES ('sample_shipment'") !== false;
            $db->tasks[$id] = ['id' => $id, 'task_type' => $isSample ? 'sample_shipment' : 'customer_followup', 'source_id' => $params[3], 'request_token' => $isSample ? $params[11] : null, 'created_by' => $params[$isSample ? 12 : 11], 'deleted_at' => null];
        } elseif (strpos($this->sql, 'INSERT INTO crm_sample_shipments ') === 0) {
            sample_assert(substr_count($this->sql, '?') === count($params), 'Shipment insert placeholder mismatch');
            preg_match('/\(([^)]+)\) VALUES/', $this->sql, $match);
            $cols = explode(',', $match[1]);
            $cols = array_slice($cols, 0, -2);
            $id = ++$db->lastId;
            $db->shipments[$id] = array_combine($cols, $params) + ['id' => $id, 'deleted_at' => null];
        } elseif (strpos($this->sql, "UPDATE crm_tasks SET source_type='sample_shipment', source_id=?") === 0) {
            $db->tasks[$params[1]]['source_id'] = $params[0];
        } else {
            throw new RuntimeException('Unexpected SQL in isolated test: ' . $this->sql);
        }
    }
    public function fetchColumn() { return $this->result; }
    public function fetch() { return $this->result; }
}
$sampleDb = new SampleMemoryDb();
$sampleUser = 7;
$deniedPermission = '';
$permissions = [];
function db(): SampleMemoryDb { return $GLOBALS['sampleDb']; }
function current_user(): array { return ['id' => $GLOBALS['sampleUser']]; }
function crm_require(string $permission): void
{
    $GLOBALS['permissions'][] = $permission;
    if ($GLOBALS['deniedPermission'] === $permission) throw new RuntimeException('Permission denied');
}
function crm_task_center_ensure_tables(): void {}
function crm_customer_ensure_tables(): void { sample_assert(!db()->inTransaction(), 'Customer ensure must precede transaction'); }
function crm_ensure_tables(): void { sample_assert(!db()->inTransaction(), 'Log ensure must precede transaction'); }
function notification_ensure_schema(): void { sample_assert(!db()->inTransaction(), 'Notification ensure must precede transaction'); }
function notification_create_task_assigned(array $task): void
{
    if (db()->inTransaction()) db()->notificationInsideTransaction++;
    notification_ensure_schema();
    if (db()->failNotification) throw new RuntimeException('Injected notification failure');
    db()->notifications[] = $task;
}
function crm_sample_shipment_detail(int $id): array
{
    crm_require('sample.view');
    $row = db()->shipments[$id] ?? null;
    if (!$row || !empty($row['deleted_at'])) throw new RuntimeException('Unavailable shipment');
    return ['shipment' => $row, 'files' => [], 'logs' => [], 'followups' => []];
}
function crm_sample_update_row(int $id, array $data): void { db()->updates++; db()->shipments[$id] = array_merge(db()->shipments[$id], $data); }
function crm_log_event(...$args): void { db()->events[] = $args; }
function crm_customer_timeline_add(...$args): void
{
    if (db()->failTimeline) throw new RuntimeException('Injected timeline failure');
    db()->timeline[] = $args;
}
function crm_sample_dispatch_placeholder(...$args): void { db()->events[] = ['dispatch']; }

$base = ['customer_id' => 20, 'sample_name' => 'Test sample', 'product_model' => 'M1', 'quantity' => '2', 'request_token' => 'sample-form-token-0001'];
$first = crm_sample_shipment_save($base);
$firstId = $first['shipment']['id'];
sample_assert(count(db()->shipments) === 1 && count(db()->tasks) === 1, 'First submission creates one shipment and task');
sample_assert(!db()->locked && !db()->inTransaction(), 'Successful save releases lock and transaction');
$effectCounts = [count(db()->events), count(db()->timeline)];
$again = crm_sample_shipment_save($base);
sample_assert($again['shipment']['id'] === $firstId && $again['idempotent_replay'] === true, 'Same token returns same record');
sample_assert($effectCounts === [count(db()->events), count(db()->timeline)] && db()->updates === 0, 'Replay must not update or repeat side effects');
sample_throws(fn() => crm_sample_shipment_save(array_merge($base, ['quantity' => '3'])), 'Changed payload must be rejected');
sample_assert(db()->shipments[$firstId]['quantity'] === 2.0 && count(db()->shipments) === 1, 'Conflict must not mutate record');
$second = crm_sample_shipment_save(array_merge($base, ['request_token' => 'sample-form-token-0002']));
sample_assert($second['shipment']['id'] !== $firstId && count(db()->shipments) === 2, 'New form with identical sample must create a real second shipment');
$legacy = $base; unset($legacy['request_token']);
crm_sample_shipment_save($legacy); crm_sample_shipment_save($legacy);
sample_assert(count(db()->shipments) === 4 && db()->updates === 0, 'Legacy requests must never content-match and overwrite');
$sampleUser = 8;
crm_sample_shipment_save($base);
sample_assert(count(db()->shipments) === 5, 'Token scope must include creator');
$sampleUser = 7;
db()->lockAvailable = false;
sample_throws(fn() => crm_sample_shipment_save(array_merge($base, ['request_token' => 'sample-form-token-lock'])), 'Busy submission must fail safely');
sample_assert(count(db()->shipments) === 5 && !db()->inTransaction(), 'Busy lock must not create rows');
db()->lockAvailable = true;
$failureInput = array_merge($base, ['request_token' => 'sample-form-token-rollback']);
db()->failTimeline = true;
sample_throws(fn() => crm_sample_shipment_save($failureInput), 'Injected write failure must surface');
sample_assert(count(db()->shipments) === 5 && count(db()->tasks) === 5 && !db()->locked && !db()->inTransaction(), 'Failure rolls back both rows and releases lock');
db()->failTimeline = false;
crm_sample_shipment_save($failureInput);
sample_assert(count(db()->shipments) === 6, 'Failed submission can be retried without an orphan task');
$deniedPermission = 'sample.create';
sample_throws(fn() => crm_sample_shipment_save($base), 'Replay cannot bypass create permission');
$deniedPermission = 'sample.view';
sample_throws(fn() => crm_sample_shipment_save($base), 'Replay cannot bypass current view permission');
$deniedPermission = '';
crm_sample_shipment_save(array_merge($base, ['shipment_id' => $firstId, 'quantity' => '4', 'request_token' => 'ignored-for-edit']));
sample_assert(db()->updates === 1 && db()->shipments[$firstId]['quantity'] === 4.0 && in_array('sample.edit', $permissions, true), 'Explicit editing retains edit behavior and permission');
$editedReplay = crm_sample_shipment_save($base);
sample_assert($editedReplay['shipment']['quantity'] === 4.0 && db()->updates === 1, 'Original replay after later edit must not restore stale payload');
db()->shipments[$firstId]['deleted_at'] = '2026-09-06';
sample_throws(fn() => crm_sample_shipment_save($base), 'Deleted replay must not silently recreate');
sample_throws(fn() => crm_sample_shipment_save(array_merge($base, ['request_token' => ['bad']])), 'Malformed token rejected');
sample_assert(!db()->locked && !db()->inTransaction(), 'All error paths release transaction and lock');
sample_assert(strpos($source, 'crm_sample_recent_duplicate_id') === false && strpos($source, 'INTERVAL 2 HOUR') === false, 'Content/time-based dedupe must be removed');
sample_assert(strlen(crm_sample_submission_key($base['request_token'], ['x' => 1])) === 99, 'Persisted key must fit existing column');
$followupInput = array_merge($base, ['request_token' => 'sample-form-token-followup', 'create_followup_task' => '1', 'followup_time' => '2026-09-09T10:00', 'status' => 'signed', 'tracking_no' => 'TEST-TRACK']);
$notificationResult = crm_sample_shipment_save($followupInput);
sample_assert(count(db()->notifications) === 1 && db()->notificationInsideTransaction === 0, 'Shared create/signed followup sends one notification only after commit');
crm_sample_shipment_save($followupInput);
sample_assert(count(db()->notifications) === 1, 'Replay must not notify again');
db()->failNotification = true;
$notificationFailure = crm_sample_shipment_save(array_merge($followupInput, ['request_token' => 'sample-form-token-notify-fail']));
sample_assert(isset(db()->shipments[$notificationFailure['shipment']['id']]) && !db()->inTransaction() && db()->notificationInsideTransaction === 0, 'Notification failure must not roll back or misreport committed sample');
db()->failNotification = false;
db()->beginTransaction();
sample_throws(fn() => crm_sample_shipment_save($base), 'Nested new submission must be rejected before schema work');
sample_assert(db()->inTransaction(), 'Rejected nested submission must preserve caller transaction');
db()->rollBack();
echo "crm_sample_submission_isolated: OK\n";
