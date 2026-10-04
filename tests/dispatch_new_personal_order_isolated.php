<?php
declare(strict_types=1);

// Evaluate only the original functions; never load the application or a database.
$source = file_get_contents(dirname(__DIR__) . '/dispatch_next_api.php');
foreach (['dn_create_task', 'dn_personal_top_order', 'dn_task_orders', 'dn_save_row_order'] as $name) {
    if (!preg_match('/^function ' . $name . '\([^\n]*\n\{.*?^\}/ms', $source, $match)) {
        throw new RuntimeException('Cannot isolate ' . $name);
    }
    eval($match[0]);
}
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
class OrderDatabase {
    public array $tasks = [], $orders = [], $events = [], $snapshot = [];
    public bool $active = false, $failOrder = false;
    public int $nextId = 100;
    public function inTransaction(): bool { return $this->active; }
    public function beginTransaction(): void {
        check(!$this->active, 'Do not nest transactions');
        $this->snapshot = [$this->tasks, $this->orders]; $this->active = true; $this->events[] = 'begin';
    }
    public function commit(): void { $this->active = false; $this->events[] = 'commit'; }
    public function rollBack(): void {
        [$this->tasks, $this->orders] = $this->snapshot; $this->active = false; $this->events[] = 'rollback';
    }
    public function prepare(string $sql): object {
        return new class($this, $sql) {
            private OrderDatabase $db; private string $sql; private array $args = [];
            public function __construct($db, $sql) { $this->db = $db; $this->sql = $sql; }
            public function execute(array $args): void {
                $this->args = $args;
                if (str_starts_with($this->sql, 'SELECT')) return;
                check(str_contains($this->sql, 'INSERT INTO dispatch_next_task_orders'), 'Only order SQL allowed');
                if ($this->db->failOrder) throw new RuntimeException('Synthetic order failure');
                [$user, $task] = $args;
                $type = $args[2] ?? 'personal'; $rank = $args[3] ?? -1;
                check(count($args) !== 2 || str_contains($this->sql, "'personal',-1"), 'Automatic rank must be saved');
                $this->db->orders[$user . ':' . $task . ':' . $type] = ['user_id'=>$user, 'task_id'=>$task, 'table_type'=>$type, 'sort_order'=>$rank];
            }
            public function fetchAll(): array { return array_values(array_filter($this->db->orders, fn($r)=>$r['user_id']===$this->args[0])); }
        };
    }
}
$db = new OrderDatabase(); $uid = 7;
function dispatch_next_db(): OrderDatabase { return $GLOBALS['db']; }
function dn_uid(): int { return $GLOBALS['uid']; }
function dn_require($permission, $message): void {}
function dn_fail($message): void { throw new RuntimeException($message); }
function dn_sanitize_rich_text($value, $limit): string { return (string)$value; }
function dn_str($value, $limit): string { return (string)$value; }
function dn_priority($value): string { return $value; }
function dn_date($value): string { return $value ?? '2026-10-04'; }
function dn_required_due_dt($value): string { if (!$value) dn_fail('Missing due'); return $value; }
function dn_insert_task(array $data): int {
    $db = dispatch_next_db(); $id = $db->nextId++; $db->tasks[$id] = $data + ['id'=>$id]; return $id;
}
function createPersonal(string $type = 'personal'): int {
    return dn_create_task(['task_type'=>$type, 'title'=>'Synthetic task', 'due_at'=>'2026-10-04 18:00:00'])['id'];
}

$first = createPersonal(); $second = createPersonal('private');
check($db->events === ['begin','commit','begin','commit'], 'Personal create and ordering must commit together');
$orders = dn_task_orders();
check($orders[$first] === -1 && $orders[$second] === -1, 'Order persists across a fresh read');
$rows = [['id'=>5, 'user_sort_order'=>10], ['id'=>$first, 'user_sort_order'=>$orders[$first]], ['id'=>6], ['id'=>$second, 'user_sort_order'=>$orders[$second]]];
usort($rows, fn($a,$b)=>dn_personal_top_order($a,$b));
check(array_column($rows,'id') === [$second,$first,5,6], 'Newest is first; existing order is stable');
dn_save_row_order(['table_type'=>'personal','task_ids'=>[5,$first,$second]]);
$orders = dn_task_orders();
check(dn_personal_top_order(['id'=>$first,'user_sort_order'=>$orders[$first]], ['id'=>5,'user_sort_order'=>$orders[5]]) === 0, 'Manual drag removes automatic top priority');
$third = createPersonal();
check(dn_personal_top_order(['id'=>$third,'user_sort_order'=>-1], ['id'=>5,'user_sort_order'=>10]) < 0, 'Next creation still goes above manual order');
$before = [$db->tasks, $db->orders]; $db->failOrder = true;
try { createPersonal(); throw new LogicException('Expected ordering failure'); }
catch (RuntimeException $e) { check($e->getMessage() === 'Synthetic order failure', 'Expected failure'); }
check([$db->tasks,$db->orders] === $before && !$db->active, 'Order failure must roll back the created task');
$db->failOrder = false;
$uid = 8; check(dn_task_orders() === [], 'One creator does not change another user order'); $uid = 7;
$events = $db->events; $orders = $db->orders;
dn_create_task(['task_type'=>'dispatch','assigned_to'=>9,'title'=>'Synthetic dispatch','due_at'=>'2026-10-04 18:00:00']);
check($db->events === $events && $db->orders === $orders, 'Dispatch creation keeps its original ordering');
$db->beginTransaction(); createPersonal(); check($db->active, 'Do not commit an outer transaction'); $db->rollBack();
check(str_contains($source, 'usort($personal, fn($a, $b) => dn_personal_top_order($a, $b) ?: $sorter($a, $b));'), 'Personal list uses saved top order');
check(str_contains($source, 'usort($dispatch, $sorter);'), 'Dispatch sorter stays unchanged');
echo "Dispatch personal top order: create, reload, repeated create, drag, creator scope and rollback passed (isolated).\n";
