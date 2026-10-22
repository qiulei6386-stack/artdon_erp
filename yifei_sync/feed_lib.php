<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/feed_brand.php';

/** 新加坡店固定出口 IP（可用环境变量 YIFEI_FEED_ALLOWED_IPS 覆盖，逗号分隔，仅联调/契约测试）。 */
function yf_feed_allowed_ips(): array
{
    $env = getenv('YIFEI_FEED_ALLOWED_IPS');
    if (is_string($env) && trim($env) !== '') {
        $parts = preg_split('/\s*,\s*/', trim($env));
        $ips = array();
        foreach ($parts as $part) {
            if ($part !== '') {
                $ips[] = $part;
            }
        }
        return $ips !== array() ? $ips : array('43.160.202.212');
    }
    return array('43.160.202.212');
}

function yf_feed_secret_file(): string
{
    $env = getenv('YIFEI_FEED_SECRET_FILE');
    if (is_string($env) && $env !== '') {
        return $env;
    }
    return '/www/server/artdon_secrets/yifei_feed.php';
}

function yf_feed_configured_token(): string
{
    $env = getenv('YIFEI_FEED_TOKEN');
    if (is_string($env) && $env !== '') {
        return $env;
    }
    foreach (array(yf_feed_secret_file(), __DIR__ . '/feed.config.local.php') as $path) {
        $cfg = yf_read_php_config($path);
        if (isset($cfg['token']) && is_string($cfg['token']) && $cfg['token'] !== '') {
            return $cfg['token'];
        }
    }
    return '';
}

function yf_feed_request_token(array $server): string
{
    if (!empty($server['HTTP_X_FEED_TOKEN']) && is_string($server['HTTP_X_FEED_TOKEN'])) {
        return trim($server['HTTP_X_FEED_TOKEN']);
    }
    return '';
}

/**
 * 客户端 IP：与站点其它模块一致，优先信任前置代理写入的头，再 REMOTE_ADDR。
 *
 * 顺序：HTTP_CF_CONNECTING_IP → HTTP_X_REAL_IP（Nginx real_ip）→
 * HTTP_X_FORWARDED_FOR 第一个地址 → REMOTE_ADDR。
 */
function yf_feed_client_ip(array $server): string
{
    foreach (array('HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR') as $key) {
        if (empty($server[$key]) || !is_string($server[$key])) {
            continue;
        }
        $raw = trim(explode(',', $server[$key])[0]);
        if ($raw !== '') {
            return substr($raw, 0, 80);
        }
    }
    return '';
}

function yf_feed_authorize(array $server): void
{
    $ip = yf_feed_client_ip($server);
    if (!in_array($ip, yf_feed_allowed_ips(), true)) {
        throw new RuntimeException('FORBIDDEN_IP');
    }
    $expected = yf_feed_configured_token();
    if (!yf_token_usable($expected)) {
        throw new RuntimeException('TOKEN_NOT_CONFIGURED');
    }
    $presented = yf_feed_request_token($server);
    if (!hash_equals(hash('sha256', $expected), hash('sha256', $presented))) {
        throw new RuntimeException('UNAUTHORIZED');
    }
}

/** power 类「线材」排除：品名含下列任一词则不视为电源物料。 */
function yf_feed_power_wire_exclusions(): array
{
    return array('电线', '线材', '电缆', '电源线');
}

function yf_feed_is_power_name(string $itemName): bool
{
    if (strpos($itemName, '电源') === false) {
        return false;
    }
    foreach (yf_feed_power_wire_exclusions() as $bad) {
        if ($bad !== '' && strpos($itemName, $bad) !== false) {
            return false;
        }
    }
    return true;
}

function yf_feed_resolve_category(string $itemCode, string $itemName): ?string
{
    if (preg_match('/^A5-1-/i', $itemCode)) {
        return 'chip';
    }
    if (preg_match('/^A5-2-/i', $itemCode)) {
        return 'driver';
    }
    if (yf_feed_is_power_name($itemName)) {
        return 'power';
    }
    return null;
}

/**
 * @return array{sql:string,params:array<string,mixed>}
 */
function yf_feed_category_sql(string $category): array
{
    $category = strtolower(trim($category));
    if ($category === 'chip') {
        return array('sql' => 'item_code LIKE :cat_chip', 'params' => array(':cat_chip' => 'A5-1-%'));
    }
    if ($category === 'driver') {
        return array('sql' => 'item_code LIKE :cat_driver', 'params' => array(':cat_driver' => 'A5-2-%'));
    }
    if ($category === 'power') {
        $parts = array(
            "item_code NOT LIKE 'A5-1-%'",
            "item_code NOT LIKE 'A5-2-%'",
            "item_name LIKE :pwr_needle",
        );
        $params = array(':pwr_needle' => '%电源%');
        foreach (yf_feed_power_wire_exclusions() as $i => $bad) {
            $key = ':pwr_ex' . $i;
            $parts[] = 'item_name NOT LIKE ' . $key;
            $params[$key] = '%' . $bad . '%';
        }
        return array('sql' => '(' . implode(' AND ', $parts) . ')', 'params' => $params);
    }
    throw new InvalidArgumentException('category 只能是 chip、driver 或 power');
}

