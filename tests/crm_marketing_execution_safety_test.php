<?php
/** Isolated behavioral checks. Never loads application bootstrap or a real database. */
if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI only');

function safety_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function safety_function(string $source, string $name): string
{
    // These named top-level functions use column-zero closing braces. This
    // deliberately needs no tokenizer/DB/mail extensions when run with php -n.
    if (!preg_match('/^function ' . preg_quote($name, '/') . '\(/m', $source, $match, PREG_OFFSET_CAPTURE)) {
        throw new RuntimeException('Function missing: ' . $name);
    }
    $start = $match[0][1];
    $end = strpos($source, "\n}", $start);
    if ($end === false) throw new RuntimeException('Function closing brace missing: ' . $name);
    return substr($source, $start, $end + 2 - $start);
}

class MarketingSafetyDb
{
    public array $task = ['id' => 1, 'task_status' => 'pending', 'channel_key' => 'email', 'task_name' => 'Safety test', 'schedule_type' => 'manual'];
    public array $queues = [];
    public array $targets = [];
    public array $queries = [];
    public array $policy = [];
    public bool $transaction = false;
    public bool $parentLocked = false;
    public int $rollbacks = 0;
    public $beforeParentLock = null;
    public $beforeClaim = null;
    private array $snapshot = [];
    public function inTransaction(): bool { return $this->transaction; }
    public function beginTransaction(): void { $this->snapshot = [$this->task, $this->queues, $this->targets]; $this->transaction = true; }
    public function commit(): void { $this->transaction = false; $this->parentLocked = false; }
    public function rollBack(): void { [$this->task, $this->queues, $this->targets] = $this->snapshot; $this->transaction = false; $this->parentLocked = false; $this->rollbacks++; }
    public function prepare(string $sql): MarketingSafetyStatement { $this->queries[] = $sql; return new MarketingSafetyStatement($this, $sql); }
}

