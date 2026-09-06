<?php
// No bootstrap, credentials or real database connection.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!class_exists('PDO')) { class PDO { const FETCH_ASSOC = 2; } }
if (!class_exists('PDOException')) { class PDOException extends RuntimeException { public $errorInfo; } }
require_once dirname(__DIR__) . '/crm_auth.php';
function permission_expect(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function permission_error(int $code): PDOException {
    $error = new PDOException('Simulated database failure');
    $error->errorInfo = ['40001', $code, 'simulated'];
    return $error;
}
class PermissionFakeConnection {
    public bool $transaction = false;
    public array $rows = [];
    public array $writes = [];
    public bool $fail = false;
    public function inTransaction(): bool { return $this->transaction; }
    public function query($sql) { return new PermissionFakeStatement($this, $sql); }
    public function prepare($sql) { return new PermissionFakeStatement($this, $sql); }
    public function exec($sql) { if ($this->fail) throw permission_error(9999); $this->writes[] = [$sql, []]; return 0; }
}
class PermissionFakeStatement {
    private $pdo; private $sql;
    public function __construct($pdo, $sql) { $this->pdo = $pdo; $this->sql = $sql; }
    public function fetchAll($mode): array { return $this->pdo->rows; }
    public function execute($args): bool { $this->pdo->writes[] = [$this->sql, $args]; return true; }
}
$connection = new PermissionFakeConnection();
function db() { return $GLOBALS['connection']; }
foreach ([1205, 1213] as $code) {
    $attempts = 0;
    crm_permissions_retry_initialization($connection, function () use (&$attempts, $code) {
        if (++$attempts < 3) throw permission_error($code);
    });
    permission_expect($attempts === 3, 'Transient failures retry and eventually succeed');
}
foreach ([[1213, false, 3], [1205, false, 3], [1045, false, 1], [1213, true, 1]] as [$code, $transaction, $expected]) {
    $connection->transaction = $transaction;
    $attempts = 0; $caught = false;
    try {
        crm_permissions_retry_initialization($connection, function () use (&$attempts, $code) { $attempts++; throw permission_error($code); });
    } catch (PDOException $error) { $caught = true; }
    permission_expect($caught && $attempts === $expected, 'Bounded retries, fail closed, no retry inside caller transaction');
}
// MySQL may roll back a caller transaction when choosing it as a deadlock victim.
$connection->transaction = true; $attempts = 0;
try {
    crm_permissions_retry_initialization($connection, function () use (&$attempts, $connection) {
        $attempts++; $connection->transaction = false; throw permission_error(1213);
    });
} catch (PDOException $error) {}
permission_expect($attempts === 1, 'Do not replay after MySQL rolls back a caller transaction');
foreach (crm_permission_definitions() as $definition) {
    $connection->rows[] = array_combine(['permission_key','module','action','description','risk_level'], $definition);
}
crm_seed_permissions();
$definitionWrites = static function () use ($connection): array {
    return array_values(array_filter($connection->writes, fn($entry) => strpos($entry[0], 'INSERT INTO crm_permissions ') === 0));
};
permission_expect(count($definitionWrites()) === 0, 'Unchanged definitions must not acquire write locks');
$missing = array_pop($connection->rows);
$connection->rows[0]['description'] = 'stale description';
$connection->writes = [];
crm_seed_permissions();
permission_expect(count($definitionWrites()) === 2, 'Only missing and changed definitions get written');
// Failed initialization must not poison the request-local done flag.
$connection->fail = true; $caught = false;
try { crm_ensure_permissions(); } catch (PDOException $error) { $caught = true; }
permission_expect($caught, 'Initialization failure must propagate');
$connection->fail = false; $connection->writes = [];
crm_ensure_permissions();
permission_expect(count($connection->writes) > 0, 'Can initialize after a prior failure');
$connection->writes = [];
crm_ensure_permissions();
permission_expect($connection->writes === [], 'Successful initialization runs once per request');
echo "crm_permission_initialization_isolated: OK (12 cases)\n";
