<?php

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

function crm_marketing_ensure_tables(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

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
    @file_put_contents($file, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
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
            SELECT rg.group_id, COUNT(*) AS customer_count
            FROM crm_marketing_group_customers rg
            JOIN crm_customers c ON c.id = rg.customer_id AND c.deleted_at IS NULL
            GROUP BY rg.group_id
        ) x ON x.group_id = g.id
        LEFT JOIN (
            SELECT rg.group_id, COUNT(*) AS contact_count
            FROM crm_marketing_group_contacts rg
            JOIN crm_contacts ct ON ct.id = rg.contact_id AND ct.deleted_at IS NULL
            JOIN crm_customers c ON c.id = ct.customer_id AND c.deleted_at IS NULL
            GROUP BY rg.group_id
        ) y ON y.group_id = g.id
        LEFT JOIN (
            SELECT rg.group_id, COUNT(*) AS promotable_contact_count
            FROM crm_marketing_group_customers rg
            JOIN crm_contacts ct ON ct.customer_id = rg.customer_id AND ct.deleted_at IS NULL
            WHERE COALESCE(ct.is_left,0)=0 AND COALESCE(ct.do_not_contact,0)=0 AND COALESCE(ct.unsubscribe_email,0)=0 AND COALESCE(ct.email,'') <> ''
            GROUP BY rg.group_id
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
    $queryLimit = $skipCount ? ($pageSize + 1) : $pageSize;
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
            ORDER BY FIELD(COALESCE(ps.status, 'not_promoted'), 'promoting','not_promoted','paused','stopped','maintenance_only','blacklist'), c.updated_at DESC
            LIMIT {$queryLimit} OFFSET {$offset}
        ) c
        LEFT JOIN crm_users owner ON owner.id = c.owner_user_id
        ORDER BY FIELD(c.promotion_status, 'promoting','not_promoted','paused','stopped','maintenance_only','blacklist'), c.updated_at DESC");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $hasMore = false;
    if ($skipCount && count($rows) > $pageSize) {
        $hasMore = true;
        $rows = array_slice($rows, 0, $pageSize);
    }
    if ($skipCount) $total = $offset + count($rows) + ($hasMore ? 1 : 0);
    return [
        'rows' => $rows,
        'total' => $total,
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

function crm_marketing_contact_strategy_view(array $input = []): array
{
    $rows = crm_marketing_contacts($input);
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
    ];
    return ['contacts' => $rows, 'contact_stats' => $stats, 'contact_preview' => $preview, 'contact_skip_reasons' => $skip];
}

function crm_marketing_tasks(): array
{
    crm_marketing_ensure_tables();
    crm_require('promotion.view');
    $stmt = db()->query("SELECT t.*, u.username AS created_by_name FROM crm_marketing_tasks t LEFT JOIN crm_users u ON u.id = t.created_by ORDER BY t.id DESC LIMIT 80");
    return $stmt->fetchAll();
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
        $allInput['skip_count'] = 1;
        $result = crm_marketing_pool($allInput);
        $allPool = $result['rows'] ?? [];
        $allPager = $result;
        unset($allPager['rows']);
    }
    $contacts = [];
    if (!$onlyAll) {
        $contactsInput = $input;
        $contactsInput['group_id'] = $groupId;
        $contacts = crm_marketing_contacts($contactsInput);
    }
    return [
        'group_id' => $groupId,
        'group_pool' => $groupPool,
        'group_pool_pager' => $groupPager,
        'all_pool' => $allPool,
        'all_pool_pager' => $allPager,
        'pool' => $groupId > 0 ? $groupPool : $allPool,
        'pool_pager' => $groupId > 0 ? $groupPager : $allPager,
        'contacts' => $contacts,
    ];
}