class MarketingSafetyStatement
{
    private MarketingSafetyDb $db;
    private string $sql;
    private array $rows = [];
    private int $affected = 0;
    public function __construct(MarketingSafetyDb $db, string $sql) { $this->db = $db; $this->sql = preg_replace('/\s+/', ' ', trim($sql)); }
    private function sqlStatuses(string $expression): array
    {
        safety_assert((bool)preg_match($expression, $this->sql, $match), 'Missing status guard: ' . $this->sql);
        return array_map(static fn($v) => trim($v, " '\""), explode(',', $match[1]));
    }
    private function policyBlocks(): bool
    {
        foreach (['c.deleted_at IS NOT NULL','ct.deleted_at IS NOT NULL','ct.customer_id<>c.id','ct.do_not_contact','ct.unsubscribe_email','ct.is_left','crm_contact_promotions cp',"cp.status IN ('stopped','no_contact','paused')", "cp.channel IN ('no_promotion','maintenance_only')"] as $guard) {
            safety_assert(strpos($this->sql, $guard) !== false, 'Missing recipient guard: ' . $guard);
        }
        $p = $this->db->policy;
        return !empty($p['customer_deleted']) || !empty($p['contact_deleted']) || !empty($p['contact_mismatch'])
            || !empty($p['do_not_contact']) || !empty($p['unsubscribe_email']) || !empty($p['is_left'])
            || in_array($p['customer_status'] ?? '', ['blacklist','maintenance_only','stopped','no_promotion'], true)
            || in_array($p['channel_status'] ?? '', ['stopped','no_contact','paused'], true) || !empty($p['no_promotion']);
    }
    public function execute(array $params = []): bool
    {
        $d = $this->db; $s = $this->sql;
        if (strpos($s, 'SELECT * FROM crm_marketing_tasks') === 0) {
            safety_assert(strpos($s, 'FOR UPDATE') !== false && $d->inTransaction(), 'Parent must be locked in a transaction');
            if ($d->beforeParentLock) { $callback = $d->beforeParentLock; $d->beforeParentLock = null; $callback($d); }
            $d->parentLocked = true; $this->rows = [$d->task];
        } elseif (strpos($s, 'SELECT channel_key,target_status FROM crm_marketing_task_targets') === 0) {
            $this->rows = $d->targets;
        } elseif (strpos($s, 'SELECT id,target_status,executed_at FROM crm_marketing_task_targets') === 0) {
            safety_assert($d->parentLocked && $d->inTransaction() && strpos($s, 'FOR UPDATE') !== false, 'Rebuild protection must lock targets inside parent transaction');
            $this->rows = $d->targets;
        } elseif (strpos($s, 'SELECT COUNT(*) FROM crm_marketing_send_queue') === 0) {
            $this->rows = [[strpos($s, "send_status='sending'") !== false
                ? count(array_filter($d->queues, static fn($q) => $q['send_status'] === 'sending'))
                : count($d->queues)]];
        } elseif (strpos($s, 'SELECT q.*, qb.body_html') === 0) {
            safety_assert(strpos($s, 'INNER JOIN crm_marketing_tasks t ON t.id=q.task_id') !== false, 'Worker selection must join parent');
            $allowed = $this->sqlStatuses("/t\.task_status IN \(([^)]+)\)/");
            $this->rows = in_array($d->task['task_status'], $allowed, true) ? array_values($d->queues) : [];
        } elseif (strpos($s, 'UPDATE crm_marketing_send_queue q INNER JOIN crm_marketing_tasks') === 0) {
            $isSkip = strpos($s, "SET q.send_status='skipped'") !== false;
            if (!$isSkip && $d->beforeClaim) { $callback = $d->beforeClaim; $d->beforeClaim = null; $callback($d); }
            $allowed = $this->sqlStatuses("/t\.task_status IN \(([^)]+)\)/");
            $queueAllowed = $this->sqlStatuses("/q\.send_status IN \(([^)]+)\)/");
            if (!$isSkip) safety_assert(strpos($s, 'q.planned_server_time<=NOW()') !== false, 'Claim must recheck scheduled time');
            $suppressed = $this->policyBlocks();
            safety_assert(strpos($s, $isSkip ? "END)<>''" : "END)=''") !== false, 'Policy result not part of atomic update predicate');
            $id = (int)$params[0];
            if (isset($d->queues[$id]) && in_array($d->task['task_status'], $allowed, true) && in_array($d->queues[$id]['send_status'], $queueAllowed, true) && ($isSkip || !empty($d->queues[$id]['due'])) && ($isSkip ? $suppressed : !$suppressed)) {
                $d->queues[$id]['send_status'] = $isSkip ? 'skipped' : 'sending';
                if (!$isSkip) $d->queues[$id]['send_attempts']++;
                $this->affected = 1;
            }
        } elseif (strpos($s, 'UPDATE crm_marketing_tasks SET task_name = ?') === 0) {
            safety_assert($d->parentLocked && $d->inTransaction(), 'Content edit must hold parent lock');
            $d->task['task_name'] = $params[0]; $d->task['channel_key'] = $params[1]; $d->task['task_status'] = $params[2];
        } elseif (strpos($s, "UPDATE crm_marketing_send_queue SET send_status='cancelled'") === 0) {
            safety_assert($d->parentLocked && $d->task['task_status'] === 'cancelled', 'Cancel must update parent first under lock');
            $allowed = $this->sqlStatuses("/send_status IN \(([^)]+)\)/");
            foreach ($d->queues as &$q) if (in_array($q['send_status'], $allowed, true)) { $q['send_status'] = 'cancelled'; $this->affected++; }
            unset($q);
        } elseif (strpos($s, 'UPDATE crm_marketing_tasks SET task_status=CASE') === 0) {
            $preserved = $this->sqlStatuses("/task_status IN \(([^)]+)\)/");
            if (!in_array($d->task['task_status'], $preserved, true)) $d->task['task_status'] = $params[0];
            $d->task['success_count'] = $params[1]; $d->task['failed_count'] = $params[2];
        } elseif (strpos($s, 'UPDATE crm_marketing_tasks SET task_status = ?') === 0) {
            safety_assert($d->parentLocked, 'Lifecycle update must hold parent lock');
            $d->task['task_status'] = $params[0];
        } elseif (strpos($s, "UPDATE crm_marketing_tasks SET task_status='manual_pending'") === 0) {
            safety_assert($d->parentLocked, 'Manual acceptance must hold parent lock');
            $d->task['task_status'] = 'manual_pending';
        } else {
            throw new RuntimeException('Unexpected SQL, no real database is available: ' . $s);
        }
        return true;
    }
    public function fetch() { return $this->rows[0] ?? false; }
    public function fetchAll(): array { return $this->rows; }
    public function fetchColumn() { return isset($this->rows[0]) ? array_values($this->rows[0])[0] : false; }
    public function rowCount(): int { return $this->affected; }
}

