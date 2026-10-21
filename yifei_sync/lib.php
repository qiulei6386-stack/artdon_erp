<?php
declare(strict_types=1);

/**
 * 易飞（鼎捷 SQL Server 库 AT）只读镜像。
 * 广州只接收本地代理推上来的数据，不连接易飞，也不写回易飞。
 *
 * 已核实字段：
 *   INVMB  MB001 品号，MB002 品名，MB003 规格
 *          MB025 品号属性，MB050 最近进价（不参与材料合计）
 *          MB057 单位标准材料成本，MB058 单位标准人工成本
 *          MB061 本阶人工，MB062 本阶制费，MB096 工时底数
 *   BOMMD  MD001 主件，MD003 元件，MD006 组成用量，MD007 底数，MD008 损耗率
 *   CSTMB  MB002 期间，MB004 单据号（不是品号），MB007 品号，MB005 成本
 * 相邻推断、库里没有就跳过的栏位：MB059 单位标准制造费用，MB060 单位标准加工费用，MB063 本阶加工。
 * 成本金额（标准成本合计）= 材料 MB057 + 制费 MB059 + 加工 MB060。人工 MB058 另列，不计入。
 * 本阶人工/制费/加工是这一阶的数，计算标准成本后已经含在单位标准里，不再加第二次。
 * 特征工艺（CNC 开料、加工）在录入品号「特征数据」页，多为 INVMB 用户自定义数值列（UDF*），
 *   栏位标题在 DSCSYS/AT 的 DSCLB（LB005=Caption，LB006=列名，LB009/ LB008=简繁标题）。
 *   明细落在 yf_item_feature；当 MB059、MB060 都为空或 0 时，成本金额再加上特征金额合计，避免与已回写制费/加工双计。
 * 不使用 INVMI/INVMJ 属性码、BOMME/BOMMF、CSTLA、CSTMC。
 * char 尾部空格在入库时按 RTRIM 去掉。请求里没有的键不会覆盖已有列。
 */

const YF_CODE_MAX = 40;
const YF_NAME_MAX = 255;
const YF_SPEC_MAX = 500;
const YF_PERIOD_MAX = 20;
const YF_DOC_MAX = 40;
const YF_BATCH_MAX = 64;
const YF_ROWS_MAX = 500;
const YF_BODY_MAX = 2097152;

function yf_extra_column_defs(): array
{
    return array(
        'yf_invmb' => array(
            'item_attr' => array(
                'mysql' => "VARCHAR(4) NOT NULL DEFAULT '' COMMENT 'INVMB.MB025 品号属性'",
                'sqlite' => "TEXT NOT NULL DEFAULT ''",
            ),
            'latest_purchase' => array(
                'mysql' => "DECIMAL(18,6) NULL COMMENT 'INVMB.MB050 最近进价，不参与材料合计'",
                'sqlite' => 'TEXT',
            ),
            'std_material' => array(
                'mysql' => "DECIMAL(18,6) NULL COMMENT 'INVMB.MB057 单位标准材料成本'",
                'sqlite' => 'TEXT',
            ),
            'std_labor' => array(
                'mysql' => "DECIMAL(18,6) NULL COMMENT 'INVMB.MB058 单位标准人工成本'",
                'sqlite' => 'TEXT',
            ),
            'std_mfg' => array(
                'mysql' => "DECIMAL(18,6) NULL COMMENT 'INVMB.MB059 单位标准制造费用，按相邻栏位推断'",
                'sqlite' => 'TEXT',
            ),
            'std_subcontract' => array(
                'mysql' => "DECIMAL(18,6) NULL COMMENT 'INVMB.MB060 单位标准加工费用，按相邻栏位推断'",
                'sqlite' => 'TEXT',
            ),
            'level_labor' => array(
                'mysql' => "DECIMAL(18,6) NULL COMMENT 'INVMB.MB061 本阶人工'",
                'sqlite' => 'TEXT',
            ),
            'level_mfg' => array(
                'mysql' => "DECIMAL(18,6) NULL COMMENT 'INVMB.MB062 本阶制费'",
                'sqlite' => 'TEXT',
            ),
            'level_process' => array(
                'mysql' => "DECIMAL(18,6) NULL COMMENT 'INVMB.MB063 本阶加工，按相邻栏位推断'",
                'sqlite' => 'TEXT',
            ),
            'hour_base' => array(
                'mysql' => "DECIMAL(18,6) NULL COMMENT 'INVMB.MB096 工时底数'",
                'sqlite' => 'TEXT',
            ),
        ),
        'yf_bommd' => array(
            'base_qty' => array(
                'mysql' => "DECIMAL(18,6) NOT NULL DEFAULT 1 COMMENT 'BOMMD.MD007 底数'",
                'sqlite' => "TEXT NOT NULL DEFAULT '1'",
            ),
            'scrap_rate' => array(
                'mysql' => "DECIMAL(18,6) NOT NULL DEFAULT 0 COMMENT 'BOMMD.MD008 损耗率，0.25 表示 25%'",
                'sqlite' => "TEXT NOT NULL DEFAULT '0'",
            ),
        ),
    );
}

function yf_column_lines(string $driver, string $table): string
{
    $lines = array();
    foreach (yf_extra_column_defs()[$table] as $name => $defs) {
        $lines[] = $name . ' ' . $defs[$driver];
    }
    return implode(",\n            ", $lines);
}

