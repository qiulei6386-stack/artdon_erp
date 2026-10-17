<?php
declare(strict_types=1);

/**
 * Read-only BOM projections for the WeChat mini program.
 * Website writes stay in bom_api.php. This file does not route and does not write.
 */

function bom_mp_cost_keys(): array
{
    return ['price', 'cost', 'unit_price', 'unitCost', 'unit_cost', 'amount', 'subtotal', 'total', 'labor', 'other', 'process', 'process_cost', 'finishCost', 'finish_cost', 'finishCost2', 'finish_cost2', 'surfaceCost', 'surfaceCost2', 'surface_cost', 'surface_cost2', 'material_cost', 'total_cost', 'profit', 'margin'];
}

function bom_mp_supplier_keys(): array
{
    return ['supplier', 'vendor', 'supplier_name', 'factory', 'factory_name'];
}

function bom_mp_review_status_label($status): string
{
    $status = trim((string)$status);
    $map = ['draft' => '草稿', 'pending' => '待审核', 'preliminary' => '预审', 'approved' => '终审', 'rejected' => '已驳回'];
    return $map[$status] ?? ($status !== '' ? $status : '草稿');
}

function bom_mp_hide_row(array $row, bool $canCost, bool $canSupplier): array
{
    if (!$canCost) {
        foreach (bom_mp_cost_keys() as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = '';
            }
        }
    }
    if (!$canSupplier) {
        foreach (bom_mp_supplier_keys() as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = '';
            }
        }
    }
    return $row;
}

function bom_mp_empty_object(): stdClass
{
    return new stdClass();
}

function bom_mp_keyword($value): string
{
    if (!is_string($value) && !is_numeric($value)) {
        throw new InvalidArgumentException('请输入搜索文字');
    }
    $value = trim((string)$value);
    if (strlen($value) > 480) {
        throw new InvalidArgumentException('搜索文字过长');
    }
    return $value;
}

function bom_mp_filters(array $input): array
{
    $keyword = array_key_exists('keyword', $input) ? bom_mp_keyword($input['keyword']) : '';
    $status = trim((string)($input['review_status'] ?? ''));
    if (!in_array($status, ['', 'draft', 'preliminary', 'approved'], true)) {
        $status = '';
    }
    $range = trim((string)($input['date_range'] ?? ''));
    if (!in_array($range, ['', '7d', 'month'], true)) {
        $range = '';
    }
    $page = (int)($input['page'] ?? 1);
    if ($page < 1) {
        $page = 1;
    }
    $pageSize = (int)($input['page_size'] ?? 20);
    if ($pageSize < 1) {
        $pageSize = 20;
    }
    if ($pageSize > 50) {
        $pageSize = 50;
    }
    return [
        'keyword' => $keyword,
        'review_status' => $status,
        'date_range' => $range,
        'page' => $page,
        'page_size' => $pageSize,
    ];
}

function bom_mp_table_columns(PDO $pdo, string $table): array
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return [];
    }
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $rows = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC);
        $cols = [];
        foreach ($rows as $row) {
            $cols[] = (string)($row['name'] ?? '');
        }
        return array_values(array_filter($cols, static function ($col) {
            return $col !== '';
        }));
    }
    $st = $pdo->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
    $st->execute([$table]);
    $cols = $st->fetchAll(PDO::FETCH_COLUMN);
    return is_array($cols) ? array_map('strval', $cols) : [];
}

function bom_mp_quote_ident(string $column): string
{
    return '`' . str_replace('`', '``', $column) . '`';
}

function bom_mp_date_sql(PDO $pdo, string $range): string
{
    if ($range !== '7d' && $range !== 'month') {
        return '';
    }
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        return $range === '7d'
            ? "updated_at >= datetime('now', '-7 days')"
            : "updated_at >= datetime('now', 'start of month')";
    }
    return $range === '7d'
        ? 'updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)'
        : "updated_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01 00:00:00')";
}