$safetyDb = new MarketingSafetyDb(); $safetyPermissions = []; $safetyBuilds = 0; $safetyThrowBuild = false; $safetyBuildResult = null;
function db(): MarketingSafetyDb { return $GLOBALS['safetyDb']; }
function crm_marketing_ensure_tables(): void {}
function crm_mail_ensure_tables(): void {}
function crm_ensure_tables(): void {}
function crm_marketing_notify_queue_build(int $taskId, array $result): void { safety_assert(!db()->inTransaction(), 'Queue notification must run after transaction'); }
function crm_require(string $permission): void { $GLOBALS['safetyPermissions'][] = $permission; }
function crm_log_event(...$args): void {}
function crm_marketing_tasks(): array { return [db()->task]; }
function crm_marketing_logs(array $input = []): array { return []; }
function crm_marketing_linkify_mail_html(string $html): string { return $html; }
function crm_marketing_update_target_from_queue(array $row, string $status, string $reason): void { db()->targets[0]['target_status'] = $status; }
function crm_marketing_is_email_channel(string $channel): bool { return in_array(strtolower($channel), ['email','mail','edm'], true); }
function crm_marketing_queue_list(array $input): array { return ['rows' => array_values(db()->queues)]; }
function crm_mail_execute_send_job(...$args): array { throw new RuntimeException('SMTP must never be called in this test'); }
function crm_marketing_queue_status_counts(int $id): array
{
    $counts = array_fill_keys(['pending','scheduled','sending','sent','failed','skipped','cancelled','waiting_retry'], 0);
    foreach (db()->queues as $q) $counts[$q['send_status']]++;
    return $counts;
}
function crm_marketing_queue_build_locked(array $input, array $task): array
{
    safety_assert(db()->parentLocked, 'Queue building must hold parent lock');
    $GLOBALS['safetyBuilds']++;
    if (is_array($GLOBALS['safetyBuildResult'])) return $GLOBALS['safetyBuildResult'];
    db()->queues[10] = ['id' => 10, 'task_id' => 1, 'send_status' => 'pending', 'send_attempts' => 0, 'due' => true];
    if ($GLOBALS['safetyThrowBuild']) throw new RuntimeException('Simulated builder failure');
    db()->task['task_status'] = 'running';
    return ['task_id' => 1, 'queue_count' => 1, 'message' => '邮件发送队列已生成'];
}

$source = file_get_contents(dirname(__DIR__) . '/crm_marketing.php');
safety_assert(is_string($source), 'Source unavailable');
$names = ['crm_marketing_json','crm_marketing_queue_build','crm_marketing_with_task_lock','crm_marketing_assert_task_executable','crm_marketing_change_task_status',
    'crm_marketing_saved_task_status','crm_marketing_task_update','crm_marketing_email_suppression_sql','crm_marketing_queue_skip_suppressed',
    'crm_marketing_assert_targets_rebuildable',
    'crm_marketing_queue_claim','crm_marketing_queue_update_task_status','crm_marketing_queue_run_due',
    'crm_marketing_queue_cancel','crm_marketing_task_set_status','crm_marketing_task_execute'];