/**
 * 任一 feed 品类（chip / driver / power）。
 *
 * @return array{sql:string,params:array<string,mixed>}
 */
function yf_feed_any_category_sql(): array
{
    $chip = yf_feed_category_sql('chip');
    $driver = yf_feed_category_sql('driver');
    $power = yf_feed_category_sql('power');
    $sql = '(' . $chip['sql'] . ' OR ' . $driver['sql'] . ' OR ' . $power['sql'] . ')';
    $params = array_merge($chip['params'], $driver['params'], $power['params']);
    return array('sql' => $sql, 'params' => $params);
}

/**
 * @param array<string,mixed> $query
 * @return array{ok:true,data:list<array<string,mixed>>,meta:array{page:int,per_page:int,total:int}}
 */
function yf_feed_query(PDO $pdo, array $query): array
{
    $page = isset($query['page']) ? (int)$query['page'] : 1;
    $perPage = isset($query['per_page']) ? (int)$query['per_page'] : 50;
    if ($page < 1) {
        $page = 1;
    }
    if ($perPage < 1) {
        $perPage = 50;
    }
    if ($perPage > 200) {
        $perPage = 200;
    }

    $where = array();
    $params = array();

    $category = isset($query['category']) ? trim((string)$query['category']) : '';
    if ($category !== '') {
        $cat = yf_feed_category_sql($category);
        $where[] = $cat['sql'];
        $params = array_merge($params, $cat['params']);
    } else {
        $any = yf_feed_any_category_sql();
        $where[] = $any['sql'];
        $params = array_merge($params, $any['params']);
    }

    $q = isset($query['q']) ? trim((string)$query['q']) : '';
    if ($q !== '') {
        $where[] = '(item_code LIKE :q OR item_name LIKE :q OR spec LIKE :q)';
        $params[':q'] = '%' . $q . '%';
    }

    $brand = isset($query['brand']) ? trim((string)$query['brand']) : '';
    if ($brand !== '') {
        $bf = yf_feed_brand_sql_filter($brand);
        if ($bf === null) {
            return array(
                'ok' => true,
                'data' => array(),
                'meta' => array('page' => $page, 'per_page' => $perPage, 'total' => 0),
            );
        }
        $where[] = $bf['sql'];
        $params = array_merge($params, $bf['params']);
    }

    $since = isset($query['since']) ? trim((string)$query['since']) : '';
    if ($since !== '') {
        $ts = strtotime($since);
        if ($ts === false) {
            throw new InvalidArgumentException('since 不是有效时间');
        }
        $where[] = 'synced_at >= :since';
        $params[':since'] = date('Y-m-d H:i:s', $ts);
    }

    $whereSql = $where !== array() ? implode(' AND ', $where) : '1=1';

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM yf_invmb WHERE ' . $whereSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $offset = ($page - 1) * $perPage;
    $listSql = 'SELECT item_code, item_name, spec, std_material, latest_purchase, synced_at
        FROM yf_invmb WHERE ' . $whereSql . ' ORDER BY item_code ASC LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset;
    $listStmt = $pdo->prepare($listSql);
    $listStmt->execute($params);
    $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);

    $data = array();
    foreach ($rows as $row) {
        $code = (string)($row['item_code'] ?? '');
        $name = (string)($row['item_name'] ?? '');
        $spec = (string)($row['spec'] ?? '');
        $cat = yf_feed_resolve_category($code, $name);
        if ($cat === null) {
            continue;
        }
        $data[] = array(
            'item_code' => $code,
            'item_name' => $name,
            'spec' => $spec,
            'std_material' => yf_feed_decimal_or_null($row['std_material'] ?? null),
            'latest_purchase' => yf_feed_decimal_or_null($row['latest_purchase'] ?? null),
            'synced_at' => isset($row['synced_at']) && $row['synced_at'] !== null && $row['synced_at'] !== ''
                ? (string)$row['synced_at']
                : null,
            'category' => $cat,
            'brand' => yf_feed_parse_brand($name, $spec),
        );
    }

    return array(
        'ok' => true,
        'data' => $data,
        'meta' => array(
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
        ),
    );
}

function yf_feed_decimal_or_null($value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    return yf_format_decimal($value);
}