function bom_mp_like(string $keyword): string
{
    return '%' . strtr($keyword, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
}

function bom_mp_search_ids(PDO $pdo, string $keyword): array
{
    $keyword = bom_mp_keyword($keyword);
    if ($keyword === '') {
        return [];
    }
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'mysql') {
        require_once __DIR__ . '/bom_dashboard_read.php';
        $ids = bom_dashboard_search($pdo, $keyword);
        return array_values(array_unique(array_map('strval', is_array($ids) ? $ids : [])));
    }
    $cols = bom_mp_table_columns($pdo, 'bom_projects');
    if (!$cols) {
        return [];
    }
    $parts = [];
    $args = [];
    $like = bom_mp_like($keyword);
    foreach (['name', 'customer', 'model', 'product_type'] as $column) {
        if (!in_array($column, $cols, true)) {
            continue;
        }
        $parts[] = bom_mp_quote_ident($column) . " LIKE ? ESCAPE '!'";
        $args[] = $like;
    }
    $where = $parts ? ['(' . implode(' OR ', $parts) . ')'] : ['1=0'];
    if (in_array('is_active', $cols, true)) {
        array_unshift($where, 'is_active=1');
    }
    $select = in_array('rows_json', $cols, true) ? 'project_uid, rows_json' : 'project_uid';
    $st = $pdo->prepare('SELECT ' . $select . ' FROM bom_projects WHERE ' . implode(' AND ', $where));
    $headerIds = [];
    if ($parts) {
        $st->execute($args);
        $headerIds = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }
    if (!in_array('rows_json', $cols, true)) {
        return array_values(array_unique($headerIds));
    }
    $rowWhere = in_array('is_active', $cols, true) ? 'is_active=1' : '1=1';
    $body = $pdo->query('SELECT project_uid, rows_json FROM bom_projects WHERE ' . $rowWhere);
    $ids = $headerIds;
    $needle = function_exists('mb_strtolower') ? mb_strtolower($keyword, 'UTF-8') : strtolower($keyword);
    foreach ($body->fetchAll(PDO::FETCH_ASSOC) as $project) {
        $uid = (string)($project['project_uid'] ?? '');
        if ($uid === '' || in_array($uid, $ids, true)) {
            continue;
        }
        if (bom_mp_rows_match_keyword((string)($project['rows_json'] ?? ''), $needle)) {
            $ids[] = $uid;
        }
    }
    return array_values(array_unique($ids));
}

function bom_mp_rows_match_keyword(string $rowsJson, string $needle): bool
{
    if ($needle === '' || $rowsJson === '') {
        return false;
    }
    $rows = json_decode($rowsJson, true);
    if (!is_array($rows)) {
        return false;
    }
    $fields = ['name', 'material_name', 'materialname', 'spec', 'category', 'model', 'material_model'];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        foreach ($fields as $field) {
            if (!isset($row[$field]) || is_array($row[$field])) {
                continue;
            }
            $hay = function_exists('mb_strtolower') ? mb_strtolower((string)$row[$field], 'UTF-8') : strtolower((string)$row[$field]);
            if ($hay !== '' && (function_exists('mb_strpos') ? mb_strpos($hay, $needle) !== false : strpos($hay, $needle) !== false)) {
                return true;
            }
        }
    }
    return false;
}

function bom_mp_decode_assoc($raw): array
{
    if (is_array($raw)) {
        return $raw;
    }
    $decoded = json_decode((string)$raw, true);
    return is_array($decoded) ? $decoded : [];
}

function bom_mp_totals_complete(array $totals): bool
{
    foreach (['material', 'labor', 'other', 'total', 'suggest', 'profit'] as $key) {
        if (!array_key_exists($key, $totals) || $totals[$key] === '' || $totals[$key] === null) {
            return false;
        }
    }
    return true;
}

function bom_mp_public_totals(array $totals): array
{
    $out = [];
    foreach (['material', 'labor', 'other', 'total', 'suggest', 'profit'] as $key) {
        $out[$key] = is_numeric($totals[$key] ?? null) ? (float)$totals[$key] : 0.0;
    }
    return $out;
}

function bom_mp_load_workflow(): void
{
    if (!function_exists('bw_totals')) {
        require_once __DIR__ . '/bom_workflow.php';
    }
}

function bom_mp_clean_rows($raw): array
{
    $rows = is_array($raw) ? $raw : bom_mp_decode_assoc($raw);
    if ($rows && array_keys($rows) !== range(0, count($rows) - 1)) {
        $rows = array_values($rows);
    }
    $clean = [];
    foreach ($rows as $row) {
        if (is_array($row)) {
            $clean[] = $row;
        }
    }
    return $clean;
}