foreach ($names as $name) eval(safety_function($source, $name));

function safety_reset(string $status = 'pending', string $channel = 'email'): void
{
    $GLOBALS['safetyDb'] = new MarketingSafetyDb();
    db()->task['task_status'] = $status; db()->task['channel_key'] = $channel;
    db()->targets = [['channel_key' => $channel, 'target_status' => 'pending']];
    db()->queues = [10 => ['id' => 10, 'task_id' => 1, 'send_status' => 'pending', 'send_attempts' => 0, 'due' => true]];
    $GLOBALS['safetyPermissions'] = []; $GLOBALS['safetyBuilds'] = 0; $GLOBALS['safetyThrowBuild'] = false; $GLOBALS['safetyBuildResult'] = null;
}
function safety_throws(callable $operation, string $message): void
{
    try { $operation(); } catch (RuntimeException $e) { return; }
    throw new RuntimeException($message);
}

// A stopped parent is excluded from both initial selection and final claim.
foreach (['paused','cancelled','completed','draft'] as $status) {
    safety_reset($status);
    $result = crm_marketing_queue_run_due(1);
    safety_assert($result['processed'] === 0 && !crm_marketing_queue_claim(10), 'Stopped parent was claimable: ' . $status);
}
// Pause/cancel arriving after SELECT but before UPDATE cannot be bypassed.
foreach (['paused','cancelled'] as $status) {
    safety_reset();
    db()->beforeClaim = static function ($db) use ($status) { $db->task['task_status'] = $status; };
    safety_assert(!crm_marketing_queue_claim(10), 'Concurrent lifecycle change lost at claim');
    safety_assert(db()->queues[10]['send_attempts'] === 0, 'Rejected claim increments attempts');
}
safety_reset();
safety_assert(crm_marketing_queue_claim(10), 'Eligible queue was not claimed');
safety_assert(!crm_marketing_queue_claim(10), 'Duplicate claim succeeded');
$stopped = crm_marketing_task_set_status(['task_id' => 1, 'status' => 'cancelled']);
safety_assert(db()->queues[10]['send_status'] === 'sending' && $stopped['in_flight_count'] === 1, 'In-flight mail must remain distinguishable');

safety_reset();
$states = ['pending','scheduled','waiting_retry','failed','sent','sending'];
db()->queues = [];
foreach ($states as $i => $status) db()->queues[$i + 10] = ['id' => $i + 10, 'task_id' => 1, 'send_status' => $status, 'send_attempts' => 0, 'due' => true];
$cancel = crm_marketing_queue_cancel(['task_id' => 1]);
safety_assert($cancel['cancelled_queue_count'] === 4 && $cancel['in_flight_count'] === 1, 'Cancel did not cover all unsent queues');
safety_assert(db()->queues[14]['send_status'] === 'sent' && db()->queues[15]['send_status'] === 'sending', 'Cancel rewrote sent/in-flight outcome');
safety_assert(db()->task['task_status'] === 'cancelled', 'Queue cancellation did not stop parent');