function yf_mysql_statements(): array
{
    $engine = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $invmb = yf_column_lines('mysql', 'yf_invmb');
    $bommd = yf_column_lines('mysql', 'yf_bommd');
    return array(
        "CREATE TABLE IF NOT EXISTS yf_invmb (
            item_code VARCHAR(40) NOT NULL COMMENT 'INVMB.MB001 品号',
            item_name VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'INVMB.MB002 品名',
            spec VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'INVMB.MB003 规格',
            $invmb,
            synced_at DATETIME NOT NULL COMMENT '本行最后一次成功写入时间',
            batch_id VARCHAR(64) NOT NULL DEFAULT '' COMMENT '同步批次',
            PRIMARY KEY (item_code),
            KEY idx_yf_invmb_name (item_name(64)),
            KEY idx_yf_invmb_synced (synced_at)
        ) $engine",
        "CREATE TABLE IF NOT EXISTS yf_bommd (
            parent_code VARCHAR(40) NOT NULL COMMENT 'BOMMD.MD001 主件品号',
            component_code VARCHAR(40) NOT NULL COMMENT 'BOMMD.MD003 元件品号',
            qty DECIMAL(18,6) NOT NULL DEFAULT 0 COMMENT 'BOMMD.MD006 用量',
            $bommd,
            synced_at DATETIME NOT NULL,
            batch_id VARCHAR(64) NOT NULL DEFAULT '',
            PRIMARY KEY (parent_code, component_code),
            KEY idx_yf_bommd_component (component_code),
            KEY idx_yf_bommd_synced (synced_at)
        ) $engine",
        "CREATE TABLE IF NOT EXISTS yf_cstmb (
            item_code VARCHAR(40) NOT NULL COMMENT 'CSTMB.MB007 品号',
            period VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'CSTMB.MB002 期间',
            doc_no VARCHAR(40) NOT NULL DEFAULT '' COMMENT 'CSTMB.MB004 单据号，不是品号',
            cost DECIMAL(18,6) NOT NULL DEFAULT 0 COMMENT 'CSTMB.MB005 成本',
            synced_at DATETIME NOT NULL,
            batch_id VARCHAR(64) NOT NULL DEFAULT '',
            PRIMARY KEY (item_code),
            KEY idx_yf_cstmb_period (period),
            KEY idx_yf_cstmb_synced (synced_at)
        ) $engine",
        "CREATE TABLE IF NOT EXISTS yf_item_feature (
            item_code VARCHAR(40) NOT NULL COMMENT 'INVMB.MB001 品号',
            feature_code VARCHAR(40) NOT NULL COMMENT '列名或配置代号',
            feature_name VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'DSCLB 或配置中的工艺名',
            amount DECIMAL(18,6) NOT NULL COMMENT '单位特征加工金额',
            sort_no INT NOT NULL DEFAULT 0 COMMENT '推送顺序',
            synced_at DATETIME NOT NULL,
            batch_id VARCHAR(64) NOT NULL DEFAULT '',
            PRIMARY KEY (item_code, feature_code),
            KEY idx_yf_feature_synced (synced_at)
        ) $engine",
        "CREATE TABLE IF NOT EXISTS yf_sync_batches (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            batch_id VARCHAR(64) NOT NULL,
            payload_type VARCHAR(16) NOT NULL,
            row_count INT NOT NULL DEFAULT 0,
            upserted INT NOT NULL DEFAULT 0,
            skipped INT NOT NULL DEFAULT 0,
            status VARCHAR(16) NOT NULL DEFAULT 'ok',
            message VARCHAR(500) NOT NULL DEFAULT '',
            remote_ip VARCHAR(64) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_yf_batch (batch_id),
            KEY idx_yf_batch_created (created_at)
        ) $engine",
    );
}

function yf_sqlite_statements(): array
{
    $invmb = yf_column_lines('sqlite', 'yf_invmb');
    $bommd = yf_column_lines('sqlite', 'yf_bommd');
    return array(
        "CREATE TABLE IF NOT EXISTS yf_invmb (
            item_code TEXT PRIMARY KEY,
            item_name TEXT NOT NULL DEFAULT '',
            spec TEXT NOT NULL DEFAULT '',
            $invmb,
            synced_at TEXT NOT NULL,
            batch_id TEXT NOT NULL DEFAULT ''
        )",
        "CREATE TABLE IF NOT EXISTS yf_bommd (
            parent_code TEXT NOT NULL,
            component_code TEXT NOT NULL,
            qty TEXT NOT NULL DEFAULT '0',
            $bommd,
            synced_at TEXT NOT NULL,
            batch_id TEXT NOT NULL DEFAULT '',
            PRIMARY KEY (parent_code, component_code)
        )",
        'CREATE TABLE IF NOT EXISTS yf_cstmb (
            item_code TEXT PRIMARY KEY,
            period TEXT NOT NULL DEFAULT \'\',
            doc_no TEXT NOT NULL DEFAULT \'\',
            cost TEXT NOT NULL DEFAULT \'0\',
            synced_at TEXT NOT NULL,
            batch_id TEXT NOT NULL DEFAULT \'\'
        )',
        'CREATE TABLE IF NOT EXISTS yf_item_feature (
            item_code TEXT NOT NULL,
            feature_code TEXT NOT NULL,
            feature_name TEXT NOT NULL DEFAULT \'\',
            amount TEXT NOT NULL,
            sort_no INTEGER NOT NULL DEFAULT 0,
            synced_at TEXT NOT NULL,
            batch_id TEXT NOT NULL DEFAULT \'\',
            PRIMARY KEY (item_code, feature_code)
        )',
        'CREATE TABLE IF NOT EXISTS yf_sync_batches (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            batch_id TEXT NOT NULL,
            payload_type TEXT NOT NULL,
            row_count INTEGER NOT NULL DEFAULT 0,
            upserted INTEGER NOT NULL DEFAULT 0,
            skipped INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT \'ok\',
            message TEXT NOT NULL DEFAULT \'\',
            remote_ip TEXT NOT NULL DEFAULT \'\',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )',
    );
}

function yf_mysql_schema_sql(): string
{
    return implode(";\n\n", yf_mysql_statements()) . ";\n";
}

function yf_install(PDO $pdo): void
{
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $statements = $driver === 'sqlite' ? yf_sqlite_statements() : yf_mysql_statements();
    foreach ($statements as $sql) {
        $pdo->exec($sql);
    }
    yf_ensure_columns($pdo);
}

function yf_table_columns(PDO $pdo, string $table): array
{
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $have = array();
    if ($driver === 'sqlite') {
        $stmt = $pdo->query('PRAGMA table_info(' . yf_ident($table) . ')');
        foreach ($stmt as $row) {
            $have[strtolower((string)$row['name'])] = true;
        }
        return $have;
    }
    $stmt = $pdo->query('SHOW COLUMNS FROM ' . yf_ident($table));
    foreach ($stmt as $row) {
        $have[strtolower((string)$row['Field'])] = true;
    }
    return $have;
}

function yf_ensure_columns(PDO $pdo): void
{
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $key = $driver === 'sqlite' ? 'sqlite' : 'mysql';
    foreach (yf_extra_column_defs() as $table => $cols) {
        $have = yf_table_columns($pdo, $table);
        foreach ($cols as $name => $defs) {
            if (!empty($have[$name])) {
                continue;
            }
            $pdo->exec('ALTER TABLE ' . yf_ident($table) . ' ADD COLUMN ' . yf_ident($name) . ' ' . $defs[$key]);
        }
    }
}

function yf_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = getenv('YIFEI_SYNC_DSN');
    if (is_string($dsn) && $dsn !== '') {
        $user = getenv('YIFEI_SYNC_DB_USER');
        $pass = getenv('YIFEI_SYNC_DB_PASS');
        $pdo = new PDO($dsn, is_string($user) ? $user : 'root', is_string($pass) ? $pass : '', array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ));
        return $pdo;
    }
    require_once dirname(__DIR__) . '/includes/db.php';
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

function yf_secret_file(): string
{
    $env = getenv('YIFEI_SYNC_SECRET_FILE');
    if (is_string($env) && $env !== '') {
        return $env;
    }
    return '/www/server/artdon_secrets/yifei_sync.php';
}

function yf_read_php_config(string $path): array
{
    if (!is_file($path)) {
        return array();
    }
    $data = require $path;
    return is_array($data) ? $data : array();
}