function crm_marketing_target_preview(array $input = []): array
{
    crm_marketing_ensure_tables();
    crm_require('promotion.view');
    $customerIds = crm_mail_input_ids($input['customer_ids'] ?? '');
    $contactIds = crm_mail_input_ids($input['contact_ids'] ?? '');
    $customers = [];
    $contacts = [];
    $chatGroups = [];
    if ($customerIds) {
        $pool = crm_marketing_pool([
            'customer_ids' => json_encode($customerIds),
            'page' => 1,
            'page_size' => min(200, max(20, count($customerIds))),
            'skip_count' => 1,
        ]);
        $customers = $pool['rows'] ?? [];
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
    return [
        'pool' => $customers,
        'contacts' => $contacts,
        'chat_groups' => $chatGroups,
        'selected_customer_count' => count($customerIds),
        'selected_contact_count' => count($contactIds),
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
    $stmt = db()->query("SELECT a.id, a.user_id, a.email_address, a.sender_name, a.signature_html, a.is_default,
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
    $stmt = db()->query('SELECT id, template_name, template_html FROM crm_mail_signature_templates WHERE is_default = 1 ORDER BY id DESC LIMIT 1');
    $row = $stmt->fetch();
    return $row ?: [];
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
    $stmt = db()->prepare("SELECT mt.*, t.task_name, t.task_status, t.campaign_type, t.mail_subject,
        c.customer_name, c.customer_code, c.country, c.do_not_contact, c.phone AS customer_phone, c.whatsapp AS customer_whatsapp, c.owner_user_id,
        ct.name AS contact_name, ct.email, ct.phone, ct.whatsapp, ct.wechat, ct.linkedin, ct.position, ct.is_left,
        cg.group_name AS chat_group_name, cg.group_platform AS chat_group_platform, cg.group_owner AS chat_group_owner,
        COALESCE(ex.real_name, ex.username) AS executor_name,
        CASE WHEN mt.target_status IN ('pending','failed') AND mt.due_at IS NOT NULL AND mt.due_at < NOW() THEN 'overdue' ELSE mt.target_status END AS manual_status,
        COALESCE(ps.status, 'not_promoted') customer_promotion_status
        FROM crm_marketing_task_targets mt
        JOIN crm_marketing_tasks t ON t.id = mt.task_id
        JOIN crm_customers c ON c.id = mt.customer_id
        LEFT JOIN crm_contacts ct ON ct.id = mt.contact_id
        LEFT JOIN crm_customer_chat_groups cg ON cg.id = mt.chat_group_id
        LEFT JOIN crm_users ex ON ex.id = mt.executor_user_id
        LEFT JOIN crm_customer_promotion_status ps ON ps.customer_id = c.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY FIELD(mt.target_status, 'failed','pending','success'), mt.id DESC
        LIMIT 200");
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
    $stmt = db()->prepare("SELECT ml.*, c.customer_name, ct.name AS contact_name, u.username AS operator_name
        FROM crm_marketing_logs ml
        LEFT JOIN crm_customers c ON c.id = ml.customer_id
        LEFT JOIN crm_contacts ct ON ct.id = ml.contact_id
        LEFT JOIN crm_users u ON u.id = ml.operator_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY ml.touched_at DESC, ml.id DESC LIMIT 100");
    $stmt->execute($params);
    return $stmt->fetchAll();
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
        'failure_reason' => $missing ? '缺少联系方式' : '',
    ];
}

function crm_marketing_manual_schedule(?string $scheduledAt = null): array
{
    $planned = $scheduledAt ? str_replace('T', ' ', $scheduledAt) : date('Y-m-d H:i:s');
    $ts = strtotime($planned) ?: time();
    return [$planned, date('Y-m-d H:i:s', $ts + 86400)];
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
            $poolResult = crm_marketing_pool($input);
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
        'mail_accounts' => crm_marketing_bootstrap_cached('mail_accounts', 'crm_marketing_mail_accounts'),
        'company_signature' => crm_marketing_bootstrap_cached('company_signature', 'crm_marketing_company_signature'),
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
    crm_require('promotion.task_create');
    $taskId = (int)($input['task_id'] ?? 0);
    $requestedStatus = trim((string)($input['task_status'] ?? 'pending'));
    $isDraft = $requestedStatus === 'draft';
    $name = trim((string)($input['task_name'] ?? ''));
    $channel = trim((string)($input['channel_key'] ?? ''));
    $campaignType = trim((string)($input['campaign_type'] ?? 'email'));
    $subject = trim((string)($input['mail_subject'] ?? ''));
    $bodyHtml = trim((string)($input['mail_body_html'] ?? ''));
    if ($name === '' && $isDraft) $name = '未命名推广草稿 ' . date('Y-m-d H:i');
    if ($channel === '' && $isDraft) $channel = 'draft';
    if ($name === '') throw new RuntimeException('任务名称不能为空。');
    if ($channel === '') throw new RuntimeException('请选择推广渠道。');
    $preferenceMode = in_array($channel, ['preference','customer_preference','auto_preference'], true);
    if (!$isDraft && !$preferenceMode && ($campaignType === 'email' || in_array($channel, ['email','edm','mail'], true)) && ($subject === '' || $bodyHtml === '')) {
        throw new RuntimeException('邮件推广必须填写邮件主题和正文。');
    }
    if (!$isDraft && $preferenceMode && $bodyHtml === '') {
        throw new RuntimeException('按客户偏好推广必须填写执行话术/邮件正文。');
    }
    $customerIds = crm_mail_input_ids($input['customer_ids'] ?? '');
    $contactIds = crm_mail_input_ids($input['contact_ids'] ?? '');
    $chatGroupIds = crm_mail_input_ids($input['chat_group_ids'] ?? '');
    if (!$isDraft && !$customerIds && !$contactIds && !$chatGroupIds) throw new RuntimeException('请至少选择客户、联系人或客户群。');
    $audienceConfig = crm_marketing_decode_json_input($input['audience_config'] ?? []);
    $sendRule = crm_marketing_decode_json_input($input['send_rule'] ?? []);
    $scheduleConfig = crm_marketing_decode_json_input($input['schedule_config'] ?? []);
    $failurePolicy = crm_marketing_decode_json_input($input['failure_policy'] ?? []);
    $attachmentConfig = crm_marketing_decode_json_input($input['attachment_config'] ?? []);
    $riskSummary = crm_marketing_decode_json_input($input['risk_summary'] ?? []);
    $scheduledAt = trim((string)($input['scheduled_at'] ?? ''));
    if ($scheduledAt === '' && !empty($scheduleConfig['scheduled_at'])) $scheduledAt = (string)$scheduleConfig['scheduled_at'];
    $allowedStatuses = ['draft', 'pending', 'scheduled', 'running', 'paused', 'partial_failed', 'completed', 'failed', 'cancelled', 'manual_pending'];
    $status = $isDraft ? 'draft' : (($taskId > 0 && in_array($requestedStatus, $allowedStatuses, true)) ? $requestedStatus : 'pending');
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
        (string)($input['schedule_type'] ?? ($scheduleConfig['schedule_type'] ?? 'manual')),
        $scheduledAt ? str_replace('T', ' ', $scheduledAt) : null,
        (string)($input['remark'] ?? ''),
    ];
    $before = null;
    if ($taskId > 0) {
        $stmt = db()->prepare('SELECT * FROM crm_marketing_tasks WHERE id = ? LIMIT 1');
        $stmt->execute([$taskId]);
        $before = $stmt->fetch() ?: null;
        if (!$before) $taskId = 0;
    }
    if ($taskId > 0) {
        db()->prepare('UPDATE crm_marketing_tasks
            SET task_name = ?, channel_key = ?, campaign_type = ?, mail_subject = ?, mail_body_html = ?, signature_key = ?,
                attachment_config_json = ?, audience_config_json = ?, send_rule_json = ?, schedule_config_json = ?, failure_policy_json = ?, risk_summary_json = ?,
                task_status = ?, schedule_type = ?, scheduled_at = ?, remark = ?, assigned_to = ?, updated_at = NOW()
            WHERE id = ?')
            ->execute(array_merge($taskPayload, [current_user()['id'] ?? null, $taskId]));
        db()->prepare('DELETE FROM crm_marketing_task_targets WHERE task_id = ?')->execute([$taskId]);
    } else {
        db()->prepare('INSERT INTO crm_marketing_tasks (
            task_name, channel_key, campaign_type, mail_subject, mail_body_html, signature_key,
            attachment_config_json, audience_config_json, send_rule_json, schedule_config_json, failure_policy_json, risk_summary_json,
            task_status, schedule_type, scheduled_at, remark, created_by, assigned_to, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())')
            ->execute(array_merge($taskPayload, [current_user()['id'] ?? null, current_user()['id'] ?? null]));
        $taskId = (int)db()->lastInsertId();
    }
    [$manualPlannedAt, $manualDueAt] = crm_marketing_manual_schedule($scheduledAt ?: null);
    $insert = db()->prepare('INSERT INTO crm_marketing_task_targets (task_id, customer_id, contact_id, chat_group_id, channel_key, contact_method, manual_group_name, executor_user_id, planned_at, due_at, target_status, failure_reason, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
    $insertTarget = function (int $customerId, ?int $contactId, ?int $chatGroupId, string $targetChannel, array $customer = [], array $contact = [], array $group = []) use ($insert, $taskId, $manualPlannedAt, $manualDueAt): void {
        $targetChannel = crm_marketing_normalize_channel($targetChannel);
        $executorId = (int)($customer['owner_user_id'] ?? 0) ?: (int)(current_user()['id'] ?? 0) ?: null;
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
    if (!$isDraft || $customerIds || $contactIds || $chatGroupIds) {
        foreach ($contactIds as $contactId) {
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
            $stmt = db()->prepare('SELECT g.id, g.customer_id, g.group_name, g.group_platform, c.owner_user_id, c.email, c.phone, c.whatsapp, c.address
                FROM crm_customer_chat_groups g
                JOIN crm_customers c ON c.id = g.customer_id
                WHERE g.id = ? AND g.deleted_at IS NULL AND g.status = "active" AND g.use_for_promotion = 1 AND c.deleted_at IS NULL');
            $stmt->execute([$chatGroupId]);
            $group = $stmt->fetch();
            if ($group) {
                $customerId = (int)$group['customer_id'];
                $targetChannel = in_array($channel, ['wechat_group','whatsapp_group'], true) ? $channel : (string)$group['group_platform'];
                $insertTarget($customerId, null, (int)$group['id'], $targetChannel, $group, [], $group);
                $targetCustomerIds[] = $customerId;
                $targetCount++;
            }
        }
        foreach ($customerIds as $customerId) {
            $stmt = db()->prepare('SELECT id, owner_user_id, email, phone, whatsapp, address FROM crm_customers WHERE id = ? AND deleted_at IS NULL');
            $stmt->execute([$customerId]);
            $customer = $stmt->fetch();
            if ($customer) {
                $targetChannel = crm_marketing_resolve_target_channel($channel, $customerId, null);
                if (crm_marketing_is_email_channel($targetChannel)) {
                    $contactStmt = db()->prepare("SELECT * FROM crm_contacts
                        WHERE customer_id = ? AND deleted_at IS NULL
                          AND COALESCE(is_left,0) = 0
                          AND COALESCE(do_not_contact,0) = 0
                          AND COALESCE(unsubscribe_email,0) = 0
                          AND COALESCE(email,'') <> ''
                        ORDER BY is_primary DESC, id DESC");
                    $contactStmt->execute([$customerId]);
                    $expanded = 0;
                    foreach ($contactStmt->fetchAll() as $contact) {
                        $contactId = (int)$contact['id'];
                        if (isset($insertedContactIds[$contactId])) continue;
                        $insertTarget($customerId, $contactId, null, $targetChannel, $customer, $contact, []);
                        $insertedContactIds[$contactId] = true;
                        $targetCount++;
                        $expanded++;
                    }
                    if ($expanded > 0) {
                        continue;
                    }
                }
                $insertTarget($customerId, null, null, $targetChannel, $customer, [], []);
                $targetCount++;
            }
        }
    }
    db()->prepare('UPDATE crm_marketing_tasks SET customer_count = ?, contact_count = ?, updated_at = NOW() WHERE id = ?')
        ->execute([count(array_unique($targetCustomerIds)), count($insertedContactIds) + count(array_unique($chatGroupIds)), $taskId]);
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
    $priority = ['email', 'mail', 'edm', 'whatsapp_group', 'wechat_group', 'whatsapp', 'wechat', 'weixin', 'linkedin', 'phone', 'offline'];
    if ($contactId) {
        $stmt = db()->prepare("SELECT channel FROM crm_contact_promotions WHERE contact_id = ? AND status = 'active' ORDER BY FIELD(channel, 'email','mail','edm','whatsapp','wechat','weixin','linkedin','phone','offline'), id LIMIT 1");
        $stmt->execute([$contactId]);
        $channel = crm_marketing_normalize_channel((string)$stmt->fetchColumn());
        if ($channel !== '') return $channel;
    }
    if (function_exists('db_table_exists') && db_table_exists('crm_customer_promotion_channels')) {
        $stmt = db()->prepare("SELECT channel_key FROM crm_customer_promotion_channels WHERE customer_id = ? ORDER BY FIELD(channel_key, 'email','mail','edm','whatsapp_group','wechat_group','whatsapp','wechat','weixin','linkedin','phone','offline'), id LIMIT 1");
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
    crm_require('promotion.task_create');
    $taskId = (int)($input['task_id'] ?? 0);
    if ($taskId <= 0) throw new RuntimeException('请选择推广任务。');
    $stmt = db()->prepare('SELECT * FROM crm_marketing_tasks WHERE id = ? LIMIT 1');
    $stmt->execute([$taskId]);
    $before = $stmt->fetch();
    if (!$before) throw new RuntimeException('推广任务不存在。');

    $allowedStatus = ['draft','pending','scheduled','running','paused','partial_failed','completed','failed','cancelled','manual_pending'];
    $name = trim((string)($input['task_name'] ?? $before['task_name']));
    $channel = trim((string)($input['channel_key'] ?? $before['channel_key']));
    $status = trim((string)($input['task_status'] ?? $before['task_status']));
    $scheduleType = trim((string)($input['schedule_type'] ?? $before['schedule_type']));
    $scheduledAt = trim((string)($input['scheduled_at'] ?? ''));
    $subject = trim((string)($input['mail_subject'] ?? ''));
    $bodyHtml = trim((string)($input['mail_body_html'] ?? ''));
    $remark = trim((string)($input['remark'] ?? ''));
    if ($name === '') throw new RuntimeException('任务名称不能为空。');
    if ($channel === '') throw new RuntimeException('请选择推广渠道。');
    if (!in_array($status, $allowedStatus, true)) $status = (string)$before['task_status'];
    if ($scheduleType === '') $scheduleType = 'manual';
    $scheduledValue = $scheduledAt !== '' ? str_replace('T', ' ', $scheduledAt) : null;

    db()->prepare('UPDATE crm_marketing_tasks
        SET task_name = ?, channel_key = ?, task_status = ?, schedule_type = ?, scheduled_at = ?, mail_subject = ?, mail_body_html = ?, remark = ?, updated_at = NOW()
        WHERE id = ?')
        ->execute([$name, $channel, $status, $scheduleType, $scheduledValue, $subject, $bodyHtml, $remark, $taskId]);

    $after = db()->prepare('SELECT * FROM crm_marketing_tasks WHERE id = ? LIMIT 1');
    $after->execute([$taskId]);
    $updated = $after->fetch() ?: [];
    crm_log_event('promotion', 'task_update', 'marketing_task', (string)$taskId, $before, $updated);
    return ['task' => $updated, 'tasks' => crm_marketing_tasks(), 'logs' => crm_marketing_logs(['task_id' => $taskId])];
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
        'germany' => 1, 'de' => 1, 'france' => 1, 'fr' => 1, 'italy' => 1, 'spain' => 1,
        'uk' => 0, 'united kingdom' => 0, 'gb' => 0, '英国' => 0,
        'usa' => -5, 'us' => -5, 'united states' => -5, 'america' => -5, '美国' => -5,
        'australia' => 10, 'au' => 10, '澳大利亚' => 10, 'new zealand' => 12,
        'singapore' => 8, 'malaysia' => 8, 'thailand' => 7, 'vietnam' => 7, 'indonesia' => 7, 'philippines' => 8,
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
    $vars = [
        '{customer_name}' => (string)($row['contact_name'] ?: ($row['customer_name'] ?? '')),
        '{contact_name}' => (string)($row['contact_name'] ?? ''),
        '{company_name}' => (string)($row['customer_name'] ?? ''),
        '{country}' => (string)($row['country'] ?? ''),
        '{mail_user_name}' => (string)($account['sender_name'] ?: ($account['owner_name'] ?? $account['username'] ?? '')),
        '{send_email}' => (string)($account['email_address'] ?? ''),
        '{email}' => (string)($account['email_address'] ?? ''),
    ];
    return strtr($text, $vars);
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
    crm_require('promotion.task_create');
    $taskId = (int)($input['task_id'] ?? 0);
    if ($taskId <= 0) throw new RuntimeException('请选择推广任务。');
    $task = crm_marketing_task_row($taskId);
    if (($task['task_status'] ?? '') === 'draft') throw new RuntimeException('草稿不能生成正式发送队列。');
    $subject = trim((string)($task['mail_subject'] ?? ''));
    $body = trim((string)($task['mail_body_html'] ?? ''));
    $sendRule = crm_marketing_json($task['send_rule_json'] ?? '');
    $schedule = crm_marketing_json($task['schedule_config_json'] ?? '');
    $failure = crm_marketing_json($task['failure_policy_json'] ?? '');
    $maxAttempts = max(1, (int)($failure['retry_count'] ?? 1) + 1);
    $targets = db()->prepare("SELECT mt.*, c.customer_name, c.country, c.owner_user_id, c.do_not_contact, COALESCE(ps.status, 'not_promoted') promotion_status,
            COALESCE(ct.name, '') contact_name, COALESCE(NULLIF(ct.email, ''), NULLIF(c.email, '')) receiver_email, COALESCE(ct.is_left, 0) is_left,
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
    $emailTargetCount = 0;
    foreach ($rows as $targetRow) {
        if (crm_marketing_is_email_channel((string)$targetRow['channel_key'])) $emailTargetCount++;
    }
    if ($emailTargetCount === 0) {
        db()->prepare("UPDATE crm_marketing_tasks SET task_status='manual_pending', updated_at=NOW() WHERE id=?")->execute([$taskId]);
        return ['task_id' => $taskId, 'queue_count' => 0, 'skipped_count' => count($rows), 'error_count' => 0, 'first_planned_time' => null, 'last_planned_time' => null, 'message' => '当前任务没有邮件渠道目标，已进入人工执行待处理'];
    }
    if ($subject === '') throw new RuntimeException('邮件主题为空，不能启动正式队列。');
    if ($body === '') throw new RuntimeException('邮件正文为空，不能启动正式队列。');
    $accounts = db()->query("SELECT a.*, COALESCE(u.real_name, u.username, '') owner_name, u.username
        FROM crm_user_mail_accounts a
        LEFT JOIN crm_users u ON u.id = a.user_id
        WHERE a.deleted_at IS NULL AND a.is_enabled = 1
        ORDER BY a.is_default DESC, a.id DESC")->fetchAll();
    $accountsByUser = [];
    foreach ($accounts as $account) if (!isset($accountsByUser[(int)$account['user_id']])) $accountsByUser[(int)$account['user_id']] = $account;
    $selectedIds = array_filter(array_map('intval', $sendRule['mail_account_ids'] ?? []));
    $selectedAccounts = $selectedIds ? array_values(array_filter($accounts, fn($a) => in_array((int)$a['id'], $selectedIds, true))) : $accounts;
    $queueCount = 0; $skipped = 0; $errors = 0; $first = null; $last = null; $index = 0; $balanced = 0;
    foreach ($rows as $row) {
        $channel = crm_marketing_normalize_channel((string)$row['channel_key']);
        if (!crm_marketing_is_email_channel($channel)) { $skipped++; continue; }
        $receiver = trim((string)($row['receiver_email'] ?? ''));
        $skipReason = '';
        if ((int)$row['do_not_contact'] || in_array((string)$row['promotion_status'], ['blacklist','maintenance_only','stopped','no_promotion'], true)) $skipReason = '客户禁止推广';
        elseif ((int)($row['is_left'] ?? 0) === 1) $skipReason = '联系人已离职';
        elseif ($receiver === '') $skipReason = '收件邮箱为空';
        if ($skipReason !== '') {
            $skipped++;
            db()->prepare('INSERT INTO crm_marketing_logs (task_id, customer_id, contact_id, channel_key, action_key, result_status, failure_reason, operator_id, detail_json, touched_at, created_at) VALUES (?, ?, ?, "email", "queue_skipped", "skipped", ?, ?, ?, NOW(), NOW())')
                ->execute([$taskId, (int)$row['customer_id'], (int)($row['contact_id'] ?? 0) ?: null, $skipReason, current_user()['id'] ?? null, json_encode(['receiver_email' => $receiver], JSON_UNESCAPED_UNICODE)]);
            continue;
        }
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
        $queueBody = crm_marketing_render_queue_template($body, $row, $account);
        $dup = db()->prepare('SELECT id FROM crm_marketing_send_queue WHERE task_id=? AND receiver_email=? AND contact_id <=> ? LIMIT 1');
        $dup->execute([$taskId, $receiver, (int)($row['contact_id'] ?? 0) ?: null]);
        if ($dup->fetchColumn()) {
            $skipped++;
            continue;
        }
        db()->prepare("INSERT INTO crm_marketing_send_queue
            (task_id, campaign_id, customer_id, contact_id, sender_user_id, sender_email, receiver_email, subject, body, attachment_json, country, customer_timezone, planned_customer_time, planned_server_time, send_status, send_attempts, max_attempts, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE sender_user_id=VALUES(sender_user_id), sender_email=VALUES(sender_email), subject=VALUES(subject), body=VALUES(body), attachment_json=VALUES(attachment_json), country=VALUES(country), customer_timezone=VALUES(customer_timezone), planned_customer_time=VALUES(planned_customer_time), planned_server_time=VALUES(planned_server_time), send_status=IF(send_status IN ('sent','sending'), send_status, VALUES(send_status)), max_attempts=VALUES(max_attempts), updated_at=NOW()")
            ->execute([$taskId, null, (int)$row['customer_id'], (int)($row['contact_id'] ?? 0) ?: null, (int)$account['user_id'], (string)$account['email_address'], $receiver, $queueSubject, $queueBody, (string)($task['attachment_config_json'] ?? '[]'), (string)($row['country'] ?? ''), $planned['timezone'], $planned['customer'], $planned['server'], strtotime($planned['server']) <= time() ? 'pending' : 'scheduled', $maxAttempts]);
        $queueCount++;
        $first = $first === null || $planned['server'] < $first ? $planned['server'] : $first;
        $last = $last === null || $planned['server'] > $last ? $planned['server'] : $last;
    }
    $status = $queueCount > 0 ? (($first && strtotime($first) > time()) ? 'scheduled' : 'running') : 'manual_pending';
    db()->prepare('UPDATE crm_marketing_tasks SET task_status=?, customer_count=customer_count, contact_count=contact_count, updated_at=NOW() WHERE id=?')->execute([$status, $taskId]);
    crm_log_event('promotion', 'queue_build', 'marketing_task', (string)$taskId, null, ['queue_count' => $queueCount, 'skipped_count' => $skipped, 'error_count' => $errors, 'first' => $first, 'last' => $last]);
    if (function_exists('create_system_notification') && !empty($task['created_by'])) {
        create_system_notification((int)$task['created_by'], 'promotion_queue_build', '推广发送队列已生成', '任务 ' . (string)$task['task_name'] . ' 已生成 ' . $queueCount . ' 条邮件队列。', ['source_module' => 'promotion', 'source_id' => $taskId]);
    }
    return ['task_id' => $taskId, 'queue_count' => $queueCount, 'skipped_count' => $skipped, 'error_count' => $errors, 'first_planned_time' => $first, 'last_planned_time' => $last, 'message' => $queueCount > 0 ? '邮件发送队列已生成' : '没有可入队邮件目标，任务进入人工处理'];
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

function crm_marketing_queue_list(array $input): array
{
    crm_marketing_ensure_tables();
    crm_require('promotion.view');
    $taskId = (int)($input['task_id'] ?? 0);
    if ($taskId <= 0) throw new RuntimeException('请选择推广任务。');
    $status = trim((string)($input['status'] ?? ''));
    $where = ['q.task_id = ?'];
    $params = [$taskId];
    if ($status !== '') {
        $where[] = 'q.send_status = ?';
        $params[] = $status;
    }
    $stmt = db()->prepare('SELECT q.*, c.customer_name, ct.name contact_name
        FROM crm_marketing_send_queue q
        LEFT JOIN crm_customers c ON c.id = q.customer_id
        LEFT JOIN crm_contacts ct ON ct.id = q.contact_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY q.planned_server_time ASC, q.id ASC
        LIMIT 300');
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
    $stmt = db()->prepare("UPDATE crm_marketing_send_queue SET send_status='cancelled', updated_at=NOW() WHERE task_id=? AND send_status IN ('pending','scheduled','waiting_retry')");
    $stmt->execute([$taskId]);
    db()->prepare("UPDATE crm_marketing_tasks SET task_status='cancelled', updated_at=NOW() WHERE id=?")->execute([$taskId]);
    crm_log_event('promotion', 'queue_cancel', 'marketing_task', (string)$taskId, null, ['affected' => $stmt->rowCount()]);
    return crm_marketing_queue_list(['task_id' => $taskId]);
}

function crm_marketing_queue_update_task_status(int $taskId): void
{
    $counts = crm_marketing_queue_status_counts($taskId);
    $active = ($counts['pending'] ?? 0) + ($counts['scheduled'] ?? 0) + ($counts['sending'] ?? 0) + ($counts['waiting_retry'] ?? 0);
    $failed = (int)($counts['failed'] ?? 0);
    $sent = (int)($counts['sent'] ?? 0);
    $status = $active > 0 ? 'running' : ($failed > 0 && $sent > 0 ? 'partial_failed' : ($failed > 0 ? 'failed' : 'completed'));
    db()->prepare('UPDATE crm_marketing_tasks SET task_status=?, success_count=?, failed_count=?, updated_at=NOW() WHERE id=?')->execute([$status, $sent, $failed, $taskId]);
}

function crm_marketing_queue_run_due(int $limit = 30): array
{
    crm_marketing_ensure_tables();
    crm_mail_ensure_tables();
    $limit = max(1, min(200, $limit));
    $stmt = db()->prepare("SELECT * FROM crm_marketing_send_queue WHERE send_status IN ('pending','scheduled','waiting_retry') AND planned_server_time <= NOW() ORDER BY planned_server_time ASC, id ASC LIMIT {$limit}");
    $stmt->execute();
    $rows = $stmt->fetchAll();
    $sent = 0; $failed = 0; $skipped = 0;
    foreach ($rows as $row) {
        $lock = db()->prepare("UPDATE crm_marketing_send_queue SET send_status='sending', send_attempts=send_attempts+1, updated_at=NOW() WHERE id=? AND send_status IN ('pending','scheduled','waiting_retry')");
        $lock->execute([(int)$row['id']]);
        if ($lock->rowCount() !== 1) continue;
        try {
            $accountStmt = db()->prepare('SELECT * FROM crm_user_mail_accounts WHERE email_address=? AND user_id=? AND deleted_at IS NULL AND is_enabled=1 LIMIT 1');
            $accountStmt->execute([(string)$row['sender_email'], (int)$row['sender_user_id']]);
            $account = $accountStmt->fetch();
            if (!$account) throw new RuntimeException('发件邮箱不可用');
            $account['mail_secret'] = crm_mail_decrypt($account['email_password_encrypted'] ?? '');
            unset($account['email_password_encrypted']);
            if ((string)$account['mail_secret'] === '') throw new RuntimeException('发件邮箱未配置 SMTP 密码');
            $result = crm_mail_smtp_send($account, ['to_emails' => (string)$row['receiver_email'], 'subject' => (string)$row['subject'], 'body_html' => (string)$row['body']], []);
            db()->prepare('INSERT INTO crm_mails (user_id, mail_account_id, folder, subject, from_email, from_name, to_emails, sent_at, body_html, body_text, has_body, has_attachment, attachment_count, linked_customer_id, linked_contact_id, tags_json, raw_headers_json, created_at, updated_at)
                VALUES (?, ?, "sent", ?, ?, ?, ?, NOW(), ?, ?, 1, 0, 0, ?, ?, ?, ?, NOW(), NOW())')
                ->execute([(int)$account['user_id'], (int)$account['id'], (string)$row['subject'], (string)$account['email_address'], (string)($account['sender_name'] ?: $account['email_address']), (string)$row['receiver_email'], (string)$row['body'], strip_tags((string)$row['body']), (int)$row['customer_id'], (int)($row['contact_id'] ?? 0) ?: null, json_encode(['推广队列'], JSON_UNESCAPED_UNICODE), json_encode(['marketing_queue_id' => (int)$row['id'], 'smtp_response' => $result['response'] ?? ''], JSON_UNESCAPED_UNICODE)]);
            db()->prepare("UPDATE crm_marketing_send_queue SET send_status='sent', last_error=NULL, sent_at=NOW(), updated_at=NOW() WHERE id=?")->execute([(int)$row['id']]);
            db()->prepare('INSERT INTO crm_marketing_logs (task_id, customer_id, contact_id, channel_key, action_key, result_status, failure_reason, operator_id, detail_json, touched_at, created_at) VALUES (?, ?, ?, "email", "queue_send", "success", "", ?, ?, NOW(), NOW())')
                ->execute([(int)$row['task_id'], (int)$row['customer_id'], (int)($row['contact_id'] ?? 0) ?: null, (int)$row['sender_user_id'], json_encode(['queue_id' => (int)$row['id'], 'sender_email' => $row['sender_email'], 'receiver_email' => $row['receiver_email'], 'smtp_response' => $result['response'] ?? ''], JSON_UNESCAPED_UNICODE)]);
            $sent++;
        } catch (Throwable $e) {
            $next = ((int)$row['send_attempts'] + 1) < (int)$row['max_attempts'] ? 'waiting_retry' : 'failed';
            $retryAt = $next === 'waiting_retry' ? ", planned_server_time=DATE_ADD(NOW(), INTERVAL 30 MINUTE)" : '';
            db()->prepare("UPDATE crm_marketing_send_queue SET send_status='{$next}', last_error=?, updated_at=NOW() {$retryAt} WHERE id=?")->execute([$e->getMessage(), (int)$row['id']]);
            db()->prepare('INSERT INTO crm_marketing_logs (task_id, customer_id, contact_id, channel_key, action_key, result_status, failure_reason, operator_id, detail_json, touched_at, created_at) VALUES (?, ?, ?, "email", "queue_send", "failed", ?, ?, ?, NOW(), NOW())')
                ->execute([(int)$row['task_id'], (int)$row['customer_id'], (int)($row['contact_id'] ?? 0) ?: null, $e->getMessage(), (int)$row['sender_user_id'], json_encode(['queue_id' => (int)$row['id'], 'sender_email' => $row['sender_email'], 'receiver_email' => $row['receiver_email']], JSON_UNESCAPED_UNICODE)]);
            $failed++;
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

function crm_marketing_task_set_status(array $input): array
{
    crm_marketing_ensure_tables();
    crm_require('promotion.execute');
    $taskId = (int)($input['task_id'] ?? 0);
    $status = trim((string)($input['status'] ?? ''));
    if ($taskId <= 0) throw new RuntimeException('任务 ID 无效。');
    if (!in_array($status, ['pending','scheduled','running','completed','partial_failed','failed','paused','cancelled','manual_pending'], true)) throw new RuntimeException('任务状态无效。');
    $before = db()->prepare('SELECT * FROM crm_marketing_tasks WHERE id = ? LIMIT 1');
    $before->execute([$taskId]);
    $row = $before->fetch();
    if (!$row) throw new RuntimeException('推广任务不存在。');
    db()->prepare('UPDATE crm_marketing_tasks SET task_status = ?, updated_at = NOW() WHERE id = ?')->execute([$status, $taskId]);
    crm_log_event('promotion', 'task_status_update', 'marketing_task', (string)$taskId, $row, ['task_status' => $status]);
    return ['tasks' => crm_marketing_tasks()];
}

function crm_marketing_task_execute(array $input): array
{
    crm_marketing_ensure_tables();
    crm_require('promotion.execute');
    $taskId = (int)($input['task_id'] ?? 0);
    if ($taskId <= 0) throw new RuntimeException('任务 ID 无效。');
    $taskStmt = db()->prepare('SELECT * FROM crm_marketing_tasks WHERE id = ? LIMIT 1');
    $taskStmt->execute([$taskId]);
    $task = $taskStmt->fetch();
    if (!$task) throw new RuntimeException('推广任务不存在。');
    if (in_array($task['task_status'], ['cancelled','completed'], true)) throw new RuntimeException('该任务已结束，不能执行。');
    db()->prepare('UPDATE crm_marketing_tasks SET task_status = "running", updated_at = NOW() WHERE id = ?')->execute([$taskId]);
    $stmt = db()->prepare("SELECT mt.*, c.customer_name, c.do_not_contact, COALESCE(ps.status, 'not_promoted') customer_status, ct.is_left,
        cp.status AS contact_channel_status
        FROM crm_marketing_task_targets mt
        JOIN crm_customers c ON c.id = mt.customer_id
        LEFT JOIN crm_customer_promotion_status ps ON ps.customer_id = c.id
        LEFT JOIN crm_contacts ct ON ct.id = mt.contact_id
        LEFT JOIN crm_contact_promotions cp ON cp.contact_id = mt.contact_id AND cp.channel = mt.channel_key
        WHERE mt.task_id = ? AND mt.target_status IN ('pending','failed') AND LOWER(mt.channel_key) NOT IN ('wechat_group','whatsapp_group')");
    $stmt->execute([$taskId]);
    $targets = $stmt->fetchAll();
    if (!$targets) {
        db()->prepare('UPDATE crm_marketing_tasks SET task_status = "pending", updated_at = NOW() WHERE id = ?')->execute([$taskId]);
        throw new RuntimeException('微信群 / WhatsApp群推广必须在手动执行清单中逐条勾选，不能自动执行。');
    }
    $success = 0;
    $failed = 0;
    $updateTarget = db()->prepare('UPDATE crm_marketing_task_targets SET target_status = ?, failure_reason = ?, executed_at = NOW() WHERE id = ?');
    $sendRule = crm_marketing_decode_json_input($task['send_rule_json'] ?? []);
    $attachmentConfig = crm_marketing_decode_json_input($task['attachment_config_json'] ?? []);
    $failurePolicy = crm_marketing_decode_json_input($task['failure_policy_json'] ?? []);
    $log = db()->prepare('INSERT INTO crm_marketing_logs (task_id, customer_id, contact_id, channel_key, action_key, result_status, failure_reason, operator_id, detail_json, touched_at, created_at) VALUES (?, ?, ?, ?, "task_execute", ?, ?, ?, ?, NOW(), NOW())');
    foreach ($targets as $target) {
        $reason = '';
        if ((int)$target['do_not_contact'] === 1) $reason = '客户禁止联系';
        elseif (in_array((string)$target['customer_status'], ['blacklist','stopped','no_promotion','maintenance_only'], true)) $reason = '客户推广状态禁止执行：' . $target['customer_status'];
        elseif ($target['contact_id'] && (int)$target['is_left'] === 1) $reason = '联系人已离职';
        elseif ($target['contact_id'] && in_array((string)$target['contact_channel_status'], ['stopped','no_contact','paused'], true)) $reason = '联系人渠道策略禁止执行：' . $target['contact_channel_status'];
        if ($reason !== '') {
            $failed++;
            $updateTarget->execute(['failed', $reason, (int)$target['id']]);
            $detail = [
                'target' => $target,
                'send_rule' => $sendRule,
                'attachment' => $attachmentConfig,
                'failure_policy' => $failurePolicy,
                'linkage_plan' => ['失败处理中心', '生成跟进', '资料/报价复盘', '派工异常处理'],
            ];
            $log->execute([$taskId, (int)$target['customer_id'], $target['contact_id'] ? (int)$target['contact_id'] : null, (string)$target['channel_key'], 'failed', $reason, current_user()['id'] ?? null, json_encode($detail, JSON_UNESCAPED_UNICODE)]);
            continue;
        }
        $success++;
        $updateTarget->execute(['success', '', (int)$target['id']]);
        $detail = [
            'target' => $target,
            'send_rule' => $sendRule,
            'attachment' => $attachmentConfig,
            'execution_mode' => 'crm_internal_execution',
            'linkage_plan' => ['推广记录', '资料联动', '报价跟进', '派工跟进'],
        ];
        $log->execute([$taskId, (int)$target['customer_id'], $target['contact_id'] ? (int)$target['contact_id'] : null, (string)$target['channel_key'], 'success', '', current_user()['id'] ?? null, json_encode($detail, JSON_UNESCAPED_UNICODE)]);
        if ($target['contact_id']) {
            db()->prepare('UPDATE crm_contact_promotions SET last_contact_time = NOW(), updated_by = ?, updated_at = NOW() WHERE contact_id = ? AND channel = ?')
                ->execute([current_user()['id'] ?? null, (int)$target['contact_id'], (string)$target['channel_key']]);
        }
    }
    $finalStatus = $failed > 0 && $success > 0 ? 'partial_failed' : ($failed > 0 ? 'partial_failed' : 'completed');
    db()->prepare('UPDATE crm_marketing_tasks SET task_status = ?, success_count = success_count + ?, failed_count = failed_count + ?, updated_at = NOW() WHERE id = ?')
        ->execute([$finalStatus, $success, $failed, $taskId]);
    crm_log_event('promotion', 'task_execute', 'marketing_task', (string)$taskId, null, ['success' => $success, 'failed' => $failed]);
    return ['success_count' => $success, 'failed_count' => $failed, 'tasks' => crm_marketing_tasks(), 'logs' => crm_marketing_logs(), 'targets' => crm_marketing_task_targets(['task_id' => $taskId]), 'failed_targets' => crm_marketing_task_targets(['status' => 'failed']), 'analytics' => crm_can('promotion.analytics') ? crm_marketing_analytics() : []];
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
    $manualChannels = ['wechat', 'weixin', 'wechat_group', 'whatsapp', 'whatsapp_group', 'phone', 'offline', 'visit', 'linkedin'];
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
    $update = db()->prepare('UPDATE crm_marketing_task_targets SET target_status = ?, failure_reason = ?, manual_result = ?, manual_remark = ?, manual_attachment_json = ?, executor_user_id = ?, executed_at = NOW() WHERE id = ?');
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
    db()->prepare('UPDATE crm_marketing_tasks SET success_count = ?, failed_count = ?, task_status = ?, updated_at = NOW() WHERE id = ?')
        ->execute([(int)($counts['success_count'] ?? 0), (int)($counts['failed_count'] ?? 0), $nextStatus, $taskId]);
    crm_log_event('promotion', 'manual_execute', 'marketing_task', (string)$taskId, null, ['target_ids' => $targetIds, 'checked_count' => count($targets), 'manual_status' => $manualStatus, 'manual_result' => $manualResult]);
    return ['checked_count' => count($targets), 'tasks' => crm_marketing_tasks(), 'logs' => crm_marketing_logs(), 'targets' => crm_marketing_task_targets(['task_id' => $taskId]), 'failed_targets' => crm_marketing_task_targets(['status' => 'failed']), 'analytics' => crm_can('promotion.analytics') ? crm_marketing_analytics() : []];
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

    $sendInput = [
        'to_emails' => $testEmail,
        'subject' => '[推广测试] ' . $subject,
        'body_html' => '<div style="font-size:12px;color:#6b7280;margin-bottom:12px;border-bottom:1px solid #e5e7eb;padding-bottom:8px;">这是一封推广任务预览测试邮件，仅用于检查客户变量、称呼和正文显示，不会写入正式推广执行结果。</div>' . crm_mail_render_signature_variables($body, $account),
    ];
    $sendResult = crm_mail_smtp_send($account, $sendInput, []);

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
        'smtp_response' => (string)($sendResult['response'] ?? ''),
    ];
    db()->prepare('INSERT INTO crm_marketing_logs (task_id, customer_id, contact_id, channel_key, action_key, result_status, failure_reason, operator_id, detail_json, touched_at, created_at) VALUES (?, ?, ?, "email", "test_send", "success", "", ?, ?, NOW(), NOW())')
        ->execute([(int)($input['task_id'] ?? 0) ?: null, $detail['customer_id'], $detail['contact_id'], current_user()['id'] ?? null, json_encode($detail, JSON_UNESCAPED_UNICODE)]);
    crm_log_event('promotion', 'test_send', 'marketing_task', (string)((int)($input['task_id'] ?? 0) ?: 0), null, $detail);

    return [
        'sent_to' => $testEmail,
        'send_email' => (string)$account['email_address'],
        'customer_name' => $detail['customer_name'],
        'response' => (string)($sendResult['response'] ?? ''),
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
    if (!in_array($mode, ['retry','resolved','followup','dispatch','quote','material'], true)) $mode = 'resolved';
    $stmt = db()->prepare('SELECT * FROM crm_marketing_task_targets WHERE id = ? LIMIT 1');
    $stmt->execute([$targetId]);
    $target = $stmt->fetch();
    if (!$target) throw new RuntimeException('失败目标不存在。');
    $nextStatus = $mode === 'retry' ? 'pending' : 'handled';
    $reason = $mode === 'retry' ? '' : ('已处理：' . $mode);
    db()->prepare('UPDATE crm_marketing_task_targets SET target_status = ?, failure_reason = ?, executed_at = NOW() WHERE id = ?')
        ->execute([$nextStatus, $reason, $targetId]);
    db()->prepare('INSERT INTO crm_marketing_logs (task_id, customer_id, contact_id, channel_key, action_key, result_status, failure_reason, operator_id, detail_json, touched_at, created_at) VALUES (?, ?, ?, ?, ?, "success", ?, ?, ?, NOW(), NOW())')
        ->execute([(int)$target['task_id'], (int)$target['customer_id'], $target['contact_id'] ? (int)$target['contact_id'] : null, (string)$target['channel_key'], 'failure_' . $mode, $reason, current_user()['id'] ?? null, json_encode(['target' => $target, 'mode' => $mode], JSON_UNESCAPED_UNICODE)]);
    crm_log_event('promotion', 'failure_handle', 'marketing_target', (string)$targetId, $target, ['mode' => $mode, 'status' => $nextStatus]);
    return ['targets' => crm_marketing_task_targets(['task_id' => (int)$target['task_id']]), 'failed_targets' => crm_marketing_task_targets(['status' => 'failed']), 'logs' => crm_marketing_logs(), 'tasks' => crm_marketing_tasks()];
}