function bom_mp_compute_totals(array $project): array
{
    bom_mp_load_workflow();
    $rows = array_key_exists('rows', $project) ? bom_mp_clean_rows($project['rows']) : bom_mp_clean_rows($project['rows_json'] ?? []);
    return bw_totals(array_merge($project, ['rows' => $rows]));
}

function bom_mp_public_rows(array $rows, bool $canCost): array
{
    $clean = bom_mp_clean_rows($rows);
    $norm = $clean;
    if ($clean) {
        bom_mp_load_workflow();
        try {
            $norm = bw_rows($clean);
        } catch (Throwable $e) {
            $norm = $clean;
        }
    }
    $out = [];
    foreach ($norm as $index => $row) {
        if (!is_array($row)) {
            continue;
        }
        $source = $clean[$index] ?? $row;
        $safe = bom_mp_hide_row($row, $canCost, false);
        $item = [
            'category' => trim((string)($safe['category'] ?? '')),
            'name' => trim((string)($safe['name'] ?? '')),
            'spec' => trim((string)($safe['spec'] ?? '')),
            'qty' => $row['qty'] ?? ($source['qty'] ?? ($source['quantity'] ?? ($source['数量'] ?? 0))),
            'finish' => trim((string)($source['finish'] ?? $source['finish_name'] ?? '')),
            'finish2' => trim((string)($source['finish2'] ?? '')),
        ];
        if (!$canCost) {
            $out[] = $item;
            continue;
        }
        $price = is_numeric($row['price'] ?? null) ? (float)$row['price'] : 0.0;
        $process = is_numeric($row['process'] ?? null) ? (float)$row['process'] : 0.0;
        $finishCost = is_numeric($row['finishCost'] ?? null) ? (float)$row['finishCost'] : 0.0;
        $finishCost2 = is_numeric($row['finishCost2'] ?? null) ? (float)$row['finishCost2'] : 0.0;
        $qty = is_numeric($row['qty'] ?? null) ? (float)$row['qty'] : 0.0;
        $item['price'] = $price;
        $item['process'] = $process;
        $item['finishCost'] = $finishCost;
        $item['finishCost2'] = $finishCost2;
        $item['subtotal'] = $qty * ($price + $process + $finishCost + $finishCost2);
        $out[] = $item;
    }
    return $out;
}

function bom_mp_select(array $columns, array $wanted): string
{
    $picked = [];
    foreach ($wanted as $column) {
        if (in_array($column, $columns, true)) {
            $picked[] = bom_mp_quote_ident($column);
        }
    }
    if (!$picked) {
        throw new RuntimeException('BOM 数据暂时读不了');
    }
    return implode(',', $picked);
}

function bom_mp_fetch_rows_json(PDO $pdo, array $uids): array
{
    $uids = array_values(array_filter(array_map('strval', $uids), static function ($uid) {
        return $uid !== '';
    }));
    if (!$uids) {
        return [];
    }
    $marks = implode(',', array_fill(0, count($uids), '?'));
    $st = $pdo->prepare('SELECT project_uid, rows_json FROM bom_projects WHERE project_uid IN (' . $marks . ')');
    $st->execute($uids);
    $map = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[(string)$row['project_uid']] = (string)($row['rows_json'] ?? '');
    }
    return $map;
}

function bom_mp_list_item(array $row, bool $canCost, ?array $totals, int $rowCount): array
{
    $item = [
        'project_uid' => (string)($row['project_uid'] ?? ''),
        'name' => (string)($row['name'] ?? ''),
        'customer' => (string)($row['customer'] ?? ''),
        'model' => (string)($row['model'] ?? ''),
        'product_type' => (string)($row['product_type'] ?? ''),
        'review_status' => (string)($row['review_status'] ?? 'draft'),
        'review_status_label' => bom_mp_review_status_label($row['review_status'] ?? 'draft'),
        'row_count' => $rowCount,
        'currency' => (string)($row['currency'] ?? 'RMB'),
        'updated_at' => (string)($row['updated_at'] ?? ''),
        'totals_summary' => $canCost && is_array($totals) ? bom_mp_public_totals($totals) : bom_mp_empty_object(),
    ];
    return $item;
}

