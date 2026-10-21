<?php
declare(strict_types=1);
namespace MiniappDispatchCreateIsolated;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * Execute the real create / normalizer functions against memory-only PDO spies.
 * No app bootstrap, configuration, schema initialization, credentials or DB driver
 * is loaded. Only the create function's require_once and init_schema lines are
 * removed, with exact-match assertions. All SQL, binding, branching, transaction
 * handling and normalizers are otherwise the original source. ENUM constraints
 * are parsed as text from the real schema, never executed as DDL.
 *
 * php tests/miniapp_dispatch_create_isolated.php
 * MINIAPP_DISPATCH_SOURCE=/local/live-api-copy.php php tests/miniapp_dispatch_create_isolated.php
 */
if (PHP_SAPI !== 'cli') exit(2);
date_default_timezone_set('Asia/Shanghai');

function check(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

function sourceFunction(string $source, string $wanted): string
{
    $tokens = token_get_all($source);
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;
        $j = $i + 1;
        while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
        if (!is_array($tokens[$j] ?? null) || $tokens[$j][0] !== T_STRING || $tokens[$j][1] !== $wanted) continue;
        $code = ''; $depth = 0; $started = false;
        for ($k = $i; $k < $count; $k++) {
            $token = $tokens[$k];
            $code .= is_array($token) ? $token[1] : $token;
            if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++; $started = true;
            } elseif ($token === '}') {
                $depth--;
                if ($started && $depth === 0) return $code;
            }
        }
        throw new RuntimeException('Unclosed source function: ' . $wanted);
    }
    throw new RuntimeException('Missing source function: ' . $wanted);
}

function schemaEnum(string $schema, string $column): array
{
    check((bool) preg_match('/\b' . preg_quote($column, '/') . '\s+ENUM\s*\(([^)]+)\)/i', $schema, $match), 'Missing schema ENUM: ' . $column);
    preg_match_all("/'([^']*)'/", $match[1], $values);
    check((bool) $values[1], 'Empty schema ENUM: ' . $column);
    return $values[1];
}

$sourcePath = getenv('MINIAPP_DISPATCH_SOURCE') ?: dirname(__DIR__) . '/miniapp/api/index.php';
check(strpos($sourcePath, '://') === false && is_file($sourcePath), 'Source must be a local PHP source file');
$source = (string) file_get_contents($sourcePath);
$schema = (string) file_get_contents(dirname(__DIR__) . '/dispatch_next_schema.php');
$enums = [];
foreach (['dispatch_mode', 'task_type', 'priority', 'status'] as $column) $enums[$column] = schemaEnum($schema, $column);
check(in_array('single', $enums['dispatch_mode'], true), 'Real schema must accept the standalone single mode');
foreach (['mx_dispatch_status_label', 'mx_dispatch_standalone_task_no', 'mx_dispatch_normalize_due', 'mx_dispatch_normalize_priority', 'mx_dispatch_create'] as $name) {
    $function = sourceFunction($source, $name);
    if ($name === 'mx_dispatch_create') {
        $excluded = [
            "    require_once __DIR__ . '/../../artdon_erp/dispatch_next_schema.php';",
            "    if (function_exists('dispatch_next_init_schema')) dispatch_next_init_schema();",
        ];
        foreach ($excluded as $line) {
            check(substr_count($function, $line) === 1, 'Schema isolation boundary changed: ' . $line);
            $function = str_replace($line, '', $function);
        }
    }
    check(!preg_match('/\b(?:require|require_once|include|include_once)\b/', $function), 'Unexpected include in isolated function');
    eval('namespace MiniappDispatchCreateIsolated; use PDO; use RuntimeException; use Throwable; ' . $function);
}