function yf_configured_token(): string
{
    $env = getenv('YIFEI_SYNC_TOKEN');
    if (is_string($env) && $env !== '') {
        return $env;
    }
    foreach (array(yf_secret_file(), __DIR__ . '/config.local.php') as $path) {
        $cfg = yf_read_php_config($path);
        if (isset($cfg['token']) && is_string($cfg['token']) && $cfg['token'] !== '') {
            return $cfg['token'];
        }
    }
    return '';
}

function yf_token_usable(string $token): bool
{
    if (strlen($token) < 16) {
        return false;
    }
    if (stripos($token, 'replace-with') !== false) {
        return false;
    }
    return true;
}

function yf_local_preview(): bool
{
    if (getenv('YIFEI_SYNC_ALLOW_PREVIEW') !== '1') {
        return false;
    }
    $cfg = yf_read_php_config(__DIR__ . '/config.local.php');
    return !empty($cfg['allow_preview_without_login']);
}

function yf_safe_return_url(string $requestUri): string
{
    $url = str_replace(array("\r", "\n"), '', trim($requestUri));
    if ($url === '') {
        return '';
    }
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) || strpos($url, '//') === 0 || strpos($url, '\\') !== false) {
        return '';
    }
    $path = parse_url($url, PHP_URL_PATH);
    $page = basename((string)$path);
    if ($page === 'login.php' || $page === 'logout.php') {
        return '';
    }
    return $url;
}

function yf_site_login_url(string $scriptName, string $requestUri): string
{
    $script = str_replace(array("\r", "\n", '\\'), array('', '', '/'), trim($scriptName));
    if ($script === '' || preg_match('#^[a-z][a-z0-9+.-]*:#i', $script) || strpos($script, '//') !== false) {
        $login = '/login.php';
    } else {
        if ($script[0] !== '/') {
            $script = '/' . $script;
        }
        $dir = dirname(dirname($script));
        if ($dir === '/' || $dir === '.' || $dir === '') {
            $login = '/login.php';
        } else {
            $login = rtrim($dir, '/') . '/login.php';
        }
    }
    $return = yf_safe_return_url($requestUri);
    if ($return === '') {
        return $login;
    }
    return $login . '?redirect=' . rawurlencode($return);
}

function yf_authorize(?string $presented): void
{
    $expected = yf_configured_token();
    if (!yf_token_usable($expected)) {
        throw new RuntimeException('TOKEN_NOT_CONFIGURED');
    }
    $presented = (string)$presented;
    if (!hash_equals(hash('sha256', $expected), hash('sha256', $presented))) {
        throw new RuntimeException('UNAUTHORIZED');
    }
}

function yf_request_token(array $server): string
{
    if (!empty($server['HTTP_X_YIFEI_TOKEN']) && is_string($server['HTTP_X_YIFEI_TOKEN'])) {
        return $server['HTTP_X_YIFEI_TOKEN'];
    }
    $auth = isset($server['HTTP_AUTHORIZATION']) && is_string($server['HTTP_AUTHORIZATION']) ? $server['HTTP_AUTHORIZATION'] : '';
    if (stripos($auth, 'Bearer ') === 0) {
        return trim(substr($auth, 7));
    }
    return '';
}

function yf_json(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Robots-Tag: noindex');
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

function yf_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function yf_is_list(array $rows): bool
{
    $i = 0;
    foreach ($rows as $key => $_row) {
        if ($key !== $i) {
            return false;
        }
        $i++;
    }
    return true;
}

function yf_text(string $value, int $max, string $field, bool $required, bool $truncate = false): string
{
    // 易飞品名/规格里常有换行、Tab 等控制字符：统一替换为空格，而不是拒收。
    $value = str_replace("\0", '', $value);
    if (strncmp($value, "\xEF\xBB\xBF", 3) === 0) {
        $value = substr($value, 3);
    }
    $value = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value);
    $value = preg_replace('/ {2,}/', ' ', $value);
    $value = trim($value);
    if ($required && $value === '') {
        throw new InvalidArgumentException($field . ' 不能为空');
    }
    $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    if ($length > $max && $truncate) {
        $value = function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
        $length = $max;
    }
    if ($length > $max) {
        throw new InvalidArgumentException($field . ' 超过 ' . $max . ' 个字符');
    }
    return $value;
}

function yf_decimal_in($value, string $field): string
{
    if (is_int($value)) {
        $value = (string)$value;
    } elseif (is_float($value)) {
        $value = sprintf('%.6F', $value);
    } elseif (is_string($value)) {
        $value = trim($value);
    } else {
        throw new InvalidArgumentException($field . ' 必须是数字');
    }
    if ($value === '') {
        $value = '0';
    }
    // 易飞成本/用量可能为负（冲销、调整），接受任意有限数字；NaN/INF/非数字拒收。
    if (!preg_match('/^([+-]?)(\d*)(?:\.(\d+))?$/', $value, $m) || ($m[2] === '' && ($m[3] ?? '') === '')) {
        throw new InvalidArgumentException($field . ' 必须是数字');
    }
    $sign = $m[1] === '-' ? '-' : '';
    $value = ($m[2] === '' ? '0' : $m[2]) . (isset($m[3]) && $m[3] !== '' ? '.' . $m[3] : '');
    $parts = explode('.', $value, 2);
    if (strlen($parts[0]) > 12) {
        throw new InvalidArgumentException($field . ' 整数位过长');
    }
    $frac = $parts[1] ?? '';
    if (strlen($frac) > 6) {
        throw new InvalidArgumentException($field . ' 最多 6 位小数');
    }
    $int = ltrim($parts[0], '0');
    $out = ($int === '' ? '0' : $int) . '.' . str_pad($frac, 6, '0');
    return ($sign === '-' && $out !== '0.000000') ? '-' . $out : $out;
}

function yf_format_decimal($value): string
{
    $raw = trim((string)$value);
    if ($raw === '' || !is_numeric($raw)) {
        return '—';
    }
    $negative = false;
    if ($raw[0] === '-') {
        $negative = true;
        $raw = substr($raw, 1);
    }
    $parts = explode('.', $raw, 2);
    $int = ltrim($parts[0], '0');
    if ($int === '') {
        $int = '0';
    }
    $frac = rtrim($parts[1] ?? '', '0');
    $out = $frac === '' ? $int : $int . '.' . $frac;
    return ($negative ? '-' : '') . $out;
}

function yf_period_cmp(string $a, string $b): int
{
    $da = preg_replace('/\D+/', '', $a);
    $db = preg_replace('/\D+/', '', $b);
    if (!is_string($da)) {
        $da = '';
    }
    if (!is_string($db)) {
        $db = '';
    }
    if ($da !== '' && $db !== '' && strlen($da) === strlen($db)) {
        return strcmp($da, $db);
    }
    return strcmp($a, $b);
}

function yf_ident(string $name): string
{
    if (!preg_match('/^[a-z_]+$/', $name)) {
        throw new InvalidArgumentException('字段名不正确');
    }
    return '`' . $name . '`';
}