foreach (['paused','cancelled'] as $status) {
    safety_reset($status); db()->queues[10]['send_status'] = 'sent';
    crm_marketing_queue_update_task_status(1);
    safety_assert(db()->task['task_status'] === $status && db()->task['success_count'] === 1, 'Summary revived stopped task or lost totals');
    safety_throws(static fn() => crm_marketing_queue_build(['task_id' => 1]), 'Stopped task allowed queue building');
    safety_assert($GLOBALS['safetyBuilds'] === 0, 'Builder ran for stopped task');
}
foreach (['paused','cancelled'] as $status) {
    safety_reset();
    db()->beforeParentLock = static function ($db) use ($status) { $db->task['task_status'] = $status; };
    safety_throws(static fn() => crm_marketing_queue_build(['task_id' => 1]), 'Builder ignored lifecycle change before lock');
    safety_assert($GLOBALS['safetyBuilds'] === 0, 'Builder used stale unlocked parent');
}
safety_reset(); db()->queues = [];
crm_marketing_queue_build(['task_id' => 1]);
crm_marketing_task_set_status(['task_id' => 1, 'status' => 'cancelled']);
safety_assert(db()->queues[10]['send_status'] === 'cancelled' && !crm_marketing_queue_claim(10), 'Cancellation after build did not block newly built queue');
safety_reset(); db()->queues = []; $GLOBALS['safetyThrowBuild'] = true;
safety_throws(static fn() => crm_marketing_queue_build(['task_id' => 1]), 'Builder failure should propagate');
safety_assert(db()->queues === [] && db()->rollbacks === 1, 'Partial queue build was not rolled back');

safety_reset(); db()->queues = [];
$accepted = crm_marketing_task_execute(['task_id' => 1]);
safety_assert($accepted['execution_mode'] === 'queued' && $accepted['queue_count'] === 1, 'Mail task did not enter real builder');
safety_assert($GLOBALS['safetyPermissions'] === ['promotion.execute'], 'Execution incorrectly requires project-edit permission');
safety_assert(!array_key_exists('success_count', $accepted) && db()->targets[0]['target_status'] === 'pending', 'Acceptance falsely marked successful delivery');
$again = crm_marketing_task_execute(['task_id' => 1]);
safety_assert($GLOBALS['safetyBuilds'] === 1 && $again['queue_count'] === 1, 'Repeated start rebuilt existing queue');
safety_reset('failed'); db()->queues[10]['send_status'] = 'failed';
$failed = crm_marketing_task_execute(['task_id' => 1]);
safety_assert(db()->queues[10]['send_status'] === 'failed' && strpos($failed['message'], '未自动重发') !== false, 'Start automatically retries failed mail');
safety_reset('failed');
$resumed = crm_marketing_task_execute(['task_id' => 1]);
safety_assert($resumed['queue_count'] === 1 && crm_marketing_queue_claim(10), 'Existing pending queue on failed parent cannot be claimed');
foreach ([0, 1] as $errors) {
    safety_reset(); db()->queues = [];
    $GLOBALS['safetyBuildResult'] = ['task_id' => 1, 'queue_count' => 0, 'error_count' => $errors];
    safety_throws(static fn() => crm_marketing_task_execute(['task_id' => 1]), 'Empty or failed build was accepted as a manual list');
    safety_assert(db()->task['task_status'] === 'pending' && db()->rollbacks === 1, 'Failed acceptance did not roll back');
}
safety_reset(); db()->queues = [];
db()->targets[] = ['channel_key' => 'wechat_group', 'target_status' => 'pending'];
$GLOBALS['safetyBuildResult'] = ['task_id' => 1, 'queue_count' => 0, 'error_count' => 1];
safety_throws(static fn() => crm_marketing_task_execute(['task_id' => 1]), 'Failed mail build was hidden behind existing manual targets');
safety_reset('manual_pending', 'wechat_group'); db()->queues = [];
$manual = crm_marketing_task_execute(['task_id' => 1]);
safety_assert($manual['execution_mode'] === 'manual' && $manual['manual_target_count'] === 1 && $GLOBALS['safetyBuilds'] === 0, 'Manual task attempted to build mail');
safety_assert(db()->targets[0]['target_status'] === 'pending', 'Manual acceptance fabricated completion');
safety_reset('paused');
crm_marketing_task_set_status(['task_id' => 1, 'status' => 'pending']);
safety_assert(crm_marketing_queue_claim(10), 'Explicit resume did not allow claim');
foreach (['draft','paused','cancelled','completed'] as $status) {
    safety_reset($status);
    safety_throws(static fn() => crm_marketing_task_execute(['task_id' => 1]), 'Invalid start status accepted');
}

