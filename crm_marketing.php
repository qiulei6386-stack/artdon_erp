<?php
require_once __DIR__ . '/crm_mail.php';
require_once __DIR__ . '/crm_marketing_delivery.php';

function crm_marketing_column_exists(string $table, string $column): bool
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function crm_marketing_add_column_if_missing(string $table, string $column, string $definition): void
{
    if (!crm_marketing_column_exists($table, $column)) {
        db()->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }
}

function crm_marketing_schema_cache_file(): string
{
    $dir = __DIR__ . '/storage/cache';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return is_dir($dir) && is_writable($dir) ? $dir . '/crm_marketing_schema_ensure.json' : sys_get_temp_dir() . '/crm_marketing_schema_ensure.json';
}

function crm_marketing_schema_cache_signature(): string
{
    return sha1(__FILE__ . ':' . (is_file(__FILE__) ? (string)filemtime(__FILE__) : '0'));
}

function crm_marketing_schema_cache_valid(): bool
{
    $file = crm_marketing_schema_cache_file();
    if (!is_file($file)) return false;
    $cache = json_decode((string)@file_get_contents($file), true);
    if (!is_array($cache)) return false;
    $ttl = 21600;
    return ($cache['signature'] ?? '') === crm_marketing_schema_cache_signature()
        && (int)($cache['checked_at'] ?? 0) > time() - $ttl;
}

