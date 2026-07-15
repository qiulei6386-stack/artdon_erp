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

    crm_visit_add_column('crm_visit_records', 'preparation_note', 'preparation_note TEXT NULL AFTER planned_note');
    crm_visit_add_column('crm_visit_records', 'followup_offsets_json', 'followup_offsets_json JSON NULL AFTER next_followup_time');
    crm_visit_add_column('crm_visit_files', 'customer_id', 'customer_id INT NULL AFTER visit_id');
    crm_visit_add_column('crm_visit_files', 'original_name', "original_name VARCHAR(255) NOT NULL DEFAULT '' AFTER file_name");
    crm_visit_add_column('crm_visit_files', 'mime_type', "mime_type VARCHAR(120) NOT NULL DEFAULT '' AFTER file_size");
    crm_visit_add_column('crm_visit_files', 'uploaded_at', 'uploaded_at DATETIME NULL AFTER uploaded_by');
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
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key) SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p WHERE r.role_key IN ('sales','staff') AND p.permission_key IN ('visit.view','visit.create','visit.edit','visit.result','visit.reception','visit.convert_followup','visit.dispatch','visit.file_upload','visit.file_delete','visit.file_preview','visit.file_download')");
}

function crm_visit_scope_sql(string $alias = 'v'): string
{
    if (is_super_admin() || has_permission('visit.view_all')) return '1=1';
    $user = current_user();
    $userId = (int)($user['id'] ?? 0);
    if (has_permission('visit.view_department')) {
        $deptId = (int)($user['department_id'] ?? 0);
        return "({$alias}.owner_user_id = {$userId} OR {$alias}.created_by = {$userId} OR EXISTS (SELECT 1 FROM crm_users vu WHERE vu.id = {$alias}.owner_user_id AND vu.department_id = {$deptId}))";
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
            (SELECT COUNT(*) FROM crm_visit_files vf WHERE vf.visit_id = v.id AND vf.deleted_at IS NULL AND vf.file_kind = 'attachment') AS attachment_count
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
    $pending = [
        'create_dispatch' => ['visit_dispatch_requested', '拜访/来访派工接口待接入'],
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
        if ($key === 'create_dispatch') crm_task_upsert_visit_action($visit, 'dispatch_confirm', '拜访后派工确认', $meta[1]);
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

function crm_visit_result_save(int $id, array $input): array
{
    crm_visit_ensure_tables();
    crm_require('visit.result');
    $before = crm_visit_row($id);
    $status = !empty($input['need_quote']) || !empty($input['need_material']) || !empty($input['need_sample']) || !empty($input['need_dispatch']) ? 'followup_pending' : 'completed';
    $actualTime = trim((string)($input['actual_time'] ?? '')) ?: date('Y-m-d H:i:s');
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
        'need_quote' => !empty($input['need_quote']) ? 1 : (int)$before['need_quote'],
        'need_material' => !empty($input['need_material']) ? 1 : (int)$before['need_material'],
        'need_sample' => !empty($input['need_sample']) ? 1 : (int)$before['need_sample'],
        'need_dispatch' => !empty($input['need_dispatch']) ? 1 : (int)$before['need_dispatch'],
        'status' => $status,
    ];
    $sets = [];
    $values = [];
    foreach ($data as $key => $value) {
        $sets[] = $key . ' = ?';
        $values[] = $value;
    }
    $values[] = (int)(current_user()['id'] ?? 0);
    $values[] = (int)(current_user()['id'] ?? 0);
    $values[] = $id;
    db()->prepare('UPDATE crm_visit_records SET ' . implode(', ', $sets) . ', completed_by = ?, updated_by = ?, completed_at = NOW(), updated_at = NOW() WHERE id = ?')->execute($values);
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
    return ['record' => $after, 'linkage' => crm_visit_linkage_actions($after), 'list' => crm_visit_list([])];
}

function crm_visit_linkage_actions(array $visit): array
{
    $actions = [];
    if (!empty($visit['need_quote'])) $actions[] = ['type' => 'quote', 'label' => '创建报价', 'status' => 'pending_integration', 'message' => '报价接口待接入，可从右侧 ACTIONS 跳转报价系统。'];
    if (!empty($visit['need_material'])) $actions[] = ['type' => 'material', 'label' => '生成资料', 'status' => 'pending_integration', 'message' => '资料生成接口待接入，可从资料系统创建资料包。'];
    if (!empty($visit['need_sample'])) $actions[] = ['type' => 'sample', 'label' => '样品/PLM', 'status' => 'pending_integration', 'message' => '样品/PLM 项目接口待接入。'];
    if (!empty($visit['need_dispatch'])) $actions[] = ['type' => 'dispatch', 'label' => '创建派工', 'status' => 'pending_integration', 'message' => '派工接口待接入，已保留入口。'];
    return $actions;
}

function crm_visit_dispatch_placeholder(int $id, string $kind = 'visit_prepare'): array
{
    $visit = crm_visit_row($id);
    crm_require('visit.edit');
    crm_log_event('visit', 'dispatch_placeholder', 'visit', (string)$id, null, ['kind' => $kind, 'status' => 'pending_integration']);
    crm_customer_timeline_add((int)$visit['customer_id'], 'visit_dispatch_placeholder', '拜访/来访派工接口待接入', $visit['title'] . ' · ' . $kind, 'visit', (string)$id);
    return ['message' => '派工接口待接入，入口已保留并写入日志。', 'record' => $visit];
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
    $relative = 'uploads/visit_files/' . date('Ym');
    $absolute = __DIR__ . '/' . $relative;
    if (!is_dir($absolute) && !mkdir($absolute, 0775, true) && !is_dir($absolute)) {
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
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    foreach ($items as $file) {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('文件上传失败，请重新选择。');
        if (!is_uploaded_file($file['tmp_name'])) throw new RuntimeException('上传文件无效。');
        $original = trim((string)$file['name']);
        $size = (int)$file['size'];
        $mime = $finfo ? (string)finfo_file($finfo, $file['tmp_name']) : (string)($file['type'] ?? '');
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if ($kind === 'image') {
            if ($size > 512000) throw new RuntimeException($original . ' 超过 500KB 图片限制。');
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
    if ($finfo) finfo_close($finfo);
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