function bom_mp_list(PDO $pdo, array $input, bool $canCost, bool $canSupplier): array
{
    unset($canSupplier);
    $filters = bom_mp_filters($input);
    $columns = bom_mp_table_columns($pdo, 'bom_projects');
    $page = [
        'list' => [],
        'page' => $filters['page'],
        'page_size' => $filters['page_size'],
        'has_more' => false,
        'total' => 0,
    ];
    if (!$columns || !in_array('project_uid', $columns, true)) {
        return $page;
    }
    $where = [];
    $args = [];
    if (in_array('is_active', $columns, true)) {
        $where[] = 'is_active=1';
    }
    if ($filters['review_status'] !== '' && in_array('review_status', $columns, true)) {
        $where[] = 'review_status=?';
        $args[] = $filters['review_status'];
    }
    $dateSql = bom_mp_date_sql($pdo, $filters['date_range']);
    if ($dateSql !== '' && in_array('updated_at', $columns, true)) {
        $where[] = $dateSql;
    }
    if ($filters['keyword'] !== '') {
        $ids = bom_mp_search_ids($pdo, $filters['keyword']);
        if (!$ids) {
            return $page;
        }
        $where[] = 'project_uid IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        foreach ($ids as $id) {
            $args[] = $id;
        }
    }
    $whereSql = $where ? implode(' AND ', $where) : '1=1';
    $count = $pdo->prepare('SELECT COUNT(*) FROM bom_projects WHERE ' . $whereSql);
    $count->execute($args);
    $total = (int)$count->fetchColumn();
    $wanted = ['project_uid', 'name', 'customer', 'model', 'product_type', 'review_status', 'currency', 'updated_at', 'row_count'];
    if ($canCost) {
        $wanted = array_merge($wanted, ['totals_json', 'labor', 'other', 'profit_rate', 'quote_mode']);
    }
    $select = bom_mp_select($columns, $wanted);
    $limit = (int)$filters['page_size'];
    $offset = (int)(($filters['page'] - 1) * $limit);
    $order = in_array('updated_at', $columns, true) ? 'updated_at DESC, project_uid DESC' : 'project_uid DESC';
    $st = $pdo->prepare('SELECT ' . $select . ' FROM bom_projects WHERE ' . $whereSql . ' ORDER BY ' . $order . ' LIMIT ' . $limit . ' OFFSET ' . $offset);
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $needRows = [];
    foreach ($rows as $row) {
        $uid = (string)($row['project_uid'] ?? '');
        $cached = $canCost ? bom_mp_decode_assoc($row['totals_json'] ?? '') : [];
        $hasCount = array_key_exists('row_count', $row) && $row['row_count'] !== null && $row['row_count'] !== '';
        if ($uid !== '' && ((!$hasCount) || ($canCost && !bom_mp_totals_complete($cached)))) {
            $needRows[] = $uid;
        }
    }
    $rowsJson = $needRows && in_array('rows_json', $columns, true) ? bom_mp_fetch_rows_json($pdo, $needRows) : [];
    $list = [];
    foreach ($rows as $row) {
        $uid = (string)($row['project_uid'] ?? '');
        $cached = $canCost ? bom_mp_decode_assoc($row['totals_json'] ?? '') : [];
        $totals = null;
        $count = array_key_exists('row_count', $row) && $row['row_count'] !== null && $row['row_count'] !== '' ? (int)$row['row_count'] : null;
        $json = $rowsJson[$uid] ?? null;
        if ($canCost && bom_mp_totals_complete($cached)) {
            $totals = $cached;
        } elseif ($canCost) {
            $calc = $row;
            if ($json !== null) {
                $calc['rows_json'] = $json;
            }
            $totals = bom_mp_compute_totals($calc);
        }
        if ($count === null) {
            $count = $json !== null ? count(bom_mp_clean_rows($json)) : 0;
        }
        $list[] = bom_mp_list_item($row, $canCost, $totals, $count);
    }
    $page['list'] = $list;
    $page['total'] = $total;
    $page['has_more'] = ($offset + count($list)) < $total;
    return $page;
}

