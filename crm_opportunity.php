<?php
require_once __DIR__ . '/crm_customer.php';

function crm_opportunity_stages(): array
{
    return [
        'new_need' => ['label' => '新需求', 'probability' => 10],
        'requirement_confirm' => ['label' => '需求确认', 'probability' => 20],
        'material_sent' => ['label' => '方案 / 资料已发', 'probability' => 30],
        'quoted' => ['label' => '已报价', 'probability' => 40],
        'sampling' => ['label' => '样品中', 'probability' => 50],
        'technical_confirm' => ['label' => '技术确认中', 'probability' => 55],
        'price_negotiation' => ['label' => '价格谈判', 'probability' => 65],
        'waiting_confirm' => ['label' => '等待客户确认', 'probability' => 80],
        'won' => ['label' => '赢单', 'probability' => 100],
        'lost' => ['label' => '输单', 'probability' => 0],
        'paused' => ['label' => '暂停', 'probability' => 0],
    ];
}

function crm_opportunity_ensure_tables(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    crm_customer_ensure_tables();
    db()->exec("CREATE TABLE IF NOT EXISTS crm_opportunities (
        id INT AUTO_INCREMENT PRIMARY KEY,
        opportunity_no VARCHAR(40) NOT NULL,
        opportunity_name VARCHAR(255) NOT NULL,
        customer_id INT NOT NULL,
        contact_id INT NULL,
        country VARCHAR(120) NULL,
        project_name VARCHAR(255) NULL,
        project_type VARCHAR(120) NULL,
        source_type VARCHAR(80) NOT NULL DEFAULT 'manual',
        source_id VARCHAR(80) NULL,
        stage VARCHAR(80) NOT NULL DEFAULT 'new_need',
        owner_user_id INT NULL,
        collaborator_user_ids_json JSON NULL,
        product_category VARCHAR(160) NULL,
        related_model VARCHAR(255) NULL,
        customer_model VARCHAR(255) NULL,
        quantity DECIMAL(14,2) NULL,
        unit VARCHAR(40) NULL,
        currency VARCHAR(20) NOT NULL DEFAULT 'USD',
        target_price DECIMAL(14,4) NULL,
        expected_amount DECIMAL(16,2) NULL,
        probability INT NOT NULL DEFAULT 10,
        forecast_amount DECIMAL(16,2) NULL,
        quoted_amount DECIMAL(16,2) NULL,
        won_amount DECIMAL(16,2) NULL,
        expected_close_date DATE NULL,
        delivery_requirement VARCHAR(255) NULL,
        custom_requirement TEXT NULL,
        parameter_requirement TEXT NULL,
        next_action TEXT NULL,
        next_followup_time DATETIME NULL,
        priority VARCHAR(40) NOT NULL DEFAULT 'normal',
        need_quote TINYINT(1) NOT NULL DEFAULT 0,
        need_sample TINYINT(1) NOT NULL DEFAULT 0,
        need_material TINYINT(1) NOT NULL DEFAULT 0,
        need_technical TINYINT(1) NOT NULL DEFAULT 0,
        need_plm TINYINT(1) NOT NULL DEFAULT 0,
        need_bom TINYINT(1) NOT NULL DEFAULT 0,
        status VARCHAR(40) NOT NULL DEFAULT 'open',
        win_reason TEXT NULL,
        lost_reason VARCHAR(255) NULL,
        competitor VARCHAR(255) NULL,
        lost_note TEXT NULL,
        remark TEXT NULL,
        created_by INT NULL,
        updated_by INT NULL,
        won_at DATETIME NULL,
        lost_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        deleted_at DATETIME NULL,
        UNIQUE KEY uk_opportunity_no (opportunity_no),
        KEY idx_opp_customer (customer_id),
        KEY idx_opp_stage (stage),
        KEY idx_opp_owner (owner_user_id),
        KEY idx_opp_close (expected_close_date),
        KEY idx_opp_deleted (deleted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    db()->exec("CREATE TABLE IF NOT EXISTS crm_opportunity_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        opportunity_id INT NOT NULL,
        customer_id INT NULL,
        action_key VARCHAR(80) NOT NULL,
        old_value TEXT NULL,
        new_value TEXT NULL,
        note TEXT NULL,
        created_by INT NULL,
        created_at DATETIME NOT NULL,
        KEY idx_opp_log (opportunity_id, created_at),
        KEY idx_opp_log_customer (customer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    db()->exec("CREATE TABLE IF NOT EXISTS crm_opportunity_relations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        opportunity_id INT NOT NULL,
        relation_type VARCHAR(60) NOT NULL,
        relation_id VARCHAR(80) NOT NULL,
        title VARCHAR(255) NULL,
        meta_json JSON NULL,
        created_by INT NULL,
        created_at DATETIME NOT NULL,
        deleted_at DATETIME NULL,
        KEY idx_opp_rel (opportunity_id, relation_type),
        KEY idx_opp_rel_target (relation_type, relation_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    db()->exec("CREATE TABLE IF NOT EXISTS crm_opportunity_files (
        id INT AUTO_INCREMENT PRIMARY KEY,
        opportunity_id INT NOT NULL,
        customer_id INT NULL,
        file_type VARCHAR(40) NOT NULL DEFAULT 'attachment',
        file_name VARCHAR(255) NOT NULL,
        original_name VARCHAR(255) NOT NULL DEFAULT '',
        file_path VARCHAR(500) NOT NULL,
        file_size INT NOT NULL DEFAULT 0,
        mime_type VARCHAR(120) NOT NULL DEFAULT '',
        uploaded_by INT NULL,
        uploaded_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        deleted_at DATETIME NULL,
        KEY idx_opp_file (opportunity_id),
        KEY idx_opp_file_type (file_type),
        KEY idx_opp_file_customer (customer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach ([
        "ALTER TABLE crm_opportunities ADD KEY idx_opp_deleted_updated (deleted_at, updated_at)",
        "ALTER TABLE crm_opportunities ADD KEY idx_opp_owner_deleted_updated (owner_user_id, deleted_at, updated_at)",
        "ALTER TABLE crm_opportunities ADD KEY idx_opp_deleted_stage (deleted_at, stage)",
        "ALTER TABLE crm_opportunities ADD KEY idx_opp_deleted_follow (deleted_at, next_followup_time)",
        "ALTER TABLE crm_opportunity_files ADD KEY idx_opp_file_opp_type_deleted (opportunity_id, file_type, deleted_at)",
    ] as $indexSql) {
        try { db()->exec($indexSql); } catch (Throwable $e) {}
    }

    crm_opportunity_ensure_permissions();
}

function crm_opportunity_column_exists(string $table, string $column): bool
{
    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $stmt = db()->query("SHOW COLUMNS FROM `{$safeTable}` WHERE Field = " . db()->quote($column));
    return (bool)$stmt->fetch();
}

function crm_opportunity_add_column(string $table, string $column, string $definition): void
{
    if (!crm_opportunity_column_exists($table, $column)) {
        $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        db()->exec("ALTER TABLE `{$safeTable}` ADD COLUMN {$definition}");
    }
}

function crm_opportunity_ensure_permissions(): void
{
    $permissions = [
        ['opportunity.view','opportunity','view','查看商机','medium'],
        ['opportunity.view_all','opportunity','view_all','查看全部商机','high'],
        ['opportunity.view_department','opportunity','view_department','查看本部门商机','medium'],
        ['opportunity.create','opportunity','create','新建商机','medium'],
        ['opportunity.edit','opportunity','edit','编辑商机','medium'],
        ['opportunity.delete','opportunity','delete','删除商机','high'],
        ['opportunity.assign','opportunity','assign','转移商机负责人','high'],
        ['opportunity.stage','opportunity','stage','推进商机阶段','medium'],
        ['opportunity.win','opportunity','win','标记赢单','high'],
        ['opportunity.lose','opportunity','lose','标记输单','high'],
        ['opportunity.export','opportunity','export','导出商机','high'],
        ['opportunity.report','opportunity','report','查看商机报表','medium'],
        ['opportunity.file_upload','opportunity','file_upload','上传商机图片/附件','medium'],
        ['opportunity.file_delete','opportunity','file_delete','删除商机图片/附件','medium'],
        ['opportunity.file_preview','opportunity','file_preview','预览商机图片/附件','medium'],
        ['opportunity.file_download','opportunity','file_download','下载商机附件','medium'],
    ];
    $stmt = db()->prepare('INSERT IGNORE INTO crm_permissions (permission_key, module, action, description, risk_level, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
    foreach ($permissions as $permission) $stmt->execute($permission);
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key) SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p WHERE r.role_key IN ('super_admin','admin') AND p.module = 'opportunity'");
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key) SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p WHERE r.role_key = 'manager' AND p.permission_key IN ('opportunity.view','opportunity.view_department','opportunity.create','opportunity.edit','opportunity.assign','opportunity.stage','opportunity.win','opportunity.lose','opportunity.export','opportunity.report','opportunity.file_upload','opportunity.file_delete','opportunity.file_preview','opportunity.file_download')");
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key) SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p WHERE r.role_key IN ('sales','staff') AND p.permission_key IN ('opportunity.view','opportunity.create','opportunity.edit','opportunity.stage','opportunity.report','opportunity.file_upload','opportunity.file_delete','opportunity.file_preview','opportunity.file_download')");
}

function crm_opportunity_scope_sql(string $alias = 'o'): string
{
    if (is_super_admin() || has_permission('opportunity.view_all')) return '1=1';
    $user = current_user();
    $userId = (int)($user['id'] ?? 0);
    if (has_permission('opportunity.view_department')) {
        $deptId = (int)($user['department_id'] ?? 0);
        return "({$alias}.owner_user_id = {$userId} OR {$alias}.created_by = {$userId} OR EXISTS (SELECT 1 FROM crm_users ou WHERE ou.id = {$alias}.owner_user_id AND ou.department_id = {$deptId}))";
    }
    return "({$alias}.owner_user_id = {$userId} OR {$alias}.created_by = {$userId} OR JSON_CONTAINS(COALESCE({$alias}.collaborator_user_ids_json, JSON_ARRAY()), '{$userId}'))";
}

function crm_opportunity_no(): string
{
    return 'OP-' . date('ymd') . '-' . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
}

function crm_opportunity_probability(string $stage): int
{
    $stages = crm_opportunity_stages();
    return (int)($stages[$stage]['probability'] ?? 10);
}

function crm_opportunity_forecast($amount, $probability): float
{
    return round(((float)$amount) * max(0, min(100, (int)$probability)) / 100, 2);
}

function crm_opportunity_log(int $opportunityId, int $customerId, string $action, $before = null, $after = null, string $note = ''): void
{
    db()->prepare('INSERT INTO crm_opportunity_logs (opportunity_id, customer_id, action_key, old_value, new_value, note, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())')
        ->execute([$opportunityId, $customerId ?: null, $action, $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE), $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE), $note, current_user()['id'] ?? null]);
    crm_log_event('opportunity', $action, 'opportunity', (string)$opportunityId, $before, $after);
}

function crm_opportunity_list(array $input = []): array
{
    crm_opportunity_ensure_tables();
    crm_require('opportunity.view');
    $where = ['o.deleted_at IS NULL', crm_opportunity_scope_sql('o')];
    $params = [];
    if (!empty($input['customer_id'])) {
        $where[] = 'o.customer_id = ?';
        $params[] = (int)$input['customer_id'];
    }
    if (!empty($input['stage'])) {
        $where[] = 'o.stage = ?';
        $params[] = (string)$input['stage'];
    }
    $filter = (string)($input['filter'] ?? '');
    if ($filter === 'my') {
        $where[] = 'o.owner_user_id = ?';
        $params[] = (int)(current_user()['id'] ?? 0);
    } elseif ($filter === 'month_close') {
        $where[] = "o.expected_close_date IS NOT NULL AND DATE_FORMAT(o.expected_close_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')";
    } elseif ($filter === 'overdue_follow') {
        $where[] = 'o.next_followup_time IS NOT NULL AND o.next_followup_time < NOW() AND o.stage NOT IN ("won","lost")';
    } elseif ($filter === 'quoted') {
        $where[] = 'o.stage = "quoted"';
    } elseif ($filter === 'sampling') {
        $where[] = 'o.stage = "sampling"';
    } elseif ($filter === 'large') {
        $where[] = 'COALESCE(o.expected_amount,0) >= 10000';
    } elseif ($filter === 'won') {
        $where[] = 'o.stage = "won"';
    } elseif ($filter === 'lost') {
        $where[] = 'o.stage = "lost"';
    }
    $q = trim((string)($input['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(o.opportunity_name LIKE ? OR o.project_name LIKE ? OR o.related_model LIKE ? OR o.remark LIKE ? OR c.customer_name LIKE ? OR c.customer_code LIKE ? OR ct.name LIKE ? OR o.country LIKE ?)';
        for ($i = 0; $i < 8; $i++) $params[] = '%' . $q . '%';
    }
    $sortMap = ['created_at' => 'o.created_at', 'expected_close_date' => 'o.expected_close_date', 'expected_amount' => 'o.expected_amount', 'probability' => 'o.probability', 'forecast_amount' => 'o.forecast_amount', 'updated_at' => 'o.updated_at'];
    $sort = $sortMap[$input['sort'] ?? 'updated_at'] ?? 'o.updated_at';
    $dir = strtoupper((string)($input['dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
    $pageSize = max(20, min(200, (int)($input['page_size'] ?? 80)));
    $page = max(1, (int)($input['page'] ?? 1));
    $offset = ($page - 1) * $pageSize;
    $sqlWhere = implode(' AND ', $where);
    $count = db()->prepare("SELECT COUNT(*) FROM crm_opportunities o LEFT JOIN crm_customers c ON c.id=o.customer_id LEFT JOIN crm_contacts ct ON ct.id=o.contact_id WHERE {$sqlWhere}");
    $count->execute($params);
    $stmt = db()->prepare("SELECT o.*, c.customer_name, c.customer_code, ct.name AS contact_name, u.username AS owner_name,
            (SELECT COUNT(*) FROM crm_opportunity_files ofi WHERE ofi.opportunity_id = o.id AND ofi.deleted_at IS NULL AND ofi.file_type = 'image') AS image_count,
            (SELECT COUNT(*) FROM crm_opportunity_files ofi WHERE ofi.opportunity_id = o.id AND ofi.deleted_at IS NULL AND ofi.file_type = 'attachment') AS attachment_count
        FROM crm_opportunities o
        LEFT JOIN crm_customers c ON c.id = o.customer_id
        LEFT JOIN crm_contacts ct ON ct.id = o.contact_id
        LEFT JOIN crm_users u ON u.id = o.owner_user_id
        WHERE {$sqlWhere}
        ORDER BY {$sort} {$dir}, o.id DESC LIMIT {$pageSize} OFFSET {$offset}");
    $stmt->execute($params);
    return ['rows' => $stmt->fetchAll(), 'total' => (int)$count->fetchColumn(), 'page' => $page, 'page_size' => $pageSize, 'stats' => crm_opportunity_stats()];
}

function crm_opportunity_stats(): array
{
    crm_opportunity_ensure_tables();
    if (!has_permission('opportunity.view')) return [];
    $scope = crm_opportunity_scope_sql('o');
    $row = db()->query("SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN stage NOT IN ('won','lost','paused') THEN 1 ELSE 0 END) AS open_count,
        SUM(CASE WHEN stage='won' THEN 1 ELSE 0 END) AS won_count,
        SUM(CASE WHEN stage='lost' THEN 1 ELSE 0 END) AS lost_count,
        COALESCE(SUM(expected_amount),0) AS expected_total,
        COALESCE(SUM(forecast_amount),0) AS forecast_total,
        COALESCE(SUM(won_amount),0) AS won_total
        FROM crm_opportunities o WHERE o.deleted_at IS NULL AND {$scope}")->fetch() ?: [];
    $stages = [];
    foreach (db()->query("SELECT stage, COUNT(*) AS count, COALESCE(SUM(expected_amount),0) AS amount FROM crm_opportunities o WHERE o.deleted_at IS NULL AND {$scope} GROUP BY stage")->fetchAll() as $r) {
        $stages[$r['stage']] = ['count' => (int)$r['count'], 'amount' => (float)$r['amount']];
    }
    return ['summary' => $row, 'stages' => $stages];
}

function crm_opportunity_detail(int $id): array
{
    crm_opportunity_ensure_tables();
    crm_require('opportunity.view');
    $stmt = db()->prepare("SELECT o.*, c.customer_name, c.customer_code, ct.name AS contact_name, u.username AS owner_name,
            (SELECT COUNT(*) FROM crm_opportunity_files ofi WHERE ofi.opportunity_id = o.id AND ofi.deleted_at IS NULL AND ofi.file_type = 'image') AS image_count,
            (SELECT COUNT(*) FROM crm_opportunity_files ofi WHERE ofi.opportunity_id = o.id AND ofi.deleted_at IS NULL AND ofi.file_type = 'attachment') AS attachment_count
        FROM crm_opportunities o
        LEFT JOIN crm_customers c ON c.id=o.customer_id
        LEFT JOIN crm_contacts ct ON ct.id=o.contact_id
        LEFT JOIN crm_users u ON u.id=o.owner_user_id
        WHERE o.id=? AND o.deleted_at IS NULL AND " . crm_opportunity_scope_sql('o') . " LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) throw new RuntimeException('商机不存在或无权查看。');
    $logs = db()->prepare('SELECT l.*, u.username FROM crm_opportunity_logs l LEFT JOIN crm_users u ON u.id=l.created_by WHERE l.opportunity_id=? ORDER BY l.created_at DESC, l.id DESC LIMIT 80');
    $logs->execute([$id]);
    $relations = db()->prepare('SELECT * FROM crm_opportunity_relations WHERE opportunity_id=? AND deleted_at IS NULL ORDER BY created_at DESC, id DESC');
    $relations->execute([$id]);
    return ['opportunity' => $row, 'files' => crm_opportunity_files($id), 'logs' => $logs->fetchAll(), 'relations' => $relations->fetchAll(), 'stages' => crm_opportunity_stages()];
}

function crm_opportunity_save(array $input): array
{
    crm_opportunity_ensure_tables();
    $id = (int)($input['opportunity_id'] ?? $input['id'] ?? 0);
    crm_require($id ? 'opportunity.edit' : 'opportunity.create');
    $name = trim((string)($input['opportunity_name'] ?? ''));
    $customerId = (int)($input['customer_id'] ?? 0);
    if ($name === '') throw new RuntimeException('商机名称不能为空。');
    if (!$customerId) throw new RuntimeException('客户不能为空。');
    $customer = crm_customer_get($customerId)['customer'];
    $stage = array_key_exists((string)($input['stage'] ?? ''), crm_opportunity_stages()) ? (string)$input['stage'] : 'new_need';
    $probability = isset($input['probability']) && $input['probability'] !== '' ? (int)$input['probability'] : crm_opportunity_probability($stage);
    $numOrNull = static function (array $source, string $key): ?float {
        if (!array_key_exists($key, $source) || $source[$key] === '' || $source[$key] === null) return null;
        return (float)$source[$key];
    };
    $expectedAmount = $numOrNull($input, 'expected_amount');
    $forecast = $expectedAmount === null ? null : crm_opportunity_forecast($expectedAmount, $probability);
    $userId = (int)(current_user()['id'] ?? 0);
    $data = [
        'opportunity_name' => $name,
        'customer_id' => $customerId,
        'contact_id' => (int)($input['contact_id'] ?? 0) ?: null,
        'country' => trim((string)($input['country'] ?? $customer['country'] ?? '')),
        'project_name' => trim((string)($input['project_name'] ?? '')),
        'project_type' => trim((string)($input['project_type'] ?? '')),
        'source_type' => trim((string)($input['source_type'] ?? 'manual')) ?: 'manual',
        'source_id' => trim((string)($input['source_id'] ?? '')),
        'stage' => $stage,
        'owner_user_id' => (int)($input['owner_user_id'] ?? 0) ?: $userId,
        'collaborator_user_ids_json' => json_encode(crm_opportunity_ids_from_input($input['collaborator_user_ids'] ?? []), JSON_UNESCAPED_UNICODE),
        'product_category' => trim((string)($input['product_category'] ?? '')),
        'related_model' => trim((string)($input['related_model'] ?? '')),
        'customer_model' => trim((string)($input['customer_model'] ?? '')),
        'quantity' => $numOrNull($input, 'quantity'),
        'unit' => trim((string)($input['unit'] ?? 'pcs')),
        'currency' => trim((string)($input['currency'] ?? 'USD')) ?: 'USD',
        'target_price' => $numOrNull($input, 'target_price'),
        'expected_amount' => $expectedAmount,
        'probability' => max(0, min(100, $probability)),
        'forecast_amount' => $forecast,
        'expected_close_date' => trim((string)($input['expected_close_date'] ?? '')) ?: null,
        'delivery_requirement' => trim((string)($input['delivery_requirement'] ?? '')),
        'custom_requirement' => trim((string)($input['custom_requirement'] ?? '')),
        'parameter_requirement' => trim((string)($input['parameter_requirement'] ?? '')),
        'next_action' => trim((string)($input['next_action'] ?? '')),
        'next_followup_time' => trim((string)($input['next_followup_time'] ?? '')) ?: null,
        'priority' => trim((string)($input['priority'] ?? 'normal')) ?: 'normal',
        'need_quote' => !empty($input['need_quote']) ? 1 : 0,
        'need_sample' => !empty($input['need_sample']) ? 1 : 0,
        'need_material' => !empty($input['need_material']) ? 1 : 0,
        'need_technical' => !empty($input['need_technical']) ? 1 : 0,
        'need_plm' => !empty($input['need_plm']) ? 1 : 0,
        'need_bom' => !empty($input['need_bom']) ? 1 : 0,
        'remark' => trim((string)($input['remark'] ?? '')),
    ];
    if ($id) {
        $before = crm_opportunity_detail($id)['opportunity'];
        $sets = [];
        foreach ($data as $key => $value) $sets[] = "{$key}=?";
        $values = array_values($data);
        $values[] = $userId;
        $values[] = $id;
        db()->prepare('UPDATE crm_opportunities SET ' . implode(',', $sets) . ', updated_by=?, updated_at=NOW() WHERE id=?')->execute($values);
        crm_opportunity_log($id, $customerId, 'opportunity_update', $before, $data);
        crm_customer_timeline_add($customerId, 'opportunity_update', '编辑商机：' . $name, $stage, 'opportunity', (string)$id);
    } else {
        $no = crm_opportunity_no();
        $keys = array_keys($data);
        $sql = 'INSERT INTO crm_opportunities (opportunity_no,' . implode(',', $keys) . ',created_by,updated_by,created_at,updated_at) VALUES (?' . str_repeat(',?', count($keys)) . ',?,?,NOW(),NOW())';
        db()->prepare($sql)->execute(array_merge([$no], array_values($data), [$userId, $userId]));
        $id = (int)db()->lastInsertId();
        crm_opportunity_log($id, $customerId, 'opportunity_create', null, $data);
        crm_customer_timeline_add($customerId, 'opportunity_create', '创建商机：' . $name, $stage . ' · ' . ($data['currency'] ?? '') . ' ' . ($expectedAmount ?? 0), 'opportunity', (string)$id);
    }
    if (!empty($input['create_followup']) && function_exists('crm_followup_create') && has_permission('follow.create')) {
        crm_followup_create(['customer_id' => $customerId, 'followup_type' => '商机', 'content' => '商机下一步：' . ($data['next_action'] ?: $name), 'next_remind_time' => $data['next_followup_time']]);
    }
    crm_opportunity_handle_linkage_requests($id, $customerId, $name, $input);
    return crm_opportunity_detail($id);
}

function crm_opportunity_ids_from_input($value): array
{
    if (is_array($value)) return array_values(array_unique(array_map('intval', $value)));
    return array_values(array_unique(array_filter(array_map('intval', preg_split('/[,，\s]+/', (string)$value)))));
}

function crm_opportunity_handle_linkage_requests(int $id, int $customerId, string $name, array $input): void
{
    $pending = [
        'need_quote' => ['opportunity_quote_requested', '商机报价接口待接入'],
        'need_sample' => ['opportunity_sample_requested', '商机样品接口待接入'],
        'need_material' => ['opportunity_material_requested', '商机资料接口待接入'],
        'need_technical' => ['opportunity_technical_requested', '商机技术确认待处理'],
        'need_plm' => ['opportunity_plm_requested', '商机 PLM 接口待接入'],
        'need_bom' => ['opportunity_bom_requested', '商机 BOM 接口待接入'],
        'create_dispatch' => ['opportunity_dispatch_requested', '商机派工接口待接入'],
    ];
    foreach ($pending as $key => $meta) {
        if (empty($input[$key])) continue;
        crm_opportunity_log($id, $customerId, $meta[0], null, ['status' => 'pending_integration']);
        crm_customer_timeline_add($customerId, $meta[0], $meta[1], $name, 'opportunity', (string)$id);
    }
}

function crm_opportunity_stage_update(int $id, string $stage, array $input = []): array
{
    crm_opportunity_ensure_tables();
    crm_require('opportunity.stage');
    $detail = crm_opportunity_detail($id);
    $before = $detail['opportunity'];
    if (!isset(crm_opportunity_stages()[$stage])) throw new RuntimeException('商机阶段无效。');
    if ($stage === 'won' && empty($input['confirmed'])) throw new RuntimeException('赢单必须填写赢单信息。');
    if ($stage === 'lost' && trim((string)($input['lost_reason'] ?? '')) === '') throw new RuntimeException('输单必须填写原因。');
    $prob = isset($input['probability']) && $input['probability'] !== '' ? (int)$input['probability'] : crm_opportunity_probability($stage);
    $amount = (float)($before['expected_amount'] ?? 0);
    db()->prepare('UPDATE crm_opportunities SET stage=?, probability=?, forecast_amount=?, status=?, updated_by=?, updated_at=NOW() WHERE id=?')
        ->execute([$stage, $prob, crm_opportunity_forecast($amount, $prob), in_array($stage, ['won','lost','paused'], true) ? $stage : 'open', current_user()['id'] ?? null, $id]);
    crm_opportunity_log($id, (int)$before['customer_id'], 'stage_change', ['stage' => $before['stage']], ['stage' => $stage]);
    crm_customer_timeline_add((int)$before['customer_id'], 'opportunity_stage', '商机阶段变更：' . $before['opportunity_name'], $before['stage'] . ' → ' . $stage, 'opportunity', (string)$id);
    return crm_opportunity_detail($id);
}

function crm_opportunity_win(int $id, array $input): array
{
    crm_require('opportunity.win');
    $detail = crm_opportunity_detail($id);
    $before = $detail['opportunity'];
    $amount = $input['won_amount'] === '' ? (float)($before['expected_amount'] ?? 0) : (float)($input['won_amount'] ?? 0);
    db()->prepare('UPDATE crm_opportunities SET stage="won", status="won", probability=100, forecast_amount=?, won_amount=?, currency=?, win_reason=?, won_at=NOW(), updated_by=?, updated_at=NOW() WHERE id=?')
        ->execute([$amount, $amount, trim((string)($input['currency'] ?? $before['currency'] ?? 'USD')) ?: 'USD', trim((string)($input['win_reason'] ?? '')), current_user()['id'] ?? null, $id]);
    crm_opportunity_log($id, (int)$before['customer_id'], 'opportunity_won', $before, $input);
    crm_customer_timeline_add((int)$before['customer_id'], 'opportunity_won', '商机赢单：' . $before['opportunity_name'], '金额 ' . $amount, 'opportunity', (string)$id);
    return crm_opportunity_detail($id);
}

function crm_opportunity_lose(int $id, array $input): array
{
    crm_require('opportunity.lose');
    $reason = trim((string)($input['lost_reason'] ?? ''));
    if ($reason === '') throw new RuntimeException('输单原因不能为空。');
    $detail = crm_opportunity_detail($id);
    $before = $detail['opportunity'];
    db()->prepare('UPDATE crm_opportunities SET stage="lost", status="lost", probability=0, forecast_amount=0, lost_reason=?, competitor=?, lost_note=?, lost_at=NOW(), updated_by=?, updated_at=NOW() WHERE id=?')
        ->execute([$reason, trim((string)($input['competitor'] ?? '')), trim((string)($input['lost_note'] ?? '')), current_user()['id'] ?? null, $id]);
    crm_opportunity_log($id, (int)$before['customer_id'], 'opportunity_lost', $before, $input);
    crm_customer_timeline_add((int)$before['customer_id'], 'opportunity_lost', '商机输单：' . $before['opportunity_name'], $reason, 'opportunity', (string)$id);
    return crm_opportunity_detail($id);
}

function crm_opportunity_delete(int $id): array
{
    crm_opportunity_ensure_tables();
    crm_require('opportunity.delete');
    $before = crm_opportunity_detail($id)['opportunity'];
    db()->prepare('UPDATE crm_opportunities SET deleted_at=NOW(), updated_by=?, updated_at=NOW() WHERE id=?')->execute([current_user()['id'] ?? null, $id]);
    crm_opportunity_log($id, (int)$before['customer_id'], 'opportunity_delete', $before, null);
    crm_customer_timeline_add((int)$before['customer_id'], 'opportunity_delete', '删除商机：' . $before['opportunity_name'], '软删除', 'opportunity', (string)$id);
    return ['id' => $id];
}

function crm_opportunity_files(int $opportunityId): array
{
    $stmt = db()->prepare("SELECT f.*, u.username AS uploaded_by_name
        FROM crm_opportunity_files f
        JOIN crm_opportunities o ON o.id = f.opportunity_id
        LEFT JOIN crm_users u ON u.id = f.uploaded_by
        WHERE f.opportunity_id = ? AND f.deleted_at IS NULL AND o.deleted_at IS NULL AND " . crm_opportunity_scope_sql('o') . "
        ORDER BY f.created_at DESC, f.id DESC");
    $stmt->execute([$opportunityId]);
    return array_map('crm_opportunity_file_public_row', $stmt->fetchAll());
}

function crm_opportunity_file_public_row(array $row): array
{
    $bytes = (int)($row['file_size'] ?? 0);
    $row['file_size_label'] = $bytes >= 1048576 ? round($bytes / 1048576, 1) . 'MB' : ($bytes >= 1024 ? round($bytes / 1024, 1) . 'KB' : $bytes . 'B');
    $row['is_image'] = strpos((string)($row['mime_type'] ?? ''), 'image/') === 0 || ($row['file_type'] ?? '') === 'image';
    $row['is_pdf'] = (string)($row['mime_type'] ?? '') === 'application/pdf';
    return $row;
}

function crm_opportunity_file_row(int $fileId): array
{
    $stmt = db()->prepare("SELECT f.*, o.customer_id, o.owner_user_id, o.created_by, o.opportunity_name
        FROM crm_opportunity_files f
        JOIN crm_opportunities o ON o.id = f.opportunity_id
        WHERE f.id = ? AND f.deleted_at IS NULL AND o.deleted_at IS NULL AND " . crm_opportunity_scope_sql('o') . ' LIMIT 1');
    $stmt->execute([$fileId]);
    $row = $stmt->fetch();
    if (!$row) throw new RuntimeException('商机文件不存在或无权访问。');
    return $row;
}

function crm_opportunity_upload_dir(): array
{
    $relative = 'uploads/opportunity_files/' . date('Ym');
    $absolute = __DIR__ . '/' . $relative;
    if (!is_dir($absolute) && !mkdir($absolute, 0775, true) && !is_dir($absolute)) {
        throw new RuntimeException('商机附件目录不可写：' . $relative);
    }
    return [$relative, $absolute];
}

function crm_opportunity_normalize_files(array $files): array
{
    if (!isset($files['name'])) return [];
    if (!is_array($files['name'])) return [$files];
    $out = [];
    foreach ($files['name'] as $i => $name) {
        $out[] = ['name' => $name, 'type' => $files['type'][$i] ?? '', 'tmp_name' => $files['tmp_name'][$i] ?? '', 'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE, 'size' => $files['size'][$i] ?? 0];
    }
    return $out;
}

function crm_opportunity_upload_files(int $opportunityId, string $type, array $files): array
{
    crm_opportunity_ensure_tables();
    crm_require('opportunity.file_upload');
    $detail = crm_opportunity_detail($opportunityId);
    $opportunity = $detail['opportunity'];
    $type = $type === 'image' ? 'image' : 'attachment';
    $items = crm_opportunity_normalize_files($files);
    if (!$items) throw new RuntimeException('请选择要上传的商机文件。');
    [$relativeDir, $absoluteDir] = crm_opportunity_upload_dir();
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $saved = [];
    foreach ($items as $file) {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('文件上传失败，请重新选择。');
        if (!is_uploaded_file($file['tmp_name'])) throw new RuntimeException('上传文件无效。');
        $original = trim((string)$file['name']);
        $size = (int)$file['size'];
        $mime = $finfo ? (string)finfo_file($finfo, $file['tmp_name']) : (string)($file['type'] ?? '');
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if ($type === 'image') {
            if ($size > 1048576) throw new RuntimeException($original . ' 超过 1MB 图片限制。');
            if (!in_array($mime, ['image/jpeg','image/png','image/webp','image/gif'], true) || !in_array($ext, ['jpg','jpeg','png','webp','gif'], true)) throw new RuntimeException($original . ' 不是支持的图片格式。');
        } elseif ($size > 104857600) {
            throw new RuntimeException($original . ' 超过 100MB 附件限制。');
        }
        $stored = date('YmdHis') . '_' . bin2hex(random_bytes(5)) . ($ext ? '.' . preg_replace('/[^a-z0-9]/', '', $ext) : '');
        if (!move_uploaded_file($file['tmp_name'], $absoluteDir . '/' . $stored)) throw new RuntimeException($original . ' 保存失败。');
        $relativePath = $relativeDir . '/' . $stored;
        db()->prepare("INSERT INTO crm_opportunity_files (opportunity_id, customer_id, file_type, file_name, original_name, file_path, file_size, mime_type, uploaded_by, uploaded_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())")->execute([$opportunityId, (int)$opportunity['customer_id'], $type, $stored, $original, $relativePath, $size, $mime, current_user()['id'] ?? null]);
        $fileId = (int)db()->lastInsertId();
        $saved[] = crm_opportunity_file_public_row(array_merge(crm_opportunity_file_row($fileId), ['uploaded_by_name' => current_user()['username'] ?? '']));
        crm_opportunity_log($opportunityId, (int)$opportunity['customer_id'], $type === 'image' ? 'opportunity_image_upload' : 'opportunity_attachment_upload', null, ['file' => $original]);
        crm_customer_timeline_add((int)$opportunity['customer_id'], $type === 'image' ? 'opportunity_image_upload' : 'opportunity_attachment_upload', $type === 'image' ? '上传商机图片' : '上传商机附件', $opportunity['opportunity_name'] . ' · ' . $original, 'opportunity', (string)$opportunityId);
    }
    if ($finfo) finfo_close($finfo);
    return ['files' => crm_opportunity_files($opportunityId), 'uploaded' => $saved];
}

function crm_opportunity_delete_file(int $fileId): array
{
    crm_require('opportunity.file_delete');
    $file = crm_opportunity_file_row($fileId);
    db()->prepare('UPDATE crm_opportunity_files SET deleted_at = NOW() WHERE id = ?')->execute([$fileId]);
    crm_opportunity_log((int)$file['opportunity_id'], (int)$file['customer_id'], 'opportunity_file_delete', $file, ['deleted' => 1]);
    crm_customer_timeline_add((int)$file['customer_id'], 'opportunity_file_delete', '删除商机图片/附件', ($file['opportunity_name'] ?? '') . ' · ' . ($file['original_name'] ?: $file['file_name']), 'opportunity', (string)$file['opportunity_id']);
    return ['files' => crm_opportunity_files((int)$file['opportunity_id'])];
}

function crm_opportunity_stream_file(int $fileId, bool $inline = false): void
{
    crm_require($inline ? 'opportunity.file_preview' : 'opportunity.file_download');
    $file = crm_opportunity_file_row($fileId);
    $path = __DIR__ . '/' . ltrim((string)$file['file_path'], '/');
    if (!is_file($path)) throw new RuntimeException('文件已失效或不存在。');
    crm_opportunity_log((int)$file['opportunity_id'], (int)$file['customer_id'], $inline ? 'opportunity_file_preview' : 'opportunity_file_download', null, ['file' => $file['original_name'] ?: $file['file_name']]);
    $name = $file['original_name'] ?: $file['file_name'];
    header('Content-Type: ' . (($file['mime_type'] ?? '') ?: 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . rawurlencode($name) . '"');
    readfile($path);
    exit;
}

function crm_opportunity_options(): array
{
    crm_opportunity_ensure_tables();
    static $options = null;
    if ($options !== null) return $options;
    $users = db()->query("SELECT u.id, COALESCE(NULLIF(u.real_name,''), u.username) AS display_name, u.username, d.name AS department_name FROM crm_users u LEFT JOIN crm_departments d ON d.id=u.department_id WHERE u.status='active' ORDER BY u.id")->fetchAll();
    $options = ['stages' => crm_opportunity_stages(), 'users' => $users];
    return $options;
}