function yf_upsert(PDO $pdo, string $table, array $pk, array $row): void
{
    if (!preg_match('/^[a-z_]+$/', $table)) {
        throw new InvalidArgumentException('表名不正确');
    }
    $cols = array_keys($row);
    $quoted = array();
    $updates = array();
    foreach ($cols as $col) {
        $quoted[] = yf_ident($col);
        if (!isset($pk[$col])) {
            $updates[] = yf_ident($col);
        }
    }
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $placeholders = implode(',', array_fill(0, count($cols), '?'));
    $colSql = implode(',', $quoted);
    if ($driver === 'sqlite') {
        $sets = array();
        foreach ($updates as $col) {
            $sets[] = $col . '=excluded.' . $col;
        }
        $pkCols = array();
        foreach (array_keys($pk) as $col) {
            $pkCols[] = yf_ident($col);
        }
        $sql = 'INSERT INTO ' . yf_ident($table) . ' (' . $colSql . ') VALUES (' . $placeholders . ')'
            . ' ON CONFLICT(' . implode(',', $pkCols) . ') DO UPDATE SET ' . implode(',', $sets);
    } else {
        $sets = array();
        foreach ($updates as $col) {
            $sets[] = $col . '=VALUES(' . $col . ')';
        }
        $sql = 'INSERT INTO ' . yf_ident($table) . ' (' . $colSql . ') VALUES (' . $placeholders . ')'
            . ' ON DUPLICATE KEY UPDATE ' . implode(',', $sets);
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($row));
}

function yf_decode_payload(string $raw): array
{
    if (strlen($raw) > YF_BODY_MAX) {
        throw new InvalidArgumentException('请求体超过 2MB，请分批推送');
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new InvalidArgumentException('请求体不是 JSON 对象');
    }
    return yf_normalize_payload($data);
}

function yf_normalize_payload(array $data): array
{
    $type = isset($data['type']) ? trim((string)$data['type']) : '';
    if (!in_array($type, array('invmb', 'bommd', 'cstmb', 'feature'), true)) {
        throw new InvalidArgumentException('type 只能是 invmb、bommd、cstmb 或 feature');
    }
    if (!isset($data['rows']) || !is_array($data['rows']) || !yf_is_list($data['rows'])) {
        throw new InvalidArgumentException('rows 必须是数组');
    }
    if (count($data['rows']) > YF_ROWS_MAX) {
        throw new InvalidArgumentException('单次最多 ' . YF_ROWS_MAX . ' 行');
    }
    $batch = isset($data['batch_id']) ? trim((string)$data['batch_id']) : '';
    if ($batch === '') {
        $batch = 'srv' . date('YmdHis') . bin2hex(random_bytes(4));
    }
    if (!preg_match('/^[A-Za-z0-9_.:-]{1,' . YF_BATCH_MAX . '}$/', $batch)) {
        throw new InvalidArgumentException('batch_id 只能包含字母、数字和 _ . : -，最长 64');
    }
    $rows = array();
    foreach ($data['rows'] as $index => $row) {
        if (!is_array($row)) {
            throw new InvalidArgumentException('第 ' . ($index + 1) . ' 行不是对象');
        }
        $rows[] = yf_normalize_row($type, $row, (int)$index);
    }
    $out = array('type' => $type, 'batch_id' => $batch, 'rows' => $rows);
    if ($type === 'feature') {
        if (!isset($data['items']) || !is_array($data['items']) || !yf_is_list($data['items'])) {
            throw new InvalidArgumentException('feature 批次必须带 items 数组');
        }
        if (count($data['items']) > YF_ROWS_MAX) {
            throw new InvalidArgumentException('items 单次最多 ' . YF_ROWS_MAX . ' 个品号');
        }
        $items = array();
        foreach ($data['items'] as $index => $code) {
            $items[] = yf_text((string)$code, YF_CODE_MAX, 'items 第 ' . ($index + 1) . ' 个品号', true);
        }
        $out['items'] = array_values(array_unique($items));
    }
    return $out;
}

function yf_optional_decimal_key(array $row, array &$out, string $key, string $field, ?string $nullDefault = null): void
{
    if (!array_key_exists($key, $row)) {
        return;
    }
    $raw = $row[$key];
    if ($raw === null || $raw === '') {
        if ($nullDefault === null) {
            $out[$key] = null;
            return;
        }
        $raw = $nullDefault;
    }
    $out[$key] = yf_decimal_in($raw, $field);
}

function yf_normalize_row(string $type, array $row, int $index): array
{
    $at = '第 ' . ($index + 1) . ' 行';
    if ($type === 'invmb') {
        $out = array(
            'item_code' => yf_text((string)($row['item_code'] ?? ''), YF_CODE_MAX, $at . '品号', true),
            'item_name' => yf_text((string)($row['item_name'] ?? ''), YF_NAME_MAX, $at . '品名', false, true),
            'spec' => yf_text((string)($row['spec'] ?? ''), YF_SPEC_MAX, $at . '规格', false, true),
        );
        if (array_key_exists('item_attr', $row)) {
            $out['item_attr'] = yf_text((string)($row['item_attr'] ?? ''), 4, $at . '品号属性', false);
        }
        foreach (array(
            'latest_purchase' => '最近进价',
            'std_material' => '单位标准材料成本',
            'std_labor' => '单位标准人工成本',
            'std_mfg' => '单位标准制造费用',
            'std_subcontract' => '单位标准加工费用',
            'level_labor' => '本阶人工',
            'level_mfg' => '本阶制费',
            'level_process' => '本阶加工',
            'hour_base' => '工时底数',
        ) as $key => $label) {
            yf_optional_decimal_key($row, $out, $key, $at . $label);
        }
        return $out;
    }
    if ($type === 'bommd') {
        $out = array(
            'parent_code' => yf_text((string)($row['parent_code'] ?? ''), YF_CODE_MAX, $at . '主件', true),
            'component_code' => yf_text((string)($row['component_code'] ?? ''), YF_CODE_MAX, $at . '元件', true),
            'qty' => yf_decimal_in($row['qty'] ?? '0', $at . '用量'),
        );
        yf_optional_decimal_key($row, $out, 'base_qty', $at . '底数', '1');
        yf_optional_decimal_key($row, $out, 'scrap_rate', $at . '损耗率', '0');
        return $out;
    }
    if ($type === 'feature') {
        $amount = yf_decimal_in($row['amount'] ?? '0', $at . '特征金额');
        if (yf_decimal_is_zero($amount)) {
            throw new InvalidArgumentException($at . '特征金额为 0 时不推送');
        }
        $sort = isset($row['sort_no']) ? (int)$row['sort_no'] : 0;
        if ($sort < 0 || $sort > 999999) {
            throw new InvalidArgumentException($at . 'sort_no 超出范围');
        }
        return array(
            'item_code' => yf_text((string)($row['item_code'] ?? ''), YF_CODE_MAX, $at . '品号', true),
            'feature_code' => yf_text((string)($row['feature_code'] ?? ''), 40, $at . '特征代号', true),
            'feature_name' => yf_text((string)($row['feature_name'] ?? ''), YF_NAME_MAX, $at . '特征名称', false, true),
            'amount' => $amount,
            'sort_no' => $sort,
        );
    }
    $period = yf_text((string)($row['period'] ?? ''), YF_PERIOD_MAX, $at . '期间', true);
    if (!preg_match('/^[0-9A-Za-z_-]+$/', $period)) {
        throw new InvalidArgumentException($at . '期间格式不正确');
    }
    return array(
        'item_code' => yf_text((string)($row['item_code'] ?? ''), YF_CODE_MAX, $at . '品号', true),
        'period' => $period,
        'doc_no' => yf_text((string)($row['doc_no'] ?? ''), YF_DOC_MAX, $at . '单据号', false),
        'cost' => yf_decimal_in($row['cost'] ?? '0', $at . '成本'),
    );
}

