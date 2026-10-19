<?php
declare(strict_types=1);

/**
 * 易飞（鼎捷 SQL Server 库 AT）只读镜像。
 * 广州只接收本地代理推上来的数据，不连接易飞，也不写回易飞。
 *
 * 已核实字段：
 *   INVMB  MB001 品号，MB002 品名，MB003 规格
 *   BOMMD  MD001 主件，MD003 元件，MD006 用量
 *   CSTMB  MB002 期间，MB004 单据号（不是品号），MB007 品号，MB005 成本
 * 不使用 CSTLA、CSTMC。char 尾部空格在入库时按 RTRIM 去掉。
 */

const YF_CODE_MAX = 40;
const YF_NAME_MAX = 255;
const YF_SPEC_MAX = 500;
const YF_PERIOD_MAX = 20;
const YF_DOC_MAX = 40;
const YF_BATCH_MAX = 64;
const YF_ROWS_MAX = 500;
const YF_BODY_MAX = 2097152;

function yf_mysql_statements(): array
{
    $engine = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return array(
        "CREATE TABLE IF NOT EXISTS yf_invmb (
            item_code VARCHAR(40) NOT NULL COMMENT 'INVMB.MB001 品号',
            item_name VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'INVMB.MB002 品名',
            spec VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'INVMB.MB003 规格',
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
    return array(
        'CREATE TABLE IF NOT EXISTS yf_invmb (
            item_code TEXT PRIMARY KEY,
            item_name TEXT NOT NULL DEFAULT \'\',
            spec TEXT NOT NULL DEFAULT \'\',
            synced_at TEXT NOT NULL,
            batch_id TEXT NOT NULL DEFAULT \'\'
        )',
        'CREATE TABLE IF NOT EXISTS yf_bommd (
            parent_code TEXT NOT NULL,
            component_code TEXT NOT NULL,
            qty TEXT NOT NULL DEFAULT \'0\',
            synced_at TEXT NOT NULL,
            batch_id TEXT NOT NULL DEFAULT \'\',
            PRIMARY KEY (parent_code, component_code)
        )',
        'CREATE TABLE IF NOT EXISTS yf_cstmb (
            item_code TEXT PRIMARY KEY,
            period TEXT NOT NULL DEFAULT \'\',
            doc_no TEXT NOT NULL DEFAULT \'\',
            cost TEXT NOT NULL DEFAULT \'0\',
            synced_at TEXT NOT NULL,
            batch_id TEXT NOT NULL DEFAULT \'\'
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

function yf_text(string $value, int $max, string $field, bool $required): string
{
    $value = str_replace("\0", '', $value);
    if (preg_match('/[\r\n]/', $value)) {
        throw new InvalidArgumentException($field . ' 不能包含换行');
    }
    $value = trim($value);
    if ($required && $value === '') {
        throw new InvalidArgumentException($field . ' 不能为空');
    }
    $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
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
    if (!preg_match('/^\d+(\.\d+)?$/', $value)) {
        throw new InvalidArgumentException($field . ' 必须是非负数字');
    }
    $parts = explode('.', $value, 2);
    if (strlen($parts[0]) > 12) {
        throw new InvalidArgumentException($field . ' 整数位过长');
    }
    $frac = $parts[1] ?? '';
    if (strlen($frac) > 6) {
        throw new InvalidArgumentException($field . ' 最多 6 位小数');
    }
    return $parts[0] . '.' . str_pad($frac, 6, '0');
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
    if (!in_array($type, array('invmb', 'bommd', 'cstmb'), true)) {
        throw new InvalidArgumentException('type 只能是 invmb、bommd 或 cstmb');
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
    return array('type' => $type, 'batch_id' => $batch, 'rows' => $rows);
}

function yf_normalize_row(string $type, array $row, int $index): array
{
    $at = '第 ' . ($index + 1) . ' 行';
    if ($type === 'invmb') {
        return array(
            'item_code' => yf_text((string)($row['item_code'] ?? ''), YF_CODE_MAX, $at . '品号', true),
            'item_name' => yf_text((string)($row['item_name'] ?? ''), YF_NAME_MAX, $at . '品名', false),
            'spec' => yf_text((string)($row['spec'] ?? ''), YF_SPEC_MAX, $at . '规格', false),
        );
    }
    if ($type === 'bommd') {
        return array(
            'parent_code' => yf_text((string)($row['parent_code'] ?? ''), YF_CODE_MAX, $at . '主件', true),
            'component_code' => yf_text((string)($row['component_code'] ?? ''), YF_CODE_MAX, $at . '元件', true),
            'qty' => yf_decimal_in($row['qty'] ?? '0', $at . '用量'),
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
    foreach (array('yf_invmb', 'yf_bommd', 'yf_cstmb', 'yf_sync_batches') as $table) {
        if (empty($have[$table])) {
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

function yf_apply_items(PDO $pdo, array $rows, string $batch, string $now): array
{
    $picked = array();
    foreach ($rows as $row) {
        $picked[$row['item_code']] = $row;
    }
    foreach ($picked as $row) {
        yf_upsert($pdo, 'yf_invmb', array('item_code' => true), array(
            'item_code' => $row['item_code'],
            'item_name' => $row['item_name'],
            'spec' => $row['spec'],
            'synced_at' => $now,
            'batch_id' => $batch,
        ));
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
    }
    foreach ($picked as $row) {
        yf_upsert($pdo, 'yf_bommd', array('parent_code' => true, 'component_code' => true), array(
            'parent_code' => $row['parent_code'],
            'component_code' => $row['component_code'],
            'qty' => $row['qty'],
            'synced_at' => $now,
            'batch_id' => $batch,
        ));
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
        $sql = 'SELECT i.item_code, i.item_name, i.spec, i.synced_at AS item_synced_at,
                c.cost, c.period, c.doc_no, c.synced_at AS cost_synced_at
            FROM yf_invmb i
            LEFT JOIN yf_cstmb c ON c.item_code = i.item_code
            ORDER BY i.synced_at DESC, i.item_code
            LIMIT ' . $limit;
        $rows = $pdo->query($sql)->fetchAll();
        return array('ready' => true, 'rows' => $rows);
    }
    $like = yf_like($q);
    $escape = yf_like_escape_sql($pdo);
    $sql = 'SELECT u.item_code,
            COALESCE(i.item_name, \'\') AS item_name,
            COALESCE(i.spec, \'\') AS spec,
            i.synced_at AS item_synced_at,
            c.cost, c.period, c.doc_no, c.synced_at AS cost_synced_at
        FROM (
            SELECT item_code FROM yf_invmb WHERE item_code LIKE ?' . $escape . ' OR item_name LIKE ?' . $escape . '
            UNION
            SELECT item_code FROM yf_cstmb WHERE item_code LIKE ?' . $escape . '
        ) u
        LEFT JOIN yf_invmb i ON i.item_code = u.item_code
        LEFT JOIN yf_cstmb c ON c.item_code = u.item_code
        ORDER BY CASE WHEN u.item_code = ? THEN 0 ELSE 1 END, u.item_code
        LIMIT ' . $limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array($like, $like, $like, $q));
    return array('ready' => true, 'rows' => $stmt->fetchAll());
}

function yf_detail(PDO $pdo, string $code): ?array
{
    if (!yf_tables_ready($pdo) || $code === '') {
        return null;
    }
    $item = $pdo->prepare('SELECT item_code, item_name, spec, synced_at, batch_id FROM yf_invmb WHERE item_code = ?');
    $item->execute(array($code));
    $itemRow = $item->fetch() ?: null;
    $cost = $pdo->prepare('SELECT item_code, period, doc_no, cost, synced_at, batch_id FROM yf_cstmb WHERE item_code = ?');
    $cost->execute(array($code));
    $costRow = $cost->fetch() ?: null;
    $childrenStmt = $pdo->prepare('SELECT b.component_code, b.qty, b.synced_at,
            COALESCE(i.item_name, \'\') AS item_name,
            COALESCE(i.spec, \'\') AS spec,
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
    return array(
        'code' => $code,
        'item' => $itemRow,
        'cost' => $costRow,
        'children' => $children,
        'parents' => $parents,
        'synced_at' => $times ? $times[0] : '',
    );
}
