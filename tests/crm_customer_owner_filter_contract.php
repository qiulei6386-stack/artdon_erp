<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('CLI only');
}

$root = dirname(__DIR__);
$page = file_get_contents($root . '/crm.php');
$css = file_get_contents($root . '/assets/crm/crm.css');
$js = file_get_contents($root . '/assets/crm/crm.js');
$api = file_get_contents($root . '/crm_api.php');
$customer = file_get_contents($root . '/crm_customer.php');
if ($page === false || $css === false || $js === false || $api === false || $customer === false) {
    fwrite(STDERR, "Cannot read CRM owner filter sources\n");
    exit(1);
}

function owner_filter_fail(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

$markers = [
    [$page, 'data-filter-owner-mode value="primary" checked', '默认勾选仅第一负责人'],
    [$page, '>仅第一负责人</span>', '仅第一负责人文案'],
    [$page, '>含协助</span>', '含协助文案'],
    [$page, 'aria-label="负责人匹配方式"', '匹配方式分组标签'],
    [$page, '仅第一负责人只看客户的第一负责人；含协助同时包含协助人。', '匹配方式说明'],
    [$css, '.customer-filter-owner-mode { display: grid; gap: 4px; }', '匹配方式布局'],
    [$js, 'owner_match: this.ownerMatchValue()', '读取匹配方式'],
    [$js, "owner_match: advanced.owner_match === 'any' ? 'any' : 'primary'", '列表请求默认仅第一负责人'],
    [$js, "this.setOwnerMatchValue('primary')", '清空后回到仅第一负责人'],
    [$js, "this.setOwnerMatchValue(advanced.owner_match || 'primary')", '筛选状态回写匹配方式'],
    [$js, "if (key === 'owner_match') return false;", '只改匹配方式不算已筛选'],
    [$js, "advanced.owner_match === 'any' ? '（含协助）' : '（仅第一负责人）'", '筛选说明带匹配方式'],
    [$api, 'crm_customer_list($_POST)', '客户列表接口原样转发筛选参数'],
    [$customer, 'function crm_customer_owner_match_sql(int $userId, array &$params): string', '含协助仍用原来的宽匹配'],
    [$customer, 'function crm_customer_owner_primary_match_sql(int $userId, array &$params): string', '仅第一负责人单独匹配'],
    [$customer, "if (trim(\$mode) === 'any') return crm_customer_owner_match_sql(\$userId, \$params);", '只有含协助走宽匹配'],
    [$customer, "crm_customer_owner_filter_sql((int)\$input['owner_user_id'], (string)(\$input['owner_match'] ?? ''), \$params)", '列表按 owner_match 选择匹配'],
    [$customer, "\$where[] = crm_customer_owner_match_sql((int)(current_user()['id'] ?? 0), \$params);", '我的客户仍包含协助人'],
];
foreach ($markers as [$source, $needle, $label]) {
    if (strpos($source, $needle) === false) {
        owner_filter_fail('Missing owner filter marker: ' . $label);
    }
}

function owner_filter_original(string $source, string $name): string
{
    if (!preg_match('/^function ' . preg_quote($name, '/') . '\(/m', $source, $match, PREG_OFFSET_CAPTURE)) {
        owner_filter_fail('Missing function ' . $name);
    }
    $start = $match[0][1];
    $end = strpos($source, "\nfunction ", $start + 1);
    return substr($source, $start, $end === false ? null : $end - $start);
}

eval(owner_filter_original($customer, 'crm_customer_owner_match_sql'));
eval(owner_filter_original($customer, 'crm_customer_owner_primary_match_sql'));
eval(owner_filter_original($customer, 'crm_customer_owner_filter_sql'));

$wideParams = [];
$wideSql = crm_customer_owner_match_sql(6, $wideParams);
if ($wideParams !== [6, 6] || strpos($wideSql, 'is_primary') !== false || strpos($wideSql, 'crm_customer_owners') === false) {
    owner_filter_fail('Wide owner match changed');
}

$ownerFilterCustomers = [17 => 6, 64 => 15, 100 => 6, 101 => 6, 102 => null, 103 => 15, 104 => 15, 105 => 6, 106 => null];
$ownerFilterRows = [
    [1, 17, 6, 'primary', 1],
    [2, 17, 15, 'secondary', 0],
    [3, 17, 3, 'secondary', 0],
    [4, 64, 15, 'primary', 1],
    [5, 64, 6, 'secondary', 0],
    [6, 101, 15, 'primary', 1],
    [7, 101, 6, 'secondary', 0],
    [8, 102, 3, 'primary', 1],
    [9, 102, 6, 'secondary', 0],
    [10, 102, 15, 'secondary', 0],
    [11, 103, 6, 'primary', 0],
    [12, 104, 6, 'primary', 1],
    [13, 104, 15, 'primary', 0],
    [14, 105, 15, 'primary', 0],
    [20, 105, 6, 'primary', 0],
];

function owner_filter_ids(string $sql, array $params): array
{
    static $pdo = null;
    static $usePython = null;
    $statement = 'SELECT c.id FROM crm_customers c WHERE c.deleted_at IS NULL AND ' . $sql . ' ORDER BY c.id';
    if ($usePython === null) $usePython = !in_array('sqlite', PDO::getAvailableDrivers(), true);
    if (!$usePython) {
        if (!$pdo instanceof PDO) $pdo = owner_filter_pdo();
        $stmt = $pdo->prepare($statement);
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
    return owner_filter_python($statement, $params);
}

function owner_filter_pdo(): PDO
{
    global $ownerFilterCustomers, $ownerFilterRows;
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE crm_customers (id INTEGER PRIMARY KEY, owner_user_id INTEGER, deleted_at TEXT)');
    $pdo->exec('CREATE TABLE crm_customer_owners (id INTEGER PRIMARY KEY, customer_id INTEGER, user_id INTEGER, role_type TEXT, is_primary INTEGER)');
    $insertCustomer = $pdo->prepare('INSERT INTO crm_customers (id, owner_user_id, deleted_at) VALUES (?, ?, NULL)');
    $insertOwner = $pdo->prepare('INSERT INTO crm_customer_owners (id, customer_id, user_id, role_type, is_primary) VALUES (?, ?, ?, ?, ?)');
    foreach ($ownerFilterCustomers as $id => $ownerId) $insertCustomer->execute([$id, $ownerId]);
    foreach ($ownerFilterRows as $row) $insertOwner->execute($row);
    return $pdo;
}

function owner_filter_python(string $sql, array $params): array
{
    global $ownerFilterCustomers, $ownerFilterRows;
    $payload = json_encode([
        'customers' => $ownerFilterCustomers,
        'owners' => $ownerFilterRows,
        'sql' => $sql,
        'params' => array_values($params),
    ], JSON_UNESCAPED_UNICODE);
    $python = <<<'PY'
import json, sqlite3, sys
job = json.load(sys.stdin)
con = sqlite3.connect(":memory:")
con.execute("CREATE TABLE crm_customers (id INTEGER PRIMARY KEY, owner_user_id INTEGER, deleted_at TEXT)")
con.execute("CREATE TABLE crm_customer_owners (id INTEGER PRIMARY KEY, customer_id INTEGER, user_id INTEGER, role_type TEXT, is_primary INTEGER)")
for cid, owner in job["customers"].items():
    con.execute("INSERT INTO crm_customers (id, owner_user_id, deleted_at) VALUES (?, ?, NULL)", (int(cid), owner))
con.executemany("INSERT INTO crm_customer_owners (id, customer_id, user_id, role_type, is_primary) VALUES (?, ?, ?, ?, ?)", job["owners"])
rows = [row[0] for row in con.execute(job["sql"], job["params"])]
json.dump(rows, sys.stdout)
PY;
    $cmd = 'python3 -c ' . escapeshellarg($python);
    $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $spec, $pipes);
    if (!is_resource($proc)) owner_filter_fail('Cannot start sqlite runner');
    fwrite($pipes[0], $payload === false ? '' : $payload);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    $rows = json_decode((string)$out, true);
    if ($code !== 0 || !is_array($rows)) owner_filter_fail('SQLite runner failed: ' . trim((string)$err . ' ' . (string)$out));
    return array_map('intval', $rows);
}

function owner_filter_expect(array $actual, array $expected, string $label): void
{
    if ($actual !== $expected) {
        owner_filter_fail($label . ' got ' . json_encode($actual) . ' expected ' . json_encode($expected));
    }
}

$primarySukie = [17, 100, 103, 104];
$primaryWinnie = [64, 101, 105];
$anySukie = [17, 64, 100, 101, 102, 103, 104, 105];
$anyWinnie = [17, 64, 101, 102, 103, 104, 105];

foreach (['', 'primary', 'bogus', '含协助'] as $mode) {
    $params = [];
    $sql = crm_customer_owner_filter_sql(6, $mode, $params);
    owner_filter_expect(owner_filter_ids($sql, $params), $primarySukie, 'sukie primary mode ' . ($mode === '' ? 'empty' : $mode));
    if ($params !== [6]) owner_filter_fail('Primary mode must bind the user once');
}
$params = [];
owner_filter_expect(owner_filter_ids(crm_customer_owner_filter_sql(15, 'primary', $params), $params), $primaryWinnie, 'winnie primary');
if (array_intersect($primarySukie, $primaryWinnie) !== []) {
    owner_filter_fail('Primary owner sets must not overlap in this fixture');
}

foreach (['any', ' any '] as $mode) {
    $params = [];
    $sql = crm_customer_owner_filter_sql(6, $mode, $params);
    $legacy = [];
    $legacySql = crm_customer_owner_match_sql(6, $legacy);
    owner_filter_expect(owner_filter_ids($sql, $params), $anySukie, 'sukie any mode ' . trim($mode));
    owner_filter_expect(owner_filter_ids($legacySql, $legacy), $anySukie, 'sukie legacy wide match');
    if ($sql !== $legacySql) owner_filter_fail('含协助 must keep the previous SQL');
}
$params = [];
owner_filter_expect(owner_filter_ids(crm_customer_owner_filter_sql(15, 'any', $params), $params), $anyWinnie, 'winnie any');
if (!in_array(64, $anySukie, true) || in_array(64, $primarySukie, true)) {
    owner_filter_fail('Secondary-only sukie must stay out of 仅第一负责人 and stay in 含协助');
}
if (in_array(101, $primarySukie, true)) {
    owner_filter_fail('Stale owner_user_id must not beat another primary owner');
}

echo "crm_customer_owner_filter_contract ok\n";