function yf_tables_ready(PDO $pdo): bool
{
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'");
    } else {
        $stmt = $pdo->query('SHOW TABLES');
    }
    $have = array();
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
        $have[strtolower((string)$name)] = true;
    }
    foreach (array('yf_invmb', 'yf_bommd', 'yf_cstmb', 'yf_sync_batches', 'yf_item_feature') as $table) {
        if (empty($have[$table])) {
            return false;
        }
    }
    $inv = yf_table_columns($pdo, 'yf_invmb');
    $bom = yf_table_columns($pdo, 'yf_bommd');
    foreach (array('std_material', 'std_labor') as $col) {
        if (empty($inv[$col])) {
            return false;
        }
    }
    foreach (array('base_qty', 'scrap_rate') as $col) {
        if (empty($bom[$col])) {
            return false;
        }
    }
    return true;
}

function yf_log_batch(PDO $pdo, string $batch, string $type, int $received, int $upserted, int $skipped, string $status, string $message, string $ip): void
{
    $stmt = $pdo->prepare('INSERT INTO yf_sync_batches (batch_id, payload_type, row_count, upserted, skipped, status, message, remote_ip, created_at) VALUES (?,?,?,?,?,?,?,?,?)');
    $stmt->execute(array(
        $batch,
        $type,
        $received,
        $upserted,
        $skipped,
        $status,
        function_exists('mb_substr') ? mb_substr($message, 0, 500, 'UTF-8') : substr($message, 0, 500),
        substr($ip, 0, 64),
        date('Y-m-d H:i:s'),
    ));
}

