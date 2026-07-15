<?php

require_once __DIR__ . '/crm_customer.php';

function crm_task_center_ensure_tables(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS crm_tasks (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        task_type VARCHAR(60) NOT NULL DEFAULT 'customer_followup',
        title VARCHAR(255) NOT NULL,
        description TEXT NULL,
        source_type VARCHAR(60) NOT NULL DEFAULT '',
        source_id VARCHAR(80) NOT NULL DEFAULT '',
        customer_id INT UNSIGNED NULL,
        contact_id INT UNSIGNED NULL,
        opportunity_id INT UNSIGNED NULL,
        quote_id VARCHAR(80) NULL,
        assigned_user_id INT UNSIGNED NULL,
        collaborator_user_ids_json JSON NULL,
        priority VARCHAR(30) NOT NULL DEFAULT 'normal',
        status VARCHAR(40) NOT NULL DEFAULT 'pending',
        due_at DATETIME NULL,
        reminder_at DATETIME NULL,
        last_reminded_at DATETIME NULL,
        completed_at DATETIME NULL,
        completed_by INT UNSIGNED NULL,
        result VARCHAR(120) NOT NULL DEFAULT '',
        result_note TEXT NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        deleted_at DATETIME NULL,
        KEY idx_task_type (task_type),
        KEY idx_task_status (status),
        KEY idx_task_due (due_at),
        KEY idx_task_customer (customer_id),
        KEY idx_task_assignee (assigned_user_id),
        KEY idx_task_deleted (deleted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    db()->exec("CREATE TABLE IF NOT EXISTS crm_sample_shipments (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        task_id BIGINT UNSIGNED NULL,
        customer_id INT UNSIGNED NOT NULL,
        contact_id INT UNSIGNED NULL,
        opportunity_id INT UNSIGNED NULL,
        quote_id VARCHAR(80) NULL,
        sample_name VARCHAR(255) NOT NULL,
        product_model VARCHAR(160) NOT NULL DEFAULT '',
        customer_model VARCHAR(160) NOT NULL DEFAULT '',
        product_category VARCHAR(120) NOT NULL DEFAULT '',
        quantity DECIMAL(12,2) NOT NULL DEFAULT 1.00,
        unit VARCHAR(30) NOT NULL DEFAULT 'pcs',
        color VARCHAR(80) NOT NULL DEFAULT '',
        power VARCHAR(80) NOT NULL DEFAULT '',
        cct VARCHAR(80) NOT NULL DEFAULT '',
        cri VARCHAR(80) NOT NULL DEFAULT '',
        beam_angle VARCHAR(80) NOT NULL DEFAULT '',
        is_custom TINYINT(1) NOT NULL DEFAULT 0,
        recipient_name VARCHAR(160) NOT NULL DEFAULT '',
        recipient_phone VARCHAR(120) NOT NULL DEFAULT '',
        recipient_email VARCHAR(160) NOT NULL DEFAULT '',
        recipient_whatsapp VARCHAR(120) NOT NULL DEFAULT '',
        country VARCHAR(120) NOT NULL DEFAULT '',
        city VARCHAR(120) NOT NULL DEFAULT '',
        address VARCHAR(500) NOT NULL DEFAULT '',
        postal_code VARCHAR(80) NOT NULL DEFAULT '',
        courier_company VARCHAR(80) NOT NULL DEFAULT '',
        tracking_no VARCHAR(160) NOT NULL DEFAULT '',
        shipping_date DATE NULL,
        expected_arrival_date DATE NULL,
        freight_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        currency VARCHAR(20) NOT NULL DEFAULT 'USD',
        freight_payer VARCHAR(80) NOT NULL DEFAULT '',
        sender_user_id INT UNSIGNED NULL,
        status VARCHAR(40) NOT NULL DEFAULT 'preparing',
        owner_user_id INT UNSIGNED NULL,
        followup_time DATETIME NULL,
        remind_customer_sign TINYINT(1) NOT NULL DEFAULT 0,
        remind_owner_follow TINYINT(1) NOT NULL DEFAULT 1,
        create_followup_task TINYINT(1) NOT NULL DEFAULT 0,
        create_dispatch_task TINYINT(1) NOT NULL DEFAULT 0,
        feedback_note TEXT NULL,
        remark TEXT NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        deleted_at DATETIME NULL,
        KEY idx_sample_task (task_id),
        KEY idx_sample_customer (customer_id),
        KEY idx_sample_opportunity (opportunity_id),
        KEY idx_sample_status (status),
        KEY idx_sample_owner (owner_user_id),
        KEY idx_sample_deleted (deleted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    db()->exec("CREATE TABLE IF NOT EXISTS crm_sample_shipment_files (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        shipment_id BIGINT UNSIGNED NOT NULL,
        customer_id INT UNSIGNED NULL,
        file_type VARCHAR(40) NOT NULL DEFAULT 'attachment',
        file_name VARCHAR(255) NOT NULL,
        original_name VARCHAR(255) NOT NULL DEFAULT '',
        file_path VARCHAR(500) NOT NULL,
        file_size INT UNSIGNED NOT NULL DEFAULT 0,
        mime_type VARCHAR(160) NOT NULL DEFAULT '',
        uploaded_by INT UNSIGNED NULL,
        uploaded_at DATETIME NOT NULL,
        deleted_at DATETIME NULL,
        KEY idx_sample_file_shipment (shipment_id),
        KEY idx_sample_file_type (file_type),
        KEY idx_sample_file_customer (customer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach ([
        "ALTER TABLE crm_tasks ADD KEY idx_task_deleted_status_due (deleted_at, status, due_at)",
        "ALTER TABLE crm_tasks ADD KEY idx_task_deleted_type_due (deleted_at, task_type, due_at)",
        "ALTER TABLE crm_tasks ADD KEY idx_task_source_lookup (deleted_at, task_type, source_type, source_id)",
        "ALTER TABLE crm_tasks ADD KEY idx_task_assignee_deleted_due (assigned_user_id, deleted_at, due_at)",
        "ALTER TABLE crm_sample_shipments ADD KEY idx_sample_deleted_status_follow (deleted_at, status, followup_time)",
        "ALTER TABLE crm_sample_shipments ADD KEY idx_sample_owner_deleted_status (owner_user_id, deleted_at, status)",
        "ALTER TABLE crm_sample_shipment_files ADD KEY idx_sample_file_ship_type_deleted (shipment_id, file_type, deleted_at)",
    ] as $indexSql) {
        try { db()->exec($indexSql); } catch (Throwable $e) {}
    }

    crm_task_center_ensure_permissions();
    crm_task_center_backfill_links();
}

function crm_task_center_ensure_permissions(): void
{
    $permissions = [
        ['task.view','task','view','查看任务中心','medium'],
        ['task.view_all','task','view_all','查看全部任务','high'],
        ['task.view_department','task','view_department','查看本部门任务','medium'],
        ['task.create','task','create','新建任务','medium'],
        ['task.edit','task','edit','编辑任务','medium'],
        ['task.delete','task','delete','删除任务','high'],
        ['task.complete','task','complete','标记任务完成','medium'],
        ['task.delay','task','delay','延期任务','medium'],
        ['sample.view','sample','view','查看样品寄送','medium'],
        ['sample.create','sample','create','新建样品寄送','medium'],
        ['sample.edit','sample','edit','编辑样品寄送','medium'],
        ['sample.upload_image','sample','upload_image','上传寄样图片','medium'],
        ['sample.delete_image','sample','delete_image','删除寄样图片','medium'],
        ['sample.upload_file','sample','upload_file','上传寄样附件','medium'],
        ['sample.delete_file','sample','delete_file','删除寄样附件','medium'],
        ['sample.tracking','sample','tracking','修改快递单号','medium'],
        ['sample.shipped','sample','shipped','标记样品已寄出','medium'],
        ['sample.signed','sample','signed','标记样品已签收','medium'],
        ['sample.export','sample','export','导出样品寄送','high'],
    ];
    $stmt = db()->prepare('INSERT IGNORE INTO crm_permissions (permission_key, module, action, description, risk_level, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
    foreach ($permissions as $permission) $stmt->execute($permission);
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key) SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p WHERE r.role_key IN ('super_admin','admin') AND p.module IN ('task','sample')");
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key) SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p WHERE r.role_key = 'manager' AND p.permission_key IN ('task.view','task.view_department','task.create','task.edit','task.complete','task.delay','sample.view','sample.create','sample.edit','sample.upload_image','sample.delete_image','sample.upload_file','sample.delete_file','sample.tracking','sample.shipped','sample.signed','sample.export')");
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key) SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p WHERE r.role_key IN ('sales','marketing','staff') AND p.permission_key IN ('task.view','task.create','task.edit','task.complete','task.delay','sample.view','sample.create','sample.edit','sample.upload_image','sample.delete_image','sample.upload_file','sample.delete_file','sample.tracking','sample.shipped','sample.signed')");
}

function crm_task_center_backfill_links(): void
{
    if (db_table_exists('crm_visit_records')) {
        $visitCols = crm_task_table_columns('crm_visit_records');
        $noteParts = ["NULLIF(v.planned_note,'')"];
        if (in_array('preparation_note', $visitCols, true)) $noteParts[] = "NULLIF(v.preparation_note,'')";
        if (in_array('customer_needs', $visitCols, true)) $noteParts[] = "NULLIF(v.customer_needs,'')";
        $descriptionExpr = "TRIM(CONCAT_WS('\n', " . implode(', ', $noteParts) . '))';
        db()->exec("INSERT INTO crm_tasks (task_type,title,description,source_type,source_id,customer_id,contact_id,assigned_user_id,collaborator_user_ids_json,priority,status,due_at,reminder_at,created_by,created_at,updated_at)
            SELECT
                IF(v.visit_type='customer_arrival','arrival_reception','visit_plan'),
                COALESCE(NULLIF(v.title,''), IF(v.visit_type='customer_arrival','来访接待','拜访计划')),
                {$descriptionExpr},
                'visit',
                CAST(v.id AS CHAR),
                v.customer_id,
                v.contact_id,
                v.owner_user_id,
                JSON_ARRAY(),
                'important',
                CASE
                    WHEN v.status='completed' THEN 'done'
                    WHEN v.status='cancelled' THEN 'cancelled'
                    WHEN v.status IN ('followup_pending','pending_confirm') THEN 'confirming'
                    WHEN v.status IN ('executing','pending_execute','confirmed') THEN 'processing'
                    ELSE 'pending'
                END,
                IF(v.visit_date IS NULL, NULL, CONCAT(v.visit_date, ' ', COALESCE(NULLIF(v.visit_time,''), '18:00:00'))),
                IF(v.visit_date IS NULL, NULL, CONCAT(v.visit_date, ' ', COALESCE(NULLIF(v.visit_time,''), '18:00:00'))),
                v.created_by,
                NOW(),
                NOW()
            FROM crm_visit_records v
            WHERE v.deleted_at IS NULL AND v.customer_id IS NOT NULL AND v.customer_id > 0
              AND NOT EXISTS (
                SELECT 1 FROM crm_tasks t
                WHERE t.source_type='visit' COLLATE utf8mb4_unicode_ci
                  AND t.source_id=(CAST(v.id AS CHAR) COLLATE utf8mb4_unicode_ci)
                  AND t.task_type=(IF(v.visit_type='customer_arrival','arrival_reception','visit_plan') COLLATE utf8mb4_unicode_ci)
                  AND t.deleted_at IS NULL
              )");
    }
    if (db_table_exists('crm_customer_followups')) {
        db()->exec("INSERT INTO crm_tasks (task_type,title,description,source_type,source_id,customer_id,contact_id,assigned_user_id,collaborator_user_ids_json,priority,status,due_at,reminder_at,created_by,created_at,updated_at)
            SELECT
                'customer_followup',
                CONCAT('客户跟进：', LEFT(NULLIF(f.content,''), 80)),
                CONCAT_WS('\n', NULLIF(f.content,''), NULLIF(f.next_plan,'')),
                'followup',
                CAST(f.id AS CHAR),
                f.customer_id,
                f.contact_id,
                COALESCE(c.owner_user_id, f.created_by),
                JSON_ARRAY(),
                'normal',
                IF(f.status IN ('done','completed','closed'), 'done', 'pending'),
                COALESCE(f.next_remind_time, f.followup_time),
                f.next_remind_time,
                f.created_by,
                NOW(),
                NOW()
            FROM crm_customer_followups f
            LEFT JOIN crm_customers c ON c.id=f.customer_id
            WHERE f.deleted_at IS NULL AND f.customer_id IS NOT NULL AND f.customer_id > 0
              AND NOT EXISTS (
                SELECT 1 FROM crm_tasks t
                WHERE t.source_type='followup' COLLATE utf8mb4_unicode_ci
                  AND t.source_id=(CAST(f.id AS CHAR) COLLATE utf8mb4_unicode_ci)
                  AND t.task_type='customer_followup' COLLATE utf8mb4_unicode_ci
                  AND t.deleted_at IS NULL
              )");
    }
    if (db_table_exists('quote_orders')) {
        $quoteCols = crm_task_table_columns('quote_orders');
        $convertedWhere = '1=1';
        $convertedParts = [];
        if (in_array('converted_order_id', $quoteCols, true)) $convertedParts[] = 'COALESCE(q.converted_order_id,0)=0';
        if (in_array('converted_order_no', $quoteCols, true)) $convertedParts[] = "COALESCE(q.converted_order_no,'')=''";
        if ($convertedParts) $convertedWhere = implode(' AND ', $convertedParts);
        $dateExpr = in_array('approved_at', $quoteCols, true) ? 'q.approved_at' : 'q.updated_at';
        $customerIdExpr = in_array('customer_id', $quoteCols, true) ? "CASE WHEN q.customer_id REGEXP '^[0-9]+$' THEN q.customer_id+0 ELSE NULL END" : 'NULL';
        $ownerParts = [];
        foreach (['user_name', 'submitted_by', 'created_by'] as $col) {
            if (in_array($col, $quoteCols, true)) $ownerParts[] = "NULLIF(q.{$col},'')";
        }
        $ownerExpr = $ownerParts ? 'COALESCE(' . implode(',', $ownerParts) . ')' : "''";
        $ownerJoin = db_table_exists('crm_users') ? "LEFT JOIN crm_users u ON u.username COLLATE utf8mb4_unicode_ci = ({$ownerExpr}) COLLATE utf8mb4_unicode_ci" : '';
        $ownerSelect = db_table_exists('crm_users') ? 'u.id' : 'NULL';
        db()->exec("INSERT INTO crm_tasks (task_type,title,description,source_type,source_id,customer_id,contact_id,opportunity_id,quote_id,assigned_user_id,collaborator_user_ids_json,priority,status,due_at,reminder_at,created_by,created_at,updated_at)
            SELECT
                'quote_followup',
                CONCAT('报价未回复：', COALESCE(NULLIF(q.quote_no,''), CONCAT('ID ', q.id)), IF(COALESCE(q.customer_name,'')='', '', CONCAT(' / ', q.customer_name))),
                CONCAT('报价已审核完成，请跟进客户是否回复、是否需要转订单/订金/出货。', '\n金额：', COALESCE(q.currency,''), ' ', COALESCE(q.amount,0)),
                'quote',
                CAST(q.id AS CHAR),
                {$customerIdExpr},
                NULL,
                NULL,
                COALESCE(NULLIF(q.quote_no,''), CAST(q.id AS CHAR)),
                {$ownerSelect},
                JSON_ARRAY(),
                'important',
                'pending',
                DATE_ADD(COALESCE({$dateExpr}, q.updated_at, q.created_at, NOW()), INTERVAL 3 DAY),
                DATE_ADD(COALESCE({$dateExpr}, q.updated_at, q.created_at, NOW()), INTERVAL 1 DAY),
                {$ownerSelect},
                NOW(),
                NOW()
            FROM quote_orders q
            {$ownerJoin}
            WHERE COALESCE(q.approval_status,'pending')='approved'
              AND {$convertedWhere}
              AND NOT EXISTS (
                SELECT 1 FROM crm_tasks t
                WHERE t.task_type='quote_followup' COLLATE utf8mb4_unicode_ci
                  AND t.source_type='quote' COLLATE utf8mb4_unicode_ci
                  AND t.source_id=(CAST(q.id AS CHAR) COLLATE utf8mb4_unicode_ci)
                  AND t.deleted_at IS NULL
              )");
    }
}

function crm_task_table_columns(string $table): array
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) return [];
    try {
        $rows = db()->query("SHOW COLUMNS FROM `{$table}`")->fetchAll();
        return array_map(fn($row) => (string)$row['Field'], $rows);
    } catch (Throwable $e) {
        return [];
    }
}

function crm_task_scope_sql(string $alias = 't'): string
{
    if (is_super_admin() || has_permission('task.view_all')) return '1=1';
    $user = current_user() ?: [];
    $uid = (int)($user['id'] ?? 0);
    if (has_permission('task.view_department')) {
        $dept = (int)($user['department_id'] ?? 0);
        return "({$alias}.assigned_user_id={$uid} OR {$alias}.created_by={$uid} OR EXISTS (SELECT 1 FROM crm_users tu WHERE tu.id={$alias}.assigned_user_id AND tu.department_id={$dept}))";
    }
    return "({$alias}.assigned_user_id={$uid} OR {$alias}.created_by={$uid} OR JSON_CONTAINS(COALESCE({$alias}.collaborator_user_ids_json, JSON_ARRAY()), '\"{$uid}\"'))";
}

function crm_sample_scope_sql(string $alias = 's'): string
{
    if (is_super_admin() || has_permission('task.view_all')) return '1=1';
    $user = current_user() ?: [];
    $uid = (int)($user['id'] ?? 0);
    if (has_permission('task.view_department')) {
        $dept = (int)($user['department_id'] ?? 0);
        return "({$alias}.owner_user_id={$uid} OR {$alias}.created_by={$uid} OR EXISTS (SELECT 1 FROM crm_users su WHERE su.id={$alias}.owner_user_id AND su.department_id={$dept}))";
    }
    return "({$alias}.owner_user_id={$uid} OR {$alias}.created_by={$uid})";
}

function crm_task_center_options(): array
{
    crm_task_center_ensure_tables();
    static $options = null;
    if ($options !== null) return $options;
    $users = db()->query("SELECT u.id, COALESCE(NULLIF(u.real_name,''), NULLIF(u.english_name,''), u.username) AS name, d.name AS department_name FROM crm_users u LEFT JOIN crm_departments d ON d.id=u.department_id WHERE u.status <> 'disabled' ORDER BY d.name, u.username")->fetchAll();
    $options = [
        'users' => $users,
        'couriers' => ['DHL','FedEx','UPS','TNT','顺丰','EMS','中通','圆通','其他'],
        'task_types' => crm_task_type_map(),
        'task_statuses' => crm_task_status_map(),
        'sample_statuses' => crm_sample_status_map(),
    ];
    return $options;
}

function crm_task_type_map(): array
{
    return [
        'customer_followup' => '客户跟进',
        'opportunity_followup' => '商机跟进',
        'quote_followup' => '报价跟进',
        'mail_reply' => '邮件回复提醒',
        'promotion_manual' => '推广人工执行',
        'group_promotion' => '群推广执行',
        'visit_plan' => '拜访计划',
        'arrival_reception' => '来访接待',
        'ai_confirm' => 'AI 待确认',
        'material_task' => '资料任务',
        'sample_task' => '样品任务',
        'sample_shipment' => '样品寄送',
        'dispatch_confirm' => '派工确认',
        'payment_reminder' => '回款提醒',
        'aftersales_reminder' => '售后提醒',
    ];
}

function crm_task_status_map(): array
{
    return ['pending'=>'待处理','processing'=>'处理中','confirming'=>'待确认','done'=>'已完成','cancelled'=>'已取消','delayed'=>'已延期','overdue'=>'已逾期','rejected'=>'被驳回','blocked'=>'无法处理','closed'=>'已关闭'];
}

function crm_sample_status_map(): array
{
    return ['preparing'=>'待准备','sample_ready'=>'已备样','pending_ship'=>'待寄出','shipped'=>'已寄出','in_transit'=>'运输中','signed'=>'已签收','feedback'=>'客户已反馈','exception'=>'异常','cancelled'=>'已取消'];
}

function crm_task_center_list(array $input = []): array
{
    crm_task_center_ensure_tables();
    crm_require('task.view');
    $view = trim((string)($input['view'] ?? 'my'));
    $q = trim((string)($input['q'] ?? ''));
    $where = ['t.deleted_at IS NULL', crm_task_scope_sql('t')];
    $params = [];
    $uid = (int)((current_user() ?: [])['id'] ?? 0);
    if ($view === 'my') { $where[] = '(t.assigned_user_id=? OR t.created_by=?)'; array_push($params, $uid, $uid); }
    elseif ($view === 'today') $where[] = 'DATE(t.due_at)=CURDATE()';
    elseif ($view === 'tomorrow') $where[] = 'DATE(t.due_at)=DATE_ADD(CURDATE(), INTERVAL 1 DAY)';
    elseif ($view === 'week') $where[] = 'DATE(t.due_at) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)';
    elseif ($view === 'overdue') $where[] = "t.status NOT IN ('done','closed','cancelled') AND t.due_at IS NOT NULL AND t.due_at < NOW()";
    elseif ($view === 'confirming') $where[] = "t.status='confirming'";
    elseif ($view === 'done') $where[] = "t.status='done'";
    elseif ($view === 'sample') $where[] = "t.task_type IN ('sample_task','sample_shipment')";
    elseif ($view === 'sample_pending_ship') $where[] = "t.task_type='sample_shipment' AND EXISTS (SELECT 1 FROM crm_sample_shipments ps WHERE ps.task_id=t.id AND ps.deleted_at IS NULL AND ps.status IN ('preparing','sample_ready','pending_ship'))";
    elseif ($view === 'sample_follow_overdue') $where[] = "t.task_type='sample_shipment' AND EXISTS (SELECT 1 FROM crm_sample_shipments ps WHERE ps.task_id=t.id AND ps.deleted_at IS NULL AND ps.status='signed' AND ps.followup_time IS NOT NULL AND ps.followup_time < NOW())";
    elseif ($view === 'ai') $where[] = "t.task_type='ai_confirm'";
    elseif ($view === 'dispatch') $where[] = "t.task_type='dispatch_confirm'";
    elseif ($view === 'promotion') $where[] = "t.task_type IN ('promotion_manual','group_promotion')";
    elseif ($view === 'opportunity') $where[] = "t.task_type='opportunity_followup'";
    elseif ($view === 'quote') $where[] = "t.task_type='quote_followup'";
    if ($q !== '') {
        $where[] = "(t.title LIKE ? OR t.description LIKE ? OR c.customer_name LIKE ? OR ct.name LIKE ? OR o.opportunity_name LIKE ? OR t.quote_id LIKE ? OR EXISTS (SELECT 1 FROM crm_sample_shipments ss WHERE ss.task_id=t.id AND (ss.tracking_no LIKE ? OR ss.sample_name LIKE ? OR ss.product_model LIKE ?)))";
        for ($i = 0; $i < 9; $i++) $params[] = '%' . $q . '%';
    }
    $sql = "SELECT t.*, c.customer_name, c.customer_code, ct.name AS contact_name, o.opportunity_name, u.username AS assigned_name,
            CASE WHEN t.status NOT IN ('done','closed','cancelled') AND t.due_at IS NOT NULL AND t.due_at < NOW() THEN 1 ELSE 0 END AS is_overdue,
            ss.id AS sample_shipment_id, ss.sample_name, ss.courier_company, ss.tracking_no, ss.status AS sample_status
        FROM crm_tasks t
        LEFT JOIN crm_customers c ON c.id=t.customer_id
        LEFT JOIN crm_contacts ct ON ct.id=t.contact_id
        LEFT JOIN crm_opportunities o ON o.id=t.opportunity_id
        LEFT JOIN crm_users u ON u.id=t.assigned_user_id
        LEFT JOIN crm_sample_shipments ss ON ss.task_id=t.id AND ss.deleted_at IS NULL
        WHERE " . implode(' AND ', $where) . "
        ORDER BY is_overdue DESC, COALESCE(t.due_at, t.created_at) ASC, t.id DESC LIMIT 300";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $extra = [];
    if ($view === 'quote') {
        $extra['quote_flow'] = crm_task_quote_flow_summary();
    }
    return array_merge(['rows' => $rows, 'stats' => crm_task_center_stats(), 'options' => crm_task_center_options()], $extra);
}

function crm_task_quote_table_cols(string $table): array
{
    if (!db_table_exists($table)) return [];
    try {
        $stmt = db()->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    } catch (Throwable $e) {
        return [];
    }
}

function crm_task_quote_count(string $sql): int
{
    try { return (int)db()->query($sql)->fetchColumn(); } catch (Throwable $e) { return 0; }
}

function crm_task_quote_flow_records(array $quoteCols, array $orderCols, array $shipCols, array $recordWhere = [], array $recordParams = []): array
{
    if (!$quoteCols) return [];
    $q = function (string $col) use ($quoteCols): string {
        return in_array($col, $quoteCols, true) ? "q.`{$col}`" : 'NULL';
    };
    $o = function (string $col) use ($orderCols): string {
        return in_array($col, $orderCols, true) ? "o.`{$col}`" : 'NULL';
    };
    $hasOrder = !empty($orderCols);
    $hasShip = $hasOrder && !empty($shipCols);
    $shipSelect = $hasShip ? "COUNT(s.id) AS shipment_count,
            COALESCE(SUM(COALESCE(s.total_qty,0)),0) AS shipped_qty,
            MAX(s.ship_date) AS last_ship_date,
            SUM(CASE WHEN s.pl_generated_at IS NOT NULL THEN 1 ELSE 0 END) AS pl_count,
            SUM(CASE WHEN s.ci_generated_at IS NOT NULL THEN 1 ELSE 0 END) AS ci_count" :
        "0 AS shipment_count, 0 AS shipped_qty, NULL AS last_ship_date, 0 AS pl_count, 0 AS ci_count";
    $shipJoin = $hasShip ? "LEFT JOIN quote_shipments s ON s.order_id=o.id" : "";
    $orderJoin = $hasOrder ? "LEFT JOIN quote_sales_orders o ON (o.id=q.converted_order_id OR (COALESCE(q.converted_order_no,'')<>'' AND o.order_no=q.converted_order_no) OR (COALESCE(o.quote_no,'')<>'' AND o.quote_no=q.quote_no))" : "";
    $orderGroup = $hasOrder ? ", o.id, o.order_no, o.order_date, o.status, o.shipment_status, o.payment_status, o.paid_amount, o.balance_amount, o.amount, o.currency, o.qty, o.updated_at" : "";
    $whereSql = $recordWhere ? 'WHERE (' . implode(') AND (', $recordWhere) . ')' : '';
    $sql = "SELECT
            q.id AS quote_id,
            {$q('quote_no')} AS quote_no,
            {$q('customer_id')} AS customer_id,
            {$q('customer_name')} AS customer_name,
            {$q('customer_json')} AS customer_json,
            {$q('amount')} AS quote_amount,
            {$q('currency')} AS quote_currency,
            {$q('user_name')} AS quote_owner,
            {$q('created_at')} AS quote_created_at,
            {$q('updated_at')} AS quote_updated_at,
            {$q('submitted_at')} AS submitted_at,
            {$q('approval_status')} AS approval_status,
            {$q('approved_by')} AS approved_by,
            {$q('approved_at')} AS approved_at,
            {$q('rejected_by')} AS rejected_by,
            {$q('rejected_at')} AS rejected_at,
            {$q('reject_reason_category')} AS reject_reason_category,
            {$q('reject_reason_custom')} AS reject_reason_custom,
            {$q('reject_reason_detail')} AS reject_reason_detail,
            {$q('approval_note')} AS approval_note,
            {$q('converted_order_id')} AS converted_order_id,
            {$q('converted_order_no')} AS converted_order_no,
            " . ($orderCols ? "o.id AS order_id, o.order_no, o.order_date, o.status AS order_status, o.shipment_status, o.payment_status, o.paid_amount, o.balance_amount, o.amount AS order_amount, o.currency AS order_currency, o.qty AS order_qty, o.updated_at AS order_updated_at" : "NULL AS order_id, NULL AS order_no, NULL AS order_date, NULL AS order_status, NULL AS shipment_status, NULL AS payment_status, NULL AS paid_amount, NULL AS balance_amount, NULL AS order_amount, NULL AS order_currency, NULL AS order_qty, NULL AS order_updated_at") . ",
            {$shipSelect},
            t.id AS task_id,
            t.status AS task_status,
            t.due_at AS task_due_at,
            t.completed_at AS replied_at,
            t.result AS task_result,
            t.result_note AS task_result_note,
            u.username AS assigned_name
        FROM quote_orders q
        {$orderJoin}
        {$shipJoin}
        LEFT JOIN crm_tasks t ON t.deleted_at IS NULL
            AND t.task_type='quote_followup' COLLATE utf8mb4_unicode_ci
            AND t.source_type='quote' COLLATE utf8mb4_unicode_ci
            AND t.source_id=(CAST(q.id AS CHAR) COLLATE utf8mb4_unicode_ci)
        LEFT JOIN crm_users u ON u.id=t.assigned_user_id
        {$whereSql}
        GROUP BY q.id, t.id {$orderGroup}
        ORDER BY COALESCE(o.updated_at, q.updated_at, q.created_at) DESC
        LIMIT 300";
    try {
        if ($recordParams) {
            $stmt = db()->prepare($sql);
            $stmt->execute($recordParams);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $rows = db()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) { return []; }
    $out = [];
    foreach ($rows as $r) {
        $approval = strtolower((string)($r['approval_status'] ?: 'pending'));
        $orderId = (int)($r['order_id'] ?? 0);
        $paid = (float)($r['paid_amount'] ?? 0);
        $balance = (float)($r['balance_amount'] ?? 0);
        $orderAmount = (float)($r['order_amount'] ?? 0);
        $orderQty = (float)($r['order_qty'] ?? 0);
        $shippedQty = (float)($r['shipped_qty'] ?? 0);
        $pl = (int)($r['pl_count'] ?? 0);
        $ci = (int)($r['ci_count'] ?? 0);
        $taskDone = in_array((string)($r['task_status'] ?? ''), ['done','closed'], true);
        $days = null;
        $base = $r['approved_at'] ?: $r['quote_updated_at'] ?: $r['quote_created_at'] ?: null;
        if ($base && !$taskDone && !$orderId) {
            try { $days = max(0, (int)((time() - strtotime((string)$base)) / 86400)); } catch (Throwable $e) { $days = null; }
        }
        $stages = ['quote'];
        if ($approval === 'pending' || $approval === '') $stages[] = 'review';
        if ($approval === 'rejected') $stages[] = 'review_rejected';
        if ($approval === 'approved') {
            $stages[] = 'quote_done';
            if ($taskDone) $stages[] = 'replied';
            elseif (!$orderId) $stages[] = 'unreplied';
        }
        if ($orderId) {
            $stages[] = 'order';
            $stages[] = $paid > 0 ? 'deposit' : 'deposit_unpaid';
            $stages[] = $balance <= 0 && $orderAmount > 0 ? 'balance_paid' : 'balance_unpaid';
            if ($shippedQty > 0 && ($orderQty <= 0 || $shippedQty < $orderQty)) $stages[] = 'shipping';
            if ($orderQty > 0 && $shippedQty >= $orderQty) $stages[] = 'shipping_done';
            if ($pl > 0 || $ci > 0) $stages[] = 'documents';
            if ($pl <= 0 || $ci <= 0) $stages[] = 'documents_pending';
        }
        $reason = trim(implode(' / ', array_filter([
            (string)($r['reject_reason_category'] ?? ''),
            (string)($r['reject_reason_custom'] ?? ''),
            (string)($r['reject_reason_detail'] ?? ''),
            (string)($r['approval_note'] ?? ''),
        ])));
        $contactName = '';
        $customerJson = json_decode((string)($r['customer_json'] ?? ''), true);
        if (is_array($customerJson)) {
            foreach (['contact_name', 'contact', 'primary_contact', 'person', 'linkman', 'name'] as $contactKey) {
                if (!empty($customerJson[$contactKey]) && is_scalar($customerJson[$contactKey])) {
                    $contactName = (string)$customerJson[$contactKey];
                    break;
                }
            }
            if ($contactName === '' && !empty($customerJson['contacts']) && is_array($customerJson['contacts'])) {
                $firstContact = reset($customerJson['contacts']);
                if (is_array($firstContact)) {
                    foreach (['name', 'contact_name', 'contact', 'person', 'linkman'] as $contactKey) {
                        if (!empty($firstContact[$contactKey]) && is_scalar($firstContact[$contactKey])) {
                            $contactName = (string)$firstContact[$contactKey];
                            break;
                        }
                    }
                }
            }
        }
        $currentStage = $orderId ? ($balance > 0 ? '尾款/收款' : (($pl > 0 || $ci > 0) ? '单证' : '出货')) : ($approval === 'rejected' ? '审核驳回' : ($approval === 'pending' ? '待审核' : ($taskDone ? '客户已回复' : '客户未回复')));
        $out[] = [
            'record_key' => 'quote_' . (int)$r['quote_id'] . '_' . (int)$orderId,
            'quote_id' => (int)$r['quote_id'],
            'quote_no' => (string)($r['quote_no'] ?? ''),
            'customer_id' => (string)($r['customer_id'] ?? ''),
            'customer_name' => (string)($r['customer_name'] ?? ''),
            'contact_name' => $contactName,
            'quote_amount' => (float)($r['quote_amount'] ?? 0),
            'quote_currency' => (string)($r['quote_currency'] ?? ''),
            'quote_owner' => (string)($r['quote_owner'] ?? ''),
            'approval_status' => $approval ?: 'pending',
            'approved_by' => (string)($r['approved_by'] ?? ''),
            'rejected_by' => (string)($r['rejected_by'] ?? ''),
            'reject_reason' => $reason,
            'sent_at' => (string)($r['approved_at'] ?: $r['quote_updated_at'] ?: ''),
            'no_reply_days' => $days,
            'task_id' => (int)($r['task_id'] ?? 0),
            'task_status' => (string)($r['task_status'] ?? ''),
            'task_due_at' => (string)($r['task_due_at'] ?? ''),
            'replied_at' => (string)($r['replied_at'] ?? ''),
            'reply_summary' => (string)($r['task_result_note'] ?: $r['task_result'] ?: ''),
            'assigned_name' => (string)($r['assigned_name'] ?? ''),
            'order_id' => $orderId,
            'order_no' => (string)($r['order_no'] ?? ''),
            'order_date' => (string)($r['order_date'] ?? ''),
            'order_status' => (string)($r['order_status'] ?? ''),
            'payment_status' => (string)($r['payment_status'] ?? ''),
            'shipment_status' => (string)($r['shipment_status'] ?? ''),
            'order_amount' => $orderAmount,
            'order_currency' => (string)($r['order_currency'] ?? ''),
            'paid_amount' => $paid,
            'balance_amount' => $balance,
            'order_qty' => $orderQty,
            'shipped_qty' => $shippedQty,
            'unshipped_qty' => max(0, $orderQty - $shippedQty),
            'shipment_count' => (int)($r['shipment_count'] ?? 0),
            'last_ship_date' => (string)($r['last_ship_date'] ?? ''),
            'pl_status' => $pl > 0 ? '已生成 PL' : '待生成 PL',
            'ci_status' => $ci > 0 ? '已生成 CI' : '待生成 CI',
            'current_stage' => $currentStage,
            'current_status' => $orderId ? ((string)($r['payment_status'] ?: $r['shipment_status'] ?: $r['order_status'] ?: '订单处理中')) : ($approval === 'approved' ? ($taskDone ? '客户已回复' : '客户未回复') : ($approval === 'rejected' ? '审核驳回' : '待审核')),
            'next_action' => $orderId ? ($balance > 0 ? '跟进收款' : (($pl <= 0 || $ci <= 0) ? '生成单证' : '流程完成')) : ($approval === 'pending' ? '审核报价' : ($approval === 'rejected' ? '修改后重提' : ($taskDone ? '推进转订单' : '跟进客户回复'))),
            'updated_at' => (string)($r['order_updated_at'] ?: $r['quote_updated_at'] ?: $r['quote_created_at'] ?: ''),
            'stages' => array_values(array_unique($stages)),
        ];
    }
    return $out;
}

function crm_task_quote_flow_summary(): array
{
    $quoteCols = crm_task_quote_table_cols('quote_orders');
    $orderCols = crm_task_quote_table_cols('quote_sales_orders');
    $shipCols = crm_task_quote_table_cols('quote_shipments');
    $taskScope = crm_task_scope_sql('t');
    $unreplied = crm_task_quote_count("SELECT COUNT(*) FROM crm_tasks t WHERE t.deleted_at IS NULL AND {$taskScope} AND t.task_type='quote_followup' AND t.status NOT IN ('done','closed','cancelled')");
    if ($unreplied <= 0) {
        $unreplied = crm_task_quote_count("SELECT COUNT(*) FROM crm_tasks t WHERE t.deleted_at IS NULL AND t.task_type='quote_followup' AND t.status NOT IN ('done','closed','cancelled')");
    }
    $quoteTotal = $pending = $approved = $rejected = $converted = 0;
    if ($quoteCols) {
        $statusExpr = in_array('approval_status', $quoteCols, true) ? "COALESCE(NULLIF(approval_status,''),'pending')" : "'pending'";
        $quoteTotal = crm_task_quote_count("SELECT COUNT(*) FROM quote_orders");
        $pending = crm_task_quote_count("SELECT COUNT(*) FROM quote_orders WHERE {$statusExpr}='pending'");
        $approved = crm_task_quote_count("SELECT COUNT(*) FROM quote_orders WHERE {$statusExpr}='approved'");
        $rejected = crm_task_quote_count("SELECT COUNT(*) FROM quote_orders WHERE {$statusExpr}='rejected'");
        $convertedParts = [];
        if (in_array('converted_order_id', $quoteCols, true)) $convertedParts[] = 'COALESCE(converted_order_id,0)>0';
        if (in_array('converted_order_no', $quoteCols, true)) $convertedParts[] = "COALESCE(converted_order_no,'')<>''";
        if ($convertedParts) $converted = crm_task_quote_count('SELECT COUNT(*) FROM quote_orders WHERE ' . implode(' OR ', $convertedParts));
    }
    $replyDone = crm_task_quote_count("SELECT COUNT(*) FROM crm_tasks t WHERE t.deleted_at IS NULL AND t.task_type='quote_followup' AND t.status IN ('done','closed')");
    $reviewOverdue = 0;
    if ($quoteCols) {
        $dateExpr = in_array('submitted_at', $quoteCols, true) ? 'submitted_at' : (in_array('updated_at', $quoteCols, true) ? 'updated_at' : 'created_at');
        $reviewOverdue = crm_task_quote_count("SELECT COUNT(*) FROM quote_orders WHERE COALESCE(NULLIF(approval_status,''),'pending')='pending' AND {$dateExpr} IS NOT NULL AND {$dateExpr} < DATE_SUB(NOW(), INTERVAL 1 DAY)");
    }
    $quoteFollowOverdue = crm_task_quote_count("SELECT COUNT(*) FROM crm_tasks t WHERE t.deleted_at IS NULL AND t.task_type='quote_followup' AND t.status NOT IN ('done','closed','cancelled') AND t.due_at IS NOT NULL AND t.due_at < NOW()");

    $orderCount = $deposit = $unpaid = $balanceDue = $paid = 0;
    if ($orderCols) {
        $orderCount = crm_task_quote_count('SELECT COUNT(*) FROM quote_sales_orders');
        if ($converted <= 0) $converted = $orderCount;
        $paidCol = in_array('paid_amount', $orderCols, true) ? 'paid_amount' : '';
        $balanceCol = in_array('balance_amount', $orderCols, true) ? 'balance_amount' : '';
        if ($paidCol) $deposit = crm_task_quote_count("SELECT COUNT(*) FROM quote_sales_orders WHERE COALESCE({$paidCol},0)>0");
        if ($paidCol) $unpaid = crm_task_quote_count("SELECT COUNT(*) FROM quote_sales_orders WHERE COALESCE({$paidCol},0)<=0 AND COALESCE(amount,0)>0");
        if ($balanceCol) $balanceDue = crm_task_quote_count("SELECT COUNT(*) FROM quote_sales_orders WHERE COALESCE({$balanceCol},0)>0 AND COALESCE(amount,0)>0");
        if ($balanceCol) $paid = crm_task_quote_count("SELECT COUNT(*) FROM quote_sales_orders WHERE COALESCE({$balanceCol},0)<=0 AND COALESCE(amount,0)>0");
    }
    $shipment = $partialShipment = $fullShipment = $document = 0;
    if ($shipCols) {
        $shipment = crm_task_quote_count('SELECT COUNT(*) FROM quote_shipments');
        if ($orderCols && in_array('total_qty', $shipCols, true) && in_array('qty', $orderCols, true)) {
            $partialShipment = crm_task_quote_count("SELECT COUNT(*) FROM quote_sales_orders o LEFT JOIN (SELECT order_id, SUM(COALESCE(total_qty,0)) shipped_qty FROM quote_shipments GROUP BY order_id) s ON s.order_id=o.id WHERE COALESCE(s.shipped_qty,0)>0 AND COALESCE(s.shipped_qty,0)<COALESCE(o.qty,0)");
            $fullShipment = crm_task_quote_count("SELECT COUNT(*) FROM quote_sales_orders o LEFT JOIN (SELECT order_id, SUM(COALESCE(total_qty,0)) shipped_qty FROM quote_shipments GROUP BY order_id) s ON s.order_id=o.id WHERE COALESCE(o.qty,0)>0 AND COALESCE(s.shipped_qty,0)>=COALESCE(o.qty,0)");
        }
        $docParts = [];
        if (in_array('pl_generated_at', $shipCols, true)) $docParts[] = 'pl_generated_at IS NOT NULL';
        if (in_array('ci_generated_at', $shipCols, true)) $docParts[] = 'ci_generated_at IS NOT NULL';
        if ($docParts) $document = crm_task_quote_count('SELECT COUNT(*) FROM quote_shipments WHERE ' . implode(' OR ', $docParts));
    }
    $steps = [
        ['key' => 'quote_created', 'label' => '报价创建', 'count' => max($quoteTotal, $pending + $approved + $rejected, $unreplied), 'hint' => '全部报价单', 'state' => 'normal'],
        ['key' => 'review_pending', 'label' => '待审核', 'count' => $pending, 'hint' => '提交后待审核', 'state' => $reviewOverdue ? 'warning' : 'normal'],
        ['key' => 'review_rejected', 'label' => '已驳回', 'count' => $rejected, 'hint' => '需修改重提', 'state' => 'danger'],
        ['key' => 'quote_approved', 'label' => '审核通过', 'count' => $approved, 'hint' => '可发送客户', 'state' => 'success'],
        ['key' => 'customer_unreplied', 'label' => '客户未回复', 'count' => $unreplied, 'hint' => '待业务跟进', 'state' => $quoteFollowOverdue ? 'danger' : 'warning'],
        ['key' => 'customer_replied', 'label' => '客户已回复', 'count' => $replyDone, 'hint' => '跟进已完成', 'state' => 'success'],
        ['key' => 'order_converted', 'label' => '已转订单', 'count' => $converted, 'hint' => 'PI / 订单', 'state' => 'success'],
        ['key' => 'deposit_received', 'label' => '已收订金', 'count' => $deposit, 'hint' => '已有收款', 'state' => 'success'],
        ['key' => 'payment_due', 'label' => '未收/尾款', 'count' => max($unpaid, $balanceDue), 'hint' => '待收款', 'state' => max($unpaid, $balanceDue) ? 'danger' : 'normal'],
        ['key' => 'payment_done', 'label' => '收款完成', 'count' => $paid, 'hint' => '余额结清', 'state' => 'success'],
        ['key' => 'ship_partial', 'label' => '部分出货', 'count' => $partialShipment ?: $shipment, 'hint' => '已有出货批次', 'state' => 'warning'],
        ['key' => 'ship_done', 'label' => '全部出货', 'count' => $fullShipment, 'hint' => '数量已出完', 'state' => 'success'],
        ['key' => 'documents', 'label' => '单证', 'count' => $document, 'hint' => 'PL / CI 已生成', 'state' => 'success'],
    ];
    $risks = [
        ['key' => 'review_overdue', 'label' => '审核超期', 'count' => $reviewOverdue],
        ['key' => 'quote_follow_overdue', 'label' => '客户未回复超期', 'count' => $quoteFollowOverdue],
        ['key' => 'payment_due', 'label' => '未收款/尾款', 'count' => max($unpaid, $balanceDue)],
    ];
    return [
        'steps' => $steps,
        'risks' => $risks,
        'summary' => [
            'quote_total' => max($quoteTotal, $pending + $approved + $rejected),
            'order_total' => $orderCount,
            'unreplied' => $unreplied,
            'overdue' => $reviewOverdue + $quoteFollowOverdue,
            'payment_due' => max($unpaid, $balanceDue),
        ],
        'records' => crm_task_quote_flow_records($quoteCols, $orderCols, $shipCols),
        'updated_at' => date('Y-m-d H:i:s'),
    ];
}

function crm_task_center_stats(): array
{
    $scope = crm_task_scope_sql('t');
    $one = fn($where) => (int)db()->query("SELECT COUNT(*) FROM crm_tasks t WHERE t.deleted_at IS NULL AND {$scope} AND {$where}")->fetchColumn();
    $sampleScope = crm_sample_scope_sql('s');
    $sample = fn($where) => (int)db()->query("SELECT COUNT(*) FROM crm_sample_shipments s WHERE s.deleted_at IS NULL AND {$sampleScope} AND {$where}")->fetchColumn();
    return [
        'total' => $one('1=1'),
        'today' => $one('DATE(t.due_at)=CURDATE()'),
        'overdue' => $one("t.status NOT IN ('done','closed','cancelled') AND t.due_at IS NOT NULL AND t.due_at < NOW()"),
        'confirming' => $one("t.status='confirming'"),
        'done' => $one("t.status='done'"),
        'customer_followup' => $one("t.task_type='customer_followup' AND t.status NOT IN ('done','closed','cancelled')"),
        'ai_pending' => $one("t.task_type='ai_confirm' AND t.status NOT IN ('done','closed','cancelled')"),
        'quote_unreplied' => $one("t.task_type='quote_followup' AND t.status NOT IN ('done','closed','cancelled')"),
        'opportunity_due' => $one("t.task_type='opportunity_followup' AND t.status NOT IN ('done','closed','cancelled')"),
        'dispatch_pending' => $one("t.task_type='dispatch_confirm' AND t.status NOT IN ('done','closed','cancelled')"),
        'promotion_pending' => $one("t.task_type IN ('promotion_manual','group_promotion') AND t.status NOT IN ('done','closed','cancelled')"),
        'samples' => $sample('1=1'),
        'sample_pending_ship' => $sample("s.status IN ('preparing','sample_ready','pending_ship')"),
        'sample_shipped' => $sample("s.status IN ('shipped','in_transit')"),
        'sample_signed' => $sample("s.status IN ('signed','feedback')"),
        'sample_follow_overdue' => $sample("s.status='signed' AND s.followup_time IS NOT NULL AND s.followup_time < NOW()"),
    ];
}

function crm_task_detail(int $id): array
{
    crm_task_center_ensure_tables();
    $task = crm_task_row($id);
    $logs = [];
    try {
        $stmt = db()->prepare("SELECT * FROM crm_operation_logs WHERE module_key='tasks' AND target_type='task' AND target_id=? ORDER BY id DESC LIMIT 80");
        $stmt->execute([(string)$id]);
        $logs = $stmt->fetchAll();
    } catch (Throwable $e) {
        $logs = [];
    }
    $sample = null;
    if (($task['task_type'] ?? '') === 'sample_shipment') {
        $sid = (int)($task['sample_shipment_id'] ?? 0);
        if (!$sid && ($task['source_type'] ?? '') === 'sample_shipment') $sid = (int)($task['source_id'] ?? 0);
        if ($sid > 0) {
            try { $sample = crm_sample_shipment_detail($sid); } catch (Throwable $e) { $sample = null; }
        }
    }
    return ['task' => $task, 'logs' => $logs, 'sample' => $sample];
}

function crm_task_save(array $input): array
{
    crm_task_center_ensure_tables();
    $id = (int)($input['task_id'] ?? 0);
    crm_require($id > 0 ? 'task.edit' : 'task.create');
    $data = [
        'task_type' => preg_replace('/[^a-z0-9_]/i', '', (string)($input['task_type'] ?? 'customer_followup')) ?: 'customer_followup',
        'title' => trim((string)($input['title'] ?? '')),
        'description' => trim((string)($input['description'] ?? '')),
        'source_type' => trim((string)($input['source_type'] ?? '')),
        'source_id' => trim((string)($input['source_id'] ?? '')),
        'customer_id' => (int)($input['customer_id'] ?? 0) ?: null,
        'contact_id' => (int)($input['contact_id'] ?? 0) ?: null,
        'opportunity_id' => (int)($input['opportunity_id'] ?? 0) ?: null,
        'quote_id' => trim((string)($input['quote_id'] ?? '')),
        'assigned_user_id' => (int)($input['assigned_user_id'] ?? ((current_user() ?: [])['id'] ?? 0)) ?: null,
        'priority' => in_array(($input['priority'] ?? 'normal'), ['urgent','important','normal','low'], true) ? $input['priority'] : 'normal',
        'status' => preg_replace('/[^a-z_]/', '', (string)($input['status'] ?? 'pending')) ?: 'pending',
        'due_at' => crm_task_datetime($input['due_at'] ?? ''),
        'reminder_at' => crm_task_datetime($input['reminder_at'] ?? ''),
    ];
    if ($data['title'] === '') throw new RuntimeException('请输入任务标题。');
    $uid = (int)((current_user() ?: [])['id'] ?? 0);
    if ($id > 0) {
        $before = crm_task_row($id);
        db()->prepare("UPDATE crm_tasks SET task_type=?, title=?, description=?, source_type=?, source_id=?, customer_id=?, contact_id=?, opportunity_id=?, quote_id=?, assigned_user_id=?, priority=?, status=?, due_at=?, reminder_at=?, updated_at=NOW() WHERE id=?")
            ->execute(array_merge(array_values($data), [$id]));
        crm_log_event('tasks', 'task_update', 'task', (string)$id, $before, $data);
    } else {
        db()->prepare("INSERT INTO crm_tasks (task_type,title,description,source_type,source_id,customer_id,contact_id,opportunity_id,quote_id,assigned_user_id,collaborator_user_ids_json,priority,status,due_at,reminder_at,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,JSON_ARRAY(),?,?,?,?,?,NOW(),NOW())")
            ->execute([$data['task_type'],$data['title'],$data['description'],$data['source_type'],$data['source_id'],$data['customer_id'],$data['contact_id'],$data['opportunity_id'],$data['quote_id'],$data['assigned_user_id'],$data['priority'],$data['status'],$data['due_at'],$data['reminder_at'],$uid]);
        $id = (int)db()->lastInsertId();
        crm_log_event('tasks', 'task_create', 'task', (string)$id, null, $data);
    }
    if ($data['customer_id']) crm_customer_timeline_add((int)$data['customer_id'], 'task_save', ($id ? '保存任务：' : '新建任务：') . $data['title'], crm_task_type_map()[$data['task_type']] ?? $data['task_type'], 'task', (string)$id);
    if (function_exists('notification_create_task_assigned')) {
        notification_create_task_assigned(array_merge($data, ['id' => $id]));
    }
    return ['task' => crm_task_row($id)];
}

function crm_task_row(int $id): array
{
    $stmt = db()->prepare("SELECT t.*, c.customer_name, c.customer_code, ct.name AS contact_name, o.opportunity_name, u.username AS assigned_name,
            ss.id AS sample_shipment_id, ss.sample_name, ss.courier_company, ss.tracking_no, ss.status AS sample_status
        FROM crm_tasks t
        LEFT JOIN crm_customers c ON c.id=t.customer_id
        LEFT JOIN crm_contacts ct ON ct.id=t.contact_id
        LEFT JOIN crm_opportunities o ON o.id=t.opportunity_id
        LEFT JOIN crm_users u ON u.id=t.assigned_user_id
        LEFT JOIN crm_sample_shipments ss ON ss.task_id=t.id AND ss.deleted_at IS NULL
        WHERE t.id=? AND t.deleted_at IS NULL AND " . crm_task_scope_sql('t') . " LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) throw new RuntimeException('任务不存在或无权查看。');
    return $row;
}

function crm_task_update_status(array $input): array
{
    crm_task_center_ensure_tables();
    $id = (int)($input['task_id'] ?? 0);
    $status = preg_replace('/[^a-z_]/', '', (string)($input['status'] ?? ''));
    if (!$id || !$status) throw new RuntimeException('缺少任务或状态。');
    if ($status === 'done') crm_require('task.complete'); else crm_require('task.edit');
    $before = crm_task_row($id);
    $result = trim((string)($input['result'] ?? ''));
    $note = trim((string)($input['result_note'] ?? ''));
    if ($status === 'done' && $result === '') throw new RuntimeException('标记完成时必须填写完成结果。');
    db()->prepare("UPDATE crm_tasks SET status=?, result=?, result_note=?, completed_at=IF(?='done',NOW(),completed_at), completed_by=IF(?='done',?,completed_by), updated_at=NOW() WHERE id=?")
        ->execute([$status, $result, $note, $status, $status, (int)((current_user() ?: [])['id'] ?? 0), $id]);
    $after = crm_task_row($id);
    crm_log_event('tasks', 'task_status', 'task', (string)$id, $before, $after);
    if ((int)($after['customer_id'] ?? 0) > 0) crm_customer_timeline_add((int)$after['customer_id'], 'task_status', '任务状态更新：' . $after['title'], (crm_task_status_map()[$status] ?? $status) . ($note ? ' · ' . $note : ''), 'task', (string)$id);
    return ['task' => $after];
}

function crm_task_delay(array $input): array
{
    crm_require('task.delay');
    $id = (int)($input['task_id'] ?? 0);
    $due = crm_task_datetime($input['due_at'] ?? '');
    if (!$id || !$due) throw new RuntimeException('请选择延期时间。');
    $before = crm_task_row($id);
    db()->prepare("UPDATE crm_tasks SET due_at=?, status='delayed', updated_at=NOW() WHERE id=?")->execute([$due, $id]);
    $after = crm_task_row($id);
    crm_log_event('tasks', 'task_delay', 'task', (string)$id, $before, $after);
    return ['task' => $after];
}

function crm_task_delete(array $input): array
{
    crm_require('task.delete');
    $id = (int)($input['task_id'] ?? 0);
    if ($id <= 0) throw new RuntimeException('缺少任务。');
    $before = crm_task_row($id);
    db()->prepare("UPDATE crm_tasks SET deleted_at=NOW(), updated_at=NOW() WHERE id=?")->execute([$id]);
    crm_log_event('tasks', 'task_delete', 'task', (string)$id, $before, ['deleted_at' => date('Y-m-d H:i:s')]);
    if ((int)($before['customer_id'] ?? 0) > 0) crm_customer_timeline_add((int)$before['customer_id'], 'task_delete', '删除任务：' . ($before['title'] ?? ''), crm_task_type_map()[$before['task_type']] ?? ($before['task_type'] ?? ''), 'task', (string)$id);
    return ['deleted' => true, 'task_id' => $id];
}

function crm_sample_shipments(array $input = []): array
{
    crm_task_center_ensure_tables();
    crm_require('sample.view');
    $where = ['s.deleted_at IS NULL', crm_sample_scope_sql('s')];
    $params = [];
    $status = trim((string)($input['status'] ?? ''));
    $q = trim((string)($input['q'] ?? ''));
    if ($status !== '') { $where[] = 's.status=?'; $params[] = $status; }
    if (($input['view'] ?? '') === 'my') { $where[] = '(s.owner_user_id=? OR s.created_by=?)'; $uid = (int)((current_user() ?: [])['id'] ?? 0); array_push($params, $uid, $uid); }
    if (($input['view'] ?? '') === 'overdue_follow') $where[] = "s.status='signed' AND s.followup_time IS NOT NULL AND s.followup_time < NOW()";
    if ($q !== '') {
        $where[] = "(s.sample_name LIKE ? OR s.product_model LIKE ? OR s.tracking_no LIKE ? OR c.customer_name LIKE ? OR ct.name LIKE ? OR o.opportunity_name LIKE ?)";
        for ($i = 0; $i < 6; $i++) $params[] = '%' . $q . '%';
    }
    $sql = "SELECT s.*, c.customer_name, ct.name AS contact_name, o.opportunity_name, u.username AS owner_name,
            (SELECT COUNT(*) FROM crm_sample_shipment_files f WHERE f.shipment_id=s.id AND f.deleted_at IS NULL AND f.file_type='image') AS image_count,
            (SELECT COUNT(*) FROM crm_sample_shipment_files f WHERE f.shipment_id=s.id AND f.deleted_at IS NULL AND f.file_type='attachment') AS attachment_count
        FROM crm_sample_shipments s
        LEFT JOIN crm_customers c ON c.id=s.customer_id
        LEFT JOIN crm_contacts ct ON ct.id=s.contact_id
        LEFT JOIN crm_opportunities o ON o.id=s.opportunity_id
        LEFT JOIN crm_users u ON u.id=s.owner_user_id
        WHERE " . implode(' AND ', $where) . " ORDER BY s.id DESC LIMIT 300";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return ['rows' => $stmt->fetchAll(), 'stats' => crm_task_center_stats()];
}

function crm_sample_shipment_save(array $input): array
{
    crm_task_center_ensure_tables();
    $id = (int)($input['shipment_id'] ?? 0);
    crm_require($id ? 'sample.edit' : 'sample.create');
    $status = preg_replace('/[^a-z_]/', '', (string)($input['status'] ?? 'preparing')) ?: 'preparing';
    $tracking = trim((string)($input['tracking_no'] ?? ''));
    if (in_array($status, ['shipped','in_transit','signed','feedback'], true) && $tracking === '') throw new RuntimeException('状态为已寄出/运输/签收时，快递单号必须填写。');
    $customerId = (int)($input['customer_id'] ?? 0);
    if ($customerId <= 0) throw new RuntimeException('请选择客户。');
    $sampleName = trim((string)($input['sample_name'] ?? ''));
    if ($sampleName === '') throw new RuntimeException('请输入样品名称。');
    $uid = (int)((current_user() ?: [])['id'] ?? 0);
    $data = crm_sample_payload($input, $status, $tracking, $customerId, $sampleName);
    $isNew = $id <= 0;
    if ($id > 0) {
        $before = crm_sample_shipment_detail($id);
        crm_sample_update_row($id, $data);
        crm_log_event('tasks', 'sample_update', 'sample_shipment', (string)$id, $before['shipment'], $data);
    } else {
        $taskId = crm_sample_create_task($data);
        $data['task_id'] = $taskId;
        crm_sample_insert_row($data, $uid);
        $id = (int)db()->lastInsertId();
        db()->prepare("UPDATE crm_tasks SET source_type='sample_shipment', source_id=? WHERE id=?")->execute([(string)$id, $taskId]);
        crm_log_event('tasks', 'sample_create', 'sample_shipment', (string)$id, null, $data);
    }
    $detail = crm_sample_shipment_detail($id);
    crm_customer_timeline_add($customerId, $isNew ? 'sample_shipment_create' : 'sample_shipment_save', ($isNew ? '创建样品寄送：' : '保存样品寄送：') . $sampleName, ($data['courier_company'] ?: '未填写快递') . ' · ' . ($tracking ?: '未填写单号'), 'sample_shipment', (string)$id);
    if (!empty($data['create_followup_task']) && !empty($data['followup_time'])) crm_sample_create_followup_task($detail['shipment'], '寄样后跟进：', '样品寄送后续跟进，请确认客户收样和测试反馈。');
    if (!empty($data['create_dispatch_task'])) crm_sample_dispatch_placeholder($detail['shipment']);
    if ($status === 'signed' && !empty($data['followup_time'])) crm_sample_create_signed_followup($detail['shipment']);
    return $detail;
}

function crm_sample_payload(array $input, string $status, string $tracking, int $customerId, string $sampleName): array
{
    return [
        'customer_id' => $customerId,
        'contact_id' => (int)($input['contact_id'] ?? 0) ?: null,
        'opportunity_id' => (int)($input['opportunity_id'] ?? 0) ?: null,
        'quote_id' => trim((string)($input['quote_id'] ?? '')),
        'sample_name' => $sampleName,
        'product_model' => trim((string)($input['product_model'] ?? '')),
        'customer_model' => trim((string)($input['customer_model'] ?? '')),
        'product_category' => trim((string)($input['product_category'] ?? '')),
        'quantity' => max(0.01, (float)($input['quantity'] ?? 1)),
        'unit' => trim((string)($input['unit'] ?? 'pcs')) ?: 'pcs',
        'color' => trim((string)($input['color'] ?? '')),
        'power' => trim((string)($input['power'] ?? '')),
        'cct' => trim((string)($input['cct'] ?? '')),
        'cri' => trim((string)($input['cri'] ?? '')),
        'beam_angle' => trim((string)($input['beam_angle'] ?? '')),
        'is_custom' => !empty($input['is_custom']) ? 1 : 0,
        'recipient_name' => trim((string)($input['recipient_name'] ?? '')),
        'recipient_phone' => trim((string)($input['recipient_phone'] ?? '')),
        'recipient_email' => trim((string)($input['recipient_email'] ?? '')),
        'recipient_whatsapp' => trim((string)($input['recipient_whatsapp'] ?? '')),
        'country' => trim((string)($input['country'] ?? '')),
        'city' => trim((string)($input['city'] ?? '')),
        'address' => trim((string)($input['address'] ?? '')),
        'postal_code' => trim((string)($input['postal_code'] ?? '')),
        'courier_company' => trim((string)($input['courier_company'] ?? '')),
        'tracking_no' => $tracking,
        'shipping_date' => crm_task_date($input['shipping_date'] ?? ''),
        'expected_arrival_date' => crm_task_date($input['expected_arrival_date'] ?? ''),
        'freight_cost' => (float)($input['freight_cost'] ?? 0),
        'currency' => trim((string)($input['currency'] ?? 'USD')) ?: 'USD',
        'freight_payer' => trim((string)($input['freight_payer'] ?? '')),
        'sender_user_id' => (int)($input['sender_user_id'] ?? 0) ?: null,
        'status' => $status,
        'owner_user_id' => (int)($input['owner_user_id'] ?? ((current_user() ?: [])['id'] ?? 0)) ?: null,
        'followup_time' => crm_task_datetime($input['followup_time'] ?? ''),
        'remind_customer_sign' => !empty($input['remind_customer_sign']) ? 1 : 0,
        'remind_owner_follow' => !empty($input['remind_owner_follow']) ? 1 : 0,
        'create_followup_task' => !empty($input['create_followup_task']) ? 1 : 0,
        'create_dispatch_task' => !empty($input['create_dispatch_task']) ? 1 : 0,
        'feedback_note' => trim((string)($input['feedback_note'] ?? '')),
        'remark' => trim((string)($input['remark'] ?? '')),
    ];
}

function crm_sample_create_task(array $data): int
{
    db()->prepare("INSERT INTO crm_tasks (task_type,title,description,source_type,source_id,customer_id,contact_id,opportunity_id,quote_id,assigned_user_id,collaborator_user_ids_json,priority,status,due_at,reminder_at,created_by,created_at,updated_at) VALUES ('sample_shipment',?,?,?,?,?,?,?,?,?,JSON_ARRAY(),'normal','pending',?,?,?,NOW(),NOW())")
        ->execute([
            '样品寄送：' . $data['sample_name'],
            $data['remark'],
            'sample_shipment',
            '',
            $data['customer_id'],
            $data['contact_id'],
            $data['opportunity_id'],
            $data['quote_id'],
            $data['owner_user_id'],
            $data['expected_arrival_date'] ? $data['expected_arrival_date'] . ' 18:00:00' : null,
            $data['followup_time'],
            (int)((current_user() ?: [])['id'] ?? 0),
        ]);
    return (int)db()->lastInsertId();
}

function crm_sample_insert_row(array $data, int $uid): void
{
    $cols = array_keys($data);
    $sql = 'INSERT INTO crm_sample_shipments (' . implode(',', $cols) . ',created_by,created_at,updated_at) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ',?,NOW(),NOW())';
    db()->prepare($sql)->execute(array_merge(array_values($data), [$uid]));
}

function crm_sample_update_row(int $id, array $data): void
{
    $sets = array_map(fn($c) => "{$c}=?", array_keys($data));
    db()->prepare('UPDATE crm_sample_shipments SET ' . implode(',', $sets) . ', updated_at=NOW() WHERE id=?')->execute(array_merge(array_values($data), [$id]));
    if (!empty($data['task_id'])) return;
    $row = crm_sample_shipment_detail($id)['shipment'];
    if (!empty($row['task_id'])) {
        db()->prepare("UPDATE crm_tasks SET title=?, customer_id=?, contact_id=?, opportunity_id=?, quote_id=?, assigned_user_id=?, due_at=?, reminder_at=?, updated_at=NOW() WHERE id=?")
            ->execute(['样品寄送：' . $data['sample_name'], $data['customer_id'], $data['contact_id'], $data['opportunity_id'], $data['quote_id'], $data['owner_user_id'], $data['expected_arrival_date'] ? $data['expected_arrival_date'] . ' 18:00:00' : null, $data['followup_time'], $row['task_id']]);
    }
}

function crm_sample_shipment_detail(int $id): array
{
    crm_task_center_ensure_tables();
    crm_require('sample.view');
    $stmt = db()->prepare("SELECT s.*, c.customer_name, c.customer_code, ct.name AS contact_name, o.opportunity_name, u.username AS owner_name
        FROM crm_sample_shipments s
        LEFT JOIN crm_customers c ON c.id=s.customer_id
        LEFT JOIN crm_contacts ct ON ct.id=s.contact_id
        LEFT JOIN crm_opportunities o ON o.id=s.opportunity_id
        LEFT JOIN crm_users u ON u.id=s.owner_user_id
        WHERE s.id=? AND s.deleted_at IS NULL AND " . crm_sample_scope_sql('s') . " LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) throw new RuntimeException('样品寄送记录不存在或无权查看。');
    return ['shipment' => $row, 'files' => crm_sample_files($id), 'logs' => crm_sample_logs($id)];
}

function crm_sample_files(int $shipmentId): array
{
    $stmt = db()->prepare("SELECT *, CASE WHEN mime_type='application/pdf' THEN 1 ELSE 0 END AS is_pdf FROM crm_sample_shipment_files WHERE shipment_id=? AND deleted_at IS NULL ORDER BY id DESC");
    $stmt->execute([$shipmentId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) $row['file_size_label'] = crm_task_file_size((int)$row['file_size']);
    return $rows;
}

function crm_sample_logs(int $shipmentId): array
{
    $stmt = db()->prepare("SELECT * FROM crm_operation_logs WHERE module_key='tasks' COLLATE utf8mb4_unicode_ci AND ((target_type='sample_shipment' COLLATE utf8mb4_unicode_ci AND target_id=(CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci)) OR (target_type='sample_file' COLLATE utf8mb4_unicode_ci AND target_id IN (SELECT CAST(id AS CHAR) COLLATE utf8mb4_unicode_ci FROM crm_sample_shipment_files WHERE shipment_id=?))) ORDER BY id DESC LIMIT 100");
    try { $stmt->execute([(string)$shipmentId, $shipmentId]); return $stmt->fetchAll(); } catch (Throwable $e) { return []; }
}

function crm_sample_upload_files(int $shipmentId, string $type, array $files): array
{
    crm_task_center_ensure_tables();
    crm_require($type === 'image' ? 'sample.upload_image' : 'sample.upload_file');
    $detail = crm_sample_shipment_detail($shipmentId);
    $shipment = $detail['shipment'];
    $base = __DIR__ . '/uploads/crm_sample_shipments/' . $shipmentId;
    if (!is_dir($base)) mkdir($base, 0775, true);
    $allowedImage = ['image/jpeg','image/png','image/webp'];
    $allowedAttach = ['application/pdf','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','application/zip','application/x-zip-compressed','image/jpeg','image/png','image/webp'];
    $saved = [];
    $names = $files['name'] ?? [];
    if (!is_array($names)) $files = ['name'=>[$files['name'] ?? ''], 'tmp_name'=>[$files['tmp_name'] ?? ''], 'size'=>[$files['size'] ?? 0], 'type'=>[$files['type'] ?? ''], 'error'=>[$files['error'] ?? 0]];
    foreach (($files['name'] ?? []) as $i => $name) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
        $original = basename((string)$name);
        $mime = (string)($files['type'][$i] ?? '');
        $size = (int)($files['size'][$i] ?? 0);
        if ($type === 'image' && !in_array($mime, $allowedImage, true)) throw new RuntimeException('图片仅支持 jpg/png/webp。');
        if ($type === 'attachment' && !in_array($mime, $allowedAttach, true)) throw new RuntimeException('附件类型暂不支持：' . $original);
        if ($type === 'image' && $size > 8 * 1024 * 1024) throw new RuntimeException('单张图片不能超过 8MB。');
        if ($type === 'attachment' && $size > 100 * 1024 * 1024) throw new RuntimeException('单个附件不能超过 100MB。');
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION)) ?: ($type === 'image' ? 'jpg' : 'bin');
        $fileName = date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . preg_replace('/[^a-z0-9]/', '', $ext);
        $target = $base . '/' . $fileName;
        if (!move_uploaded_file((string)$files['tmp_name'][$i], $target)) throw new RuntimeException('文件保存失败：' . $original);
        $rel = 'uploads/crm_sample_shipments/' . $shipmentId . '/' . $fileName;
        db()->prepare("INSERT INTO crm_sample_shipment_files (shipment_id, customer_id, file_type, file_name, original_name, file_path, file_size, mime_type, uploaded_by, uploaded_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())")
            ->execute([$shipmentId, $shipment['customer_id'], $type, $fileName, $original, $rel, $size, $mime, (int)((current_user() ?: [])['id'] ?? 0)]);
        $fid = (int)db()->lastInsertId();
        crm_log_event('tasks', $type === 'image' ? 'sample_image_upload' : 'sample_attachment_upload', 'sample_file', (string)$fid, null, ['shipment_id'=>$shipmentId,'file'=>$original]);
        crm_customer_timeline_add((int)$shipment['customer_id'], $type === 'image' ? 'sample_image_upload' : 'sample_attachment_upload', $type === 'image' ? '上传寄样图片' : '上传寄样附件', $shipment['sample_name'] . ' · ' . $original, 'sample_shipment', (string)$shipmentId);
        $saved[] = $fid;
    }
    return ['files' => crm_sample_files($shipmentId), 'saved_ids' => $saved];
}

function crm_sample_delete_file(int $fileId): array
{
    $stmt = db()->prepare("SELECT f.*, s.sample_name FROM crm_sample_shipment_files f LEFT JOIN crm_sample_shipments s ON s.id=f.shipment_id WHERE f.id=? AND f.deleted_at IS NULL LIMIT 1");
    $stmt->execute([$fileId]);
    $file = $stmt->fetch();
    if (!$file) throw new RuntimeException('文件不存在。');
    crm_require($file['file_type'] === 'image' ? 'sample.delete_image' : 'sample.delete_file');
    db()->prepare("UPDATE crm_sample_shipment_files SET deleted_at=NOW() WHERE id=?")->execute([$fileId]);
    crm_log_event('tasks', 'sample_file_delete', 'sample_file', (string)$fileId, $file, ['deleted'=>1]);
    if ((int)$file['customer_id'] > 0) crm_customer_timeline_add((int)$file['customer_id'], 'sample_file_delete', '删除寄样图片/附件', ($file['sample_name'] ?? '') . ' · ' . ($file['original_name'] ?: $file['file_name']), 'sample_shipment', (string)$file['shipment_id']);
    return ['deleted' => true];
}

function crm_sample_stream_file(int $fileId, bool $inline = false): void
{
    $stmt = db()->prepare("SELECT * FROM crm_sample_shipment_files WHERE id=? AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([$fileId]);
    $file = $stmt->fetch();
    if (!$file) throw new RuntimeException('文件不存在。');
    $path = __DIR__ . '/' . ltrim((string)$file['file_path'], '/');
    if (!is_file($path)) throw new RuntimeException('文件已丢失。');
    header('Content-Type: ' . ($file['mime_type'] ?: 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . rawurlencode($file['original_name'] ?: $file['file_name']) . '"');
    readfile($path);
    exit;
}

function crm_sample_set_status(array $input): array
{
    $id = (int)($input['shipment_id'] ?? 0);
    $status = preg_replace('/[^a-z_]/', '', (string)($input['status'] ?? ''));
    if (!$id || !$status) throw new RuntimeException('缺少样品寄送记录或状态。');
    if ($status === 'shipped') crm_require('sample.shipped');
    elseif ($status === 'signed') crm_require('sample.signed');
    else crm_require('sample.edit');
    $before = crm_sample_shipment_detail($id)['shipment'];
    if (in_array($status, ['shipped','in_transit','signed','feedback'], true) && trim((string)$before['tracking_no']) === '') throw new RuntimeException('标记已寄出/签收前必须填写快递单号。');
    db()->prepare("UPDATE crm_sample_shipments SET status=?, updated_at=NOW() WHERE id=?")->execute([$status, $id]);
    $after = crm_sample_shipment_detail($id)['shipment'];
    if (!empty($after['task_id'])) db()->prepare("UPDATE crm_tasks SET status=?, updated_at=NOW() WHERE id=?")->execute([$status === 'signed' ? 'confirming' : 'processing', $after['task_id']]);
    crm_log_event('tasks', 'sample_status', 'sample_shipment', (string)$id, $before, $after);
    crm_customer_timeline_add((int)$after['customer_id'], 'sample_status', '样品寄送状态：' . $after['sample_name'], crm_sample_status_map()[$status] ?? $status, 'sample_shipment', (string)$id);
    if ($status === 'signed') crm_sample_create_signed_followup($after);
    return crm_sample_shipment_detail($id);
}

function crm_sample_quick_update(array $input): array
{
    crm_task_center_ensure_tables();
    $id = (int)($input['shipment_id'] ?? 0);
    if ($id <= 0) throw new RuntimeException('缺少样品寄送记录。');
    $before = crm_sample_shipment_detail($id)['shipment'];
    $updates = [];
    $action = trim((string)($input['quick_action'] ?? ''));
    $status = preg_replace('/[^a-z_]/', '', (string)($input['status'] ?? ''));

    if (array_key_exists('courier_company', $input)) {
        crm_require('sample.tracking');
        $updates['courier_company'] = trim((string)$input['courier_company']);
    }
    if (array_key_exists('tracking_no', $input)) {
        crm_require('sample.tracking');
        $updates['tracking_no'] = trim((string)$input['tracking_no']);
    }
    if (array_key_exists('feedback_note', $input)) {
        crm_require('sample.edit');
        $updates['feedback_note'] = trim((string)$input['feedback_note']);
    }
    if (array_key_exists('followup_time', $input)) {
        crm_require('sample.edit');
        $updates['followup_time'] = crm_task_datetime($input['followup_time'] ?? '');
    }
    if ($status !== '') {
        if (!array_key_exists($status, crm_sample_status_map())) throw new RuntimeException('样品状态无效。');
        if ($status === 'shipped') crm_require('sample.shipped');
        elseif ($status === 'signed') crm_require('sample.signed');
        else crm_require('sample.edit');
        $tracking = array_key_exists('tracking_no', $updates) ? $updates['tracking_no'] : trim((string)($before['tracking_no'] ?? ''));
        if (in_array($status, ['shipped','in_transit','signed','feedback'], true) && $tracking === '') {
            throw new RuntimeException('标记已寄出/签收前必须填写快递单号。');
        }
        if ($status === 'feedback' && trim((string)($updates['feedback_note'] ?? $before['feedback_note'] ?? '')) === '') {
            throw new RuntimeException('填写客户反馈时必须填写反馈内容。');
        }
        $updates['status'] = $status;
    }
    if (!$updates) throw new RuntimeException('没有可更新的样品寄送属性。');

    $sets = array_map(fn($c) => "{$c}=?", array_keys($updates));
    db()->prepare('UPDATE crm_sample_shipments SET ' . implode(',', $sets) . ', updated_at=NOW() WHERE id=?')
        ->execute(array_merge(array_values($updates), [$id]));
    $after = crm_sample_shipment_detail($id)['shipment'];
    if (!empty($after['task_id']) && isset($updates['status'])) {
        db()->prepare("UPDATE crm_tasks SET status=?, reminder_at=COALESCE(?, reminder_at), updated_at=NOW() WHERE id=?")
            ->execute([$after['status'] === 'signed' ? 'confirming' : 'processing', $after['followup_time'] ?? null, $after['task_id']]);
    }
    crm_log_event('tasks', $action ? 'sample_' . $action : 'sample_quick_update', 'sample_shipment', (string)$id, $before, $after);
    $statusText = isset($updates['status']) ? (crm_sample_status_map()[$after['status']] ?? $after['status']) : '属性更新';
    $note = trim(implode(' · ', array_filter([
        $after['courier_company'] ?? '',
        $after['tracking_no'] ?? '',
        isset($updates['feedback_note']) ? (string)$updates['feedback_note'] : '',
    ])));
    crm_customer_timeline_add((int)$after['customer_id'], 'sample_quick_update', '样品寄送更新：' . ($after['sample_name'] ?? ''), $statusText . ($note ? ' · ' . $note : ''), 'sample_shipment', (string)$id);
    if (($updates['status'] ?? '') === 'signed') crm_sample_create_signed_followup($after);
    return crm_sample_shipment_detail($id);
}

function crm_sample_shipment_delete(array $input): array
{
    crm_require('sample.edit');
    $id = (int)($input['shipment_id'] ?? 0);
    if ($id <= 0) throw new RuntimeException('缺少样品寄送记录。');
    $detail = crm_sample_shipment_detail($id);
    $before = $detail['shipment'];
    db()->prepare("UPDATE crm_sample_shipments SET deleted_at=NOW(), updated_at=NOW() WHERE id=?")->execute([$id]);
    if (!empty($before['task_id'])) db()->prepare("UPDATE crm_tasks SET deleted_at=NOW(), updated_at=NOW() WHERE id=?")->execute([(int)$before['task_id']]);
    crm_log_event('tasks', 'sample_delete', 'sample_shipment', (string)$id, $before, ['deleted_at' => date('Y-m-d H:i:s')]);
    crm_customer_timeline_add((int)$before['customer_id'], 'sample_shipment_delete', '删除样品寄送：' . ($before['sample_name'] ?? ''), ($before['courier_company'] ?: '未填写快递') . ' · ' . ($before['tracking_no'] ?: '未填写单号'), 'sample_shipment', (string)$id);
    return ['deleted' => true, 'shipment_id' => $id];
}

function crm_sample_create_signed_followup(array $shipment): void
{
    if (empty($shipment['followup_time'])) return;
    crm_sample_create_followup_task($shipment, '样品签收跟进：', '客户已签收样品，请跟进测试反馈。快递单号：' . ($shipment['tracking_no'] ?? ''));
}

function crm_sample_create_followup_task(array $shipment, string $prefix, string $description): void
{
    $exists = db()->prepare("SELECT id FROM crm_tasks WHERE source_type='sample_shipment' AND source_id=? AND task_type='customer_followup' AND deleted_at IS NULL LIMIT 1");
    $exists->execute([(string)$shipment['id']]);
    if ($exists->fetchColumn()) return;
    db()->prepare("INSERT INTO crm_tasks (task_type,title,description,source_type,source_id,customer_id,contact_id,opportunity_id,quote_id,assigned_user_id,collaborator_user_ids_json,priority,status,due_at,reminder_at,created_by,created_at,updated_at) VALUES ('customer_followup',?,?,?,?,?,?,?,?,?,JSON_ARRAY(),'important','pending',?,?,?,NOW(),NOW())")
        ->execute([
            $prefix . $shipment['sample_name'],
            $description,
            'sample_shipment',
            (string)$shipment['id'],
            $shipment['customer_id'],
            $shipment['contact_id'],
            $shipment['opportunity_id'],
            $shipment['quote_id'],
            $shipment['owner_user_id'],
            $shipment['followup_time'],
            $shipment['followup_time'],
            (int)((current_user() ?: [])['id'] ?? 0),
        ]);
    $taskId = (int)db()->lastInsertId();
    if (function_exists('notification_create_task_assigned')) {
        try {
            notification_create_task_assigned([
                'id' => $taskId,
                'assigned_user_id' => (int)($shipment['owner_user_id'] ?? 0),
                'title' => $prefix . $shipment['sample_name'],
                'customer_id' => (int)($shipment['customer_id'] ?? 0),
                'contact_id' => (int)($shipment['contact_id'] ?? 0) ?: null,
                'opportunity_id' => (int)($shipment['opportunity_id'] ?? 0) ?: null,
                'quote_id' => (string)($shipment['quote_id'] ?? ''),
            ]);
        } catch (Throwable $e) {
            error_log('sample followup notification failed: ' . $e->getMessage());
        }
    }
    crm_customer_timeline_add((int)$shipment['customer_id'], 'sample_followup_task_create', '创建样品跟进任务', $shipment['sample_name'] . ' · ' . $shipment['followup_time'], 'sample_shipment', (string)$shipment['id']);
}

function crm_sample_dispatch_placeholder(array $shipment): void
{
    crm_log_event('tasks', 'sample_dispatch_requested', 'sample_shipment', (string)$shipment['id'], null, ['status' => 'pending_integration', 'message' => '样品寄送派工接口待接入']);
    crm_customer_timeline_add((int)$shipment['customer_id'], 'sample_dispatch_requested', '样品寄送派工接口待接入', $shipment['sample_name'] ?? '', 'sample_shipment', (string)$shipment['id']);
}

function crm_task_customer_options(array $input): array
{
    $q = trim((string)($input['q'] ?? ''));
    if ($q === '') return ['rows' => []];
    $stmt = db()->prepare("SELECT c.id, c.customer_name, c.customer_code, c.country, c.city, c.address, c.email, c.phone, c.whatsapp,
            (SELECT COUNT(*) FROM crm_contacts ct WHERE ct.customer_id=c.id AND ct.deleted_at IS NULL) AS contact_count
        FROM crm_customers c
        WHERE c.deleted_at IS NULL AND (c.customer_name LIKE ? OR c.customer_code LIKE ? OR c.country LIKE ? OR c.city LIKE ?)
        ORDER BY c.updated_at DESC LIMIT 12");
    $like = '%' . $q . '%';
    $stmt->execute([$like,$like,$like,$like]);
    return ['rows' => $stmt->fetchAll()];
}

function crm_task_customer_contacts(int $customerId): array
{
    $stmt = db()->prepare("SELECT id, name, position, email, phone, whatsapp FROM crm_contacts WHERE customer_id=? AND deleted_at IS NULL ORDER BY is_primary DESC, id ASC");
    $stmt->execute([$customerId]);
    return ['contacts' => $stmt->fetchAll()];
}

function crm_task_datetime($value): ?string
{
    $s = trim((string)$value);
    if ($s === '') return null;
    $s = str_replace('T', ' ', $s);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) $s .= ' 00:00:00';
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $s)) $s .= ':00';
    return $s;
}

function crm_task_date($value): ?string
{
    $s = trim((string)$value);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
}

function crm_task_file_size(int $size): string
{
    if ($size >= 1048576) return round($size / 1048576, 1) . 'MB';
    if ($size >= 1024) return round($size / 1024, 1) . 'KB';
    return $size . 'B';
}

function crm_task_pending_interface(string $name): array
{
    return ['message' => $name . '接口待接入', 'status' => 'pending_interface'];
}

function crm_task_due_from_visit(array $visit): ?string
{
    $date = trim((string)($visit['visit_date'] ?? ''));
    if ($date === '') return null;
    $time = trim((string)($visit['visit_time'] ?? ''));
    if ($time === '') $time = '18:00:00';
    if (preg_match('/^\d{2}:\d{2}$/', $time)) $time .= ':00';
    return $date . ' ' . $time;
}

function crm_task_status_from_visit(array $visit): string
{
    $status = (string)($visit['status'] ?? 'pending_confirm');
    if (in_array($status, ['completed'], true)) return 'done';
    if (in_array($status, ['cancelled'], true)) return 'cancelled';
    if (in_array($status, ['followup_pending', 'pending_confirm'], true)) return 'confirming';
    if (in_array($status, ['executing', 'pending_execute', 'confirmed'], true)) return 'processing';
    if ($status === 'draft') return 'pending';
    return 'pending';
}

function crm_task_upsert_from_visit(array $visit): void
{
    if (!db_table_exists('crm_tasks')) crm_task_center_ensure_tables();
    $visitId = (int)($visit['id'] ?? 0);
    $customerId = (int)($visit['customer_id'] ?? 0);
    if ($visitId <= 0 || $customerId <= 0) return;
    $type = ($visit['visit_type'] ?? '') === 'customer_arrival' ? 'arrival_reception' : 'visit_plan';
    $typeText = $type === 'arrival_reception' ? '来访接待' : '拜访计划';
    $title = trim((string)($visit['title'] ?? '')) ?: $typeText;
    $due = crm_task_due_from_visit($visit);
    $status = crm_task_status_from_visit($visit);
    $uid = (int)((current_user() ?: [])['id'] ?? 0);
    $owner = (int)($visit['owner_user_id'] ?? 0) ?: $uid;
    $description = trim(implode("\n", array_filter([
        trim((string)($visit['planned_note'] ?? '')),
        trim((string)($visit['preparation_note'] ?? '')),
        trim((string)($visit['customer_needs'] ?? '')),
    ])));
    $stmt = db()->prepare("SELECT id FROM crm_tasks WHERE source_type='visit' AND source_id=? AND task_type=? AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([(string)$visitId, $type]);
    $taskId = (int)$stmt->fetchColumn();
    if ($taskId > 0) {
        db()->prepare("UPDATE crm_tasks SET title=?, description=?, customer_id=?, contact_id=?, assigned_user_id=?, priority='important', status=?, due_at=?, reminder_at=?, updated_at=NOW() WHERE id=?")
            ->execute([$title, $description, $customerId, (int)($visit['contact_id'] ?? 0) ?: null, $owner, $status, $due, $due, $taskId]);
    } else {
        db()->prepare("INSERT INTO crm_tasks (task_type,title,description,source_type,source_id,customer_id,contact_id,assigned_user_id,collaborator_user_ids_json,priority,status,due_at,reminder_at,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,JSON_ARRAY(),'important',?,?,?, ?,NOW(),NOW())")
            ->execute([$type, $title, $description, 'visit', (string)$visitId, $customerId, (int)($visit['contact_id'] ?? 0) ?: null, $owner, $status, $due, $due, $uid]);
        $taskId = (int)db()->lastInsertId();
        if (function_exists('notification_create_task_assigned')) {
            notification_create_task_assigned(['id' => $taskId, 'assigned_user_id' => $owner, 'title' => $title, 'customer_id' => $customerId, 'contact_id' => (int)($visit['contact_id'] ?? 0) ?: null]);
        }
    }
    crm_log_event('tasks', 'visit_task_sync', 'task', (string)$taskId, null, ['visit_id' => $visitId, 'task_type' => $type, 'status' => $status]);
}

function crm_task_upsert_visit_action(array $visit, string $taskType, string $titlePrefix, string $description): void
{
    if (!db_table_exists('crm_tasks')) crm_task_center_ensure_tables();
    $visitId = (int)($visit['id'] ?? 0);
    $customerId = (int)($visit['customer_id'] ?? 0);
    if ($visitId <= 0 || $customerId <= 0) return;
    $sourceId = $visitId . ':' . $taskType;
    $uid = (int)((current_user() ?: [])['id'] ?? 0);
    $owner = (int)($visit['owner_user_id'] ?? 0) ?: $uid;
    $due = trim((string)($visit['next_followup_time'] ?? '')) ?: crm_task_due_from_visit($visit);
    $stmt = db()->prepare("SELECT id FROM crm_tasks WHERE source_type='visit_action' AND source_id=? AND task_type=? AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([$sourceId, $taskType]);
    $taskId = (int)$stmt->fetchColumn();
    $title = $titlePrefix . '：' . (trim((string)($visit['title'] ?? '')) ?: ('拜访/来访 #' . $visitId));
    if ($taskId > 0) {
        db()->prepare("UPDATE crm_tasks SET title=?, description=?, customer_id=?, contact_id=?, assigned_user_id=?, status='pending', due_at=?, reminder_at=?, updated_at=NOW() WHERE id=?")
            ->execute([$title, $description, $customerId, (int)($visit['contact_id'] ?? 0) ?: null, $owner, $due, $due, $taskId]);
    } else {
        db()->prepare("INSERT INTO crm_tasks (task_type,title,description,source_type,source_id,customer_id,contact_id,assigned_user_id,collaborator_user_ids_json,priority,status,due_at,reminder_at,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,JSON_ARRAY(),'important','pending',?,?,?,NOW(),NOW())")
            ->execute([$taskType, $title, $description, 'visit_action', $sourceId, $customerId, (int)($visit['contact_id'] ?? 0) ?: null, $owner, $due, $due, $uid]);
        $taskId = (int)db()->lastInsertId();
        if (function_exists('notification_create_task_assigned')) {
            notification_create_task_assigned(['id' => $taskId, 'assigned_user_id' => $owner, 'title' => $title, 'customer_id' => $customerId, 'contact_id' => (int)($visit['contact_id'] ?? 0) ?: null]);
        }
    }
    crm_log_event('tasks', 'visit_action_task_sync', 'task', (string)$taskId, null, ['visit_id' => $visitId, 'task_type' => $taskType]);
}

function crm_task_upsert_from_followup(array $followup): void
{
    if (!db_table_exists('crm_tasks')) crm_task_center_ensure_tables();
    $followupId = (int)($followup['id'] ?? 0);
    $customerId = (int)($followup['customer_id'] ?? 0);
    if ($followupId <= 0 || $customerId <= 0) return;
    $ownerStmt = db()->prepare('SELECT owner_user_id FROM crm_customers WHERE id=? LIMIT 1');
    $ownerStmt->execute([$customerId]);
    $ownerId = (int)$ownerStmt->fetchColumn();
    if ($ownerId <= 0) $ownerId = (int)($followup['created_by'] ?? ((current_user() ?: [])['id'] ?? 0));
    $due = crm_task_datetime($followup['next_remind_time'] ?? '') ?: crm_task_datetime($followup['followup_time'] ?? '');
    $title = '客户跟进：' . substr(trim((string)($followup['content'] ?? '跟进提醒')), 0, 80);
    $description = trim(implode("\n", array_filter([trim((string)($followup['content'] ?? '')), trim((string)($followup['next_plan'] ?? ''))])));
    $status = in_array((string)($followup['status'] ?? 'open'), ['done','completed','closed'], true) ? 'done' : 'pending';
    $stmt = db()->prepare("SELECT id FROM crm_tasks WHERE source_type='followup' AND source_id=? AND task_type='customer_followup' AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([(string)$followupId]);
    $taskId = (int)$stmt->fetchColumn();
    if ($taskId > 0) {
        db()->prepare("UPDATE crm_tasks SET title=?, description=?, customer_id=?, contact_id=?, assigned_user_id=?, status=?, due_at=?, reminder_at=?, updated_at=NOW() WHERE id=?")
            ->execute([$title, $description, $customerId, (int)($followup['contact_id'] ?? 0) ?: null, $ownerId, $status, $due, $due, $taskId]);
    } else {
        db()->prepare("INSERT INTO crm_tasks (task_type,title,description,source_type,source_id,customer_id,contact_id,assigned_user_id,collaborator_user_ids_json,priority,status,due_at,reminder_at,created_by,created_at,updated_at) VALUES ('customer_followup',?,?,?,?,?,?,?,JSON_ARRAY(),'normal',?,?,?, ?,NOW(),NOW())")
            ->execute([$title, $description, 'followup', (string)$followupId, $customerId, (int)($followup['contact_id'] ?? 0) ?: null, $ownerId, $status, $due, $due, (int)($followup['created_by'] ?? ((current_user() ?: [])['id'] ?? 0))]);
        $taskId = (int)db()->lastInsertId();
        if (function_exists('notification_create_task_assigned')) {
            notification_create_task_assigned([
                'id' => $taskId,
                'assigned_user_id' => $ownerId,
                'title' => $title,
                'customer_id' => $customerId,
                'contact_id' => (int)($followup['contact_id'] ?? 0) ?: null,
            ]);
        }
    }
    if (function_exists('notification_create_followup_reminder')) {
        notification_create_followup_reminder(array_merge($followup, [
            'owner_user_id' => $ownerId,
            'customer_name' => $followup['customer_name'] ?? '',
        ]), $taskId);
    }
}
