<?php
require_once __DIR__ . '/crm_task_center.php';

require_once __DIR__ . '/crm_customer.php';

function crm_visit_ensure_tables(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS crm_visit_records (
        id INT AUTO_INCREMENT PRIMARY KEY,
        visit_type VARCHAR(40) NOT NULL DEFAULT 'customer_visit',
        customer_id INT NOT NULL,
        contact_id INT NULL,
        title VARCHAR(255) NOT NULL,
        purpose VARCHAR(120) NOT NULL DEFAULT '',
        visit_category VARCHAR(120) NOT NULL DEFAULT '',
        owner_user_id INT NULL,
        assistant_user_ids_json JSON NULL,
        visit_date DATE NULL,
        visit_time TIME NULL,
        location VARCHAR(255) NOT NULL DEFAULT '',
        country VARCHAR(80) NOT NULL DEFAULT '',
        city VARCHAR(120) NOT NULL DEFAULT '',
        transport_method VARCHAR(80) NOT NULL DEFAULT '',
        visitor_count INT NOT NULL DEFAULT 0,
        status VARCHAR(40) NOT NULL DEFAULT 'pending_confirm',
        need_sample TINYINT(1) NOT NULL DEFAULT 0,
        need_material TINYINT(1) NOT NULL DEFAULT 0,
        need_quote TINYINT(1) NOT NULL DEFAULT 0,
        need_demo TINYINT(1) NOT NULL DEFAULT 0,
        need_technical TINYINT(1) NOT NULL DEFAULT 0,
        need_boss TINYINT(1) NOT NULL DEFAULT 0,
        need_dispatch TINYINT(1) NOT NULL DEFAULT 0,
        need_meeting_room TINYINT(1) NOT NULL DEFAULT 0,
        need_factory_tour TINYINT(1) NOT NULL DEFAULT 0,
        need_pickup TINYINT(1) NOT NULL DEFAULT 0,
        need_hotel TINYINT(1) NOT NULL DEFAULT 0,
        need_catering TINYINT(1) NOT NULL DEFAULT 0,
        estimated_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        planned_note TEXT NULL,
        actual_time DATETIME NULL,
        actual_people TEXT NULL,
        customer_feedback TEXT NULL,
        customer_needs TEXT NULL,
        products_discussed TEXT NULL,
        result VARCHAR(120) NOT NULL DEFAULT '',
        result_note TEXT NULL,
        next_action TEXT NULL,
        next_followup_time DATETIME NULL,
        followup_offsets_json JSON NULL,
        deal_probability INT NOT NULL DEFAULT 0,
        related_quote_id INT NULL,
        related_material_id INT NULL,
        related_dispatch_id INT NULL,
        created_by INT NULL,
        updated_by INT NULL,
        completed_by INT NULL,
        completed_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        deleted_at DATETIME NULL,
        KEY idx_visit_customer (customer_id),
        KEY idx_visit_type_date (visit_type, visit_date),
        KEY idx_visit_owner (owner_user_id),
        KEY idx_visit_status (status),
        KEY idx_visit_deleted (deleted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    db()->exec("CREATE TABLE IF NOT EXISTS crm_visit_files (
        id INT AUTO_INCREMENT PRIMARY KEY,
        visit_id INT NOT NULL,
        customer_id INT NULL,
        file_kind VARCHAR(40) NOT NULL DEFAULT 'attachment',
        file_name VARCHAR(255) NOT NULL,
        original_name VARCHAR(255) NOT NULL DEFAULT '',
        file_path VARCHAR(500) NOT NULL,
        file_size INT NOT NULL DEFAULT 0,
        mime_type VARCHAR(120) NOT NULL DEFAULT '',
        uploaded_by INT NULL,
        uploaded_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        deleted_at DATETIME NULL,
        KEY idx_visit_file (visit_id),
        KEY idx_visit_file_kind (file_kind),
        KEY idx_visit_file_customer (customer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    db()->exec("CREATE TABLE IF NOT EXISTS crm_visit_results (
        id INT AUTO_INCREMENT PRIMARY KEY,
        visit_id INT NOT NULL,
        customer_id INT NOT NULL,
        contact_id INT NULL,
        actual_time DATETIME NULL,
        actual_people TEXT NULL,
        customer_feedback TEXT NULL,
        customer_needs TEXT NULL,
        products_discussed TEXT NULL,
        result VARCHAR(120) NOT NULL DEFAULT '',
        result_note TEXT NULL,
        next_action TEXT NULL,
        next_followup_time DATETIME NULL,
        deal_probability INT NOT NULL DEFAULT 0,
        need_quote TINYINT(1) NOT NULL DEFAULT 0,
        need_material TINYINT(1) NOT NULL DEFAULT 0,
        need_sample TINYINT(1) NOT NULL DEFAULT 0,
        need_dispatch TINYINT(1) NOT NULL DEFAULT 0,
        result_source VARCHAR(40) NOT NULL DEFAULT 'manual',
        created_by INT NULL,
        updated_by INT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        deleted_at DATETIME NULL,
        KEY idx_visit_result_visit (visit_id, deleted_at, created_at),
        KEY idx_visit_result_customer (customer_id, deleted_at, created_at),
        KEY idx_visit_result_source (result_source)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    crm_visit_add_column('crm_visit_records', 'preparation_note', 'preparation_note TEXT NULL AFTER planned_note');
    crm_visit_add_column('crm_visit_records', 'followup_offsets_json', 'followup_offsets_json JSON NULL AFTER next_followup_time');
    crm_visit_add_column('crm_visit_files', 'customer_id', 'customer_id INT NULL AFTER visit_id');
    crm_visit_add_column('crm_visit_files', 'original_name', "original_name VARCHAR(255) NOT NULL DEFAULT '' AFTER file_name");
    crm_visit_add_column('crm_visit_files', 'mime_type', "mime_type VARCHAR(120) NOT NULL DEFAULT '' AFTER file_size");
    crm_visit_add_column('crm_visit_files', 'uploaded_at', 'uploaded_at DATETIME NULL AFTER uploaded_by');
    crm_visit_add_column('crm_visit_results', 'result_source', "result_source VARCHAR(40) NOT NULL DEFAULT 'manual' AFTER need_dispatch");
    crm_visit_ensure_permissions();
}

function crm_visit_column_exists(string $table, string $column): bool
{
    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $stmt = db()->query("SHOW COLUMNS FROM `{$safeTable}` WHERE Field = " . db()->quote($column));
    return (bool)$stmt->fetch();
}

function crm_visit_add_column(string $table, string $column, string $definition): void
{
    if (!crm_visit_column_exists($table, $column)) {
        $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        db()->exec("ALTER TABLE `{$safeTable}` ADD COLUMN {$definition}");
    }
}

function crm_visit_ensure_permissions(): void
{
    $permissions = [
        ['visit.view','visit','view','查看拜访/来访','medium'],
        ['visit.view_all','visit','view_all','查看全部拜访/来访','high'],
        ['visit.view_department','visit','view_department','查看本部门拜访/来访','medium'],
        ['visit.create','visit','create','新建拜访/来访','medium'],
        ['visit.edit','visit','edit','编辑拜访/来访','medium'],
        ['visit.delete','visit','delete','删除拜访/来访','high'],
        ['visit.confirm','visit','confirm','确认拜访/来访','medium'],
        ['visit.result','visit','result','填写拜访/来访结果','medium'],
        ['visit.reception','visit','reception','来访接待准备','medium'],
        ['visit.convert_followup','visit','convert_followup','拜访/来访转跟进','medium'],
        ['visit.dispatch','visit','dispatch','拜访/来访创建派工','medium'],
        ['visit.quote','visit','quote','拜访/来访创建报价','high'],
        ['visit.material','visit','material','拜访/来访生成资料','high'],
        ['visit.export','visit','export','导出拜访/来访记录','high'],
        ['visit.report','visit','report','查看拜访/来访报表','medium'],
        ['visit.file_upload','visit','file_upload','上传拜访图片/附件','medium'],
        ['visit.file_delete','visit','file_delete','删除拜访图片/附件','medium'],
        ['visit.file_preview','visit','file_preview','预览拜访图片/附件','medium'],
        ['visit.file_download','visit','file_download','下载拜访附件','medium'],
    ];
    $stmt = db()->prepare('INSERT IGNORE INTO crm_permissions (permission_key, module, action, description, risk_level, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
    foreach ($permissions as $permission) $stmt->execute($permission);
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key) SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p WHERE r.role_key IN ('super_admin','admin') AND p.module = 'visit'");
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key) SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p WHERE r.role_key = 'manager' AND p.permission_key IN ('visit.view','visit.view_department','visit.create','visit.edit','visit.confirm','visit.result','visit.reception','visit.convert_followup','visit.dispatch','visit.quote','visit.material','visit.export','visit.report','visit.file_upload','visit.file_delete','visit.file_preview','visit.file_download')");
    $businessVisitPermissions = crm_visit_business_department_permission_keys();
    $businessVisitPermissionSql = "'" . implode("','", array_map(static fn($key) => str_replace("'", "''", $key), $businessVisitPermissions)) . "'";
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key)
        SELECT r.id, p.permission_key
        FROM crm_roles r JOIN crm_permissions p
        WHERE r.role_key IN ('sales','staff') AND p.permission_key IN ({$businessVisitPermissionSql})");
    db()->exec("INSERT IGNORE INTO crm_user_permissions (user_id, permission_key, effect, created_at)
        SELECT u.id, p.permission_key, 'allow', NOW()
        FROM crm_users u
        JOIN crm_departments d ON d.id = u.department_id
        JOIN crm_permissions p ON p.permission_key IN ({$businessVisitPermissionSql})
        WHERE u.status = 'active' AND (d.name = '业务部' OR d.code = 'sales')");
    db()->exec("INSERT IGNORE INTO crm_user_permissions (user_id, permission_key, effect, created_at)
        SELECT DISTINCT u.id, p.permission_key, 'allow', NOW()
        FROM crm_users u
        JOIN crm_departments d ON d.id = u.department_id
        JOIN crm_user_mail_accounts ma ON ma.user_id = u.id AND ma.deleted_at IS NULL AND ma.is_enabled = 1
        JOIN crm_permissions p ON p.permission_key = 'visit.view_all'
        WHERE u.status = 'active' AND (d.name = '业务部' OR d.code = 'sales')");
}

function crm_visit_business_department_permission_keys(): array
{
    return [
        'visit.view',
        'visit.view_department',
        'visit.create',
        'visit.edit',
        'visit.result',
        'visit.reception',
        'visit.convert_followup',
        'visit.dispatch',
        'visit.file_upload',
        'visit.file_delete',
        'visit.file_preview',
        'visit.file_download',
    ];
}

function crm_visit_scope_sql(string $alias = 'v'): string
{
    if (is_super_admin() || has_permission('visit.view_all')) return '1=1';
    $user = current_user();
    $userId = (int)($user['id'] ?? 0);
    if (has_permission('visit.view_department')) {
        $deptId = (int)($user['department_id'] ?? 0);
        return "({$alias}.owner_user_id = {$userId} OR {$alias}.created_by = {$userId} OR EXISTS (SELECT 1 FROM crm_users vu WHERE vu.id = {$alias}.owner_user_id AND vu.department_id = {$deptId}) OR EXISTS (SELECT 1 FROM crm_users vc WHERE vc.id = {$alias}.created_by AND vc.department_id = {$deptId}))";
    }
    return "({$alias}.owner_user_id = {$userId} OR {$alias}.created_by = {$userId} OR JSON_CONTAINS(COALESCE({$alias}.assistant_user_ids_json, JSON_ARRAY()), '{$userId}'))";
}

function crm_visit_row(int $id): array
{
    crm_visit_ensure_tables();
    $sql = "SELECT v.*, c.customer_name, c.customer_code, ct.name AS contact_name, u.username AS owner_name, cu.username AS creator_name
        FROM crm_visit_records v
        LEFT JOIN crm_customers c ON c.id = v.customer_id
        LEFT JOIN crm_contacts ct ON ct.id = v.contact_id
        LEFT JOIN crm_users u ON u.id = v.owner_user_id
        LEFT JOIN crm_users cu ON cu.id = v.created_by
        WHERE v.id = ? AND v.deleted_at IS NULL AND " . crm_visit_scope_sql('v') . ' LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) throw new RuntimeException('拜访/来访记录不存在或无权查看。');
    $row['assistant_user_ids'] = json_decode((string)($row['assistant_user_ids_json'] ?? '[]'), true) ?: [];
    $row['followup_offsets'] = json_decode((string)($row['followup_offsets_json'] ?? '[]'), true) ?: [];
    $row['files'] = crm_visit_files($id);
    $row['result_history'] = crm_visit_results($id, $row);
    $row['result_count'] = count($row['result_history']);
    return $row;
}

function crm_visit_list(array $input = []): array
{
    crm_visit_ensure_tables();
    crm_require('visit.view');
    $where = ['v.deleted_at IS NULL', crm_visit_scope_sql('v')];
    $params = [];
    $type = trim((string)($input['visit_type'] ?? ''));
    if ($type !== '') {
        $where[] = 'v.visit_type = ?';
        $params[] = $type;
    }
    $customerId = (int)($input['customer_id'] ?? 0);
    if ($customerId > 0) {
        $where[] = 'v.customer_id = ?';
        $params[] = $customerId;
    }
    $keyword = trim((string)($input['keyword'] ?? ''));
    if ($keyword !== '') {
        $like = '%' . $keyword . '%';
        $where[] = "(v.title LIKE ? OR v.purpose LIKE ? OR v.visit_category LIKE ? OR v.location LIKE ? OR v.country LIKE ? OR v.city LIKE ?
            OR v.planned_note LIKE ? OR v.preparation_note LIKE ? OR v.result LIKE ? OR v.result_note LIKE ? OR v.customer_feedback LIKE ?
            OR v.customer_needs LIKE ? OR v.products_discussed LIKE ? OR v.next_action LIKE ?
            OR c.customer_name LIKE ? OR c.customer_name_en LIKE ? OR c.customer_code LIKE ? OR c.country LIKE ? OR c.city LIKE ? OR c.address LIKE ?
            OR ct.name LIKE ? OR ct.name_en LIKE ? OR ct.email LIKE ? OR ct.phone LIKE ? OR ct.whatsapp LIKE ? OR ct.wechat LIKE ?
            OR u.username LIKE ? OR u.real_name LIKE ?
            OR EXISTS (
                SELECT 1 FROM crm_visit_results vrk
                WHERE vrk.visit_id = v.id
                    AND vrk.deleted_at IS NULL
                    AND (vrk.result LIKE ? OR vrk.result_note LIKE ? OR vrk.customer_feedback LIKE ? OR vrk.customer_needs LIKE ? OR vrk.products_discussed LIKE ? OR vrk.next_action LIKE ? OR vrk.actual_people LIKE ?)
            ))";
        for ($i = 0; $i < 35; $i += 1) {
            $params[] = $like;
        }
    }
    $status = trim((string)($input['status'] ?? ''));
    if ($status !== '') {
        $where[] = 'v.status = ?';
        $params[] = $status;
    }
    $range = trim((string)($input['range'] ?? ''));
    if ($range === 'today') $where[] = 'v.visit_date = CURDATE()';
    elseif ($range === 'tomorrow') $where[] = 'v.visit_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY)';
    elseif ($range === 'week') $where[] = 'v.visit_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)';
    elseif ($range === 'month') $where[] = 'v.visit_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)';
    elseif ($range === 'overdue_result') $where[] = "v.visit_date < CURDATE() AND v.status IN ('confirmed','pending_execute','executing','overdue_no_record')";
    elseif ($range === 'need_quote') $where[] = 'v.need_quote = 1';
    elseif ($range === 'need_material') $where[] = 'v.need_material = 1';
    elseif ($range === 'need_sample') $where[] = 'v.need_sample = 1';
    elseif ($range === 'my') {
        $where[] = '(v.owner_user_id = ? OR v.created_by = ?)';
        $params[] = (int)(current_user()['id'] ?? 0);
        $params[] = (int)(current_user()['id'] ?? 0);
    }
    $sql = "SELECT v.*, c.customer_name, c.customer_code, ct.name AS contact_name, u.username AS owner_name,
            (SELECT COUNT(*) FROM crm_visit_files vf WHERE vf.visit_id = v.id AND vf.deleted_at IS NULL AND vf.file_kind = 'image') AS image_count,
            (SELECT COUNT(*) FROM crm_visit_files vf WHERE vf.visit_id = v.id AND vf.deleted_at IS NULL AND vf.file_kind = 'attachment') AS attachment_count,
            (SELECT COUNT(*) FROM crm_visit_results vr WHERE vr.visit_id = v.id AND vr.deleted_at IS NULL) AS result_count,
            (SELECT MAX(vr.created_at) FROM crm_visit_results vr WHERE vr.visit_id = v.id AND vr.deleted_at IS NULL) AS latest_result_at
        FROM crm_visit_records v
        LEFT JOIN crm_customers c ON c.id = v.customer_id
        LEFT JOIN crm_contacts ct ON ct.id = v.contact_id
        LEFT JOIN crm_users u ON u.id = v.owner_user_id
        WHERE " . implode(' AND ', $where) . '
        ORDER BY COALESCE(v.visit_date, DATE(v.created_at)) DESC, COALESCE(v.visit_time, TIME(v.created_at)) DESC, v.id DESC
        LIMIT 300';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['assistant_user_ids'] = json_decode((string)($row['assistant_user_ids_json'] ?? '[]'), true) ?: [];
        $row['followup_offsets'] = json_decode((string)($row['followup_offsets_json'] ?? '[]'), true) ?: [];
    }
    return ['rows' => $rows, 'stats' => crm_visit_stats()];
}

function crm_visit_followup_offsets($value): array
{
    if (is_string($value)) {
        $value = preg_split('/[,，\s]+/', $value);
    }
    if (!is_array($value)) {
        $value = [];
    }
    $allowed = [1, 3, 7, 15, 30, 60];
    $days = [];
    foreach ($value as $item) {
        $day = (int)$item;
        if (in_array($day, $allowed, true)) {
            $days[] = $day;
        }
    }
    return array_values(array_unique($days));
}

function crm_visit_stats(): array
{
    crm_visit_ensure_tables();
    $scope = crm_visit_scope_sql('v');
    $sql = "SELECT
        SUM(v.visit_type='customer_visit' AND v.visit_date = CURDATE()) AS today_visits,
        SUM(v.visit_type='customer_arrival' AND v.visit_date = CURDATE()) AS today_arrivals,
        SUM(v.status='pending_confirm') AS pending_confirm,
        SUM(v.visit_date < CURDATE() AND v.status IN ('confirmed','pending_execute','executing','overdue_no_record')) AS overdue_result,
        SUM(v.need_quote = 1 AND v.status IN ('completed','followup_pending')) AS need_quote,
        SUM(v.need_material = 1 AND v.status IN ('completed','followup_pending')) AS need_material
        FROM crm_visit_records v WHERE v.deleted_at IS NULL AND {$scope}";
    $row = db()->query($sql)->fetch() ?: [];
    foreach ($row as $key => $value) $row[$key] = (int)$value;
    return $row;
}

function crm_visit_save(array $input): array
{
    crm_visit_ensure_tables();
    $id = (int)($input['visit_id'] ?? $input['id'] ?? 0);
    crm_require($id > 0 ? 'visit.edit' : 'visit.create');
    $type = (string)($input['visit_type'] ?? 'customer_visit');
    if (!in_array($type, ['customer_visit', 'customer_arrival'], true)) $type = 'customer_visit';
    $customerId = (int)($input['customer_id'] ?? 0);
    if ($customerId <= 0) throw new RuntimeException('请选择客户。');
    $customer = crm_customer_get($customerId)['customer'] ?? null;
    $title = trim((string)($input['title'] ?? ''));
    if ($title === '') $title = ($type === 'customer_visit' ? '客户拜访：' : '客户来访：') . ($customer['customer_name'] ?? ('#' . $customerId));
    $assistants = $input['assistant_user_ids'] ?? [];
    if (is_string($assistants)) $assistants = array_filter(array_map('intval', preg_split('/[,，\s]+/', $assistants)));
    if (!is_array($assistants)) $assistants = [];
    $followupOffsets = crm_visit_followup_offsets($input['followup_offsets'] ?? []);
    $data = [
        'visit_type' => $type,
        'customer_id' => $customerId,
        'contact_id' => (int)($input['contact_id'] ?? 0) ?: null,
        'title' => $title,
        'purpose' => trim((string)($input['purpose'] ?? '')),
        'visit_category' => trim((string)($input['visit_category'] ?? '')),
        'owner_user_id' => (int)($input['owner_user_id'] ?? 0) ?: (int)(current_user()['id'] ?? 0),
        'assistant_user_ids_json' => json_encode(array_values(array_unique(array_map('intval', $assistants))), JSON_UNESCAPED_UNICODE),
        'visit_date' => trim((string)($input['visit_date'] ?? '')) ?: null,
        'visit_time' => trim((string)($input['visit_time'] ?? '')) ?: null,
        'location' => trim((string)($input['location'] ?? '')),
        'country' => trim((string)($input['country'] ?? ($customer['country'] ?? ''))),
        'city' => trim((string)($input['city'] ?? ($customer['city'] ?? ''))),
        'transport_method' => trim((string)($input['transport_method'] ?? '')),
        'visitor_count' => (int)($input['visitor_count'] ?? 0),
        'status' => trim((string)($input['status'] ?? 'pending_confirm')) ?: 'pending_confirm',
        'need_sample' => !empty($input['need_sample']) ? 1 : 0,
        'need_material' => !empty($input['need_material']) ? 1 : 0,
        'need_quote' => !empty($input['need_quote']) ? 1 : 0,
        'need_demo' => !empty($input['need_demo']) ? 1 : 0,
        'need_technical' => !empty($input['need_technical']) ? 1 : 0,
        'need_boss' => !empty($input['need_boss']) ? 1 : 0,
        'need_dispatch' => !empty($input['need_dispatch']) ? 1 : 0,
        'need_meeting_room' => !empty($input['need_meeting_room']) ? 1 : 0,
        'need_factory_tour' => !empty($input['need_factory_tour']) ? 1 : 0,
        'need_pickup' => !empty($input['need_pickup']) ? 1 : 0,
        'need_hotel' => !empty($input['need_hotel']) ? 1 : 0,
        'need_catering' => !empty($input['need_catering']) ? 1 : 0,
        'estimated_cost' => (float)($input['estimated_cost'] ?? 0),
        'planned_note' => trim((string)($input['planned_note'] ?? '')),
        'preparation_note' => trim((string)($input['preparation_note'] ?? '')),
        'customer_needs' => trim((string)($input['customer_needs'] ?? '')),
        'next_followup_time' => trim((string)($input['next_followup_time'] ?? '')) ?: null,
        'followup_offsets_json' => json_encode($followupOffsets, JSON_UNESCAPED_UNICODE),
    ];
    $before = $id > 0 ? crm_visit_row($id) : null;
    if ($id > 0) {
        $sets = [];
        $values = [];
        foreach ($data as $key => $value) {
            $sets[] = $key . ' = ?';
            $values[] = $value;
        }
        $values[] = (int)(current_user()['id'] ?? 0);
        $values[] = $id;
        db()->prepare('UPDATE crm_visit_records SET ' . implode(', ', $sets) . ', updated_by = ?, updated_at = NOW() WHERE id = ?')->execute($values);
        $action = 'visit_update';
        $message = ($type === 'customer_visit' ? '编辑拜访计划' : '编辑来访接待');
    } else {
        $keys = array_keys($data);
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $values = array_values($data);
        $values[] = (int)(current_user()['id'] ?? 0);
        $values[] = (int)(current_user()['id'] ?? 0);
        db()->prepare('INSERT INTO crm_visit_records (' . implode(',', $keys) . ', created_by, updated_by, created_at, updated_at) VALUES (' . $placeholders . ', ?, ?, NOW(), NOW())')->execute($values);
        $id = (int)db()->lastInsertId();
        $action = 'visit_create';
        $message = ($type === 'customer_visit' ? '创建拜访计划' : '创建来访接待');
    }
    $after = crm_visit_row($id);
    crm_log_event('visit', $action, 'visit', (string)$id, $before, $after);
    crm_customer_timeline_add($customerId, $action, $message, $title . ' · ' . ($data['visit_date'] ?: '未定日期'), 'visit', (string)$id);
    crm_task_upsert_from_visit($after);
    crm_visit_handle_linkage_requests($id, $after, $input);
    return ['record' => $after, 'list' => crm_visit_list([])];
}

function crm_visit_delete(int $id): array
{
    crm_visit_ensure_tables();
    $before = crm_visit_row($id);
    $userId = (int)(current_user()['id'] ?? 0);
    $canDelete = is_super_admin()
        || has_permission('visit.delete')
        || $userId === (int)($before['created_by'] ?? 0)
        || $userId === (int)($before['owner_user_id'] ?? 0);
    if (!$canDelete) {
        throw new RuntimeException('没有权限删除这条拜访/来访记录。');
    }
    db()->beginTransaction();
    try {
        db()->prepare('UPDATE crm_visit_records SET deleted_at = NOW(), updated_by = ?, updated_at = NOW() WHERE id = ? AND deleted_at IS NULL')
            ->execute([$userId, $id]);
        db()->prepare('UPDATE crm_visit_files SET deleted_at = NOW() WHERE visit_id = ? AND deleted_at IS NULL')
            ->execute([$id]);
        db()->prepare("UPDATE crm_tasks SET deleted_at = NOW(), updated_at = NOW()
            WHERE source_type = 'visit' AND source_id = ? AND deleted_at IS NULL")
            ->execute([(string)$id]);
        db()->prepare("UPDATE crm_tasks SET deleted_at = NOW(), updated_at = NOW()
            WHERE source_type = 'visit_action' AND source_id LIKE ? AND deleted_at IS NULL")
            ->execute([$id . ':%']);
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }
    crm_log_event('visit', 'visit_delete', 'visit', (string)$id, $before, ['deleted_at' => date('Y-m-d H:i:s')]);
    crm_customer_timeline_add(
        (int)$before['customer_id'],
        'visit_delete',
        ($before['visit_type'] ?? '') === 'customer_arrival' ? '删除来访记录' : '删除拜访记录',
        (string)($before['title'] ?? ''),
        'visit',
        (string)$id
    );
    return ['deleted_id' => $id, 'list' => crm_visit_list([])];
}

function crm_visit_handle_linkage_requests(int $id, array $visit, array $input): void
{
    $customerId = (int)($visit['customer_id'] ?? 0);
    if (($visit['status'] ?? '') === 'draft') {
        return;
    }
    if (!empty($input['create_followup']) && has_permission('follow.create')) {
        try {
            crm_followup_create([
                'customer_id' => $customerId,
                'contact_id' => (int)($visit['contact_id'] ?? 0),
                'followup_time' => trim((string)($input['next_followup_time'] ?? '')) ?: date('Y-m-d H:i:s'),
                'followup_type' => $visit['visit_type'] === 'customer_visit' ? '拜访' : '来访',
                'content' => $visit['planned_note'] ?: $visit['title'],
                'next_plan' => trim((string)($input['next_action'] ?? '')),
                'status' => 'open',
            ]);
        } catch (Throwable $e) {
            crm_log_event('visit', 'visit_followup_create_failed', 'visit', (string)$id, null, ['error' => $e->getMessage()], false, $e->getMessage());
        }
    }
    crm_visit_create_followup_reminders($id, $visit, crm_visit_followup_offsets($input['followup_offsets'] ?? ($visit['followup_offsets'] ?? [])));
    if (!empty($input['create_dispatch'])) {
        try {
            crm_visit_dispatch_placeholder($id, ($visit['visit_type'] ?? '') === 'customer_arrival' ? 'arrival_reception' : 'visit_prepare');
        } catch (Throwable $e) {
            crm_log_event('visit', 'visit_dispatch_create_failed', 'visit', (string)$id, null, ['error' => $e->getMessage()], false, $e->getMessage());
            throw $e;
        }
    }
    $pending = [
        'create_quote_task' => ['visit_quote_requested', '拜访/来访报价接口待接入'],
        'create_material_task' => ['visit_material_requested', '拜访/来访资料接口待接入'],
        'create_sample_task' => ['visit_sample_requested', '拜访/来访样品接口待接入'],
    ];
    foreach ($pending as $key => $meta) {
        if (empty($input[$key])) continue;
        crm_log_event('visit', $meta[0], 'visit', (string)$id, null, ['status' => 'pending_integration']);
        if ($customerId > 0) crm_customer_timeline_add($customerId, $meta[0], $meta[1], $visit['title'] ?? '', 'visit', (string)$id);
        if ($key === 'create_quote_task') crm_task_upsert_visit_action($visit, 'quote_followup', '拜访后报价提醒', $meta[1]);
        if ($key === 'create_material_task') crm_task_upsert_visit_action($visit, 'material_task', '拜访后资料任务', $meta[1]);
        if ($key === 'create_sample_task') crm_task_upsert_visit_action($visit, 'sample_task', '拜访后样品任务', $meta[1]);
    }
}

function crm_visit_create_followup_reminders(int $id, array $visit, array $offsets): void
{
    if (!$offsets) {
        return;
    }
    if (!has_permission('follow.create')) {
        crm_log_event('visit', 'visit_followup_reminders_failed', 'visit', (string)$id, null, ['offsets' => $offsets], false, '无跟进创建权限');
        return;
    }
    $customerId = (int)($visit['customer_id'] ?? 0);
    if ($customerId <= 0) {
        return;
    }
    $ownerId = (int)($visit['owner_user_id'] ?? 0);
    if ($ownerId <= 0) {
        $ownerId = (int)(current_user()['id'] ?? 0);
    }
    $baseDate = trim((string)($visit['actual_time'] ?? '')) ?: trim((string)($visit['visit_date'] ?? '')) ?: date('Y-m-d');
    $baseTs = strtotime($baseDate);
    if (!$baseTs) {
        $baseTs = time();
    }
    $typeLabel = ($visit['visit_type'] ?? '') === 'customer_arrival' ? '来访' : '拜访';
    $created = [];
    foreach ($offsets as $day) {
        $remindAt = date('Y-m-d 09:00:00', strtotime('+' . (int)$day . ' day', $baseTs));
        $content = $typeLabel . '后 ' . (int)$day . ' 天跟进：' . trim((string)($visit['title'] ?? ''));
        $exists = db()->prepare("SELECT id FROM crm_customer_followups WHERE customer_id = ? AND followup_type = ? AND content = ? AND DATE(next_remind_time) = DATE(?) AND deleted_at IS NULL LIMIT 1");
        $exists->execute([$customerId, $typeLabel, $content, $remindAt]);
        if ($exists->fetchColumn()) {
            continue;
        }
        try {
            crm_followup_create([
                'customer_id' => $customerId,
                'contact_id' => (int)($visit['contact_id'] ?? 0),
                'followup_time' => $remindAt,
                'followup_type' => $typeLabel,
                'content' => $content,
                'next_plan' => '回访客户，确认报价、资料、样品或项目进展。',
                'next_remind_time' => $remindAt,
                'status' => 'open',
            ]);
            $created[] = $day;
            if ($ownerId > 0 && function_exists('create_system_notification')) {
                create_system_notification($ownerId, 'visit_followup_reminder', $content, '提醒时间：' . $remindAt, [
                    'visit_id' => $id,
                    'customer_id' => $customerId,
                    'offset_day' => $day,
                    'remind_at' => $remindAt,
                    'target' => 'tasks',
                ]);
            }
        } catch (Throwable $e) {
            crm_log_event('visit', 'visit_followup_reminder_failed', 'visit', (string)$id, null, ['offset' => $day, 'error' => $e->getMessage()], false, $e->getMessage());
        }
    }
    if ($created) {
        crm_log_event('visit', 'visit_followup_reminders_create', 'visit', (string)$id, null, ['offsets' => $created, 'owner_user_id' => $ownerId]);
        crm_customer_timeline_add($customerId, 'visit_followup_reminders_create', '生成拜访/来访跟进提醒', implode('、', $created) . ' 天后提醒', 'visit', (string)$id);
    }
}

function crm_visit_result_row_public(array $row): array
{
    $row['is_legacy_snapshot'] = (string)($row['result_source'] ?? '') === 'legacy_current';
    $row['created_by_name'] = $row['created_by_name'] ?? $row['creator_name'] ?? '';
    return $row;
}

function crm_visit_current_result_has_content(array $visit): bool
{
    foreach (['actual_time','actual_people','customer_feedback','customer_needs','products_discussed','result','result_note','next_action','next_followup_time'] as $key) {
        if (trim((string)($visit[$key] ?? '')) !== '') return true;
    }
    return (int)($visit['deal_probability'] ?? 0) > 0
        || !empty($visit['need_quote'])
        || !empty($visit['need_material'])
        || !empty($visit['need_sample'])
        || !empty($visit['need_dispatch']);
}

function crm_visit_results(int $visitId, ?array $visit = null): array
{
    $stmt = db()->prepare("SELECT r.*, u.username AS created_by_name
        FROM crm_visit_results r
        JOIN crm_visit_records v ON v.id = r.visit_id
        LEFT JOIN crm_users u ON u.id = r.created_by
        WHERE r.visit_id = ? AND r.deleted_at IS NULL AND v.deleted_at IS NULL AND " . crm_visit_scope_sql('v') . "
        ORDER BY r.created_at DESC, r.id DESC");
    $stmt->execute([$visitId]);
    $rows = array_map('crm_visit_result_row_public', $stmt->fetchAll());
    if (!$rows && $visit && crm_visit_current_result_has_content($visit)) {
        $rows[] = crm_visit_result_row_public([
            'id' => 0,
            'visit_id' => (int)($visit['id'] ?? $visitId),
            'customer_id' => (int)($visit['customer_id'] ?? 0),
            'contact_id' => (int)($visit['contact_id'] ?? 0) ?: null,
            'actual_time' => $visit['actual_time'] ?? null,
            'actual_people' => $visit['actual_people'] ?? '',
            'customer_feedback' => $visit['customer_feedback'] ?? '',
            'customer_needs' => $visit['customer_needs'] ?? '',
            'products_discussed' => $visit['products_discussed'] ?? '',
            'result' => $visit['result'] ?? '',
            'result_note' => $visit['result_note'] ?? '',
            'next_action' => $visit['next_action'] ?? '',
            'next_followup_time' => $visit['next_followup_time'] ?? null,
            'deal_probability' => (int)($visit['deal_probability'] ?? 0),
            'need_quote' => (int)($visit['need_quote'] ?? 0),
            'need_material' => (int)($visit['need_material'] ?? 0),
            'need_sample' => (int)($visit['need_sample'] ?? 0),
            'need_dispatch' => (int)($visit['need_dispatch'] ?? 0),
            'result_source' => 'legacy_current',
            'created_by' => (int)($visit['completed_by'] ?? $visit['updated_by'] ?? $visit['created_by'] ?? 0) ?: null,
            'created_by_name' => $visit['completed_by_name'] ?? $visit['owner_name'] ?? '',
            'created_at' => $visit['completed_at'] ?? $visit['updated_at'] ?? $visit['created_at'] ?? '',
            'updated_at' => $visit['updated_at'] ?? '',
            'is_virtual' => true,
        ]);
    }
    return $rows;
}

function crm_visit_result_history_count(int $visitId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM crm_visit_results WHERE visit_id = ? AND deleted_at IS NULL');
    $stmt->execute([$visitId]);
    return (int)$stmt->fetchColumn();
}

function crm_visit_backfill_current_result(array $visit): void
{
    $visitId = (int)($visit['id'] ?? 0);
    if ($visitId <= 0 || !crm_visit_current_result_has_content($visit) || crm_visit_result_history_count($visitId) > 0) return;
    $uid = (int)($visit['completed_by'] ?? $visit['updated_by'] ?? $visit['created_by'] ?? 0) ?: (int)(current_user()['id'] ?? 0);
    $createdAt = trim((string)($visit['completed_at'] ?? $visit['updated_at'] ?? $visit['created_at'] ?? '')) ?: date('Y-m-d H:i:s');
    db()->prepare("INSERT INTO crm_visit_results (
        visit_id, customer_id, contact_id, actual_time, actual_people, customer_feedback, customer_needs, products_discussed,
        result, result_note, next_action, next_followup_time, deal_probability, need_quote, need_material, need_sample, need_dispatch,
        result_source, created_by, updated_by, created_at, updated_at
    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
        $visitId,
        (int)$visit['customer_id'],
        (int)($visit['contact_id'] ?? 0) ?: null,
        trim((string)($visit['actual_time'] ?? '')) ?: null,
        trim((string)($visit['actual_people'] ?? '')),
        trim((string)($visit['customer_feedback'] ?? '')),
        trim((string)($visit['customer_needs'] ?? '')),
        trim((string)($visit['products_discussed'] ?? '')),
        trim((string)($visit['result'] ?? '')),
        trim((string)($visit['result_note'] ?? '')),
        trim((string)($visit['next_action'] ?? '')),
        trim((string)($visit['next_followup_time'] ?? '')) ?: null,
        (int)($visit['deal_probability'] ?? 0),
        (int)($visit['need_quote'] ?? 0),
        (int)($visit['need_material'] ?? 0),
        (int)($visit['need_sample'] ?? 0),
        (int)($visit['need_dispatch'] ?? 0),
        'legacy_current',
        $uid ?: null,
        $uid ?: null,
        $createdAt,
        $createdAt,
    ]);
}

function crm_visit_result_save(int $id, array $input): array
{
    crm_visit_ensure_tables();
    crm_require('visit.result');
    $before = crm_visit_row($id);
    $status = !empty($input['need_quote']) || !empty($input['need_material']) || !empty($input['need_sample']) || !empty($input['need_dispatch']) || !empty($input['create_dispatch']) ? 'followup_pending' : 'completed';
    $actualTime = trim((string)($input['actual_time'] ?? '')) ?: (trim((string)($before['actual_time'] ?? '')) ?: date('Y-m-d H:i:s'));
    $data = [
        'actual_time' => $actualTime,
        'actual_people' => trim((string)($input['actual_people'] ?? '')),
        'customer_feedback' => trim((string)($input['customer_feedback'] ?? '')),
        'customer_needs' => trim((string)($input['customer_needs'] ?? '')),
        'products_discussed' => trim((string)($input['products_discussed'] ?? '')),
        'result' => trim((string)($input['result'] ?? '')),
        'result_note' => trim((string)($input['result_note'] ?? '')),
        'next_action' => trim((string)($input['next_action'] ?? '')),
        'next_followup_time' => trim((string)($input['next_followup_time'] ?? '')) ?: null,
        'followup_offsets_json' => json_encode(crm_visit_followup_offsets($input['followup_offsets'] ?? []), JSON_UNESCAPED_UNICODE),
        'deal_probability' => (int)($input['deal_probability'] ?? 0),
        'need_quote' => !empty($input['need_quote']) ? 1 : 0,
        'need_material' => !empty($input['need_material']) ? 1 : 0,
        'need_sample' => !empty($input['need_sample']) ? 1 : 0,
        'need_dispatch' => (!empty($input['need_dispatch']) || !empty($input['create_dispatch'])) ? 1 : 0,
        'status' => $status,
    ];
    $uid = (int)(current_user()['id'] ?? 0);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        crm_visit_backfill_current_result($before);
        $pdo->prepare("INSERT INTO crm_visit_results (
            visit_id, customer_id, contact_id, actual_time, actual_people, customer_feedback, customer_needs, products_discussed,
            result, result_note, next_action, next_followup_time, deal_probability, need_quote, need_material, need_sample, need_dispatch,
            result_source, created_by, updated_by, created_at, updated_at
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())")->execute([
            $id,
            (int)$before['customer_id'],
            (int)($before['contact_id'] ?? 0) ?: null,
            $data['actual_time'],
            $data['actual_people'],
            $data['customer_feedback'],
            $data['customer_needs'],
            $data['products_discussed'],
            $data['result'],
            $data['result_note'],
            $data['next_action'],
            $data['next_followup_time'],
            $data['deal_probability'],
            $data['need_quote'],
            $data['need_material'],
            $data['need_sample'],
            $data['need_dispatch'],
            'manual',
            $uid ?: null,
            $uid ?: null,
        ]);
        $sets = [];
        $values = [];
        foreach ($data as $key => $value) {
            $sets[] = $key . ' = ?';
            $values[] = $value;
        }
        $values[] = $uid;
        $values[] = $uid;
        $values[] = $id;
        $pdo->prepare('UPDATE crm_visit_records SET ' . implode(', ', $sets) . ', completed_by = ?, updated_by = ?, completed_at = NOW(), updated_at = NOW() WHERE id = ?')->execute($values);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    $after = crm_visit_row($id);
    crm_log_event('visit', 'visit_result_save', 'visit', (string)$id, $before, $after);
    $title = $before['visit_type'] === 'customer_visit' ? '完成拜访并填写结果' : '完成来访接待并填写结果';
    crm_customer_timeline_add((int)$before['customer_id'], 'visit_result', $title, $data['result_note'] ?: $data['result'], 'visit', (string)$id);
    crm_task_upsert_from_visit($after);
    if ($data['next_followup_time'] && has_permission('follow.create')) {
        try {
            crm_followup_create([
                'customer_id' => (int)$before['customer_id'],
                'contact_id' => (int)($before['contact_id'] ?? 0),
                'followup_time' => $data['next_followup_time'],
                'followup_type' => $before['visit_type'] === 'customer_visit' ? '拜访' : '来访',
                'content' => $data['next_action'] ?: $data['result_note'] ?: '拜访/来访后续跟进',
                'next_plan' => $data['next_action'],
                'status' => 'open',
            ]);
        } catch (Throwable $e) {
            crm_log_event('visit', 'visit_followup_create_failed', 'visit', (string)$id, null, ['error' => $e->getMessage()], false, $e->getMessage());
        }
    }
    crm_visit_create_followup_reminders($id, $after, crm_visit_followup_offsets($input['followup_offsets'] ?? []));
    if (!empty($data['need_quote'])) crm_task_upsert_visit_action($after, 'quote_followup', '拜访后报价提醒', '客户拜访/来访后需要报价，请确认报价内容。');
    if (!empty($data['need_material'])) crm_task_upsert_visit_action($after, 'material_task', '拜访后资料任务', '客户拜访/来访后需要资料，请准备并发送资料。');
    if (!empty($data['need_sample'])) crm_task_upsert_visit_action($after, 'sample_task', '拜访后样品任务', '客户拜访/来访后需要样品，请创建样品寄送或样品准备任务。');
    if (!empty($data['need_dispatch'])) crm_task_upsert_visit_action($after, 'dispatch_confirm', '拜访后派工确认', '客户拜访/来访后需要派工处理，请确认派工内容。');
    if (!empty($input['create_dispatch'])) crm_visit_dispatch_placeholder($id, 'visit_result');
    return ['record' => $after, 'linkage' => crm_visit_linkage_actions($after), 'list' => crm_visit_list([])];
}

function crm_visit_linkage_actions(array $visit): array
{
    $actions = [];
    if (!empty($visit['need_quote'])) $actions[] = ['type' => 'quote', 'label' => '创建报价', 'status' => 'pending_integration', 'message' => '报价接口待接入，可从右侧 ACTIONS 跳转报价系统。'];
    if (!empty($visit['need_material'])) $actions[] = ['type' => 'material', 'label' => '生成资料', 'status' => 'pending_integration', 'message' => '资料生成接口待接入，可从资料系统创建资料包。'];
    if (!empty($visit['need_sample'])) $actions[] = ['type' => 'sample', 'label' => '样品/PLM', 'status' => 'pending_integration', 'message' => '样品/PLM 项目接口待接入。'];
    if (!empty($visit['need_dispatch'])) $actions[] = ['type' => 'dispatch', 'label' => '创建派工', 'status' => 'ready', 'message' => '可从右侧 ACTIONS 创建派工待办，已创建过不会重复。'];
    return $actions;
}

function crm_visit_dispatch_task_no(PDO $pdo): string
{
    do {
        $no = 'VD' . date('ymdHis') . mt_rand(100, 999);
        $st = $pdo->prepare('SELECT COUNT(*) FROM dispatch_next_tasks WHERE task_no = ?');
        $st->execute([$no]);
    } while ((int)$st->fetchColumn() > 0);
    return $no;
}

function crm_visit_dispatch_existing(array $visit): ?array
{
    $pdo = db();
    $visitId = (int)($visit['id'] ?? 0);
    $relatedId = (int)($visit['related_dispatch_id'] ?? 0);
    if ($relatedId > 0) {
        $stmt = $pdo->prepare('SELECT id, task_no FROM dispatch_next_tasks WHERE id = ? AND is_deleted = 0 LIMIT 1');
        $stmt->execute([$relatedId]);
        $row = $stmt->fetch();
        if ($row) return $row;
    }
    if ($visitId > 0) {
        $stmt = $pdo->prepare("SELECT id, task_no FROM dispatch_next_tasks WHERE linked_system = 'crm' AND linked_table = 'crm_visit_records' AND linked_id = ? AND is_deleted = 0 ORDER BY id DESC LIMIT 1");
        $stmt->execute([(string)$visitId]);
        $row = $stmt->fetch();
        if ($row) {
            db()->prepare('UPDATE crm_visit_records SET related_dispatch_id = ?, updated_at = NOW() WHERE id = ? AND (related_dispatch_id IS NULL OR related_dispatch_id = 0)')
                ->execute([(int)$row['id'], $visitId]);
            return $row;
        }
    }
    return null;
}

function crm_visit_dispatch_due_at(array $visit, string $kind): string
{
    $due = $kind === 'visit_result' ? trim((string)($visit['next_followup_time'] ?? '')) : '';
    if ($due === '') {
        $due = crm_task_due_from_visit($visit) ?: '';
    }
    $ts = $due !== '' ? strtotime(str_replace('T', ' ', $due)) : false;
    if (!$ts || $ts <= time()) {
        $ts = strtotime('tomorrow 18:00');
    }
    return date('Y-m-d H:i:s', $ts);
}

function crm_visit_dispatch_assignee(array $visit): int
{
    $uid = (int)((current_user() ?: [])['id'] ?? 0);
    $owner = (int)($visit['owner_user_id'] ?? 0) ?: $uid;
    $stmt = db()->prepare("SELECT id FROM crm_users WHERE id = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$owner]);
    $valid = (int)$stmt->fetchColumn();
    if ($valid > 0) return $valid;
    if ($uid > 0) return $uid;
    throw new RuntimeException('拜访记录没有可用的派工负责人。');
}

function crm_visit_dispatch_description(array $visit, string $kind): string
{
    $typeLabel = ($visit['visit_type'] ?? '') === 'customer_arrival' ? '来访接待' : '客户拜访';
    $lines = [
        '来源：CRM ' . $typeLabel,
        '联动类型：' . ($kind === 'visit_result' ? '拜访结果后续派工' : '拜访/来访准备派工'),
        '客户：' . trim((string)($visit['customer_name'] ?? '')),
        '客户代码：' . trim((string)($visit['customer_code'] ?? '')),
        '联系人：' . trim((string)($visit['contact_name'] ?? '')),
        '日期：' . trim((string)($visit['visit_date'] ?? '')) . ' ' . trim((string)($visit['visit_time'] ?? '')),
        '地点：' . trim(implode(' ', array_filter([
            trim((string)($visit['country'] ?? '')),
            trim((string)($visit['city'] ?? '')),
            trim((string)($visit['location'] ?? '')),
        ]))),
        '目的：' . trim((string)($visit['purpose'] ?? '')),
        '',
        '计划/准备：',
        trim((string)($visit['planned_note'] ?? '')),
        trim((string)($visit['preparation_note'] ?? '')),
        '',
        '客户需求/结果：',
        trim((string)($visit['customer_needs'] ?? '')),
        trim((string)($visit['result_note'] ?? '')),
        trim((string)($visit['next_action'] ?? '')),
    ];
    $description = trim(implode("\n", array_filter($lines, static fn($line) => $line !== '')));
    return mb_strlen($description, 'UTF-8') > 8000 ? mb_substr($description, 0, 8000, 'UTF-8') : $description;
}

function crm_visit_dispatch_placeholder(int $id, string $kind = 'visit_prepare'): array
{
    $visit = crm_visit_row($id);
    crm_require('visit.dispatch');
    require_once __DIR__ . '/dispatch_next_schema.php';
    dispatch_next_init_schema();
    $existing = crm_visit_dispatch_existing($visit);
    if ($existing) {
        return ['message' => '已存在派工：' . (string)$existing['task_no'], 'record' => $visit, 'dispatch_id' => (int)$existing['id'], 'task_no' => (string)$existing['task_no'], 'existing' => true];
    }
    $pdo = db();
    $uid = (int)((current_user() ?: [])['id'] ?? 0);
    if ($uid <= 0) throw new RuntimeException('登录状态无效。');
    $assignee = crm_visit_dispatch_assignee($visit);
    $dueAt = crm_visit_dispatch_due_at($visit, $kind);
    $taskDate = date('Y-m-d', strtotime($dueAt));
    $typeLabel = ($visit['visit_type'] ?? '') === 'customer_arrival' ? '来访接待' : '客户拜访';
    $titlePrefix = $kind === 'visit_result' ? '拜访后派工' : ($typeLabel . '派工');
    $title = $titlePrefix . '：' . (trim((string)($visit['title'] ?? '')) ?: ('拜访/来访 #' . $id));
    if (mb_strlen($title, 'UTF-8') > 240) $title = mb_substr($title, 0, 240, 'UTF-8');
    $project = trim(implode(' · ', array_filter([
        trim((string)($visit['customer_name'] ?? '')),
        trim((string)($visit['customer_code'] ?? '')),
        trim((string)($visit['title'] ?? '')),
    ])));
    if ($project === '') $project = $title;
    if (mb_strlen($project, 'UTF-8') > 180) $project = mb_substr($project, 0, 180, 'UTF-8');
    $linked = [
        'visit_id' => (int)$visit['id'],
        'visit_type' => (string)($visit['visit_type'] ?? ''),
        'kind' => $kind,
        'customer_id' => (int)($visit['customer_id'] ?? 0),
        'customer_name' => (string)($visit['customer_name'] ?? ''),
        'contact_id' => (int)($visit['contact_id'] ?? 0),
        'contact_name' => (string)($visit['contact_name'] ?? ''),
        'visit_date' => (string)($visit['visit_date'] ?? ''),
        'visit_time' => (string)($visit['visit_time'] ?? ''),
    ];
    $pdo->beginTransaction();
    try {
        $taskNo = crm_visit_dispatch_task_no($pdo);
        $pdo->prepare("INSERT INTO dispatch_next_tasks(task_no,task_type,dispatch_mode,parent_group_id,title,project,description,priority,status,created_by,assigned_to,helper_ids_json,task_date,due_at,progress,is_read,linked_system,linked_table,linked_id,linked_title,linked_json,extra_json,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())")
            ->execute([
                $taskNo,
                'dispatch',
                'single',
                null,
                $title,
                $project,
                crm_visit_dispatch_description($visit, $kind),
                'important',
                $assignee === $uid ? 'in_progress' : 'pending_accept',
                $uid,
                $assignee,
                json_encode([], JSON_UNESCAPED_UNICODE),
                $taskDate,
                $dueAt,
                0,
                $assignee === $uid ? 1 : 0,
                'crm',
                'crm_visit_records',
                (string)$id,
                trim((string)($visit['title'] ?? '')),
                json_encode($linked, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode(['source' => 'crm_visit_create_dispatch', 'kind' => $kind], JSON_UNESCAPED_UNICODE),
            ]);
        $dispatchId = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE crm_visit_records SET related_dispatch_id = ?, need_dispatch = 1, updated_by = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$dispatchId, $uid, $id]);
        $pdo->prepare("INSERT INTO dispatch_next_notifications(recipient_id,sender_id,task_id,type,title,message,created_at) VALUES(?,?,?,?,?,?,NOW())")
            ->execute([$assignee, $uid, $dispatchId, 'new_dispatch', '拜访联动派工待接收', $title]);
        $pdo->prepare("INSERT INTO dispatch_next_logs(task_id,user_id,action_type,field_name,old_value,new_value,note,ip,user_agent,created_at) VALUES(?,?,?,?,?,?,?,?,?,NOW())")
            ->execute([$dispatchId, $uid, 'visit_create_dispatch', 'linked_id', '', (string)$id, 'CRM 拜访/来访创建派工', $_SERVER['REMOTE_ADDR'] ?? '', substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]);
        crm_log_event('visit', 'visit_create_dispatch', 'visit', (string)$id, null, ['dispatch_id' => $dispatchId, 'task_no' => $taskNo, 'assigned_to' => $assignee, 'due_at' => $dueAt, 'kind' => $kind]);
        crm_customer_timeline_add((int)$visit['customer_id'], 'visit_create_dispatch', '已创建拜访/来访派工', $taskNo . ' · ' . $title, 'visit', (string)$id);
        $pdo->commit();
        $visit = crm_visit_row($id);
        return ['message' => '派工已创建：' . $taskNo, 'record' => $visit, 'dispatch_id' => $dispatchId, 'task_no' => $taskNo, 'existing' => false];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function crm_visit_files(int $visitId): array
{
    $stmt = db()->prepare("SELECT f.*, u.username AS uploaded_by_name
        FROM crm_visit_files f
        JOIN crm_visit_records v ON v.id = f.visit_id
        LEFT JOIN crm_users u ON u.id = f.uploaded_by
        WHERE f.visit_id = ? AND f.deleted_at IS NULL AND v.deleted_at IS NULL AND " . crm_visit_scope_sql('v') . "
        ORDER BY f.created_at DESC, f.id DESC");
    $stmt->execute([$visitId]);
    return array_map('crm_visit_file_public_row', $stmt->fetchAll());
}

function crm_visit_file_public_row(array $row): array
{
    $row['file_size_label'] = crm_visit_file_size_label((int)($row['file_size'] ?? 0));
    $row['is_image'] = strpos((string)($row['mime_type'] ?? ''), 'image/') === 0 || ($row['file_kind'] ?? '') === 'image';
    $row['is_pdf'] = (string)($row['mime_type'] ?? '') === 'application/pdf';
    return $row;
}

function crm_visit_file_size_label(int $bytes): string
{
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . 'MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . 'KB';
    return $bytes . 'B';
}

function crm_visit_file_row(int $fileId): array
{
    $stmt = db()->prepare("SELECT f.*, v.customer_id, v.owner_user_id, v.created_by
        FROM crm_visit_files f
        JOIN crm_visit_records v ON v.id = f.visit_id
        WHERE f.id = ? AND f.deleted_at IS NULL AND v.deleted_at IS NULL AND " . crm_visit_scope_sql('v') . ' LIMIT 1');
    $stmt->execute([$fileId]);
    $row = $stmt->fetch();
    if (!$row) throw new RuntimeException('文件不存在或无权访问。');
    return $row;
}

function crm_visit_upload_dir(): array
{
    $relative = 'storage/visit_files/' . date('Ym');
    $absolute = __DIR__ . '/' . $relative;
    $parent = dirname($absolute);
    if ((!is_dir($parent) && !@mkdir($parent, 0775, true) && !is_dir($parent))
        || (!is_dir($absolute) && !@mkdir($absolute, 0775, true) && !is_dir($absolute))) {
        throw new RuntimeException('拜访附件目录不可写：' . $relative);
    }
    return [$relative, $absolute];
}

function crm_visit_normalize_files(array $files): array
{
    if (!isset($files['name'])) return [];
    if (!is_array($files['name'])) return [$files];
    $normalized = [];
    foreach ($files['name'] as $i => $name) {
        $normalized[] = [
            'name' => $name,
            'type' => $files['type'][$i] ?? '',
            'tmp_name' => $files['tmp_name'][$i] ?? '',
            'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'][$i] ?? 0,
        ];
    }
    return $normalized;
}

function crm_visit_upload_files(int $visitId, string $kind, array $files): array
{
    crm_visit_ensure_tables();
    crm_require('visit.file_upload');
    $visit = crm_visit_row($visitId);
    $kind = $kind === 'image' ? 'image' : 'attachment';
    $items = crm_visit_normalize_files($files);
    if (!$items) throw new RuntimeException('请选择要上传的文件。');
    [$relativeDir, $absoluteDir] = crm_visit_upload_dir();
    $saved = [];
    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
    foreach ($items as $file) {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('文件上传失败，请重新选择。');
        if (!is_uploaded_file($file['tmp_name'])) throw new RuntimeException('上传文件无效。');
        $original = trim((string)$file['name']);
        $size = (int)$file['size'];
        $mime = $finfo && function_exists('finfo_file') ? (string)finfo_file($finfo, $file['tmp_name']) : '';
        if ($mime === '' && function_exists('mime_content_type')) {
            $mime = (string)@mime_content_type($file['tmp_name']);
        }
        if ($mime === '' && $kind === 'image' && function_exists('getimagesize')) {
            $imageInfo = @getimagesize($file['tmp_name']);
            $mime = is_array($imageInfo) ? (string)($imageInfo['mime'] ?? '') : '';
        }
        if ($mime === '') {
            $mime = trim((string)($file['type'] ?? '')) ?: 'application/octet-stream';
        }
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if ($kind === 'image') {
            if ($size > 2097152) throw new RuntimeException($original . ' 超过 2MB 图片限制。');
            if (!in_array($mime, ['image/jpeg','image/png','image/webp','image/gif'], true) || !in_array($ext, ['jpg','jpeg','png','webp','gif'], true)) {
                throw new RuntimeException($original . ' 不是支持的图片格式。');
            }
        } else {
            if ($size > 104857600) throw new RuntimeException($original . ' 超过 100MB 附件限制。');
        }
        $safeExt = $ext ? ('.' . preg_replace('/[^a-z0-9]/', '', $ext)) : '';
        $stored = date('YmdHis') . '_' . bin2hex(random_bytes(5)) . $safeExt;
        $absolutePath = $absoluteDir . '/' . $stored;
        if (!move_uploaded_file($file['tmp_name'], $absolutePath)) throw new RuntimeException($original . ' 保存失败。');
        $relativePath = $relativeDir . '/' . $stored;
        db()->prepare("INSERT INTO crm_visit_files (visit_id, customer_id, file_kind, file_name, original_name, file_path, file_size, mime_type, uploaded_by, uploaded_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())")->execute([
                $visitId,
                (int)$visit['customer_id'],
                $kind,
                $stored,
                $original,
                $relativePath,
                $size,
                $mime,
                (int)(current_user()['id'] ?? 0),
            ]);
        $fileId = (int)db()->lastInsertId();
        $saved[] = crm_visit_file_public_row(array_merge(crm_visit_file_row($fileId), ['uploaded_by_name' => current_user()['username'] ?? '']));
        crm_log_event('visit', $kind === 'image' ? 'visit_image_upload' : 'visit_attachment_upload', 'visit_file', (string)$fileId, null, ['visit_id' => $visitId, 'file_name' => $original]);
        crm_customer_timeline_add((int)$visit['customer_id'], $kind === 'image' ? 'visit_image_upload' : 'visit_attachment_upload', $kind === 'image' ? '上传拜访图片' : '上传拜访附件', $original, 'visit', (string)$visitId);
    }
    if ($finfo && function_exists('finfo_close')) finfo_close($finfo);
    return ['files' => crm_visit_files($visitId), 'uploaded' => $saved];
}

function crm_visit_delete_file(int $fileId): array
{
    crm_require('visit.file_delete');
    $file = crm_visit_file_row($fileId);
    db()->prepare('UPDATE crm_visit_files SET deleted_at = NOW() WHERE id = ?')->execute([$fileId]);
    crm_log_event('visit', 'visit_file_delete', 'visit_file', (string)$fileId, $file, ['deleted' => 1]);
    crm_customer_timeline_add((int)$file['customer_id'], 'visit_file_delete', '删除拜访图片/附件', $file['original_name'] ?: $file['file_name'], 'visit', (string)$file['visit_id']);
    return ['files' => crm_visit_files((int)$file['visit_id'])];
}

function crm_visit_stream_file(int $fileId, bool $inline = false): void
{
    crm_require($inline ? 'visit.file_preview' : 'visit.file_download');
    $file = crm_visit_file_row($fileId);
    $path = __DIR__ . '/' . ltrim((string)$file['file_path'], '/');
    if (!is_file($path)) throw new RuntimeException('文件已失效或不存在。');
    crm_log_event('visit', $inline ? 'visit_file_preview' : 'visit_file_download', 'visit_file', (string)$fileId, null, ['visit_id' => $file['visit_id'], 'file_name' => $file['original_name'] ?: $file['file_name']]);
    $name = $file['original_name'] ?: $file['file_name'];
    header('Content-Type: ' . (($file['mime_type'] ?? '') ?: 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . rawurlencode($name) . '"');
    readfile($path);
    exit;
}

function crm_visit_options(): array
{
    $users = db()->query("SELECT u.id, u.username, COALESCE(u.real_name, u.username) AS display_name, d.name AS department_name FROM crm_users u LEFT JOIN crm_departments d ON d.id = u.department_id WHERE u.status = 'active' ORDER BY d.sort_order, u.username")->fetchAll();
    return ['users' => $users];
}