function yf_apply(PDO $pdo, array $payload, string $ip): array
{
    $type = $payload['type'];
    $batch = $payload['batch_id'];
    $rows = $payload['rows'];
    $now = date('Y-m-d H:i:s');
    $pdo->beginTransaction();
    try {
        if ($type === 'invmb') {
            list($upserted, $deduped) = yf_apply_items($pdo, $rows, $batch, $now);
            $skipped = 0;
        } elseif ($type === 'bommd') {
            list($upserted, $deduped) = yf_apply_bom($pdo, $rows, $batch, $now);
            $skipped = 0;
        } elseif ($type === 'feature') {
            list($upserted, $deduped) = yf_apply_features($pdo, $payload, $batch, $now);
            $skipped = 0;
        } else {
            list($upserted, $skipped, $deduped) = yf_apply_costs($pdo, $rows, $batch, $now);
        }
        yf_log_batch($pdo, $batch, $type, count($rows), $upserted, $skipped, 'ok', '', $ip);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return array(
        'ok' => true,
        'type' => $type,
        'batch_id' => $batch,
        'received' => count($rows),
        'upserted' => $upserted,
        'skipped' => $skipped,
        'deduped' => $deduped,
    );
}

function yf_apply_features(PDO $pdo, array $payload, string $batch, string $now): array
{
    $rows = $payload['rows'];
    $items = $payload['items'] ?? array();
    if (!$items) {
        throw new InvalidArgumentException('feature 批次 items 不能为空');
    }
    $place = implode(',', array_fill(0, count($items), '?'));
    $pdo->prepare('DELETE FROM yf_item_feature WHERE item_code IN (' . $place . ')')->execute($items);
    $picked = array();
    foreach ($rows as $row) {
        $key = $row['item_code'] . "\n" . $row['feature_code'];
        $picked[$key] = $row;
    }
    foreach ($picked as $row) {
        yf_upsert($pdo, 'yf_item_feature', array('item_code' => true, 'feature_code' => true), array(
            'item_code' => $row['item_code'],
            'feature_code' => $row['feature_code'],
            'feature_name' => $row['feature_name'],
            'amount' => $row['amount'],
            'sort_no' => $row['sort_no'],
            'synced_at' => $now,
            'batch_id' => $batch,
        ));
    }
    return array(count($picked), count($rows) - count($picked));
}

function yf_features_for_codes(PDO $pdo, array $codes): array
{
    $codes = array_values(array_unique(array_filter($codes, function ($c) {
        return $c !== '';
    })));
    if (!$codes) {
        return array();
    }
    $out = array();
    foreach ($codes as $code) {
        $out[$code] = array();
    }
    $chunks = array_chunk($codes, 200);
    foreach ($chunks as $chunk) {
        $place = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = $pdo->prepare('SELECT item_code, feature_code, feature_name, amount, sort_no FROM yf_item_feature WHERE item_code IN (' . $place . ') ORDER BY item_code, sort_no, feature_code');
        $stmt->execute($chunk);
        foreach ($stmt as $row) {
            $code = (string)$row['item_code'];
            if (!isset($out[$code])) {
                $out[$code] = array();
            }
            $out[$code][] = array(
                'feature_code' => (string)$row['feature_code'],
                'feature_name' => (string)$row['feature_name'],
                'amount' => (string)$row['amount'],
                'sort_no' => (int)$row['sort_no'],
            );
        }
    }
    return $out;
}

function yf_feature_rows_sum(array $rows): ?string
{
    $sum = null;
    foreach ($rows as $row) {
        $amount = $row['amount'] ?? null;
        if ($amount === null || $amount === '') {
            continue;
        }
        $norm = yf_decimal_in((string)$amount, '特征金额');
        if (yf_decimal_is_zero($norm)) {
            continue;
        }
        $sum = $sum === null ? $norm : yf_decimal_add($sum, $norm);
    }
    return $sum;
}

/** MB059、MB060 任一为非零数值时，认为制费/加工已写入会计栏，不再加特征金额。 */
function yf_should_add_feature_costs(?array $item): bool
{
    if (!$item) {
        return false;
    }
    foreach (array('std_mfg', 'std_subcontract') as $key) {
        if (!array_key_exists($key, $item) || $item[$key] === null || $item[$key] === '') {
            continue;
        }
        if (!yf_decimal_is_zero(yf_decimal_in((string)$item[$key], $key))) {
            return false;
        }
    }
    return true;
}

function yf_apply_items(PDO $pdo, array $rows, string $batch, string $now): array
{
    $picked = array();
    foreach ($rows as $row) {
        $picked[$row['item_code']] = $row;
    }
    foreach ($picked as $row) {
        $save = $row;
        $save['synced_at'] = $now;
        $save['batch_id'] = $batch;
        yf_upsert($pdo, 'yf_invmb', array('item_code' => true), $save);
    }
    return array(count($picked), count($rows) - count($picked));
}

function yf_decimal_add(string $a, string $b): string
{
    if (function_exists('bcadd')) {
        return bcadd($a, $b, 6);
    }
    return yf_decimal_in((float)$a + (float)$b, '用量');
}

function yf_decimal_is_zero(string $value): bool
{
    if (function_exists('bccomp')) {
        return bccomp($value, '0', 6) === 0;
    }
    return (float)$value == 0.0;
}

function yf_decimal_cmp(string $a, string $b): int
{
    if (function_exists('bccomp')) {
        return bccomp($a, $b, 6);
    }
    return ((float)$a <=> (float)$b);
}

function yf_decimal_mul(string $a, string $b): string
{
    if (function_exists('bcmul')) {
        return bcmul($a, $b, 6);
    }
    return yf_decimal_in(((float)$a) * ((float)$b), '金额');
}

function yf_decimal_div(string $a, string $b): string
{
    if (yf_decimal_is_zero($b)) {
        throw new InvalidArgumentException('除数不能为 0');
    }
    if (function_exists('bcdiv')) {
        return bcdiv($a, $b, 6);
    }
    return yf_decimal_in(((float)$a) / ((float)$b), '金额');
}

function yf_effective_qty($qty, $base, $scrap): string
{
    $qtyNorm = yf_decimal_in($qty === null || $qty === '' ? '0' : $qty, '用量');
    if ($base === null || $base === '') {
        $baseNorm = '1.000000';
    } else {
        $baseNorm = yf_decimal_in($base, '底数');
        if (yf_decimal_is_zero($baseNorm)) {
            $baseNorm = '1.000000';
        }
    }
    $scrapNorm = ($scrap === null || $scrap === '') ? '0.000000' : yf_decimal_in($scrap, '损耗率');
    $factor = yf_decimal_add('1.000000', $scrapNorm);
    return yf_decimal_mul(yf_decimal_div($qtyNorm, $baseNorm), $factor);
}

function yf_extend_unit($unit, string $effective, string $label): array
{
    if ($unit === null || $unit === '') {
        return array('missing' => true, 'amount' => null, 'zero' => false);
    }
    $norm = yf_decimal_in((string)$unit, $label);
    return array(
        'missing' => false,
        'amount' => yf_decimal_mul($norm, $effective),
        'zero' => yf_decimal_is_zero($norm),
    );
}

function yf_sum_present(array $parts): ?string
{
    $sum = null;
    foreach ($parts as $part) {
        if (!empty($part['missing']) || $part['amount'] === null) {
            continue;
        }
        $sum = $sum === null ? (string)$part['amount'] : yf_decimal_add($sum, (string)$part['amount']);
    }
    return $sum;
}

function yf_line_material(array $child, array $featureRows = array()): array
{
    $effective = yf_effective_qty($child['qty'] ?? '0', $child['base_qty'] ?? null, $child['scrap_rate'] ?? null);
    $material = yf_extend_unit($child['std_material'] ?? null, $effective, '单位标准材料成本');
    $mfg = yf_extend_unit($child['std_mfg'] ?? null, $effective, '单位标准制费');
    $process = yf_extend_unit($child['std_subcontract'] ?? null, $effective, '单位标准加工');
    $featureExt = null;
    if (yf_should_add_feature_costs($child) && $featureRows) {
        $featureUnit = yf_feature_rows_sum($featureRows);
        if ($featureUnit !== null) {
            $featureExt = yf_decimal_mul($featureUnit, $effective);
        }
    }
    $baseAmount = yf_sum_present(array($material, $mfg, $process));
    $amount = $baseAmount;
    if ($featureExt !== null) {
        $amount = $amount === null ? $featureExt : yf_decimal_add($amount, $featureExt);
    }
    return array(
        'effective_qty' => $effective,
        'material_cost' => $material['amount'],
        'mfg_cost' => $mfg['amount'],
        'process_cost' => $process['amount'],
        'feature_cost' => $featureExt,
        'amount' => $amount,
        'std_missing' => $material['missing'],
        'mfg_missing' => $mfg['missing'],
        'process_missing' => $process['missing'],
        'std_zero' => $material['zero'],
        'mfg_zero' => $mfg['zero'],
        'process_zero' => $process['zero'],
    );
}

function yf_rollup_bucket(array $lines, string $amountKey, string $missingKey, string $zeroKey): array
{
    $sum = null;
    $missing = 0;
    $zero = 0;
    foreach ($lines as $line) {
        if (!empty($line[$missingKey])) {
            $missing++;
            continue;
        }
        if (!empty($line[$zeroKey])) {
            $zero++;
        }
        $amount = $line[$amountKey] ?? null;
        if ($amount === null) {
            continue;
        }
        $sum = $sum === null ? (string)$amount : yf_decimal_add($sum, (string)$amount);
    }
    return array('total' => $sum, 'missing' => $missing, 'zero' => $zero);
}

function yf_material_rollup(array $children, array $featuresByCode = array()): array
{
    $phantom = 0;
    $lines = array();
    foreach ($children as $child) {
        $code = (string)($child['component_code'] ?? '');
        $feat = $featuresByCode[$code] ?? array();
        $line = yf_line_material($child, $feat);
        $lines[] = $line;
        $attr = strtoupper(trim((string)($child['item_attr'] ?? '')));
        if ($attr === 'Y') {
            $phantom++;
        }
    }
    $material = yf_rollup_bucket($lines, 'material_cost', 'std_missing', 'std_zero');
    $mfg = yf_rollup_bucket($lines, 'mfg_cost', 'mfg_missing', 'mfg_zero');
    $process = yf_rollup_bucket($lines, 'process_cost', 'process_missing', 'process_zero');
    $amount = null;
    foreach ($lines as $line) {
        if ($line['amount'] === null) {
            continue;
        }
        $amount = $amount === null ? (string)$line['amount'] : yf_decimal_add($amount, (string)$line['amount']);
    }
    return array(
        'lines' => $lines,
        'material_total' => $material['total'] ?? '0.000000',
        'mfg_total' => $mfg['total'],
        'process_total' => $process['total'],
        'amount_total' => $amount,
        'missing_std' => $material['missing'],
        'missing_mfg' => $mfg['missing'],
        'missing_process' => $process['missing'],
        'zero_std' => $material['zero'],
        'zero_mfg' => $mfg['zero'],
        'zero_process' => $process['zero'],
        'phantom' => $phantom,
    );
}

function yf_std_total(?array $item, array $featureRows = array()): array
{
    $parts = array(
        'std_material' => '材料',
        'std_mfg' => '制费',
        'std_subcontract' => '加工',
    );
    $values = array('材料' => null, '制费' => null, '加工' => null);
    if (!$item) {
        return array(
            'total' => null,
            'present' => array(),
            'absent' => array_values($parts),
            'parts' => $values,
            'labor' => null,
            'feature_total' => null,
            'features_applied' => false,
            'feature_lines' => array(),
        );
    }
    $sum = '0.000000';
    $present = array();
    $absent = array();
    foreach ($parts as $key => $label) {
        if (!array_key_exists($key, $item) || $item[$key] === null || $item[$key] === '') {
            $absent[] = $label;
            continue;
        }
        $present[] = $label;
        $norm = yf_decimal_in((string)$item[$key], $label);
        $values[$label] = $norm;
        $sum = yf_decimal_add($sum, $norm);
    }
    $labor = null;
    if (array_key_exists('std_labor', $item) && $item['std_labor'] !== null && $item['std_labor'] !== '') {
        $labor = yf_decimal_in((string)$item['std_labor'], '人工');
    }
    $featureTotal = null;
    $featuresApplied = false;
    if (yf_should_add_feature_costs($item) && $featureRows) {
        $featureTotal = yf_feature_rows_sum($featureRows);
        if ($featureTotal !== null) {
            $featuresApplied = true;
            if ($present) {
                $sum = yf_decimal_add($sum, $featureTotal);
            } else {
                $sum = $featureTotal;
                $present = array('特征');
            }
        }
    }
    return array(
        'total' => $present ? $sum : null,
        'present' => $present,
        'absent' => $absent,
        'parts' => $values,
        'labor' => $labor,
        'feature_total' => $featureTotal,
        'features_applied' => $featuresApplied,
        'feature_lines' => $featuresApplied ? $featureRows : array(),
    );
}

function yf_apply_bom(PDO $pdo, array $rows, string $batch, string $now): array
{
    $picked = array();
    foreach ($rows as $row) {
        $key = $row['parent_code'] . "\n" . $row['component_code'];
        if (!isset($picked[$key])) {
            $picked[$key] = $row;
            continue;
        }
        $picked[$key]['qty'] = yf_decimal_add($picked[$key]['qty'], $row['qty']);
        foreach (array('base_qty', 'scrap_rate') as $extra) {
            if (!array_key_exists($extra, $picked[$key]) && array_key_exists($extra, $row)) {
                $picked[$key][$extra] = $row[$extra];
            }
        }
    }
    foreach ($picked as $row) {
        $save = $row;
        $save['synced_at'] = $now;
        $save['batch_id'] = $batch;
        yf_upsert($pdo, 'yf_bommd', array('parent_code' => true, 'component_code' => true), $save);
    }
    return array(count($picked), count($rows) - count($picked));
}

function yf_apply_costs(PDO $pdo, array $rows, string $batch, string $now): array
{
    $picked = array();
    foreach ($rows as $row) {
        $code = $row['item_code'];
        if (!isset($picked[$code]) || yf_period_cmp($row['period'], $picked[$code]['period']) >= 0) {
            $picked[$code] = $row;
        }
    }
    $existing = array();
    if ($picked) {
        $place = implode(',', array_fill(0, count($picked), '?'));
        $stmt = $pdo->prepare('SELECT item_code, period FROM yf_cstmb WHERE item_code IN (' . $place . ')');
        $stmt->execute(array_keys($picked));
        foreach ($stmt as $old) {
            $existing[$old['item_code']] = (string)$old['period'];
        }
    }
    $upserted = 0;
    $skipped = 0;
    foreach ($picked as $code => $row) {
        if (isset($existing[$code]) && yf_period_cmp($row['period'], $existing[$code]) < 0) {
            $skipped++;
            continue;
        }
        yf_upsert($pdo, 'yf_cstmb', array('item_code' => true), array(
            'item_code' => $code,
            'period' => $row['period'],
            'doc_no' => $row['doc_no'],
            'cost' => $row['cost'],
            'synced_at' => $now,
            'batch_id' => $batch,
        ));
        $upserted++;
    }
    return array($upserted, $skipped, count($rows) - count($picked));
}

function yf_like(string $q): string
{
    $q = str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $q);
    return '%' . $q . '%';
}

