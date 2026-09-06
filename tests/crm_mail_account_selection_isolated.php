<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Execute only extracted account-selection functions against an in-memory fake.
// No bootstrap, SQL connection, account deletion or SMTP/IMAP can run here.
$source = file_get_contents(dirname(__DIR__) . '/crm_mail.php');
function mail_load_function(string $source, string $name): void
{
    if (!preg_match('/^function ' . preg_quote($name, '/') . '\(/m', $source, $match, PREG_OFFSET_CAPTURE)) throw new RuntimeException('Missing function ' . $name);
    $start = $match[0][1];
    $end = strpos($source, "\nfunction ", $start + 1);
    eval(substr($source, $start, $end === false ? null : $end - $start));
}
foreach (['crm_mail_session_key', 'crm_mail_current_account', 'crm_mail_input_account_id', 'crm_mail_account_get_own', 'crm_mail_account_list_own'] as $name) mail_load_function($source, $name);
function mail_assert(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
function mail_throws(callable $fn, string $message): void
{
    try { $fn(); } catch (RuntimeException $e) { return; }
    throw new RuntimeException($message);
}
class MailMemoryDb
{
    public array $rows = [];
    public int $queries = 0;
    public function prepare(string $sql): MailMemoryStatement { return new MailMemoryStatement($this, $sql); }
}
class MailMemoryStatement
{
    private MailMemoryDb $db;
    private string $sql;
    private array $rows = [];
    public function __construct(MailMemoryDb $db, string $sql) { $this->db = $db; $this->sql = $sql; }
    public function execute(array $params): void
    {
        if (++$this->db->queries > 20) throw new LogicException('Unbounded account lookup');
        mail_assert(strpos($this->sql, 'SELECT * FROM crm_user_mail_accounts') === 0, 'Unexpected SQL');
        $explicit = strpos($this->sql, 'WHERE id = ?') !== false;
        $userId = $params[$explicit ? 1 : 0];
        $this->rows = array_values(array_filter($this->db->rows, fn($r) => $r['user_id'] === $userId && $r['deleted_at'] === null && (!$explicit || $r['id'] === $params[0])));
        usort($this->rows, fn($a, $b) => [$b['is_default'], $b['id']] <=> [$a['is_default'], $a['id']]);
    }
    public function fetch() { return $this->rows[0] ?? false; }
    public function fetchAll(): array { return $this->rows; }
}
$mailDb = new MailMemoryDb();
function db(): MailMemoryDb { return $GLOBALS['mailDb']; }
function crm_mail_ensure_tables(): void {}
function crm_mail_target_user_id(array $input = []): int { return (int)($input['target_user_id'] ?? 7); }
function crm_mail_decrypt(string $encrypted): string { return 'decoded:' . $encrypted; }
function crm_require(string $permission): void { mail_assert($permission === 'mail.view', 'Unexpected permission'); }
function crm_mail_user_context(int $id): array { return ['id' => $id]; }
function crm_mail_account_payload(?array $account): array { return ['bound' => $account !== null, 'account' => $account]; }
function mail_reset(): void
{
    $_POST = []; $_GET = []; $_SESSION = [];
    db()->queries = 0;
    db()->rows = [
        ['id' => 10, 'user_id' => 7, 'is_default' => 1, 'deleted_at' => null, 'email_password_encrypted' => 'test'],
        ['id' => 11, 'user_id' => 7, 'is_default' => 0, 'deleted_at' => null, 'email_password_encrypted' => 'other'],
        ['id' => 12, 'user_id' => 8, 'is_default' => 1, 'deleted_at' => null, 'email_password_encrypted' => 'different-user'],
        ['id' => 13, 'user_id' => 7, 'is_default' => 0, 'deleted_at' => '2026-09-06', 'email_password_encrypted' => 'deleted'],
    ];
}
mail_reset();
$row = crm_mail_current_account();
mail_assert($row['id'] === 10 && db()->queries === 1 && !isset($row['email_password_encrypted']) && !isset($row['mail_secret']), 'Unspecified account selects default and strips secret');
mail_reset(); $_SESSION['crm_mail_account_id_7'] = 11;
mail_assert(crm_mail_current_account()['id'] === 11 && db()->queries === 1, 'Valid session selection preserved');
mail_reset(); $_SESSION['crm_mail_account_id_7'] = 999;
mail_assert(crm_mail_current_account()['id'] === 10 && db()->queries === 2, 'Stale session gets exactly one fallback lookup');
foreach ([999, 12, 13] as $id) {
    mail_reset(); $_POST['mail_account_id'] = (string)$id; $_SESSION['crm_mail_account_id_7'] = 11;
    mail_throws(fn() => crm_mail_current_account(), 'Invalid explicit account must fail, not fall back');
    mail_assert(db()->queries === 1 && $_SESSION['crm_mail_account_id_7'] === 11, 'Invalid request must be bounded and preserve valid session');
}
mail_reset(); $_GET['mail_account_id'] = '999';
mail_throws(fn() => crm_mail_current_account(), 'GET invalid ID rejected');
mail_assert(db()->queries === 1, 'GET invalid ID checked once');
mail_reset(); $_POST['mail_account_id'] = '999';
$row = crm_mail_current_account(true, 11);
mail_assert($row['id'] === 11 && $row['mail_secret'] === 'decoded:other' && !isset($row['email_password_encrypted']), 'Explicit argument wins and authorized secret retrieval retained');
mail_reset(); $_POST['mail_account_id'] = '999';
mail_assert(crm_mail_current_account(false, 0)['id'] === 10 && db()->queries === 1, 'Internal default selection must not reread stale request');
mail_reset(); $_POST['mail_account_id'] = '13';
$result = crm_mail_account_get_own(['target_user_id' => 7, 'mail_account_id' => 10]);
mail_assert($result['account']['id'] === 10 && $result['current_id'] === 10, 'Post-deletion response uses next ID throughout, not deleted POST ID');
mail_reset(); db()->rows = []; $_POST['mail_account_id'] = '13';
$result = crm_mail_account_get_own(['target_user_id' => 7, 'mail_account_id' => 0]);
mail_assert($result['bound'] === false && $result['current_id'] === 0, 'Deleting last account can return unbound without recursion');
foreach (['abc', '-1', ['bad']] as $bad) {
    mail_reset(); $_POST['mail_account_id'] = $bad;
    mail_throws(fn() => crm_mail_current_account(), 'Malformed explicit ID rejected');
    mail_assert(db()->queries === 0, 'Malformed ID must fail before lookup');
}
mail_assert(strpos($source, "return crm_mail_current_account(\$withSecret, 0, \$userId)") === false, 'Recursive fallback must be absent');
mail_assert(strpos($source, "crm_mail_account_get_own(['target_user_id' => \$userId, 'mail_account_id' => \$nextId])") !== false, 'Deletion return must explicitly pass next account');
echo "crm_mail_account_selection_isolated: OK\n";