function crm_marketing_schema_cache_write(): void
{
    crm_marketing_cache_write_file(crm_marketing_schema_cache_file(), json_encode([
        'signature' => crm_marketing_schema_cache_signature(),
        'checked_at' => time(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function crm_marketing_cache_write_file(string $file, string $content): void
{
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $tmp = $dir . '/.' . basename($file) . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $content) === false) return;
    @rename($tmp, $file);
    if (is_file($tmp)) @unlink($tmp);
}

function crm_marketing_ensure_tables(): void
{
    if (!empty($GLOBALS['crm_schema_ready'])) return;
    static $done = false;
    if ($done) return;
    $done = true;
    if (crm_marketing_schema_cache_valid()) return;

    db()->exec("CREATE TABLE IF NOT EXISTS crm_marketing_tasks (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        task_name VARCHAR(190) NOT NULL,
        channel_key VARCHAR(120) NOT NULL,
        task_status VARCHAR(40) NOT NULL DEFAULT 'pending',
        schedule_type VARCHAR(40) NOT NULL DEFAULT 'manual',
        scheduled_at DATETIME NULL,
        customer_count INT NOT NULL DEFAULT 0,
        contact_count INT NOT NULL DEFAULT 0,
        success_count INT NOT NULL DEFAULT 0,
        failed_count INT NOT NULL DEFAULT 0,
        created_by INT UNSIGNED NULL,
        assigned_to INT UNSIGNED NULL,
        remark VARCHAR(500) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_marketing_task_status (task_status),
        KEY idx_marketing_task_channel (channel_key),
        KEY idx_marketing_task_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    crm_marketing_add_column_if_missing('crm_marketing_tasks', 'campaign_type', "VARCHAR(60) NOT NULL DEFAULT 'email' AFTER channel_key");
    crm_marketing_add_column_if_missing('crm_marketing_tasks', 'mail_subject', "VARCHAR(255) NULL AFTER campaign_type");
    crm_marketing_add_column_if_missing('crm_marketing_tasks', 'mail_body_html', "MEDIUMTEXT NULL AFTER mail_subject");
    crm_marketing_add_column_if_missing('crm_marketing_tasks', 'signature_key', "VARCHAR(120) NULL AFTER mail_body_html");
    crm_marketing_add_column_if_missing('crm_marketing_tasks', 'attachment_config_json', "JSON NULL AFTER signature_key");
    crm_marketing_add_column_if_missing('crm_marketing_tasks', 'audience_config_json', "JSON NULL AFTER attachment_config_json");
    crm_marketing_add_column_if_missing('crm_marketing_tasks', 'send_rule_json', "JSON NULL AFTER audience_config_json");
    crm_marketing_add_column_if_missing('crm_marketing_tasks', 'schedule_config_json', "JSON NULL AFTER send_rule_json");
    crm_marketing_add_column_if_missing('crm_marketing_tasks', 'failure_policy_json', "JSON NULL AFTER schedule_config_json");
    crm_marketing_add_column_if_missing('crm_marketing_tasks', 'risk_summary_json', "JSON NULL AFTER failure_policy_json");

    db()->exec("CREATE TABLE IF NOT EXISTS crm_marketing_send_queue (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        task_id BIGINT UNSIGNED NOT NULL,
        campaign_id BIGINT UNSIGNED NULL,
        customer_id INT UNSIGNED NOT NULL,
        contact_id INT UNSIGNED NULL,
        sender_user_id INT UNSIGNED NULL,
        sender_email VARCHAR(190) NOT NULL,
        receiver_email VARCHAR(190) NOT NULL,
        subject VARCHAR(500) NOT NULL,
        body MEDIUMTEXT NOT NULL,
        attachment_json JSON NULL,
        country VARCHAR(120) NULL,
        customer_timezone VARCHAR(80) NULL,
        planned_customer_time DATETIME NULL,
        planned_server_time DATETIME NOT NULL,
        send_status VARCHAR(40) NOT NULL DEFAULT 'pending',
        send_attempts INT NOT NULL DEFAULT 0,
        max_attempts INT NOT NULL DEFAULT 1,
        last_error VARCHAR(1000) NULL,
        sent_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_marketing_queue_target (task_id, contact_id, receiver_email),
        KEY idx_marketing_queue_due (send_status, planned_server_time),
        KEY idx_marketing_queue_task (task_id),
        KEY idx_marketing_queue_customer (customer_id),
        KEY idx_marketing_queue_sender (sender_email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    crm_marketing_add_column_if_missing('crm_marketing_send_queue', 'body_ref_id', "BIGINT UNSIGNED NULL AFTER body");
    db()->exec("CREATE TABLE IF NOT EXISTS crm_marketing_queue_bodies (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        task_id BIGINT UNSIGNED NOT NULL,
        body_hash CHAR(64) NOT NULL,
        body_html MEDIUMTEXT NOT NULL,
        body_bytes INT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_marketing_queue_body (task_id, body_hash),
        KEY idx_marketing_queue_body_task (task_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS crm_marketing_task_targets (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        task_id BIGINT UNSIGNED NOT NULL,
        customer_id INT UNSIGNED NOT NULL,
        contact_id INT UNSIGNED NULL,
        chat_group_id INT UNSIGNED NULL,
        channel_key VARCHAR(120) NOT NULL,
        target_status VARCHAR(40) NOT NULL DEFAULT 'pending',
        failure_reason VARCHAR(500) NULL,
        executed_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_marketing_target_task (task_id),
        KEY idx_marketing_target_customer (customer_id),
        KEY idx_marketing_target_contact (contact_id),
        KEY idx_marketing_target_chat_group (chat_group_id),
        KEY idx_marketing_target_status (target_status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    crm_marketing_add_column_if_missing('crm_marketing_task_targets', 'chat_group_id', "INT UNSIGNED NULL AFTER contact_id");
    crm_marketing_add_column_if_missing('crm_marketing_task_targets', 'contact_method', "VARCHAR(255) NULL AFTER channel_key");
    crm_marketing_add_column_if_missing('crm_marketing_task_targets', 'manual_group_name', "VARCHAR(255) NULL AFTER contact_method");
    crm_marketing_add_column_if_missing('crm_marketing_task_targets', 'executor_user_id', "INT UNSIGNED NULL AFTER manual_group_name");
    crm_marketing_add_column_if_missing('crm_marketing_task_targets', 'planned_at', "DATETIME NULL AFTER executor_user_id");
    crm_marketing_add_column_if_missing('crm_marketing_task_targets', 'due_at', "DATETIME NULL AFTER planned_at");
    crm_marketing_add_column_if_missing('crm_marketing_task_targets', 'manual_result', "VARCHAR(120) NULL AFTER executed_at");
    crm_marketing_add_column_if_missing('crm_marketing_task_targets', 'manual_remark', "TEXT NULL AFTER manual_result");
    crm_marketing_add_column_if_missing('crm_marketing_task_targets', 'manual_attachment_json', "TEXT NULL AFTER manual_remark");
    crm_marketing_add_column_if_missing('crm_marketing_task_targets', 'manual_checked_by_user_id', "INT UNSIGNED NULL AFTER manual_attachment_json");

    db()->exec("CREATE TABLE IF NOT EXISTS crm_marketing_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        task_id BIGINT UNSIGNED NULL,
        customer_id INT UNSIGNED NULL,
        contact_id INT UNSIGNED NULL,
        channel_key VARCHAR(120) NOT NULL,
        action_key VARCHAR(120) NOT NULL DEFAULT 'manual_touch',
        result_status VARCHAR(40) NOT NULL DEFAULT 'success',
        failure_reason VARCHAR(500) NULL,
        operator_id INT UNSIGNED NULL,
        detail_json JSON NULL,
        touched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_marketing_log_task (task_id),
        KEY idx_marketing_log_customer (customer_id),
        KEY idx_marketing_log_contact (contact_id),
        KEY idx_marketing_log_channel (channel_key),
        KEY idx_marketing_log_time (touched_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS crm_marketing_groups (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        group_name VARCHAR(190) NOT NULL,
        group_color VARCHAR(40) NOT NULL DEFAULT '#2563eb',
        remark VARCHAR(500) NULL,
        sort_order INT NOT NULL DEFAULT 100,
        is_enabled TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT UNSIGNED NULL,
        updated_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        deleted_at DATETIME NULL,
        UNIQUE KEY uk_marketing_group_name (group_name, deleted_at),
        KEY idx_marketing_group_enabled (is_enabled, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    crm_marketing_add_column_if_missing('crm_marketing_groups', 'group_type', "VARCHAR(60) NOT NULL DEFAULT 'normal' AFTER group_color");
    crm_marketing_add_column_if_missing('crm_marketing_groups', 'owner_id', "INT UNSIGNED NULL AFTER group_type");
    crm_marketing_add_column_if_missing('crm_marketing_groups', 'visibility', "VARCHAR(40) NOT NULL DEFAULT 'public' AFTER owner_id");
    crm_marketing_add_column_if_missing('crm_marketing_groups', 'tags', "VARCHAR(500) NULL AFTER visibility");
    crm_marketing_add_column_if_missing('crm_marketing_groups', 'description', "VARCHAR(1000) NULL AFTER tags");
    crm_marketing_add_column_if_missing('crm_marketing_groups', 'status', "VARCHAR(40) NOT NULL DEFAULT 'active' AFTER description");
    crm_marketing_add_column_if_missing('crm_marketing_groups', 'archived_at', "DATETIME NULL AFTER updated_at");

    db()->exec("CREATE TABLE IF NOT EXISTS crm_marketing_group_customers (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        group_id BIGINT UNSIGNED NOT NULL,
        customer_id INT UNSIGNED NOT NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_marketing_group_customer (group_id, customer_id),
        KEY idx_marketing_group_customer_group (group_id),
        KEY idx_marketing_group_customer_customer (customer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS crm_marketing_group_contacts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        group_id BIGINT UNSIGNED NOT NULL,
        contact_id INT UNSIGNED NOT NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_marketing_group_contact (group_id, contact_id),
        KEY idx_marketing_group_contact_group (group_id),
        KEY idx_marketing_group_contact_contact (contact_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS crm_marketing_templates (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        template_key VARCHAR(120) NOT NULL,
        channel_key VARCHAR(120) NOT NULL DEFAULT 'email',
        template_name VARCHAR(190) NOT NULL,
        mail_subject VARCHAR(255) NULL,
        body_html MEDIUMTEXT NULL,
        action_note VARCHAR(500) NULL,
        source_key VARCHAR(120) NULL,
        created_by INT UNSIGNED NULL,
        updated_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        deleted_at DATETIME NULL,
        UNIQUE KEY uk_marketing_template_key (template_key),
        KEY idx_marketing_template_channel (channel_key),
        KEY idx_marketing_template_deleted (deleted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (!function_exists('db_table_exists') || db_table_exists('crm_contact_promotions')) {
        db()->exec("ALTER TABLE crm_contact_promotions MODIFY status ENUM('active','stopped','no_contact','paused','failed') NOT NULL DEFAULT 'active'");
    }

    crm_marketing_ensure_permissions();
    crm_marketing_schema_cache_write();
}

function crm_marketing_ensure_permissions(): void
{
    $permissions = [
        ['promotion.view', 'promotion', 'view', '查看推广中心', 'medium'],
        ['promotion.manage', 'promotion', 'manage', '管理推广池和联系人策略', 'high'],
        ['promotion.create_group', 'promotion', 'create_group', '创建推广分组', 'medium'],
        ['promotion.edit_group', 'promotion', 'edit_group', '编辑推广分组', 'medium'],
        ['promotion.delete_group', 'promotion', 'delete_group', '删除推广分组', 'high'],
        ['promotion.move_customer', 'promotion', 'move_customer', '移动推广客户分组', 'medium'],
        ['promotion.task_create', 'promotion', 'task_create', '创建推广任务', 'high'],
        ['promotion.execute', 'promotion', 'execute', '执行推广任务', 'high'],
        ['promotion.analytics', 'promotion', 'analytics', '查看推广转化分析', 'medium'],
        ['promotion.delete_project', 'promotion', 'delete_project', '删除推广任务', 'high'],
    ];
    $stmt = db()->prepare('INSERT IGNORE INTO crm_permissions (permission_key, module, action, description, risk_level, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
    foreach ($permissions as $permission) $stmt->execute($permission);
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key) SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p WHERE r.role_key = 'super_admin' AND p.module = 'promotion'");
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key) SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p WHERE r.role_key IN ('admin','manager','sales','marketing') AND p.permission_key IN ('promotion.view','promotion.manage','promotion.task_create','promotion.execute','promotion.analytics')");
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key) SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p WHERE r.role_key IN ('admin','manager','sales','marketing') AND p.permission_key IN ('promotion.create_group','promotion.edit_group','promotion.delete_group','promotion.move_customer')");
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key) SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p WHERE r.role_key IN ('admin','manager','marketing') AND p.permission_key = 'promotion.delete_project'");
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key) SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p WHERE r.role_key IN ('viewer','finance') AND p.permission_key IN ('promotion.view')");
}

function crm_marketing_bootstrap_cache_dir(): string
{
    $dir = __DIR__ . '/storage/cache';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return is_dir($dir) && is_writable($dir) ? $dir : sys_get_temp_dir();
}

function crm_marketing_bootstrap_cache_get(string $key, int $ttl = 60)
{
    $file = crm_marketing_bootstrap_cache_dir() . '/crm_marketing_bootstrap_' . sha1($key) . '.json';
    if (!is_file($file) || filemtime($file) < time() - $ttl) return null;
    $data = json_decode((string)@file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

function crm_marketing_bootstrap_cache_set(string $key, array $value): array
{
    $file = crm_marketing_bootstrap_cache_dir() . '/crm_marketing_bootstrap_' . sha1($key) . '.json';
    crm_marketing_cache_write_file($file, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $value;
}

function crm_marketing_bootstrap_cached(string $key, callable $loader): array
{
    $userId = (int)(current_user()['id'] ?? 0);
    $cacheKey = $key . ':u' . $userId;
    $cached = crm_marketing_bootstrap_cache_get($cacheKey);
    if (is_array($cached)) return $cached;
    $value = $loader();
    return is_array($value) ? crm_marketing_bootstrap_cache_set($cacheKey, $value) : [];
}

function crm_marketing_bootstrap_cache_clear(): void
{
    foreach (glob(crm_marketing_bootstrap_cache_dir() . '/crm_marketing_bootstrap_*.json') ?: [] as $file) {
        @unlink($file);
    }
}

function crm_marketing_groups(): array
{
    crm_marketing_ensure_tables();
    crm_require('promotion.view');
    $stmt = db()->query("SELECT g.*,
        COALESCE(x.customer_count, 0) AS customer_count,
        COALESCE(y.contact_count, 0) AS contact_count,
        COALESCE(z.promotable_contact_count, 0) AS promotable_contact_count,
        COALESCE(co.country_distribution, '') AS country_distribution,
        COALESCE(mt.task_count, 0) AS task_count,
        ml.last_touch_time,
        u.username AS created_by_name,
        COALESCE(owner.real_name, owner.username, '') AS owner_name
        FROM crm_marketing_groups g
        LEFT JOIN (
            SELECT grouped_customers.group_id, COUNT(*) AS customer_count
            FROM (
                SELECT rg.group_id, rg.customer_id
                FROM crm_marketing_group_customers rg
                JOIN crm_customers c ON c.id = rg.customer_id AND c.deleted_at IS NULL
                UNION
                SELECT rg.group_id, ct.customer_id
                FROM crm_marketing_group_contacts rg
                JOIN crm_contacts ct ON ct.id = rg.contact_id AND ct.deleted_at IS NULL
                JOIN crm_customers c ON c.id = ct.customer_id AND c.deleted_at IS NULL
            ) grouped_customers
            GROUP BY grouped_customers.group_id
        ) x ON x.group_id = g.id
        LEFT JOIN (
            SELECT grouped_contacts.group_id, COUNT(*) AS contact_count
            FROM (
                SELECT rg.group_id, ct.id AS contact_id
                FROM crm_marketing_group_customers rg
                JOIN crm_contacts ct ON ct.customer_id = rg.customer_id AND ct.deleted_at IS NULL
                JOIN crm_customers c ON c.id = ct.customer_id AND c.deleted_at IS NULL
                UNION
                SELECT rg.group_id, ct.id AS contact_id
                FROM crm_marketing_group_contacts rg
                JOIN crm_contacts ct ON ct.id = rg.contact_id AND ct.deleted_at IS NULL
                JOIN crm_customers c ON c.id = ct.customer_id AND c.deleted_at IS NULL
            ) grouped_contacts
            GROUP BY grouped_contacts.group_id
        ) y ON y.group_id = g.id
        LEFT JOIN (
            SELECT promotable_contacts.group_id, COUNT(*) AS promotable_contact_count
            FROM (
                SELECT rg.group_id, ct.id AS contact_id
                FROM crm_marketing_group_customers rg
                JOIN crm_contacts ct ON ct.customer_id = rg.customer_id AND ct.deleted_at IS NULL
                JOIN crm_customers c ON c.id = ct.customer_id AND c.deleted_at IS NULL
                WHERE COALESCE(c.do_not_contact,0)=0 AND COALESCE(ct.is_left,0)=0 AND COALESCE(ct.do_not_contact,0)=0 AND COALESCE(ct.unsubscribe_email,0)=0 AND COALESCE(ct.email,'') <> ''
                UNION
                SELECT rg.group_id, ct.id AS contact_id
                FROM crm_marketing_group_contacts rg
                JOIN crm_contacts ct ON ct.id = rg.contact_id AND ct.deleted_at IS NULL
                JOIN crm_customers c ON c.id = ct.customer_id AND c.deleted_at IS NULL
                WHERE COALESCE(c.do_not_contact,0)=0 AND COALESCE(ct.is_left,0)=0 AND COALESCE(ct.do_not_contact,0)=0 AND COALESCE(ct.unsubscribe_email,0)=0 AND COALESCE(ct.email,'') <> ''
            ) promotable_contacts
            GROUP BY promotable_contacts.group_id
        ) z ON z.group_id = g.id
        LEFT JOIN (
            SELECT group_id, GROUP_CONCAT(CONCAT(country, ' ', total) ORDER BY total DESC SEPARATOR ', ') AS country_distribution
            FROM (
                SELECT rg.group_id, COALESCE(NULLIF(c.country,''), '未填') AS country, COUNT(*) AS total
                FROM crm_marketing_group_customers rg
                JOIN crm_customers c ON c.id = rg.customer_id AND c.deleted_at IS NULL
                GROUP BY rg.group_id, COALESCE(NULLIF(c.country,''), '未填')
            ) country_rows GROUP BY group_id
        ) co ON co.group_id = g.id
        LEFT JOIN (
            SELECT CAST(JSON_UNQUOTE(JSON_EXTRACT(audience_config_json, '$.group_key')) AS UNSIGNED) AS group_id, COUNT(*) AS task_count
            FROM crm_marketing_tasks
            WHERE JSON_UNQUOTE(JSON_EXTRACT(audience_config_json, '$.group_mode')) = 'group'
            GROUP BY CAST(JSON_UNQUOTE(JSON_EXTRACT(audience_config_json, '$.group_key')) AS UNSIGNED)
        ) mt ON mt.group_id = g.id
        LEFT JOIN (
            SELECT rg.group_id, MAX(ml.touched_at) AS last_touch_time
            FROM crm_marketing_group_customers rg
            JOIN crm_marketing_logs ml ON ml.customer_id = rg.customer_id
            GROUP BY rg.group_id
        ) ml ON ml.group_id = g.id
        LEFT JOIN crm_users u ON u.id = g.created_by
        LEFT JOIN crm_users owner ON owner.id = g.owner_id
        WHERE g.deleted_at IS NULL
        ORDER BY g.sort_order, g.id");
    return $stmt->fetchAll();
}

function crm_marketing_group_save(array $input): array
{
    crm_marketing_ensure_tables();
    $id = (int)($input['group_id'] ?? $input['id'] ?? 0);
    crm_require($id > 0 ? 'promotion.edit_group' : 'promotion.create_group');
    $name = trim((string)($input['group_name'] ?? ''));
    if ($name === '') throw new RuntimeException('请输入推广分组名称。');
    $dup = db()->prepare('SELECT id FROM crm_marketing_groups WHERE group_name = ? AND deleted_at IS NULL AND id <> ? LIMIT 1');
    $dup->execute([$name, $id]);
    if ($dup->fetchColumn()) throw new RuntimeException('推广分组名称已存在：' . $name);
    $color = trim((string)($input['group_color'] ?? '#2563eb'));
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#2563eb';
    $remark = trim((string)($input['remark'] ?? ''));
    $description = trim((string)($input['description'] ?? $remark));
    $groupType = trim((string)($input['group_type'] ?? 'normal'));
    if (!in_array($groupType, ['normal','country','exhibition','key_account','temporary'], true)) $groupType = 'normal';
    $ownerId = (int)($input['owner_id'] ?? 0);
    $visibility = trim((string)($input['visibility'] ?? 'public'));
    if (!in_array($visibility, ['public','private','specified'], true)) $visibility = 'public';
    $tags = trim((string)($input['tags'] ?? ''));
    $status = trim((string)($input['status'] ?? ((int)($input['is_enabled'] ?? 1) ? 'active' : 'disabled')));
    if (!in_array($status, ['active','disabled','archived'], true)) $status = 'active';
    $sort = (int)($input['sort_order'] ?? 100);
    if ($id > 0) {
        $before = db()->prepare('SELECT * FROM crm_marketing_groups WHERE id = ? AND deleted_at IS NULL LIMIT 1');
        $before->execute([$id]);
        $row = $before->fetch();
        if (!$row) throw new RuntimeException('推广分组不存在。');
        db()->prepare('UPDATE crm_marketing_groups SET group_name=?, group_color=?, group_type=?, owner_id=?, visibility=?, tags=?, description=?, remark=?, status=?, is_enabled=?, archived_at=IF(?="archived", COALESCE(archived_at, NOW()), NULL), sort_order=?, updated_by=?, updated_at=NOW() WHERE id=?')
            ->execute([$name, $color, $groupType, $ownerId ?: null, $visibility, $tags, $description, $remark, $status, $status === 'disabled' ? 0 : 1, $status, $sort, current_user()['id'] ?? null, $id]);
        crm_log_event('promotion', 'group_update', 'marketing_group', (string)$id, $row, ['group_name' => $name, 'group_type' => $groupType, 'owner_id' => $ownerId, 'visibility' => $visibility, 'tags' => $tags, 'status' => $status]);
    } else {
        db()->prepare('INSERT INTO crm_marketing_groups (group_name, group_color, group_type, owner_id, visibility, tags, description, remark, status, is_enabled, archived_at, sort_order, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())')
            ->execute([$name, $color, $groupType, $ownerId ?: null, $visibility, $tags, $description, $remark, $status, $status === 'disabled' ? 0 : 1, $status === 'archived' ? date('Y-m-d H:i:s') : null, $sort, current_user()['id'] ?? null, current_user()['id'] ?? null]);
        $id = (int)db()->lastInsertId();
        crm_log_event('promotion', 'group_create', 'marketing_group', (string)$id, null, ['group_name' => $name, 'group_type' => $groupType, 'owner_id' => $ownerId, 'visibility' => $visibility, 'tags' => $tags, 'status' => $status]);
    }
    crm_marketing_bootstrap_cache_clear();
    return ['group_id' => $id, 'groups' => crm_marketing_groups()];
}

function crm_marketing_group_status_update(array $input): array
{
    crm_marketing_ensure_tables();
    crm_require('promotion.edit_group');
    $ids = crm_mail_input_ids($input['group_ids'] ?? ($input['group_id'] ?? []));
    $status = trim((string)($input['status'] ?? 'active'));
    if (!$ids) throw new RuntimeException('请选择客户组。');
    if (!in_array($status, ['active','disabled','archived'], true)) throw new RuntimeException('客户组状态无效。');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("UPDATE crm_marketing_groups SET status=?, is_enabled=?, archived_at=IF(?='archived', COALESCE(archived_at, NOW()), NULL), updated_by=?, updated_at=NOW() WHERE id IN ({$placeholders}) AND deleted_at IS NULL");
    $stmt->execute(array_merge([$status, $status === 'disabled' ? 0 : 1, $status, current_user()['id'] ?? null], $ids));
    crm_log_event('promotion', 'group_status_update', 'marketing_group', implode(',', $ids), null, ['group_ids' => $ids, 'status' => $status, 'affected' => $stmt->rowCount()]);
    crm_marketing_bootstrap_cache_clear();
    return ['groups' => crm_marketing_groups(), 'affected' => $stmt->rowCount()];
}

function crm_marketing_copy_name(string $table, string $nameColumn, string $baseName): string
{
    $base = trim($baseName) !== '' ? trim($baseName) : '未命名';
    $candidate = $base . ' - 副本';
    $i = 2;
    $hasDeletedAt = crm_marketing_column_exists($table, 'deleted_at');
    while (true) {
        $sql = "SELECT COUNT(*) FROM {$table} WHERE {$nameColumn} = ?" . ($hasDeletedAt ? ' AND deleted_at IS NULL' : '');
        $stmt = db()->prepare($sql);
        $stmt->execute([$candidate]);
        if ((int)$stmt->fetchColumn() === 0) return $candidate;
        $candidate = $base . ' - 副本 ' . $i;
        $i++;
    }
}

function crm_marketing_group_copy(array $input): array
{
    crm_marketing_ensure_tables();
    crm_require('promotion.create_group');
    $id = (int)($input['group_id'] ?? 0);
    if ($id <= 0) throw new RuntimeException('请选择要复制的推广分组。');
    $stmt = db()->prepare('SELECT * FROM crm_marketing_groups WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([$id]);
    $source = $stmt->fetch();
    if (!$source) throw new RuntimeException('推广分组不存在。');
    $newName = crm_marketing_copy_name('crm_marketing_groups', 'group_name', (string)$source['group_name']);
    db()->prepare('INSERT INTO crm_marketing_groups (group_name, group_color, remark, sort_order, is_enabled, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())')
        ->execute([$newName, $source['group_color'] ?: '#2563eb', $source['remark'] ?? '', (int)$source['sort_order'] + 1, (int)$source['is_enabled'], current_user()['id'] ?? null, current_user()['id'] ?? null]);
    $newId = (int)db()->lastInsertId();
    db()->prepare('INSERT IGNORE INTO crm_marketing_group_customers (group_id, customer_id, created_by, created_at) SELECT ?, customer_id, ?, NOW() FROM crm_marketing_group_customers WHERE group_id = ?')
        ->execute([$newId, current_user()['id'] ?? null, $id]);
    db()->prepare('INSERT IGNORE INTO crm_marketing_group_contacts (group_id, contact_id, created_by, created_at) SELECT ?, contact_id, ?, NOW() FROM crm_marketing_group_contacts WHERE group_id = ?')
        ->execute([$newId, current_user()['id'] ?? null, $id]);
    crm_log_event('promotion', 'group_copy', 'marketing_group', (string)$newId, ['source_id' => $id, 'source_name' => $source['group_name']], ['new_id' => $newId, 'new_name' => $newName]);
    crm_marketing_bootstrap_cache_clear();
    return ['ok' => true, 'new_id' => $newId, 'new_name' => $newName, 'message' => '客户组已复制', 'groups' => crm_marketing_groups()];
}

function crm_marketing_group_delete(array $input): array
{
    crm_marketing_ensure_tables();
    crm_require('promotion.delete_group');
    $id = (int)($input['group_id'] ?? 0);
    if ($id <= 0) throw new RuntimeException('推广分组 ID 无效。');
    $stmt = db()->prepare('SELECT * FROM crm_marketing_groups WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([$id]);
    $before = $stmt->fetch();
    if (!$before) throw new RuntimeException('推广分组不存在。');
    db()->prepare('UPDATE crm_marketing_groups SET deleted_at=NOW(), updated_by=?, updated_at=NOW() WHERE id=?')->execute([current_user()['id'] ?? null, $id]);
    db()->prepare('DELETE FROM crm_marketing_group_customers WHERE group_id=?')->execute([$id]);
    db()->prepare('DELETE FROM crm_marketing_group_contacts WHERE group_id=?')->execute([$id]);
    crm_log_event('promotion', 'group_delete', 'marketing_group', (string)$id, $before, ['deleted' => 1]);
    crm_marketing_bootstrap_cache_clear();
    return ['groups' => crm_marketing_groups()];
}

function crm_marketing_group_customer_update(array $input): array
{
    crm_marketing_ensure_tables();
    crm_require('promotion.move_customer');
    $groupId = (int)($input['group_id'] ?? 0);
    $targetGroupId = (int)($input['target_group_id'] ?? 0);
    $mode = trim((string)($input['mode'] ?? 'add'));
    $ids = crm_mail_input_ids($input['customer_ids'] ?? []);
    $contactIds = crm_mail_input_ids($input['contact_ids'] ?? []);
    if ($groupId <= 0) throw new RuntimeException('请选择推广分组。');
    if (!$ids && !$contactIds) throw new RuntimeException('请先选择客户或联系人。');
    $stmt = db()->prepare('SELECT id, group_name FROM crm_marketing_groups WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([$groupId]);
    $group = $stmt->fetch();
    if (!$group) throw new RuntimeException('推广分组不存在。');
    if ($mode === 'move') {
        if ($targetGroupId <= 0 || $targetGroupId === $groupId) throw new RuntimeException('请选择要移动到的其他推广分组。');
        $targetStmt = db()->prepare('SELECT id, group_name FROM crm_marketing_groups WHERE id = ? AND deleted_at IS NULL LIMIT 1');
        $targetStmt->execute([$targetGroupId]);
        if (!$targetStmt->fetch()) throw new RuntimeException('目标推广分组不存在。');
    }
    if ($ids) {
        if ($mode === 'remove' || $mode === 'move') {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            db()->prepare("DELETE FROM crm_marketing_group_customers WHERE group_id=? AND customer_id IN ({$placeholders})")
                ->execute(array_merge([$groupId], $ids));
        }
        if ($mode === 'add' || $mode === 'move') {
            $insertGroupId = $mode === 'move' ? $targetGroupId : $groupId;
            $insert = db()->prepare('INSERT IGNORE INTO crm_marketing_group_customers (group_id, customer_id, created_by, created_at) VALUES (?, ?, ?, NOW())');
            foreach ($ids as $customerId) $insert->execute([$insertGroupId, $customerId, current_user()['id'] ?? null]);
        }
    }
    if ($contactIds) {
        if ($mode === 'remove' || $mode === 'move') {
            $placeholders = implode(',', array_fill(0, count($contactIds), '?'));
            db()->prepare("DELETE FROM crm_marketing_group_contacts WHERE group_id=? AND contact_id IN ({$placeholders})")
                ->execute(array_merge([$groupId], $contactIds));
        }
        if ($mode === 'add' || $mode === 'move') {
            $insertGroupId = $mode === 'move' ? $targetGroupId : $groupId;
            $insert = db()->prepare('INSERT IGNORE INTO crm_marketing_group_contacts (group_id, contact_id, created_by, created_at) VALUES (?, ?, ?, NOW())');
            foreach ($contactIds as $contactId) $insert->execute([$insertGroupId, $contactId, current_user()['id'] ?? null]);
        }
    }
    crm_log_event('promotion', 'group_' . $mode . '_members', 'marketing_group', (string)$groupId, null, [
        'group_id' => $groupId,
        'target_group_id' => $targetGroupId ?: null,
        'customer_ids' => $ids,
        'contact_ids' => $contactIds,
    ]);
    $poolResult = crm_marketing_pool($input);
    $poolPager = $poolResult;
    unset($poolPager['rows']);
    crm_marketing_bootstrap_cache_clear();
    return ['groups' => crm_marketing_groups(), 'pool' => $poolResult['rows'] ?? [], 'pool_pager' => $poolPager];
}

function crm_marketing_channels(): array
{
    crm_marketing_ensure_tables();
    $items = function_exists('crm_dictionary_items') ? crm_dictionary_items('promotion_channel', false) : [];
    $stats = [];
    foreach (db()->query("SELECT channel_key, COUNT(*) total, SUM(result_status='success') success_count, SUM(result_status='failed') failed_count FROM crm_marketing_logs GROUP BY channel_key") as $row) {
        $stats[(string)$row['channel_key']] = $row;
    }
    $channels = [];
    foreach ($items as $item) {
        if ((int)($item['is_enabled'] ?? 1) !== 1) continue;
        $extra = json_decode((string)($item['extra_config_json'] ?? '{}'), true) ?: [];
        $key = (string)$item['item_key'];
        $total = (int)($stats[$key]['total'] ?? 0);
        $success = (int)($stats[$key]['success_count'] ?? 0);
        $failed = (int)($stats[$key]['failed_count'] ?? 0);
        $channels[] = [
            'key' => $key,
            'name' => (string)$item['name_cn'],
            'short_name' => (string)($item['short_name'] ?: $item['name_cn']),
            'color' => (string)($item['color'] ?: '#2563eb'),
            'enabled' => 1,
            'auto' => (int)($extra['auto'] ?? 0),
            'bulk' => (int)($extra['bulk'] ?? 0),
            'contact_level' => (int)($extra['contact_level'] ?? 1),
            'total' => $total,
            'success_count' => $success,
            'failed_count' => $failed,
            'success_rate' => $total > 0 ? round($success / $total * 100, 1) : 0,
        ];
    }
    return $channels;
}

function crm_marketing_templates(): array
{
    $templates = [
        [
            'key' => 'mail_intro',
            'channel' => 'email',
            'name' => '邮件开发模板',
            'subject' => '{customer_name} 产品资料与合作沟通',
            'body' => '<p>{customer_name} 您好，</p><p>我们整理了适合贵司的产品资料和合作方案，想和您确认近期采购计划与目标型号。</p><p>如方便，请回复当前需求或指定负责同事。</p><p>{mail_user_name}<br>{mail_user_position}<br>{send_email} · {mail_user_mobile}</p>',
            'action' => '发送邮件、记录资料意向、未回复进入失败处理',
        ],
        [
            'key' => 'material_follow',
            'channel' => 'email',
            'name' => '资料跟进模板',
            'subject' => '{customer_name} 资料包跟进',
            'body' => '<p>您好，</p><p>资料包已按客户阶段准备，执行后会写入资料联动记录，并跟踪下载/回复状态。</p>',
            'action' => '生成资料记录、发送资料包、创建跟进',
        ],
        [
            'key' => 'quote_follow',
            'channel' => 'email',
            'name' => '报价跟进模板',
            'subject' => '{customer_name} 报价方案确认',
            'body' => '<p>您好，</p><p>根据前次沟通，我们将报价方案纳入本次推广任务，执行后会记录报价跟进和转化状态。</p>',
            'action' => '生成报价跟进、写入推广日志、失败进入报价复盘',
        ],
        [
            'key' => 'whatsapp_intro',
            'channel' => 'whatsapp',
            'name' => 'WhatsApp 首触达话术',
            'subject' => 'WhatsApp 首触达',
            'body' => '您好，我是 Artdon 的客户负责人。想和您确认近期采购需求，并发送适合贵司市场的产品资料。',
            'action' => '记录 WhatsApp 触达、未回复进入重试队列',
        ],
        [
            'key' => 'phone_follow',
            'channel' => 'phone',
            'name' => '电话跟进话术',
            'subject' => '电话跟进',
            'body' => '确认联系人身份、近期采购计划、目标产品、报价负责人，并约定下一次跟进时间。',
            'action' => '记录电话结果、生成跟进/派工',
        ],
        [
            'key' => 'offline_visit',
            'channel' => 'offline',
            'name' => '线下拜访计划',
            'subject' => '线下拜访计划',
            'body' => '拜访前确认客户等级、资料包、报价范围、负责人和拜访结果回填要求。',
            'action' => '生成线下执行清单、回填拜访结论',
        ],
    ];
    if (function_exists('db_table_exists') && db_table_exists('crm_marketing_templates')) {
        $stmt = db()->query("SELECT * FROM crm_marketing_templates WHERE deleted_at IS NULL ORDER BY id DESC");
        foreach ($stmt->fetchAll() as $row) {
            $templates[] = [
                'key' => (string)$row['template_key'],
                'channel' => (string)$row['channel_key'],
                'name' => (string)$row['template_name'],
                'subject' => (string)($row['mail_subject'] ?? ''),
                'body' => (string)($row['body_html'] ?? ''),
                'action' => (string)($row['action_note'] ?? ''),
                'custom' => 1,
            ];
        }
    }
    return $templates;
}

function crm_marketing_pool(array $input = []): array
{
    crm_marketing_ensure_tables();
    crm_require('promotion.view');
    $page = max(1, (int)($input['page'] ?? 1));
    $pageSize = (int)($input['page_size'] ?? 50);
    if ($pageSize < 20) $pageSize = 20;
    if ($pageSize > 200) $pageSize = 200;
    $offset = ($page - 1) * $pageSize;
    $skipCount = !empty($input['skip_count']);
    $params = [];
    $scope = crm_customer_scope_sql($params);
    $where = ['c.deleted_at IS NULL', $scope];
    $status = trim((string)($input['status'] ?? ''));
    if ($status !== '') {
        $where[] = 'COALESCE(ps.status, "not_promoted") = ?';
        $params[] = $status;
    }
    $country = trim((string)($input['country'] ?? ''));
    if ($country !== '') {
        [$countrySql, $countryParams] = crm_customer_country_search_sql(crm_customer_search_terms($country), 'c');
        [$regionSql, $regionParams] = crm_customer_region_search_sql($country, 'c');
        $where[] = $regionSql !== '' ? '(' . $regionSql . ' OR ' . $countrySql . ')' : $countrySql;
        foreach ($regionParams as $value) $params[] = $value;
        foreach ($countryParams as $value) $params[] = $value;
    }
    $level = trim((string)($input['level'] ?? ''));
    if ($level !== '') {
        $where[] = 'c.level = ?';
        $params[] = $level;
    }
    $ownerId = (int)($input['owner_id'] ?? 0);
    if ($ownerId > 0) {
        $where[] = '(c.owner_user_id = ? OR EXISTS (SELECT 1 FROM crm_customer_owners co WHERE co.customer_id = c.id AND co.user_id = ?))';
        $params[] = $ownerId;
        $params[] = $ownerId;
    }
    if ((string)($input['my_customers'] ?? '') === '1') {
        $userId = (int)(current_user()['id'] ?? 0);
        $where[] = '(c.owner_user_id = ? OR EXISTS (SELECT 1 FROM crm_customer_owners co WHERE co.customer_id = c.id AND co.user_id = ?))';
        $params[] = $userId;
        $params[] = $userId;
    }
    $hasEmail = trim((string)($input['has_email'] ?? ''));
    if ($hasEmail === '1') {
        $where[] = '(NULLIF(c.email, "") IS NOT NULL OR EXISTS (SELECT 1 FROM crm_contacts he WHERE he.customer_id = c.id AND he.deleted_at IS NULL AND NULLIF(he.email, "") IS NOT NULL))';
    } elseif ($hasEmail === '0') {
        $where[] = '(NULLIF(c.email, "") IS NULL AND NOT EXISTS (SELECT 1 FROM crm_contacts he WHERE he.customer_id = c.id AND he.deleted_at IS NULL AND NULLIF(he.email, "") IS NOT NULL))';
    }
    $hasContact = trim((string)($input['has_contact'] ?? ''));
    if ($hasContact === '1') {
        $where[] = 'EXISTS (SELECT 1 FROM crm_contacts hc WHERE hc.customer_id = c.id AND hc.deleted_at IS NULL)';
    } elseif ($hasContact === '0') {
        $where[] = 'NOT EXISTS (SELECT 1 FROM crm_contacts hc WHERE hc.customer_id = c.id AND hc.deleted_at IS NULL)';
    }
    if ((string)($input['ungrouped'] ?? '') === '1') {
        $where[] = 'NOT EXISTS (SELECT 1 FROM crm_marketing_group_customers ug WHERE ug.customer_id = c.id)';
    }
    $groupId = (int)($input['group_id'] ?? 0);
    if ($groupId > 0) {
        $where[] = '(EXISTS (SELECT 1 FROM crm_marketing_group_customers mgc WHERE mgc.customer_id = c.id AND mgc.group_id = ?) OR EXISTS (SELECT 1 FROM crm_marketing_group_contacts mgct JOIN crm_contacts mgctc ON mgctc.id = mgct.contact_id AND mgctc.deleted_at IS NULL WHERE mgctc.customer_id = c.id AND mgct.group_id = ?))';
        $params[] = $groupId;
        $params[] = $groupId;
    }
    $excludeGroupId = (int)($input['exclude_group_id'] ?? 0);
    if ($excludeGroupId > 0) {
        $where[] = 'NOT EXISTS (SELECT 1 FROM crm_marketing_group_customers ex_mgc WHERE ex_mgc.customer_id = c.id AND ex_mgc.group_id = ?)
            AND NOT EXISTS (SELECT 1 FROM crm_marketing_group_contacts ex_mgct JOIN crm_contacts ex_mgctc ON ex_mgctc.id = ex_mgct.contact_id AND ex_mgctc.deleted_at IS NULL WHERE ex_mgctc.customer_id = c.id AND ex_mgct.group_id = ?)';
        $params[] = $excludeGroupId;
        $params[] = $excludeGroupId;
    }
    $filterCustomerIds = crm_mail_input_ids($input['customer_ids'] ?? '');
    if ($filterCustomerIds) {
        $where[] = 'c.id IN (' . implode(',', array_fill(0, count($filterCustomerIds), '?')) . ')';
        foreach ($filterCustomerIds as $id) $params[] = $id;
    }
    $q = trim((string)($input['q'] ?? ''));
    if ($q !== '') {
        [$countrySearchSql, $countrySearchParams] = crm_customer_country_search_sql(crm_customer_search_terms($q), 'c');
        [$regionSearchSql, $regionSearchParams] = crm_customer_region_search_sql($q, 'c');
        $locationSql = $regionSearchSql !== '' ? '(' . $regionSearchSql . ' OR ' . $countrySearchSql . ')' : $countrySearchSql;
        $where[] = '(c.customer_code LIKE ? OR c.customer_name LIKE ? OR c.customer_name_en LIKE ? OR c.email LIKE ? OR c.phone LIKE ? OR c.whatsapp LIKE ? OR c.website LIKE ? OR ' . $locationSql . ' OR EXISTS (SELECT 1 FROM crm_users ou WHERE ou.id = c.owner_user_id AND (ou.username LIKE ? OR ou.real_name LIKE ?)) OR EXISTS (SELECT 1 FROM crm_contacts ct WHERE ct.customer_id = c.id AND ct.deleted_at IS NULL AND (ct.name LIKE ? OR ct.email LIKE ? OR ct.phone LIKE ? OR ct.whatsapp LIKE ?)))';
        for ($i = 0; $i < 7; $i++) $params[] = '%' . $q . '%';
        foreach ($regionSearchParams as $value) $params[] = $value;
        foreach ($countrySearchParams as $value) $params[] = $value;
        for ($i = 0; $i < 2; $i++) $params[] = '%' . $q . '%';
        for ($i = 0; $i < 4; $i++) $params[] = '%' . $q . '%';
    }
    $sqlWhere = implode(' AND ', $where);
    $total = 0;
    if (!$skipCount) {
        $countStmt = db()->prepare("SELECT COUNT(*)
            FROM crm_customers c
            LEFT JOIN crm_customer_promotion_status ps ON ps.customer_id = c.id
            WHERE {$sqlWhere}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
    }
    $queryLimit = $pageSize;
    $stmt = db()->prepare("SELECT c.id, c.customer_code, c.customer_name, c.country, c.level, c.lifecycle_key, c.do_not_contact,
        c.owner_user_id, c.email,
        (SELECT email FROM crm_contacts pct WHERE pct.customer_id = c.id AND pct.deleted_at IS NULL AND pct.email IS NOT NULL AND pct.email <> '' ORDER BY pct.is_primary DESC, pct.id DESC LIMIT 1) primary_contact_email,
        (SELECT name FROM crm_contacts pct WHERE pct.customer_id = c.id AND pct.deleted_at IS NULL AND pct.name IS NOT NULL AND pct.name <> '' ORDER BY pct.is_primary DESC, pct.id DESC LIMIT 1) primary_contact_name,
        COALESCE(owner.real_name, owner.username, '') owner_name,
        c.promotion_status,
        COALESCE((SELECT GROUP_CONCAT(channel_key ORDER BY id) FROM crm_customer_promotion_channels pc WHERE pc.customer_id = c.id), '') promotion_channels,
        COALESCE((SELECT GROUP_CONCAT(g.id ORDER BY g.sort_order, g.id) FROM crm_marketing_group_customers mgc JOIN crm_marketing_groups g ON g.id = mgc.group_id AND g.deleted_at IS NULL WHERE mgc.customer_id = c.id), '') marketing_group_ids,
        COALESCE((SELECT GROUP_CONCAT(g.group_name ORDER BY g.sort_order, g.id SEPARATOR ', ') FROM crm_marketing_group_customers mgc JOIN crm_marketing_groups g ON g.id = mgc.group_id AND g.deleted_at IS NULL WHERE mgc.customer_id = c.id), '') marketing_group_names,
        COALESCE((SELECT COUNT(*) FROM crm_contacts cc WHERE cc.customer_id = c.id AND cc.deleted_at IS NULL), 0) contact_count,
        COALESCE((SELECT COUNT(*) FROM crm_contacts pc WHERE pc.customer_id = c.id AND pc.deleted_at IS NULL AND COALESCE(pc.is_left,0) = 0 AND COALESCE(pc.do_not_contact,0) = 0 AND COALESCE(pc.unsubscribe_email,0) = 0 AND COALESCE(pc.email,'') <> ''), 0) promotable_contact_count,
        (CASE WHEN COALESCE(c.email,'') <> '' THEN 1 ELSE 0 END) + COALESCE((SELECT COUNT(*) FROM crm_contacts ec WHERE ec.customer_id = c.id AND ec.deleted_at IS NULL AND COALESCE(ec.email,'') <> ''), 0) email_count,
        (CASE WHEN COALESCE(c.whatsapp,'') <> '' THEN 1 ELSE 0 END) + COALESCE((SELECT COUNT(*) FROM crm_contacts wc WHERE wc.customer_id = c.id AND wc.deleted_at IS NULL AND COALESCE(wc.whatsapp,'') <> ''), 0) whatsapp_count,
        COALESCE((SELECT COUNT(*) FROM crm_contact_promotions cp JOIN crm_contacts ct ON ct.id = cp.contact_id WHERE ct.customer_id = c.id AND ct.deleted_at IS NULL AND cp.status = 'active'), 0) active_contact_channels,
        (SELECT MAX(touched_at) FROM crm_marketing_logs ml WHERE ml.customer_id = c.id) last_touch_time,
        (SELECT MAX(touched_at) FROM crm_marketing_logs ml WHERE ml.customer_id = c.id AND ml.result_status IN ('reply','replied','success_reply')) last_reply_time
        FROM (
            SELECT c.id, c.customer_code, c.customer_name, c.country, c.level, c.lifecycle_key, c.do_not_contact, c.owner_user_id, c.email, c.whatsapp, c.updated_at,
                COALESCE(ps.status, 'not_promoted') promotion_status
            FROM crm_customers c
            LEFT JOIN crm_customer_promotion_status ps ON ps.customer_id = c.id
            WHERE {$sqlWhere}
            ORDER BY FIELD(COALESCE(ps.status, 'not_promoted'), 'promoting','not_promoted','paused','stopped','maintenance_only','blacklist'), c.updated_at DESC, c.id DESC
            LIMIT {$queryLimit} OFFSET {$offset}
        ) c
        LEFT JOIN crm_users owner ON owner.id = c.owner_user_id
        ORDER BY FIELD(c.promotion_status, 'promoting','not_promoted','paused','stopped','maintenance_only','blacklist'), c.updated_at DESC, c.id DESC");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $hasMore = false;
    if ($skipCount) {
        $nextOffset = $offset + $pageSize;
        $moreStmt = db()->prepare("SELECT 1
            FROM crm_customers c
            LEFT JOIN crm_customer_promotion_status ps ON ps.customer_id = c.id
            WHERE {$sqlWhere}
            ORDER BY FIELD(COALESCE(ps.status, 'not_promoted'), 'promoting','not_promoted','paused','stopped','maintenance_only','blacklist'), c.updated_at DESC, c.id DESC
            LIMIT 1 OFFSET {$nextOffset}");
        $moreStmt->execute($params);
        $hasMore = $moreStmt->fetchColumn() !== false;
        $total = $offset + count($rows) + ($hasMore ? 1 : 0);
    }
    return [
        'rows' => $rows,
        'total' => $total,
        'total_is_exact' => $skipCount ? 0 : 1,
        'shown_count' => count($rows),
        'page' => $page,
        'page_size' => $pageSize,
        'page_count' => $skipCount ? ($hasMore ? $page + 1 : $page) : max(1, (int)ceil($total / max(1, $pageSize))),
        'has_more' => $hasMore ? 1 : 0,
    ];
}

function crm_marketing_contacts(array $input = []): array
{
    crm_marketing_ensure_tables();
    crm_require('promotion.view');
    $params = [];
    $scope = crm_customer_scope_sql($params);
    $where = ['c.deleted_at IS NULL', 'ct.deleted_at IS NULL', $scope];
    $customerId = (int)($input['customer_id'] ?? 0);
    $customerIds = crm_mail_input_ids($input['customer_ids'] ?? '');
    $contactIds = crm_mail_input_ids($input['contact_ids'] ?? '');
    $groupId = (int)($input['group_id'] ?? 0);
    $channel = trim((string)($input['channel'] ?? ''));
    $search = trim((string)($input['search'] ?? ''));
    $country = trim((string)($input['country'] ?? ''));
    $role = trim((string)($input['role'] ?? ''));
    $strategyStatus = trim((string)($input['strategy_status'] ?? ''));
    $emailStatus = trim((string)($input['email_status'] ?? ''));
    $whatsappStatus = trim((string)($input['whatsapp_status'] ?? ''));
    $primary = trim((string)($input['is_primary'] ?? ''));
    $quick = trim((string)($input['quick'] ?? ''));
    if ($customerId > 0) {
        $where[] = 'c.id = ?';
        $params[] = $customerId;
    }
    if ($customerIds) {
        $where[] = 'c.id IN (' . implode(',', array_fill(0, count($customerIds), '?')) . ')';
        foreach ($customerIds as $id) $params[] = $id;
    }
    if ($contactIds) {
        $where[] = 'ct.id IN (' . implode(',', array_fill(0, count($contactIds), '?')) . ')';
        foreach ($contactIds as $id) $params[] = $id;
    }
    if ($groupId > 0) {
        $where[] = '(EXISTS (SELECT 1 FROM crm_marketing_group_contacts mgct WHERE mgct.contact_id = ct.id AND mgct.group_id = ?) OR EXISTS (SELECT 1 FROM crm_marketing_group_customers mgc WHERE mgc.customer_id = c.id AND mgc.group_id = ?))';
        $params[] = $groupId;
        $params[] = $groupId;
    } elseif ($groupId === 0 && array_key_exists('group_id', $input) && (string)($input['group_id'] ?? '') === '0') {
        $where[] = 'NOT EXISTS (SELECT 1 FROM crm_marketing_group_contacts mgct WHERE mgct.contact_id = ct.id) AND NOT EXISTS (SELECT 1 FROM crm_marketing_group_customers mgc WHERE mgc.customer_id = c.id)';
    }
    if ($channel !== '') {
        $where[] = 'EXISTS (SELECT 1 FROM crm_contact_promotions cp2 WHERE cp2.contact_id = ct.id AND cp2.channel = ?)';
        $params[] = $channel;
    }
    if ($search !== '') {
        $like = '%' . $search . '%';
        $where[] = '(c.customer_name LIKE ? OR c.customer_code LIKE ? OR c.country LIKE ? OR ct.name LIKE ? OR ct.name_en LIKE ? OR ct.position LIKE ? OR ct.email LIKE ? OR ct.phone LIKE ? OR ct.whatsapp LIKE ? OR EXISTS (SELECT 1 FROM crm_users ou WHERE ou.id = c.owner_user_id AND (ou.username LIKE ? OR ou.real_name LIKE ?)))';
        array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like);
    }
    if ($country !== '') {
        $where[] = 'c.country LIKE ?';
        $params[] = '%' . $country . '%';
    }
    if ($role !== '') {
        if ($role === 'uncategorized') {
            $where[] = 'NOT EXISTS (SELECT 1 FROM crm_contact_role_tags rt WHERE rt.contact_id = ct.id)';
        } else {
            $where[] = 'EXISTS (SELECT 1 FROM crm_contact_role_tags rt WHERE rt.contact_id = ct.id AND rt.role_key = ?)';
            $params[] = $role;
        }
    }
    if ($primary !== '') {
        $where[] = 'ct.is_primary = ?';
        $params[] = (int)$primary;
    }
    $effectiveQuick = $quick !== '' && $quick !== 'all' ? $quick : $strategyStatus;
    if ($emailStatus === 'has' || $quick === 'has_email') $where[] = "COALESCE(ct.email,'') <> ''";
    if ($emailStatus === 'none' || $quick === 'no_email') $where[] = "COALESCE(ct.email,'') = ''";
    if ($whatsappStatus === 'has') $where[] = "COALESCE(ct.whatsapp,'') <> ''";
    if ($whatsappStatus === 'none') $where[] = "COALESCE(ct.whatsapp,'') = ''";
    if ($quick === 'primary') $where[] = 'ct.is_primary = 1';
    if ($quick === 'uncategorized') $where[] = 'NOT EXISTS (SELECT 1 FROM crm_contact_role_tags rt WHERE rt.contact_id = ct.id)';
    if ($quick === 'recent_reply') $where[] = 'EXISTS (SELECT 1 FROM crm_mails mm WHERE mm.linked_customer_id = c.id AND mm.folder = "inbox" AND COALESCE(mm.received_at, mm.created_at) >= DATE_SUB(NOW(), INTERVAL 30 DAY))';
    if ($quick === 'duplicate_email') $where[] = "COALESCE(ct.email,'') <> '' AND EXISTS (SELECT 1 FROM crm_contacts dup WHERE dup.deleted_at IS NULL AND dup.email = ct.email AND dup.id <> ct.id)";
    if ($effectiveQuick === 'blacklist') $where[] = '(COALESCE(c.do_not_contact,0)=1 OR COALESCE(ct.do_not_contact,0)=1 OR COALESCE(ps.status,"")="blacklist")';
    if ($effectiveQuick === 'no_promotion') $where[] = '(COALESCE(ct.do_not_contact,0)=1 OR COALESCE(ct.unsubscribe_email,0)=1 OR EXISTS (SELECT 1 FROM crm_contact_promotions cp3 WHERE cp3.contact_id = ct.id AND cp3.channel IN ("no_promotion","maintenance_only") AND cp3.status <> "no_contact"))';
    if ($effectiveQuick === 'left') $where[] = 'COALESCE(ct.is_left,0)=1';
    if ($effectiveQuick === 'invalid_email' || $emailStatus === 'invalid') $where[] = '(COALESCE(ct.unsubscribe_email,0)=1 OR EXISTS (SELECT 1 FROM crm_contact_promotions cp4 WHERE cp4.contact_id = ct.id AND cp4.channel = "email" AND cp4.status IN ("failed","stopped","no_contact")))';
    if ($effectiveQuick === 'manual') $where[] = "COALESCE(ct.email,'') = '' AND (COALESCE(ct.whatsapp,'') <> '' OR COALESCE(ct.phone,'') <> '')";
    if ($effectiveQuick === 'promotable') $where[] = 'COALESCE(c.do_not_contact,0)=0 AND COALESCE(ct.do_not_contact,0)=0 AND COALESCE(ct.is_left,0)=0 AND COALESCE(ct.unsubscribe_email,0)=0 AND COALESCE(ps.status,"not_promoted") <> "blacklist" AND COALESCE(ct.email,"") <> ""';
    if ($whatsappStatus === 'invalid') $where[] = 'COALESCE(ct.no_whatsapp,0)=1';
    $stmt = db()->prepare("SELECT ct.id, ct.customer_id, c.customer_name, c.customer_code, c.country, c.owner_user_id, COALESCE(u.real_name, u.username) owner_name, c.do_not_contact, ct.name, ct.name_en, ct.position, ct.email, ct.phone, ct.whatsapp, ct.linkedin, ct.is_primary, ct.is_left, ct.do_not_contact contact_do_not_contact, ct.unsubscribe_email, ct.no_whatsapp,
        COALESCE(ps.status, 'not_promoted') customer_promotion_status,
        (SELECT GROUP_CONCAT(rt.role_key ORDER BY rt.role_key) FROM crm_contact_role_tags rt WHERE rt.contact_id = ct.id) role_tags,
        (SELECT GROUP_CONCAT(CONCAT(cp.channel, ':', cp.status) ORDER BY cp.channel) FROM crm_contact_promotions cp WHERE cp.contact_id = ct.id) promotion_rules,
        (SELECT GROUP_CONCAT(g.id ORDER BY g.sort_order, g.id) FROM crm_marketing_group_contacts mgct JOIN crm_marketing_groups g ON g.id = mgct.group_id AND g.deleted_at IS NULL WHERE mgct.contact_id = ct.id) marketing_group_ids,
        (SELECT GROUP_CONCAT(DISTINCT g.group_name ORDER BY g.sort_order, g.id SEPARATOR ', ') FROM crm_marketing_groups g WHERE g.deleted_at IS NULL AND (EXISTS (SELECT 1 FROM crm_marketing_group_contacts mgct WHERE mgct.group_id = g.id AND mgct.contact_id = ct.id) OR EXISTS (SELECT 1 FROM crm_marketing_group_customers mgc WHERE mgc.group_id = g.id AND mgc.customer_id = c.id))) marketing_group_names,
        (SELECT MAX(touched_at) FROM crm_marketing_logs ml WHERE ml.contact_id = ct.id) last_touch_time,
        (SELECT MAX(COALESCE(sent_at, created_at)) FROM crm_mails mm WHERE mm.linked_customer_id = c.id AND mm.folder = 'sent' AND (mm.linked_contact_id = ct.id OR (COALESCE(ct.email,'') <> '' AND LOCATE(LOWER(ct.email), LOWER(COALESCE(mm.to_emails,''))) > 0))) last_mail_sent_time,
        (SELECT MAX(COALESCE(received_at, created_at)) FROM crm_mails mm WHERE mm.linked_customer_id = c.id AND mm.folder = 'inbox' AND (mm.linked_contact_id = ct.id OR (COALESCE(ct.email,'') <> '' AND LOWER(mm.from_email) = LOWER(ct.email)))) last_reply_time
        FROM crm_contacts ct
        JOIN crm_customers c ON c.id = ct.customer_id
        LEFT JOIN crm_users u ON u.id = c.owner_user_id
        LEFT JOIN crm_customer_promotion_status ps ON ps.customer_id = c.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY ct.is_primary DESC, c.customer_name ASC, ct.id DESC
        LIMIT 500");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        crm_marketing_apply_contact_strategy_meta($row);
    }
    unset($row);
    return $rows;
}

function crm_marketing_apply_contact_strategy_meta(array &$row): void
{
    $rules = [];
    foreach (explode(',', (string)($row['promotion_rules'] ?? '')) as $rule) {
        if (strpos($rule, ':') === false) continue;
        [$channel, $status] = array_pad(explode(':', $rule, 2), 2, '');
        $rules[$channel] = $status;
    }
    $reasons = [];
    if ((int)($row['do_not_contact'] ?? 0) === 1 || (string)($row['customer_promotion_status'] ?? '') === 'blacklist') $reasons[] = '黑名单';
    if ((int)($row['contact_do_not_contact'] ?? 0) === 1) $reasons[] = '不推广';
    if ((int)($row['is_left'] ?? 0) === 1) $reasons[] = '已离职';
    if ((int)($row['unsubscribe_email'] ?? 0) === 1 || (($rules['email'] ?? '') && in_array($rules['email'], ['failed','stopped','no_contact'], true))) $reasons[] = '邮箱无效';
    if ((string)($row['email'] ?? '') === '') $reasons[] = '无邮箱';
    if ((int)($row['no_whatsapp'] ?? 0) === 1) $reasons[] = 'WhatsApp 无效';
    if ((string)($row['email'] ?? '') === '' && (string)($row['whatsapp'] ?? '') === '' && (string)($row['phone'] ?? '') === '') $reasons[] = '无联系方式';
    if ((string)($row['role_tags'] ?? '') === '') $reasons[] = '未设置联系人角色';
    if (!empty($row['last_touch_time']) && strtotime((string)$row['last_touch_time']) >= strtotime('-14 days')) $reasons[] = '最近已推广';
    $manual = (string)($row['email'] ?? '') === '' && ((string)($row['whatsapp'] ?? '') !== '' || (string)($row['phone'] ?? '') !== '') && !in_array('黑名单', $reasons, true) && !in_array('不推广', $reasons, true) && !in_array('已离职', $reasons, true);
    $canPromote = !array_intersect($reasons, ['黑名单','不推广','已离职','邮箱无效','无邮箱','无联系方式']);
    $row['skip_reasons'] = implode('、', array_values(array_unique($reasons)));
    $row['can_promote'] = $canPromote ? 1 : 0;
    $row['can_manual'] = $manual ? 1 : 0;
    $row['strategy_status'] = $canPromote ? '可推广' : ($manual ? '可转人工执行' : ($row['skip_reasons'] ?: '待确认'));
}

function crm_marketing_normalize_email(string $email): string
{
    return strtolower(trim($email));
}

function crm_marketing_duplicate_email_report(array &$rows): array
{
    $groups = [];
    foreach ($rows as $index => $row) {
        $email = crm_marketing_normalize_email((string)($row['email'] ?? ''));
        if ($email === '') continue;
        if (!isset($groups[$email])) $groups[$email] = [];
        $groups[$email][] = $index;
    }
    $details = [];
    $skipped = 0;
    foreach ($groups as $email => $indexes) {
        if (count($indexes) < 2) continue;
        $keptIndex = $indexes[0];
        $skipIndexes = array_slice($indexes, 1);
        $skipped += count($skipIndexes);
        foreach ($indexes as $pos => $rowIndex) {
            $rows[$rowIndex]['duplicate_email_count'] = count($indexes);
            $rows[$rowIndex]['duplicate_email_role'] = $pos === 0 ? 'keep' : 'skip';
            if ($pos > 0) {
                $reasons = array_filter(explode('、', (string)($rows[$rowIndex]['skip_reasons'] ?? '')));
                $reasons[] = '重复邮箱';
                $rows[$rowIndex]['skip_reasons'] = implode('、', array_values(array_unique($reasons)));
                $rows[$rowIndex]['can_promote'] = 0;
                if (empty($rows[$rowIndex]['can_manual'])) $rows[$rowIndex]['strategy_status'] = '重复邮箱';
            }
        }
        $details[] = [
            'email' => $email,
            'count' => count($indexes),
            'kept' => $rows[$keptIndex] ?? [],
            'skipped' => array_map(static fn($i) => $rows[$i] ?? [], $skipIndexes),
        ];
    }
    return [
        'duplicate_email_count' => count($details),
        'duplicate_email_skipped_count' => $skipped,
        'duplicate_email_details' => array_slice($details, 0, 50),
    ];
}

function crm_marketing_contact_strategy_view(array $input = []): array
{
    $rows = crm_marketing_contacts($input);
    $duplicates = crm_marketing_duplicate_email_report($rows);
    $stats = ['total' => count($rows), 'promotable' => 0, 'no_email' => 0, 'no_promotion' => 0, 'blacklist' => 0, 'manual' => 0];
    $skip = [];
    $customerIds = [];
    foreach ($rows as $row) {
        $customerIds[(int)$row['customer_id']] = true;
        if ((int)($row['can_promote'] ?? 0) === 1) $stats['promotable']++;
        if ((string)($row['email'] ?? '') === '') $stats['no_email']++;
        if (strpos((string)($row['skip_reasons'] ?? ''), '不推广') !== false) $stats['no_promotion']++;
        if (strpos((string)($row['skip_reasons'] ?? ''), '黑名单') !== false) $stats['blacklist']++;
        if ((int)($row['can_manual'] ?? 0) === 1) $stats['manual']++;
        foreach (array_filter(explode('、', (string)($row['skip_reasons'] ?? ''))) as $reason) {
            $skip[$reason] = ($skip[$reason] ?? 0) + 1;
        }
    }
    $preview = [
        'customer_count' => count($customerIds),
        'raw_contact_count' => count($rows),
        'promotable_contact_count' => $stats['promotable'],
        'mail_contact_count' => $stats['promotable'],
        'manual_contact_count' => $stats['manual'],
        'skipped_contact_count' => max(0, count($rows) - $stats['promotable'] - $stats['manual']),
        'duplicate_email_count' => $duplicates['duplicate_email_count'],
        'duplicate_email_skipped_count' => $duplicates['duplicate_email_skipped_count'],
    ];
    return ['contacts' => $rows, 'contact_stats' => $stats, 'contact_preview' => $preview, 'contact_skip_reasons' => $skip, 'duplicate_email_report' => $duplicates];
}

function crm_marketing_tasks(): array
{
    crm_marketing_ensure_tables();
    crm_require('promotion.view');
    $stmt = db()->query("SELECT
            t.id, t.task_name, t.channel_key, t.campaign_type, t.mail_subject, t.signature_key,
            t.attachment_config_json, t.audience_config_json, t.send_rule_json, t.schedule_config_json,
            t.failure_policy_json, t.risk_summary_json, t.task_status, t.schedule_type, t.scheduled_at,
            t.customer_count, t.contact_count, t.success_count, t.failed_count, t.created_by, t.assigned_to,
            COALESCE(q.queue_count, 0) AS queue_count,
            COALESCE(ts.target_count, 0) AS target_count,
            COALESCE(ts.manual_pending_count, 0) AS manual_pending_count,
            COALESCE(ts.email_no_receiver_count, 0) AS email_no_receiver_count,
            COALESCE(ts.duplicate_email_count, 0) AS duplicate_email_count,
            t.remark, t.created_at, t.updated_at,
            CASE WHEN COALESCE(t.mail_body_html, '') <> '' THEN 1 ELSE 0 END AS has_mail_body,
            OCTET_LENGTH(COALESCE(t.mail_body_html, '')) AS mail_body_bytes,
            u.username AS created_by_name
        FROM crm_marketing_tasks t
        LEFT JOIN crm_users u ON u.id = t.created_by
        LEFT JOIN (
            SELECT task_id, COUNT(*) AS queue_count
            FROM crm_marketing_send_queue
            GROUP BY task_id
        ) q ON q.task_id = t.id
        LEFT JOIN (
            SELECT mt.task_id,
                COUNT(*) AS target_count,
                SUM(CASE
                    WHEN LOWER(COALESCE(mt.channel_key, '')) IN ('wechat','weixin','wechat_group','whatsapp','whatsapp_group','phone','offline','visit','linkedin')
                         AND mt.target_status IN ('pending','failed') THEN 1
                    WHEN LOWER(COALESCE(mt.channel_key, '')) IN ('email','mail','edm')
                         AND mt.target_status IN ('pending','failed','skipped')
                         AND gx.customer_id IS NULL THEN 1
                    ELSE 0
                END) AS manual_pending_count,
                SUM(CASE WHEN LOWER(COALESCE(mt.channel_key, '')) IN ('email','mail','edm') AND COALESCE(mt.failure_reason, '') = '收件邮箱为空' THEN 1 ELSE 0 END) AS email_no_receiver_count,
                SUM(CASE WHEN LOWER(COALESCE(mt.channel_key, '')) IN ('email','mail','edm') AND COALESCE(mt.failure_reason, '') = '重复邮箱' THEN 1 ELSE 0 END) AS duplicate_email_count
            FROM crm_marketing_task_targets mt
            LEFT JOIN (
                SELECT task_id, customer_id
                FROM crm_marketing_task_targets
                WHERE chat_group_id IS NOT NULL OR LOWER(COALESCE(channel_key, '')) IN ('wechat_group','whatsapp_group')
                GROUP BY task_id, customer_id
            ) gx ON gx.task_id = mt.task_id AND gx.customer_id = mt.customer_id
            GROUP BY mt.task_id
        ) ts ON ts.task_id = t.id
        ORDER BY t.id DESC
        LIMIT 80");
    return $stmt->fetchAll();
}

function crm_marketing_task_detail(array $input = []): array
{
    crm_marketing_ensure_tables();
    crm_require('promotion.view');
    $taskId = (int)($input['task_id'] ?? 0);
    if ($taskId <= 0) throw new RuntimeException('请选择推广任务。');
    $stmt = db()->prepare("SELECT t.*, u.username AS created_by_name
        FROM crm_marketing_tasks t
        LEFT JOIN crm_users u ON u.id = t.created_by
        WHERE t.id = ?
        LIMIT 1");
    $stmt->execute([$taskId]);
    $task = $stmt->fetch();
    if (!$task) throw new RuntimeException('推广任务不存在。');
    $task['_detail_loaded'] = 1;
    $task['has_mail_body'] = trim((string)($task['mail_body_html'] ?? '')) !== '' ? 1 : 0;
    $task['mail_body_bytes'] = strlen((string)($task['mail_body_html'] ?? ''));
    return ['task' => $task];
}

function crm_marketing_pool_view(array $input = []): array
{
    crm_marketing_ensure_tables();
    crm_require('promotion.view');
    $groupId = (int)($input['group_id'] ?? 0);
    $includeAll = (int)($input['include_all'] ?? 0) === 1;
    $onlyAll = (int)($input['only_all'] ?? 0) === 1;
    $groupPool = [];
    $groupPager = ['total' => 0, 'page' => 1, 'page_size' => 50, 'page_count' => 1];
    if ($groupId > 0 && !$onlyAll) {
        $groupInput = $input;
        $groupInput['group_id'] = $groupId;
        $groupInput['skip_count'] = empty($input['exact_count']) ? 1 : 0;
        $result = crm_marketing_pool($groupInput);
        $groupPool = $result['rows'] ?? [];
        $groupPager = $result;
        unset($groupPager['rows']);
    }
    $allPool = [];
    $allPager = ['total' => 0, 'page' => 1, 'page_size' => 50, 'page_count' => 1];
    if ($groupId <= 0 || $includeAll) {
        $allInput = $input;
        $allInput['group_id'] = 0;
        $allInput['page'] = (int)($input['all_page'] ?? $input['page'] ?? 1);
        $allInput['page_size'] = (int)($input['all_page_size'] ?? $input['page_size'] ?? 50);
        $allInput['skip_count'] = empty($input['exact_count']) ? 1 : 0;
        $result = crm_marketing_pool($allInput);
        $allPool = $result['rows'] ?? [];
        $allPager = $result;
        unset($allPager['rows']);
    }
    return [
        'group_id' => $groupId,
        'group_pool' => $groupPool,
        'group_pool_pager' => $groupPager,
        'all_pool' => $allPool,
        'all_pool_pager' => $allPager,
        'pool' => $groupId > 0 ? $groupPool : $allPool,
        'pool_pager' => $groupId > 0 ? $groupPager : $allPager,
    ];
}

function crm_marketing_audience_filter_input(array $input): array
{
    $nested = crm_marketing_decode_json_input($input['pool_filters'] ?? []);
    $source = array_merge($nested, $input);
    $allowed = ['q', 'status', 'country', 'level', 'owner_id', 'my_customers', 'has_email', 'has_contact', 'group_id', 'ungrouped'];
    $filters = [];
    foreach ($allowed as $key) {
        if (!array_key_exists($key, $source)) continue;
        $filters[$key] = $source[$key];
    }
    return $filters;
}

function crm_marketing_resolve_audience_customers(array $input, array $explicitCustomerIds = []): array
{
    $mode = strtolower(trim((string)($input['group_mode'] ?? 'selected')));
    if (!in_array($mode, ['selected', 'all_pool', 'group', 'country'], true)) $mode = 'selected';
    $poolInput = [];
    if ($mode === 'group') {
        $groupIds = crm_mail_input_ids($input['group_keys'] ?? []);
        $groupKey = trim((string)($input['group_key'] ?? ''));
        if (!$groupIds && $groupKey !== '') $groupIds = crm_mail_input_ids($groupKey);
        $groupId = $groupIds[0] ?? (ctype_digit($groupKey) ? (int)$groupKey : 0);
        if (!$groupIds && $groupId <= 0 && $groupKey !== '') {
            $stmt = db()->prepare('SELECT id FROM crm_marketing_groups WHERE group_name = ? AND deleted_at IS NULL LIMIT 1');
            $stmt->execute([$groupKey]);
            $groupId = (int)$stmt->fetchColumn();
            if ($groupId > 0) $groupIds = [$groupId];
        }
        $groupIds = array_values(array_unique(array_filter(array_map('intval', $groupIds))));
        if (!$groupIds) return ['rows' => [], 'customer_ids' => [], 'total' => 0, 'mode' => $mode];
        $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
        $exists = db()->prepare("SELECT id FROM crm_marketing_groups WHERE id IN ({$placeholders}) AND deleted_at IS NULL");
        $exists->execute($groupIds);
        $foundGroupIds = array_values(array_unique(array_map('intval', array_column($exists->fetchAll(), 'id'))));
        if (count($foundGroupIds) !== count($groupIds)) throw new RuntimeException('所选推广分组不存在或已删除。');
        if (count($groupIds) === 1) {
            $poolInput['group_id'] = $groupIds[0];
        } else {
            $memberStmt = db()->prepare("
                SELECT DISTINCT customer_id FROM (
                    SELECT rg.customer_id
                    FROM crm_marketing_group_customers rg
                    JOIN crm_customers c ON c.id = rg.customer_id AND c.deleted_at IS NULL
                    WHERE rg.group_id IN ({$placeholders})
                    UNION
                    SELECT ct.customer_id
                    FROM crm_marketing_group_contacts rg
                    JOIN crm_contacts ct ON ct.id = rg.contact_id AND ct.deleted_at IS NULL
                    JOIN crm_customers c ON c.id = ct.customer_id AND c.deleted_at IS NULL
                    WHERE rg.group_id IN ({$placeholders})
                ) grouped_customers
            ");
            $memberStmt->execute(array_merge($groupIds, $groupIds));
            $customerIds = array_values(array_unique(array_filter(array_map('intval', array_column($memberStmt->fetchAll(), 'customer_id')))));
            if (!$customerIds) return ['rows' => [], 'customer_ids' => [], 'total' => 0, 'mode' => $mode];
            $poolInput['customer_ids'] = json_encode($customerIds);
        }
    } elseif ($mode === 'country') {
        $country = trim((string)($input['group_key'] ?? ''));
        if ($country === '') return ['rows' => [], 'customer_ids' => [], 'total' => 0, 'mode' => $mode];
        $poolInput['country'] = $country;
    } elseif ($mode === 'all_pool') {
        $poolInput = crm_marketing_audience_filter_input($input);
    } else {
        $explicitCustomerIds = array_values(array_unique(array_filter(array_map('intval', $explicitCustomerIds))));
        if (!$explicitCustomerIds) return ['rows' => [], 'customer_ids' => [], 'total' => 0, 'mode' => $mode];
        $poolInput['customer_ids'] = json_encode($explicitCustomerIds);
    }

    $pageSize = 200;
    $first = crm_marketing_pool(array_merge($poolInput, [
        'page' => 1,
        'page_size' => $pageSize,
    ]));
    $total = (int)($first['total'] ?? 0);
    $maxAudience = 5000;
    if ($total > $maxAudience) {
        throw new RuntimeException("当前客户范围共 {$total} 个，超过单个推广任务 {$maxAudience} 个客户的安全上限，请继续缩小筛选范围。");
    }
    $rows = $first['rows'] ?? [];
    $pageCount = max(1, (int)($first['page_count'] ?? 1));
    for ($page = 2; $page <= $pageCount; $page++) {
        $next = crm_marketing_pool(array_merge($poolInput, [
            'page' => $page,
            'page_size' => $pageSize,
            'skip_count' => 1,
        ]));
        foreach (($next['rows'] ?? []) as $row) $rows[] = $row;
    }
    $byId = [];
    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id > 0) $byId[$id] = $row;
    }
    return [
        'rows' => array_values($byId),
        'customer_ids' => array_map('intval', array_keys($byId)),
        'total' => count($byId),
        'mode' => $mode,
    ];
}

function crm_marketing_apply_audience_policy(array $customers, string $blacklistPolicy): array
{
    $allowed = [];
    $blocked = [];
    foreach ($customers as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) continue;
        $status = strtolower(trim((string)($row['promotion_status'] ?? '')));
        $isBlocked = (int)($row['do_not_contact'] ?? 0) === 1
            || in_array($status, ['blacklist', 'maintenance_only', 'stopped', 'no_promotion'], true);
        if ($isBlocked) {
            $blocked[] = $id;
            continue;
        }
        $allowed[] = $id;
    }
    if ($blocked && $blacklistPolicy === 'block_task') {
        throw new RuntimeException('当前客户范围包含 ' . count($blocked) . ' 个黑名单或禁止推广客户，已按失败处理策略阻止保存。');
    }
    return [
        'customer_ids' => array_values(array_unique($allowed)),
        'blocked_customer_ids' => array_values(array_unique($blocked)),
    ];
}

function crm_marketing_target_preview(array $input = []): array
{
    crm_marketing_ensure_tables();
    crm_require('promotion.view');
    $requestedCustomerIds = crm_mail_input_ids($input['customer_ids'] ?? '');
    $contactIds = crm_mail_input_ids($input['contact_ids'] ?? '');
    $audience = crm_marketing_resolve_audience_customers($input, $requestedCustomerIds);
    $customers = $audience['rows'] ?? [];
    $customerIds = $audience['customer_ids'] ?? [];
    $contacts = [];
    $chatGroups = [];
    if ($customerIds) {
        $contacts = crm_marketing_contacts([
            'customer_ids' => json_encode($customerIds),
        ]);
        $placeholders = implode(',', array_fill(0, count($customerIds), '?'));
        $stmt = db()->prepare("SELECT g.id, g.customer_id, g.group_name, g.group_platform, c.customer_name, c.country, c.owner_user_id, COALESCE(u.real_name, u.username, '') owner_name
            FROM crm_customer_chat_groups g
            JOIN crm_customers c ON c.id = g.customer_id AND c.deleted_at IS NULL
            LEFT JOIN crm_users u ON u.id = c.owner_user_id
            WHERE g.deleted_at IS NULL AND g.status = 'active' AND g.use_for_promotion = 1 AND g.customer_id IN ({$placeholders})
            ORDER BY c.customer_name ASC, g.id DESC");
        $stmt->execute($customerIds);
        $chatGroups = $stmt->fetchAll();
    }
    if ($contactIds) {
        $extraContacts = crm_marketing_contacts([
            'contact_ids' => json_encode($contactIds),
        ]);
        $seen = [];
        foreach ($contacts as $row) $seen[(int)$row['id']] = true;
        foreach ($extraContacts as $row) {
            if (!isset($seen[(int)$row['id']])) $contacts[] = $row;
        }
        $extraCustomerIds = [];
        foreach ($extraContacts as $row) $extraCustomerIds[] = (int)$row['customer_id'];
        $extraCustomerIds = array_values(array_unique(array_filter($extraCustomerIds)));
        if ($extraCustomerIds) {
            $pool = crm_marketing_pool([
                'customer_ids' => json_encode($extraCustomerIds),
                'page' => 1,
                'page_size' => min(200, max(20, count($extraCustomerIds))),
                'skip_count' => 1,
            ]);
            $seenCustomers = [];
            foreach ($customers as $row) $seenCustomers[(int)$row['id']] = true;
            foreach (($pool['rows'] ?? []) as $row) {
                if (!isset($seenCustomers[(int)$row['id']])) $customers[] = $row;
            }
        }
    }
    $duplicates = crm_marketing_duplicate_email_report($contacts);
    return [
        'pool' => $customers,
        'contacts' => $contacts,
        'chat_groups' => $chatGroups,
        'selected_customer_count' => count($customerIds),
        'selected_contact_count' => count($contactIds),
        'audience_customer_ids' => array_values($customerIds),
        'audience_customer_count' => count($customerIds),
        'contact_preview_count' => count($contacts),
        'contact_preview_limited' => count($contacts) >= 500 ? 1 : 0,
        'duplicate_email_report' => $duplicates,
    ];
}

function crm_marketing_users(): array
{
    crm_marketing_ensure_tables();
    $stmt = db()->query("SELECT u.id, u.username, COALESCE(u.real_name, u.username) display_name, u.email, d.name department_name
        FROM crm_users u
        LEFT JOIN crm_departments d ON d.id = u.department_id
        WHERE u.status = 'active'
        ORDER BY d.sort_order, u.username");
    return $stmt->fetchAll();
}

function crm_marketing_mail_accounts(): array
{
    crm_marketing_ensure_tables();
    if (!function_exists('db_table_exists') || !db_table_exists('crm_user_mail_accounts')) return [];
    $stmt = db()->query("SELECT a.id, a.user_id, a.email_address, a.sender_name, a.is_default,
            CASE WHEN COALESCE(a.signature_html, '') <> '' THEN 1 ELSE 0 END AS has_signature,
            OCTET_LENGTH(COALESCE(a.signature_html, '')) AS signature_bytes,
            COALESCE(NULLIF(a.sender_name, ''), u.real_name, u.username) owner_name, u.username, u.email user_email, u.phone user_phone, u.position user_position, d.name department_name,
            COALESCE(sent.today_sent, 0) today_sent, 200 daily_limit, 50 hourly_limit
        FROM crm_user_mail_accounts a
        LEFT JOIN crm_users u ON u.id = a.user_id
        LEFT JOIN crm_roles r ON r.id = u.role_id
        LEFT JOIN crm_departments d ON d.id = u.department_id
        LEFT JOIN (
            SELECT mail_account_id, COUNT(*) today_sent
            FROM crm_mails
            WHERE folder = 'sent' AND DATE(COALESCE(sent_at, created_at)) = CURDATE()
            GROUP BY mail_account_id
        ) sent ON sent.mail_account_id = a.id
        WHERE a.deleted_at IS NULL AND a.is_enabled = 1
        ORDER BY a.is_default DESC, u.username, a.email_address");
    return $stmt->fetchAll();
}

function crm_marketing_company_signature(): array
{
    if (!function_exists('db_table_exists') || !db_table_exists('crm_mail_signature_templates')) return [];
    $stmt = db()->query("SELECT id, template_name,
            CASE WHEN COALESCE(template_html, '') <> '' THEN 1 ELSE 0 END AS has_template,
            OCTET_LENGTH(COALESCE(template_html, '')) AS template_bytes
        FROM crm_mail_signature_templates
        WHERE is_default = 1
        ORDER BY id DESC
        LIMIT 1");
    $row = $stmt->fetch();
    return $row ?: [];
}

function crm_marketing_signature_content(array $input = []): array
{
    crm_marketing_ensure_tables();
    crm_require('promotion.view');
    $signatureKey = strtolower(trim((string)($input['signature_key'] ?? 'personal')));
    if ($signatureKey === 'none') return ['signature_key' => 'none', 'html' => '', 'has_html' => 0];
    if ($signatureKey === 'company') {
        if (!function_exists('db_table_exists') || !db_table_exists('crm_mail_signature_templates')) {
            return ['signature_key' => 'company', 'html' => '', 'has_html' => 0];
        }
        $stmt = db()->query("SELECT id, template_name, template_html
            FROM crm_mail_signature_templates
            WHERE is_default = 1
            ORDER BY id DESC
            LIMIT 1");
        $row = $stmt->fetch() ?: [];
        return [
            'signature_key' => 'company',
            'signature_id' => (int)($row['id'] ?? 0),
            'template_name' => (string)($row['template_name'] ?? ''),
            'html' => (string)($row['template_html'] ?? ''),
            'has_html' => trim((string)($row['template_html'] ?? '')) !== '' ? 1 : 0,
        ];
    }
    if (!function_exists('db_table_exists') || !db_table_exists('crm_user_mail_accounts')) {
        return ['signature_key' => 'personal', 'account_id' => 0, 'html' => '', 'has_html' => 0];
    }
    $accountId = (int)($input['mail_account_id'] ?? 0);
    $sql = "SELECT id, signature_html
        FROM crm_user_mail_accounts
        WHERE deleted_at IS NULL AND is_enabled = 1";
    $params = [];
    if ($accountId > 0) {
        $sql .= ' AND id = ?';
        $params[] = $accountId;
    }
    $sql .= ' ORDER BY is_default DESC, id DESC LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch() ?: [];
    return [
        'signature_key' => 'personal',
        'account_id' => (int)($row['id'] ?? 0),
        'html' => (string)($row['signature_html'] ?? ''),
        'has_html' => trim((string)($row['signature_html'] ?? '')) !== '' ? 1 : 0,
    ];
}

function crm_marketing_chat_groups(array $input = []): array
{
    crm_customer_ensure_tables();
    crm_require('promotion.view');
    $stmt = db()->query("SELECT g.*, c.customer_name, c.customer_code, c.country, c.owner_user_id, u.username AS owner_name
        FROM crm_customer_chat_groups g
        JOIN crm_customers c ON c.id = g.customer_id AND c.deleted_at IS NULL
        LEFT JOIN crm_users u ON u.id = c.owner_user_id
        WHERE g.deleted_at IS NULL AND g.use_for_promotion = 1 AND g.status = 'active'
        ORDER BY g.updated_at DESC, g.id DESC
        LIMIT 1000");
    return $stmt->fetchAll();
}

function crm_marketing_task_targets(array $input = []): array
{
    crm_marketing_ensure_tables();
    crm_require('promotion.view');
    $taskId = (int)($input['task_id'] ?? 0);
    if ($taskId > 0) {
        crm_marketing_reconcile_task_targets_from_queue($taskId);
        crm_marketing_reconcile_group_targets($taskId);
        crm_marketing_reconcile_email_group_followups($taskId);
    }
    $status = trim((string)($input['status'] ?? ''));
    $params = [];
    $where = ['1=1'];
    if ($taskId > 0) {
        $where[] = 'mt.task_id = ?';
        $params[] = $taskId;
    }
    if ($status !== '') {
        $where[] = 'mt.target_status = ?';
        $params[] = $status;
    }
    $defaultLimit = $taskId > 0 ? 1000 : 200;
    $limit = (int)($input['limit'] ?? $defaultLimit);
    $limit = max(1, min(5000, $limit));
    $stmt = db()->prepare("SELECT mt.*, t.task_name, t.task_status, t.campaign_type, t.mail_subject,
        c.customer_name, c.customer_code, c.country, c.do_not_contact, c.phone AS customer_phone, c.whatsapp AS customer_whatsapp, c.owner_user_id,
        ct.name AS contact_name, ct.email, ct.phone, ct.whatsapp, ct.wechat, ct.linkedin, ct.position, ct.is_left,
        cg.group_name AS chat_group_name, cg.group_platform AS chat_group_platform, cg.group_owner AS chat_group_owner,
        COALESCE(ex.real_name, ex.username) AS executor_name,
        COALESCE(chk.real_name, chk.username) AS manual_checked_by_name,
        CASE WHEN mt.target_status IN ('pending','failed') AND mt.due_at IS NOT NULL AND mt.due_at < NOW() THEN 'overdue' ELSE mt.target_status END AS manual_status,
        COALESCE(ps.status, 'not_promoted') customer_promotion_status,
        CASE WHEN TRIM(COALESCE(c.email, '')) <> ''
               OR TRIM(COALESCE(c.backup_email, '')) <> ''
               OR EXISTS (
                    SELECT 1
                    FROM crm_contacts any_ct
                    WHERE any_ct.customer_id = mt.customer_id
                      AND any_ct.deleted_at IS NULL
                      AND COALESCE(any_ct.is_left, 0) = 0
                      AND COALESCE(any_ct.do_not_contact, 0) = 0
                      AND COALESCE(any_ct.unsubscribe_email, 0) = 0
                      AND TRIM(COALESCE(any_ct.email, '')) <> ''
                    LIMIT 1
               )
             THEN 1 ELSE 0 END AS customer_has_any_email
        FROM crm_marketing_task_targets mt
        JOIN crm_marketing_tasks t ON t.id = mt.task_id
        JOIN crm_customers c ON c.id = mt.customer_id
        LEFT JOIN crm_contacts ct ON ct.id = mt.contact_id
        LEFT JOIN crm_customer_chat_groups cg ON cg.id = mt.chat_group_id
        LEFT JOIN crm_users ex ON ex.id = mt.executor_user_id
        LEFT JOIN crm_users chk ON chk.id = mt.manual_checked_by_user_id
        LEFT JOIN crm_customer_promotion_status ps ON ps.customer_id = c.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY FIELD(mt.target_status, 'failed','pending','success'), mt.id DESC
        LIMIT {$limit}");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function crm_marketing_logs(array $input = []): array
{
    crm_marketing_ensure_tables();
    crm_require('promotion.view');
    $taskId = (int)($input['task_id'] ?? 0);
    $params = [];
    $where = ['1=1'];
    if ($taskId > 0) {
        $where[] = 'ml.task_id = ?';
        $params[] = $taskId;
    }
    $limit = $taskId > 0 ? 1000 : 100;
    $stmt = db()->prepare("SELECT ml.*, t.task_name, c.customer_name, ct.name AS contact_name, COALESCE(u.real_name, u.username) AS operator_name
        FROM crm_marketing_logs ml
        LEFT JOIN crm_marketing_tasks t ON t.id = ml.task_id
        LEFT JOIN crm_customers c ON c.id = ml.customer_id
        LEFT JOIN crm_contacts ct ON ct.id = ml.contact_id
        LEFT JOIN crm_users u ON u.id = ml.operator_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY ml.touched_at DESC, ml.id DESC LIMIT {$limit}");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function crm_marketing_feedback_types(): array
{
    return [
        'interest' => '客户有兴趣',
        'quote_request' => '要求报价',
        'material_request' => '要求资料',
        'sample_request' => '要求样品',
        'question' => '客户提问',
        'price_high' => '反馈价格高',
        'later_follow' => '以后再跟进',
        'no_need' => '暂不需要',
        'not_relevant' => '产品不匹配',
        'unsubscribe' => '要求勿扰',
        'complaint' => '投诉/负面反馈',
        'other' => '其他反馈',
    ];
}

function crm_marketing_feedback_next_actions(): array
{
    return [
        'none' => '暂不处理',
        'followup' => '后续跟进',
        'quote' => '创建报价',
        'material' => '发送资料',
        'sample' => '寄送样品',
        'visit' => '安排拜访',
        'dispatch' => '生成派工',
    ];
}

function crm_marketing_feedback_decorate(array $row): array
{
    $detail = json_decode((string)($row['detail_json'] ?? ''), true);
    if (!is_array($detail)) $detail = [];
    $typeMap = crm_marketing_feedback_types();
    $nextMap = crm_marketing_feedback_next_actions();
    $type = (string)($detail['feedback_type'] ?? 'other');
    $next = (string)($detail['next_action'] ?? 'none');
    $row['feedback_type'] = $type;
    $row['feedback_type_label'] = $typeMap[$type] ?? $typeMap['other'];
    $row['feedback_content'] = (string)($detail['feedback_content'] ?? $detail['content'] ?? '');
    $row['feedback_time'] = (string)($detail['feedback_time'] ?? $row['touched_at'] ?? $row['created_at'] ?? '');
    $row['next_action'] = $next;
    $row['next_action_label'] = $nextMap[$next] ?? $nextMap['none'];
    $row['is_handled'] = (int)($detail['is_handled'] ?? ((string)($row['result_status'] ?? '') === 'handled' ? 1 : 0));
    $row['detail'] = $detail;
    return $row;
}

function crm_marketing_feedback_list(array $input = []): array
{
    crm_marketing_ensure_tables();
    crm_require('promotion.view');
    $taskId = (int)($input['task_id'] ?? 0);
    $customerId = (int)($input['customer_id'] ?? 0);
    $handled = trim((string)($input['handled'] ?? ''));
    $channel = crm_marketing_normalize_channel((string)($input['channel_key'] ?? ''));
    $params = [];
    $where = ["ml.action_key = 'customer_feedback'"];
    if ($taskId > 0) {
        $where[] = 'ml.task_id = ?';
        $params[] = $taskId;
    }
    if ($customerId > 0) {
        $where[] = 'ml.customer_id = ?';
        $params[] = $customerId;
    }
    if ($channel !== '') {
        $where[] = 'ml.channel_key = ?';
        $params[] = $channel;
    }
    if ($handled === '1' || $handled === 'handled') {
        $where[] = "ml.result_status = 'handled'";
    } elseif ($handled === '0' || $handled === 'pending') {
        $where[] = "ml.result_status <> 'handled'";
    }
    $limit = max(1, min(1000, (int)($input['limit'] ?? ($taskId > 0 ? 300 : 120))));
    $stmt = db()->prepare("SELECT ml.*, t.task_name, c.customer_name, c.country, ct.name AS contact_name, ct.email, ct.phone, ct.whatsapp, ct.wechat, COALESCE(u.real_name, u.username) AS operator_name
        FROM crm_marketing_logs ml
        LEFT JOIN crm_marketing_tasks t ON t.id = ml.task_id
        LEFT JOIN crm_customers c ON c.id = ml.customer_id
        LEFT JOIN crm_contacts ct ON ct.id = ml.contact_id
        LEFT JOIN crm_users u ON u.id = ml.operator_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY ml.touched_at DESC, ml.id DESC
        LIMIT {$limit}");
    $stmt->execute($params);
    $rows = array_map('crm_marketing_feedback_decorate', $stmt->fetchAll());
    $feedbackSummary = crm_marketing_feedback_summary($taskId, $customerId);
    $touchRows = $customerId > 0 ? crm_marketing_customer_touch_rows($customerId, 120) : [];
    $activityLogs = $customerId > 0 ? crm_marketing_customer_activity_logs($customerId, 120) : [];
    return [
        'rows' => $rows,
        'touches' => $touchRows,
        'activity_logs' => $activityLogs,
        'summary' => $customerId > 0
            ? crm_marketing_customer_promotion_summary($feedbackSummary, $touchRows, $activityLogs)
            : $feedbackSummary,
    ];
}

function crm_marketing_feedback_summary(int $taskId = 0, int $customerId = 0): array
{
    crm_marketing_ensure_tables();
    $params = [];
    $where = ["action_key = 'customer_feedback'"];
    if ($taskId > 0) {
        $where[] = 'task_id = ?';
        $params[] = $taskId;
    }
    if ($customerId > 0) {
        $where[] = 'customer_id = ?';
        $params[] = $customerId;
    }
    $stmt = db()->prepare('SELECT id, channel_key, result_status, detail_json, touched_at FROM crm_marketing_logs WHERE ' . implode(' AND ', $where) . ' ORDER BY touched_at DESC, id DESC LIMIT 2000');
    $stmt->execute($params);
    $typeMap = crm_marketing_feedback_types();
    $summary = [
        'total' => 0,
        'pending' => 0,
        'handled' => 0,
        'latest_time' => null,
        'by_type' => [],
        'by_channel' => [],
        'type_labels' => $typeMap,
    ];
    foreach ($stmt->fetchAll() as $row) {
        $summary['total']++;
        $status = (string)($row['result_status'] ?? '');
        if ($status === 'handled') $summary['handled']++;
        else $summary['pending']++;
        $channel = crm_marketing_normalize_channel((string)($row['channel_key'] ?? 'other')) ?: 'other';
        $summary['by_channel'][$channel] = ($summary['by_channel'][$channel] ?? 0) + 1;
        $detail = json_decode((string)($row['detail_json'] ?? ''), true);
        if (!is_array($detail)) $detail = [];
        $type = (string)($detail['feedback_type'] ?? 'other');
        if (!isset($typeMap[$type])) $type = 'other';
        $summary['by_type'][$type] = ($summary['by_type'][$type] ?? 0) + 1;
        $time = (string)($detail['feedback_time'] ?? $row['touched_at'] ?? '');
        if ($time !== '' && (!$summary['latest_time'] || $time > $summary['latest_time'])) {
            $summary['latest_time'] = $time;
        }
    }
    return $summary;
}

function crm_marketing_customer_touch_rows(int $customerId, int $limit = 80): array
{
    crm_marketing_ensure_tables();
    if ($customerId <= 0) return [];
    $limit = max(1, min(300, $limit));
    $stmt = db()->prepare("SELECT mt.id AS target_id, mt.task_id, mt.customer_id, mt.contact_id, mt.chat_group_id,
            mt.channel_key, mt.contact_method, mt.manual_group_name, mt.target_status, mt.failure_reason,
            mt.executed_at, mt.planned_at, mt.due_at, mt.manual_result, mt.manual_remark, mt.created_at AS target_created_at,
            t.task_name, t.task_status, t.campaign_type, t.mail_subject,
            c.customer_name, c.customer_code, c.country,
            ct.name AS contact_name, ct.email, ct.phone, ct.whatsapp, ct.wechat,
            cg.group_name AS chat_group_name, cg.group_platform AS chat_group_platform,
            COALESCE(ex.real_name, ex.username) AS executor_name,
            q.sender_email AS queue_sender_email, q.receiver_email AS queue_receiver_email,
            q.send_status AS queue_status, q.sent_at AS queue_sent_at, q.last_error AS queue_last_error,
            q.planned_server_time AS queue_planned_server_time
        FROM crm_marketing_task_targets mt
        JOIN crm_marketing_tasks t ON t.id = mt.task_id
        JOIN crm_customers c ON c.id = mt.customer_id
        LEFT JOIN crm_contacts ct ON ct.id = mt.contact_id
        LEFT JOIN crm_customer_chat_groups cg ON cg.id = mt.chat_group_id
        LEFT JOIN crm_users ex ON ex.id = mt.executor_user_id
        LEFT JOIN crm_marketing_send_queue q ON q.id = (
            SELECT q2.id
            FROM crm_marketing_send_queue q2
            WHERE q2.task_id = mt.task_id
              AND q2.customer_id = mt.customer_id
              AND (mt.contact_id IS NULL OR mt.contact_id = 0 OR q2.contact_id = mt.contact_id)
            ORDER BY COALESCE(q2.sent_at, q2.updated_at, q2.created_at) DESC, q2.id DESC
            LIMIT 1
        )
        WHERE mt.customer_id = ?
        ORDER BY COALESCE(mt.executed_at, q.sent_at, q.updated_at, mt.planned_at, mt.created_at) DESC, mt.id DESC
        LIMIT {$limit}");
    $stmt->execute([$customerId]);
    return $stmt->fetchAll();
}

function crm_marketing_customer_activity_logs(int $customerId, int $limit = 120): array
{
    crm_marketing_ensure_tables();
    if ($customerId <= 0) return [];
    $limit = max(1, min(300, $limit));
    $stmt = db()->prepare("SELECT ml.*, t.task_name, c.customer_name, c.country,
            ct.name AS contact_name, ct.email, COALESCE(u.real_name, u.username) AS operator_name
        FROM crm_marketing_logs ml
        LEFT JOIN crm_marketing_tasks t ON t.id = ml.task_id
        LEFT JOIN crm_customers c ON c.id = ml.customer_id
        LEFT JOIN crm_contacts ct ON ct.id = ml.contact_id
        LEFT JOIN crm_users u ON u.id = ml.operator_id
        WHERE ml.customer_id = ?
          AND ml.action_key <> 'customer_feedback'
        ORDER BY ml.touched_at DESC, ml.id DESC
        LIMIT {$limit}");
    $stmt->execute([$customerId]);
    return $stmt->fetchAll();
}

function crm_marketing_customer_promotion_summary(array $feedbackSummary, array $touchRows, array $activityLogs): array
{
    $summary = $feedbackSummary;
    $summary['touch_total'] = count($touchRows);
    $summary['activity_total'] = count($activityLogs);
    $summary['email_sent'] = 0;
    $summary['manual_total'] = 0;
    $summary['failed_total'] = 0;
    $summary['skipped_total'] = 0;
    $summary['latest_touch_time'] = null;
    foreach ($touchRows as $row) {
        $channel = crm_marketing_normalize_channel((string)($row['channel_key'] ?? ''));
        $status = (string)($row['target_status'] ?? '');
        $queueStatus = (string)($row['queue_status'] ?? '');
        if ($channel === 'email' && ($status === 'success' || $queueStatus === 'sent')) $summary['email_sent']++;
        if (in_array($channel, ['wechat', 'wechat_group', 'whatsapp', 'whatsapp_group', 'offline', 'phone', 'linkedin'], true)) $summary['manual_total']++;
        if ($status === 'failed' || $queueStatus === 'failed') $summary['failed_total']++;
        if ($status === 'skipped' || $queueStatus === 'skipped') $summary['skipped_total']++;
        $time = (string)($row['executed_at'] ?? $row['queue_sent_at'] ?? $row['planned_at'] ?? $row['target_created_at'] ?? '');
        if ($time !== '' && (!$summary['latest_touch_time'] || $time > $summary['latest_touch_time'])) {
            $summary['latest_touch_time'] = $time;
        }
    }
    foreach ($activityLogs as $row) {
        $time = (string)($row['touched_at'] ?? $row['created_at'] ?? '');
        if ($time !== '' && (!$summary['latest_touch_time'] || $time > $summary['latest_touch_time'])) {
            $summary['latest_touch_time'] = $time;
        }
    }
    return $summary;
}

function crm_marketing_feedback_mail_context(array $input): array
{
    crm_marketing_ensure_tables();
    crm_require('promotion.view');
    crm_require('mail.view');
    $mailId = (int)($input['mail_id'] ?? 0);
    if ($mailId <= 0) throw new RuntimeException('邮件 ID 无效。');
    $account = crm_mail_current_account(false);
    if (!$account) throw new RuntimeException('请先绑定邮箱。');
    $stmt = db()->prepare('SELECT m.id, m.folder, m.subject, m.from_email, m.from_name, m.to_emails, m.cc_emails, m.received_at, m.sent_at, m.linked_customer_id, m.linked_contact_id, c.customer_name AS linked_customer_name, c.country AS linked_customer_country
        FROM crm_mails m
        LEFT JOIN crm_customers c ON c.id = m.linked_customer_id
        WHERE m.id = ? AND m.user_id = ? AND m.mail_account_id = ? LIMIT 1');
    $stmt->execute([$mailId, (int)$account['user_id'], (int)$account['id']]);
    $mail = $stmt->fetch();
    if (!$mail) throw new RuntimeException('邮件不存在或无权查看。');

    $customerId = (int)($mail['linked_customer_id'] ?? 0);
    $contactId = (int)($mail['linked_contact_id'] ?? 0);
    $fromEmail = strtolower(trim((string)($mail['from_email'] ?? '')));
    $matchedContact = null;
    if ($contactId <= 0 && $fromEmail !== '') {
        $stmt = db()->prepare('SELECT ct.id, ct.customer_id, ct.name, ct.email, c.customer_name, c.country
            FROM crm_contacts ct
            JOIN crm_customers c ON c.id = ct.customer_id
            WHERE ct.deleted_at IS NULL
              AND c.deleted_at IS NULL
              AND LOWER(TRIM(COALESCE(ct.email, ""))) = ?
            ORDER BY ct.is_primary DESC, ct.id DESC
            LIMIT 1');
        $stmt->execute([$fromEmail]);
        $matchedContact = $stmt->fetch();
        if ($matchedContact) {
            $contactId = (int)$matchedContact['id'];
            if ($customerId <= 0) $customerId = (int)$matchedContact['customer_id'];
        }
    }
    if ($customerId <= 0 && $fromEmail !== '') {
        $stmt = db()->prepare('SELECT id, customer_name, country
            FROM crm_customers
            WHERE deleted_at IS NULL
              AND (LOWER(TRIM(COALESCE(email, ""))) = ? OR LOWER(TRIM(COALESCE(backup_email, ""))) = ?)
            ORDER BY id DESC
            LIMIT 1');
        $stmt->execute([$fromEmail, $fromEmail]);
        $customer = $stmt->fetch();
        if ($customer) $customerId = (int)$customer['id'];
    }

    $where = [];
    $params = [];
    if ($customerId > 0) {
        $where[] = 'mt.customer_id = ?';
        $params[] = $customerId;
    }
    if ($fromEmail !== '') {
        $where[] = '(LOWER(TRIM(COALESCE(ct.email, ""))) = ? OR LOWER(TRIM(COALESCE(mt.contact_method, ""))) = ? OR LOWER(TRIM(COALESCE(c.email, ""))) = ? OR LOWER(TRIM(COALESCE(c.backup_email, ""))) = ?)';
        array_push($params, $fromEmail, $fromEmail, $fromEmail, $fromEmail);
    }
    if (!$where) throw new RuntimeException('当前邮件未关联客户，也没有可识别的发件邮箱。');

    $stmt = db()->prepare("SELECT mt.id AS target_id, mt.task_id, mt.customer_id, mt.contact_id, mt.channel_key, mt.contact_method, mt.target_status, mt.executed_at, mt.created_at AS target_created_at,
            t.task_name, t.task_status, t.campaign_type, t.mail_subject, t.updated_at AS task_updated_at,
            c.customer_name, c.customer_code, c.country,
            ct.name AS contact_name, ct.email AS contact_email, ct.phone AS contact_phone, ct.whatsapp AS contact_whatsapp, ct.wechat AS contact_wechat
        FROM crm_marketing_task_targets mt
        JOIN crm_marketing_tasks t ON t.id = mt.task_id
        JOIN crm_customers c ON c.id = mt.customer_id
        LEFT JOIN crm_contacts ct ON ct.id = mt.contact_id
        WHERE (" . implode(' OR ', $where) . ")
          AND c.deleted_at IS NULL
        ORDER BY COALESCE(mt.executed_at, t.updated_at, mt.created_at) DESC, mt.id DESC
        LIMIT 80");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $subject = trim((string)($mail['subject'] ?? ''));
    $candidates = [];
    $seen = [];
    foreach ($rows as $row) {
        $key = (int)($row['task_id'] ?? 0) . ':' . (int)($row['customer_id'] ?? 0) . ':' . (int)($row['contact_id'] ?? 0);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $score = 0;
        $reason = [];
        if ($customerId > 0 && (int)$row['customer_id'] === $customerId) {
            $score += 30;
            $reason[] = '同一客户';
        }
        if ($contactId > 0 && (int)$row['contact_id'] === $contactId) {
            $score += 45;
            $reason[] = '同一联系人';
        }
        $rowEmail = strtolower(trim((string)($row['contact_email'] ?? '')));
        $method = strtolower(trim((string)($row['contact_method'] ?? '')));
        if ($fromEmail !== '' && ($rowEmail === $fromEmail || $method === $fromEmail)) {
            $score += 35;
            $reason[] = '邮箱匹配';
        }
        $mailSubject = trim((string)($row['mail_subject'] ?? ''));
        if ($subject !== '' && $mailSubject !== '') {
            $a = mb_strtolower($subject, 'UTF-8');
            $b = mb_strtolower($mailSubject, 'UTF-8');
            if ($a === $b || mb_strpos($a, $b) !== false || mb_strpos($b, $a) !== false) {
                $score += 18;
                $reason[] = '主题接近';
            }
        }
        if ((string)($row['target_status'] ?? '') === 'success') $score += 6;
        $row['score'] = $score;
        $row['match_reason'] = $reason ? implode('、', $reason) : '近期推广目标';
        $candidates[] = $row;
    }
    usort($candidates, static function ($a, $b): int {
        $score = ((int)($b['score'] ?? 0)) <=> ((int)($a['score'] ?? 0));
        if ($score !== 0) return $score;
        return strcmp((string)($b['executed_at'] ?? $b['task_updated_at'] ?? ''), (string)($a['executed_at'] ?? $a['task_updated_at'] ?? ''));
    });
    $candidates = array_slice($candidates, 0, 12);

    return [
        'mail' => [
            'id' => (int)$mail['id'],
            'subject' => (string)($mail['subject'] ?? ''),
            'from_name' => (string)($mail['from_name'] ?? ''),
            'from_email' => (string)($mail['from_email'] ?? ''),
            'received_at' => (string)($mail['received_at'] ?? $mail['sent_at'] ?? ''),
            'linked_customer_id' => $customerId ?: null,
            'linked_contact_id' => $contactId ?: null,
            'linked_customer_name' => (string)($mail['linked_customer_name'] ?? ($matchedContact['customer_name'] ?? '')),
            'linked_customer_country' => (string)($mail['linked_customer_country'] ?? ($matchedContact['country'] ?? '')),
        ],
        'candidates' => $candidates,
    ];
}

function crm_marketing_feedback_save(array $input): array
{
    crm_marketing_ensure_tables();
    crm_require('promotion.execute');
    $taskId = (int)($input['task_id'] ?? 0);
    $customerId = (int)($input['customer_id'] ?? 0);
    $contactId = (int)($input['contact_id'] ?? 0);
    if ($taskId <= 0) throw new RuntimeException('请选择推广任务。');
    if ($customerId <= 0) throw new RuntimeException('请选择反馈客户。');
    $task = crm_marketing_task_row($taskId);
    $stmt = db()->prepare('SELECT id, customer_name, customer_code, country FROM crm_customers WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch();
    if (!$customer) throw new RuntimeException('客户不存在或已删除。');
    $contact = null;
    if ($contactId > 0) {
        $stmt = db()->prepare('SELECT id, name, email, phone, whatsapp, wechat FROM crm_contacts WHERE id = ? AND customer_id = ? AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([$contactId, $customerId]);
        $contact = $stmt->fetch();
        if (!$contact) throw new RuntimeException('联系人不属于当前客户。');
    }
    $channel = crm_marketing_normalize_channel((string)($input['channel_key'] ?? ''));
    if ($channel === '') throw new RuntimeException('请选择反馈渠道。');
    $allowedChannels = ['email','wechat','wechat_group','whatsapp','whatsapp_group','phone','linkedin','offline','other'];
    if (!in_array($channel, $allowedChannels, true)) $channel = 'other';
    $typeMap = crm_marketing_feedback_types();
    $type = (string)($input['feedback_type'] ?? 'other');
    if (!isset($typeMap[$type])) $type = 'other';
    $nextMap = crm_marketing_feedback_next_actions();
    $nextAction = (string)($input['next_action'] ?? 'none');
    if (!isset($nextMap[$nextAction])) $nextAction = 'none';
    $content = trim((string)($input['feedback_content'] ?? $input['content'] ?? ''));
    if ($content === '') throw new RuntimeException('请填写客户反馈内容。');
    $content = function_exists('mb_substr') ? mb_substr($content, 0, 5000, 'UTF-8') : substr($content, 0, 5000);
    $feedbackTime = trim((string)($input['feedback_time'] ?? ''));
    $feedbackTime = str_replace('T', ' ', $feedbackTime);
    $feedbackTime = $feedbackTime !== '' && strtotime($feedbackTime) ? date('Y-m-d H:i:s', strtotime($feedbackTime)) : date('Y-m-d H:i:s');
    $handled = !empty($input['is_handled']) && (string)$input['is_handled'] !== '0';
    $sourceMailId = (int)($input['source_mail_id'] ?? 0);
    $detail = [
        'source' => $sourceMailId > 0 ? 'mail_reply' : 'manual',
        'task_id' => $taskId,
        'task_name' => (string)($task['task_name'] ?? ''),
        'customer_id' => $customerId,
        'customer_name' => (string)($customer['customer_name'] ?? ''),
        'contact_id' => $contactId ?: null,
        'contact_name' => $contact ? (string)($contact['name'] ?? '') : '',
        'source_mail_id' => $sourceMailId > 0 ? $sourceMailId : null,
        'source_mail_subject' => trim((string)($input['source_mail_subject'] ?? '')),
        'source_mail_from' => trim((string)($input['source_mail_from'] ?? '')),
        'feedback_type' => $type,
        'feedback_type_label' => $typeMap[$type],
        'feedback_content' => $content,
        'feedback_time' => $feedbackTime,
        'next_action' => $nextAction,
        'next_action_label' => $nextMap[$nextAction],
        'is_handled' => $handled ? 1 : 0,
    ];
    db()->prepare('INSERT INTO crm_marketing_logs (task_id, customer_id, contact_id, channel_key, action_key, result_status, failure_reason, operator_id, detail_json, touched_at, created_at) VALUES (?, ?, ?, ?, "customer_feedback", ?, "", ?, ?, ?, NOW())')
        ->execute([$taskId, $customerId, $contactId ?: null, $channel, $handled ? 'handled' : 'pending', current_user()['id'] ?? null, json_encode($detail, JSON_UNESCAPED_UNICODE), $feedbackTime]);
    $logId = (int)db()->lastInsertId();
    if (function_exists('crm_customer_log')) {
        crm_customer_log('promotion_feedback', 'marketing_task', (string)$taskId, $customerId, null, $detail, '推广反馈：' . $typeMap[$type]);
    }
    if (function_exists('crm_customer_timeline_add')) {
        crm_customer_timeline_add($customerId, 'promotion_feedback', '推广反馈 · ' . $typeMap[$type], $content, 'marketing_task', (string)$taskId);
    }
    crm_log_event('promotion', 'customer_feedback', 'marketing_task', (string)$taskId, null, $detail);
    return [
        'feedback_id' => $logId,
        'feedback' => crm_marketing_feedback_decorate(array_merge($detail, [
            'id' => $logId,
            'task_id' => $taskId,
            'customer_id' => $customerId,
            'contact_id' => $contactId,
            'channel_key' => $channel,
            'action_key' => 'customer_feedback',
            'result_status' => $handled ? 'handled' : 'pending',
            'touched_at' => $feedbackTime,
            'created_at' => date('Y-m-d H:i:s'),
            'task_name' => (string)($task['task_name'] ?? ''),
            'customer_name' => (string)($customer['customer_name'] ?? ''),
            'contact_name' => $contact ? (string)($contact['name'] ?? '') : '',
            'operator_name' => current_user()['real_name'] ?? current_user()['username'] ?? '',
            'detail_json' => json_encode($detail, JSON_UNESCAPED_UNICODE),
        ])),
        'feedback_logs' => crm_marketing_feedback_list(['task_id' => $taskId])['rows'],
        'feedback_summary' => crm_marketing_feedback_summary($taskId),
        'report' => crm_marketing_task_report(['task_id' => $taskId]),
    ];
}

function crm_marketing_feedback_row(int $feedbackId): array
{
    crm_marketing_ensure_tables();
    if ($feedbackId <= 0) throw new RuntimeException('反馈记录 ID 无效。');
    $stmt = db()->prepare("SELECT ml.*, t.task_name, c.customer_name, c.customer_code, c.country, ct.name AS contact_name, ct.email, ct.phone, ct.whatsapp, ct.wechat, COALESCE(u.real_name, u.username) AS operator_name
        FROM crm_marketing_logs ml
        LEFT JOIN crm_marketing_tasks t ON t.id = ml.task_id
        LEFT JOIN crm_customers c ON c.id = ml.customer_id
        LEFT JOIN crm_contacts ct ON ct.id = ml.contact_id
        LEFT JOIN crm_users u ON u.id = ml.operator_id
        WHERE ml.id = ? AND ml.action_key = 'customer_feedback'
        LIMIT 1");
    $stmt->execute([$feedbackId]);
    $row = $stmt->fetch();
    if (!$row) throw new RuntimeException('反馈记录不存在或已删除。');
    return $row;
}

function crm_marketing_feedback_update(array $input): array
{
    crm_marketing_ensure_tables();
    crm_require('promotion.execute');
    $feedbackId = (int)($input['feedback_id'] ?? $input['id'] ?? 0);
    $oldRow = crm_marketing_feedback_row($feedbackId);
    $oldDetail = json_decode((string)($oldRow['detail_json'] ?? ''), true);
    if (!is_array($oldDetail)) $oldDetail = [];

    $taskId = (int)($input['task_id'] ?? $oldRow['task_id'] ?? 0);
    $customerId = (int)($input['customer_id'] ?? $oldRow['customer_id'] ?? 0);
    $contactId = array_key_exists('contact_id', $input) ? (int)$input['contact_id'] : (int)($oldRow['contact_id'] ?? 0);
    if ($taskId <= 0) throw new RuntimeException('请选择推广任务。');
    if ($customerId <= 0) throw new RuntimeException('请选择反馈客户。');
    $task = crm_marketing_task_row($taskId);
    $stmt = db()->prepare('SELECT id, customer_name, customer_code, country FROM crm_customers WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch();
    if (!$customer) throw new RuntimeException('客户不存在或已删除。');
    $contact = null;
    if ($contactId > 0) {
        $stmt = db()->prepare('SELECT id, name, email, phone, whatsapp, wechat FROM crm_contacts WHERE id = ? AND customer_id = ? AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([$contactId, $customerId]);
        $contact = $stmt->fetch();
        if (!$contact) throw new RuntimeException('联系人不属于当前客户。');
    }

    $channel = crm_marketing_normalize_channel((string)($input['channel_key'] ?? $oldRow['channel_key'] ?? ''));
    if ($channel === '') throw new RuntimeException('请选择反馈渠道。');
    $allowedChannels = ['email','wechat','wechat_group','whatsapp','whatsapp_group','phone','linkedin','offline','other'];
    if (!in_array($channel, $allowedChannels, true)) $channel = 'other';
    $typeMap = crm_marketing_feedback_types();
    $type = (string)($input['feedback_type'] ?? $oldDetail['feedback_type'] ?? 'other');
    if (!isset($typeMap[$type])) $type = 'other';
    $nextMap = crm_marketing_feedback_next_actions();
    $nextAction = (string)($input['next_action'] ?? $oldDetail['next_action'] ?? 'none');
    if (!isset($nextMap[$nextAction])) $nextAction = 'none';
    $content = trim((string)($input['feedback_content'] ?? $input['content'] ?? $oldDetail['feedback_content'] ?? $oldDetail['content'] ?? ''));
    if ($content === '') throw new RuntimeException('请填写客户反馈内容。');
    $content = function_exists('mb_substr') ? mb_substr($content, 0, 5000, 'UTF-8') : substr($content, 0, 5000);
    $feedbackTime = trim((string)($input['feedback_time'] ?? $oldDetail['feedback_time'] ?? $oldRow['touched_at'] ?? ''));
    $feedbackTime = str_replace('T', ' ', $feedbackTime);
    $feedbackTime = $feedbackTime !== '' && strtotime($feedbackTime) ? date('Y-m-d H:i:s', strtotime($feedbackTime)) : date('Y-m-d H:i:s');
    $handled = !empty($input['is_handled']) && (string)$input['is_handled'] !== '0';
    if (!array_key_exists('is_handled', $input)) {
        $handled = (int)($oldDetail['is_handled'] ?? ((string)($oldRow['result_status'] ?? '') === 'handled' ? 1 : 0)) === 1;
    }
    $sourceMailId = (int)($input['source_mail_id'] ?? $oldDetail['source_mail_id'] ?? 0);
    $detail = array_merge($oldDetail, [
        'source' => (string)($oldDetail['source'] ?? ($sourceMailId > 0 ? 'mail_reply' : 'manual')),
        'task_id' => $taskId,
        'task_name' => (string)($task['task_name'] ?? ''),
        'customer_id' => $customerId,
        'customer_name' => (string)($customer['customer_name'] ?? ''),
        'contact_id' => $contactId ?: null,
        'contact_name' => $contact ? (string)($contact['name'] ?? '') : '',
        'source_mail_id' => $sourceMailId > 0 ? $sourceMailId : null,
        'source_mail_subject' => trim((string)($input['source_mail_subject'] ?? $oldDetail['source_mail_subject'] ?? '')),
        'source_mail_from' => trim((string)($input['source_mail_from'] ?? $oldDetail['source_mail_from'] ?? '')),
        'feedback_type' => $type,
        'feedback_type_label' => $typeMap[$type],
        'feedback_content' => $content,
        'feedback_time' => $feedbackTime,
        'next_action' => $nextAction,
        'next_action_label' => $nextMap[$nextAction],
        'is_handled' => $handled ? 1 : 0,
        'updated_at' => date('Y-m-d H:i:s'),
        'updated_by' => current_user()['id'] ?? null,
    ]);
    db()->prepare('UPDATE crm_marketing_logs SET task_id = ?, customer_id = ?, contact_id = ?, channel_key = ?, result_status = ?, failure_reason = "", operator_id = ?, detail_json = ?, touched_at = ? WHERE id = ? AND action_key = "customer_feedback"')
        ->execute([$taskId, $customerId, $contactId ?: null, $channel, $handled ? 'handled' : 'pending', current_user()['id'] ?? null, json_encode($detail, JSON_UNESCAPED_UNICODE), $feedbackTime, $feedbackId]);
    if (function_exists('crm_customer_log')) {
        crm_customer_log('promotion_feedback_update', 'marketing_task', (string)$taskId, $customerId, null, $detail, '推广反馈修改：' . $typeMap[$type]);
    }
    crm_log_event('promotion', 'customer_feedback_update', 'marketing_task', (string)$taskId, null, ['feedback_id' => $feedbackId, 'detail' => $detail]);
    $fresh = crm_marketing_feedback_decorate(crm_marketing_feedback_row($feedbackId));
    return [
        'feedback_id' => $feedbackId,
        'feedback' => $fresh,
        'feedback_logs' => crm_marketing_feedback_list(['task_id' => $taskId])['rows'],
        'feedback_summary' => crm_marketing_feedback_summary($taskId),
        'customer_feedback' => crm_marketing_feedback_list(['customer_id' => $customerId, 'limit' => 80]),
        'report' => crm_marketing_task_report(['task_id' => $taskId]),
    ];
}

function crm_marketing_feedback_delete(array $input): array
{
    crm_marketing_ensure_tables();
    crm_require('promotion.execute');
    $feedbackId = (int)($input['feedback_id'] ?? $input['id'] ?? 0);
    $row = crm_marketing_feedback_row($feedbackId);
    $taskId = (int)($row['task_id'] ?? 0);
    $customerId = (int)($row['customer_id'] ?? 0);
    db()->prepare('DELETE FROM crm_marketing_logs WHERE id = ? AND action_key = "customer_feedback"')->execute([$feedbackId]);
    crm_log_event('promotion', 'customer_feedback_delete', 'marketing_task', (string)$taskId, null, [
        'feedback_id' => $feedbackId,
        'customer_id' => $customerId,
        'customer_name' => (string)($row['customer_name'] ?? ''),
        'feedback_content' => crm_marketing_feedback_decorate($row)['feedback_content'] ?? '',
    ]);
    return [
        'feedback_id' => $feedbackId,
        'deleted' => true,
        'feedback_logs' => $taskId > 0 ? crm_marketing_feedback_list(['task_id' => $taskId])['rows'] : [],
        'feedback_summary' => $taskId > 0 ? crm_marketing_feedback_summary($taskId) : crm_marketing_feedback_summary(0, $customerId),
        'customer_feedback' => $customerId > 0 ? crm_marketing_feedback_list(['customer_id' => $customerId, 'limit' => 80]) : ['rows' => [], 'summary' => []],
        'report' => $taskId > 0 ? crm_marketing_task_report(['task_id' => $taskId]) : null,
    ];
}

function crm_marketing_task_execution_summary(int $taskId): array
{
    crm_marketing_reconcile_task_targets_from_queue($taskId);
    $summary = [
        'mail' => ['total' => 0, 'success' => 0, 'failed' => 0, 'skipped' => 0, 'pending' => 0, 'latest_time' => null],
        'manual' => ['total' => 0, 'success' => 0, 'failed' => 0, 'skipped' => 0, 'pending' => 0, 'latest_time' => null],
    ];
    $stmt = db()->prepare("SELECT
            CASE WHEN LOWER(channel_key) IN ('email','mail','edm') THEN 'mail' ELSE 'manual' END AS mode_key,
            target_status,
            COUNT(*) AS total,
            MAX(executed_at) AS latest_time
        FROM crm_marketing_task_targets
        WHERE task_id = ?
        GROUP BY mode_key, target_status");
    $stmt->execute([$taskId]);
    foreach ($stmt->fetchAll() as $row) {
        $mode = (string)($row['mode_key'] ?? 'manual');
        $status = (string)($row['target_status'] ?? 'pending');
        if (!isset($summary[$mode])) continue;
        $count = (int)($row['total'] ?? 0);
        $summary[$mode]['total'] += $count;
        if (isset($summary[$mode][$status])) $summary[$mode][$status] += $count;
        $latest = $row['latest_time'] ?? null;
        if ($latest && (!$summary[$mode]['latest_time'] || $latest > $summary[$mode]['latest_time'])) {
            $summary[$mode]['latest_time'] = $latest;
        }
    }
    return $summary;
}

function crm_marketing_task_target_summary(int $taskId): array
{
    crm_marketing_ensure_tables();
    if ($taskId <= 0) {
        return [
            'total_targets' => 0,
            'customers' => 0,
            'contacts' => 0,
            'groups' => 0,
            'by_channel' => [],
            'by_status' => [],
            'failure_reasons' => [],
            'email' => [],
            'manual' => [],
            'queue' => [],
        ];
    }
    crm_marketing_reconcile_task_targets_from_queue($taskId);
    crm_marketing_reconcile_group_targets($taskId);
    crm_marketing_reconcile_email_group_followups($taskId);

    $emailSql = "LOWER(COALESCE(mt.channel_key, '')) IN ('email','mail','edm')";
    $manualSql = "LOWER(COALESCE(mt.channel_key, '')) IN ('wechat','weixin','wechat_group','whatsapp','whatsapp_group','phone','offline','visit','linkedin')";
    $emailFallbackSql = "({$emailSql} AND mt.target_status IN ('pending','failed','skipped'))";
    $groupExistsSql = "EXISTS (
        SELECT 1
        FROM crm_marketing_task_targets g
        WHERE g.task_id = mt.task_id
          AND g.customer_id = mt.customer_id
          AND (g.chat_group_id IS NOT NULL OR LOWER(COALESCE(g.channel_key, '')) IN ('wechat_group','whatsapp_group'))
        LIMIT 1
    )";

    $stmt = db()->prepare("SELECT
            COUNT(*) AS total_targets,
            COUNT(DISTINCT mt.customer_id) AS customers,
            COUNT(DISTINCT NULLIF(mt.contact_id, 0)) AS contacts,
            COUNT(DISTINCT NULLIF(mt.chat_group_id, 0)) AS groups,
            SUM(CASE WHEN {$emailSql} THEN 1 ELSE 0 END) AS email_total,
            SUM(CASE WHEN {$manualSql} OR mt.chat_group_id IS NOT NULL THEN 1 ELSE 0 END) AS manual_channel_total,
            SUM(CASE WHEN {$emailSql} AND mt.target_status = 'success' THEN 1 ELSE 0 END) AS email_success,
            SUM(CASE WHEN {$emailSql} AND mt.target_status = 'failed' THEN 1 ELSE 0 END) AS email_failed,
            SUM(CASE WHEN {$emailSql} AND mt.target_status = 'skipped' THEN 1 ELSE 0 END) AS email_skipped,
            SUM(CASE WHEN {$emailSql} AND mt.target_status = 'pending' THEN 1 ELSE 0 END) AS email_pending,
            SUM(CASE WHEN {$manualSql} AND mt.target_status = 'success' THEN 1 ELSE 0 END) AS manual_success,
            SUM(CASE WHEN {$manualSql} AND mt.target_status IN ('pending','failed') THEN 1 ELSE 0 END) AS manual_direct_pending,
            SUM(CASE WHEN {$emailFallbackSql} THEN 1 ELSE 0 END) AS email_fallback_total,
            SUM(CASE WHEN {$emailFallbackSql} AND {$groupExistsSql} THEN 1 ELSE 0 END) AS email_fallback_collapsed_by_group,
            SUM(CASE WHEN {$emailFallbackSql} AND NOT {$groupExistsSql} THEN 1 ELSE 0 END) AS email_fallback_manual_pending,
            SUM(CASE WHEN {$emailFallbackSql} AND NOT {$groupExistsSql} AND COALESCE(mt.failure_reason, '') = '收件邮箱为空' THEN 1 ELSE 0 END) AS manual_no_email_pending,
            SUM(CASE WHEN {$emailFallbackSql} AND NOT {$groupExistsSql} AND COALESCE(mt.failure_reason, '') = '重复邮箱' THEN 1 ELSE 0 END) AS manual_duplicate_pending,
            SUM(CASE WHEN {$emailFallbackSql} AND NOT {$groupExistsSql} AND mt.target_status = 'failed' THEN 1 ELSE 0 END) AS manual_failed_pending,
            SUM(CASE WHEN {$emailSql} AND COALESCE(mt.failure_reason, '') = '收件邮箱为空' THEN 1 ELSE 0 END) AS email_no_receiver_recorded,
            COUNT(DISTINCT CASE WHEN {$emailSql} AND COALESCE(mt.failure_reason, '') = '收件邮箱为空' THEN mt.customer_id END) AS email_no_receiver_customers_recorded,
            SUM(CASE WHEN {$emailSql} AND COALESCE(mt.failure_reason, '') = '重复邮箱' THEN 1 ELSE 0 END) AS duplicate_email_skipped
        FROM crm_marketing_task_targets mt
        WHERE mt.task_id = ?");
    $stmt->execute([$taskId]);
    $row = $stmt->fetch() ?: [];

    $byChannel = [];
    $stmt = db()->prepare("SELECT COALESCE(NULLIF(channel_key, ''), 'unknown') AS channel_key, COUNT(*) AS total
        FROM crm_marketing_task_targets
        WHERE task_id = ?
        GROUP BY COALESCE(NULLIF(channel_key, ''), 'unknown')");
    $stmt->execute([$taskId]);
    foreach ($stmt->fetchAll() as $item) {
        $byChannel[(string)$item['channel_key']] = (int)$item['total'];
    }

    $byStatus = [];
    $stmt = db()->prepare("SELECT COALESCE(NULLIF(target_status, ''), 'unknown') AS target_status, COUNT(*) AS total
        FROM crm_marketing_task_targets
        WHERE task_id = ?
        GROUP BY COALESCE(NULLIF(target_status, ''), 'unknown')");
    $stmt->execute([$taskId]);
    foreach ($stmt->fetchAll() as $item) {
        $byStatus[(string)$item['target_status']] = (int)$item['total'];
    }

    $failureReasons = [];
    $stmt = db()->prepare("SELECT COALESCE(NULLIF(failure_reason, ''), '未填写原因') AS failure_reason, COUNT(*) AS total
        FROM crm_marketing_task_targets
        WHERE task_id = ? AND target_status IN ('failed','skipped')
        GROUP BY COALESCE(NULLIF(failure_reason, ''), '未填写原因')
        ORDER BY total DESC, failure_reason ASC");
    $stmt->execute([$taskId]);
    foreach ($stmt->fetchAll() as $item) {
        $failureReasons[(string)$item['failure_reason']] = (int)$item['total'];
    }

    $manualReasons = [];
    $stmt = db()->prepare("SELECT COALESCE(NULLIF(mt.failure_reason, ''), '人工渠道') AS failure_reason, COUNT(*) AS total
        FROM crm_marketing_task_targets mt
        WHERE mt.task_id = ?
          AND (
            ({$manualSql} AND mt.target_status IN ('pending','failed'))
            OR ({$emailFallbackSql} AND NOT {$groupExistsSql})
          )
        GROUP BY COALESCE(NULLIF(mt.failure_reason, ''), '人工渠道')
        ORDER BY total DESC, failure_reason ASC");
    $stmt->execute([$taskId]);
    foreach ($stmt->fetchAll() as $item) {
        $manualReasons[(string)$item['failure_reason']] = (int)$item['total'];
    }

    $stmt = db()->prepare("SELECT
            COUNT(*) AS queue_total,
            COUNT(DISTINCT customer_id) AS queue_customers,
            COUNT(DISTINCT LOWER(receiver_email)) AS queue_unique_receivers,
            SUM(CASE WHEN send_status = 'sent' THEN 1 ELSE 0 END) AS sent,
            SUM(CASE WHEN send_status = 'failed' THEN 1 ELSE 0 END) AS failed,
            SUM(CASE WHEN send_status IN ('pending','scheduled','sending') THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN send_status = 'waiting_retry' THEN 1 ELSE 0 END) AS waiting_retry
        FROM crm_marketing_send_queue
        WHERE task_id = ?");
    $stmt->execute([$taskId]);
    $queue = $stmt->fetch() ?: [];

    $emailNoReceiverExpr = "TRIM(COALESCE(mt.contact_method, ct.email, c.email, c.backup_email, '')) = ''";
    $customerHasAnyEmailExpr = "(
        TRIM(COALESCE(c.email, '')) <> ''
        OR TRIM(COALESCE(c.backup_email, '')) <> ''
        OR EXISTS (
            SELECT 1
            FROM crm_contacts any_ct
            WHERE any_ct.customer_id = mt.customer_id
              AND any_ct.deleted_at IS NULL
              AND COALESCE(any_ct.is_left, 0) = 0
              AND TRIM(COALESCE(any_ct.email, '')) <> ''
            LIMIT 1
        )
    )";
    $stmt = db()->prepare("SELECT
            SUM(CASE WHEN {$emailNoReceiverExpr} THEN 1 ELSE 0 END) AS no_receiver_current_targets,
            COUNT(DISTINCT CASE WHEN {$emailNoReceiverExpr} THEN mt.customer_id END) AS no_receiver_current_customers,
            SUM(CASE WHEN c.id IS NULL THEN 1 ELSE 0 END) AS orphan_target_rows,
            COUNT(DISTINCT CASE WHEN c.id IS NULL THEN mt.customer_id END) AS orphan_customers,
            COUNT(DISTINCT CASE WHEN c.id IS NOT NULL THEN mt.customer_id END) AS active_target_customers,
            COUNT(DISTINCT CASE WHEN c.id IS NOT NULL AND {$customerHasAnyEmailExpr} THEN mt.customer_id END) AS customers_with_any_email,
            COUNT(DISTINCT CASE WHEN c.id IS NOT NULL AND NOT {$customerHasAnyEmailExpr} THEN mt.customer_id END) AS true_no_email_customers,
            COUNT(DISTINCT CASE WHEN {$emailNoReceiverExpr} AND {$customerHasAnyEmailExpr} THEN mt.customer_id END) AS empty_target_but_customer_has_other_email
        FROM crm_marketing_task_targets mt
        LEFT JOIN crm_customers c ON c.id = mt.customer_id AND c.deleted_at IS NULL
        LEFT JOIN crm_contacts ct ON ct.id = mt.contact_id AND ct.deleted_at IS NULL
        WHERE mt.task_id = ? AND {$emailSql}");
    $stmt->execute([$taskId]);
    $emailQuality = $stmt->fetch() ?: [];

    $stmt = db()->prepare("SELECT COUNT(*) AS duplicate_email_groups, SUM(row_count) AS duplicate_email_rows, SUM(row_count - 1) AS duplicate_email_extra_rows
        FROM (
            SELECT LOWER(TRIM(COALESCE(mt.contact_method, ct.email, ''))) AS receiver_email, COUNT(*) AS row_count
            FROM crm_marketing_task_targets mt
            LEFT JOIN crm_contacts ct ON ct.id = mt.contact_id AND ct.deleted_at IS NULL
            WHERE mt.task_id = ?
              AND {$emailSql}
              AND TRIM(COALESCE(mt.contact_method, ct.email, '')) <> ''
            GROUP BY LOWER(TRIM(COALESCE(mt.contact_method, ct.email, '')))
            HAVING COUNT(*) > 1
        ) dup");
    $stmt->execute([$taskId]);
    $duplicates = $stmt->fetch() ?: [];

    $manualDirectPending = (int)($row['manual_direct_pending'] ?? 0);
    $emailFallbackManualPending = (int)($row['email_fallback_manual_pending'] ?? 0);
    $manualPending = $manualDirectPending + $emailFallbackManualPending;
    $manualTargetTotal = (int)($row['manual_channel_total'] ?? 0) + $emailFallbackManualPending;

    return [
        'total_targets' => (int)($row['total_targets'] ?? 0),
        'customers' => (int)($row['customers'] ?? 0),
        'contacts' => (int)($row['contacts'] ?? 0),
        'groups' => (int)($row['groups'] ?? 0),
        'by_channel' => $byChannel,
        'by_status' => $byStatus,
        'failure_reasons' => $failureReasons,
        'email' => [
            'total' => (int)($row['email_total'] ?? 0),
            'success' => (int)($row['email_success'] ?? 0),
            'failed' => (int)($row['email_failed'] ?? 0),
            'skipped' => (int)($row['email_skipped'] ?? 0),
            'pending' => (int)($row['email_pending'] ?? 0),
            'queue_total' => (int)($queue['queue_total'] ?? 0),
            'no_receiver_recorded' => (int)($row['email_no_receiver_recorded'] ?? 0),
            'no_receiver_customers_recorded' => (int)($row['email_no_receiver_customers_recorded'] ?? 0),
            'no_receiver_current_targets' => (int)($emailQuality['no_receiver_current_targets'] ?? 0),
            'no_receiver_current_customers' => (int)($emailQuality['no_receiver_current_customers'] ?? 0),
            'true_no_email_customers' => (int)($emailQuality['true_no_email_customers'] ?? 0),
            'empty_target_but_customer_has_other_email' => (int)($emailQuality['empty_target_but_customer_has_other_email'] ?? 0),
            'duplicate_skipped' => (int)($row['duplicate_email_skipped'] ?? 0),
            'duplicate_current_groups' => (int)($duplicates['duplicate_email_groups'] ?? 0),
            'duplicate_current_rows' => (int)($duplicates['duplicate_email_rows'] ?? 0),
            'duplicate_current_extra_rows' => (int)($duplicates['duplicate_email_extra_rows'] ?? 0),
            'orphan_target_rows' => (int)($emailQuality['orphan_target_rows'] ?? 0),
            'orphan_customers' => (int)($emailQuality['orphan_customers'] ?? 0),
            'active_target_customers' => (int)($emailQuality['active_target_customers'] ?? 0),
            'customers_with_any_email' => (int)($emailQuality['customers_with_any_email'] ?? 0),
        ],
        'manual' => [
            'target_total' => $manualTargetTotal,
            'channel_total' => (int)($row['manual_channel_total'] ?? 0),
            'channel_success' => (int)($row['manual_success'] ?? 0),
            'direct_pending' => $manualDirectPending,
            'pending' => $manualPending,
            'email_fallback_total' => (int)($row['email_fallback_total'] ?? 0),
            'email_fallback_collapsed_by_group' => (int)($row['email_fallback_collapsed_by_group'] ?? 0),
            'email_fallback_pending' => $emailFallbackManualPending,
            'no_email_pending' => (int)($row['manual_no_email_pending'] ?? 0),
            'duplicate_pending' => (int)($row['manual_duplicate_pending'] ?? 0),
            'failed_pending' => (int)($row['manual_failed_pending'] ?? 0),
            'reasons' => $manualReasons,
        ],
        'queue' => [
            'total' => (int)($queue['queue_total'] ?? 0),
            'customers' => (int)($queue['queue_customers'] ?? 0),
            'unique_receivers' => (int)($queue['queue_unique_receivers'] ?? 0),
            'sent' => (int)($queue['sent'] ?? 0),
            'failed' => (int)($queue['failed'] ?? 0),
            'pending' => (int)($queue['pending'] ?? 0),
            'waiting_retry' => (int)($queue['waiting_retry'] ?? 0),
        ],
    ];
}

function crm_marketing_task_report(array $input = []): array
{
    crm_marketing_ensure_tables();
    crm_require('promotion.view');
    $taskId = (int)($input['task_id'] ?? 0);
    if ($taskId <= 0) {
        throw new Exception('推广任务 ID 无效');
    }
    return [
        'targets' => crm_marketing_task_targets(['task_id' => $taskId]),
        'logs' => crm_marketing_logs(['task_id' => $taskId]),
        'feedback_logs' => crm_marketing_feedback_list(['task_id' => $taskId, 'limit' => 300])['rows'],
        'feedback_summary' => crm_marketing_feedback_summary($taskId),
        'execution_summary' => crm_marketing_task_execution_summary($taskId),
        'target_summary' => crm_marketing_task_target_summary($taskId),
    ];
}

function crm_marketing_is_manual_channel(string $channel): bool
{
    return in_array(crm_marketing_normalize_channel($channel), ['wechat', 'wechat_group', 'whatsapp', 'whatsapp_group', 'phone', 'offline', 'linkedin'], true);
}

function crm_marketing_pick_first(array $row, array $fields): string
{
    foreach ($fields as $field) {
        $value = trim((string)($row[$field] ?? ''));
        if ($value !== '') return $value;
    }
    return '';
}

function crm_marketing_manual_target_meta(string $channel, array $customer = [], array $contact = [], array $group = []): array
{
    $channel = crm_marketing_normalize_channel($channel);
    $method = '';
    $groupName = '';
    if ($channel === 'phone') {
        $method = crm_marketing_pick_first($contact, ['phone', 'mobile']);
        if ($method === '') $method = crm_marketing_pick_first($customer, ['phone', 'mobile']);
    } elseif ($channel === 'wechat') {
        $method = crm_marketing_pick_first($contact, ['wechat']);
        if ($method === '') $method = crm_marketing_pick_first($customer, ['wechat']);
    } elseif ($channel === 'whatsapp') {
        $method = crm_marketing_pick_first($contact, ['whatsapp']);
        if ($method === '') $method = crm_marketing_pick_first($customer, ['whatsapp']);
    } elseif ($channel === 'linkedin') {
        $method = crm_marketing_pick_first($contact, ['linkedin']);
        if ($method === '') $method = crm_marketing_pick_first($customer, ['linkedin', 'website']);
    } elseif ($channel === 'wechat_group' || $channel === 'whatsapp_group') {
        $groupName = crm_marketing_pick_first($group, ['group_name']);
        $method = $groupName;
    } elseif ($channel === 'offline') {
        $method = crm_marketing_pick_first($contact, ['phone', 'whatsapp', 'wechat', 'email']);
        if ($method === '') $method = crm_marketing_pick_first($customer, ['phone', 'whatsapp', 'wechat', 'email', 'address']);
    }
    $missing = $method === '';
    return [
        'contact_method' => $method,
        'manual_group_name' => $groupName,
        'target_status' => $missing ? 'failed' : 'pending',
        'failure_reason' => $missing ? (in_array($channel, ['wechat_group', 'whatsapp_group'], true) ? '缺少可推广客户群' : '缺少联系方式') : '',
    ];
}

function crm_marketing_manual_schedule(?string $scheduledAt = null): array
{
    $planned = $scheduledAt ? str_replace('T', ' ', $scheduledAt) : date('Y-m-d H:i:s');
    $ts = strtotime($planned) ?: time();
    return [$planned, date('Y-m-d H:i:s', $ts + 86400)];
}

function crm_marketing_reconcile_group_targets(int $taskId): void
{
    if ($taskId <= 0) return;
    $taskStmt = db()->prepare('SELECT channel_key FROM crm_marketing_tasks WHERE id = ? LIMIT 1');
    $taskStmt->execute([$taskId]);
    $taskChannel = crm_marketing_normalize_channel((string)$taskStmt->fetchColumn());
    if (!in_array($taskChannel, ['wechat_group', 'whatsapp_group'], true)) return;

    $targetStmt = db()->prepare("SELECT mt.*, c.owner_user_id, c.email, c.phone, c.whatsapp, c.address
        FROM crm_marketing_task_targets mt
        JOIN crm_customers c ON c.id = mt.customer_id AND c.deleted_at IS NULL
        WHERE mt.task_id = ? AND mt.contact_id IS NULL AND mt.chat_group_id IS NULL AND LOWER(mt.channel_key) = ?");
    $targetStmt->execute([$taskId, $taskChannel]);
    $placeholderTargets = $targetStmt->fetchAll();
    if (!$placeholderTargets) return;

    $groupStmt = db()->prepare('SELECT g.id, g.customer_id, g.group_name, g.group_platform, c.owner_user_id, c.email, c.phone, c.whatsapp, c.address
        FROM crm_customer_chat_groups g
        JOIN crm_customers c ON c.id = g.customer_id
        WHERE g.customer_id = ? AND g.group_platform = ? AND g.deleted_at IS NULL AND g.status = "active" AND g.use_for_promotion = 1 AND c.deleted_at IS NULL
        ORDER BY g.updated_at DESC, g.id DESC');
    $existsStmt = db()->prepare('SELECT id FROM crm_marketing_task_targets WHERE task_id = ? AND chat_group_id = ? LIMIT 1');
    $insertStmt = db()->prepare('INSERT INTO crm_marketing_task_targets (task_id, customer_id, contact_id, chat_group_id, channel_key, contact_method, manual_group_name, executor_user_id, planned_at, due_at, target_status, failure_reason, executed_at, manual_result, created_at) VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
    $deleteStmt = db()->prepare("DELETE FROM crm_marketing_task_targets WHERE id = ? AND target_status IN ('pending','failed') AND executed_at IS NULL");
    $missingStmt = db()->prepare("UPDATE crm_marketing_task_targets SET target_status = 'failed', failure_reason = '缺少可推广客户群' WHERE id = ? AND target_status IN ('pending','failed') AND (failure_reason IS NULL OR failure_reason = '' OR failure_reason = '缺少联系方式')");

    foreach ($placeholderTargets as $target) {
        $groupStmt->execute([(int)$target['customer_id'], $taskChannel]);
        $groups = $groupStmt->fetchAll();
        if (!$groups) {
            $missingStmt->execute([(int)$target['id']]);
            continue;
        }
        foreach ($groups as $group) {
            $groupId = (int)$group['id'];
            $existsStmt->execute([$taskId, $groupId]);
            if ((int)$existsStmt->fetchColumn() > 0) continue;
            $meta = crm_marketing_manual_target_meta($taskChannel, $group, [], $group);
            $status = in_array((string)$target['target_status'], ['pending', 'failed', 'success'], true) ? (string)$target['target_status'] : 'pending';
            $failureReason = $status === 'failed' ? ((string)($target['failure_reason'] ?? '') ?: $meta['failure_reason']) : '';
            $insertStmt->execute([
                $taskId,
                (int)$target['customer_id'],
                $groupId,
                $taskChannel,
                $meta['contact_method'] ?: null,
                $meta['manual_group_name'] ?: null,
                (int)($target['executor_user_id'] ?? 0) ?: (int)($group['owner_user_id'] ?? 0) ?: null,
                $target['planned_at'] ?? null,
                $target['due_at'] ?? null,
                $status,
                $failureReason ?: null,
                $target['executed_at'] ?? null,
                $target['manual_result'] ?? null,
            ]);
        }
        $deleteStmt->execute([(int)$target['id']]);
    }

    $countStmt = db()->prepare('SELECT COUNT(DISTINCT customer_id) AS customers, COUNT(DISTINCT contact_id) AS contacts, COUNT(DISTINCT chat_group_id) AS groups FROM crm_marketing_task_targets WHERE task_id = ?');
    $countStmt->execute([$taskId]);
    $counts = $countStmt->fetch() ?: [];
    db()->prepare('UPDATE crm_marketing_tasks SET customer_count = ?, contact_count = ?, updated_at = NOW() WHERE id = ?')
        ->execute([(int)($counts['customers'] ?? 0), (int)($counts['contacts'] ?? 0) + (int)($counts['groups'] ?? 0), $taskId]);
}

function crm_marketing_reconcile_email_group_followups(int $taskId): void
{
    if ($taskId <= 0) return;
    $taskStmt = db()->prepare('SELECT channel_key, scheduled_at FROM crm_marketing_tasks WHERE id = ? LIMIT 1');
    $taskStmt->execute([$taskId]);
    $task = $taskStmt->fetch();
    if (!$task || !crm_marketing_is_email_channel((string)($task['channel_key'] ?? ''))) return;

    $customerStmt = db()->prepare("SELECT DISTINCT mt.customer_id, c.owner_user_id, c.email, c.phone, c.whatsapp, c.address, COALESCE(mt.planned_at, ?) planned_at, COALESCE(mt.due_at, ?) due_at
        FROM crm_marketing_task_targets mt
        JOIN crm_customers c ON c.id = mt.customer_id AND c.deleted_at IS NULL
        LEFT JOIN crm_contacts ct ON ct.id = mt.contact_id
        WHERE mt.task_id = ?
          AND LOWER(mt.channel_key) IN ('email','mail','edm')
          AND mt.target_status IN ('skipped','failed')
          AND (mt.failure_reason LIKE '%邮箱为空%' OR COALESCE(NULLIF(ct.email, ''), NULLIF(c.email, ''), '') = '')");
    [$defaultPlannedAt, $defaultDueAt] = crm_marketing_manual_schedule((string)($task['scheduled_at'] ?? ''));
    $customerStmt->execute([$defaultPlannedAt, $defaultDueAt, $taskId]);
    $customers = $customerStmt->fetchAll();
    if (!$customers) return;

    $groupStmt = db()->prepare('SELECT g.id, g.customer_id, g.group_name, g.group_platform, c.owner_user_id, c.email, c.phone, c.whatsapp, c.address
        FROM crm_customer_chat_groups g
        JOIN crm_customers c ON c.id = g.customer_id
        WHERE g.customer_id = ? AND g.deleted_at IS NULL AND g.status = "active" AND g.use_for_promotion = 1 AND c.deleted_at IS NULL
        ORDER BY FIELD(g.group_platform, "wechat_group", "whatsapp_group"), g.updated_at DESC, g.id DESC');
    $existsStmt = db()->prepare('SELECT id FROM crm_marketing_task_targets WHERE task_id = ? AND chat_group_id = ? LIMIT 1');
    $insertStmt = db()->prepare('INSERT INTO crm_marketing_task_targets (task_id, customer_id, contact_id, chat_group_id, channel_key, contact_method, manual_group_name, executor_user_id, planned_at, due_at, target_status, failure_reason, created_at) VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, "pending", "邮件无收件邮箱，转群人工执行", NOW())');
    $inserted = 0;

    foreach ($customers as $customer) {
        $groupStmt->execute([(int)$customer['customer_id']]);
        foreach ($groupStmt->fetchAll() as $group) {
            $groupId = (int)$group['id'];
            $existsStmt->execute([$taskId, $groupId]);
            if ((int)$existsStmt->fetchColumn() > 0) continue;
            $meta = crm_marketing_manual_target_meta((string)$group['group_platform'], $group, [], $group);
            if ($meta['contact_method'] === '') continue;
            $insertStmt->execute([
                $taskId,
                (int)$customer['customer_id'],
                $groupId,
                (string)$group['group_platform'],
                $meta['contact_method'],
                $meta['manual_group_name'],
                (int)($customer['owner_user_id'] ?? 0) ?: (int)($group['owner_user_id'] ?? 0) ?: null,
                $customer['planned_at'] ?: $defaultPlannedAt,
                $customer['due_at'] ?: $defaultDueAt,
            ]);
            $inserted++;
        }
    }

    if ($inserted > 0) {
        $countStmt = db()->prepare('SELECT COUNT(DISTINCT customer_id) AS customers, COUNT(DISTINCT contact_id) AS contacts, COUNT(DISTINCT chat_group_id) AS groups FROM crm_marketing_task_targets WHERE task_id = ?');
        $countStmt->execute([$taskId]);
        $counts = $countStmt->fetch() ?: [];
        db()->prepare('UPDATE crm_marketing_tasks SET customer_count = ?, contact_count = ?, updated_at = NOW() WHERE id = ?')
            ->execute([(int)($counts['customers'] ?? 0), (int)($counts['contacts'] ?? 0) + (int)($counts['groups'] ?? 0), $taskId]);
    }
}

function crm_marketing_analytics(): array
{
    crm_marketing_ensure_tables();
    crm_require('promotion.analytics');
    $statusRows = db()->query("SELECT COALESCE(ps.status, 'not_promoted') status, COUNT(*) total FROM crm_customers c LEFT JOIN crm_customer_promotion_status ps ON ps.customer_id = c.id WHERE c.deleted_at IS NULL GROUP BY COALESCE(ps.status, 'not_promoted')")->fetchAll();
    $channelRows = db()->query("SELECT channel_key, COUNT(*) total, SUM(result_status='success') success_count, SUM(result_status='failed') failed_count FROM crm_marketing_logs GROUP BY channel_key ORDER BY total DESC")->fetchAll();
    $countryRows = db()->query("SELECT COALESCE(NULLIF(c.country, ''), '未填') country, COUNT(*) total FROM crm_customers c WHERE c.deleted_at IS NULL GROUP BY COALESCE(NULLIF(c.country, ''), '未填') ORDER BY total DESC LIMIT 10")->fetchAll();
    $taskRows = db()->query("SELECT task_status, COUNT(*) total FROM crm_marketing_tasks GROUP BY task_status")->fetchAll();
    return ['status' => $statusRows, 'channels' => $channelRows, 'countries' => $countryRows, 'tasks' => $taskRows];
}

function crm_marketing_bootstrap(array $input = []): array
{
    $view = strtolower(trim((string)($input['view'] ?? $input['current_view'] ?? 'campaigns')));
    if ($view === 'pool' || $view === 'customer_pool') $view = 'customer_pool';
    if ($view === 'projects' || $view === 'campaign') $view = 'campaigns';
    if ($view === 'contacts') $view = 'contact_strategy';
    $heavyViews = [
        'customer_pool' => true,
        'contact_strategy' => true,
        'execution' => true,
        'analytics' => true,
        'dashboard' => true,
        'group_management' => true,
    ];
    $fallbackAnalytics = ['status' => [], 'channels' => [], 'countries' => [], 'tasks' => []];
    $channels = [];
    $pool = [];
    $contacts = [];
    $chatGroups = [];
    $tasks = [];
    $logs = [];
    $analytics = $fallbackAnalytics;
    try { $channels = crm_marketing_bootstrap_cached('channels', 'crm_marketing_channels'); } catch (Throwable $e) { error_log('crm_marketing_channels failed: ' . $e->getMessage()); }
    $poolPager = ['total' => 0, 'page' => 1, 'page_size' => 50, 'page_count' => 1];
    if ($view === 'customer_pool' || $view === 'group_management') {
        try {
            $poolInput = $input;
            if ($view === 'customer_pool') $poolInput['skip_count'] = 1;
            $poolResult = crm_marketing_pool($poolInput);
            $pool = $poolResult['rows'] ?? [];
            $poolPager = $poolResult;
            unset($poolPager['rows']);
        } catch (Throwable $e) { error_log('crm_marketing_pool failed: ' . $e->getMessage()); }
    }
    if ($view === 'contact_strategy') {
        try { $contacts = crm_marketing_contacts($input); } catch (Throwable $e) { error_log('crm_marketing_contacts failed: ' . $e->getMessage()); }
    }
    if ($view === 'customer_pool' || $view === 'contact_strategy' || $view === 'execution') {
        try { $chatGroups = crm_marketing_chat_groups($input); } catch (Throwable $e) { error_log('crm_marketing_chat_groups failed: ' . $e->getMessage()); }
    }
    try { $tasks = crm_marketing_tasks(); } catch (Throwable $e) { error_log('crm_marketing_tasks failed: ' . $e->getMessage()); }
    if ($view === 'execution' || $view === 'analytics' || $view === 'dashboard') {
        try { $logs = crm_marketing_logs($input); } catch (Throwable $e) { error_log('crm_marketing_logs failed: ' . $e->getMessage()); }
    }
    if (($view === 'analytics' || $view === 'dashboard') && crm_can('promotion.analytics')) {
        try { $analytics = crm_marketing_analytics(); } catch (Throwable $e) { error_log('crm_marketing_analytics failed: ' . $e->getMessage()); }
    }
    $targets = [];
    $failedTargets = [];
    if ($view === 'execution' || $view === 'analytics' || !empty($input['task_id'])) {
        try { $targets = crm_marketing_task_targets($input); } catch (Throwable $e) { error_log('crm_marketing_task_targets failed: ' . $e->getMessage()); }
        try { $failedTargets = crm_marketing_task_targets(['status' => 'failed']); } catch (Throwable $e) { error_log('crm_marketing_failed_targets failed: ' . $e->getMessage()); }
    }
    return [
        'loaded_view' => $view,
        'lazy_views' => array_keys($heavyViews),
        'channels' => $channels,
        'groups' => crm_marketing_bootstrap_cached('groups', 'crm_marketing_groups'),
        'templates' => crm_marketing_bootstrap_cached('templates', 'crm_marketing_templates'),
        'users' => crm_marketing_bootstrap_cached('users', 'crm_marketing_users'),
        'mail_accounts' => crm_marketing_bootstrap_cached('mail_accounts_meta_v2', 'crm_marketing_mail_accounts'),
        'company_signature' => crm_marketing_bootstrap_cached('company_signature_meta_v2', 'crm_marketing_company_signature'),
        'pool' => $pool,
        'pool_pager' => $poolPager,
        'contacts' => $contacts,
        'chat_groups' => $chatGroups,
        'tasks' => $tasks,
        'targets' => $targets,
        'logs' => $logs,
        'failed_targets' => $failedTargets,
        'analytics' => $analytics,
    ];
}

function crm_marketing_task_create(array $input): array
{
    crm_marketing_ensure_tables();
    // Warm the shared operation-log schema before opening the task transaction.
    // MySQL DDL would otherwise commit a first-use transaction implicitly.
    crm_ensure_tables();
    crm_require('promotion.task_create');
    $taskId = (int)($input['task_id'] ?? 0);
    $requestedStatus = trim((string)($input['task_status'] ?? 'pending'));
    $isDraft = $requestedStatus === 'draft';
    $name = trim((string)($input['task_name'] ?? ''));
    $channel = crm_marketing_normalize_channel((string)($input['channel_key'] ?? ''));
    $campaignType = trim((string)($input['campaign_type'] ?? 'email'));
    $subject = trim((string)($input['mail_subject'] ?? ''));
    $bodyHtml = trim((string)($input['mail_body_html'] ?? ''));
    $bodyHtml = crm_marketing_linkify_mail_html($bodyHtml);
    if ($name === '' && $isDraft) $name = '未命名推广草稿 ' . date('Y-m-d H:i');
    if ($channel === '' && $isDraft) $channel = 'draft';
    if ($name === '') throw new RuntimeException('任务名称不能为空。');
    if ($channel === '') throw new RuntimeException('请选择推广渠道。');
    $preferenceMode = in_array($channel, ['preference','customer_preference','auto_preference'], true);
    if (!$isDraft && !$preferenceMode && crm_marketing_is_email_channel($channel) && ($subject === '' || $bodyHtml === '')) {
        throw new RuntimeException('邮件推广必须填写邮件主题和正文。');
    }
    if (!$isDraft && $preferenceMode && $bodyHtml === '') {
        throw new RuntimeException('按客户偏好推广必须填写执行话术/邮件正文。');
    }
    $requestedCustomerIds = crm_mail_input_ids($input['customer_ids'] ?? '');
    $contactIds = crm_mail_input_ids($input['contact_ids'] ?? '');
    $chatGroupIds = crm_mail_input_ids($input['chat_group_ids'] ?? '');
    $audienceConfig = crm_marketing_decode_json_input($input['audience_config'] ?? []);
    $sendRule = crm_marketing_decode_json_input($input['send_rule'] ?? []);
    $deliveryV2 = (int)($sendRule['delivery_version'] ?? 0) === 2;
    if ($deliveryV2) {
        crm_delivery_ensure();
        if (!$isDraft) throw new RuntimeException('新版推广须先保存草稿，在最终预览中确认执行。');
    }
    $scheduleConfig = crm_marketing_decode_json_input($input['schedule_config'] ?? []);
    $failurePolicy = crm_marketing_decode_json_input($input['failure_policy'] ?? []);
    $attachmentConfig = crm_marketing_decode_json_input($input['attachment_config'] ?? []);
    $riskSummary = crm_marketing_decode_json_input($input['risk_summary'] ?? []);
    $audienceInput = array_merge(
        crm_marketing_decode_json_input($audienceConfig['pool_filters'] ?? []),
        [
            'group_mode' => $audienceConfig['group_mode'] ?? 'selected',
            'group_key' => $audienceConfig['group_key'] ?? '',
            'group_keys' => $audienceConfig['group_keys'] ?? [],
        ]
    );
    $resolvedAudience = crm_marketing_resolve_audience_customers($audienceInput, $requestedCustomerIds);
    $audiencePolicy = crm_marketing_apply_audience_policy(
        $resolvedAudience['rows'] ?? [],
        (string)($failurePolicy['blacklist_policy'] ?? 'skip')
    );
    $customerIds = $audiencePolicy['customer_ids'] ?? [];
    $audienceConfig['resolved_customer_count'] = count($customerIds);
    $audienceConfig['blocked_customer_count'] = count($audiencePolicy['blocked_customer_ids'] ?? []);
    if ($deliveryV2) {
        $blockedIds = $audiencePolicy['blocked_customer_ids'] ?? [];
        $audienceConfig['excluded_customers'] = array_values(array_map(static fn($r)=>['id'=>(int)$r['id'],'name'=>(string)$r['customer_name']],array_filter($resolvedAudience['rows'] ?? [],static fn($r)=>in_array((int)$r['id'],$blockedIds,true))));
    }
    if (!$isDraft && !$customerIds && !$contactIds && !$chatGroupIds) throw new RuntimeException('请至少选择客户、联系人或客户群。');
    $scheduledAt = trim((string)($input['scheduled_at'] ?? ''));
    if ($scheduledAt === '' && !empty($scheduleConfig['scheduled_at'])) $scheduledAt = (string)$scheduleConfig['scheduled_at'];
    $scheduleType = (string)($input['schedule_type'] ?? ($scheduleConfig['schedule_type'] ?? 'manual'));
    if (!$isDraft && ($requestedStatus === 'scheduled' || in_array($scheduleType, ['scheduled', 'auto'], true)) && $scheduledAt === '') {
        throw new RuntimeException('定时或自动执行必须设置开始时间。');
    }
    $allowedStatuses = ['draft', 'pending', 'scheduled', 'running', 'paused', 'partial_failed', 'completed', 'failed', 'cancelled', 'manual_pending'];
    $status = $isDraft ? 'draft' : (in_array($requestedStatus, $allowedStatuses, true) ? $requestedStatus : 'pending');
    $taskPayload = [
        $name,
        $channel,
        $campaignType ?: $channel,
        $subject ?: null,
        $bodyHtml ?: null,
        trim((string)($input['signature_key'] ?? '')) ?: null,
        json_encode($attachmentConfig, JSON_UNESCAPED_UNICODE),
        json_encode($audienceConfig, JSON_UNESCAPED_UNICODE),
        json_encode($sendRule, JSON_UNESCAPED_UNICODE),
        json_encode($scheduleConfig, JSON_UNESCAPED_UNICODE),
        json_encode($failurePolicy, JSON_UNESCAPED_UNICODE),
        json_encode($riskSummary, JSON_UNESCAPED_UNICODE),
        $status,
        $scheduleType,
        $scheduledAt ? str_replace('T', ' ', $scheduledAt) : null,
        (string)($input['remark'] ?? ''),
    ];
    $before = null;
    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
    $requestId = $deliveryV2 ? trim((string)($input['client_request_id'] ?? '')) : '';
    if ($deliveryV2 && !preg_match('/^[a-zA-Z0-9_-]{16,80}$/', $requestId)) throw new RuntimeException('创建标识无效，请重新打开创建窗口。');
    if ($deliveryV2) {
        $userId = (int)current_user()['id'];
        db()->prepare('INSERT IGNORE INTO crm_marketing_delivery_requests (request_id,user_id) VALUES (?,?)')->execute([$requestId,$userId]);
        $request = db()->prepare('SELECT task_id FROM crm_marketing_delivery_requests WHERE request_id=? AND user_id=? FOR UPDATE');
        $request->execute([$requestId,$userId]);
        $recordedId = (int)$request->fetchColumn();
        if ($recordedId && $taskId && $recordedId !== $taskId) throw new RuntimeException('草稿标识不匹配，请重新打开。');
        if ($recordedId) $taskId = $recordedId;
    }
    if ($taskId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM crm_marketing_tasks WHERE id=? LIMIT 1 FOR UPDATE');
        $stmt->execute([$taskId]);
        $before = $stmt->fetch() ?: null;
        if (!$before) throw new RuntimeException('推广项目已不存在，请刷新后重试。');
        if ($deliveryV2) crm_delivery_assert_owner($before);
        $status = crm_marketing_saved_task_status($before, $status);
        $taskPayload[12] = $status;
        crm_marketing_assert_targets_rebuildable($taskId);
        db()->prepare('UPDATE crm_marketing_tasks
            SET task_name = ?, channel_key = ?, campaign_type = ?, mail_subject = ?, mail_body_html = ?, signature_key = ?,
                attachment_config_json = ?, audience_config_json = ?, send_rule_json = ?, schedule_config_json = ?, failure_policy_json = ?, risk_summary_json = ?,
                task_status = ?, schedule_type = ?, scheduled_at = ?, remark = ?, assigned_to = ?, updated_at = NOW()
            WHERE id = ?')
            ->execute(array_merge($taskPayload, [current_user()['id'] ?? null, $taskId]));
        db()->prepare('DELETE FROM crm_marketing_task_targets WHERE task_id = ?')->execute([$taskId]);
    } else {
        $status = crm_marketing_saved_task_status(null, $status);
        $taskPayload[12] = $status;
        db()->prepare('INSERT INTO crm_marketing_tasks (
            task_name, channel_key, campaign_type, mail_subject, mail_body_html, signature_key,
            attachment_config_json, audience_config_json, send_rule_json, schedule_config_json, failure_policy_json, risk_summary_json,
            task_status, schedule_type, scheduled_at, remark, created_by, assigned_to, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())')
            ->execute(array_merge($taskPayload, [current_user()['id'] ?? null, current_user()['id'] ?? null]));
        $taskId = (int)db()->lastInsertId();
    }
    if ($deliveryV2) db()->prepare('UPDATE crm_marketing_delivery_requests SET task_id=? WHERE request_id=? AND user_id=?')->execute([$taskId,$requestId,(int)current_user()['id']]);
    [$manualPlannedAt, $manualDueAt] = crm_marketing_manual_schedule($scheduledAt ?: null);
    $insert = db()->prepare('INSERT INTO crm_marketing_task_targets (task_id, customer_id, contact_id, chat_group_id, channel_key, contact_method, manual_group_name, executor_user_id, planned_at, due_at, target_status, failure_reason, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
    $insertTarget = function (int $customerId, ?int $contactId, ?int $chatGroupId, string $targetChannel, array $customer = [], array $contact = [], array $group = []) use ($insert, $taskId, $manualPlannedAt, $manualDueAt, $deliveryV2, $channel): void {
        if ($deliveryV2) $targetChannel = crm_delivery_channel($channel, crm_delivery_channels($customerId,(int)$contactId));
        $targetChannel = crm_marketing_normalize_channel($targetChannel);
        $executorId = (int)($customer['owner_user_id'] ?? 0) ?: (int)(current_user()['id'] ?? 0) ?: null;
        if ($deliveryV2) $executorId = (int)($customer['owner_user_id'] ?? 0) ?: null;
        $contactMethod = '';
        $groupName = '';
        $targetStatus = 'pending';
        $failureReason = '';
        $plannedAt = null;
        $dueAt = null;
        if (crm_marketing_is_manual_channel($targetChannel)) {
            $meta = crm_marketing_manual_target_meta($targetChannel, $customer, $contact, $group);
            $contactMethod = $meta['contact_method'];
            $groupName = $meta['manual_group_name'];
            $targetStatus = $meta['target_status'];
            $failureReason = $meta['failure_reason'];
            $plannedAt = $manualPlannedAt;
            $dueAt = $manualDueAt;
        }
        $insert->execute([$taskId, $customerId, $contactId, $chatGroupId, $targetChannel, $contactMethod ?: null, $groupName ?: null, $executorId, $plannedAt, $dueAt, $targetStatus, $failureReason ?: null]);
    };
    $targetCount = 0;
    $targetCustomerIds = $customerIds;
    $insertedContactIds = [];
    $insertedChatGroupIds = [];
    $groupOnlyChannel = in_array($channel, ['wechat_group','whatsapp_group'], true);
    // 客户偏好模式不能直接混用前端提交的联系人/群目标；
    // 必须按每个客户的首选渠道在下面的 customer fallback 中重新展开。
    $channelAllowsContactTargets = !$groupOnlyChannel && (!$preferenceMode || $deliveryV2);
    $channelAllowsChatGroupTargets = $groupOnlyChannel;
    if (!$isDraft || $customerIds || $contactIds || $chatGroupIds) {
        foreach ($contactIds as $contactId) {
            if (!$channelAllowsContactTargets) continue;
            $stmt = db()->prepare('SELECT ct.*, c.owner_user_id, c.email AS customer_email, c.phone AS customer_phone, c.whatsapp AS customer_whatsapp, c.address AS customer_address
                FROM crm_contacts ct
                JOIN crm_customers c ON c.id = ct.customer_id
                WHERE ct.id = ? AND ct.deleted_at IS NULL AND c.deleted_at IS NULL');
            $stmt->execute([$contactId]);
            $contact = $stmt->fetch();
            $customerId = (int)($contact['customer_id'] ?? 0);
            if ($contact && $customerId > 0) {
                $customer = [
                    'owner_user_id' => $contact['owner_user_id'] ?? null,
                    'email' => $contact['customer_email'] ?? '',
                    'phone' => $contact['customer_phone'] ?? '',
                    'whatsapp' => $contact['customer_whatsapp'] ?? '',
                    'address' => $contact['customer_address'] ?? '',
                ];
                $targetChannel = crm_marketing_resolve_target_channel($channel, $customerId, $contactId);
                $insertTarget($customerId, $contactId, null, $targetChannel, $customer, $contact, []);
                $insertedContactIds[$contactId] = true;
                $targetCustomerIds[] = $customerId;
                $targetCount++;
            }
        }
        foreach ($chatGroupIds as $chatGroupId) {
            if (!$channelAllowsChatGroupTargets) continue;
            $stmt = db()->prepare('SELECT g.id, g.customer_id, g.group_name, g.group_platform, c.owner_user_id, c.email, c.phone, c.whatsapp, c.address
                FROM crm_customer_chat_groups g
                JOIN crm_customers c ON c.id = g.customer_id
                WHERE g.id = ? AND g.deleted_at IS NULL AND g.status = "active" AND g.use_for_promotion = 1 AND c.deleted_at IS NULL');
            $stmt->execute([$chatGroupId]);
            $group = $stmt->fetch();
            if ($group) {
                $customerId = (int)$group['customer_id'];
                $groupPlatform = crm_marketing_normalize_channel((string)($group['group_platform'] ?? ''));
                if ($groupOnlyChannel && $groupPlatform !== $channel) continue;
                $targetChannel = $groupOnlyChannel ? $channel : $groupPlatform;
                $insertTarget($customerId, null, (int)$group['id'], $targetChannel, $group, [], $group);
                $insertedChatGroupIds[(int)$group['id']] = true;
                $targetCustomerIds[] = $customerId;
                $targetCount++;
            }
        }
        foreach ($customerIds as $customerId) {
            if ($deliveryV2 && ($audienceConfig['contact_filter'] ?? '') === 'selected') continue;
            $stmt = db()->prepare('SELECT id, owner_user_id, email, phone, whatsapp, address FROM crm_customers WHERE id = ? AND deleted_at IS NULL');
            $stmt->execute([$customerId]);
            $customer = $stmt->fetch();
            if ($customer) {
                $targetChannel = crm_marketing_resolve_target_channel($channel, $customerId, null);
                if (in_array($targetChannel, ['wechat_group','whatsapp_group'], true)) {
                    $groupStmt = db()->prepare('SELECT g.id, g.customer_id, g.group_name, g.group_platform, c.owner_user_id, c.email, c.phone, c.whatsapp, c.address
                        FROM crm_customer_chat_groups g
                        JOIN crm_customers c ON c.id = g.customer_id
                        WHERE g.customer_id = ? AND g.group_platform = ? AND g.deleted_at IS NULL AND g.status = "active" AND g.use_for_promotion = 1 AND c.deleted_at IS NULL
                        ORDER BY g.updated_at DESC, g.id DESC');
                    $groupStmt->execute([$customerId, $targetChannel]);
                    $matchedGroups = 0;
                    foreach ($groupStmt->fetchAll() as $group) {
                        $matchedGroups++;
                        $groupId = (int)$group['id'];
                        if (isset($insertedChatGroupIds[$groupId])) continue;
                        $insertTarget($customerId, null, $groupId, $targetChannel, $group, [], $group);
                        $insertedChatGroupIds[$groupId] = true;
                        $targetCustomerIds[] = $customerId;
                        $targetCount++;
                    }
                    if ($matchedGroups > 0) {
                        continue;
                    }
                }
                if (crm_marketing_is_email_channel($targetChannel) || ($deliveryV2 && !$groupOnlyChannel)) {
                    $contactEligibilitySql = $deliveryV2 ? '' : " AND COALESCE(is_left,0)=0 AND COALESCE(do_not_contact,0)=0 AND COALESCE(unsubscribe_email,0)=0 AND COALESCE(email,'')<>''";
                    $contactStmt = db()->prepare("SELECT * FROM crm_contacts
                        WHERE customer_id = ? AND deleted_at IS NULL
                          {$contactEligibilitySql}
                        ORDER BY is_primary DESC, id DESC");
                    $contactStmt->execute([$customerId]);
                    $matchedContacts = 0;
                    $expanded = 0;
                    foreach ($contactStmt->fetchAll() as $contact) {
                        $contactId = (int)$contact['id'];
                        $matchedContacts++;
                        if ($deliveryV2 && ($audienceConfig['contact_filter'] ?? '') === 'primary' && empty($contact['is_primary'])) continue;
                        if (isset($insertedContactIds[$contactId])) continue;
                        $insertTarget($customerId, $contactId, null, $targetChannel, $customer, $contact, []);
                        $insertedContactIds[$contactId] = true;
                        $targetCount++;
                        $expanded++;
                    }
                    if ($matchedContacts > 0) {
                        continue;
                    }
                }
                $insertTarget($customerId, null, null, $targetChannel, $customer, [], []);
                $targetCount++;
            }
        }
    }
    db()->prepare('UPDATE crm_marketing_tasks SET customer_count = ?, contact_count = ?, updated_at = NOW() WHERE id = ?')
        ->execute([count(array_unique($targetCustomerIds)), count($insertedContactIds) + count($insertedChatGroupIds), $taskId]);
    crm_log_event('promotion', $before ? 'task_save' : 'task_create', 'marketing_task', (string)$taskId, $before, [
        'channel' => $channel,
        'campaign_type' => $campaignType,
        'task_status' => $status,
        'targets' => $targetCount,
        'subject' => $subject,
        'send_rule' => $sendRule,
        'schedule' => $scheduleConfig,
        'risk_summary' => $riskSummary,
    ]);
    if ($ownsTransaction) $pdo->commit();
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    $queue = null;
    if (!$isDraft && !empty($input['build_queue'])) {
        $queue = crm_marketing_queue_build(['task_id' => $taskId]);
        $status = (string)(crm_marketing_task_row($taskId)['task_status'] ?? $status);
    }
    return ['task_id' => $taskId, 'task_status' => $status, 'target_count' => $targetCount, 'queue' => $queue, 'tasks' => crm_marketing_tasks()];
}

function crm_marketing_normalize_channel(string $channel): string
{
    $value = strtolower(trim($channel));
    $map = [
        'email' => 'email', 'mail' => 'email', 'edm' => 'email', 'e-mail' => 'email', '邮件' => 'email', '邮箱' => 'email', '邮件推广' => 'email', 'edm推广' => 'email',
        'wechat_group' => 'wechat_group', 'weixin_group' => 'wechat_group', 'wx_group' => 'wechat_group', '微信群' => 'wechat_group', '微信客户群' => 'wechat_group',
        'whatsapp_group' => 'whatsapp_group', 'whatsapp群' => 'whatsapp_group', 'wa_group' => 'whatsapp_group',
        'wechat' => 'wechat', 'weixin' => 'wechat', 'wx' => 'wechat', '微信' => 'wechat', '微信线下' => 'wechat',
        'whatsapp' => 'whatsapp', 'whats app' => 'whatsapp',
        'linkedin' => 'linkedin',
        'phone' => 'phone', 'tel' => 'phone', 'call' => 'phone', '电话' => 'phone',
        'offline' => 'offline', 'visit' => 'offline', '线下' => 'offline', '拜访' => 'offline',
    ];
    return $map[$value] ?? $value;
}

function crm_marketing_resolve_target_channel(string $requestedChannel, int $customerId, ?int $contactId = null): string
{
    $requestedChannel = crm_marketing_normalize_channel($requestedChannel);
    if (!in_array($requestedChannel, ['preference','customer_preference','auto_preference'], true)) {
        return $requestedChannel !== '' ? $requestedChannel : 'email';
    }
    if ($contactId) {
        $stmt = db()->prepare("SELECT channel
            FROM crm_contact_promotions
            WHERE contact_id = ? AND status = 'active'
            ORDER BY CASE
                WHEN LOWER(channel) IN ('wechat','weixin','wx','微信','微信线下') THEN 10
                WHEN LOWER(channel) IN ('whatsapp','whats app') THEN 20
                WHEN LOWER(channel) IN ('linkedin') THEN 30
                WHEN LOWER(channel) IN ('phone','tel','call','电话') THEN 40
                WHEN LOWER(channel) IN ('offline','visit','线下','拜访') THEN 50
                WHEN LOWER(channel) IN ('email','mail','edm','e-mail','邮件','邮箱','邮件推广','edm推广') THEN 60
                ELSE 90
            END, id LIMIT 1");
        $stmt->execute([$contactId]);
        $channel = crm_marketing_normalize_channel((string)$stmt->fetchColumn());
        if ($channel !== '') return $channel;
    }
    if (function_exists('db_table_exists') && db_table_exists('crm_customer_promotion_channels')) {
        $stmt = db()->prepare("SELECT channel_key
            FROM crm_customer_promotion_channels
            WHERE customer_id = ?
            ORDER BY CASE
                WHEN LOWER(channel_key) IN ('wechat_group','weixin_group','wx_group','微信群','微信客户群') THEN 10
                WHEN LOWER(channel_key) IN ('whatsapp_group','whatsapp群','wa_group') THEN 20
                WHEN LOWER(channel_key) IN ('wechat','weixin','wx','微信','微信线下') THEN 30
                WHEN LOWER(channel_key) IN ('whatsapp','whats app') THEN 40
                WHEN LOWER(channel_key) IN ('linkedin') THEN 50
                WHEN LOWER(channel_key) IN ('phone','tel','call','电话') THEN 60
                WHEN LOWER(channel_key) IN ('offline','visit','线下','拜访') THEN 70
                WHEN LOWER(channel_key) IN ('email','mail','edm','e-mail','邮件','邮箱','邮件推广','edm推广') THEN 80
                ELSE 90
            END, id LIMIT 1");
        $stmt->execute([$customerId]);
        $channel = crm_marketing_normalize_channel((string)$stmt->fetchColumn());
        if ($channel !== '') return $channel;
    }
    return 'email';
}

function crm_marketing_task_copy(array $input): array
{
    crm_marketing_ensure_tables();
    crm_require('promotion.task_create');
    $taskId = (int)($input['task_id'] ?? 0);
    if ($taskId <= 0) throw new RuntimeException('请选择要复制的推广项目。');
    $stmt = db()->prepare('SELECT * FROM crm_marketing_tasks WHERE id = ? LIMIT 1');
    $stmt->execute([$taskId]);
    $source = $stmt->fetch();
    if (!$source) throw new RuntimeException('推广项目不存在。');
    $newName = crm_marketing_copy_name('crm_marketing_tasks', 'task_name', (string)$source['task_name']);
    db()->prepare('INSERT INTO crm_marketing_tasks (
        task_name, channel_key, campaign_type, mail_subject, mail_body_html, signature_key,
        attachment_config_json, audience_config_json, send_rule_json, schedule_config_json, failure_policy_json, risk_summary_json,
        task_status, schedule_type, scheduled_at, customer_count, contact_count, created_by, assigned_to, remark, created_at, updated_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "draft", ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())')
        ->execute([
            $newName,
            $source['channel_key'],
            $source['campaign_type'],
            $source['mail_subject'],
            $source['mail_body_html'],
            $source['signature_key'],
            $source['attachment_config_json'],
            $source['audience_config_json'],
            $source['send_rule_json'],
            $source['schedule_config_json'],
            $source['failure_policy_json'],
            $source['risk_summary_json'],
            $source['schedule_type'],
            $source['scheduled_at'],
            (int)($source['customer_count'] ?? 0),
            (int)($source['contact_count'] ?? 0),
            current_user()['id'] ?? null,
            current_user()['id'] ?? null,
            $source['remark'] ?? '',
        ]);
    $newId = (int)db()->lastInsertId();
    crm_log_event('promotion', 'task_copy', 'marketing_task', (string)$newId, ['source_id' => $taskId, 'source_name' => $source['task_name']], ['new_id' => $newId, 'new_name' => $newName, 'task_status' => 'draft']);
    return ['ok' => true, 'new_id' => $newId, 'new_name' => $newName, 'message' => '推广项目已复制为草稿', 'tasks' => crm_marketing_tasks()];
}

function crm_marketing_task_update(array $input): array
{
    crm_marketing_ensure_tables();
    crm_ensure_tables();
    crm_require('promotion.task_create');
    $taskId = (int)($input['task_id'] ?? 0);
    if ($taskId <= 0) throw new RuntimeException('请选择推广任务。');
    $updated = crm_marketing_with_task_lock($taskId, static function (array $before) use ($input, $taskId): array {
    $allowedStatus = ['draft','pending','scheduled','running','paused','partial_failed','completed','failed','cancelled','manual_pending'];
    $name = trim((string)($input['task_name'] ?? $before['task_name']));
    $channel = trim((string)($input['channel_key'] ?? $before['channel_key']));
    $status = trim((string)($input['task_status'] ?? $before['task_status']));
    $scheduleType = trim((string)($input['schedule_type'] ?? $before['schedule_type']));
    $scheduledAt = trim((string)($input['scheduled_at'] ?? ($before['scheduled_at'] ?? '')));
    $subject = array_key_exists('mail_subject', $input)
        ? trim((string)$input['mail_subject'])
        : (string)($before['mail_subject'] ?? '');
    $bodyHtml = array_key_exists('mail_body_html', $input)
        ? trim((string)$input['mail_body_html'])
        : (string)($before['mail_body_html'] ?? '');
    $bodyHtml = crm_marketing_linkify_mail_html($bodyHtml);
    $remark = trim((string)($input['remark'] ?? ($before['remark'] ?? '')));
    if ($name === '') throw new RuntimeException('任务名称不能为空。');
    if ($channel === '') throw new RuntimeException('请选择推广渠道。');
    if (!in_array($status, $allowedStatus, true)) $status = (string)$before['task_status'];
    $status = crm_marketing_saved_task_status($before, $status);
    if ($scheduleType === '') $scheduleType = 'manual';
    $scheduledValue = $scheduledAt !== '' ? str_replace('T', ' ', $scheduledAt) : null;

    db()->prepare('UPDATE crm_marketing_tasks
        SET task_name = ?, channel_key = ?, task_status = ?, schedule_type = ?, scheduled_at = ?, mail_subject = ?, mail_body_html = ?, remark = ?, updated_at = NOW()
        WHERE id = ?')
        ->execute([$name, $channel, $status, $scheduleType, $scheduledValue, $subject, $bodyHtml, $remark, $taskId]);

    $updated = array_merge($before, ['task_name' => $name, 'channel_key' => $channel, 'task_status' => $status,
        'schedule_type' => $scheduleType, 'scheduled_at' => $scheduledValue, 'mail_subject' => $subject, 'mail_body_html' => $bodyHtml, 'remark' => $remark]);
    crm_log_event('promotion', 'task_update', 'marketing_task', (string)$taskId, $before, $updated);
    return $updated;
    });
    $tasks = crm_marketing_tasks();
    $summary = [];
    foreach ($tasks as $row) {
        if ((int)($row['id'] ?? 0) === $taskId) {
            $summary = $row;
            break;
        }
    }
    return ['task' => $summary, 'tasks' => $tasks, 'logs' => crm_marketing_logs(['task_id' => $taskId])];
}

/** Saving content is not an alternate lifecycle/execute permission path. */
function crm_marketing_saved_task_status(?array $existing, string $requested): string
{
    $current = (string)($existing['task_status'] ?? 'draft');
    if ($existing === null || $current === 'draft') {
        if (!in_array($requested, ['draft','pending','scheduled'], true)) throw new RuntimeException('保存项目只能保存草稿或正式待执行项目，请使用专门的执行操作。');
        return $requested;
    }
    if ($requested !== $current) throw new RuntimeException('项目执行状态已变化或不能通过编辑修改，请刷新后使用暂停、继续或取消操作。');
    return $current;
}

/** Caller holds the parent lock; retain both queue and manual execution history. */
function crm_marketing_assert_targets_rebuildable(int $taskId): void
{
    $pdo = db();
    if (!$pdo->inTransaction()) throw new RuntimeException('执行目标保护必须在项目保存事务内检查。');
    $queued = $pdo->prepare('SELECT COUNT(*) FROM crm_marketing_send_queue WHERE task_id=?');
    $queued->execute([$taskId]);
    if ((int)$queued->fetchColumn() > 0) throw new RuntimeException('项目已有发送队列，不能重建执行目标；请复制为新项目。');
    $targets = $pdo->prepare('SELECT id,target_status,executed_at FROM crm_marketing_task_targets WHERE task_id=? FOR UPDATE');
    $targets->execute([$taskId]);
    foreach ($targets->fetchAll() as $target) {
        // A failed target with no execution timestamp can be initial validation
        // (e.g. no contact details), not a historical attempt.
        if (!empty($target['executed_at']) || in_array((string)$target['target_status'], ['success','handled','skipped','cancelled'], true)) {
            throw new RuntimeException('项目已有执行或处理记录，不能重建执行目标；请复制为新项目，原执行明细将保留。');
        }
    }
}

function crm_marketing_task_delete(array $input): array
{
    crm_marketing_ensure_tables();
    crm_require('promotion.delete_project');
    $ids = [];
    if (isset($input['task_ids'])) {
        $raw = $input['task_ids'];
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : preg_split('/[,\s]+/', $raw);
        }
        foreach ((array)$raw as $id) {
            $id = (int)$id;
            if ($id > 0) $ids[$id] = $id;
        }
    }
    $singleId = (int)($input['task_id'] ?? 0);
    if ($singleId > 0) $ids[$singleId] = $singleId;
    $ids = array_values($ids);
    if (!$ids) throw new RuntimeException('请选择要删除的推广任务。');
    if (count($ids) > 200) throw new RuntimeException('一次最多删除 200 个推广任务。');

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT * FROM crm_marketing_tasks WHERE id IN ({$placeholders}) ORDER BY id DESC");
    $stmt->execute($ids);
    $tasks = $stmt->fetchAll();
    if (!$tasks) throw new RuntimeException('推广任务不存在或已删除。');
    $foundIds = array_map('intval', array_column($tasks, 'id'));

    $foundPlaceholders = implode(',', array_fill(0, count($foundIds), '?'));
    $queueStmt = db()->prepare("SELECT COUNT(*) FROM crm_marketing_send_queue WHERE task_id IN ({$foundPlaceholders}) AND send_status IN ('sending')");
    $queueStmt->execute($foundIds);
    if ((int)$queueStmt->fetchColumn() > 0) throw new RuntimeException('存在正在发送中的推广任务，请稍后再删除。');

    db()->beginTransaction();
    try {
        $deletePlaceholders = implode(',', array_fill(0, count($foundIds), '?'));
        db()->prepare("DELETE FROM crm_marketing_send_queue WHERE task_id IN ({$deletePlaceholders})")->execute($foundIds);
        db()->prepare("DELETE FROM crm_marketing_task_targets WHERE task_id IN ({$deletePlaceholders})")->execute($foundIds);
        db()->prepare("DELETE FROM crm_marketing_logs WHERE task_id IN ({$deletePlaceholders})")->execute($foundIds);
        db()->prepare("DELETE FROM crm_marketing_tasks WHERE id IN ({$deletePlaceholders})")->execute($foundIds);
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }

    crm_log_event('promotion', 'task_delete', 'marketing_task', implode(',', $foundIds), $tasks, ['deleted_ids' => $foundIds, 'deleted_count' => count($foundIds)]);
    return ['deleted_ids' => $foundIds, 'deleted_count' => count($foundIds), 'tasks' => crm_marketing_tasks(), 'logs' => crm_marketing_logs()];
}

function crm_marketing_json(?string $json): array
{
    $data = json_decode((string)$json, true);
    return is_array($data) ? $data : [];
}

function crm_marketing_is_email_channel(string $channel): bool
{
    return crm_marketing_normalize_channel($channel) === 'email';
}

function crm_marketing_country_offset(?string $country): ?float
{
    $key = strtolower(trim((string)$country));
    $map = [
        'china' => 8, 'cn' => 8, '中国' => 8, 'hong kong' => 8, 'hk' => 8, '香港' => 8,
        'india' => 5.5, 'in' => 5.5, '印度' => 5.5, 'japan' => 9, 'jp' => 9, '日本' => 9,
        'uae' => 4, 'ae' => 4, 'dubai' => 4, 'united arab emirates' => 4, '阿联酋' => 4,
        'saudi arabia' => 3, 'ksa' => 3, '沙特' => 3, 'qatar' => 3, 'kuwait' => 3, 'oman' => 4,
        'germany' => 1, 'de' => 1, '德国' => 1, 'france' => 1, 'fr' => 1, '法国' => 1, 'italy' => 1, '意大利' => 1, 'spain' => 1, '西班牙' => 1,
        'poland' => 1, '波兰' => 1, 'norway' => 1, '挪威' => 1, 'hungary' => 1, '匈牙利' => 1, 'slovakia' => 1, '斯洛伐克' => 1, 'austria' => 1, '奥地利' => 1,
        'finland' => 2, '芬兰' => 2, 'bulgaria' => 2, '保加利亚' => 2, 'romania' => 2, '罗马尼亚' => 2, 'greece' => 2, '希腊' => 2,
        'russia' => 3, 'ru' => 3, '俄罗斯' => 3, 'turkey' => 3, '土耳其' => 3, 'ukraine' => 2, '乌克兰' => 2, 'kazakhstan' => 6, '哈萨克斯坦' => 6,
        'uk' => 0, 'united kingdom' => 0, 'gb' => 0, '英国' => 0,
        'usa' => -5, 'us' => -5, 'united states' => -5, 'america' => -5, '美国' => -5,
        'australia' => 10, 'au' => 10, '澳大利亚' => 10, 'new zealand' => 12,
        'singapore' => 8, '新加坡' => 8, 'malaysia' => 8, '马来西亚' => 8, 'thailand' => 7, '泰国' => 7, 'vietnam' => 7, '越南' => 7, 'indonesia' => 7, '印度尼西亚' => 7, '印尼' => 7, 'philippines' => 8, '菲律宾' => 8,
        'south africa' => 2, 'za' => 2, 'nigeria' => 1, 'egypt' => 2,
    ];
    if (array_key_exists($key, $map)) return (float)$map[$key];
    if (preg_match('/中东|middle east/u', $key)) return 4.0;
    if (preg_match('/欧洲|europe/u', $key)) return 1.0;
    if (preg_match('/东南亚|asia/u', $key)) return 8.0;
    return null;
}

function crm_marketing_plan_time(int $index, array $schedule, ?string $country): array
{
    $interval = max(1, (int)($schedule['send_interval_minutes'] ?? 3));
    $hourlyLimit = max(1, (int)($schedule['hourly_limit'] ?? 50));
    $dailyLimit = max(1, (int)($schedule['daily_limit'] ?? 200));
    $base = trim((string)($schedule['scheduled_at'] ?? ''));
    $baseTime = $base !== '' ? strtotime(str_replace('T', ' ', $base)) : time();
    if (!$baseTime) $baseTime = time();
    $dayOffset = intdiv($index, $dailyLimit);
    $withinDay = $index % $dailyLimit;
    $hourOffset = intdiv($withinDay, $hourlyLimit);
    $minuteOffset = ($withinDay % $hourlyLimit) * $interval;
    $serverTime = $baseTime + ($dayOffset * 86400) + ($hourOffset * 3600) + ($minuteOffset * 60);
    $offset = crm_marketing_country_offset($country);
    $localTime = $offset === null ? null : $serverTime + (int)(($offset - 8) * 3600);
    if (($schedule['timezone_rule'] ?? '') === 'business_hours' && $localTime !== null) {
        $hour = (int)date('G', $localTime);
        if ($hour < 9) {
            $localTime = strtotime(date('Y-m-d 09:00:00', $localTime));
            $serverTime = $localTime - (int)(($offset - 8) * 3600);
        } elseif ($hour > 17 || ($hour === 17 && (int)date('i', $localTime) > 30)) {
            $localTime = strtotime(date('Y-m-d 09:00:00', $localTime) . ' +1 day');
            $serverTime = $localTime - (int)(($offset - 8) * 3600);
        }
    }
    return [
        'server' => date('Y-m-d H:i:s', $serverTime),
        'customer' => $localTime ? date('Y-m-d H:i:s', $localTime) : null,
        'timezone' => $offset === null ? 'unknown' : ('UTC' . ($offset >= 0 ? '+' : '') . $offset),
    ];
}

function crm_marketing_render_queue_template(string $text, array $row, array $account): string
{
    $recipientName = (string)($row['contact_name'] ?: ($row['customer_name'] ?? ''));
    $senderName = (string)($account['sender_name'] ?: ($account['owner_name'] ?? $account['username'] ?? ''));
    $senderMobile = (string)($account['user_phone'] ?? $account['owner_phone'] ?? '');
    $senderPosition = (string)($account['user_position'] ?? $account['position'] ?? '');
    $senderEmail = (string)($account['email_address'] ?? '');
    $vars = [
        '{customer_name}' => $recipientName,
        '{contact_name}' => $recipientName,
        '{company_name}' => $recipientName,
        '{country}' => (string)($row['country'] ?? ''),
        '{mail_user_name}' => $senderName,
        '{mail_user_mobile}' => $senderMobile,
        '{mail_user_position}' => $senderPosition,
        '{send_email}' => $senderEmail,
        '{email}' => $senderEmail,
        '{mobile}' => $senderMobile,
        '{phone}' => $senderMobile,
        '{name}' => $recipientName,
        '{customer_full_name}' => $recipientName,
        '{user_name}' => $senderName,
        '{position}' => $senderPosition,
    ];
    return crm_marketing_linkify_mail_html(strtr($text, $vars));
}

function crm_marketing_linkify_mail_html(string $html): string
{
    $html = (string)$html;
    if ($html === '') return '';

    $styleAnchor = static function (string $tag): string {
        if (!preg_match('/\bhref\s*=\s*(["\'])(.*?)\1/i', $tag, $hrefMatch)) return $tag;
        $href = trim(html_entity_decode((string)$hrefMatch[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (preg_match('/^www\./i', $href)) $href = 'https://' . $href;
        if (!preg_match('/^(https?:\/\/|mailto:|tel:)/i', $href)) return $tag;
        $tag = preg_replace('/\bhref\s*=\s*(["\'])(.*?)\1/i', 'href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"', $tag, 1) ?? $tag;
        if (!preg_match('/\btarget\s*=/i', $tag)) {
            $tag = preg_replace('/<a\b/i', '<a target="_blank"', $tag, 1) ?? $tag;
        }
        if (!preg_match('/\brel\s*=/i', $tag)) {
            $tag = preg_replace('/<a\b/i', '<a rel="noopener noreferrer"', $tag, 1) ?? $tag;
        }
        if (preg_match('/\bstyle\s*=\s*(["\'])(.*?)\1/i', $tag, $styleMatch)) {
            $style = (string)$styleMatch[2];
            $trimmedStyle = trim($style);
            $styleSuffix = ($trimmedStyle === '' || substr($trimmedStyle, -1) === ';') ? '' : ';';
            if (!preg_match('/color\s*:/i', $style)) {
                $style .= $styleSuffix . 'color:#2563eb';
                $trimmedStyle = trim($style);
                $styleSuffix = ($trimmedStyle === '' || substr($trimmedStyle, -1) === ';') ? '' : ';';
            }
            if (!preg_match('/text-decoration\s*:/i', $style)) $style .= $styleSuffix . 'text-decoration:underline';
            $tag = preg_replace('/\bstyle\s*=\s*(["\'])(.*?)\1/i', 'style="' . htmlspecialchars($style, ENT_QUOTES, 'UTF-8') . '"', $tag, 1) ?? $tag;
        } else {
            $tag = preg_replace('/<a\b/i', '<a style="color:#2563eb;text-decoration:underline"', $tag, 1) ?? $tag;
        }
        return $tag;
    };

    $linkifyText = static function (string $text): string {
        return preg_replace_callback('/(https?:\/\/[^\s<>"\']+|www\.[^\s<>"\']+)/i', static function (array $match): string {
            $visible = (string)$match[0];
            $trailing = '';
            while ($visible !== '' && preg_match('/[).,;!?，。；！？）]$/u', $visible)) {
                $trailing = mb_substr($visible, -1, null, 'UTF-8') . $trailing;
                $visible = mb_substr($visible, 0, mb_strlen($visible, 'UTF-8') - 1, 'UTF-8');
            }
            $href = preg_match('/^www\./i', $visible) ? ('https://' . $visible) : $visible;
            if (!preg_match('/^https?:\/\//i', $href)) return $match[0];
            return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer" style="color:#2563eb;text-decoration:underline;">' . htmlspecialchars($visible, ENT_QUOTES, 'UTF-8') . '</a>' . htmlspecialchars($trailing, ENT_QUOTES, 'UTF-8');
        }, $text) ?? $text;
    };

    $parts = preg_split('/(<[^>]+>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (!is_array($parts)) return $html;
    $insideAnchor = false;
    $out = '';
    foreach ($parts as $part) {
        if ($part === '') continue;
        if ($part[0] === '<') {
            if (preg_match('/^<a\b/i', $part)) {
                $insideAnchor = true;
                $part = $styleAnchor($part);
            } elseif (preg_match('/^<\/a\b/i', $part)) {
                $insideAnchor = false;
            }
            $out .= $part;
            continue;
        }
        $out .= $insideAnchor ? $part : $linkifyText($part);
    }
    return $out;
}

function crm_marketing_queue_body_store(int $taskId, string $bodyHtml): int
{
    $bodyHtml = crm_marketing_linkify_mail_html((string)$bodyHtml);
    if ($taskId <= 0 || $bodyHtml === '') return 0;
    $hash = hash('sha256', $bodyHtml);
    db()->prepare('INSERT INTO crm_marketing_queue_bodies (task_id, body_hash, body_html, body_bytes, created_at, updated_at)
        VALUES (?, ?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id), body_html=VALUES(body_html), body_bytes=VALUES(body_bytes), updated_at=NOW()')
        ->execute([$taskId, $hash, $bodyHtml, strlen($bodyHtml)]);
    return (int)db()->lastInsertId();
}

function crm_marketing_compact_queue_bodies(int $taskId = 0, int $limit = 500): array
{
    crm_marketing_ensure_tables();
    $limit = max(1, min(2000, $limit));
    $where = ["(COALESCE(q.body, '') <> '' OR (q.send_status = 'sent' AND q.body_ref_id IS NOT NULL AND COALESCE(t.mail_body_html, '') <> ''))"];
    $params = [];
    if ($taskId > 0) {
        $where[] = 'q.task_id = ?';
        $params[] = $taskId;
    }
    $stmt = db()->prepare('SELECT q.id, q.task_id, q.send_status, q.body, t.mail_body_html
        FROM crm_marketing_send_queue q
        LEFT JOIN crm_marketing_tasks t ON t.id = q.task_id
        WHERE ' . implode(' AND ', $where) . " ORDER BY q.id ASC LIMIT {$limit}");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $update = db()->prepare("UPDATE crm_marketing_send_queue SET body_ref_id = ?, body = '', updated_at = NOW() WHERE id = ?");
    $compacted = 0;
    $bytesBefore = 0;
    $bodyRefs = [];
    foreach ($rows as $row) {
        $body = (string)($row['body'] ?? '');
        $sentWithTaskBody = (string)($row['send_status'] ?? '') === 'sent' && trim((string)($row['mail_body_html'] ?? '')) !== '';
        if ($body === '' && !$sentWithTaskBody) continue;
        $bodyForStorage = $body;
        if ($sentWithTaskBody) {
            $bodyForStorage = (string)$row['mail_body_html'];
        }
        $bytesBefore += strlen($body);
        $bodyRefId = crm_marketing_queue_body_store((int)$row['task_id'], $bodyForStorage);
        if ($bodyRefId <= 0) continue;
        $update->execute([$bodyRefId, (int)$row['id']]);
        $compacted += $update->rowCount();
        $bodyRefs[$bodyRefId] = true;
    }
    $deleteWhere = ['NOT EXISTS (SELECT 1 FROM crm_marketing_send_queue q WHERE q.body_ref_id = crm_marketing_queue_bodies.id)'];
    $deleteParams = [];
    if ($taskId > 0) {
        $deleteWhere[] = 'task_id = ?';
        $deleteParams[] = $taskId;
    }
    $delete = db()->prepare('DELETE FROM crm_marketing_queue_bodies WHERE ' . implode(' AND ', $deleteWhere));
    $delete->execute($deleteParams);
    return [
        'scanned' => count($rows),
        'compacted' => $compacted,
        'body_refs' => count($bodyRefs),
        'bytes_before' => $bytesBefore,
        'orphan_bodies_deleted' => $delete->rowCount(),
    ];
}

function crm_marketing_task_row(int $taskId): array
{
    $stmt = db()->prepare('SELECT * FROM crm_marketing_tasks WHERE id = ? LIMIT 1');
    $stmt->execute([$taskId]);
    $task = $stmt->fetch();
    if (!$task) throw new RuntimeException('推广任务不存在。');
    return $task;
}

function crm_marketing_queue_build(array $input): array
{
    crm_marketing_ensure_tables();
    crm_mail_ensure_tables();
    crm_ensure_tables();
    crm_require('promotion.task_create');
    $taskId = (int)($input['task_id'] ?? 0);
    if ($taskId <= 0) throw new RuntimeException('请选择推广任务。');
    $result = crm_marketing_with_task_lock($taskId, static function (array $task) use ($input): array {
        crm_marketing_assert_task_executable($task);
        return crm_marketing_queue_build_locked($input, $task);
    });
    crm_marketing_notify_queue_build($taskId, $result);
    return $result;
}

/** Serialize queue building and lifecycle changes against worker claims. */
function crm_marketing_with_task_lock(int $taskId, callable $operation): array
{
    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM crm_marketing_tasks WHERE id=? LIMIT 1 FOR UPDATE');
        $stmt->execute([$taskId]);
        $task = $stmt->fetch();
        if (!$task) throw new RuntimeException('推广任务不存在。');
        $result = $operation($task);
        if ($ownsTransaction) $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function crm_marketing_assert_task_executable(array $task): void
{
    $status = (string)($task['task_status'] ?? '');
    if ($status === 'draft') throw new RuntimeException('请先在推广向导中保存正式项目，再启动执行。');
    if ($status === 'paused') throw new RuntimeException('项目已暂停，请先点击继续项目。');
    if (in_array($status, ['cancelled','completed'], true)) throw new RuntimeException('该项目已结束，请复制为新项目后再执行。');
    if (!in_array($status, ['pending','scheduled','running','partial_failed','failed','manual_pending'], true)) {
        throw new RuntimeException('当前项目状态不能启动执行。');
    }
}

/** Caller holds the parent task lock and has checked its own action permission. */
function crm_marketing_queue_build_locked(array $input, array $task): array
{
    if (function_exists('crm_delivery_version') && crm_delivery_version($task)) throw new RuntimeException('请打开新版推广草稿，通过最终发送预览确认；旧入口不能直接启动。');
    $taskId = (int)$task['id'];
    $taskChannel = crm_marketing_normalize_channel((string)($task['channel_key'] ?? ''));
    $emailCapableTaskChannels = ['email', 'preference', 'customer_preference', 'auto_preference'];
    if ($taskChannel !== '' && !in_array($taskChannel, $emailCapableTaskChannels, true)) {
        db()->prepare("UPDATE crm_marketing_send_queue
            SET send_status = 'cancelled', failure_reason = COALESCE(NULLIF(failure_reason, ''), '非邮件渠道任务，禁止生成邮件队列'), updated_at = NOW()
            WHERE task_id = ? AND send_status IN ('pending','scheduled','waiting_retry')")
            ->execute([$taskId]);
        db()->prepare("UPDATE crm_marketing_tasks SET task_status = 'manual_pending', updated_at = NOW() WHERE id = ?")->execute([$taskId]);
        crm_log_event('promotion', 'queue_block_non_email_channel', 'marketing_task', (string)$taskId, null, [
            'channel' => $taskChannel,
            'message' => '非邮件渠道任务禁止生成邮件发送队列',
        ]);
        return ['task_id' => $taskId, 'queue_count' => 0, 'skipped_count' => 0, 'error_count' => 0, 'first_planned_time' => null, 'last_planned_time' => null, 'message' => '当前任务是非邮件渠道，已禁止生成邮件队列'];
    }
    $subject = trim((string)($task['mail_subject'] ?? ''));
    $body = trim((string)($task['mail_body_html'] ?? ''));
    $sendRule = crm_marketing_json($task['send_rule_json'] ?? '');
    $schedule = crm_marketing_json($task['schedule_config_json'] ?? '');
    $failure = crm_marketing_json($task['failure_policy_json'] ?? '');
    $maxAttempts = max(1, (int)($failure['retry_count'] ?? 1) + 1);
    $suppressionSql = crm_marketing_email_suppression_sql('mt.contact_id');
    $targets = db()->prepare("SELECT mt.*, c.customer_name, c.country, c.owner_user_id, c.do_not_contact, COALESCE(ps.status, 'not_promoted') promotion_status,
            COALESCE(ct.name, '') contact_name, COALESCE(NULLIF(ct.email, ''), NULLIF(c.email, '')) receiver_email, COALESCE(ct.is_left, 0) is_left,
            ({$suppressionSql}) email_suppression_reason,
            COALESCE(owner.real_name, owner.username, '') owner_name
        FROM crm_marketing_task_targets mt
        JOIN crm_customers c ON c.id = mt.customer_id AND c.deleted_at IS NULL
        LEFT JOIN crm_customer_promotion_status ps ON ps.customer_id = c.id
        LEFT JOIN crm_contacts ct ON ct.id = mt.contact_id AND ct.deleted_at IS NULL
        LEFT JOIN crm_users owner ON owner.id = c.owner_user_id
        WHERE mt.task_id = ?
        ORDER BY mt.id");
    $targets->execute([$taskId]);
    $rows = $targets->fetchAll();
    if (!$rows) throw new RuntimeException('当前没有可执行客户，无法生成发送队列。');
    $emailTargetCount = 0;
    $emailAddressableCount = 0;
    $missingMailExecutorCount = 0;
    $missingOfflineExecutorCount = 0;
    $mailExecutorRule = (string)($sendRule['mail_executor_rule'] ?? $sendRule['executor_rule'] ?? 'owner');
    $offlineExecutorRule = (string)($sendRule['offline_executor_rule'] ?? $sendRule['executor_rule'] ?? 'owner');
    foreach ($rows as $targetRow) {
        $isEmailTarget = crm_marketing_is_email_channel((string)$targetRow['channel_key']);
        if ($isEmailTarget) {
            $emailTargetCount++;
            if (trim((string)($targetRow['receiver_email'] ?? '')) !== '') $emailAddressableCount++;
            if ($mailExecutorRule === 'owner' && (int)($targetRow['owner_user_id'] ?? 0) <= 0) $missingMailExecutorCount++;
        } elseif ($offlineExecutorRule === 'owner' && (int)($targetRow['owner_user_id'] ?? 0) <= 0) {
            $missingOfflineExecutorCount++;
        }
    }
    if ($emailTargetCount === 0) {
        db()->prepare("UPDATE crm_marketing_tasks SET task_status='manual_pending', updated_at=NOW() WHERE id=?")->execute([$taskId]);
        return ['task_id' => $taskId, 'queue_count' => 0, 'skipped_count' => count($rows), 'error_count' => 0, 'first_planned_time' => null, 'last_planned_time' => null, 'message' => '当前任务没有邮件渠道目标，已进入人工执行待处理'];
    }
    if ($subject === '') throw new RuntimeException('邮件主题为空，不能启动正式队列。');
    if ($body === '') throw new RuntimeException('邮件正文为空，不能启动正式队列。');
    if ($emailAddressableCount === 0) throw new RuntimeException('所有邮件客户都没有可用邮箱，不能启动正式队列。');
    if ($missingMailExecutorCount > 0 || $missingOfflineExecutorCount > 0) throw new RuntimeException('存在未分配执行人的客户，请先在推广向导中指定执行人或补充客户负责人。');
    if (in_array((string)($schedule['schedule_type'] ?? $task['schedule_type'] ?? ''), ['scheduled', 'auto'], true) && trim((string)($schedule['scheduled_at'] ?? $task['scheduled_at'] ?? '')) === '') {
        throw new RuntimeException('计划发送时间为空，不能启动正式队列。');
    }
    $allowedTemplateVars = ['customer_name', 'contact_name', 'company_name', 'mail_user_name', 'mail_user_position', 'send_email', 'mail_user_mobile', 'country', 'email', 'mobile', 'phone', 'name', 'customer_full_name', 'user_name', 'position'];
    if (preg_match_all('/\{([^}]+)\}/', $subject . ' ' . $body, $matches)) {
        $unknownTemplateVars = array_values(array_unique(array_filter(array_map('trim', $matches[1]), static fn(string $name): bool => !in_array($name, $allowedTemplateVars, true))));
        if ($unknownTemplateVars) throw new RuntimeException('邮件中存在未识别变量：' . implode('、', array_map(static fn(string $name): string => '{' . $name . '}', $unknownTemplateVars)) . '。');
    }
    $accounts = db()->query("SELECT a.*, COALESCE(u.real_name, u.username, '') owner_name, u.username
        FROM crm_user_mail_accounts a
        LEFT JOIN crm_users u ON u.id = a.user_id
        WHERE a.deleted_at IS NULL AND a.is_enabled = 1
        ORDER BY a.is_default DESC, a.id DESC")->fetchAll();
    $accountsByUser = [];
    foreach ($accounts as $account) if (!isset($accountsByUser[(int)$account['user_id']])) $accountsByUser[(int)$account['user_id']] = $account;
    $selectedIds = array_filter(array_map('intval', $sendRule['mail_account_ids'] ?? []));
    $selectedAccounts = $selectedIds ? array_values(array_filter($accounts, fn($a) => in_array((int)$a['id'], $selectedIds, true))) : $accounts;
    $mailAccountRule = (string)($sendRule['mail_account_rule'] ?? 'owner_mailbox');
    if (!$accounts || (($mailAccountRule === 'selected_mailbox' || $mailAccountRule === 'balanced' || $mailAccountRule === 'group_by_country') && !$selectedAccounts)) {
        throw new RuntimeException('邮件渠道存在但可用发件邮箱为 0，请先配置可用邮箱。');
    }
    $bodyRefId = crm_marketing_queue_body_store($taskId, $body);
    if ($bodyRefId <= 0) throw new RuntimeException('邮件正文引用保存失败，不能启动正式队列。');
    $queueCount = 0; $skipped = 0; $errors = 0; $first = null; $last = null; $index = 0; $balanced = 0; $seenReceivers = [];
    foreach ($rows as $row) {
        $channel = crm_marketing_normalize_channel((string)$row['channel_key']);
        if (!crm_marketing_is_email_channel($channel)) { $skipped++; continue; }
        $receiver = trim((string)($row['receiver_email'] ?? ''));
        $receiverKey = crm_marketing_normalize_email($receiver);
        $skipReason = (string)($row['email_suppression_reason'] ?? '');
        if ($skipReason === '' && $receiver === '') $skipReason = '收件邮箱为空';
        elseif ($skipReason === '' && $receiverKey !== '' && isset($seenReceivers[$receiverKey])) $skipReason = '重复邮箱';
        if ($skipReason !== '') {
            $skipped++;
            db()->prepare("UPDATE crm_marketing_task_targets SET target_status='skipped', failure_reason=?, executed_at=NOW() WHERE id=? AND target_status <> 'success'")
                ->execute([$skipReason, (int)$row['id']]);
            db()->prepare('INSERT INTO crm_marketing_logs (task_id, customer_id, contact_id, channel_key, action_key, result_status, failure_reason, operator_id, detail_json, touched_at, created_at) VALUES (?, ?, ?, "email", "queue_skipped", "skipped", ?, ?, ?, NOW(), NOW())')
                ->execute([$taskId, (int)$row['customer_id'], (int)($row['contact_id'] ?? 0) ?: null, $skipReason, current_user()['id'] ?? null, json_encode(['receiver_email' => $receiver, 'duplicate_of' => $seenReceivers[$receiverKey] ?? null], JSON_UNESCAPED_UNICODE)]);
            continue;
        }
        if ($receiverKey !== '') $seenReceivers[$receiverKey] = ['customer_id' => (int)$row['customer_id'], 'contact_id' => (int)($row['contact_id'] ?? 0) ?: null];
        $account = null;
        $rule = (string)($sendRule['mail_account_rule'] ?? 'owner_mailbox');
        if ($rule === 'owner_mailbox' || $rule === 'owner_then_fallback') $account = $accountsByUser[(int)$row['owner_user_id']] ?? null;
        if (!$account && ($rule === 'selected_mailbox' || $rule === 'balanced' || $rule === 'group_by_country' || $rule === 'owner_then_fallback')) {
            $account = $selectedAccounts ? $selectedAccounts[$balanced % count($selectedAccounts)] : null;
            $balanced++;
        }
        if (!$account && $accounts) $account = $accounts[$balanced++ % count($accounts)];
        if (!$account || trim((string)$account['email_address']) === '') {
            $errors++;
            continue;
        }
        $planned = crm_marketing_plan_time($index++, $schedule, (string)($row['country'] ?? ''));
        $queueSubject = crm_marketing_render_queue_template($subject, $row, $account);
        $dup = db()->prepare('SELECT id FROM crm_marketing_send_queue WHERE task_id=? AND LOWER(receiver_email)=? LIMIT 1');
        $dup->execute([$taskId, $receiverKey]);
        if ($dup->fetchColumn()) {
            $skipped++;
            db()->prepare("UPDATE crm_marketing_task_targets SET target_status='skipped', failure_reason='重复邮箱', executed_at=NOW() WHERE id=? AND target_status <> 'success'")
                ->execute([(int)$row['id']]);
            db()->prepare('INSERT INTO crm_marketing_logs (task_id, customer_id, contact_id, channel_key, action_key, result_status, failure_reason, operator_id, detail_json, touched_at, created_at) VALUES (?, ?, ?, "email", "queue_skipped", "skipped", "重复邮箱", ?, ?, NOW(), NOW())')
                ->execute([$taskId, (int)$row['customer_id'], (int)($row['contact_id'] ?? 0) ?: null, current_user()['id'] ?? null, json_encode(['receiver_email' => $receiver, 'source' => 'existing_queue'], JSON_UNESCAPED_UNICODE)]);
            continue;
        }
        db()->prepare("INSERT INTO crm_marketing_send_queue
            (task_id, campaign_id, customer_id, contact_id, sender_user_id, sender_email, receiver_email, subject, body, body_ref_id, attachment_json, country, customer_timezone, planned_customer_time, planned_server_time, send_status, send_attempts, max_attempts, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, '', ?, ?, ?, ?, ?, ?, ?, 0, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE sender_user_id=VALUES(sender_user_id), sender_email=VALUES(sender_email), subject=VALUES(subject), body=VALUES(body), body_ref_id=VALUES(body_ref_id), attachment_json=VALUES(attachment_json), country=VALUES(country), customer_timezone=VALUES(customer_timezone), planned_customer_time=VALUES(planned_customer_time), planned_server_time=VALUES(planned_server_time), send_status=IF(send_status IN ('sent','sending'), send_status, VALUES(send_status)), max_attempts=VALUES(max_attempts), updated_at=NOW()")
            ->execute([$taskId, null, (int)$row['customer_id'], (int)($row['contact_id'] ?? 0) ?: null, (int)$account['user_id'], (string)$account['email_address'], $receiver, $queueSubject, $bodyRefId, (string)($task['attachment_config_json'] ?? '[]'), (string)($row['country'] ?? ''), $planned['timezone'], $planned['customer'], $planned['server'], strtotime($planned['server']) <= time() ? 'pending' : 'scheduled', $maxAttempts]);
        $queueCount++;
        $first = $first === null || $planned['server'] < $first ? $planned['server'] : $first;
        $last = $last === null || $planned['server'] > $last ? $planned['server'] : $last;
    }
    $status = $queueCount > 0 ? (($first && strtotime($first) > time()) ? 'scheduled' : 'running') : 'manual_pending';
    db()->prepare('UPDATE crm_marketing_tasks SET task_status=?, customer_count=customer_count, contact_count=contact_count, updated_at=NOW() WHERE id=?')->execute([$status, $taskId]);
    crm_log_event('promotion', 'queue_build', 'marketing_task', (string)$taskId, null, ['queue_count' => $queueCount, 'skipped_count' => $skipped, 'error_count' => $errors, 'first' => $first, 'last' => $last]);
    return ['task_id' => $taskId, 'queue_count' => $queueCount, 'skipped_count' => $skipped, 'error_count' => $errors, 'first_planned_time' => $first, 'last_planned_time' => $last, 'message' => $queueCount > 0 ? '邮件发送队列已生成' : '没有可入队邮件目标，任务进入人工处理'];
}

function crm_marketing_notify_queue_build(int $taskId, array $result): void
{
    // Notification schema maintenance may execute DDL; never release a queue
    // transaction implicitly. Notification failure must not hide committed work.
    if (db()->inTransaction() || !function_exists('create_system_notification') || (int)($result['queue_count'] ?? 0) <= 0) return;
    try {
        $task = crm_marketing_task_row($taskId);
        if (!empty($task['created_by'])) {
            create_system_notification((int)$task['created_by'], 'promotion_queue_build', '推广发送队列已生成',
                '任务 ' . (string)$task['task_name'] . ' 已生成 ' . (int)$result['queue_count'] . ' 条邮件队列。',
                ['source_module' => 'promotion', 'source_id' => $taskId]);
        }
    } catch (Throwable $e) {
        error_log('marketing queue notification failed: ' . $e->getMessage());
    }
}

function crm_marketing_queue_status_counts(int $taskId): array
{
    crm_marketing_ensure_tables();
    $stmt = db()->prepare("SELECT send_status, COUNT(*) total FROM crm_marketing_send_queue WHERE task_id=? GROUP BY send_status");
    $stmt->execute([$taskId]);
    $counts = ['pending'=>0,'scheduled'=>0,'sending'=>0,'sent'=>0,'failed'=>0,'skipped'=>0,'cancelled'=>0,'waiting_retry'=>0];
    foreach ($stmt->fetchAll() as $row) $counts[(string)$row['send_status']] = (int)$row['total'];
    $time = db()->prepare('SELECT MIN(planned_server_time) first_planned_time, MAX(planned_server_time) last_planned_time FROM crm_marketing_send_queue WHERE task_id=?');
    $time->execute([$taskId]);
    return $counts + ($time->fetch() ?: []);
}

function crm_marketing_update_target_from_queue(array $queueRow, string $status, string $failureReason = ''): void
{
    $taskId = (int)($queueRow['task_id'] ?? 0);
    $customerId = (int)($queueRow['customer_id'] ?? 0);
    if ($taskId <= 0 || $customerId <= 0) return;
    $contactId = (int)($queueRow['contact_id'] ?? 0);
    $params = [$status, $failureReason, $status, $taskId, $customerId];
    $contactWhere = 'mt.contact_id IS NULL';
    if ($contactId > 0) {
        $contactWhere = 'mt.contact_id = ?';
        $params[] = $contactId;
    }
    db()->prepare("UPDATE crm_marketing_task_targets mt
        SET mt.target_status = ?, mt.failure_reason = ?, mt.executed_at = IF(? IN ('success','failed','skipped'), COALESCE(mt.executed_at, NOW()), mt.executed_at)
        WHERE mt.task_id = ? AND mt.customer_id = ? AND {$contactWhere}
          AND LOWER(mt.channel_key) IN ('email','mail','edm')
          AND mt.target_status <> 'success'")
        ->execute($params);
}

function crm_marketing_reconcile_task_targets_from_queue(int $taskId): array
{
    crm_marketing_ensure_tables();
    if ($taskId <= 0) return ['success' => 0, 'failed' => 0, 'skipped' => 0];
    $success = db()->prepare("UPDATE crm_marketing_task_targets mt
        JOIN crm_marketing_send_queue q ON q.task_id = mt.task_id
            AND q.customer_id = mt.customer_id
            AND (q.contact_id <=> mt.contact_id)
        SET mt.target_status = 'success',
            mt.failure_reason = '',
            mt.executed_at = COALESCE(mt.executed_at, q.sent_at, q.updated_at, NOW())
        WHERE mt.task_id = ?
          AND q.send_status = 'sent'
          AND LOWER(mt.channel_key) IN ('email','mail','edm')
          AND mt.target_status <> 'success'");
    $success->execute([$taskId]);

    $failed = db()->prepare("UPDATE crm_marketing_task_targets mt
        JOIN crm_marketing_send_queue q ON q.task_id = mt.task_id
            AND q.customer_id = mt.customer_id
            AND (q.contact_id <=> mt.contact_id)
        SET mt.target_status = 'failed',
            mt.failure_reason = COALESCE(NULLIF(q.last_error, ''), '邮件发送失败'),
            mt.executed_at = COALESCE(mt.executed_at, q.updated_at, NOW())
        WHERE mt.task_id = ?
          AND q.send_status = 'failed'
          AND LOWER(mt.channel_key) IN ('email','mail','edm')
          AND mt.target_status NOT IN ('success','failed')");
    $failed->execute([$taskId]);

    $skipped = db()->prepare("UPDATE crm_marketing_task_targets mt
        JOIN crm_marketing_logs ml ON ml.task_id = mt.task_id
            AND ml.customer_id = mt.customer_id
            AND (ml.contact_id <=> mt.contact_id)
        SET mt.target_status = 'skipped',
            mt.failure_reason = COALESCE(NULLIF(ml.failure_reason, ''), '队列生成时跳过'),
            mt.executed_at = COALESCE(mt.executed_at, ml.touched_at, ml.created_at, NOW())
        WHERE mt.task_id = ?
          AND ml.action_key = 'queue_skipped'
          AND ml.result_status = 'skipped'
          AND LOWER(mt.channel_key) IN ('email','mail','edm')
          AND mt.target_status = 'pending'");
    $skipped->execute([$taskId]);

    return ['success' => $success->rowCount(), 'failed' => $failed->rowCount(), 'skipped' => $skipped->rowCount()];
}

function crm_marketing_queue_list(array $input): array
{
    crm_marketing_ensure_tables();
    crm_require('promotion.view');
    $taskId = (int)($input['task_id'] ?? 0);
    if ($taskId <= 0) throw new RuntimeException('请选择推广任务。');
    crm_marketing_reconcile_task_targets_from_queue($taskId);
    $status = trim((string)($input['status'] ?? ''));
    $unfinishedOnly = !empty($input['unfinished_only']);
    $limit = (int)($input['limit'] ?? 300);
    $limit = max(20, min(2000, $limit));
    $where = ['q.task_id = ?'];
    $params = [$taskId];
    if ($status !== '') {
        $where[] = 'q.send_status = ?';
        $params[] = $status;
    } elseif ($unfinishedOnly) {
        $where[] = "q.send_status IN ('pending','scheduled','sending','waiting_retry','failed')";
    }
    $stmt = db()->prepare('SELECT
            q.id, q.task_id, q.campaign_id,
            COALESCE(NULLIF(q.customer_id, 0), ce.id, 0) customer_id,
            COALESCE(NULLIF(q.contact_id, 0), cte.id, 0) contact_id,
            q.sender_user_id, q.sender_email, q.receiver_email, q.subject,
            q.country, q.customer_timezone, q.planned_customer_time, q.planned_server_time,
            q.send_status, q.send_attempts, q.max_attempts, q.last_error,
            q.sent_at, q.created_at, q.updated_at,
            COALESCE(c.customer_name, ce.customer_name) customer_name,
            COALESCE(ct.name, cte.name) contact_name
        FROM crm_marketing_send_queue q
        LEFT JOIN crm_customers c ON c.id = q.customer_id
        LEFT JOIN crm_contacts ct ON ct.id = q.contact_id
        LEFT JOIN crm_contacts cte ON cte.id = (
            SELECT ct2.id
            FROM crm_contacts ct2
            WHERE ct2.deleted_at IS NULL
              AND q.receiver_email <> \'\'
              AND LOWER(ct2.email) = LOWER(q.receiver_email)
            ORDER BY ct2.is_primary DESC, ct2.id DESC
            LIMIT 1
        )
        LEFT JOIN crm_customers ce ON ce.id = cte.customer_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY q.planned_server_time ASC, q.id ASC
        LIMIT ' . $limit);
    $stmt->execute($params);
    return ['rows' => $stmt->fetchAll(), 'status' => crm_marketing_queue_status_counts($taskId)];
}

function crm_marketing_queue_retry_failed(array $input): array
{
    crm_marketing_ensure_tables();
    crm_require('promotion.manage');
    $taskId = (int)($input['task_id'] ?? 0);
    if ($taskId <= 0) throw new RuntimeException('请选择推广任务。');
    $minutes = max(1, (int)($input['retry_minutes'] ?? 5));
    $stmt = db()->prepare("UPDATE crm_marketing_send_queue SET send_status='waiting_retry', planned_server_time=DATE_ADD(NOW(), INTERVAL {$minutes} MINUTE), last_error=NULL, updated_at=NOW() WHERE task_id=? AND send_status='failed'");
    $stmt->execute([$taskId]);
    crm_log_event('promotion', 'queue_retry_failed', 'marketing_task', (string)$taskId, null, ['affected' => $stmt->rowCount()]);
    return crm_marketing_queue_list(['task_id' => $taskId]);
}

function crm_marketing_queue_cancel(array $input): array
{
    crm_marketing_ensure_tables();
    crm_require('promotion.manage');
    $taskId = (int)($input['task_id'] ?? 0);
    if ($taskId <= 0) throw new RuntimeException('请选择推广任务。');
    $change = crm_marketing_change_task_status($taskId, 'cancelled');
    crm_log_event('promotion', 'queue_cancel', 'marketing_task', (string)$taskId, null, ['affected' => $change['cancelled_queue_count']]);
    return crm_marketing_queue_list(['task_id' => $taskId]) + $change;
}

function crm_marketing_queue_update_task_status(int $taskId): void
{
    $counts = crm_marketing_queue_status_counts($taskId);
    $active = ($counts['pending'] ?? 0) + ($counts['scheduled'] ?? 0) + ($counts['sending'] ?? 0) + ($counts['waiting_retry'] ?? 0);
    $failed = (int)($counts['failed'] ?? 0);
    $sent = (int)($counts['sent'] ?? 0);
    $targets = db()->prepare('SELECT channel_key,target_status FROM crm_marketing_task_targets WHERE task_id=?');
    $targets->execute([$taskId]);
    $manualPending = 0;
    foreach ($targets->fetchAll() as $target) {
        if (crm_marketing_is_email_channel((string)$target['channel_key'])) continue;
        if ((string)$target['target_status'] === 'success') $sent++;
        elseif ((string)$target['target_status'] === 'failed') $failed++;
        elseif (!in_array((string)$target['target_status'], ['skipped','cancelled','handled'], true)) $manualPending++;
    }
    $status = $active > 0 ? 'running' : ($manualPending > 0 ? 'manual_pending' : ($failed > 0 && $sent > 0 ? 'partial_failed' : ($failed > 0 ? 'failed' : 'completed')));
    db()->prepare("UPDATE crm_marketing_tasks SET task_status=CASE WHEN task_status IN ('paused','cancelled') THEN task_status ELSE ? END, success_count=?, failed_count=?, updated_at=NOW() WHERE id=?")
        ->execute([$status, $sent, $failed, $taskId]);
}

/** Shared current contact policy, used both while building and atomically claiming. */
function crm_marketing_email_suppression_sql(string $contactIdExpression): string
{
    if (!in_array($contactIdExpression, ['mt.contact_id','q.contact_id'], true)) throw new InvalidArgumentException('Invalid contact policy context');
    return "CASE
        WHEN c.id IS NULL OR c.deleted_at IS NOT NULL THEN '客户已删除'
        WHEN COALESCE(c.do_not_contact,0)=1 OR COALESCE(ps.status,'') IN ('blacklist','maintenance_only','stopped','no_promotion') THEN '客户禁止推广'
        WHEN COALESCE({$contactIdExpression},0)>0 AND (ct.id IS NULL OR ct.deleted_at IS NOT NULL OR ct.customer_id<>c.id) THEN '联系人已失效'
        WHEN COALESCE(ct.is_left,0)=1 THEN '联系人已离职'
        WHEN COALESCE(ct.do_not_contact,0)=1 OR COALESCE(ct.unsubscribe_email,0)=1 THEN '联系人禁止邮件推广'
        WHEN EXISTS (SELECT 1 FROM crm_contact_promotions cp WHERE cp.contact_id={$contactIdExpression}
            AND ((LOWER(cp.channel) IN ('email','mail','edm','e-mail','邮件','邮箱','邮件推广','edm推广') AND cp.status IN ('stopped','no_contact','paused'))
              OR (cp.channel IN ('no_promotion','maintenance_only') AND cp.status<>'no_contact'))) THEN '联系人渠道策略禁止邮件推广'
        ELSE '' END";
}

/** Resolve a now-prohibited queued recipient without consuming a send attempt. */
function crm_marketing_queue_skip_suppressed(int $queueId): bool
{
    $policy = crm_marketing_email_suppression_sql('q.contact_id');
    $stmt = db()->prepare("UPDATE crm_marketing_send_queue q
        INNER JOIN crm_marketing_tasks t ON t.id=q.task_id
        LEFT JOIN crm_customers c ON c.id=q.customer_id
        LEFT JOIN crm_contacts ct ON ct.id=q.contact_id
        LEFT JOIN crm_customer_promotion_status ps ON ps.customer_id=c.id
        SET q.send_status='skipped', q.last_error=({$policy}), q.updated_at=NOW()
        WHERE q.id=? AND q.send_status IN ('pending','scheduled','waiting_retry')
          AND t.task_status IN ('pending','scheduled','running','partial_failed','failed','manual_pending')
          AND ({$policy})<>''");
    $stmt->execute([$queueId]);
    return $stmt->rowCount() === 1;
}

/** The successful atomic claim is the boundary after which mail is in flight. */
function crm_marketing_queue_claim(int $queueId): bool
{
    $policy = crm_marketing_email_suppression_sql('q.contact_id');
    $lock = db()->prepare("UPDATE crm_marketing_send_queue q
        INNER JOIN crm_marketing_tasks t ON t.id=q.task_id
        LEFT JOIN crm_customers c ON c.id=q.customer_id
        LEFT JOIN crm_contacts ct ON ct.id=q.contact_id
        LEFT JOIN crm_customer_promotion_status ps ON ps.customer_id=c.id
        SET q.send_status='sending', q.send_attempts=q.send_attempts+1, q.updated_at=NOW()
        WHERE q.id=? AND q.send_status IN ('pending','scheduled','waiting_retry')
          AND q.planned_server_time<=NOW()
          AND t.task_status IN ('pending','scheduled','running','partial_failed','failed','manual_pending')
          AND ({$policy})=''");
    $lock->execute([$queueId]);
    return $lock->rowCount() === 1;
}

function crm_marketing_queue_run_due(int $limit = 30): array
{
    crm_marketing_ensure_tables();
    crm_mail_ensure_tables();
    $limit = max(1, min(200, $limit));
    $stmt = db()->prepare("SELECT q.*, qb.body_html AS queue_body_template, c.customer_name, COALESCE(ct.name, '') contact_name
        FROM crm_marketing_send_queue q
        INNER JOIN crm_marketing_tasks t ON t.id=q.task_id
        LEFT JOIN crm_marketing_queue_bodies qb ON qb.id = q.body_ref_id
        LEFT JOIN crm_customers c ON c.id = q.customer_id
        LEFT JOIN crm_contacts ct ON ct.id = q.contact_id
        WHERE q.send_status IN ('pending','scheduled','waiting_retry') AND q.planned_server_time <= NOW()
          AND t.task_status IN ('pending','scheduled','running','partial_failed','failed','manual_pending')
        ORDER BY q.planned_server_time ASC, q.id ASC LIMIT {$limit}");
    $stmt->execute();
    $rows = $stmt->fetchAll();
    $sent = 0; $failed = 0; $skipped = 0;
    foreach ($rows as $row) {
        if (crm_marketing_queue_skip_suppressed((int)$row['id'])) {
            crm_marketing_update_target_from_queue($row, 'skipped', '发送前复核：当前客户或联系人策略禁止邮件推广');
            crm_marketing_queue_update_task_status((int)$row['task_id']);
            $skipped++;
            continue;
        }
        if (!crm_marketing_queue_claim((int)$row['id'])) continue;
        $deliveryMeta = crm_marketing_json($row['attachment_json'] ?? '');
        $deliveryLease = false;
        try {
            $accountStmt = db()->prepare("SELECT a.*, COALESCE(u.real_name, u.username, '') owner_name, u.username, u.phone user_phone, u.position user_position
                FROM crm_user_mail_accounts a
                LEFT JOIN crm_users u ON u.id = a.user_id
                WHERE (a.email_address=? OR a.email_username=?) AND a.user_id=? AND a.deleted_at IS NULL AND a.is_enabled=1
                LIMIT 1");
            $accountStmt->execute([(string)$row['sender_email'], (string)$row['sender_email'], (int)$row['sender_user_id']]);
            $account = $accountStmt->fetch();
            if (!$account) throw new RuntimeException('发件邮箱不可用');
            if ((int)($deliveryMeta['delivery_version'] ?? 0) === 2) {
                if ((int)$account['id'] !== (int)$deliveryMeta['account_id']) throw new RuntimeException('发件账号与已确认预览不一致，已停止本次发送');
                $account['sender_name'] = (string)$deliveryMeta['sender_name'];
                crm_delivery_verify_recipient($row,$deliveryMeta);
                if (!crm_delivery_acquire_send($row,$deliveryMeta)) { $skipped++; continue; }
                $deliveryLease = true;
            }
            $account['mail_secret'] = crm_mail_decrypt($account['email_password_encrypted'] ?? '');
            unset($account['email_password_encrypted']);
            if ((string)$account['mail_secret'] === '') throw new RuntimeException('发件邮箱未配置 SMTP 密码');
            $bodyTemplate = trim((string)($row['queue_body_template'] ?? ''));
            $bodyHtml = $bodyTemplate !== '' ? crm_marketing_render_queue_template($bodyTemplate, $row, $account) : (string)$row['body'];
            if (trim($bodyHtml) === '') throw new RuntimeException('队列邮件正文为空');
            $prepared = crm_marketing_prepare_mail_inline_images($bodyHtml, $account);
            $jobId = 'marketing_queue_' . (int)$row['id'] . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
            try {
                $result = crm_mail_execute_send_job($account, [
                    'to_emails' => (string)$row['receiver_email'],
                    'subject' => (string)$row['subject'],
                    'body_html' => (string)$prepared['body_html'],
                    'customer_id' => (int)$row['customer_id'],
                ], array_merge($prepared['attachments'], (int)($deliveryMeta['delivery_version'] ?? 0) === 2 ? crm_delivery_assets($deliveryMeta['asset_ids'] ?? [],(int)$deliveryMeta['owner_id'],true) : []), $jobId, (string)$prepared['body_original']);
            } finally {
                crm_mail_cleanup_generated_attachments($prepared['attachments']);
            }
            $sentMailId = (int)($result['sent_mail_id'] ?? 0);
            if ($sentMailId > 0) {
                db()->prepare('UPDATE crm_mails SET linked_contact_id = ?, tags_json = ?, raw_headers_json = ?, updated_at = NOW() WHERE id = ? AND user_id = ?')
                    ->execute([(int)($row['contact_id'] ?? 0) ?: null, json_encode(['推广队列', 'CRM发送'], JSON_UNESCAPED_UNICODE), json_encode(['marketing_queue_id' => (int)$row['id'], 'smtp_response' => $result['smtp_response'] ?? '', 'inline_image_count' => (int)($result['store_result']['inline'] ?? 0)], JSON_UNESCAPED_UNICODE), $sentMailId, (int)$account['user_id']]);
            }
            db()->prepare("UPDATE crm_marketing_send_queue SET send_status='sent', last_error=NULL, sent_at=NOW(), updated_at=NOW() WHERE id=?")->execute([(int)$row['id']]);
            crm_marketing_update_target_from_queue($row, 'success', '');
            db()->prepare('INSERT INTO crm_marketing_logs (task_id, customer_id, contact_id, channel_key, action_key, result_status, failure_reason, operator_id, detail_json, touched_at, created_at) VALUES (?, ?, ?, "email", "queue_send", "success", "", ?, ?, NOW(), NOW())')
                ->execute([(int)$row['task_id'], (int)$row['customer_id'], (int)($row['contact_id'] ?? 0) ?: null, (int)$row['sender_user_id'], json_encode(['queue_id' => (int)$row['id'], 'sent_mail_id' => $sentMailId, 'sender_email' => $row['sender_email'], 'receiver_email' => $row['receiver_email'], 'inline_image_count' => (int)($result['store_result']['inline'] ?? 0), 'smtp_response' => $result['smtp_response'] ?? ''], JSON_UNESCAPED_UNICODE)]);
            $sent++;
        } catch (Throwable $e) {
            $next = ((int)$row['send_attempts'] + 1) < (int)$row['max_attempts'] ? 'waiting_retry' : 'failed';
            $retryMinutes = max(5,min(1440,(int)($deliveryMeta['retry_interval_minutes'] ?? 30)));
            $retryAt = $next === 'waiting_retry' ? ", planned_server_time=DATE_ADD(NOW(), INTERVAL {$retryMinutes} MINUTE)" : '';
            db()->prepare("UPDATE crm_marketing_send_queue SET send_status='{$next}', last_error=?, updated_at=NOW() {$retryAt} WHERE id=?")->execute([$e->getMessage(), (int)$row['id']]);
            if ($next === 'failed') crm_marketing_update_target_from_queue($row, 'failed', $e->getMessage());
            db()->prepare('INSERT INTO crm_marketing_logs (task_id, customer_id, contact_id, channel_key, action_key, result_status, failure_reason, operator_id, detail_json, touched_at, created_at) VALUES (?, ?, ?, "email", "queue_send", "failed", ?, ?, ?, NOW(), NOW())')
                ->execute([(int)$row['task_id'], (int)$row['customer_id'], (int)($row['contact_id'] ?? 0) ?: null, $e->getMessage(), (int)$row['sender_user_id'], json_encode(['queue_id' => (int)$row['id'], 'sender_email' => $row['sender_email'], 'receiver_email' => $row['receiver_email']], JSON_UNESCAPED_UNICODE)]);
            $failed++;
        } finally {
            if ($deliveryLease) crm_delivery_release_send($deliveryMeta);
        }
        crm_marketing_queue_update_task_status((int)$row['task_id']);
    }
    return ['processed' => count($rows), 'sent' => $sent, 'failed' => $failed, 'skipped' => $skipped];
}

function crm_marketing_template_copy(array $input): array
{
    crm_marketing_ensure_tables();
    crm_require('promotion.task_create');
    $key = trim((string)($input['template_key'] ?? ''));
    if ($key === '') throw new RuntimeException('请选择要复制的邮件模板。');
    $source = null;
    foreach (crm_marketing_templates() as $template) {
        if ((string)$template['key'] === $key) {
            $source = $template;
            break;
        }
    }
    if (!$source) throw new RuntimeException('邮件模板不存在。');
    $newName = crm_marketing_copy_name('crm_marketing_templates', 'template_name', (string)$source['name']);
    $baseKey = preg_replace('/[^a-zA-Z0-9_]+/', '_', strtolower($key)) ?: 'template';
    $newKey = $baseKey . '_copy';
    $i = 2;
    while (true) {
        $stmt = db()->prepare('SELECT COUNT(*) FROM crm_marketing_templates WHERE template_key = ?');
        $stmt->execute([$newKey]);
        if ((int)$stmt->fetchColumn() === 0) break;
        $newKey = $baseKey . '_copy_' . $i;
        $i++;
    }
    db()->prepare('INSERT INTO crm_marketing_templates (template_key, channel_key, template_name, mail_subject, body_html, action_note, source_key, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())')
        ->execute([$newKey, $source['channel'] ?? 'email', $newName, $source['subject'] ?? '', $source['body'] ?? '', $source['action'] ?? '', $key, current_user()['id'] ?? null, current_user()['id'] ?? null]);
    $newId = (int)db()->lastInsertId();
    crm_log_event('promotion', 'template_copy', 'marketing_template', (string)$newId, ['source_key' => $key, 'source_name' => $source['name']], ['new_id' => $newId, 'new_key' => $newKey, 'new_name' => $newName]);
    return ['ok' => true, 'new_id' => $newId, 'new_key' => $newKey, 'new_name' => $newName, 'message' => '邮件模板已复制', 'templates' => crm_marketing_templates()];
}

function crm_marketing_decode_json_input($value): array
{
    if (is_array($value)) return $value;
    $value = trim((string)$value);
    if ($value === '') return [];
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

function crm_marketing_update_customer_status(array $input): array
{
    crm_marketing_ensure_tables();
    crm_require('promotion.manage');
    $customerId = (int)($input['customer_id'] ?? 0);
    $status = trim((string)($input['status'] ?? ''));
    if ($customerId <= 0) throw new RuntimeException('客户 ID 无效。');
    if (!in_array($status, crm_dictionary_keys('promotion_status'), true)) throw new RuntimeException('推广状态无效。');
    $before = null;
    $stmt = db()->prepare('SELECT * FROM crm_customer_promotion_status WHERE customer_id = ? LIMIT 1');
    $stmt->execute([$customerId]);
    $before = $stmt->fetch() ?: null;
    db()->prepare('INSERT INTO crm_customer_promotion_status (customer_id, status, updated_by, updated_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE status=VALUES(status), updated_by=VALUES(updated_by), updated_at=NOW()')
        ->execute([$customerId, $status, current_user()['id'] ?? null]);
    if ($status === 'blacklist') {
        db()->prepare('UPDATE crm_customers SET do_not_contact = 1, updated_by = ?, updated_at = NOW() WHERE id = ?')
            ->execute([current_user()['id'] ?? null, $customerId]);
    }
    crm_log_event('promotion', 'customer_status_update', 'customer', (string)$customerId, $before, ['status' => $status]);
    $poolResult = crm_marketing_pool($input);
    $poolPager = $poolResult;
    unset($poolPager['rows']);
    return ['pool' => $poolResult['rows'] ?? [], 'pool_pager' => $poolPager, 'analytics' => crm_can('promotion.analytics') ? crm_marketing_analytics() : []];
}

function crm_marketing_update_contact_strategy(array $input): array
{
    crm_marketing_ensure_tables();
    crm_require('promotion.manage');
    $ids = crm_mail_input_ids($input['contact_ids'] ?? ($input['contact_id'] ?? []));
    $contactId = (int)($input['contact_id'] ?? 0);
    if (!$ids && $contactId > 0) $ids = [$contactId];
    $channel = trim((string)($input['channel_key'] ?? ''));
    $status = trim((string)($input['status'] ?? 'active'));
    $mode = trim((string)($input['mode'] ?? 'channel'));
    $role = trim((string)($input['role_key'] ?? ''));
    if (!$ids) throw new RuntimeException('联系人 ID 无效。');
    if ($mode === 'channel' && !in_array($channel, crm_dictionary_keys('promotion_channel'), true)) throw new RuntimeException('推广渠道无效。');
    if (!in_array($status, ['active','stopped','no_contact','paused','failed'], true)) $status = 'active';
    $userId = current_user()['id'] ?? null;
    foreach ($ids as $id) {
        $stmt = db()->prepare('SELECT * FROM crm_contacts WHERE id = ? AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([$id]);
        $before = $stmt->fetch();
        if (!$before) continue;
        if ($mode === 'primary') {
            db()->prepare('UPDATE crm_contacts SET is_primary = 0 WHERE customer_id = ? AND id <> ?')->execute([(int)$before['customer_id'], $id]);
            db()->prepare('UPDATE crm_contacts SET is_primary = 1, updated_by = ?, updated_at = NOW() WHERE id = ?')->execute([$userId, $id]);
        } elseif ($mode === 'role') {
            if ($role === '') throw new RuntimeException('请选择联系人角色。');
            db()->prepare('INSERT IGNORE INTO crm_contact_role_tags (contact_id, role_key, created_by, created_at) VALUES (?, ?, ?, NOW())')->execute([$id, $role, $userId]);
        } elseif ($mode === 'clear_role') {
            db()->prepare('DELETE FROM crm_contact_role_tags WHERE contact_id = ?')->execute([$id]);
        } elseif ($mode === 'no_promotion') {
            db()->prepare('UPDATE crm_contacts SET do_not_contact = 1, updated_by = ?, updated_at = NOW() WHERE id = ?')->execute([$userId, $id]);
            db()->prepare('INSERT INTO crm_contact_promotions (contact_id, channel, status, last_contact_time, updated_by, updated_at) VALUES (?, "no_promotion", "active", NULL, ?, NOW()) ON DUPLICATE KEY UPDATE status="active", updated_by=VALUES(updated_by), updated_at=NOW()')->execute([$id, $userId]);
        } elseif ($mode === 'promotable') {
            db()->prepare('UPDATE crm_contacts SET do_not_contact = 0, unsubscribe_email = 0, is_left = 0, updated_by = ?, updated_at = NOW() WHERE id = ?')->execute([$userId, $id]);
            db()->prepare('DELETE FROM crm_contact_promotions WHERE contact_id = ? AND channel IN ("no_promotion","maintenance_only")')->execute([$id]);
            db()->prepare('INSERT INTO crm_contact_promotions (contact_id, channel, status, last_contact_time, updated_by, updated_at) VALUES (?, "email", "active", NULL, ?, NOW()) ON DUPLICATE KEY UPDATE status="active", updated_by=VALUES(updated_by), updated_at=NOW()')->execute([$id, $userId]);
        } elseif ($mode === 'blacklist') {
            db()->prepare('UPDATE crm_contacts SET do_not_contact = 1, updated_by = ?, updated_at = NOW() WHERE id = ?')->execute([$userId, $id]);
        } elseif ($mode === 'invalid_email') {
            db()->prepare('UPDATE crm_contacts SET unsubscribe_email = 1, updated_by = ?, updated_at = NOW() WHERE id = ?')->execute([$userId, $id]);
            db()->prepare('INSERT INTO crm_contact_promotions (contact_id, channel, status, last_contact_time, updated_by, updated_at) VALUES (?, "email", "failed", NULL, ?, NOW()) ON DUPLICATE KEY UPDATE status="failed", updated_by=VALUES(updated_by), updated_at=NOW()')->execute([$id, $userId]);
        } elseif ($mode === 'left') {
            db()->prepare('UPDATE crm_contacts SET is_left = 1, updated_by = ?, updated_at = NOW() WHERE id = ?')->execute([$userId, $id]);
        } else {
            $beforeStmt = db()->prepare('SELECT * FROM crm_contact_promotions WHERE contact_id = ? AND channel = ? LIMIT 1');
            $beforeStmt->execute([$id, $channel]);
            $before = $beforeStmt->fetch() ?: $before;
            db()->prepare('INSERT INTO crm_contact_promotions (contact_id, channel, status, last_contact_time, updated_by, updated_at) VALUES (?, ?, ?, NULL, ?, NOW()) ON DUPLICATE KEY UPDATE status=VALUES(status), updated_by=VALUES(updated_by), updated_at=NOW()')
                ->execute([$id, $channel, $status, $userId]);
        }
        crm_log_event('promotion', 'contact_strategy_update', 'contact', (string)$id, $before, ['mode' => $mode, 'channel' => $channel, 'status' => $status, 'role' => $role]);
    }
    return crm_marketing_contact_strategy_view($input);
}

function crm_marketing_change_task_status(int $taskId, string $status): array
{
    return crm_marketing_with_task_lock($taskId, static function (array $task) use ($taskId, $status): array {
        $oldStatus = (string)($task['task_status'] ?? '');
        if (in_array($oldStatus, ['cancelled','completed'], true) && !in_array($status, [$oldStatus, 'cancelled'], true)) {
            throw new RuntimeException('已结束项目不能恢复执行，请复制为新项目。');
        }
        db()->prepare('UPDATE crm_marketing_tasks SET task_status = ?, updated_at = NOW() WHERE id = ?')->execute([$status, $taskId]);
        $cancelled = 0;
        if ($status === 'cancelled') {
            $stmt = db()->prepare("UPDATE crm_marketing_send_queue SET send_status='cancelled', updated_at=NOW()
                WHERE task_id=? AND send_status IN ('pending','scheduled','waiting_retry','failed')");
            $stmt->execute([$taskId]);
            $cancelled = $stmt->rowCount();
        }
        $inFlight = db()->prepare("SELECT COUNT(*) FROM crm_marketing_send_queue WHERE task_id=? AND send_status='sending'");
        $inFlight->execute([$taskId]);
        return [
            'task_id' => $taskId, 'task_status' => $status, 'previous_status' => $oldStatus,
            'cancelled_queue_count' => $cancelled, 'in_flight_count' => (int)$inFlight->fetchColumn(),
            'message' => $status === 'cancelled' ? '项目及尚未发送的队列已取消；已在发送中的邮件可能仍会送达。'
                : ($status === 'paused' ? '项目已暂停，不再领取待发邮件；已在发送中的邮件可能仍会送达。' : '推广任务状态已更新'),
        ];
    });
}

function crm_marketing_task_set_status(array $input): array
{
    crm_marketing_ensure_tables();
    crm_require('promotion.execute');
    $taskId = (int)($input['task_id'] ?? 0);
    $status = trim((string)($input['status'] ?? ''));
    if ($taskId <= 0) throw new RuntimeException('任务 ID 无效。');
    if (!in_array($status, ['pending','scheduled','running','completed','partial_failed','failed','paused','cancelled','manual_pending'], true)) throw new RuntimeException('任务状态无效。');
    $change = crm_marketing_change_task_status($taskId, $status);
    crm_log_event('promotion', 'task_status_update', 'marketing_task', (string)$taskId, ['task_status' => $change['previous_status']], $change);
    return ['tasks' => crm_marketing_tasks()] + $change;
}

function crm_marketing_task_execute(array $input): array
{
    crm_marketing_ensure_tables();
    crm_mail_ensure_tables();
    crm_ensure_tables();
    crm_require('promotion.execute');
    $taskId = (int)($input['task_id'] ?? 0);
    if ($taskId <= 0) throw new RuntimeException('任务 ID 无效。');
    $builtQueue = false;
    $result = crm_marketing_with_task_lock($taskId, static function (array $task) use ($taskId, $input, &$builtQueue): array {
        crm_marketing_assert_task_executable($task);
        $stmt = db()->prepare('SELECT channel_key,target_status FROM crm_marketing_task_targets WHERE task_id=?');
        $stmt->execute([$taskId]);
        $targets = $stmt->fetchAll();
        if (!$targets) throw new RuntimeException('当前项目没有执行目标，请先在推广向导中补充。');
        $emailTargets = 0; $manualTargets = 0;
        foreach ($targets as $target) {
            if (crm_marketing_is_email_channel((string)$target['channel_key'])) $emailTargets++;
            elseif (in_array((string)$target['target_status'], ['pending','failed'], true)) $manualTargets++;
        }
        if ($emailTargets === 0) {
            if ($manualTargets === 0) throw new RuntimeException('当前没有待处理的人工目标，请查看执行明细。');
            db()->prepare("UPDATE crm_marketing_tasks SET task_status='manual_pending', updated_at=NOW() WHERE id=?")->execute([$taskId]);
            return ['task_id' => $taskId, 'execution_mode' => 'manual', 'accepted' => true, 'queue_count' => 0,
                'manual_target_count' => $manualTargets, 'message' => '人工执行清单已就绪，请逐条记录实际执行结果。'];
        }
        $counts = crm_marketing_queue_status_counts($taskId);
        $existing = 0;
        foreach (['pending','scheduled','sending','sent','failed','skipped','cancelled','waiting_retry'] as $key) $existing += (int)($counts[$key] ?? 0);
        if ($existing > 0) {
            $active = (int)$counts['pending'] + (int)$counts['scheduled'] + (int)$counts['sending'] + (int)$counts['waiting_retry'];
            return ['task_id' => $taskId, 'execution_mode' => 'queued', 'accepted' => true, 'queue_count' => $active,
                'queue_status' => $counts, 'manual_target_count' => $manualTargets,
                'message' => (int)$counts['failed'] > 0 ? '已有发送队列；失败邮件未自动重发，请查看明细并选择重试失败队列。'
                    : ($active > 0 ? '已有发送队列，请查看实际发送进度；本次未重复入队。' : '现有发送队列已结束，请查看执行明细；本次未重新发送。')];
        }
        $queue = crm_marketing_queue_build_locked($input, $task);
        $builtQueue = true;
        if ((int)($queue['queue_count'] ?? 0) <= 0) {
            if ((int)($queue['error_count'] ?? 0) > 0) throw new RuntimeException('邮件未能进入发送队列，请检查发件邮箱及执行目标后重试。');
            if ($manualTargets <= 0) throw new RuntimeException('当前没有可入队邮件或待处理人工目标，请检查执行明细和推广策略。');
        }
        return $queue + ['execution_mode' => (int)($queue['queue_count'] ?? 0) > 0 ? 'queued' : 'manual',
            'accepted' => true, 'manual_target_count' => $manualTargets];
    });
    if ($builtQueue) crm_marketing_notify_queue_build($taskId, $result);
    crm_log_event('promotion', 'task_execution_accepted', 'marketing_task', (string)$taskId, null, $result);
    return $result + ['tasks' => crm_marketing_tasks()];
}

function crm_marketing_manual_upload(array $files): array
{
    $file = $files['manual_attachment'] ?? null;
    if (!$file || empty($file['tmp_name']) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [];
    if ((int)$file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('截图/附件上传失败。');
    $original = trim((string)($file['name'] ?? 'attachment'));
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $safeExt = preg_replace('/[^a-z0-9]/i', '', $ext) ?: 'bin';
    $relativeDir = 'uploads/marketing_manual/' . date('Ym');
    $absoluteDir = __DIR__ . '/' . $relativeDir;
    if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
        throw new RuntimeException('无法创建人工执行附件目录。');
    }
    $stored = date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $safeExt;
    if (!move_uploaded_file((string)$file['tmp_name'], $absoluteDir . '/' . $stored)) {
        throw new RuntimeException('截图/附件保存失败。');
    }
    return [
        'original_name' => $original,
        'file_name' => $stored,
        'file_path' => $relativeDir . '/' . $stored,
        'mime_type' => (string)($file['type'] ?? ''),
        'file_size' => (int)($file['size'] ?? 0),
    ];
}

function crm_marketing_write_manual_followup(array $task, array $target, string $result, string $remark): void
{
    $customerId = (int)($target['customer_id'] ?? 0);
    if ($customerId <= 0) return;
    $channel = crm_marketing_normalize_channel((string)($target['channel_key'] ?? ''));
    $title = '推广人工执行完成';
    $content = '推广任务：' . (string)($task['task_name'] ?? '-') . ' · 渠道：' . $channel . ' · 结果：' . ($result ?: '已完成');
    if ($remark !== '') $content .= ' · 备注：' . $remark;
    try {
        db()->prepare('INSERT INTO crm_customer_followups (customer_id, contact_id, followup_time, followup_type, content, next_plan, next_remind_time, status, created_by, updated_by, created_at, updated_at) VALUES (?, ?, NOW(), ?, ?, "", NULL, "done", ?, ?, NOW(), NOW())')
            ->execute([$customerId, (int)($target['contact_id'] ?? 0) ?: null, 'promotion', $content, current_user()['id'] ?? null, current_user()['id'] ?? null]);
        $followupId = (int)db()->lastInsertId();
        crm_customer_timeline_add($customerId, 'promotion_manual_execute', $title, $content, 'followup', (string)$followupId);
    } catch (Throwable $e) {
        crm_customer_timeline_add($customerId, 'promotion_manual_execute', $title, $content, 'marketing_task', (string)($task['id'] ?? ''));
    }
}

function crm_marketing_manual_execute(array $input, array $files = []): array
{
    crm_marketing_ensure_tables();
    crm_require('promotion.execute');
    $taskId = (int)($input['task_id'] ?? 0);
    $targetIds = crm_mail_input_ids($input['target_ids'] ?? []);
    $manualStatus = trim((string)($input['manual_status'] ?? ($input['status'] ?? 'success')));
    if (!in_array($manualStatus, ['success', 'failed', 'skipped'], true)) $manualStatus = 'success';
    $manualResult = trim((string)($input['manual_result'] ?? ''));
    $manualRemark = trim((string)($input['remark'] ?? ($input['manual_remark'] ?? '')));
    if ($taskId <= 0) throw new RuntimeException('任务 ID 无效。');
    if (!$targetIds) throw new RuntimeException('请选择要标记完成的手动执行目标。');
    $taskStmt = db()->prepare('SELECT * FROM crm_marketing_tasks WHERE id = ? LIMIT 1');
    $taskStmt->execute([$taskId]);
    $task = $taskStmt->fetch();
    if (!$task) throw new RuntimeException('推广任务不存在。');
    $placeholders = implode(',', array_fill(0, count($targetIds), '?'));
    $manualChannels = ['wechat', 'weixin', 'wechat_group', 'whatsapp', 'whatsapp_group', 'phone', 'offline', 'visit', 'linkedin', 'email', 'mail', 'edm'];
    $channelPlaceholders = implode(',', array_fill(0, count($manualChannels), '?'));
    $stmt = db()->prepare("SELECT mt.*, c.customer_name, ct.name AS contact_name, cg.group_name AS chat_group_name, cg.group_platform AS chat_group_platform
        FROM crm_marketing_task_targets mt
        JOIN crm_customers c ON c.id = mt.customer_id
        LEFT JOIN crm_contacts ct ON ct.id = mt.contact_id
        LEFT JOIN crm_customer_chat_groups cg ON cg.id = mt.chat_group_id
        WHERE mt.task_id = ? AND mt.id IN ({$placeholders}) AND LOWER(mt.channel_key) IN ({$channelPlaceholders})");
    $stmt->execute(array_merge([$taskId], $targetIds, $manualChannels));
    $targets = $stmt->fetchAll();
    if (!$targets) throw new RuntimeException('没有可执行的手动目标。');
    $attachment = crm_marketing_manual_upload($files);
    $failureReason = $manualStatus === 'success' ? '' : ($manualResult ?: ($manualStatus === 'skipped' ? '已跳过' : '人工执行失败'));
    $update = db()->prepare('UPDATE crm_marketing_task_targets SET target_status = ?, failure_reason = ?, manual_result = ?, manual_remark = ?, manual_attachment_json = ?, manual_checked_by_user_id = ?, executed_at = NOW() WHERE id = ?');
    $log = db()->prepare('INSERT INTO crm_marketing_logs (task_id, customer_id, contact_id, channel_key, action_key, result_status, failure_reason, operator_id, detail_json, touched_at, created_at) VALUES (?, ?, ?, ?, "manual_execute", ?, ?, ?, ?, NOW(), NOW())');
    foreach ($targets as $target) {
        $update->execute([
            $manualStatus,
            $failureReason,
            $manualResult ?: ($manualStatus === 'success' ? '已联系' : ''),
            $manualRemark ?: null,
            $attachment ? json_encode($attachment, JSON_UNESCAPED_UNICODE) : ($target['manual_attachment_json'] ?? null),
            current_user()['id'] ?? null,
            (int)$target['id'],
        ]);
        $detail = [
            'target_id' => (int)$target['id'],
            'manual_checked_at' => date('Y-m-d H:i:s'),
            'task_name' => $task['task_name'],
            'manual_result' => $manualResult,
            'manual_remark' => $manualRemark,
            'manual_status' => $manualStatus,
            'attachment' => $attachment,
            'chat_group_id' => $target['chat_group_id'] ? (int)$target['chat_group_id'] : null,
            'chat_group_name' => $target['chat_group_name'] ?? '',
            'chat_group_platform' => $target['chat_group_platform'] ?? '',
        ];
        $log->execute([
            $taskId,
            (int)$target['customer_id'],
            $target['contact_id'] ? (int)$target['contact_id'] : null,
            (string)$target['channel_key'],
            $manualStatus,
            $failureReason,
            current_user()['id'] ?? null,
            json_encode($detail, JSON_UNESCAPED_UNICODE),
        ]);
        if ($manualStatus === 'success') {
            crm_marketing_write_manual_followup($task, $target, $manualResult, $manualRemark);
        } else {
            crm_customer_timeline_add((int)$target['customer_id'], 'promotion_manual_' . $manualStatus, '推广人工执行' . ($manualStatus === 'skipped' ? '已跳过' : '失败'), '任务：' . $task['task_name'] . ' · 渠道：' . $target['channel_key'] . ' · ' . $failureReason, 'marketing_task', (string)$taskId);
        }
        if (!empty($target['chat_group_id'])) {
            db()->prepare('UPDATE crm_customer_chat_groups SET last_promoted_at = NOW(), updated_by = ?, updated_at = NOW() WHERE id = ?')
                ->execute([current_user()['id'] ?? null, (int)$target['chat_group_id']]);
        }
    }
    $countStmt = db()->prepare("SELECT
        SUM(target_status = 'success') success_count,
        SUM(target_status = 'failed') failed_count,
        SUM(target_status = 'pending') remaining_count
        FROM crm_marketing_task_targets WHERE task_id = ?");
    $countStmt->execute([$taskId]);
    $counts = $countStmt->fetch() ?: ['success_count' => 0, 'failed_count' => 0, 'remaining_count' => 0];
    $nextStatus = ((int)($counts['remaining_count'] ?? 0) === 0) ? (((int)($counts['failed_count'] ?? 0) > 0) ? 'partial_failed' : 'completed') : 'manual_pending';
    db()->prepare("UPDATE crm_marketing_tasks SET success_count = ?, failed_count = ?, task_status = CASE WHEN task_status IN ('paused','cancelled') THEN task_status ELSE ? END, updated_at = NOW() WHERE id = ?")
        ->execute([(int)($counts['success_count'] ?? 0), (int)($counts['failed_count'] ?? 0), $nextStatus, $taskId]);
    crm_log_event('promotion', 'manual_execute', 'marketing_task', (string)$taskId, null, ['target_ids' => $targetIds, 'checked_count' => count($targets), 'manual_status' => $manualStatus, 'manual_result' => $manualResult]);
    return ['checked_count' => count($targets), 'tasks' => crm_marketing_tasks(), 'logs' => crm_marketing_logs(), 'targets' => crm_marketing_task_targets(['task_id' => $taskId]), 'failed_targets' => crm_marketing_task_targets(['status' => 'failed']), 'analytics' => crm_can('promotion.analytics') ? crm_marketing_analytics() : []];
}

function crm_marketing_manual_unexecute(array $input): array
{
    crm_marketing_ensure_tables();
    crm_require('promotion.execute');
    $taskId = (int)($input['task_id'] ?? 0);
    $targetId = (int)($input['target_id'] ?? 0);
    if ($taskId <= 0 || $targetId <= 0) throw new RuntimeException('请选择要取消的人工执行记录。');
    $stmt = db()->prepare("SELECT mt.*, t.task_name, c.customer_name, ct.name AS contact_name, cg.group_name AS chat_group_name, cg.group_platform AS chat_group_platform
        FROM crm_marketing_task_targets mt
        JOIN crm_marketing_tasks t ON t.id = mt.task_id
        JOIN crm_customers c ON c.id = mt.customer_id
        LEFT JOIN crm_contacts ct ON ct.id = mt.contact_id
        LEFT JOIN crm_customer_chat_groups cg ON cg.id = mt.chat_group_id
        WHERE mt.task_id = ? AND mt.id = ? AND mt.target_status = 'success'
        LIMIT 1");
    $stmt->execute([$taskId, $targetId]);
    $target = $stmt->fetch();
    if (!$target) throw new RuntimeException('没有可取消的已执行记录。');
    $channel = crm_marketing_normalize_channel((string)($target['channel_key'] ?? ''));
    $failureReason = '';
    if (in_array($channel, ['email', 'mail', 'edm'], true)) {
        $failureReason = '邮件无收件邮箱，转人工执行';
    } elseif (!empty($target['chat_group_id']) || in_array($channel, ['wechat_group', 'whatsapp_group'], true)) {
        $failureReason = '邮件无收件邮箱，转群人工执行';
    }
    db()->prepare('UPDATE crm_marketing_task_targets
        SET target_status = "pending", failure_reason = ?, manual_result = NULL, manual_remark = NULL, manual_attachment_json = NULL, manual_checked_by_user_id = NULL, executed_at = NULL
        WHERE id = ?')
        ->execute([$failureReason, $targetId]);
    $detail = [
        'target_id' => $targetId,
        'task_name' => $target['task_name'],
        'previous_manual_result' => $target['manual_result'] ?? '',
        'previous_executed_at' => $target['executed_at'] ?? '',
        'chat_group_id' => $target['chat_group_id'] ? (int)$target['chat_group_id'] : null,
        'chat_group_name' => $target['chat_group_name'] ?? '',
        'chat_group_platform' => $target['chat_group_platform'] ?? '',
    ];
    db()->prepare('INSERT INTO crm_marketing_logs (task_id, customer_id, contact_id, channel_key, action_key, result_status, failure_reason, operator_id, detail_json, touched_at, created_at) VALUES (?, ?, ?, ?, "manual_execute_cancel", "pending", ?, ?, ?, NOW(), NOW())')
        ->execute([$taskId, (int)$target['customer_id'], $target['contact_id'] ? (int)$target['contact_id'] : null, (string)$target['channel_key'], '取消人工执行，恢复待执行', current_user()['id'] ?? null, json_encode($detail, JSON_UNESCAPED_UNICODE)]);
    $countStmt = db()->prepare("SELECT
        SUM(target_status = 'success') success_count,
        SUM(target_status = 'failed') failed_count,
        SUM(target_status = 'pending') remaining_count
        FROM crm_marketing_task_targets WHERE task_id = ?");
    $countStmt->execute([$taskId]);
    $counts = $countStmt->fetch() ?: ['success_count' => 0, 'failed_count' => 0, 'remaining_count' => 0];
    $nextStatus = ((int)($counts['remaining_count'] ?? 0) === 0) ? (((int)($counts['failed_count'] ?? 0) > 0) ? 'partial_failed' : 'completed') : 'manual_pending';
    db()->prepare("UPDATE crm_marketing_tasks SET success_count = ?, failed_count = ?, task_status = CASE WHEN task_status IN ('paused','cancelled') THEN task_status ELSE ? END, updated_at = NOW() WHERE id = ?")
        ->execute([(int)($counts['success_count'] ?? 0), (int)($counts['failed_count'] ?? 0), $nextStatus, $taskId]);
    crm_log_event('promotion', 'manual_execute_cancel', 'marketing_task', (string)$taskId, null, ['target_id' => $targetId]);
    return ['cancelled_id' => $targetId, 'tasks' => crm_marketing_tasks(), 'logs' => crm_marketing_logs(), 'targets' => crm_marketing_task_targets(['task_id' => $taskId]), 'failed_targets' => crm_marketing_task_targets(['status' => 'failed']), 'analytics' => crm_can('promotion.analytics') ? crm_marketing_analytics() : []];
}

function crm_marketing_log_touch(array $input): array
{
    crm_marketing_ensure_tables();
    crm_require('promotion.execute');
    $customerId = (int)($input['customer_id'] ?? 0);
    $contactId = (int)($input['contact_id'] ?? 0);
    $channel = trim((string)($input['channel_key'] ?? ''));
    if ($channel === '') throw new RuntimeException('请选择渠道。');
    db()->prepare('INSERT INTO crm_marketing_logs (task_id, customer_id, contact_id, channel_key, action_key, result_status, failure_reason, operator_id, detail_json, touched_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())')
        ->execute([(int)($input['task_id'] ?? 0) ?: null, $customerId ?: null, $contactId ?: null, $channel, (string)($input['action_key'] ?? 'manual_touch'), (string)($input['result_status'] ?? 'success'), (string)($input['failure_reason'] ?? ''), current_user()['id'] ?? null, json_encode($input, JSON_UNESCAPED_UNICODE)]);
    if ($contactId > 0) {
        db()->prepare('UPDATE crm_contact_promotions SET last_contact_time = NOW(), updated_by = ?, updated_at = NOW() WHERE contact_id = ? AND channel = ?')
            ->execute([current_user()['id'] ?? null, $contactId, $channel]);
    }
    if ($customerId > 0) {
        db()->prepare('INSERT INTO crm_customer_promotion_status (customer_id, status, updated_by, updated_at) VALUES (?, "promoting", ?, NOW()) ON DUPLICATE KEY UPDATE status = IF(status IN ("blacklist","maintenance_only","stopped"), status, "promoting"), updated_by = VALUES(updated_by), updated_at = NOW()')
            ->execute([$customerId, current_user()['id'] ?? null]);
    }
    crm_log_event('promotion', 'manual_touch', $contactId ? 'contact' : 'customer', (string)($contactId ?: $customerId), null, $input);
    return ['logs' => crm_marketing_logs(), 'analytics' => crm_can('promotion.analytics') ? crm_marketing_analytics() : ['status' => [], 'channels' => [], 'countries' => [], 'tasks' => []]];
}

function crm_marketing_prepare_mail_inline_images(string $bodyHtml, array $account): array
{
    $bodyHtml = crm_marketing_linkify_mail_html($bodyHtml);
    $bodyOriginal = $bodyHtml;
    $embeddedInlineResult = crm_mail_extract_embedded_attachment_images($bodyHtml, $account);
    $bodyHtml = (string)($embeddedInlineResult['html'] ?? $bodyHtml);
    $inlineResult = crm_mail_extract_inline_data_attachments($bodyHtml);
    $bodyHtml = (string)($inlineResult['html'] ?? $bodyHtml);
    return [
        'body_html' => $bodyHtml,
        'body_original' => $bodyOriginal,
        'attachments' => array_merge($embeddedInlineResult['attachments'] ?? [], $inlineResult['attachments'] ?? []),
    ];
}

function crm_marketing_test_send(array $input): array
{
    crm_marketing_ensure_tables();
    crm_require('promotion.task_create');
    crm_require('mail.send');

    $testEmail = trim((string)($input['test_email'] ?? ''));
    if (!filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('请填写正确的测试收件邮箱。');
    }

    $subject = trim((string)($input['subject'] ?? ''));
    $body = trim((string)($input['body_html'] ?? ''));
    if ($subject === '') throw new RuntimeException('测试邮件主题不能为空。');
    if ($body === '') throw new RuntimeException('测试邮件正文不能为空。');

    $accountId = (int)($input['mail_account_id'] ?? 0);
    $account = null;
    if ($accountId > 0) {
        $stmt = db()->prepare('SELECT id, user_id FROM crm_user_mail_accounts WHERE id = ? AND deleted_at IS NULL AND is_enabled = 1 LIMIT 1');
        $stmt->execute([$accountId]);
        $row = $stmt->fetch();
        if (!$row) throw new RuntimeException('预览发件箱不存在或未启用。');
        $targetUserId = (int)$row['user_id'];
        $currentId = (int)(current_user()['id'] ?? 0);
        if ($targetUserId !== $currentId && !crm_can('mail.account_manage_all') && !is_super_admin()) {
            throw new RuntimeException('无权使用该发件箱发送测试邮件。');
        }
        $account = crm_mail_current_account(true, $accountId, $targetUserId);
    }
    if (!$account) $account = crm_mail_current_account(true);
    if (!$account) throw new RuntimeException('请先绑定可用发件邮箱。');

    $bodyHtml = '<div style="font-size:12px;color:#6b7280;margin-bottom:12px;border-bottom:1px solid #e5e7eb;padding-bottom:8px;">这是一封推广任务预览测试邮件，仅用于检查客户变量、称呼和正文显示，不会写入正式推广执行结果。</div>' . crm_mail_render_signature_variables($body, $account);
    $prepared = crm_marketing_prepare_mail_inline_images($bodyHtml, $account);
    $sendInput = [
        'to_emails' => $testEmail,
        'subject' => '[推广测试] ' . $subject,
        'body_html' => (string)$prepared['body_html'],
        'customer_id' => (int)($input['customer_id'] ?? 0) ?: 0,
    ];
    $jobId = 'promo_test_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
    try {
        $sendResult = crm_mail_execute_send_job($account, $sendInput, $prepared['attachments'], $jobId, (string)$prepared['body_original']);
    } finally {
        crm_mail_cleanup_generated_attachments($prepared['attachments']);
    }

    $detail = [
        'test_email' => $testEmail,
        'subject' => $subject,
        'customer_id' => (int)($input['customer_id'] ?? 0) ?: null,
        'customer_name' => (string)($input['customer_name'] ?? ''),
        'contact_id' => (int)($input['contact_id'] ?? 0) ?: null,
        'contact_name' => (string)($input['contact_name'] ?? ''),
        'target_email' => (string)($input['target_email'] ?? ''),
        'mail_account_id' => (int)$account['id'],
        'send_email' => (string)$account['email_address'],
        'sent_mail_id' => (int)($sendResult['sent_mail_id'] ?? 0),
        'inline_image_count' => (int)($sendResult['store_result']['inline'] ?? 0),
        'smtp_response' => (string)($sendResult['smtp_response'] ?? ''),
    ];
    db()->prepare('INSERT INTO crm_marketing_logs (task_id, customer_id, contact_id, channel_key, action_key, result_status, failure_reason, operator_id, detail_json, touched_at, created_at) VALUES (?, ?, ?, "email", "test_send", "success", "", ?, ?, NOW(), NOW())')
        ->execute([(int)($input['task_id'] ?? 0) ?: null, $detail['customer_id'], $detail['contact_id'], current_user()['id'] ?? null, json_encode($detail, JSON_UNESCAPED_UNICODE)]);
    crm_log_event('promotion', 'test_send', 'marketing_task', (string)((int)($input['task_id'] ?? 0) ?: 0), null, $detail);

    return [
        'sent_to' => $testEmail,
        'send_email' => (string)$account['email_address'],
        'customer_name' => $detail['customer_name'],
        'response' => (string)($sendResult['smtp_response'] ?? ''),
        'logs' => crm_marketing_logs(['task_id' => (int)($input['task_id'] ?? 0)]),
    ];
}

function crm_marketing_failure_handle(array $input): array
{
    crm_marketing_ensure_tables();
    crm_require('promotion.execute');
    $targetId = (int)($input['target_id'] ?? 0);
    $mode = trim((string)($input['mode'] ?? 'resolved'));
    if ($targetId <= 0) throw new RuntimeException('失败目标 ID 无效。');
    if (!in_array($mode, ['retry','skip','manual','resolved','followup','dispatch','quote','material'], true)) $mode = 'resolved';
    $stmt = db()->prepare('SELECT * FROM crm_marketing_task_targets WHERE id = ? LIMIT 1');
    $stmt->execute([$targetId]);
    $target = $stmt->fetch();
    if (!$target) throw new RuntimeException('失败目标不存在。');
    $taskId = (int)$target['task_id'];
    $originalReason = trim((string)($target['failure_reason'] ?? ''));
    [$manualPlannedAt, $manualDueAt] = crm_marketing_manual_schedule();
    $nextStatus = 'handled';
    $reason = '已处理：' . $mode;
    $executedAtSql = 'NOW()';
    $extraSets = '';
    $params = [];
    if ($mode === 'retry') {
        $nextStatus = 'pending';
        $reason = '';
        $executedAtSql = 'NULL';
    } elseif ($mode === 'skip') {
        $nextStatus = 'skipped';
        $reason = '已跳过' . ($originalReason !== '' ? '：' . $originalReason : '');
    } elseif ($mode === 'manual') {
        $nextStatus = 'pending';
        $reason = '邮件发送失败，转人工执行' . ($originalReason !== '' ? '：' . $originalReason : '');
        $executedAtSql = 'NULL';
        $extraSets = ', planned_at = ?, due_at = ?, manual_result = NULL, manual_remark = NULL, manual_attachment_json = NULL, manual_checked_by_user_id = NULL';
        $params[] = $manualPlannedAt;
        $params[] = $manualDueAt;
    }
    db()->prepare("UPDATE crm_marketing_task_targets SET target_status = ?, failure_reason = ?, executed_at = {$executedAtSql}{$extraSets} WHERE id = ?")
        ->execute(array_merge([$nextStatus, $reason], $params, [$targetId]));
    $queueAffected = 0;
    if (in_array(strtolower((string)$target['channel_key']), ['email','mail','edm'], true)) {
        $queueStatus = $mode === 'retry' ? 'waiting_retry' : ($mode === 'skip' || $mode === 'manual' ? 'skipped' : '');
        if ($queueStatus !== '') {
            $queueSql = "UPDATE crm_marketing_send_queue
                SET send_status = ?, planned_server_time = IF(? = 'waiting_retry', NOW(), planned_server_time), last_error = ?, updated_at = NOW()
                WHERE task_id = ? AND customer_id = ? AND (contact_id <=> ?) AND send_status = 'failed'";
            $queueReason = $mode === 'retry' ? null : $reason;
            $queueStmt = db()->prepare($queueSql);
            $queueStmt->execute([$queueStatus, $queueStatus, $queueReason, $taskId, (int)$target['customer_id'], $target['contact_id'] ? (int)$target['contact_id'] : null]);
            $queueAffected = $queueStmt->rowCount();
        }
    }
    $countStmt = db()->prepare("SELECT
        SUM(target_status = 'success') success_count,
        SUM(target_status = 'failed') failed_count,
        SUM(target_status = 'pending') remaining_count
        FROM crm_marketing_task_targets WHERE task_id = ?");
    $countStmt->execute([$taskId]);
    $counts = $countStmt->fetch() ?: ['success_count' => 0, 'failed_count' => 0, 'remaining_count' => 0];
    $nextTaskStatus = ((int)($counts['remaining_count'] ?? 0) === 0) ? (((int)($counts['failed_count'] ?? 0) > 0) ? 'partial_failed' : 'completed') : ($mode === 'retry' ? 'running' : 'manual_pending');
    db()->prepare("UPDATE crm_marketing_tasks SET success_count = ?, failed_count = ?, task_status = CASE WHEN task_status IN ('paused','cancelled') THEN task_status ELSE ? END, updated_at = NOW() WHERE id = ?")
        ->execute([(int)($counts['success_count'] ?? 0), (int)($counts['failed_count'] ?? 0), $nextTaskStatus, $taskId]);
    db()->prepare('INSERT INTO crm_marketing_logs (task_id, customer_id, contact_id, channel_key, action_key, result_status, failure_reason, operator_id, detail_json, touched_at, created_at) VALUES (?, ?, ?, ?, ?, "success", ?, ?, ?, NOW(), NOW())')
        ->execute([$taskId, (int)$target['customer_id'], $target['contact_id'] ? (int)$target['contact_id'] : null, (string)$target['channel_key'], 'failure_' . $mode, $reason, current_user()['id'] ?? null, json_encode(['target' => $target, 'mode' => $mode, 'queue_affected' => $queueAffected], JSON_UNESCAPED_UNICODE)]);
    crm_log_event('promotion', 'failure_handle', 'marketing_target', (string)$targetId, $target, ['mode' => $mode, 'status' => $nextStatus, 'queue_affected' => $queueAffected]);
    return ['task_id' => $taskId, 'target_id' => $targetId, 'mode' => $mode, 'queue_affected' => $queueAffected, 'targets' => crm_marketing_task_targets(['task_id' => $taskId]), 'failed_targets' => crm_marketing_task_targets(['status' => 'failed']), 'logs' => crm_marketing_logs(), 'tasks' => crm_marketing_tasks()];
}