// Stale content saves cannot revive an execution lifecycle, including a pause
// committed immediately before the content-save lock is acquired.
foreach (['paused','cancelled','completed'] as $status) {
    safety_reset($status);
    safety_throws(static fn() => crm_marketing_task_update(['task_id' => 1, 'task_status' => 'running']), 'Content save revived ' . $status);
    safety_assert(db()->task['task_status'] === $status, 'Rejected save changed lifecycle');
    crm_marketing_task_update(['task_id' => 1, 'task_name' => 'Edited content']);
    safety_assert(db()->task['task_status'] === $status && db()->task['task_name'] === 'Edited content', 'Content-only edit changed lifecycle');
}
safety_reset('running');
db()->beforeParentLock = static function ($db) { $db->task['task_status'] = 'paused'; };
safety_throws(static fn() => crm_marketing_task_update(['task_id' => 1, 'task_status' => 'running']), 'Stale form ignored latest locked lifecycle');
foreach (['running','paused','cancelled','completed','failed','partial_failed','manual_pending'] as $status) {
    safety_throws(static fn() => crm_marketing_saved_task_status(null, $status), 'New project accepted fabricated execution state');
    safety_throws(static fn() => crm_marketing_saved_task_status(['task_status' => 'draft'], $status), 'Draft save bypassed execution action');
}
safety_assert(crm_marketing_saved_task_status(['task_status' => 'draft'], 'pending') === 'pending', 'Draft cannot become formal');
$saveSource = safety_function($source, 'crm_marketing_task_create');
safety_assert(strpos($saveSource, 'FOR UPDATE') > strpos($saveSource, '$pdo->beginTransaction()'), 'Wizard save did not lock within transaction');
safety_assert(strpos($saveSource, 'crm_marketing_saved_task_status($before, $status)') < strpos($saveSource, 'DELETE FROM crm_marketing_task_targets'), 'Wizard save guards lifecycle too late');
safety_assert(strpos($saveSource, 'crm_marketing_assert_targets_rebuildable($taskId)') !== false
    && strpos($saveSource, 'crm_marketing_assert_targets_rebuildable($taskId)') < strpos($saveSource, 'DELETE FROM crm_marketing_task_targets'), 'Wizard omitted historical-target protection before replacement');

// Execute the real guard with a fake database: manual-only tasks have no queue
// but must retain prior execution/handling history.
foreach ([
    ['target_status'=>'pending','executed_at'=>'2026-09-06 10:00:00'],
    ['target_status'=>'failed','executed_at'=>'2026-09-06 10:00:00'],
    ['target_status'=>'success','executed_at'=>null],
    ['target_status'=>'handled','executed_at'=>null],
    ['target_status'=>'skipped','executed_at'=>null],
    ['target_status'=>'cancelled','executed_at'=>null],
] as $historicalTarget) {
    safety_reset('manual_pending', 'wechat_group'); db()->queues = []; db()->targets = [$historicalTarget];
    $originalTargets = db()->targets;
    safety_throws(static fn() => crm_marketing_with_task_lock(1, static function (array $task): array {
        crm_marketing_assert_targets_rebuildable(1);
        db()->targets = [];
        return [];
    }), 'Manual execution history was allowed to be rebuilt');
    safety_assert(db()->targets === $originalTargets && db()->rollbacks === 1, 'Rejected rebuild lost manual history');
}
foreach (['pending','failed'] as $unexecutedStatus) {
    safety_reset('manual_pending', 'wechat_group'); db()->queues = [];
    db()->targets = [['target_status'=>$unexecutedStatus,'executed_at'=>null]];
    crm_marketing_with_task_lock(1, static function (array $task): array {
        crm_marketing_assert_targets_rebuildable(1);
        return [];
    });
    safety_assert(db()->rollbacks === 0, 'Never-executed target cannot be corrected');
}
foreach (['pending','sent','cancelled'] as $queueState) {
    safety_reset(); db()->queues[10]['send_status'] = $queueState;
    safety_throws(static fn() => crm_marketing_with_task_lock(1, static function (array $task): array {
        crm_marketing_assert_targets_rebuildable(1);
        return [];
    }), 'Queue history was allowed to be rebuilt');
}
safety_reset(); db()->queues = [];
safety_throws(static fn() => crm_marketing_assert_targets_rebuildable(1), 'Rebuild guard allowed an unlocked nontransactional call');