final class MemoryPDO extends PDO
{
    public array $tasks = [], $notifications = [], $logs = [], $events = [];
    public array $activeUsers = [7 => true, 9 => true, 11 => false];
    public array $allowedEnums;
    public bool $active = false, $failTaskInsert = false;
    private array $snapshot = [];
    private int $nextId = 100, $lastId = 0;
    public function __construct(array $enums) { $this->allowedEnums = $enums; }
    public function prepare(string $query, array $options = []): PDOStatement|false { return new MemoryStatement($this, $query); }
    public function beginTransaction(): bool
    {
        check(!$this->active, 'No nested transaction expected');
        $this->snapshot = [$this->tasks, $this->notifications, $this->logs];
        $this->active = true; $this->events[] = 'begin'; return true;
    }
    public function inTransaction(): bool { return $this->active; }
    public function commit(): bool
    {
        check($this->active, 'Commit requires a transaction');
        $this->active = false; $this->events[] = 'commit'; return true;
    }
    public function rollBack(): bool
    {
        check($this->active, 'Rollback requires a transaction');
        [$this->tasks, $this->notifications, $this->logs] = $this->snapshot;
        $this->active = false; $this->events[] = 'rollback'; return true;
    }
    public function lastInsertId(?string $name = null): string|false { return (string) $this->lastId; }
    public function insert(string $table, array $row): void
    {
        check($this->active, 'Insert must be transactional');
        if ($table === 'dispatch_next_tasks') {
            foreach ($this->allowedEnums as $column => $allowed) {
                if (!in_array($row[$column] ?? null, $allowed, true)) {
                    throw new PDOException("1265 Data truncated for column '" . $column . "'", 1265);
                }
            }
            if ($this->failTaskInsert) throw new PDOException('Synthetic task insert failure', 1265);
            $this->lastId = $this->nextId++;
            $this->tasks[$this->lastId] = $row + ['id' => $this->lastId];
        } elseif ($table === 'dispatch_next_notifications') {
            $this->notifications[] = $row;
        } elseif ($table === 'dispatch_next_logs') {
            $this->logs[] = $row;
        } else {
            throw new RuntimeException('Unexpected write: ' . $table);
        }
    }
}

final class MemoryStatement extends PDOStatement
{
    private MemoryPDO $memory;
    private string $sql;
    private array $params = [];
    public function __construct(MemoryPDO $memory, string $sql) { $this->memory = $memory; $this->sql = trim($sql); }
    public function execute(?array $params = null): bool
    {
        $this->params = $params ?? [];
        if (preg_match('/^SELECT id FROM crm_users WHERE id = \? AND status = \'active\' LIMIT 1$/', $this->sql)) return true;
        if ($this->sql === 'SELECT COUNT(*) FROM dispatch_next_tasks WHERE task_no = ?') return true;
        check((bool) preg_match('/^INSERT INTO (dispatch_next_tasks|dispatch_next_notifications|dispatch_next_logs)\(([^)]+)\) VALUES\((.*)\)$/s', $this->sql, $match), 'Unexpected SQL in isolated create: ' . $this->sql);
        check(substr_count($match[3], '?') === count($this->params), 'Real SQL placeholder/binding mismatch');
        $columns = array_map('trim', explode(',', $match[2]));
        $row = [];
        foreach ($this->params as $i => $value) $row[$columns[$i]] = $value;
        $this->memory->insert($match[1], $row);
        return true;
    }
    public function fetchColumn(int $column = 0): mixed
    {
        if (strpos($this->sql, 'SELECT id FROM crm_users') === 0) {
            $id = (int) ($this->params[0] ?? 0);
            return ($this->memory->activeUsers[$id] ?? false) ? $id : false;
        }
        check($this->sql === 'SELECT COUNT(*) FROM dispatch_next_tasks WHERE task_no = ?', 'Unexpected fetchColumn SQL');
        return count(array_filter($this->memory->tasks, fn(array $task): bool => $task['task_no'] === $this->params[0]));
    }
}

$memory = new MemoryPDO($enums);
$uid = 7;
function db(): MemoryPDO { return $GLOBALS['memory']; }
function mx_uid(): int { return $GLOBALS['uid']; }
function input(array $overrides = []): array
{
    return array_replace([
        'task_type' => 'dispatch', 'assigned_to' => 9, 'title' => '匿名样品任务',
        'project' => "1、核对尺寸\n2、装配样品\n3、记录测试结果", 'description' => '仅隔离测试，不写真实任务',
        'due_at' => '2026-10-23T18:30', 'task_date' => '2026-10-23', 'priority' => 'normal',
    ], $overrides);
}
function createCase(array $overrides): array
{
    $db = db(); $beforeEvents = count($db->events);
    $result = mx_dispatch_create(input($overrides));
    check(array_slice($db->events, $beforeEvents) === ['begin', 'commit'], 'Each successful create commits exactly once');
    check(!$db->active && $result['id'] === $result['dispatch_id'], 'Committed result has matching IDs');
    $row = $db->tasks[$result['id']];
    check($row['dispatch_mode'] === 'single', 'Standalone create must use single independently of task kind/input');
    check(in_array($row['dispatch_mode'], $db->allowedEnums['dispatch_mode'], true), 'Mode must satisfy the actual schema ENUM');
    check($row['created_by'] === 7 && $row['due_at'] === '2026-10-23 18:30:00', 'Actor and normalized due persisted');
    check($row['project'] === input()['project'], 'Chinese multiline project remains intact');
    check($result['status'] === $row['status'], 'Return status matches persisted status');
    return $row;
}
function rejected(array $overrides, string $message): void
{
    $db = db(); $before = [$db->tasks, $db->notifications, $db->logs, $db->events]; $error = null;
    try { mx_dispatch_create(input($overrides)); } catch (Throwable $e) { $error = $e; }
    check($error instanceof RuntimeException && $error->getMessage() === $message, 'Expected validation rejection: ' . $message);
    check([$db->tasks, $db->notifications, $db->logs, $db->events] === $before && !$db->active, 'Invalid input cannot start a transaction or leave writes');
}