function bom_mp_detail(PDO $pdo, string $uid, bool $canCost, bool $canSupplier): ?array
{
    $uid = trim($uid);
    if ($uid === '' || strlen($uid) > 120) {
        return null;
    }
    $columns = bom_mp_table_columns($pdo, 'bom_projects');
    if (!$columns || !in_array('project_uid', $columns, true)) {
        return null;
    }
    $wanted = ['project_uid', 'name', 'customer', 'model', 'product_type', 'version_no', 'variant_label', 'currency', 'note', 'review_status', 'updated_at', 'row_count', 'rows_json'];
    if ($canCost) {
        $wanted = array_merge($wanted, ['labor', 'other', 'profit_rate', 'quote_mode']);
    }
    $select = bom_mp_select($columns, $wanted);
    $where = 'project_uid=?';
    $args = [$uid];
    if (in_array('is_active', $columns, true)) {
        $where .= ' AND is_active=1';
    }
    $st = $pdo->prepare('SELECT ' . $select . ' FROM bom_projects WHERE ' . $where . ' LIMIT 1');
    $st->execute($args);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $clean = bom_mp_clean_rows($row['rows_json'] ?? '[]');
    $project = [
        'project_uid' => (string)($row['project_uid'] ?? ''),
        'name' => (string)($row['name'] ?? ''),
        'customer' => (string)($row['customer'] ?? ''),
        'model' => (string)($row['model'] ?? ''),
        'product_type' => (string)($row['product_type'] ?? ''),
        'version_no' => (string)($row['version_no'] ?? ''),
        'variant_label' => (string)($row['variant_label'] ?? ''),
        'currency' => (string)($row['currency'] ?? 'RMB'),
        'note' => (string)($row['note'] ?? ''),
        'review_status' => (string)($row['review_status'] ?? 'draft'),
        'review_status_label' => bom_mp_review_status_label($row['review_status'] ?? 'draft'),
        'updated_at' => (string)($row['updated_at'] ?? ''),
        'row_count' => count($clean),
        'rows' => bom_mp_public_rows($clean, $canCost),
        'totals_summary' => bom_mp_empty_object(),
    ];
    if ($canCost) {
        $calc = array_merge($row, ['rows' => $clean]);
        $project['totals_summary'] = bom_mp_public_totals(bom_mp_compute_totals($calc));
    }
    unset($canSupplier);
    $encoded = json_encode($project, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        throw new RuntimeException('BOM 数据暂时读不了');
    }
    return $project;
}

function bom_mp_handle(PDO $pdo, string $action, array $input, bool $canView, bool $canCost, bool $canSupplier): array
{
    $can = [
        'view' => $canView,
        'cost_view' => $canCost,
        'supplier_view' => $canSupplier,
    ];
    if ($action === 'bom_meta') {
        return ['ok' => true, 'message' => '', 'data' => ['can' => $can]];
    }
    if ($action !== 'bom_list' && $action !== 'bom_detail') {
        return ['ok' => false, 'message' => '未知接口', 'data' => ['code' => 'BOM_UNKNOWN', 'can' => $can]];
    }
    if (!$canView) {
        return ['ok' => false, 'message' => '当前账号没有 BOM 查看权限', 'data' => ['code' => 'BOM_FORBIDDEN', 'can' => $can]];
    }
    try {
        if ($action === 'bom_list') {
            $data = bom_mp_list($pdo, $input, $canCost, $canSupplier);
            $data['can'] = $can;
            return ['ok' => true, 'message' => '', 'data' => $data];
        }
        $id = trim((string)($input['id'] ?? $input['project_uid'] ?? ''));
        $project = bom_mp_detail($pdo, $id, $canCost, $canSupplier);
        if ($project === null) {
            return ['ok' => false, 'message' => '没有找到这份 BOM', 'data' => ['code' => 'BOM_NOT_FOUND', 'can' => $can]];
        }
        return ['ok' => true, 'message' => '', 'data' => ['can' => $can, 'project' => $project]];
    } catch (InvalidArgumentException $e) {
        return ['ok' => false, 'message' => $e->getMessage(), 'data' => ['code' => 'BOM_BAD_REQUEST', 'can' => $can]];
    }
}