// Mail completion is not completion of a mixed project.
safety_reset('running'); db()->queues[10]['send_status'] = 'sent';
db()->targets[] = ['channel_key' => 'wechat_group', 'target_status' => 'pending'];
crm_marketing_queue_update_task_status(1);
safety_assert(db()->task['task_status'] === 'manual_pending' && db()->task['success_count'] === 1, 'Last mail falsely completed pending manual work');
db()->targets[1]['target_status'] = 'failed';
crm_marketing_queue_update_task_status(1);
safety_assert(db()->task['task_status'] === 'partial_failed' && db()->task['failed_count'] === 1, 'Summary hid manual failure');
db()->targets[1]['target_status'] = 'success';
crm_marketing_queue_update_task_status(1);
safety_assert(db()->task['task_status'] === 'completed' && db()->task['success_count'] === 2, 'Completed mixed outcome was not counted');
db()->targets[1]['target_status'] = 'handled';
crm_marketing_queue_update_task_status(1);
safety_assert(db()->task['task_status'] === 'completed' && db()->task['success_count'] === 1, 'Handled manual target reopened pending work or fabricated success');

// Existing policies and policies changed after selection prevent atomic claim.
foreach ([['channel_status'=>'stopped'],['channel_status'=>'no_contact'],['channel_status'=>'paused'],
    ['do_not_contact'=>1],['unsubscribe_email'=>1],['is_left'=>1],['customer_status'=>'blacklist'],
    ['customer_deleted'=>1],['contact_deleted'=>1],['contact_mismatch'=>1],['no_promotion'=>1]] as $policy) {
    safety_reset('running'); db()->policy = $policy;
    safety_assert(!crm_marketing_queue_claim(10), 'Blocked recipient was claimable: ' . json_encode($policy));
    $result = crm_marketing_queue_run_due(1);
    safety_assert($result['skipped'] === 1 && $result['sent'] === 0 && db()->queues[10]['send_status'] === 'skipped' && db()->queues[10]['send_attempts'] === 0, 'Suppressed mail did not safely resolve without SMTP');
}
safety_reset('running');
safety_assert(!crm_marketing_queue_skip_suppressed(10), 'Allowed recipient incorrectly skipped');
db()->beforeClaim = static function ($db) { $db->policy = ['channel_status' => 'paused']; };
safety_assert(!crm_marketing_queue_claim(10) && db()->queues[10]['send_attempts'] === 0, 'Recipient policy changed before claim was ignored');
safety_assert(crm_marketing_queue_skip_suppressed(10), 'Newly suppressed queue cannot be resolved on next worker pass');
$builderSource = safety_function($source, 'crm_marketing_queue_build_locked');
safety_assert(strpos($builderSource, "crm_marketing_email_suppression_sql('mt.contact_id')") !== false && strpos($builderSource, "email_suppression_reason") !== false, 'Builder omitted shared recipient policy');
safety_assert(strpos($builderSource, '$skipReason !==') < strpos($builderSource, 'INSERT INTO crm_marketing_send_queue'), 'Builder recipient suppression ran after insertion');

echo "CRM marketing execution safety: lifecycle, queue claim, cancellation, rollback, acceptance, stale saves, mixed completion, and contact suppression passed.\n";