function yf_like_escape_sql(PDO $pdo): string
{
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        return " ESCAPE '\\'";
    }
    return " ESCAPE '\\\\'";
}

function yf_mirror_synced_at(PDO $pdo): string
{
    $stmt = $pdo->query('SELECT MAX(synced_at) FROM (
        SELECT synced_at FROM yf_invmb
        UNION ALL SELECT synced_at FROM yf_bommd
        UNION ALL SELECT synced_at FROM yf_cstmb
        UNION ALL SELECT synced_at FROM yf_item_feature
    ) AS yf_times');
    $value = $stmt->fetchColumn();
    return $value ? (string)$value : '';
}

function yf_search(PDO $pdo, string $q, int $limit = 50): array
{
    $limit = max(1, min(50, $limit));
    if (!yf_tables_ready($pdo)) {
        return array('ready' => false, 'rows' => array());
    }
    $q = trim($q);
    if ($q === '') {
        $sql = 'SELECT i.item_code, i.item_name, i.spec, i.std_material, i.std_mfg, i.std_subcontract, i.synced_at AS item_synced_at,
                c.cost, c.period, c.doc_no, c.synced_at AS cost_synced_at
            FROM yf_invmb i
            LEFT JOIN yf_cstmb c ON c.item_code = i.item_code
            ORDER BY i.synced_at DESC, i.item_code
            LIMIT ' . $limit;
        $rows = $pdo->query($sql)->fetchAll();
        return yf_search_attach_features($pdo, array('ready' => true, 'rows' => $rows));
    }
    $like = yf_like($q);
    $escape = yf_like_escape_sql($pdo);
    // 只读 yf_invmb：品号 MB001、品名 MB002、规格 MB003 均可模糊匹配；不依赖 BOM 主件/子件关系。
    $sql = 'SELECT i.item_code,
            COALESCE(i.item_name, \'\') AS item_name,
            COALESCE(i.spec, \'\') AS spec,
            i.std_material, i.std_mfg, i.std_subcontract,
            i.synced_at AS item_synced_at,
            c.cost, c.period, c.doc_no, c.synced_at AS cost_synced_at
        FROM yf_invmb i
        LEFT JOIN yf_cstmb c ON c.item_code = i.item_code
        WHERE i.item_code LIKE ?' . $escape . '
            OR i.item_name LIKE ?' . $escape . '
            OR i.spec LIKE ?' . $escape . '
        ORDER BY CASE
            WHEN i.item_code = ? THEN 0
            WHEN i.item_name = ? THEN 1
            WHEN i.item_code LIKE ?' . $escape . ' THEN 2
            WHEN i.item_name LIKE ?' . $escape . ' THEN 3
            WHEN i.spec LIKE ?' . $escape . ' THEN 4
            ELSE 5
        END, i.item_name, i.item_code
        LIMIT ' . $limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array($like, $like, $like, $q, $q, $like, $like, $like));
    return yf_search_attach_features($pdo, array('ready' => true, 'rows' => $stmt->fetchAll()));
}

function yf_search_attach_features(PDO $pdo, array $result): array
{
    $rows = $result['rows'] ?? array();
    if (!$rows) {
        return $result;
    }
    $codes = array();
    foreach ($rows as $row) {
        $codes[] = (string)$row['item_code'];
    }
    $map = yf_features_for_codes($pdo, $codes);
    foreach ($rows as $i => $row) {
        $code = (string)$row['item_code'];
        $rows[$i]['feature_rows'] = $map[$code] ?? array();
    }
    $result['rows'] = $rows;
    return $result;
}

function yf_detail(PDO $pdo, string $code): ?array
{
    if (!yf_tables_ready($pdo) || $code === '') {
        return null;
    }
    $item = $pdo->prepare('SELECT item_code, item_name, spec, item_attr, latest_purchase, std_material, std_labor, std_mfg, std_subcontract, level_labor, level_mfg, level_process, hour_base, synced_at, batch_id FROM yf_invmb WHERE item_code = ?');
    $item->execute(array($code));
    $itemRow = $item->fetch() ?: null;
    $cost = $pdo->prepare('SELECT item_code, period, doc_no, cost, synced_at, batch_id FROM yf_cstmb WHERE item_code = ?');
    $cost->execute(array($code));
    $costRow = $cost->fetch() ?: null;
    $childrenStmt = $pdo->prepare('SELECT b.component_code, b.qty, b.base_qty, b.scrap_rate, b.synced_at,
            COALESCE(i.item_name, \'\') AS item_name,
            COALESCE(i.spec, \'\') AS spec,
            i.item_attr,
            i.latest_purchase, i.std_material, i.std_labor, i.std_mfg, i.std_subcontract,
            i.level_labor, i.level_mfg, i.level_process,
            c.cost, c.period
        FROM yf_bommd b
        LEFT JOIN yf_invmb i ON i.item_code = b.component_code
        LEFT JOIN yf_cstmb c ON c.item_code = b.component_code
        WHERE b.parent_code = ?
        ORDER BY b.component_code');
    $childrenStmt->execute(array($code));
    $children = $childrenStmt->fetchAll();
    $parentsStmt = $pdo->prepare('SELECT b.parent_code, b.qty, b.synced_at, COALESCE(i.item_name, \'\') AS item_name
        FROM yf_bommd b
        LEFT JOIN yf_invmb i ON i.item_code = b.parent_code
        WHERE b.component_code = ?
        ORDER BY b.parent_code');
    $parentsStmt->execute(array($code));
    $parents = $parentsStmt->fetchAll();
    if (!$itemRow && !$costRow && !$children && !$parents) {
        return null;
    }
    $times = array();
    if ($itemRow) {
        $times[] = (string)$itemRow['synced_at'];
    }
    if ($costRow) {
        $times[] = (string)$costRow['synced_at'];
    }
    foreach ($children as $child) {
        $times[] = (string)$child['synced_at'];
    }
    $times = array_filter($times, function ($value) {
        return $value !== '';
    });
    rsort($times);
    $featureCodes = array($code);
    foreach ($children as $child) {
        $featureCodes[] = (string)$child['component_code'];
    }
    $featuresByCode = yf_features_for_codes($pdo, $featureCodes);
    $itemFeatures = $featuresByCode[$code] ?? array();
    $featTime = $pdo->prepare('SELECT MAX(synced_at) FROM yf_item_feature WHERE item_code = ?');
    $featTime->execute(array($code));
    $featSynced = $featTime->fetchColumn();
    if ($featSynced) {
        $times[] = (string)$featSynced;
    }
    $rollup = yf_material_rollup($children, $featuresByCode);
    foreach ($children as $i => $_child) {
        foreach (array('effective_qty', 'material_cost', 'mfg_cost', 'process_cost', 'feature_cost', 'amount', 'std_missing', 'mfg_missing', 'process_missing') as $key) {
            if (array_key_exists($key, $rollup['lines'][$i])) {
                $children[$i][$key] = $rollup['lines'][$i][$key];
            }
        }
        $cc = (string)$_child['component_code'];
        $children[$i]['feature_rows'] = $featuresByCode[$cc] ?? array();
    }
    return array(
        'code' => $code,
        'item' => $itemRow,
        'cost' => $costRow,
        'children' => $children,
        'parents' => $parents,
        'features' => $itemFeatures,
        'synced_at' => $times ? $times[0] : '',
        'rollup' => array(
            'material_total' => $rollup['material_total'],
            'mfg_total' => $rollup['mfg_total'],
            'process_total' => $rollup['process_total'],
            'amount_total' => $rollup['amount_total'],
            'missing_std' => $rollup['missing_std'],
            'missing_mfg' => $rollup['missing_mfg'],
            'missing_process' => $rollup['missing_process'],
            'zero_std' => $rollup['zero_std'],
            'zero_mfg' => $rollup['zero_mfg'],
            'zero_process' => $rollup['zero_process'],
            'phantom' => $rollup['phantom'],
        ),
        'std_total' => yf_std_total($itemRow, $itemFeatures),
    );
}

/**
 * BOM 选料临时桥：把 yf_search 行映射为前端可带入 BOM 行的字段（只读镜像，不写主数据）。
 */
function yf_bom_picker_item(array $row, bool $canCost): array
{
    $code = trim((string)($row['item_code'] ?? ''));
    $name = (string)($row['item_name'] ?? '');
    $spec = (string)($row['spec'] ?? '');
    $out = array(
        'item_code' => $code,
        'item_name' => $name,
        'spec' => $spec,
        'source' => 'yifei_invmb',
    );
    if (!$canCost) {
        return $out;
    }
    $features = is_array($row['feature_rows'] ?? null) ? $row['feature_rows'] : array();
    $item = array(
        'std_material' => $row['std_material'] ?? null,
        'std_mfg' => $row['std_mfg'] ?? null,
        'std_subcontract' => $row['std_subcontract'] ?? null,
        'std_labor' => $row['std_labor'] ?? null,
    );
    $std = yf_std_total($item, $features);
    if ($item['std_material'] !== null && $item['std_material'] !== '') {
        $out['unit_material'] = (float)yf_decimal_in((string)$item['std_material'], '单位标准材料');
    }
    $process = 0.0;
    $hasProcess = false;
    foreach (array('std_mfg' => '制费', 'std_subcontract' => '加工') as $key => $label) {
        if ($item[$key] === null || $item[$key] === '') {
            continue;
        }
        $part = (float)yf_decimal_in((string)$item[$key], $label);
        if ($part > 0) {
            $process += $part;
            $hasProcess = true;
        }
    }
    if ($hasProcess) {
        $out['unit_process'] = round($process, 4);
    }
    if ($std['total'] !== null && $std['total'] !== '') {
        $out['cost_amount'] = (float)$std['total'];
    }
    return $out;
}