foreach (['personal', 'private'] as $kind) {
    $beforeNotices = count($memory->notifications);
    $row = createCase(['task_type' => $kind, 'assigned_to' => 999, 'dispatch_mode' => 'personal']);
    check($row['task_type'] === 'personal' && $row['assigned_to'] === 7, 'Personal/private always normalize to current-user personal tasks');
    check($row['status'] === 'in_progress' && $row['is_read'] === 1, 'Personal is already in progress/read');
    check(count($memory->notifications) === $beforeNotices, 'Personal creates no recipient notification');
}
$alias = input(['kind' => 'personal', 'assigned_to' => 999]); unset($alias['task_type']);
$result = mx_dispatch_create($alias);
check($memory->tasks[$result['id']]['task_type'] === 'personal' && $memory->tasks[$result['id']]['assigned_to'] === 7, 'kind alias retains personal ownership');

$beforeNotices = count($memory->notifications);
$self = createCase(['assigned_to' => 7]);
check($self['task_type'] === 'dispatch' && $self['status'] === 'in_progress' && $self['is_read'] === 1, 'Existing self-assigned dispatch behavior is unchanged');
check(count($memory->notifications) === $beforeNotices, 'Self-assigned dispatch needs no notification');
$other = createCase(['dispatch_mode' => "personal'); DROP TABLE dispatch_next_tasks; --"]);
check($other['task_type'] === 'dispatch' && $other['assigned_to'] === 9 && $other['status'] === 'pending_accept' && $other['is_read'] === 0, 'Dispatch to another user awaits acceptance and is unread');
check(count($memory->notifications) === $beforeNotices + 1, 'Other assignee receives one notification');
$notice = $memory->notifications[count($memory->notifications) - 1];
check($notice['recipient_id'] === 9 && $notice['sender_id'] === 7 && $notice['task_id'] === $other['id'] && $notice['type'] === 'new_dispatch', 'Notification identifies recipient, actor and inserted task');
check(count($memory->logs) === count($memory->tasks), 'Each successful create records the existing create log');

foreach (['normal', 'important', 'urgent', 'today'] as $priority) {
    check(mx_dispatch_normalize_priority($priority) === $priority, 'Supported priority is preserved: ' . $priority);
    $row = createCase(['task_type' => 'personal', 'priority' => $priority]);
    check($row['priority'] === $priority, 'Priority is bound to the real insert: ' . $priority);
}
check(mx_dispatch_normalize_priority('not-a-priority') === 'normal', 'Unknown priority defaults to normal');
$fallback = createCase(['task_type' => 'personal', 'priority' => 'not-a-priority']);
check($fallback['priority'] === 'normal', 'Default priority reaches the real insert');
check(mx_dispatch_normalize_due('2026-10-23') === '2026-10-23 18:00:00', 'Date-only due defaults to 18:00');
check(mx_dispatch_normalize_due('2026-10-23 09:15') === '2026-10-23 09:15:00', 'Minute due appends seconds');
check(mx_dispatch_normalize_due('2026-10-23T09:15:42') === '2026-10-23 09:15:42', 'ISO separator normalizes and seconds survive');

rejected(['assigned_to' => 999], '负责人无效或已停用');
rejected(['assigned_to' => 11], '负责人无效或已停用');
rejected(['assigned_to' => 0], '请选择负责人');
rejected(['due_at' => ''], '请填写截止日期');
rejected(['due_at' => 'not-a-date'], '请填写截止日期');
rejected(['title' => '  '], '请填写任务标题');
$uid = 0; rejected([], '登录状态无效'); $uid = 7;

$before = [$memory->tasks, $memory->notifications, $memory->logs];
$beforeEvents = count($memory->events); $memory->failTaskInsert = true; $error = null;
try { mx_dispatch_create(input()); } catch (Throwable $e) { $error = $e; }
check($error instanceof PDOException && $error->getCode() === 1265, 'Task insert PDOException propagates');
check(array_slice($memory->events, $beforeEvents) === ['begin', 'rollback'], 'Insert failure rolls back without commit');
check([$memory->tasks, $memory->notifications, $memory->logs] === $before && !$memory->active, 'Insert failure leaves no half task, notification or create log');
$memory->failTaskInsert = false;

echo "Miniapp dispatch create isolated: schema ENUM, personal/private/self/other, priorities, Chinese project, due normalization, validation, input-mode isolation and rollback passed; no bootstrap/config/database/network.\n";
