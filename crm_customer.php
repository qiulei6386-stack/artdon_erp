<?php
require_once __DIR__ . '/crm_settings_config.php';

function crm_add_column_safe(string $table, string $column, string $definition): void
{
    if (!crm_setting_column_exists($table, $column)) {
        db()->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }
}

function crm_add_index_safe(string $table, string $index, string $definition): void
{
    $stmt = db()->prepare('SHOW INDEX FROM `' . $table . '` WHERE Key_name = ?');
    $stmt->execute([$index]);
    if (!$stmt->fetchColumn()) {
        db()->exec("ALTER TABLE {$table} ADD KEY {$index} {$definition}");
    }
}

function crm_customer_ensure_tables(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    db()->exec("CREATE TABLE IF NOT EXISTS crm_customers (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_code VARCHAR(80) NULL,
        customer_name VARCHAR(190) NOT NULL,
        customer_name_en VARCHAR(190) NULL,
        country VARCHAR(120) NOT NULL,
        city VARCHAR(120) NULL,
        address VARCHAR(500) NULL,
        website VARCHAR(255) NULL,
        email VARCHAR(190) NULL,
        phone VARCHAR(80) NULL,
        whatsapp VARCHAR(80) NULL,
        source VARCHAR(80) NULL,
        level VARCHAR(40) NULL,
        status VARCHAR(40) NOT NULL DEFAULT 'active',
        owner_user_id INT UNSIGNED NULL,
        owner_department VARCHAR(120) NULL,
        created_by INT UNSIGNED NULL,
        updated_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        deleted_at DATETIME NULL,
        deleted_by INT UNSIGNED NULL,
        delete_reason VARCHAR(500) NULL,
        remark TEXT NULL,
        KEY idx_customer_owner (owner_user_id),
        KEY idx_customer_country (country),
        KEY idx_customer_status (status),
        KEY idx_customer_deleted (deleted_at),
        KEY idx_customer_updated (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS crm_contacts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        name VARCHAR(120) NOT NULL,
        name_en VARCHAR(120) NULL,
        position VARCHAR(120) NULL,
        department VARCHAR(120) NULL,
        email VARCHAR(190) NULL,
        phone VARCHAR(80) NULL,
        whatsapp VARCHAR(80) NULL,
        wechat VARCHAR(120) NULL,
        linkedin VARCHAR(255) NULL,
        gender VARCHAR(20) NULL,
        birthday DATE NULL,
        language VARCHAR(80) NULL,
        is_primary TINYINT(1) NOT NULL DEFAULT 0,
        is_left TINYINT(1) NOT NULL DEFAULT 0,
        remark TEXT NULL,
        created_by INT UNSIGNED NULL,
        updated_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        deleted_at DATETIME NULL,
        deleted_by INT UNSIGNED NULL,
        KEY idx_contact_customer (customer_id),
        KEY idx_contact_deleted (deleted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    foreach ([
        ['crm_customers', 'lifecycle_key', 'VARCHAR(120) NOT NULL DEFAULT "lead"'],
        ['crm_customers', 'risk_level', 'VARCHAR(120) NOT NULL DEFAULT "healthy"'],
        ['crm_customers', 'do_not_contact', 'TINYINT(1) NOT NULL DEFAULT 0'],
        ['crm_customers', 'blacklist_reason', 'VARCHAR(500) NULL'],
        ['crm_customers', 'customer_type', 'VARCHAR(120) NULL'],
        ['crm_customers', 'industry', 'VARCHAR(120) NULL'],
        ['crm_customers', 'backup_email', 'VARCHAR(190) NULL'],
        ['crm_customers', 'wechat', 'VARCHAR(120) NULL'],
        ['crm_customers', 'linkedin', 'VARCHAR(255) NULL'],
        ['crm_customers', 'customer_domain', 'VARCHAR(190) NULL'],
        ['crm_customers', 'postal_code', 'VARCHAR(60) NULL'],
        ['crm_customers', 'shipping_address', 'VARCHAR(500) NULL'],
        ['crm_customers', 'billing_address', 'VARCHAR(500) NULL'],
        ['crm_customers', 'common_port', 'VARCHAR(120) NULL'],
        ['crm_customers', 'timezone', 'VARCHAR(120) NULL'],
        ['crm_customers', 'no_promotion_reason', 'VARCHAR(500) NULL'],
        ['crm_customers', 'customer_tags', 'VARCHAR(500) NULL'],
        ['crm_customers', 'visibility_scope', 'VARCHAR(80) NULL'],
        ['crm_contacts', 'do_not_contact', 'TINYINT(1) NOT NULL DEFAULT 0'],
        ['crm_contacts', 'unsubscribe_email', 'TINYINT(1) NOT NULL DEFAULT 0'],
        ['crm_contacts', 'no_whatsapp', 'TINYINT(1) NOT NULL DEFAULT 0'],
    ] as $columnDef) {
        crm_add_column_safe($columnDef[0], $columnDef[1], $columnDef[2]);
    }

    db()->exec("CREATE TABLE IF NOT EXISTS crm_customer_addresses (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        address_type ENUM('HQ','Office','Factory','Warehouse','Other') NOT NULL DEFAULT 'Other',
        country VARCHAR(120) NOT NULL,
        city VARCHAR(120) NULL,
        address VARCHAR(500) NULL,
        is_primary TINYINT(1) NOT NULL DEFAULT 0,
        created_by INT UNSIGNED NULL,
        updated_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_customer_address_customer (customer_id),
        KEY idx_customer_address_primary (customer_id, is_primary),
        KEY idx_customer_address_country (country)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    foreach ([
        ['crm_customer_addresses', 'postal_code', 'VARCHAR(60) NULL'],
        ['crm_customer_addresses', 'contact_name', 'VARCHAR(120) NULL'],
        ['crm_customer_addresses', 'phone', 'VARCHAR(80) NULL'],
        ['crm_customer_addresses', 'deleted_at', 'DATETIME NULL'],
        ['crm_customer_addresses', 'deleted_by', 'INT UNSIGNED NULL'],
    ] as $columnDef) {
        crm_add_column_safe($columnDef[0], $columnDef[1], $columnDef[2]);
    }

    db()->exec("CREATE TABLE IF NOT EXISTS crm_customer_source_tags (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        source_key ENUM('exhibition','website','whatsapp','linkedin','google','ads','referral','agent','manual') NOT NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_customer_source (customer_id, source_key),
        KEY idx_customer_source_key (source_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS crm_customer_promotion_channels (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        channel_key ENUM('email','whatsapp','wechat','wechat_group','whatsapp_group','linkedin','edm','ads','visit','manual_sales','automation','no_promotion') NOT NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_customer_promotion_channel (customer_id, channel_key),
        KEY idx_customer_promotion_channel (channel_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $channelColumn = db()->query("SHOW COLUMNS FROM crm_customer_promotion_channels LIKE 'channel_key'")->fetch();
    $channelType = (string)($channelColumn['Type'] ?? '');
    if (strpos($channelType, 'wechat_group') === false || strpos($channelType, 'whatsapp_group') === false || strpos($channelType, 'no_promotion') === false) {
        db()->exec("ALTER TABLE crm_customer_promotion_channels MODIFY channel_key ENUM('email','whatsapp','wechat','wechat_group','whatsapp_group','linkedin','edm','ads','visit','manual_sales','automation','no_promotion') NOT NULL");
    }

    db()->exec("CREATE TABLE IF NOT EXISTS crm_customer_chat_groups (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        group_name VARCHAR(190) NOT NULL,
        group_platform ENUM('wechat_group','whatsapp_group') NOT NULL DEFAULT 'wechat_group',
        group_owner VARCHAR(120) NULL,
        linked_contact_ids_json JSON NULL,
        use_for_promotion TINYINT(1) NOT NULL DEFAULT 1,
        status ENUM('active','paused','invalid') NOT NULL DEFAULT 'active',
        remark VARCHAR(500) NULL,
        last_promoted_at DATETIME NULL,
        created_by INT UNSIGNED NULL,
        updated_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        deleted_at DATETIME NULL,
        deleted_by INT UNSIGNED NULL,
        KEY idx_customer_chat_group_customer (customer_id),
        KEY idx_customer_chat_group_platform (group_platform),
        KEY idx_customer_chat_group_promotion (use_for_promotion, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach ([
        ['crm_customer_chat_groups', 'group_platform', "ENUM('wechat_group','whatsapp_group') NOT NULL DEFAULT 'wechat_group' AFTER group_name"],
        ['crm_customer_chat_groups', 'group_owner', "VARCHAR(120) NULL AFTER group_platform"],
        ['crm_customer_chat_groups', 'linked_contact_ids_json', "JSON NULL AFTER group_owner"],
        ['crm_customer_chat_groups', 'use_for_promotion', "TINYINT(1) NOT NULL DEFAULT 1 AFTER linked_contact_ids_json"],
        ['crm_customer_chat_groups', 'status', "ENUM('active','paused','invalid') NOT NULL DEFAULT 'active' AFTER use_for_promotion"],
        ['crm_customer_chat_groups', 'deleted_by', "INT UNSIGNED NULL AFTER deleted_at"],
    ] as $columnDef) {
        crm_add_column_safe($columnDef[0], $columnDef[1], $columnDef[2]);
    }
    db()->exec("UPDATE crm_customer_chat_groups SET group_platform = CASE WHEN group_type LIKE '%whatsapp%' OR group_type LIKE '%WhatsApp%' THEN 'whatsapp_group' ELSE 'wechat_group' END WHERE group_platform IS NULL OR group_platform = ''");
    db()->exec("UPDATE crm_customer_chat_groups SET group_owner = COALESCE(group_owner, group_no, '') WHERE group_owner IS NULL OR group_owner = ''");
    db()->exec("UPDATE crm_customer_chat_groups SET status = IF(is_active = 0, 'paused', 'active') WHERE status IS NULL OR status = ''");

    db()->exec("CREATE TABLE IF NOT EXISTS crm_customer_promotion_status (
        customer_id INT UNSIGNED PRIMARY KEY,
        status ENUM('not_promoted','promoting','active','paused','stopped','blacklist','maintenance_only') NOT NULL DEFAULT 'not_promoted',
        updated_by INT UNSIGNED NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS crm_customer_owners (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        role_type ENUM('primary','secondary','collaborator','viewer') NOT NULL DEFAULT 'viewer',
        is_primary TINYINT(1) NOT NULL DEFAULT 0,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_customer_owner_user (customer_id, user_id),
        KEY idx_customer_owner_customer (customer_id),
        KEY idx_customer_owner_user (user_id),
        KEY idx_customer_owner_primary (customer_id, is_primary)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS crm_contact_role_tags (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        contact_id INT UNSIGNED NOT NULL,
        role_key ENUM('decision_maker','buyer','engineer','finance','boss','project_owner','middleman') NOT NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_contact_role (contact_id, role_key),
        KEY idx_contact_role_key (role_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS crm_contact_sources (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        contact_id INT UNSIGNED NOT NULL,
        source_key ENUM('exhibition','website','linkedin','whatsapp','referral','manual','import') NOT NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_contact_source (contact_id, source_key),
        KEY idx_contact_source_key (source_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS crm_contact_promotions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        contact_id INT UNSIGNED NOT NULL,
        channel ENUM('email','whatsapp','wechat','phone','linkedin','offline','edm','google_ads','social','referral','agent_dev','visit','wechat_group','whatsapp_group','manual_sales','automation','no_promotion','maintenance_only') NOT NULL,
        status ENUM('active','stopped','no_contact') NOT NULL DEFAULT 'active',
        last_contact_time DATETIME NULL,
        updated_by INT UNSIGNED NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_contact_promotion (contact_id, channel),
        KEY idx_contact_promotion_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("ALTER TABLE crm_contact_promotions MODIFY channel ENUM('email','whatsapp','wechat','phone','linkedin','offline','edm','google_ads','social','referral','agent_dev','visit','wechat_group','whatsapp_group','manual_sales','automation','no_promotion','maintenance_only') NOT NULL");

    db()->exec("CREATE TABLE IF NOT EXISTS crm_lead_pool (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        raw_name VARCHAR(190) NOT NULL,
        raw_email VARCHAR(190) NULL,
        raw_phone VARCHAR(80) NULL,
        raw_country VARCHAR(120) NULL,
        raw_domain VARCHAR(190) NULL,
        payload_json JSON NULL,
        similarity_matches_json JSON NULL,
        status ENUM('pending','confirmed','merged','rejected') NOT NULL DEFAULT 'pending',
        target_customer_id INT UNSIGNED NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_lead_pool_status (status),
        KEY idx_lead_pool_email (raw_email),
        KEY idx_lead_pool_domain (raw_domain)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS crm_customer_scores (
        customer_id INT UNSIGNED PRIMARY KEY,
        health_score INT NOT NULL DEFAULT 60,
        activity_score INT NOT NULL DEFAULT 50,
        deal_probability INT NOT NULL DEFAULT 30,
        followup_risk INT NOT NULL DEFAULT 40,
        completeness_score INT NOT NULL DEFAULT 0,
        score_json JSON NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS crm_customer_timeline (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        event_type VARCHAR(120) NOT NULL,
        title VARCHAR(190) NOT NULL,
        detail VARCHAR(1000) NULL,
        related_type VARCHAR(80) NULL,
        related_id VARCHAR(120) NULL,
        event_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_timeline_customer (customer_id),
        KEY idx_timeline_time (event_time)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS crm_customer_relations (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        related_customer_id INT UNSIGNED NOT NULL,
        relation_type VARCHAR(120) NOT NULL,
        remark VARCHAR(500) NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_customer_relation (customer_id, related_customer_id, relation_type),
        KEY idx_relation_customer (customer_id),
        KEY idx_relation_related (related_customer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS crm_duplicate_merge_cases (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        master_customer_id INT UNSIGNED NULL,
        duplicate_customer_ids_json JSON NULL,
        match_type VARCHAR(80) NOT NULL,
        similarity_score INT NOT NULL DEFAULT 0,
        status VARCHAR(40) NOT NULL DEFAULT 'pending',
        merge_plan_json JSON NULL,
        merged_by INT UNSIGNED NULL,
        merged_at DATETIME NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_merge_status (status),
        KEY idx_merge_master (master_customer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS crm_customer_protection (
        customer_id INT UNSIGNED PRIMARY KEY,
        protection_status VARCHAR(80) NOT NULL DEFAULT 'normal',
        protected_until DATETIME NULL,
        reason VARCHAR(500) NULL,
        public_pool_at DATETIME NULL,
        claimed_by INT UNSIGNED NULL,
        claimed_at DATETIME NULL,
        updated_by INT UNSIGNED NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS crm_customer_events (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        event_type VARCHAR(120) NOT NULL,
        title VARCHAR(190) NOT NULL,
        event_time DATETIME NULL,
        remind_time DATETIME NULL,
        status VARCHAR(40) NOT NULL DEFAULT 'open',
        remark VARCHAR(500) NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_customer_event_customer (customer_id),
        KEY idx_customer_event_time (event_time)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS crm_customer_product_preferences (
        customer_id INT UNSIGNED PRIMARY KEY,
        category VARCHAR(160) NULL,
        series VARCHAR(160) NULL,
        power VARCHAR(120) NULL,
        cct VARCHAR(120) NULL,
        cri VARCHAR(120) NULL,
        beam_angle VARCHAR(120) NULL,
        color VARCHAR(120) NULL,
        price_range VARCHAR(120) NULL,
        certification VARCHAR(255) NULL,
        project_type VARCHAR(160) NULL,
        market_region VARCHAR(160) NULL,
        updated_by INT UNSIGNED NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS crm_customer_communication_preferences (
        customer_id INT UNSIGNED PRIMARY KEY,
        preferred_channel VARCHAR(120) NULL,
        backup_channel VARCHAR(120) NULL,
        language VARCHAR(80) NULL,
        timezone VARCHAR(120) NULL,
        best_contact_time VARCHAR(120) NULL,
        accept_bulk TINYINT(1) NOT NULL DEFAULT 1,
        accept_quote_email TINYINT(1) NOT NULL DEFAULT 1,
        accept_material_package TINYINT(1) NOT NULL DEFAULT 1,
        updated_by INT UNSIGNED NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS crm_contact_communication_preferences (
        contact_id INT UNSIGNED PRIMARY KEY,
        preferred_channel VARCHAR(120) NULL,
        backup_channel VARCHAR(120) NULL,
        language VARCHAR(80) NULL,
        timezone VARCHAR(120) NULL,
        best_contact_time VARCHAR(120) NULL,
        accept_bulk TINYINT(1) NOT NULL DEFAULT 1,
        accept_quote_email TINYINT(1) NOT NULL DEFAULT 1,
        accept_material_package TINYINT(1) NOT NULL DEFAULT 1,
        updated_by INT UNSIGNED NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS crm_sensitive_audit_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NULL,
        action_key VARCHAR(120) NOT NULL,
        target_type VARCHAR(80) NULL,
        target_id VARCHAR(120) NULL,
        before_json JSON NULL,
        after_json JSON NULL,
        ip_address VARCHAR(64) NULL,
        user_agent VARCHAR(500) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_sensitive_action (action_key),
        KEY idx_sensitive_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS crm_customer_groups (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        group_name VARCHAR(120) NOT NULL,
        group_color VARCHAR(40) NULL,
        sort_order INT NOT NULL DEFAULT 100,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        deleted_at DATETIME NULL,
        UNIQUE KEY uk_customer_group_name (group_name),
        KEY idx_customer_group_deleted (deleted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS crm_customer_group_relations (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        group_id INT UNSIGNED NOT NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_customer_group_relation (customer_id, group_id),
        KEY idx_customer_group_customer (customer_id),
        KEY idx_customer_group_group (group_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS crm_customer_followups (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        contact_id INT UNSIGNED NULL,
        followup_time DATETIME NOT NULL,
        followup_type VARCHAR(40) NOT NULL DEFAULT 'other',
        content TEXT NOT NULL,
        next_plan TEXT NULL,
        next_remind_time DATETIME NULL,
        status VARCHAR(40) NOT NULL DEFAULT 'open',
        related_email_id INT UNSIGNED NULL,
        related_quote_id INT UNSIGNED NULL,
        created_by INT UNSIGNED NULL,
        updated_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        deleted_at DATETIME NULL,
        KEY idx_follow_customer (customer_id),
        KEY idx_follow_deleted (deleted_at),
        KEY idx_follow_time (followup_time)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS crm_customer_files (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id INT UNSIGNED NOT NULL,
        file_name VARCHAR(190) NOT NULL,
        file_path VARCHAR(500) NOT NULL,
        file_type VARCHAR(80) NULL,
        file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
        category VARCHAR(80) NULL,
        remark VARCHAR(500) NULL,
        uploaded_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        deleted_at DATETIME NULL,
        deleted_by INT UNSIGNED NULL,
        KEY idx_file_customer (customer_id),
        KEY idx_file_deleted (deleted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    crm_add_index_safe('crm_customers', 'idx_customer_list_updated', '(deleted_at, updated_at, id)');
    crm_add_index_safe('crm_customers', 'idx_customer_list_created', '(deleted_at, created_at, id)');
    crm_add_index_safe('crm_customers', 'idx_customer_lifecycle', '(lifecycle_key, deleted_at)');
    crm_add_index_safe('crm_contacts', 'idx_contact_customer_deleted_email', '(customer_id, deleted_at, email)');
    crm_add_index_safe('crm_contacts', 'idx_contact_search', '(customer_id, deleted_at, name, email, phone, whatsapp)');
    crm_add_index_safe('crm_customer_followups', 'idx_follow_customer_deleted_time', '(customer_id, deleted_at, followup_time)');
    crm_add_index_safe('crm_customer_group_relations', 'idx_customer_group_customer_group', '(customer_id, group_id)');

    db()->exec("CREATE TABLE IF NOT EXISTS crm_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NULL,
        module VARCHAR(80) NOT NULL,
        action VARCHAR(120) NOT NULL,
        object_type VARCHAR(80) NULL,
        object_id VARCHAR(120) NULL,
        customer_id INT UNSIGNED NULL,
        before_json JSON NULL,
        after_json JSON NULL,
        message VARCHAR(500) NULL,
        ip_address VARCHAR(64) NULL,
        user_agent VARCHAR(500) NULL,
        success TINYINT(1) NOT NULL DEFAULT 1,
        error_message VARCHAR(500) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_crm_logs_customer (customer_id),
        KEY idx_crm_logs_module (module),
        KEY idx_crm_logs_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    crm_customer_ensure_permissions();
}

function crm_graph_options(): array
{
    return [
        'levels' => crm_dictionary_keys('customer_level'),
        'statuses' => crm_dictionary_keys('customer_status'),
        'source_tags' => crm_dictionary_keys('customer_source'),
        'promotion_channels' => crm_dictionary_keys('promotion_channel'),
        'promotion_statuses' => crm_dictionary_keys('promotion_status'),
        'address_types' => crm_dictionary_keys('address_type'),
        'contact_role_tags' => crm_dictionary_keys('contact_role'),
        'contact_sources' => crm_dictionary_keys('contact_source'),
        'contact_promotion_statuses' => crm_dictionary_keys('promotion_status'),
        'owner_roles' => crm_dictionary_keys('owner_role'),
    ];
}

function crm_parse_keys($value, array $allowed): array
{
    if (is_string($value)) $value = preg_split('/[,\s，]+/u', $value, -1, PREG_SPLIT_NO_EMPTY);
    $result = [];
    foreach ((array)$value as $item) {
        $key = trim((string)$item);
        if ($key !== '' && in_array($key, $allowed, true)) $result[] = $key;
    }
    return array_values(array_unique($result));
}

function crm_domain_from_value(string $value): string
{
    $value = trim(strtolower($value));
    if ($value === '') return '';
    if (strpos($value, '@') !== false) return substr(strrchr($value, '@'), 1) ?: '';
    $host = parse_url(preg_match('/^https?:\/\//', $value) ? $value : 'https://' . $value, PHP_URL_HOST);
    return preg_replace('/^www\./', '', (string)$host);
}

function crm_similarity_percent(string $a, string $b): int
{
    $a = mb_strtolower(trim($a));
    $b = mb_strtolower(trim($b));
    if ($a === '' || $b === '') return 0;
    similar_text($a, $b, $percent);
    return (int)round($percent);
}

function crm_customer_ensure_permissions(): void
{
    $permissions = [
        ['customer.view_all','customer','view_all','查看全部客户','high'],
        ['customer.view_department','customer','view_department','查看本部门客户','medium'],
        ['customer.delete','customer','delete','删除客户','dangerous'],
        ['customer.force_delete','customer','force_delete','强制删除客户','dangerous'],
        ['customer.restore','customer','restore','恢复客户','high'],
        ['customer.transfer_public','customer','transfer_public','转入公海','high'],
        ['customer.claim_public','customer','claim_public','领取公海客户','medium'],
        ['customer.import','customer','import','导入客户','high'],
        ['customer.export','customer','export','导出客户','dangerous'],
        ['customer.batch','customer','batch','客户批量操作','high'],
        ['customer.lead_pool_view','customer','lead_pool_view','查看客户暂存池','medium'],
        ['customer.lead_pool','customer','lead_pool','客户暂存池处理','high'],
        ['customer.merge','customer','merge','合并客户','dangerous'],
        ['customer.graph_manage','customer','graph_manage','客户关系图谱管理','high'],
        ['customer.timeline_view','customer','timeline_view','查看客户时间轴','medium'],
        ['customer.event_manage','customer','event_manage','管理客户重要事件','high'],
        ['customer.public_pool','customer','public_pool','客户公海规则操作','dangerous'],
        ['customer.blacklist','customer','blacklist','加入或移出黑名单','dangerous'],
        ['customer.sensitive_audit','customer','sensitive_audit','查看客户敏感操作审计','dangerous'],
        ['customer.mail_summary','customer','mail_summary','查看客户邮件摘要','medium'],
        ['customer.quote_summary','customer','quote_summary','查看客户报价摘要','medium'],
        ['customer.plm_summary','customer','plm_summary','查看客户 PLM 摘要','medium'],
        ['customer.bom_summary','customer','bom_summary','查看客户 BOM 摘要','high'],
        ['customer.dispatch_summary','customer','dispatch_summary','查看客户派工摘要','medium'],
        ['customer.order_summary','customer','order_summary','查看客户订单摘要','medium'],
        ['customer.material_summary','customer','material_summary','查看客户资料摘要','medium'],
        ['contact.delete','contact','delete','删除联系人','high'],
        ['contact.promotion_manage','contact','promotion_manage','联系人推广策略管理','high'],
        ['follow.delete','follow','delete','删除跟进','high'],
        ['customer.file_upload','customer','file_upload','上传客户文件','medium'],
        ['customer.file_delete','customer','file_delete','删除客户文件','high'],
    ];
    $stmt = db()->prepare('INSERT IGNORE INTO crm_permissions (permission_key, module, action, description, risk_level, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
    foreach ($permissions as $permission) $stmt->execute($permission);
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key) SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p WHERE r.role_key = 'super_admin' AND p.module IN ('customer','contact','follow')");
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key) SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p WHERE r.role_key = 'admin' AND p.permission_key IN ('customer.view_all','customer.create','customer.edit','customer.delete','customer.force_delete','customer.restore','customer.assign','customer.batch','customer.lead_pool_view','customer.lead_pool','customer.merge','customer.graph_manage','customer.mail_summary','customer.quote_summary','customer.plm_summary','customer.bom_summary','customer.dispatch_summary','customer.order_summary','customer.material_summary','contact.view','contact.create','contact.edit','contact.delete','contact.promotion_manage','follow.view','follow.create','follow.edit','follow.delete','customer.view_logs','customer.export')");
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key) SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p WHERE r.role_key = 'manager' AND p.permission_key IN ('customer.view','customer.view_all','customer.view_department','customer.create','customer.edit','customer.assign','customer.batch','customer.lead_pool_view','customer.lead_pool','customer.claim_public','customer.mail_summary','customer.quote_summary','customer.plm_summary','customer.dispatch_summary','customer.order_summary','customer.material_summary','customer.timeline_view','customer.file_upload','contact.view','contact.create','contact.edit','follow.view','follow.create','follow.edit','customer.view_logs')");
}

function crm_customer_detail_tabs(array $customer): array
{
    $config = crm_rule_config('customer_detail_tabs');
    $tabs = $config['tabs'] ?? [];
    $existingTabKeys = array_column($tabs, 'key');
    foreach ([
        ['key' => 'visits', 'name' => '拜访 / 来访', 'short' => '拜访', 'icon' => 'VS', 'enabled' => 1, 'sort' => 35, 'permission' => 'visit.view', 'lifecycles' => ['*'], 'lifecycle_mode' => 'all', 'readonly' => 0],
        ['key' => 'opportunities', 'name' => '商机', 'short' => '商机', 'icon' => 'OP', 'enabled' => 1, 'sort' => 37, 'permission' => 'opportunity.view', 'lifecycles' => ['*'], 'lifecycle_mode' => 'all', 'readonly' => 0],
        ['key' => 'samples', 'name' => '样品 / 寄送', 'short' => '样品', 'icon' => 'SP', 'enabled' => 1, 'sort' => 38, 'permission' => 'sample.view', 'lifecycles' => ['*'], 'lifecycle_mode' => 'all', 'readonly' => 0],
        ['key' => 'documents', 'name' => '单证', 'short' => '单证', 'icon' => 'DC', 'enabled' => 1, 'sort' => 92, 'permission' => 'customer.order_summary', 'lifecycles' => ['*'], 'lifecycle_mode' => 'all', 'readonly' => 1],
        ['key' => 'shipments', 'name' => '出货进度', 'short' => '出货', 'icon' => 'SP', 'enabled' => 1, 'sort' => 94, 'permission' => 'customer.order_summary', 'lifecycles' => ['*'], 'lifecycle_mode' => 'all', 'readonly' => 1],
        ['key' => 'receivables', 'name' => '收款欠款', 'short' => '收款', 'icon' => 'AR', 'enabled' => 1, 'sort' => 96, 'permission' => 'customer.order_summary', 'lifecycles' => ['*'], 'lifecycle_mode' => 'all', 'readonly' => 1],
    ] as $requiredTab) {
        if (!in_array($requiredTab['key'], $existingTabKeys, true)) {
            $tabs[] = $requiredTab;
            $existingTabKeys[] = $requiredTab['key'];
        }
    }
    $presets = $config['lifecycle_presets'] ?? [];
    $lifecycle = (string)($customer['lifecycle_key'] ?? $customer['status'] ?? 'lead');
    if (($customer['status'] ?? '') === 'blacklist' || ($customer['level'] ?? '') === 'Blacklist') $lifecycle = 'blacklist';
    $allowedKeys = $presets[$lifecycle] ?? null;
    $user = current_user();
    $roleKey = (string)($user['role_key'] ?? '');
    $visible = [];
    $hidden = [];
    $all = [];
    foreach ($tabs as $tab) {
        if (empty($tab['key'])) continue;
        $key = (string)$tab['key'];
        if ($key === 'chat_groups') continue;
        $permission = trim((string)($tab['permission'] ?? ''));
        $entry = [
            'key' => $key,
            'name' => (string)($tab['name'] ?? $key),
            'short' => (string)($tab['short'] ?? ($tab['name'] ?? $key)),
            'icon' => (string)($tab['icon'] ?? strtoupper(substr($key, 0, 2))),
            'sort' => (int)($tab['sort'] ?? 100),
            'readonly' => !empty($tab['readonly']),
            'permission' => $permission,
            'show_summary' => !empty($tab['show_summary']),
            'enabled' => !empty($tab['enabled']),
            'show_in_detail' => !(isset($tab['show_in_detail']) && empty($tab['show_in_detail'])),
            'lifecycle_mode' => (string)($tab['lifecycle_mode'] ?? ''),
            'visible' => false,
            'reason' => '',
        ];
        $all[] = $entry;
        if (empty($tab['enabled'])) {
            $entry['reason'] = '未启用';
            $hidden[] = $entry;
            continue;
        }
        if (isset($tab['show_in_detail']) && empty($tab['show_in_detail'])) {
            $entry['reason'] = '未允许客户详情显示';
            $hidden[] = $entry;
            continue;
        }
        $lifecycles = $tab['lifecycles'] ?? ['*'];
        if (is_string($lifecycles)) $lifecycles = array_filter(array_map('trim', explode(',', $lifecycles)));
        $lifecycleMode = (string)($tab['lifecycle_mode'] ?? '');
        if ($lifecycleMode !== 'all' && is_array($allowedKeys) && !in_array('*', $allowedKeys, true) && !in_array($key, $allowedKeys, true)) {
            $entry['reason'] = '客户生命周期预设隐藏';
            $hidden[] = $entry;
            continue;
        }
        if ($lifecycleMode !== 'all' && !in_array('*', $lifecycles, true) && !in_array($lifecycle, $lifecycles, true)) {
            $entry['reason'] = '生命周期不匹配';
            $hidden[] = $entry;
            continue;
        }
        $roles = $tab['roles'] ?? [];
        if (is_string($roles)) $roles = array_filter(array_map('trim', explode(',', $roles)));
        if ($roles && !in_array($roleKey, $roles, true) && !is_super_admin()) {
            $entry['reason'] = '当前角色不可见';
            $hidden[] = $entry;
            continue;
        }
        if ($permission !== '' && !is_super_admin() && !has_permission($permission)) {
            $entry['reason'] = '权限不足：' . $permission;
            $hidden[] = $entry;
            continue;
        }
        $entry['visible'] = true;
        $entry['reason'] = '可见';
        $visible[] = $entry;
    }
    usort($visible, fn($a, $b) => ($a['sort'] <=> $b['sort']) ?: strcmp($a['key'], $b['key']));
    if (!$visible) {
        $visible[] = ['key' => 'overview', 'name' => '概览', 'short' => '概览', 'icon' => 'OV', 'sort' => 10, 'readonly' => false, 'permission' => ''];
    }
    $keys = array_column($visible, 'key');
    if (!in_array('logs', $keys, true) && ($lifecycle === 'blacklist' || is_super_admin() || has_permission('customer.view_logs'))) {
        $visible[] = ['key' => 'logs', 'name' => '日志', 'short' => '日志', 'icon' => 'LG', 'sort' => 999, 'readonly' => true, 'permission' => 'customer.view_logs'];
    }
    return [
        'tabs' => $visible,
        'label_mode' => (string)($config['label_mode'] ?? 'icon_short'),
        'overflow_after' => max(4, min(16, (int)($config['overflow_after'] ?? 10))),
        'lifecycle' => $lifecycle,
        'all_tabs' => $all,
        'hidden_tabs' => $hidden,
    ];
}

function crm_customer_log(string $action, string $objectType, $objectId, ?int $customerId, $before = null, $after = null, string $message = '', bool $success = true, string $error = ''): void
{
    $user = current_user();
    db()->prepare('INSERT INTO crm_logs (user_id, module, action, object_type, object_id, customer_id, before_json, after_json, message, ip_address, user_agent, success, error_message, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())')
        ->execute([$user['id'] ?? null, 'customer', $action, $objectType, (string)$objectId, $customerId, $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE), $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE), $message, client_ip(), user_agent(), $success ? 1 : 0, $error]);
    crm_log_event('customers', $action, $objectType, (string)$objectId, $before, $after, $success, $error);
}

function crm_sensitive_audit(string $action, string $targetType, $targetId, $before = null, $after = null): void
{
    $sensitive = ['customer_delete','customer_force_delete','customer_restore','customer_merge','customer_batch_delete','customer_batch_force_delete','customer_batch_export','customer_export','customer_batch_assign','customer_transfer_owner','customer_primary_owner_change','customer_public_pool','customer_claim_public','customer_blacklist','promotion_cancel','bom_cost_view'];
    if (!in_array($action, $sensitive, true)) return;
    $user = current_user();
    db()->prepare('INSERT INTO crm_sensitive_audit_logs (user_id, action_key, target_type, target_id, before_json, after_json, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())')
        ->execute([$user['id'] ?? null, $action, $targetType, (string)$targetId, $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE), $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE), client_ip(), user_agent()]);
}

function crm_customer_timeline_add(int $customerId, string $eventType, string $title, string $detail = '', string $relatedType = '', string $relatedId = ''): void
{
    db()->prepare('INSERT INTO crm_customer_timeline (customer_id, event_type, title, detail, related_type, related_id, event_time, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, NOW())')
        ->execute([$customerId, $eventType, $title, $detail, $relatedType, $relatedId, current_user()['id'] ?? null]);
}

function crm_customer_timeline(int $customerId): array
{
    $stmt = db()->prepare('SELECT t.*, u.username FROM crm_customer_timeline t LEFT JOIN crm_users u ON u.id = t.created_by WHERE t.customer_id = ? ORDER BY t.event_time DESC, t.id DESC LIMIT 100');
    $stmt->execute([$customerId]);
    return $stmt->fetchAll();
}

function crm_customer_relations(int $customerId): array
{
    $stmt = db()->prepare('SELECT r.*, c.customer_name AS related_name FROM crm_customer_relations r LEFT JOIN crm_customers c ON c.id = r.related_customer_id WHERE r.customer_id = ? ORDER BY r.id DESC');
    $stmt->execute([$customerId]);
    return $stmt->fetchAll();
}

function crm_customer_events(int $customerId): array
{
    $stmt = db()->prepare('SELECT * FROM crm_customer_events WHERE customer_id = ? ORDER BY COALESCE(event_time, created_at) DESC LIMIT 50');
    $stmt->execute([$customerId]);
    return $stmt->fetchAll();
}

function crm_customer_protection(int $customerId): array
{
    $stmt = db()->prepare('SELECT * FROM crm_customer_protection WHERE customer_id = ? LIMIT 1');
    $stmt->execute([$customerId]);
    return $stmt->fetch() ?: ['customer_id' => $customerId, 'protection_status' => 'normal', 'protected_until' => null, 'reason' => '默认保护规则', 'public_pool_at' => null];
}

function crm_customer_preference_row(int $customerId, string $table): array
{
    $stmt = db()->prepare("SELECT * FROM {$table} WHERE customer_id = ? LIMIT 1");
    $stmt->execute([$customerId]);
    return $stmt->fetch() ?: [];
}

function crm_customer_completeness(int $customerId, array $customer, array $context = []): array
{
    $contacts = $context['contacts'] ?? crm_contact_list(['customer_id' => $customerId])['rows'];
    $sourceTags = $context['source_tags'] ?? crm_customer_tags($customerId, 'crm_customer_source_tags', 'source_key');
    $promotionChannels = $context['promotion_channels'] ?? crm_customer_tags($customerId, 'crm_customer_promotion_channels', 'channel_key');
    $owners = $context['owners'] ?? crm_customer_owners($customerId);
    $groups = $context['groups'] ?? crm_customer_groups_for($customerId);
    $checks = [
        '客户名称' => !empty($customer['customer_name']),
        '国家' => !empty($customer['country']),
        '城市' => !empty($customer['city']),
        '地址' => !empty($customer['address']),
        '网站' => !empty($customer['website']),
        '主联系人' => (bool)array_filter($contacts, fn($c) => (int)$c['is_primary'] === 1),
        '联系人邮箱' => (bool)array_filter($contacts, fn($c) => !empty($c['email'])),
        '联系人电话' => (bool)array_filter($contacts, fn($c) => !empty($c['phone'])),
        'WhatsApp' => !empty($customer['whatsapp']) || (bool)array_filter($contacts, fn($c) => !empty($c['whatsapp'])),
        '来源' => !empty($sourceTags),
        '等级' => !empty($customer['level']),
        '生命周期' => !empty($customer['lifecycle_key']),
        '推广方式' => !empty($promotionChannels),
        '负责人' => !empty($owners),
        '分组' => !empty($groups),
    ];
    $done = count(array_filter($checks));
    return ['score' => (int)round($done * 100 / max(1, count($checks))), 'checks' => $checks, 'missing' => array_keys(array_filter($checks, fn($ok) => !$ok))];
}

function crm_customer_scores(int $customerId, array $customer, array $context = [], bool $persist = false): array
{
    $completeness = crm_customer_completeness($customerId, $customer, $context);
    $lastFollow = array_key_exists('last_followup_at', $context)
        ? $context['last_followup_at']
        : db()->query('SELECT MAX(followup_time) FROM crm_customer_followups WHERE customer_id = ' . (int)$customerId . ' AND deleted_at IS NULL')->fetchColumn();
    $days = $lastFollow ? floor((time() - strtotime($lastFollow)) / 86400) : 999;
    $followRisk = $days > 30 ? 90 : ($days > 15 ? 70 : ($days > 7 ? 50 : 20));
    $activity = max(10, min(100, 100 - min(90, $days * 3)));
    $health = max(10, min(100, (int)round(($completeness['score'] + $activity + (100 - $followRisk)) / 3)));
    $deal = in_array($customer['lifecycle_key'] ?? '', ['quoting','sampling','deal'], true) ? 65 : 30;
    $scores = ['health_score' => $health, 'activity_score' => $activity, 'deal_probability' => $deal, 'followup_risk' => $followRisk, 'completeness_score' => $completeness['score'], 'completeness' => $completeness];
    if ($persist) {
        db()->prepare('INSERT INTO crm_customer_scores (customer_id, health_score, activity_score, deal_probability, followup_risk, completeness_score, score_json, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE health_score=VALUES(health_score), activity_score=VALUES(activity_score), deal_probability=VALUES(deal_probability), followup_risk=VALUES(followup_risk), completeness_score=VALUES(completeness_score), score_json=VALUES(score_json), updated_at=NOW()')
            ->execute([$customerId, $health, $activity, $deal, $followRisk, $completeness['score'], json_encode($scores, JSON_UNESCAPED_UNICODE)]);
    }
    return $scores;
}

function crm_customer_scope_sql(array &$params): string
{
    if (is_super_admin() || has_permission('customer.view_all')) return '1=1';
    $user = current_user();
    $userId = (int)($user['id'] ?? 0);
    if (has_permission('customer.view_department') && !empty($user['department_name'])) {
        $params[] = $user['department_name'];
        $params[] = $user['department_name'];
        return '(c.owner_user_id IS NULL OR c.owner_department = ? OR c.owner_user_id = ' . $userId . ' OR c.created_by = ' . $userId . ' OR EXISTS (SELECT 1 FROM crm_customer_owners co JOIN crm_users cu ON cu.id = co.user_id LEFT JOIN crm_departments cd ON cd.id = cu.department_id WHERE co.customer_id = c.id AND cd.name = ?))';
    }
    $params[] = $userId;
    $params[] = $userId;
    $params[] = $userId;
    return '(c.owner_user_id = ? OR c.created_by = ? OR EXISTS (SELECT 1 FROM crm_customer_owners co WHERE co.customer_id = c.id AND co.user_id = ?))';
}

function crm_customer_owner_match_sql(int $userId, array &$params): string
{
    $params[] = $userId;
    $params[] = $userId;
    return '(c.owner_user_id = ? OR EXISTS (SELECT 1 FROM crm_customer_owners co WHERE co.customer_id = c.id AND co.user_id = ?))';
}

function crm_customer_addresses(int $customerId): array
{
    $stmt = db()->prepare('SELECT * FROM crm_customer_addresses WHERE customer_id = ? AND deleted_at IS NULL ORDER BY is_primary DESC, id ASC');
    $stmt->execute([$customerId]);
    return $stmt->fetchAll();
}

function crm_customer_address_save(array $input): array
{
    crm_customer_ensure_tables();
    crm_require('customer.edit');
    $customerId = (int)($input['customer_id'] ?? 0);
    crm_customer_get($customerId);
    $id = (int)($input['address_id'] ?? $input['id'] ?? 0);
    $type = trim((string)($input['address_type'] ?? 'Other'));
    $allowed = ['HQ','Office','Factory','Warehouse','Other'];
    $typeMap = ['primary' => 'HQ', 'shipping' => 'Warehouse', 'billing' => 'Office', 'other' => 'Other', '主地址' => 'HQ', '发货地址' => 'Warehouse', '账单地址' => 'Office', '其他地址' => 'Other'];
    $type = $typeMap[$type] ?? $type;
    if (!in_array($type, $allowed, true)) $type = 'Other';
    $payload = [
        'customer_id' => $customerId,
        'address_type' => $type,
        'country' => trim((string)($input['country'] ?? '')),
        'city' => trim((string)($input['city'] ?? '')),
        'address' => trim((string)($input['address'] ?? '')),
        'postal_code' => trim((string)($input['postal_code'] ?? $input['zip_code'] ?? '')),
        'contact_name' => trim((string)($input['contact_name'] ?? '')),
        'phone' => trim((string)($input['phone'] ?? '')),
        'is_primary' => !empty($input['is_primary']) ? 1 : 0,
    ];
    if ($payload['country'] === '') throw new RuntimeException('国家不能为空。');
    if ($payload['address'] === '') throw new RuntimeException('详细地址不能为空。');
    $before = null;
    if ($payload['is_primary']) {
        db()->prepare('UPDATE crm_customer_addresses SET is_primary = 0 WHERE customer_id = ? AND deleted_at IS NULL')->execute([$customerId]);
    }
    if ($id > 0) {
        $stmt = db()->prepare('SELECT * FROM crm_customer_addresses WHERE id = ? AND customer_id = ? AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([$id, $customerId]);
        $before = $stmt->fetch();
        if (!$before) throw new RuntimeException('地址不存在。');
        db()->prepare('UPDATE crm_customer_addresses SET address_type=?, country=?, city=?, address=?, postal_code=?, contact_name=?, phone=?, is_primary=?, updated_by=?, updated_at=NOW() WHERE id=?')
            ->execute([$payload['address_type'], $payload['country'], $payload['city'], $payload['address'], $payload['postal_code'], $payload['contact_name'], $payload['phone'], $payload['is_primary'], current_user()['id'] ?? null, $id]);
        crm_customer_log('address_update', 'address', $id, $customerId, $before, $payload, '编辑客户地址');
        crm_customer_timeline_add($customerId, 'address_update', '编辑客户地址', $payload['address'], 'address', (string)$id);
    } else {
        db()->prepare('INSERT INTO crm_customer_addresses (customer_id, address_type, country, city, address, postal_code, contact_name, phone, is_primary, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())')
            ->execute([$customerId, $payload['address_type'], $payload['country'], $payload['city'], $payload['address'], $payload['postal_code'], $payload['contact_name'], $payload['phone'], $payload['is_primary'], current_user()['id'] ?? null, current_user()['id'] ?? null]);
        $id = (int)db()->lastInsertId();
        crm_customer_log('address_create', 'address', $id, $customerId, null, $payload, '新增客户地址');
        crm_customer_timeline_add($customerId, 'address_create', '新增客户地址', $payload['address'], 'address', (string)$id);
    }
    return crm_customer_get($customerId, 'full');
}

function crm_customer_address_delete(array $input): array
{
    crm_customer_ensure_tables();
    crm_require('customer.edit');
    $customerId = (int)($input['customer_id'] ?? 0);
    $id = (int)($input['address_id'] ?? $input['id'] ?? 0);
    if ($customerId <= 0 || $id <= 0) throw new RuntimeException('地址 ID 无效。');
    crm_customer_get($customerId);
    $stmt = db()->prepare('SELECT * FROM crm_customer_addresses WHERE id = ? AND customer_id = ? AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([$id, $customerId]);
    $before = $stmt->fetch();
    if (!$before) throw new RuntimeException('地址不存在。');
    db()->prepare('UPDATE crm_customer_addresses SET deleted_at=NOW(), deleted_by=?, updated_by=?, updated_at=NOW() WHERE id=?')
        ->execute([current_user()['id'] ?? null, current_user()['id'] ?? null, $id]);
    crm_customer_log('address_delete', 'address', $id, $customerId, $before, ['deleted' => 1], '删除客户地址');
    crm_customer_timeline_add($customerId, 'address_delete', '删除客户地址', (string)($before['address'] ?? ''), 'address', (string)$id);
    return crm_customer_get($customerId, 'full');
}

function crm_customer_chat_groups(int $customerId): array
{
    $stmt = db()->prepare("SELECT g.*, u.username AS updated_by_name
        FROM crm_customer_chat_groups g
        LEFT JOIN crm_users u ON u.id = g.updated_by
        WHERE g.customer_id = ? AND g.deleted_at IS NULL
        ORDER BY FIELD(g.status, 'active','paused','invalid'), g.updated_at DESC, g.id DESC");
    $stmt->execute([$customerId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $ids = json_decode((string)($row['linked_contact_ids_json'] ?? '[]'), true);
        $row['linked_contact_ids'] = is_array($ids) ? array_values(array_map('intval', $ids)) : [];
        $row['platform_name'] = $row['group_platform'] === 'whatsapp_group' ? 'WhatsApp群' : '微信群';
    }
    return $rows;
}

function crm_customer_chat_group_save(array $input): array
{
    crm_customer_ensure_tables();
    crm_require('customer.edit');
    $customerId = (int)($input['customer_id'] ?? 0);
    crm_customer_get($customerId);
    $id = (int)($input['group_id'] ?? $input['id'] ?? 0);
    $name = trim((string)($input['group_name'] ?? ''));
    if ($name === '') throw new RuntimeException('群名称不能为空。');
    $platform = trim((string)($input['group_platform'] ?? 'wechat_group'));
    if (!in_array($platform, ['wechat_group','whatsapp_group'], true)) $platform = 'wechat_group';
    $status = trim((string)($input['status'] ?? 'active'));
    if (!in_array($status, ['active','paused','invalid'], true)) $status = 'active';
    $contactIds = crm_mail_input_ids($input['linked_contact_ids'] ?? ($input['linked_contact_ids_json'] ?? []));
    $payload = [
        'customer_id' => $customerId,
        'group_name' => $name,
        'group_platform' => $platform,
        'group_owner' => trim((string)($input['group_owner'] ?? '')),
        'linked_contact_ids_json' => json_encode(array_values($contactIds), JSON_UNESCAPED_UNICODE),
        'use_for_promotion' => !empty($input['use_for_promotion']) ? 1 : 0,
        'status' => $status,
        'remark' => trim((string)($input['remark'] ?? '')),
    ];
    $before = null;
    if ($id > 0) {
        $stmt = db()->prepare('SELECT * FROM crm_customer_chat_groups WHERE id = ? AND customer_id = ? AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([$id, $customerId]);
        $before = $stmt->fetch();
        if (!$before) throw new RuntimeException('客户群不存在。');
        db()->prepare('UPDATE crm_customer_chat_groups SET group_name=?, group_platform=?, group_owner=?, linked_contact_ids_json=?, use_for_promotion=?, status=?, remark=?, updated_by=?, updated_at=NOW() WHERE id=?')
            ->execute([$payload['group_name'], $payload['group_platform'], $payload['group_owner'], $payload['linked_contact_ids_json'], $payload['use_for_promotion'], $payload['status'], $payload['remark'], current_user()['id'] ?? null, $id]);
        crm_customer_timeline_add($customerId, 'chat_group_update', '更新客户群：' . $name, $platform . ' · ' . $status, 'chat_group', (string)$id);
        crm_customer_log('chat_group_update', 'chat_group', $id, $customerId, $before, $payload, '更新客户群');
    } else {
        db()->prepare('INSERT INTO crm_customer_chat_groups (customer_id, group_name, group_platform, group_owner, linked_contact_ids_json, use_for_promotion, status, remark, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())')
            ->execute([$customerId, $payload['group_name'], $payload['group_platform'], $payload['group_owner'], $payload['linked_contact_ids_json'], $payload['use_for_promotion'], $payload['status'], $payload['remark'], current_user()['id'] ?? null, current_user()['id'] ?? null]);
        $id = (int)db()->lastInsertId();
        crm_customer_timeline_add($customerId, 'chat_group_create', '新增客户群：' . $name, $platform . ' · 可用于推广：' . ($payload['use_for_promotion'] ? '是' : '否'), 'chat_group', (string)$id);
        crm_customer_log('chat_group_create', 'chat_group', $id, $customerId, null, $payload, '新增客户群');
    }
    return crm_customer_get($customerId);
}

function crm_customer_chat_group_delete(array $input): array
{
    crm_customer_ensure_tables();
    crm_require('customer.edit');
    $customerId = (int)($input['customer_id'] ?? 0);
    $id = (int)($input['group_id'] ?? 0);
    if ($customerId <= 0 || $id <= 0) throw new RuntimeException('客户群 ID 无效。');
    crm_customer_get($customerId);
    $stmt = db()->prepare('SELECT * FROM crm_customer_chat_groups WHERE id = ? AND customer_id = ? AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([$id, $customerId]);
    $before = $stmt->fetch();
    if (!$before) throw new RuntimeException('客户群不存在。');
    db()->prepare('UPDATE crm_customer_chat_groups SET deleted_at=NOW(), deleted_by=?, updated_by=?, updated_at=NOW() WHERE id=?')
        ->execute([current_user()['id'] ?? null, current_user()['id'] ?? null, $id]);
    crm_customer_timeline_add($customerId, 'chat_group_delete', '删除客户群：' . ($before['group_name'] ?? ''), (string)($before['group_platform'] ?? ''), 'chat_group', (string)$id);
    crm_customer_log('chat_group_delete', 'chat_group', $id, $customerId, $before, ['deleted' => 1], '删除客户群');
    return crm_customer_get($customerId);
}

function crm_customer_tags(int $customerId, string $table, string $column): array
{
    $stmt = db()->prepare("SELECT {$column} FROM {$table} WHERE customer_id = ? ORDER BY id");
    $stmt->execute([$customerId]);
    return array_map(fn($row) => $row[$column], $stmt->fetchAll());
}

function crm_customer_tag_list(int $customerId): array
{
    crm_customer_ensure_tables();
    $detail = crm_customer_get($customerId, 'overview');
    $customer = $detail['customer'] ?? [];
    $custom = array_values(array_filter(array_map('trim', preg_split('/[,，;；、]+/u', (string)($customer['customer_tags'] ?? ''), -1, PREG_SPLIT_NO_EMPTY))));
    return [
        'customer_tags' => $custom,
        'source_tags' => $detail['source_tags'] ?? [],
        'promotion_channels' => $detail['promotion_channels'] ?? [],
        'risk_tags' => array_values(array_filter([(string)($customer['risk_level'] ?? ''), !empty($customer['do_not_contact']) ? 'blacklist' : ''])),
        'groups' => $detail['groups'] ?? [],
    ];
}

function crm_customer_tag_save(array $input): array
{
    crm_customer_ensure_tables();
    crm_require('customer.edit');
    $customerId = (int)($input['customer_id'] ?? 0);
    $detail = crm_customer_get($customerId, 'overview');
    $customer = $detail['customer'] ?? [];
    $type = trim((string)($input['tag_type'] ?? 'customer_tag'));
    $name = trim((string)($input['tag_name'] ?? $input['tag_key'] ?? ''));
    if ($name === '') throw new RuntimeException('标签不能为空。');
    $before = crm_customer_tag_list($customerId);
    if ($type === 'customer_tag' || $type === 'risk_tag') {
        $tags = array_values(array_unique(array_merge($before['customer_tags'] ?? [], [$name])));
        db()->prepare('UPDATE crm_customers SET customer_tags=?, updated_by=?, updated_at=NOW() WHERE id=?')
            ->execute([implode(',', $tags), current_user()['id'] ?? null, $customerId]);
    } elseif ($type === 'source_tag') {
        $keys = crm_parse_keys(array_merge($before['source_tags'] ?? [], [$name]), crm_graph_options()['source_tags']);
        if (!in_array($name, $keys, true)) throw new RuntimeException('来源标签不在字典中。');
        crm_customer_sync_key_table($customerId, 'crm_customer_source_tags', 'source_key', $keys);
    } elseif ($type === 'promotion_tag') {
        $keys = crm_parse_keys(array_merge($before['promotion_channels'] ?? [], [$name]), crm_graph_options()['promotion_channels']);
        if (!in_array($name, $keys, true)) throw new RuntimeException('推广标签不在字典中。');
        crm_customer_sync_key_table($customerId, 'crm_customer_promotion_channels', 'channel_key', $keys);
    } elseif ($type === 'customer_group') {
        $groupId = (int)($input['group_id'] ?? 0);
        if ($groupId <= 0) {
            $stmt = db()->prepare('SELECT id FROM crm_customer_groups WHERE group_name = ? AND deleted_at IS NULL LIMIT 1');
            $stmt->execute([$name]);
            $groupId = (int)$stmt->fetchColumn();
        }
        if ($groupId <= 0) throw new RuntimeException('客户组不存在，请先在客户组管理中创建。');
        db()->prepare('INSERT IGNORE INTO crm_customer_group_relations (customer_id, group_id, created_by, created_at) VALUES (?, ?, ?, NOW())')
            ->execute([$customerId, $groupId, current_user()['id'] ?? null]);
    } else {
        throw new RuntimeException('标签类型无效。');
    }
    $after = crm_customer_tag_list($customerId);
    crm_customer_log('tag_save', 'tag', $name, $customerId, $before, $after, '添加客户标签');
    crm_customer_timeline_add($customerId, 'tag_save', '添加客户标签', $type . ' · ' . $name, 'customer', (string)$customerId);
    return crm_customer_get($customerId, 'full');
}

function crm_customer_tag_delete(array $input): array
{
    crm_customer_ensure_tables();
    crm_require('customer.edit');
    $customerId = (int)($input['customer_id'] ?? 0);
    crm_customer_get($customerId, 'overview');
    $type = trim((string)($input['tag_type'] ?? 'customer_tag'));
    $name = trim((string)($input['tag_name'] ?? $input['tag_key'] ?? ''));
    if ($name === '') throw new RuntimeException('标签不能为空。');
    $before = crm_customer_tag_list($customerId);
    if ($type === 'customer_tag' || $type === 'risk_tag') {
        $tags = array_values(array_filter($before['customer_tags'] ?? [], fn($tag) => (string)$tag !== $name));
        db()->prepare('UPDATE crm_customers SET customer_tags=?, updated_by=?, updated_at=NOW() WHERE id=?')
            ->execute([implode(',', $tags), current_user()['id'] ?? null, $customerId]);
    } elseif ($type === 'source_tag') {
        db()->prepare('DELETE FROM crm_customer_source_tags WHERE customer_id=? AND source_key=?')->execute([$customerId, $name]);
    } elseif ($type === 'promotion_tag') {
        db()->prepare('DELETE FROM crm_customer_promotion_channels WHERE customer_id=? AND channel_key=?')->execute([$customerId, $name]);
    } elseif ($type === 'customer_group') {
        $groupId = (int)($input['group_id'] ?? 0);
        if ($groupId <= 0) {
            $stmt = db()->prepare('SELECT id FROM crm_customer_groups WHERE group_name = ? AND deleted_at IS NULL LIMIT 1');
            $stmt->execute([$name]);
            $groupId = (int)$stmt->fetchColumn();
        }
        if ($groupId <= 0) throw new RuntimeException('客户组不存在。');
        db()->prepare('DELETE FROM crm_customer_group_relations WHERE customer_id=? AND group_id=?')->execute([$customerId, $groupId]);
    } else {
        throw new RuntimeException('标签类型无效。');
    }
    $after = crm_customer_tag_list($customerId);
    crm_customer_log('tag_delete', 'tag', $name, $customerId, $before, $after, '移除客户标签');
    crm_customer_timeline_add($customerId, 'tag_delete', '移除客户标签', $type . ' · ' . $name, 'customer', (string)$customerId);
    return crm_customer_get($customerId, 'full');
}

function crm_customer_promotion_status(int $customerId): string
{
    $stmt = db()->prepare('SELECT status FROM crm_customer_promotion_status WHERE customer_id = ? LIMIT 1');
    $stmt->execute([$customerId]);
    return (string)($stmt->fetchColumn() ?: 'not_promoted');
}

function crm_customer_owners(int $customerId): array
{
    $stmt = db()->prepare("SELECT co.*, u.username, d.name AS department_name FROM crm_customer_owners co LEFT JOIN crm_users u ON u.id = co.user_id LEFT JOIN crm_departments d ON d.id = u.department_id WHERE co.customer_id = ? ORDER BY co.is_primary DESC, FIELD(co.role_type, 'primary','secondary','collaborator','viewer'), co.id");
    $stmt->execute([$customerId]);
    return $stmt->fetchAll();
}

function crm_customer_primary_owner(int $customerId): ?array
{
    $owners = crm_customer_owners($customerId);
    foreach ($owners as $owner) if ((int)$owner['is_primary'] === 1 || $owner['role_type'] === 'primary') return $owner;
    return $owners[0] ?? null;
}

function crm_customer_sync_addresses(int $customerId, array $addresses): void
{
    db()->prepare('DELETE FROM crm_customer_addresses WHERE customer_id = ?')->execute([$customerId]);
    $insert = db()->prepare('INSERT INTO crm_customer_addresses (customer_id, address_type, country, city, address, is_primary, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
    $hasPrimary = false;
    foreach ($addresses as $index => $address) {
        $country = trim((string)($address['country'] ?? ''));
        if ($country === '') continue;
        $type = in_array(($address['address_type'] ?? 'Other'), crm_graph_options()['address_types'], true) ? $address['address_type'] : 'Other';
        $isPrimary = !empty($address['is_primary']) || (!$hasPrimary && $index === 0);
        if ($isPrimary) $hasPrimary = true;
        $insert->execute([$customerId, $type, $country, trim((string)($address['city'] ?? '')), trim((string)($address['address'] ?? '')), $isPrimary ? 1 : 0, current_user()['id'] ?? null, current_user()['id'] ?? null]);
    }
}

function crm_customer_sync_key_table(int $customerId, string $table, string $column, array $keys): void
{
    db()->prepare("DELETE FROM {$table} WHERE customer_id = ?")->execute([$customerId]);
    $insert = db()->prepare("INSERT IGNORE INTO {$table} (customer_id, {$column}, created_by, created_at) VALUES (?, ?, ?, NOW())");
    foreach (array_values(array_unique($keys)) as $key) $insert->execute([$customerId, $key, current_user()['id'] ?? null]);
}

function crm_customer_sync_promotion_status(int $customerId, string $status): void
{
    if (!in_array($status, crm_graph_options()['promotion_statuses'], true)) $status = 'not_promoted';
    db()->prepare('INSERT INTO crm_customer_promotion_status (customer_id, status, updated_by, updated_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE status = VALUES(status), updated_by = VALUES(updated_by), updated_at = NOW()')
        ->execute([$customerId, $status, current_user()['id'] ?? null]);
}

function crm_customer_sync_owners(int $customerId, array $owners): void
{
    if (!$owners) $owners = [['user_id' => current_user()['id'] ?? 0, 'role_type' => 'primary', 'is_primary' => 1]];
    db()->prepare('DELETE FROM crm_customer_owners WHERE customer_id = ?')->execute([$customerId]);
    $insert = db()->prepare('INSERT IGNORE INTO crm_customer_owners (customer_id, user_id, role_type, is_primary, created_by, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
    $primarySet = false;
    foreach ($owners as $owner) {
        $userId = (int)($owner['user_id'] ?? 0);
        if (!$userId) continue;
        $role = in_array(($owner['role_type'] ?? 'viewer'), crm_graph_options()['owner_roles'], true) ? $owner['role_type'] : 'viewer';
        $isPrimary = (!$primarySet && ($role === 'primary' || !empty($owner['is_primary']))) ? 1 : 0;
        if ($isPrimary) { $role = 'primary'; $primarySet = true; }
        $insert->execute([$customerId, $userId, $role, $isPrimary, current_user()['id'] ?? null]);
    }
}

function crm_customer_country_aliases(): array
{
    return [
        'HK' => ['HK', 'Hong Kong', '香港', '中国香港'],
        'CN' => ['CN', 'China', '中国', 'Mainland China', 'PRC'],
        'TW' => ['TW', 'Taiwan', '台湾', '中国台湾'],
        'MO' => ['MO', 'Macau', 'Macao', '澳门', '中国澳门'],
        'IN' => ['IN', 'India', '印度'],
        'PK' => ['PK', 'Pakistan', '巴基斯坦'],
        'BD' => ['BD', 'Bangladesh', '孟加拉', '孟加拉国'],
        'LK' => ['LK', 'Sri Lanka', '斯里兰卡'],
        'NP' => ['NP', 'Nepal', '尼泊尔'],
        'AE' => ['AE', 'UAE', 'United Arab Emirates', 'Dubai', 'Abu Dhabi', '阿联酋', '迪拜', '阿布扎比'],
        'SA' => ['SA', 'Saudi Arabia', 'KSA', '沙特', '沙特阿拉伯'],
        'QA' => ['QA', 'Qatar', '卡塔尔'],
        'KW' => ['KW', 'Kuwait', '科威特'],
        'BH' => ['BH', 'Bahrain', '巴林'],
        'OM' => ['OM', 'Oman', '阿曼'],
        'IR' => ['IR', 'Iran', '伊朗'],
        'IQ' => ['IQ', 'Iraq', '伊拉克'],
        'IL' => ['IL', 'Israel', '以色列'],
        'JO' => ['JO', 'Jordan', '约旦'],
        'LB' => ['LB', 'Lebanon', '黎巴嫩'],
        'SY' => ['SY', 'Syria', '叙利亚'],
        'YE' => ['YE', 'Yemen', '也门'],
        'TR' => ['TR', 'Turkey', 'Türkiye', '土耳其'],
        'EG' => ['EG', 'Egypt', '埃及'],
        'SG' => ['SG', 'Singapore', '新加坡'],
        'MY' => ['MY', 'Malaysia', '马来西亚'],
        'TH' => ['TH', 'Thailand', '泰国'],
        'VN' => ['VN', 'Vietnam', 'Viet Nam', '越南'],
        'ID' => ['ID', 'Indonesia', '印尼', '印度尼西亚'],
        'PH' => ['PH', 'Philippines', '菲律宾'],
        'MM' => ['MM', 'Myanmar', 'Burma', '缅甸'],
        'KH' => ['KH', 'Cambodia', '柬埔寨'],
        'LA' => ['LA', 'Laos', '老挝'],
        'BN' => ['BN', 'Brunei', '文莱'],
        'JP' => ['JP', 'Japan', '日本'],
        'KR' => ['KR', 'Korea', 'South Korea', 'Republic of Korea', '韩国'],
        'AU' => ['AU', 'Australia', '澳大利亚', '澳洲'],
        'NZ' => ['NZ', 'New Zealand', '新西兰'],
        'US' => ['US', 'USA', 'United States', 'United States of America', 'America', '美国'],
        'CA' => ['CA', 'Canada', '加拿大'],
        'MX' => ['MX', 'Mexico', '墨西哥'],
        'BR' => ['BR', 'Brazil', '巴西'],
        'AR' => ['AR', 'Argentina', '阿根廷'],
        'CL' => ['CL', 'Chile', '智利'],
        'CO' => ['CO', 'Colombia', '哥伦比亚'],
        'PE' => ['PE', 'Peru', '秘鲁'],
        'VE' => ['VE', 'Venezuela', '委内瑞拉'],
        'EC' => ['EC', 'Ecuador', '厄瓜多尔'],
        'UY' => ['UY', 'Uruguay', '乌拉圭'],
        'PY' => ['PY', 'Paraguay', '巴拉圭'],
        'BO' => ['BO', 'Bolivia', '玻利维亚'],
        'GB' => ['GB', 'UK', 'United Kingdom', 'Britain', 'England', '英国'],
        'IE' => ['IE', 'Ireland', '爱尔兰'],
        'FR' => ['FR', 'France', '法国'],
        'DE' => ['DE', 'Germany', '德国'],
        'IT' => ['IT', 'Italy', '意大利'],
        'ES' => ['ES', 'Spain', '西班牙'],
        'PT' => ['PT', 'Portugal', '葡萄牙'],
        'NL' => ['NL', 'Netherlands', 'Holland', '荷兰'],
        'BE' => ['BE', 'Belgium', '比利时'],
        'LU' => ['LU', 'Luxembourg', '卢森堡'],
        'CH' => ['CH', 'Switzerland', '瑞士'],
        'AT' => ['AT', 'Austria', '奥地利'],
        'PL' => ['PL', 'Poland', '波兰'],
        'CZ' => ['CZ', 'Czech Republic', 'Czechia', '捷克'],
        'SK' => ['SK', 'Slovakia', '斯洛伐克'],
        'HU' => ['HU', 'Hungary', '匈牙利'],
        'RO' => ['RO', 'Romania', '罗马尼亚'],
        'BG' => ['BG', 'Bulgaria', '保加利亚'],
        'GR' => ['GR', 'Greece', '希腊'],
        'DK' => ['DK', 'Denmark', '丹麦'],
        'SE' => ['SE', 'Sweden', '瑞典'],
        'NO' => ['NO', 'Norway', '挪威'],
        'FI' => ['FI', 'Finland', '芬兰'],
        'RU' => ['RU', 'Russia', '俄罗斯'],
        'UA' => ['UA', 'Ukraine', '乌克兰'],
        'BY' => ['BY', 'Belarus', '白俄罗斯'],
        'RS' => ['RS', 'Serbia', '塞尔维亚'],
        'HR' => ['HR', 'Croatia', '克罗地亚'],
        'SI' => ['SI', 'Slovenia', '斯洛文尼亚'],
        'ZA' => ['ZA', 'South Africa', '南非'],
        'NG' => ['NG', 'Nigeria', '尼日利亚'],
        'KE' => ['KE', 'Kenya', '肯尼亚'],
        'GH' => ['GH', 'Ghana', '加纳'],
        'ET' => ['ET', 'Ethiopia', '埃塞俄比亚'],
        'TZ' => ['TZ', 'Tanzania', '坦桑尼亚'],
        'UG' => ['UG', 'Uganda', '乌干达'],
        'SD' => ['SD', 'Sudan', '苏丹'],
        'MA' => ['MA', 'Morocco', '摩洛哥'],
        'DZ' => ['DZ', 'Algeria', '阿尔及利亚'],
        'TN' => ['TN', 'Tunisia', '突尼斯'],
        'LY' => ['LY', 'Libya', '利比亚'],
    ];
}

function crm_customer_region_codes(): array
{
    return [
        'asia|asian|亚洲|亚州|亞州' => ['CN','HK','TW','MO','JP','KR','SG','MY','TH','VN','ID','PH','MM','KH','LA','BN','IN','PK','BD','LK','NP','AE','SA','QA','KW','BH','OM','IR','IQ','IL','JO','LB','SY','YE','TR'],
        'east asia|东北亚|東北亞|东亚|東亞' => ['CN','HK','TW','MO','JP','KR'],
        'southeast asia|asean|东南亚|東南亞|东盟|東盟' => ['SG','MY','TH','VN','ID','PH','MM','KH','LA','BN'],
        'south asia|南亚|南亞' => ['IN','PK','BD','LK','NP'],
        'middle east|mena|gcc|gulf|中东|中東|海湾|海灣|海合会|海合會' => ['AE','SA','QA','KW','BH','OM','IR','IQ','IL','JO','LB','SY','YE','TR','EG'],
        'europe|eu|欧洲|歐洲|欧盟|歐盟' => ['GB','IE','FR','DE','IT','ES','PT','NL','BE','LU','CH','AT','PL','CZ','SK','HU','RO','BG','GR','DK','SE','NO','FI','RU','UA','BY','RS','HR','SI'],
        'north america|北美' => ['US','CA','MX'],
        'latin america|south america|拉美|南美' => ['MX','BR','AR','CL','CO','PE','VE','EC','UY','PY','BO'],
        'africa|非洲' => ['ZA','NG','KE','GH','ET','TZ','UG','SD','MA','DZ','TN','LY','EG'],
        'oceania|australia new zealand|大洋洲|澳洲' => ['AU','NZ'],
    ];
}

function crm_customer_lower(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function crm_customer_contains(string $haystack, string $needle): bool
{
    if ($needle === '') return true;
    return function_exists('mb_strpos') ? mb_strpos($haystack, $needle) !== false : strpos($haystack, $needle) !== false;
}

function crm_customer_search_terms(string $term): array
{
    $term = trim($term);
    if ($term === '') return [];
    $terms = [$term];
    $aliases = crm_customer_country_aliases();
    $needle = crm_customer_lower($term);
    $exactCountryCodes = [];
    foreach ($aliases as $code => $items) {
        foreach (array_merge([$code], $items) as $alias) {
            if ($needle === crm_customer_lower(trim((string)$alias))) {
                $exactCountryCodes[] = $code;
                break;
            }
        }
    }
    if ($exactCountryCodes) {
        foreach (array_values(array_unique($exactCountryCodes)) as $code) {
            $terms = array_merge($terms, $aliases[$code] ?? [$code]);
        }
        return array_slice(array_values(array_unique(array_filter(array_map('trim', $terms)))), 0, 220);
    }
    foreach ($aliases as $code => $items) {
        $haystack = crm_customer_lower($code . ' ' . implode(' ', $items));
        if ($needle === crm_customer_lower($code) || crm_customer_contains($haystack, $needle)) $terms = array_merge($terms, $items);
    }
    foreach (crm_customer_region_codes() as $aliasText => $codes) {
        foreach (array_map('trim', explode('|', $aliasText)) as $alias) {
            if ($alias === '') continue;
            $aliasNeedle = crm_customer_lower($alias);
            if (crm_customer_contains($aliasNeedle, $needle) || crm_customer_contains($needle, $aliasNeedle)) {
                foreach ($codes as $code) $terms = array_merge($terms, $aliases[$code] ?? [$code]);
                break 2;
            }
        }
    }
    foreach (crm_dictionary_items('country_region', false) as $item) {
        $itemTerms = array_filter([(string)($item['item_key'] ?? ''), (string)($item['name_cn'] ?? ''), (string)($item['name_en'] ?? ''), (string)($item['short_name'] ?? '')]);
        if (crm_customer_contains(crm_customer_lower(implode(' ', $itemTerms)), $needle)) $terms = array_merge($terms, $itemTerms);
    }
    return array_slice(array_values(array_unique(array_filter(array_map('trim', $terms)))), 0, 220);
}

function crm_customer_region_search_codes(string $term): array
{
    $needle = crm_customer_lower(trim($term));
    if ($needle === '') return [];
    foreach (crm_customer_region_codes() as $aliasText => $codes) {
        foreach (array_map('trim', explode('|', $aliasText)) as $alias) {
            if ($alias !== '' && $needle === crm_customer_lower($alias)) {
                return array_values(array_unique($codes));
            }
        }
    }
    return [];
}

function crm_customer_region_search_sql(string $term, string $customerAlias = 'c'): array
{
    $codes = crm_customer_region_search_codes($term);
    if (!$codes) return ['', []];
    $aliases = crm_customer_country_aliases();
    $values = [];
    foreach ($codes as $code) {
        $values[] = $code;
        foreach ($aliases[$code] ?? [] as $alias) $values[] = $alias;
    }
    $values = array_values(array_unique(array_filter(array_map('trim', $values))));
    if (!$values) return ['', []];
    $placeholders = implode(',', array_fill(0, count($values), '?'));
    return [
        "({$customerAlias}.country IN ({$placeholders}) OR EXISTS (SELECT 1 FROM crm_customer_addresses a_region WHERE a_region.customer_id = {$customerAlias}.id AND a_region.country IN ({$placeholders})))",
        array_merge($values, $values)
    ];
}

function crm_customer_exact_location_term(string $term): bool
{
    $term = trim($term);
    if ($term === '') return false;
    if (preg_match('/^[A-Za-z]{2,3}$/', $term)) return true;
    $needle = crm_customer_lower($term);
    foreach (crm_customer_country_aliases() as $code => $items) {
        foreach (array_merge([$code], $items) as $alias) {
            if ($needle === crm_customer_lower(trim((string)$alias))) return true;
        }
    }
    foreach (crm_dictionary_items('country_region', false) as $item) {
        foreach ([(string)($item['item_key'] ?? ''), (string)($item['name_cn'] ?? ''), (string)($item['name_en'] ?? ''), (string)($item['short_name'] ?? '')] as $alias) {
            if ($alias !== '' && $needle === crm_customer_lower(trim($alias))) return true;
        }
    }
    foreach (crm_dictionary_items('city_region', false) as $item) {
        $extra = $item['extra_config'] ?? [];
        $aliases = is_array($extra['aliases'] ?? null) ? $extra['aliases'] : [];
        foreach (array_merge([(string)($item['item_key'] ?? ''), (string)($item['name_cn'] ?? ''), (string)($item['name_en'] ?? ''), (string)($item['short_name'] ?? '')], $aliases) as $alias) {
            if ($alias !== '' && $needle === crm_customer_lower(trim((string)$alias))) return true;
        }
    }
    return false;
}

function crm_customer_country_search_sql(array $terms, string $customerAlias = 'c'): array
{
    $parts = [];
    $params = [];
    foreach ($terms as $term) {
        $term = trim((string)$term);
        if ($term === '') continue;
        if (crm_customer_exact_location_term($term)) {
            $parts[] = "({$customerAlias}.country = ? OR {$customerAlias}.city = ? OR EXISTS (SELECT 1 FROM crm_customer_addresses a_search WHERE a_search.customer_id = {$customerAlias}.id AND (a_search.country = ? OR a_search.city = ?)))";
            array_push($params, $term, $term, $term, $term);
        } else {
            $like = '%' . $term . '%';
            $parts[] = "({$customerAlias}.country LIKE ? OR {$customerAlias}.city LIKE ? OR EXISTS (SELECT 1 FROM crm_customer_addresses a_search WHERE a_search.customer_id = {$customerAlias}.id AND (a_search.country LIKE ? OR a_search.city LIKE ?)))";
            array_push($params, $like, $like, $like, $like);
        }
    }
    return $parts ? ['(' . implode(' OR ', $parts) . ')', $params] : ['1 = 0', []];
}

function crm_customer_apply_graph(int $customerId, array $graph): void
{
    crm_customer_sync_addresses($customerId, $graph['addresses']);
    crm_customer_sync_key_table($customerId, 'crm_customer_source_tags', 'source_key', $graph['source_tags']);
    crm_customer_sync_key_table($customerId, 'crm_customer_promotion_channels', 'channel_key', $graph['promotion_channels']);
    crm_customer_sync_promotion_status($customerId, $graph['promotion_status']);
    crm_customer_sync_owners($customerId, $graph['owners']);
}

function crm_customer_list(array $input): array
{
    crm_customer_ensure_tables();
    $params = [];
    $where = [crm_customer_scope_sql($params)];
    $deleted = ($input['deleted'] ?? '') === '1';
    $where[] = $deleted ? 'c.deleted_at IS NOT NULL' : 'c.deleted_at IS NULL';
    $q = trim((string)($input['q'] ?? ''));
    if ($q !== '') {
        [$countrySearchSql, $countrySearchParams] = crm_customer_country_search_sql(crm_customer_search_terms($q), 'c');
        [$regionSearchSql, $regionSearchParams] = crm_customer_region_search_sql($q, 'c');
        $locationSql = $regionSearchSql !== '' ? '(' . $regionSearchSql . ' OR ' . $countrySearchSql . ')' : $countrySearchSql;
        $where[] = '(c.customer_code LIKE ? OR c.customer_name LIKE ? OR c.customer_name_en LIKE ? OR c.email LIKE ? OR c.phone LIKE ? OR c.whatsapp LIKE ? OR c.website LIKE ? OR c.remark LIKE ? OR ' . $locationSql . ' OR EXISTS (SELECT 1 FROM crm_contacts ct WHERE ct.customer_id = c.id AND ct.deleted_at IS NULL AND (ct.name LIKE ? OR ct.email LIKE ? OR ct.phone LIKE ? OR ct.whatsapp LIKE ?)))';
        for ($i = 0; $i < 8; $i++) $params[] = '%' . $q . '%';
        foreach ($regionSearchParams as $value) $params[] = $value;
        foreach ($countrySearchParams as $value) $params[] = $value;
        for ($i = 0; $i < 4; $i++) $params[] = '%' . $q . '%';
    }
    foreach (['level','status'] as $field) {
        if (($input[$field] ?? '') !== '') {
            $where[] = 'c.' . $field . ' = ?';
            $params[] = $input[$field];
        }
    }
    if (($input['lifecycle'] ?? '') !== '') {
        $where[] = 'c.lifecycle_key = ?';
        $params[] = trim((string)$input['lifecycle']);
    }
    if (($input['country'] ?? '') !== '') {
        $countryTerm = trim((string)$input['country']);
        [$countryWhere, $countryParams] = crm_customer_country_search_sql(crm_customer_search_terms($countryTerm), 'c');
        foreach ($countryParams as $value) $params[] = $value;
        $where[] = $countryWhere;
    }
    if (($input['city'] ?? '') !== '') {
        $cityTerm = trim((string)$input['city']);
        $cityValues = [];
        $needle = strtolower($cityTerm);
        foreach (crm_dictionary_items('city_region', false) as $cityItem) {
            $extra = $cityItem['extra_config'] ?? [];
            $aliases = is_array($extra['aliases'] ?? null) ? implode(' ', $extra['aliases']) : '';
            $haystack = strtolower(($cityItem['item_key'] ?? '') . ' ' . ($cityItem['name_cn'] ?? '') . ' ' . ($cityItem['name_en'] ?? '') . ' ' . ($cityItem['short_name'] ?? '') . ' ' . ($extra['country'] ?? '') . ' ' . $aliases);
            if (strpos($haystack, $needle) !== false) {
                foreach (['item_key','name_cn','name_en','short_name'] as $field) {
                    $value = trim((string)($cityItem[$field] ?? ''));
                    if ($value !== '') $cityValues[] = $value;
                }
                foreach (($extra['aliases'] ?? []) as $alias) {
                    $value = trim((string)$alias);
                    if ($value !== '') $cityValues[] = $value;
                }
            }
        }
        $cityValues = array_values(array_unique($cityValues));
        $cityWhere = '(c.city LIKE ? OR EXISTS (SELECT 1 FROM crm_customer_addresses a WHERE a.customer_id = c.id AND a.city LIKE ?))';
        $params[] = '%' . $cityTerm . '%';
        $params[] = '%' . $cityTerm . '%';
        if ($cityValues) {
            $placeholders = implode(',', array_fill(0, count($cityValues), '?'));
            $cityWhere = '(' . $cityWhere . " OR c.city IN ({$placeholders}) OR EXISTS (SELECT 1 FROM crm_customer_addresses a2 WHERE a2.customer_id = c.id AND a2.city IN ({$placeholders})))";
            foreach ($cityValues as $value) $params[] = $value;
            foreach ($cityValues as $value) $params[] = $value;
        }
        $where[] = $cityWhere;
    }
    $sourceInput = $input['source'] ?? '';
    $sourceKeys = is_array($sourceInput) ? $sourceInput : array_filter(array_map('trim', explode(',', (string)$sourceInput)));
    $sourceKeys = array_values(array_unique(array_filter(array_map('strval', $sourceKeys))));
    if ($sourceKeys) {
        $placeholders = implode(',', array_fill(0, count($sourceKeys), '?'));
        $where[] = "(c.source IN ({$placeholders}) OR EXISTS (SELECT 1 FROM crm_customer_source_tags s WHERE s.customer_id = c.id AND s.source_key IN ({$placeholders})))";
        foreach ($sourceKeys as $sourceKey) $params[] = $sourceKey;
        foreach ($sourceKeys as $sourceKey) $params[] = $sourceKey;
    }
    if (($input['promotion_status'] ?? '') !== '') {
        $where[] = 'COALESCE(ps.status, "not_promoted") = ?';
        $params[] = trim((string)$input['promotion_status']);
    }
    if (($input['owner_user_id'] ?? '') !== '') {
        $where[] = crm_customer_owner_match_sql((int)$input['owner_user_id'], $params);
    }
    $createdRange = trim((string)($input['created_range'] ?? ''));
    if ($createdRange === 'today') {
        $where[] = 'DATE(c.created_at) = CURDATE()';
    } elseif ($createdRange === '7d') {
        $where[] = 'c.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
    } elseif ($createdRange === 'month') {
        $where[] = 'DATE_FORMAT(c.created_at, "%Y-%m") = DATE_FORMAT(CURDATE(), "%Y-%m")';
    }
    $followRange = trim((string)($input['follow_range'] ?? ''));
    if ($followRange === 'today') {
        $where[] = 'EXISTS (SELECT 1 FROM crm_customer_followups f WHERE f.customer_id = c.id AND f.deleted_at IS NULL AND DATE(f.followup_time) = CURDATE())';
    } elseif ($followRange === '7d_missing') {
        $where[] = 'NOT EXISTS (SELECT 1 FROM crm_customer_followups f WHERE f.customer_id = c.id AND f.deleted_at IS NULL AND f.followup_time >= DATE_SUB(NOW(), INTERVAL 7 DAY))';
    } elseif ($followRange === '15d_missing') {
        $where[] = 'NOT EXISTS (SELECT 1 FROM crm_customer_followups f WHERE f.customer_id = c.id AND f.deleted_at IS NULL AND f.followup_time >= DATE_SUB(NOW(), INTERVAL 15 DAY))';
    } elseif ($followRange === '30d_missing') {
        $where[] = 'NOT EXISTS (SELECT 1 FROM crm_customer_followups f WHERE f.customer_id = c.id AND f.deleted_at IS NULL AND f.followup_time >= DATE_SUB(NOW(), INTERVAL 30 DAY))';
    }
    $businessFilter = trim((string)($input['business_filter'] ?? ''));
    if ($businessFilter === '有联系人') {
        $where[] = 'EXISTS (SELECT 1 FROM crm_contacts ct WHERE ct.customer_id = c.id AND ct.deleted_at IS NULL)';
    } elseif ($businessFilter === '有邮箱' || $businessFilter === '有邮件') {
        $where[] = "((c.email IS NOT NULL AND c.email <> '') OR EXISTS (SELECT 1 FROM crm_contacts ct WHERE ct.customer_id = c.id AND ct.deleted_at IS NULL AND ct.email IS NOT NULL AND ct.email <> ''))";
    } elseif ($businessFilter === '有资料') {
        $where[] = 'EXISTS (SELECT 1 FROM crm_customer_files cf WHERE cf.customer_id = c.id AND cf.deleted_at IS NULL)';
    } elseif ($businessFilter === '有报价') {
        if (crm_table_exists_safe('quote_orders')) {
            $where[] = crm_customer_quote_exists_condition();
        } else {
            $where[] = '1 = 0';
        }
    }
    $quick = trim((string)($input['quick_filter'] ?? ''));
    if ($quick === 'all') {
        // 明确的“全部”状态，不附加快捷筛选条件。
    } elseif ($quick === 'today' || $quick === '今天新增') {
        $where[] = 'DATE(c.created_at) = CURDATE()';
    } elseif ($quick === '3 天新增') {
        $where[] = 'c.created_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)';
    } elseif ($quick === '7d' || $quick === '7天新增' || $quick === '7 天新增') {
        $where[] = 'c.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
    } elseif ($quick === '本月新增') {
        $where[] = 'DATE_FORMAT(c.created_at, "%Y-%m") = DATE_FORMAT(CURDATE(), "%Y-%m")';
    } elseif ($quick === '今天跟进') {
        $where[] = 'EXISTS (SELECT 1 FROM crm_customer_followups f WHERE f.customer_id = c.id AND f.deleted_at IS NULL AND DATE(f.followup_time) = CURDATE())';
    } elseif ($quick === '7天未跟进' || $quick === '7 天未跟进') {
        $where[] = 'NOT EXISTS (SELECT 1 FROM crm_customer_followups f WHERE f.customer_id = c.id AND f.deleted_at IS NULL AND f.followup_time >= DATE_SUB(NOW(), INTERVAL 7 DAY))';
    } elseif ($quick === '15 天未跟进') {
        $where[] = 'NOT EXISTS (SELECT 1 FROM crm_customer_followups f WHERE f.customer_id = c.id AND f.deleted_at IS NULL AND f.followup_time >= DATE_SUB(NOW(), INTERVAL 15 DAY))';
    } elseif ($quick === '30 天未跟进') {
        $where[] = 'NOT EXISTS (SELECT 1 FROM crm_customer_followups f WHERE f.customer_id = c.id AND f.deleted_at IS NULL AND f.followup_time >= DATE_SUB(NOW(), INTERVAL 30 DAY))';
    } elseif ($quick === '有联系人') {
        $where[] = 'EXISTS (SELECT 1 FROM crm_contacts ct WHERE ct.customer_id = c.id AND ct.deleted_at IS NULL)';
    } elseif ($quick === 'has_code' || $quick === '有客户代码' || $quick === '有代码') {
        $where[] = 'c.customer_code IS NOT NULL AND c.customer_code <> ""';
    } elseif ($quick === 'has_mail' || $quick === '有邮箱' || $quick === '有邮件') {
        $where[] = "((c.email IS NOT NULL AND c.email <> '') OR EXISTS (SELECT 1 FROM crm_contacts ct WHERE ct.customer_id = c.id AND ct.deleted_at IS NULL AND ct.email IS NOT NULL AND ct.email <> ''))";
    } elseif ($quick === 'has_material' || $quick === '有资料' || $quick === '有资料包') {
        $where[] = 'EXISTS (SELECT 1 FROM crm_customer_files cf WHERE cf.customer_id = c.id AND cf.deleted_at IS NULL)';
    } elseif ($quick === 'has_quote' || $quick === '有报价') {
        if (crm_table_exists_safe('quote_orders')) {
            $where[] = crm_customer_quote_exists_condition();
        } else {
            $where[] = '1 = 0';
        }
    } elseif ($quick === 'public' || $quick === '公海客户') {
        $where[] = '(c.owner_user_id IS NULL AND NOT EXISTS (SELECT 1 FROM crm_customer_owners own WHERE own.customer_id = c.id))';
    } elseif ($quick === 'no_owner' || $quick === '无负责人客户') {
        $where[] = '(c.owner_user_id IS NULL AND NOT EXISTS (SELECT 1 FROM crm_customer_owners own WHERE own.customer_id = c.id))';
    } elseif ($quick === 'no_contact' || $quick === '无联系人客户') {
        $where[] = 'NOT EXISTS (SELECT 1 FROM crm_contacts ct WHERE ct.customer_id = c.id AND ct.deleted_at IS NULL)';
    } elseif ($quick === 'no_email' || $quick === '无邮箱客户') {
        $where[] = "((c.email IS NULL OR c.email = '') AND NOT EXISTS (SELECT 1 FROM crm_contacts ct WHERE ct.customer_id = c.id AND ct.deleted_at IS NULL AND ct.email IS NOT NULL AND ct.email <> ''))";
    } elseif ($quick === 'incomplete' || $quick === '资料不完整客户' || $quick === '资料缺失客户') {
        $noContactSql = 'NOT EXISTS (SELECT 1 FROM crm_contacts ct WHERE ct.customer_id = c.id AND ct.deleted_at IS NULL)';
        $noEmailSql = "((c.email IS NULL OR c.email = '') AND NOT EXISTS (SELECT 1 FROM crm_contacts ct2 WHERE ct2.customer_id = c.id AND ct2.deleted_at IS NULL AND ct2.email IS NOT NULL AND ct2.email <> ''))";
        $where[] = "(c.city = '' OR c.city IS NULL OR {$noEmailSql} OR {$noContactSql} OR NOT EXISTS (SELECT 1 FROM crm_customer_group_relations gr WHERE gr.customer_id = c.id))";
    } elseif ($quick === 'duplicate' || $quick === '疑似重复客户') {
        $where[] = "((c.email IS NOT NULL AND c.email <> '' AND EXISTS (SELECT 1 FROM crm_customers d WHERE d.deleted_at IS NULL AND d.id <> c.id AND d.email = c.email))
            OR (c.website IS NOT NULL AND c.website <> '' AND EXISTS (SELECT 1 FROM crm_customers d2 WHERE d2.deleted_at IS NULL AND d2.id <> c.id AND d2.website = c.website)))";
    } elseif ($quick === 'mine' || $quick === '我的客户') {
        $where[] = crm_customer_owner_match_sql((int)(current_user()['id'] ?? 0), $params);
    }
    $pageSize = max(20, min(200, (int)($input['page_size'] ?? 50)));
    $page = max(1, (int)($input['page'] ?? 1));
    $sortMap = ['updated_at'=>'c.updated_at','customer_code'=>'c.customer_code','customer_name'=>'c.customer_name','country'=>'c.country','created_at'=>'c.created_at','last_followup'=>'(SELECT MAX(f.followup_time) FROM crm_customer_followups f WHERE f.customer_id = c.id AND f.deleted_at IS NULL)'];
    $sort = $sortMap[$input['sort'] ?? 'updated_at'] ?? 'c.updated_at';
    $dir = strtoupper((string)($input['dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
    $sqlWhere = implode(' AND ', $where);
    $countJoin = ($input['promotion_status'] ?? '') !== '' ? ' LEFT JOIN crm_customer_promotion_status ps ON ps.customer_id = c.id' : '';
    $countStmt = db()->prepare("SELECT COUNT(*) FROM crm_customers c{$countJoin} WHERE {$sqlWhere}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    $offset = ($page - 1) * $pageSize;
    $sql = "SELECT c.*,
        COALESCE(pa.country, c.country) AS country,
        COALESCE(pa.city, c.city) AS city,
        COALESCE(pa.address, c.address) AS address,
        u.username AS owner_name, c.owner_department AS owner_department_name,
        0 AS contact_count,
        NULL AS last_followup_at,
        '' AS group_names,
        '' AS source_tags,
        '' AS promotion_channels,
        COALESCE(ps.status, 'not_promoted') AS promotion_status
        FROM crm_customers c
        LEFT JOIN crm_customer_addresses pa ON pa.customer_id = c.id AND pa.is_primary = 1
        LEFT JOIN crm_customer_owners po ON po.customer_id = c.id AND po.is_primary = 1
        LEFT JOIN crm_users u ON u.id = COALESCE(po.user_id, c.owner_user_id)
        LEFT JOIN crm_customer_promotion_status ps ON ps.customer_id = c.id
        WHERE {$sqlWhere}
        ORDER BY {$sort} {$dir}, c.id DESC LIMIT {$pageSize} OFFSET {$offset}";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $ids = array_values(array_filter(array_map(static fn($row) => (int)($row['id'] ?? 0), $rows)));
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $contactStmt = db()->prepare("SELECT customer_id, COUNT(*) AS contact_count FROM crm_contacts WHERE customer_id IN ({$placeholders}) AND deleted_at IS NULL GROUP BY customer_id");
        $contactStmt->execute($ids);
        $contactCounts = [];
        foreach ($contactStmt->fetchAll() as $row) $contactCounts[(int)$row['customer_id']] = (int)$row['contact_count'];

        $followStmt = db()->prepare("SELECT customer_id, MAX(followup_time) AS last_followup_at FROM crm_customer_followups WHERE customer_id IN ({$placeholders}) AND deleted_at IS NULL GROUP BY customer_id");
        $followStmt->execute($ids);
        $lastFollowups = [];
        foreach ($followStmt->fetchAll() as $row) $lastFollowups[(int)$row['customer_id']] = (string)$row['last_followup_at'];

        $groupStmt = db()->prepare("SELECT r.customer_id, GROUP_CONCAT(g.group_name ORDER BY g.sort_order SEPARATOR ', ') AS group_names FROM crm_customer_group_relations r JOIN crm_customer_groups g ON g.id = r.group_id AND g.deleted_at IS NULL WHERE r.customer_id IN ({$placeholders}) GROUP BY r.customer_id");
        $groupStmt->execute($ids);
        $groupNames = [];
        foreach ($groupStmt->fetchAll() as $row) $groupNames[(int)$row['customer_id']] = (string)$row['group_names'];

        $sourceStmt = db()->prepare("SELECT customer_id, GROUP_CONCAT(source_key ORDER BY id SEPARATOR ',') AS source_tags FROM crm_customer_source_tags WHERE customer_id IN ({$placeholders}) GROUP BY customer_id");
        $sourceStmt->execute($ids);
        $sourceTags = [];
        foreach ($sourceStmt->fetchAll() as $row) $sourceTags[(int)$row['customer_id']] = (string)$row['source_tags'];

        $promotionStmt = db()->prepare("SELECT customer_id, GROUP_CONCAT(channel_key ORDER BY id SEPARATOR ',') AS promotion_channels FROM crm_customer_promotion_channels WHERE customer_id IN ({$placeholders}) GROUP BY customer_id");
        $promotionStmt->execute($ids);
        $promotionChannels = [];
        foreach ($promotionStmt->fetchAll() as $row) $promotionChannels[(int)$row['customer_id']] = (string)$row['promotion_channels'];

        foreach ($rows as &$row) {
            $customerId = (int)($row['id'] ?? 0);
            $row['contact_count'] = $contactCounts[$customerId] ?? 0;
            $row['last_followup_at'] = $lastFollowups[$customerId] ?? null;
            $row['group_names'] = $groupNames[$customerId] ?? '';
            $row['source_tags'] = $sourceTags[$customerId] ?? '';
            $row['promotion_channels'] = $promotionChannels[$customerId] ?? '';
        }
        unset($row);
    }
    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'page_size' => $pageSize];
}

function crm_customer_overview_stats(): array
{
    crm_customer_ensure_tables();
    crm_require('customer.view');
    $params = [];
    $scope = crm_customer_scope_sql($params);
    $where = implode(' AND ', ['c.deleted_at IS NULL', $scope]);
    $baseParams = $params;

    $noOwnerSql = '(c.owner_user_id IS NULL AND NOT EXISTS (SELECT 1 FROM crm_customer_owners own WHERE own.customer_id = c.id))';
    $noContactSql = 'NOT EXISTS (SELECT 1 FROM crm_contacts ct WHERE ct.customer_id = c.id AND ct.deleted_at IS NULL)';
    $noEmailSql = "((c.email IS NULL OR c.email = '') AND NOT EXISTS (SELECT 1 FROM crm_contacts ct WHERE ct.customer_id = c.id AND ct.deleted_at IS NULL AND ct.email IS NOT NULL AND ct.email <> ''))";
    $incompleteSql = "(c.city = '' OR c.city IS NULL OR {$noEmailSql} OR {$noContactSql} OR NOT EXISTS (SELECT 1 FROM crm_customer_group_relations gr WHERE gr.customer_id = c.id))";
    $staleFollowSql = 'NOT EXISTS (SELECT 1 FROM crm_customer_followups f WHERE f.customer_id = c.id AND f.deleted_at IS NULL AND f.followup_time >= DATE_SUB(NOW(), INTERVAL 30 DAY))';

    $countWhere = function (string $extra = '', array $extraParams = []) use ($where, $baseParams): int {
        $sql = "SELECT COUNT(*) FROM crm_customers c WHERE {$where}" . ($extra !== '' ? " AND ({$extra})" : '');
        $stmt = db()->prepare($sql);
        $stmt->execute(array_merge($baseParams, $extraParams));
        return (int)$stmt->fetchColumn();
    };
    $customerRows = function (string $extra = '', array $extraParams = [], int $limit = 3, string $orderBy = 'updated') use ($where, $baseParams): array {
        $orderSql = $orderBy === 'created' ? 'c.created_at DESC, c.id DESC' : 'COALESCE(c.updated_at, c.created_at) DESC, c.id DESC';
        $sql = "SELECT c.id, c.customer_name, c.customer_code, COALESCE(pa.country, c.country) AS country, COALESCE(pa.city, c.city) AS city,
                COALESCE(u.username, c.owner_department, '') AS owner_name, COALESCE(c.source, '') AS source, c.created_at, c.updated_at
            FROM crm_customers c
            LEFT JOIN crm_customer_addresses pa ON pa.customer_id = c.id AND pa.is_primary = 1
            LEFT JOIN crm_customer_owners po ON po.customer_id = c.id AND po.is_primary = 1
            LEFT JOIN crm_users u ON u.id = COALESCE(po.user_id, c.owner_user_id)
            WHERE {$where}" . ($extra !== '' ? " AND ({$extra})" : '') . "
            ORDER BY {$orderSql}
            LIMIT {$limit}";
        $stmt = db()->prepare($sql);
        $stmt->execute(array_merge($baseParams, $extraParams));
        return array_map(static fn($row) => [
            'id' => (int)$row['id'],
            'customer_name' => (string)($row['customer_name'] ?: '未命名客户'),
            'customer_code' => (string)($row['customer_code'] ?? ''),
            'country' => (string)($row['country'] ?: '未填'),
            'city' => (string)($row['city'] ?: ''),
            'owner_name' => (string)($row['owner_name'] ?: '未分配'),
            'source' => (string)($row['source'] ?: 'unknown'),
            'created_at' => (string)($row['created_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? ''),
        ], $stmt->fetchAll());
    };

    $countryStmt = db()->prepare("SELECT COALESCE(NULLIF(c.country, ''), '未填') AS label, COUNT(*) AS value FROM crm_customers c WHERE {$where} GROUP BY COALESCE(NULLIF(c.country, ''), '未填') ORDER BY value DESC, label ASC LIMIT 8");
    $countryStmt->execute($params);
    $countries = array_map(fn($row) => ['label' => (string)$row['label'], 'value' => (int)$row['value']], $countryStmt->fetchAll());

    $sourceStmt = db()->prepare("SELECT COALESCE(NULLIF(c.source, ''), 'unknown') AS label, COUNT(*) AS value FROM crm_customers c WHERE {$where} GROUP BY COALESCE(NULLIF(c.source, ''), 'unknown') ORDER BY value DESC, label ASC LIMIT 8");
    $sourceStmt->execute($params);
    $sources = array_map(fn($row) => ['label' => (string)$row['label'], 'value' => (int)$row['value']], $sourceStmt->fetchAll());

    $ownerStmt = db()->prepare("SELECT COALESCE(u.username, c.owner_department, '未分配') AS label,
            COALESCE(po.user_id, c.owner_user_id, 0) AS owner_id,
            COUNT(DISTINCT c.id) AS value,
            SUM(CASE WHEN EXISTS (SELECT 1 FROM crm_customer_followups f WHERE f.customer_id = c.id AND f.deleted_at IS NULL AND f.followup_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)) THEN 1 ELSE 0 END) AS active_count,
            SUM(CASE WHEN NOT EXISTS (SELECT 1 FROM crm_customer_followups f WHERE f.customer_id = c.id AND f.deleted_at IS NULL AND f.followup_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)) THEN 1 ELSE 0 END) AS stale_count
        FROM crm_customers c
        LEFT JOIN crm_customer_owners po ON po.customer_id = c.id AND po.is_primary = 1
        LEFT JOIN crm_users u ON u.id = COALESCE(po.user_id, c.owner_user_id)
        WHERE {$where}
        GROUP BY COALESCE(po.user_id, c.owner_user_id, 0), COALESCE(u.username, c.owner_department, '未分配')
        ORDER BY value DESC, label ASC LIMIT 8");
    $ownerStmt->execute($params);
    $owners = array_map(fn($row) => [
        'label' => (string)$row['label'],
        'owner_id' => (int)$row['owner_id'],
        'value' => (int)$row['value'],
        'active_count' => (int)$row['active_count'],
        'stale_count' => (int)$row['stale_count'],
    ], $ownerStmt->fetchAll());

    $ranges = [
        ['key' => 'today', 'label' => '今天', 'sql' => 'DATE(c.created_at) = CURDATE()'],
        ['key' => '3d', 'label' => '3天', 'sql' => 'c.created_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)'],
        ['key' => '7d', 'label' => '7天', 'sql' => 'c.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)'],
        ['key' => '1m', 'label' => '1个月', 'sql' => 'c.created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)'],
        ['key' => '3m', 'label' => '3个月', 'sql' => 'c.created_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH)'],
    ];
    $growth = [];
    foreach ($ranges as $range) {
        $stmt = db()->prepare("SELECT COUNT(*) FROM crm_customers c WHERE {$where} AND {$range['sql']}");
        $stmt->execute($params);
        $growth[] = ['key' => $range['key'], 'label' => $range['label'], 'value' => (int)$stmt->fetchColumn()];
    }
    $totalStmt = db()->prepare("SELECT COUNT(*) FROM crm_customers c WHERE {$where}");
    $totalStmt->execute($params);
    $total = (int)$totalStmt->fetchColumn();
    $leadPoolCount = 0;
    if (crm_table_exists_safe('crm_lead_pool')) {
        $leadPoolCount = (int)db()->query("SELECT COUNT(*) FROM crm_lead_pool WHERE status = 'pending'")->fetchColumn();
    }
    $duplicateSql = "((c.email IS NOT NULL AND c.email <> '' AND EXISTS (SELECT 1 FROM crm_customers d WHERE d.deleted_at IS NULL AND d.id <> c.id AND d.email = c.email))
        OR (c.website IS NOT NULL AND c.website <> '' AND EXISTS (SELECT 1 FROM crm_customers d2 WHERE d2.deleted_at IS NULL AND d2.id <> c.id AND d2.website = c.website)))";

    return [
        'total' => $total,
        'refreshed_at' => date('Y-m-d H:i:s'),
        'kpis' => [
            'total' => $total,
            'month_new' => $countWhere('DATE_FORMAT(c.created_at, "%Y-%m") = DATE_FORMAT(CURDATE(), "%Y-%m")'),
            'lead_pool' => $leadPoolCount,
            'public' => $countWhere($noOwnerSql),
            'no_owner' => $countWhere($noOwnerSql),
            'incomplete' => $countWhere($incompleteSql),
        ],
        'countries' => $countries,
        'sources' => $sources,
        'owners' => $owners,
        'growth' => $growth,
        'pending' => [
            ['key' => 'lead_pool', 'title' => '暂存池待确认', 'count' => $leadPoolCount, 'rows' => [], 'reason' => '待确认入库'],
            ['key' => 'duplicate', 'title' => '疑似重复客户', 'count' => $countWhere($duplicateSql), 'rows' => $customerRows($duplicateSql, [], 3), 'reason' => '邮箱或网站重复'],
            ['key' => 'incomplete', 'title' => '资料缺失客户', 'count' => $countWhere($incompleteSql), 'rows' => $customerRows($incompleteSql, [], 3), 'reason' => '资料不完整'],
            ['key' => 'no_contact', 'title' => '无联系人客户', 'count' => $countWhere($noContactSql), 'rows' => $customerRows($noContactSql, [], 3), 'reason' => '缺联系人'],
            ['key' => 'no_email', 'title' => '无邮箱客户', 'count' => $countWhere($noEmailSql), 'rows' => $customerRows($noEmailSql, [], 3), 'reason' => '缺联系人邮箱'],
            ['key' => 'stale_30d', 'title' => '30 天未跟进客户', 'count' => $countWhere($staleFollowSql), 'rows' => $customerRows($staleFollowSql, [], 3), 'reason' => '30 天未跟进'],
        ],
        'recent_created' => $customerRows('', [], 6, 'created'),
        'recent_updated' => $customerRows('', [], 6, 'updated'),
    ];
}

function crm_customer_get(int $id, string $detailMode = 'full'): array
{
    crm_customer_ensure_tables();
    $detailMode = strtolower(trim($detailMode));
    $light = in_array($detailMode, ['overview', 'light', 'summary'], true);
    $params = [];
    $scope = crm_customer_scope_sql($params);
    array_unshift($params, $id);
    $stmt = db()->prepare("SELECT c.*, COALESCE(pa.country, c.country) AS country, COALESCE(pa.city, c.city) AS city, COALESCE(pa.address, c.address) AS address, u.username AS owner_name FROM crm_customers c LEFT JOIN crm_customer_addresses pa ON pa.customer_id = c.id AND pa.is_primary = 1 LEFT JOIN crm_customer_owners po ON po.customer_id = c.id AND po.is_primary = 1 LEFT JOIN crm_users u ON u.id = COALESCE(po.user_id, c.owner_user_id) WHERE c.id = ? AND {$scope} LIMIT 1");
    $stmt->execute($params);
    $customer = $stmt->fetch();
    if (!$customer) throw new RuntimeException('客户不存在或无权查看。');
    $addresses = crm_customer_addresses($id);
    $sourceTags = crm_customer_tags($id, 'crm_customer_source_tags', 'source_key');
    $promotionChannels = crm_customer_tags($id, 'crm_customer_promotion_channels', 'channel_key');
    $promotionStatus = crm_customer_promotion_status($id);
    $owners = crm_customer_owners($id);
    $contacts = crm_contact_list(['customer_id' => $id])['rows'];
    $groups = crm_customer_groups_for($id);
    $lastFollowStmt = db()->prepare('SELECT MAX(followup_time) FROM crm_customer_followups WHERE customer_id = ? AND deleted_at IS NULL');
    $lastFollowStmt->execute([$id]);
    $lastFollowAt = $lastFollowStmt->fetchColumn();
    $scores = crm_customer_scores($id, $customer, [
        'contacts' => $contacts,
        'source_tags' => $sourceTags,
        'promotion_channels' => $promotionChannels,
        'owners' => $owners,
        'groups' => $groups,
        'last_followup_at' => $lastFollowAt,
    ]);
    $tabConfig = crm_customer_detail_tabs($customer);
    $linkage = crm_customer_linkage_summary($customer);
    $salesActions = crm_customer_sales_action_stats($id, $customer, $linkage);
    $base = [
        'customer' => $customer,
        'tab_config' => $tabConfig,
        'tabs' => $tabConfig['tabs'],
        'scores' => $scores,
        'protection' => crm_customer_protection($id),
        'addresses' => $addresses,
        'source_tags' => $sourceTags,
        'promotion_channels' => $promotionChannels,
        'promotion_status' => $promotionStatus,
        'owners' => $owners,
        'contacts' => $contacts,
        'groups' => $groups,
        'sales_actions' => $salesActions,
        'summary' => crm_customer_summary($id, $linkage),
        'linkage' => $linkage,
        '_lazy_detail' => $light ? 1 : 0,
    ];
    if ($light) {
        return $base + [
            'timeline' => [],
            'relations' => [],
            'events' => [],
            'product_preferences' => [],
            'communication_preferences' => [],
            'chat_groups' => [],
            'followups' => [],
            'visits' => [],
            'mail_rows' => [],
            'sample_shipments' => [],
            'opportunities' => [],
            'logs' => [],
        ];
    }
    return $base + [
        'timeline' => crm_customer_timeline($id),
        'relations' => crm_customer_relations($id),
        'events' => crm_customer_events($id),
        'product_preferences' => crm_customer_preference_row($id, 'crm_customer_product_preferences'),
        'communication_preferences' => crm_customer_preference_row($id, 'crm_customer_communication_preferences'),
        'chat_groups' => crm_customer_chat_groups($id),
        'followups' => crm_followup_list(['customer_id' => $id])['rows'],
        'visits' => function_exists('crm_visit_list') && has_permission('visit.view') ? crm_visit_list(['customer_id' => $id])['rows'] : [],
        'mail_rows' => crm_customer_mail_rows($id),
        'sample_shipments' => crm_customer_sample_shipments($id),
        'opportunities' => function_exists('crm_opportunity_list') && has_permission('opportunity.view') ? crm_opportunity_list(['customer_id' => $id])['rows'] : [],
        'logs' => crm_customer_logs($id),
    ];
}

function crm_customer_attribute_get(int $id): array
{
    $detail = crm_customer_get($id, 'full');
    return [
        'customer' => $detail['customer'] ?? [],
        'scores' => $detail['scores'] ?? [],
        'missing' => (($detail['scores'] ?? [])['completeness'] ?? [])['missing'] ?? [],
        'source_tags' => $detail['source_tags'] ?? [],
        'promotion_channels' => $detail['promotion_channels'] ?? [],
        'promotion_status' => $detail['promotion_status'] ?? '',
        'protection' => $detail['protection'] ?? [],
        'owners' => $detail['owners'] ?? [],
        'addresses' => $detail['addresses'] ?? [],
        'groups' => $detail['groups'] ?? [],
    ];
}

function crm_customer_attribute_missing(int $id): array
{
    $detail = crm_customer_attribute_get($id);
    return [
        'customer_id' => $id,
        'completeness' => ($detail['scores'] ?? [])['completeness'] ?? [],
        'missing' => $detail['missing'] ?? [],
    ];
}

function crm_customer_attribute_logs(int $id): array
{
    crm_customer_get($id, 'overview');
    return ['rows' => crm_customer_logs($id)];
}

function crm_customer_attribute_save(int $id, array $input): array
{
    if ($id <= 0) throw new RuntimeException('缺少客户 ID，无法保存客户属性。');
    $before = crm_customer_basic_row($id);
    $attributeBefore = crm_customer_attribute_get($id);
    $currentPromotionStatus = (string)($attributeBefore['promotion_status'] ?? 'not_promoted');
    $incomingPromotionStatus = array_key_exists('promotion_status', $input) ? trim((string)$input['promotion_status']) : $currentPromotionStatus;
    $incomingDoNotContact = array_key_exists('do_not_contact', $input) ? (!empty($input['do_not_contact']) ? 1 : 0) : (int)($before['do_not_contact'] ?? 0);
    $becomingBlacklist = ($currentPromotionStatus !== 'blacklist' && $incomingPromotionStatus === 'blacklist') || (!(int)($before['do_not_contact'] ?? 0) && $incomingDoNotContact);
    if ($becomingBlacklist && empty($input['blacklist_confirmed'])) {
        throw new RuntimeException('黑名单状态变更需要二次确认。');
    }
    $incomingPromotionChannels = array_key_exists('promotion_channels', $input) ? (array)$input['promotion_channels'] : (array)($attributeBefore['promotion_channels'] ?? []);
    $incomingNoPromotionReason = array_key_exists('no_promotion_reason', $input) ? trim((string)$input['no_promotion_reason']) : trim((string)($before['no_promotion_reason'] ?? ''));
    if (($incomingPromotionStatus === 'no_promotion' || in_array('no_promotion', $incomingPromotionChannels, true)) && $incomingNoPromotionReason === '') {
        throw new RuntimeException('设置不推广时必须填写不推广原因。');
    }
    $mergeFields = ['customer_code','customer_name','customer_name_en','country','city','address','website','email','backup_email','phone','whatsapp','wechat','linkedin','customer_domain','customer_type','industry','postal_code','shipping_address','billing_address','common_port','timezone','no_promotion_reason','blacklist_reason','customer_tags','visibility_scope','source','level','status','lifecycle_key','risk_level','do_not_contact','owner_user_id','owner_department','remark'];
    $merged = [];
    foreach ($mergeFields as $field) {
        $merged[$field] = array_key_exists($field, $input) ? $input[$field] : ($before[$field] ?? '');
    }
    $merged['customer_id'] = $id;
    $merged['promotion_status'] = $incomingPromotionStatus;
    $merged['source_tags'] = array_key_exists('source_tags', $input) ? (array)$input['source_tags'] : (array)($attributeBefore['source_tags'] ?? []);
    $merged['promotion_channels'] = $incomingPromotionChannels;
    if (!array_key_exists('group_ids', $input)) {
        $merged['group_ids'] = array_values(array_filter(array_map(fn($row) => (int)($row['id'] ?? $row['group_id'] ?? 0), $attributeBefore['groups'] ?? [])));
    } else {
        $merged['group_ids'] = (array)$input['group_ids'];
    }
    if (empty($input['owner_user_ids'])) {
        $ownerIds = array_values(array_filter(array_map(fn($row) => (int)($row['user_id'] ?? 0), $attributeBefore['owners'] ?? [])));
        $primaryOwnerId = (int)($merged['owner_user_id'] ?? 0);
        if ($primaryOwnerId > 0 && !in_array($primaryOwnerId, $ownerIds, true)) array_unshift($ownerIds, $primaryOwnerId);
        if ($ownerIds) $merged['owner_user_ids'] = $ownerIds;
    } else {
        $merged['owner_user_ids'] = $input['owner_user_ids'];
    }
    $result = crm_customer_update($id, $merged);
    $after = crm_customer_basic_row($id);
    $watched = ['customer_code','customer_name','customer_name_en','country','city','address','website','email','backup_email','phone','whatsapp','wechat','linkedin','customer_domain','customer_type','industry','postal_code','shipping_address','billing_address','common_port','timezone','no_promotion_reason','blacklist_reason','customer_tags','visibility_scope','source','level','status','lifecycle_key','risk_level','do_not_contact','owner_user_id','owner_department','remark'];
    $changes = [];
    foreach ($watched as $field) {
        $old = (string)($before[$field] ?? '');
        $new = (string)($after[$field] ?? '');
        if ($old !== $new) $changes[$field] = ['old' => $old, 'new' => $new];
    }
    if ($changes) {
        crm_customer_log('customer_attribute_save', 'customer', $id, $id, ['changes' => array_map(fn($v) => $v['old'], $changes)], ['changes' => array_map(fn($v) => $v['new'], $changes)], '保存客户属性');
    }
    $result['attribute_changes'] = $changes;
    $result['attribute'] = crm_customer_attribute_get($id);
    return $result;
}

function crm_customer_attribute_export(int $id): array
{
    $detail = crm_customer_get($id, 'full');
    crm_customer_log('customer_attribute_export', 'customer', $id, $id, null, ['customer_id' => $id], '导出客户属性');
    $c = $detail['customer'] ?? [];
    $rows = [
        ['分组', '字段', '值'],
        ['客户身份', '客户名称', $c['customer_name'] ?? ''],
        ['客户身份', '英文名称', $c['customer_name_en'] ?? ''],
        ['客户身份', '客户代码', $c['customer_code'] ?? ''],
        ['客户身份', '客户等级', $c['level'] ?? ''],
        ['客户身份', '生命周期', $c['lifecycle_key'] ?? ''],
        ['客户身份', '客户类型', $c['customer_type'] ?? ''],
        ['客户身份', '行业', $c['industry'] ?? ''],
        ['联系方式', '主邮箱', $c['email'] ?? ''],
        ['联系方式', '备用邮箱', $c['backup_email'] ?? ''],
        ['联系方式', '电话', $c['phone'] ?? ''],
        ['联系方式', 'WhatsApp', $c['whatsapp'] ?? ''],
        ['联系方式', '微信', $c['wechat'] ?? ''],
        ['联系方式', 'LinkedIn', $c['linkedin'] ?? ''],
        ['联系方式', '网站', $c['website'] ?? ''],
        ['联系方式', '客户域名', $c['customer_domain'] ?? ''],
        ['地址', '国家', $c['country'] ?? ''],
        ['地址', '城市', $c['city'] ?? ''],
        ['地址', '详细地址', $c['address'] ?? ''],
        ['地址', '邮编', $c['postal_code'] ?? ''],
        ['地址', '发货地址', $c['shipping_address'] ?? ''],
        ['地址', '账单地址', $c['billing_address'] ?? ''],
        ['地址', '常用港口', $c['common_port'] ?? ''],
        ['地址', '时区', $c['timezone'] ?? ''],
        ['来源推广', '来源', implode(',', $detail['source_tags'] ?? [])],
        ['来源推广', '推广方式', implode(',', $detail['promotion_channels'] ?? [])],
        ['来源推广', '推广状态', $detail['promotion_status'] ?? ''],
        ['来源推广', '是否允许联系', !empty($c['do_not_contact']) ? '否' : '是'],
        ['来源推广', '不推广原因', $c['no_promotion_reason'] ?? ''],
        ['来源推广', '黑名单原因', $c['blacklist_reason'] ?? ''],
        ['来源推广', '客户组', implode(',', array_map(fn($g) => $g['group_name'] ?? '', $detail['groups'] ?? []))],
        ['来源推广', '标签', $c['customer_tags'] ?? ''],
        ['负责人权限', '主负责人', $c['owner_name'] ?? ''],
        ['负责人权限', '可见范围', $c['visibility_scope'] ?? ''],
        ['状态信息', '创建时间', $c['created_at'] ?? ''],
        ['状态信息', '最近更新时间', $c['updated_at'] ?? ''],
        ['状态信息', '健康度', $detail['scores']['health'] ?? ''],
        ['状态信息', '资料完整度', $detail['scores']['completeness']['score'] ?? ''],
    ];
    $fp = fopen('php://temp', 'r+');
    fwrite($fp, "\xEF\xBB\xBF");
    foreach ($rows as $row) fputcsv($fp, $row);
    rewind($fp);
    $content = stream_get_contents($fp);
    fclose($fp);
    return [
        'filename' => 'customer_attribute_' . $id . '_' . date('Ymd_His') . '.csv',
        'content_type' => 'text/csv;charset=utf-8',
        'content' => $content,
    ];
}

function crm_customer_basic_row(int $id): array
{
    crm_customer_ensure_tables();
    $params = [];
    $scope = crm_customer_scope_sql($params);
    array_unshift($params, $id);
    $stmt = db()->prepare("SELECT c.*, COALESCE(pa.country, c.country) AS country, COALESCE(pa.city, c.city) AS city, COALESCE(pa.address, c.address) AS address, u.username AS owner_name FROM crm_customers c LEFT JOIN crm_customer_addresses pa ON pa.customer_id = c.id AND pa.is_primary = 1 LEFT JOIN crm_customer_owners po ON po.customer_id = c.id AND po.is_primary = 1 LEFT JOIN crm_users u ON u.id = COALESCE(po.user_id, c.owner_user_id) WHERE c.id = ? AND {$scope} LIMIT 1");
    $stmt->execute($params);
    $customer = $stmt->fetch();
    if (!$customer) throw new RuntimeException('客户不存在或无权查看。');
    return $customer;
}

function crm_customer_sample_shipments(int $customerId): array
{
    if (!db_table_exists('crm_sample_shipments')) return [];
    if (!is_super_admin() && !has_permission('sample.view') && !has_permission('task.view')) return [];
    $stmt = db()->prepare("SELECT s.*, ct.name AS contact_name, o.opportunity_name,
            (SELECT COUNT(*) FROM crm_sample_shipment_files f WHERE f.shipment_id=s.id AND f.deleted_at IS NULL AND f.file_type='image') AS image_count,
            (SELECT COUNT(*) FROM crm_sample_shipment_files f WHERE f.shipment_id=s.id AND f.deleted_at IS NULL AND f.file_type='attachment') AS attachment_count
        FROM crm_sample_shipments s
        LEFT JOIN crm_contacts ct ON ct.id=s.contact_id
        LEFT JOIN crm_opportunities o ON o.id=s.opportunity_id
        WHERE s.customer_id=? AND s.deleted_at IS NULL
        ORDER BY s.id DESC LIMIT 100");
    $stmt->execute([$customerId]);
    return $stmt->fetchAll();
}

function crm_customer_validate(array $input, int $ignoreId = 0): array
{
    if (!empty($input['addresses_json']) && empty($input['addresses'])) {
        $decodedAddresses = json_decode((string)$input['addresses_json'], true);
        if (is_array($decodedAddresses)) $input['addresses'] = $decodedAddresses;
    }
    $name = trim((string)($input['customer_name'] ?? ''));
    $country = trim((string)($input['country'] ?? ''));
    if ($name === '') throw new RuntimeException('客户名称必填。');
    if ($country === '') throw new RuntimeException('国家必填。');
    $email = trim((string)($input['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('邮箱格式不正确。');
    $backupEmail = trim((string)($input['backup_email'] ?? ''));
    if ($backupEmail !== '' && !filter_var($backupEmail, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('备用邮箱格式不正确。');
    $website = trim((string)($input['website'] ?? ''));
    if ($website !== '' && !preg_match('/^https?:\\/\\//i', $website)) $website = 'https://' . $website;
    if ($website !== '' && !filter_var($website, FILTER_VALIDATE_URL)) throw new RuntimeException('网站格式不正确。');
    $linkedin = trim((string)($input['linkedin'] ?? ''));
    if ($linkedin !== '' && !preg_match('/^https?:\\/\\//i', $linkedin)) $linkedin = 'https://' . $linkedin;
    if ($linkedin !== '' && !filter_var($linkedin, FILTER_VALIDATE_URL)) throw new RuntimeException('LinkedIn 地址格式不正确。');
    if (!$ignoreId) {
        $dupParams = [$name];
        $dupSql = 'customer_name = ? AND deleted_at IS NULL';
        $stmt = db()->prepare("SELECT id FROM crm_customers WHERE {$dupSql} LIMIT 1");
        $stmt->execute($dupParams);
        if ($stmt->fetchColumn() && ($input['entry_mode'] ?? '') !== 'force') throw new RuntimeException('已存在同名客户，请检查是否需要合并。');
    }
    $customerCode = trim((string)($input['customer_code'] ?? ''));
    if (!$ignoreId && $customerCode !== '') {
        $codeParams = [$customerCode];
        $codeSql = 'customer_code = ? AND deleted_at IS NULL';
        $codeStmt = db()->prepare("SELECT id FROM crm_customers WHERE {$codeSql} LIMIT 1");
        $codeStmt->execute($codeParams);
        if ($codeStmt->fetchColumn()) throw new RuntimeException('客户代码已存在，请检查编号。');
    }
    $options = crm_graph_options();
    $defaults = crm_rule_config('default_values');
    $level = trim((string)($input['level'] ?? ($defaults['customer_level'] ?? crm_dictionary_default('customer_level', 'P3')))) ?: crm_dictionary_default('customer_level', 'P3');
    if (!in_array($level, $options['levels'], true)) $level = crm_dictionary_default('customer_level', 'P3');
    $status = trim((string)($input['status'] ?? ($defaults['customer_status'] ?? crm_dictionary_default('customer_status', 'lead')))) ?: crm_dictionary_default('customer_status', 'lead');
    if (!in_array($status, $options['statuses'], true)) $status = crm_dictionary_default('customer_status', 'lead');
    $lifecycle = trim((string)($input['lifecycle_key'] ?? ($defaults['customer_lifecycle'] ?? crm_dictionary_default('customer_lifecycle', 'lead')))) ?: crm_dictionary_default('customer_lifecycle', 'lead');
    if (!in_array($lifecycle, crm_dictionary_keys('customer_lifecycle'), true)) $lifecycle = crm_dictionary_default('customer_lifecycle', 'lead');
    $riskLevel = trim((string)($input['risk_level'] ?? crm_dictionary_default('customer_risk_level', 'healthy')));
    if (!in_array($riskLevel, crm_dictionary_keys('customer_risk_level'), true)) $riskLevel = crm_dictionary_default('customer_risk_level', 'healthy');
    $sourceTags = crm_parse_keys($input['source_tags'] ?? ($input['source'] ?? ($defaults['source'] ?? crm_dictionary_default('customer_source', 'website'))), $options['source_tags']);
    if (!$sourceTags) $sourceTags = [crm_dictionary_default('customer_source', 'website')];
    $promotionChannels = crm_parse_keys($input['promotion_channels'] ?? [], $options['promotion_channels']);
    if (!$promotionChannels) $promotionChannels = crm_parse_keys($defaults['promotion_channels'] ?? [], $options['promotion_channels']);
    $promotionStatus = trim((string)($input['promotion_status'] ?? ($defaults['promotion_status'] ?? crm_dictionary_default('promotion_status', 'not_promoted'))));
    if (!in_array($promotionStatus, $options['promotion_statuses'], true)) $promotionStatus = crm_dictionary_default('promotion_status', 'not_promoted');
    $ownerId = (int)($input['owner_user_id'] ?? (current_user()['id'] ?? 0));
    $owners = $input['owners'] ?? [];
    if (is_string($owners) && trim($owners) !== '') {
        $decodedOwners = json_decode($owners, true);
        $owners = is_array($decodedOwners) ? $decodedOwners : [];
    }
    if (!is_array($owners)) $owners = [];
    $parseOwnerIds = static function ($value): array {
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') return [];
            $decoded = json_decode($trimmed, true);
            $value = is_array($decoded) ? $decoded : preg_split('/[,，\s]+/', $trimmed);
        } elseif (!is_array($value)) {
            $value = [$value];
        }
        $ids = [];
        foreach ((array)$value as $rawOwnerId) {
            $assignedId = (int)$rawOwnerId;
            if ($assignedId > 0 && !in_array($assignedId, $ids, true)) $ids[] = $assignedId;
        }
        return $ids;
    };
    if (!$owners && !empty($input['owner_user_ids'])) {
        $ownerIds = $parseOwnerIds($input['owner_user_ids']);
        if ($ownerId && !in_array($ownerId, $ownerIds, true)) array_unshift($ownerIds, $ownerId);
        if (!$ownerId && $ownerIds) $ownerId = (int)$ownerIds[0];
        foreach ($ownerIds as $assignedId) {
            $owners[] = [
                'user_id' => $assignedId,
                'role_type' => $ownerId && $assignedId === $ownerId ? 'primary' : 'secondary',
                'is_primary' => $ownerId && $assignedId === $ownerId ? 1 : 0,
            ];
        }
    }
    if (!$owners && $ownerId) $owners = [['user_id' => $ownerId, 'role_type' => 'primary', 'is_primary' => 1]];
    if (!$ownerId && $owners) {
        foreach ($owners as $ownerRow) {
            if (!is_array($ownerRow)) continue;
            if (!empty($ownerRow['is_primary']) || ($ownerRow['role_type'] ?? '') === 'primary') {
                $ownerId = (int)($ownerRow['user_id'] ?? 0);
                break;
            }
        }
        if (!$ownerId) $ownerId = (int)($owners[0]['user_id'] ?? 0);
    }
    $ownerDepartment = trim((string)($input['owner_department'] ?? ''));
    if ($ownerId) {
        $ownerDeptStmt = db()->prepare('SELECT d.name FROM crm_users u LEFT JOIN crm_departments d ON d.id = u.department_id WHERE u.id = ? LIMIT 1');
        $ownerDeptStmt->execute([$ownerId]);
        $resolvedOwnerDepartment = trim((string)($ownerDeptStmt->fetchColumn() ?: ''));
        if ($resolvedOwnerDepartment !== '') $ownerDepartment = $resolvedOwnerDepartment;
    }
    if ($ownerDepartment === '') $ownerDepartment = trim((string)(current_user()['department_name'] ?? ''));
    $addresses = $input['addresses'] ?? [];
    if (!$addresses) $addresses = [[
        'address_type' => $input['address_type'] ?? 'HQ',
        'country' => $country,
        'city' => trim((string)($input['city'] ?? '')),
        'address' => trim((string)($input['address'] ?? '')),
        'is_primary' => 1,
    ]];
    $mainCity = trim((string)($input['city'] ?? ''));
    $mainAddress = trim((string)($input['address'] ?? ''));
    if ($addresses && ($country !== '' || $mainCity !== '' || $mainAddress !== '')) {
        $primaryIndex = 0;
        foreach ($addresses as $idx => $addressRow) {
            if (!empty($addressRow['is_primary'])) {
                $primaryIndex = (int)$idx;
                break;
            }
        }
        if (!isset($addresses[$primaryIndex]) || !is_array($addresses[$primaryIndex])) $addresses[$primaryIndex] = [];
        $addresses[$primaryIndex]['is_primary'] = 1;
        if ($country !== '') $addresses[$primaryIndex]['country'] = $country;
        if ($mainCity !== '') $addresses[$primaryIndex]['city'] = $mainCity;
        if ($mainAddress !== '') $addresses[$primaryIndex]['address'] = $mainAddress;
    }
    return [
        'customer_code' => trim((string)($input['customer_code'] ?? '')),
        'customer_name' => $name,
        'customer_name_en' => trim((string)($input['customer_name_en'] ?? '')),
        'country' => $country,
        'city' => trim((string)($input['city'] ?? '')),
        'address' => trim((string)($input['address'] ?? '')),
        'website' => $website,
        'email' => $email,
        'backup_email' => $backupEmail,
        'phone' => trim((string)($input['phone'] ?? '')),
        'whatsapp' => trim((string)($input['whatsapp'] ?? '')),
        'wechat' => trim((string)($input['wechat'] ?? '')),
        'linkedin' => $linkedin,
        'customer_domain' => trim((string)($input['customer_domain'] ?? '')),
        'customer_type' => trim((string)($input['customer_type'] ?? '')),
        'industry' => trim((string)($input['industry'] ?? '')),
        'postal_code' => trim((string)($input['postal_code'] ?? '')),
        'shipping_address' => trim((string)($input['shipping_address'] ?? '')),
        'billing_address' => trim((string)($input['billing_address'] ?? '')),
        'common_port' => trim((string)($input['common_port'] ?? '')),
        'timezone' => trim((string)($input['timezone'] ?? '')),
        'no_promotion_reason' => trim((string)($input['no_promotion_reason'] ?? '')),
        'blacklist_reason' => trim((string)($input['blacklist_reason'] ?? '')),
        'customer_tags' => trim((string)($input['customer_tags'] ?? '')),
        'visibility_scope' => trim((string)($input['visibility_scope'] ?? '')),
        'source' => $sourceTags[0] ?? 'manual',
        'level' => $level,
        'status' => $status,
        'lifecycle_key' => $lifecycle,
        'risk_level' => $riskLevel,
        'do_not_contact' => !empty($input['do_not_contact']) ? 1 : 0,
        'owner_user_id' => $ownerId ?: null,
        'owner_department' => $ownerDepartment,
        'remark' => trim((string)($input['remark'] ?? '')),
        'graph' => [
            'addresses' => $addresses,
            'source_tags' => $sourceTags,
            'promotion_channels' => $promotionChannels,
            'promotion_status' => $promotionStatus,
            'owners' => $owners,
        ],
    ];
}

function crm_customer_initial_contacts(array $input): array
{
    $raw = $input['contacts_json'] ?? ($input['contacts'] ?? []);
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        $raw = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($raw)) return [];
    $contacts = [];
    foreach ($raw as $contact) {
        if (is_string($contact)) {
            $decoded = json_decode($contact, true);
            $contact = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($contact)) continue;
        if (empty($contact['role_tags']) && !empty($contact['role'])) $contact['role_tags'] = (array)$contact['role'];
        if (empty($contact['contact_sources']) && !empty($contact['source'])) $contact['contact_sources'] = $contact['source'];
        if (!isset($contact['is_primary']) && !empty($contact['primary'])) $contact['is_primary'] = $contact['primary'];
        $contacts[] = $contact;
    }
    $primarySeen = false;
    foreach ($contacts as $index => &$contact) {
        $isPrimary = !empty($contact['is_primary']);
        if ($isPrimary && !$primarySeen) {
            $contact['is_primary'] = 1;
            $primarySeen = true;
        } else {
            $contact['is_primary'] = 0;
        }
    }
    unset($contact);
    if ($contacts && !$primarySeen) $contacts[0]['is_primary'] = 1;
    return $contacts;
}

function crm_customer_duplicate_matches(array $input, int $ignoreId = 0): array
{
    $name = trim((string)($input['customer_name'] ?? $input['raw_name'] ?? ''));
    $nameEn = trim((string)($input['customer_name_en'] ?? ''));
    $email = trim((string)($input['email'] ?? $input['raw_email'] ?? ''));
    $phone = preg_replace('/\D+/', '', (string)($input['phone'] ?? $input['raw_phone'] ?? ''));
    $whatsapp = preg_replace('/\D+/', '', (string)($input['whatsapp'] ?? ''));
    $country = trim((string)($input['country'] ?? $input['raw_country'] ?? ''));
    $domain = crm_domain_from_value((string)($input['website'] ?? $email));
    $stmt = db()->prepare("SELECT c.id, c.customer_code, c.customer_name, c.customer_name_en, c.email, c.phone, c.whatsapp, c.website, COALESCE(a.country, c.country) AS country, COALESCE(a.city, c.city) AS city, u.username AS owner_name, (SELECT MAX(f.followup_time) FROM crm_customer_followups f WHERE f.customer_id = c.id AND f.deleted_at IS NULL) AS last_followup FROM crm_customers c LEFT JOIN crm_customer_addresses a ON a.customer_id = c.id AND a.is_primary = 1 LEFT JOIN crm_users u ON u.id = c.owner_user_id WHERE c.deleted_at IS NULL AND (? = 0 OR c.id <> ?) ORDER BY c.updated_at DESC LIMIT 500");
    $stmt->execute([$ignoreId, $ignoreId]);
    $matches = [];
    foreach ($stmt->fetchAll() as $row) {
        $score = crm_similarity_percent($name, (string)$row['customer_name']);
        $reasons = [];
        $rules = crm_rule_config('duplicate_detection');
        $nameThreshold = (int)($rules['name_threshold'] ?? 80);
        if ($score >= $nameThreshold) $reasons[] = 'name_similarity';
        if ($nameEn !== '') {
            $nameEnScore = crm_similarity_percent($nameEn, (string)$row['customer_name_en']);
            if ($nameEnScore >= $nameThreshold) { $score = max($score, $nameEnScore); $reasons[] = 'english_name_similarity'; }
        }
        if ($email !== '' && strtolower($email) === strtolower((string)$row['email'])) { $score = max($score, 100); $reasons[] = 'email_match'; }
        if ($phone !== '' && $phone === preg_replace('/\D+/', '', (string)$row['phone'])) { $score = max($score, 96); $reasons[] = 'phone_match'; }
        if ($whatsapp !== '' && $whatsapp === preg_replace('/\D+/', '', (string)$row['whatsapp'])) { $score = max($score, 96); $reasons[] = 'whatsapp_match'; }
        $rowDomain = crm_domain_from_value((string)($row['website'] ?: $row['email']));
        if ($domain !== '' && $rowDomain !== '' && $domain === $rowDomain) { $score = max($score, 92); $reasons[] = 'domain_match'; }
        if ($country !== '' && strtolower($country) === strtolower((string)$row['country']) && crm_similarity_percent($name, (string)$row['customer_name']) >= max(60, $nameThreshold - 10)) { $score = max($score, 84); $reasons[] = 'country_name_fuzzy'; }
        if ($email !== '' || $phone !== '') {
            $contactStmt = db()->prepare('SELECT email, phone FROM crm_contacts WHERE customer_id = ? AND deleted_at IS NULL');
            $contactStmt->execute([(int)$row['id']]);
            foreach ($contactStmt->fetchAll() as $contactRow) {
                if ($email !== '' && strtolower($email) === strtolower((string)$contactRow['email'])) { $score = max($score, 98); $reasons[] = 'contact_email_match'; }
                if ($phone !== '' && $phone === preg_replace('/\D+/', '', (string)$contactRow['phone'])) { $score = max($score, 94); $reasons[] = 'contact_phone_match'; }
            }
        }
        if ($score >= $nameThreshold || $reasons) {
            $matches[] = [
                'customer_id' => (int)$row['id'],
                'customer_code' => $row['customer_code'] ?? '',
                'customer_name' => $row['customer_name'],
                'customer_name_en' => $row['customer_name_en'] ?? '',
                'country' => $row['country'],
                'city' => $row['city'] ?? '',
                'email' => $row['email'] ?? '',
                'phone' => $row['phone'] ?? '',
                'whatsapp' => $row['whatsapp'] ?? '',
                'website' => $row['website'] ?? '',
                'similarity' => $score,
                'reasons' => array_values(array_unique($reasons)),
                'owner_name' => $row['owner_name'] ?? '',
                'last_followup' => $row['last_followup'] ?? '',
            ];
        }
    }
    usort($matches, fn($a, $b) => $b['similarity'] <=> $a['similarity']);
    return array_slice($matches, 0, 10);
}

function crm_lead_pool_create(array $input): array
{
    crm_customer_ensure_tables();
    crm_require('customer.create');
    $input = crm_lead_pool_normalize_payload($input);
    $name = trim((string)($input['customer_name'] ?? $input['raw_name'] ?? ''));
    if ($name === '') throw new RuntimeException('客户名称必填。');
    $email = trim((string)($input['email'] ?? $input['raw_email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('邮箱格式不正确。');
    $matches = crm_customer_duplicate_matches($input);
    $domain = crm_domain_from_value((string)($input['website'] ?? $email));
    db()->prepare('INSERT INTO crm_lead_pool (raw_name, raw_email, raw_phone, raw_country, raw_domain, payload_json, similarity_matches_json, status, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, "pending", ?, NOW(), NOW())')
        ->execute([$name, $email, trim((string)($input['phone'] ?? $input['raw_phone'] ?? '')), trim((string)($input['country'] ?? $input['raw_country'] ?? '')), $domain, json_encode($input, JSON_UNESCAPED_UNICODE), json_encode($matches, JSON_UNESCAPED_UNICODE), current_user()['id'] ?? null]);
    $leadId = (int)db()->lastInsertId();
    crm_customer_log('lead_pool_create', 'lead', $leadId, null, null, ['lead_id' => $leadId, 'matches' => $matches], '客户进入暂存池');
    return ['lead' => crm_lead_pool_get($leadId), 'matches' => $matches];
}

function crm_lead_pool_normalize_payload(array $input): array
{
    if (!empty($input['addresses_json']) && empty($input['addresses'])) {
        $decodedAddresses = json_decode((string)$input['addresses_json'], true);
        if (is_array($decodedAddresses)) $input['addresses'] = $decodedAddresses;
    }
    $addresses = is_array($input['addresses'] ?? null) ? $input['addresses'] : [];
    $cleanAddresses = [];
    foreach ($addresses as $address) {
        if (!is_array($address)) continue;
        $row = [
            'address_type' => trim((string)($address['address_type'] ?? 'HQ')) ?: 'HQ',
            'country' => trim((string)($address['country'] ?? '')),
            'city' => trim((string)($address['city'] ?? '')),
            'address' => trim((string)($address['address'] ?? '')),
            'is_primary' => !empty($address['is_primary']) ? 1 : 0,
        ];
        if ($row['country'] !== '' || $row['city'] !== '' || $row['address'] !== '') $cleanAddresses[] = $row;
    }
    if ($cleanAddresses) {
        $primaryIndex = 0;
        foreach ($cleanAddresses as $idx => $address) {
            if (!empty($address['is_primary'])) {
                $primaryIndex = (int)$idx;
                break;
            }
        }
        foreach ($cleanAddresses as $idx => &$address) $address['is_primary'] = $idx === $primaryIndex ? 1 : 0;
        unset($address);
        $primary = $cleanAddresses[$primaryIndex] ?? $cleanAddresses[0];
        if (trim((string)($input['country'] ?? $input['raw_country'] ?? '')) === '' && $primary['country'] !== '') {
            $input['country'] = $primary['country'];
            $input['raw_country'] = $primary['country'];
        }
        if (trim((string)($input['city'] ?? '')) === '' && $primary['city'] !== '') $input['city'] = $primary['city'];
        if (trim((string)($input['address'] ?? '')) === '' && $primary['address'] !== '') $input['address'] = $primary['address'];
        $input['addresses'] = $cleanAddresses;
        $input['addresses_json'] = json_encode($cleanAddresses, JSON_UNESCAPED_UNICODE);
    }
    return $input;
}

function crm_customer_should_enter_lead_pool(array $input, array $matches): bool
{
    $admission = crm_rule_config('customer_admission');
    $leadRules = crm_rule_config('lead_pool');
    if (empty($leadRules['enabled'])) return false;
    $mode = (string)($admission['mode'] ?? 'lead_pool_first');
    if ($mode === 'direct') return false;
    if ($mode === 'lead_pool_first') return true;
    if ($mode === 'duplicate_risk_only') return !empty($matches);
    if ($mode === 'external_sources_only') {
        $sources = crm_parse_keys($input['source_tags'] ?? ($input['source'] ?? ''), crm_dictionary_keys('customer_source'));
        return (bool)array_intersect($sources, (array)($admission['external_sources_to_pool'] ?? []));
    }
    if ($mode === 'import_only') return !empty($input['import_batch_id']);
    return true;
}

function crm_lead_pool_get(int $leadId): array
{
    $stmt = db()->prepare('SELECT * FROM crm_lead_pool WHERE id = ? LIMIT 1');
    $stmt->execute([$leadId]);
    $lead = $stmt->fetch();
    if (!$lead) throw new RuntimeException('暂存客户不存在。');
    $lead['payload'] = json_decode((string)($lead['payload_json'] ?? '{}'), true) ?: [];
    $lead['similarity_matches'] = crm_lead_pool_enrich_matches(json_decode((string)($lead['similarity_matches_json'] ?? '[]'), true) ?: []);
    return $lead;
}

function crm_lead_pool_list(array $input = []): array
{
    if (!crm_can('customer.lead_pool_view') && !crm_can('customer.lead_pool')) {
        throw new RuntimeException('无权查看暂存池客户。');
    }
    $status = trim((string)($input['status'] ?? 'pending')) ?: 'pending';
    $pageSize = max(20, min(100, (int)($input['page_size'] ?? 50)));
    $page = max(1, (int)($input['page'] ?? 1));
    $params = [];
    $where = '1=1';
    if (in_array($status, ['pending','confirmed','merged','rejected'], true)) {
        $where .= ' AND status = ?';
        $params[] = $status;
    }
    $count = db()->prepare("SELECT COUNT(*) FROM crm_lead_pool WHERE {$where}");
    $count->execute($params);
    $offset = ($page - 1) * $pageSize;
    $stmt = db()->prepare("SELECT * FROM crm_lead_pool WHERE {$where} ORDER BY id DESC LIMIT {$pageSize} OFFSET {$offset}");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['payload'] = json_decode((string)($row['payload_json'] ?? '{}'), true) ?: [];
        $row['similarity_matches'] = crm_lead_pool_enrich_matches(json_decode((string)($row['similarity_matches_json'] ?? '[]'), true) ?: []);
    }
    return ['rows' => $rows, 'total' => (int)$count->fetchColumn(), 'page' => $page, 'page_size' => $pageSize, 'can_process' => crm_can('customer.lead_pool')];
}

function crm_lead_pool_enrich_matches(array $matches): array
{
    $ids = array_values(array_unique(array_filter(array_map(fn($row) => (int)($row['customer_id'] ?? 0), $matches))));
    if (!$ids) return $matches;
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT c.id, c.customer_code, c.customer_name, c.customer_name_en, c.email, c.phone, c.whatsapp, c.website, COALESCE(a.country, c.country) AS country, COALESCE(a.city, c.city) AS city, u.username AS owner_name, (SELECT MAX(f.followup_time) FROM crm_customer_followups f WHERE f.customer_id = c.id AND f.deleted_at IS NULL) AS last_followup FROM crm_customers c LEFT JOIN crm_customer_addresses a ON a.customer_id = c.id AND a.is_primary = 1 LEFT JOIN crm_users u ON u.id = c.owner_user_id WHERE c.id IN ({$placeholders})");
    $stmt->execute($ids);
    $map = [];
    foreach ($stmt->fetchAll() as $row) $map[(int)$row['id']] = $row;
    foreach ($matches as &$match) {
        $customerId = (int)($match['customer_id'] ?? 0);
        if (!isset($map[$customerId])) continue;
        $existing = $map[$customerId];
        foreach (['customer_code','customer_name','customer_name_en','email','phone','whatsapp','website','country','city','owner_name','last_followup'] as $field) {
            if (!isset($match[$field]) || $match[$field] === '' || $match[$field] === null) $match[$field] = $existing[$field] ?? '';
        }
    }
    unset($match);
    return $matches;
}

function crm_lead_pool_use_existing(int $leadId, int $customerId): array
{
    crm_require('customer.lead_pool');
    crm_customer_get($customerId);
    db()->prepare('UPDATE crm_lead_pool SET status = "merged", target_customer_id = ?, updated_at = NOW() WHERE id = ?')->execute([$customerId, $leadId]);
    crm_customer_log('lead_use_existing', 'lead', $leadId, $customerId, null, ['lead_id' => $leadId, 'customer_id' => $customerId], '暂存客户使用已有客户');
    return crm_lead_pool_get($leadId);
}

function crm_lead_pool_reject(int $leadId): array
{
    crm_require('customer.lead_pool');
    db()->prepare('UPDATE crm_lead_pool SET status = "rejected", updated_at = NOW() WHERE id = ?')->execute([$leadId]);
    crm_customer_log('lead_reject', 'lead', $leadId, null, null, ['lead_id' => $leadId], '丢弃暂存客户');
    return crm_lead_pool_get($leadId);
}

function crm_lead_pool_update(array $input): array
{
    crm_require('customer.lead_pool');
    $leadId = (int)($input['lead_id'] ?? 0);
    if (!$leadId) throw new RuntimeException('缺少暂存客户 ID。');
    $before = crm_lead_pool_get($leadId);
    if (($before['status'] ?? '') !== 'pending') {
        throw new RuntimeException('只有待确认的暂存客户可以编辑。');
    }

    $payload = $before['payload'] ?? [];
    $arrayKeys = ['source_tags', 'promotion_channels', 'owner_user_ids'];
    foreach ($input as $key => $value) {
        if (in_array($key, ['action', 'csrf_token', 'lead_id', 'customer_id', 'duplicate_risk_confirmed', 'entry_mode'], true)) continue;
        if (in_array($key, $arrayKeys, true)) {
            $payload[$key] = is_array($value) ? array_values(array_filter($value, fn($v) => $v !== '')) : array_values(array_filter(explode(',', (string)$value)));
            continue;
        }
        if ($key === 'addresses_json') {
            $payload['addresses'] = json_decode((string)$value, true) ?: [];
            $payload[$key] = (string)$value;
            continue;
        }
        if ($key === 'contacts_json' || $key === 'contacts') {
            $payload['contacts'] = json_decode((string)$value, true) ?: [];
            $payload['contacts_json'] = (string)$value;
            continue;
        }
        $payload[$key] = is_array($value) ? $value : trim((string)$value);
    }

    $payload = crm_lead_pool_normalize_payload($payload);
    $rawName = trim((string)($payload['customer_name'] ?? $input['customer_name'] ?? $before['raw_name'] ?? ''));
    if ($rawName === '') throw new RuntimeException('客户名称不能为空。');
    $rawEmail = trim((string)($payload['email'] ?? $input['email'] ?? ''));
    if ($rawEmail !== '' && !filter_var($rawEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('邮箱格式不正确。');
    }
    $rawPhone = trim((string)($payload['phone'] ?? $input['phone'] ?? ''));
    $rawCountry = trim((string)($payload['country'] ?? $input['country'] ?? ''));
    $rawDomain = '';
    if ($rawEmail !== '' && strpos($rawEmail, '@') !== false) {
        $rawDomain = strtolower(substr(strrchr($rawEmail, '@'), 1));
    } else {
        $rawDomain = trim((string)($before['raw_domain'] ?? ''));
    }

    db()->prepare('UPDATE crm_lead_pool SET raw_name = ?, raw_email = ?, raw_phone = ?, raw_country = ?, raw_domain = ?, payload_json = ?, updated_at = NOW() WHERE id = ?')
        ->execute([$rawName, $rawEmail ?: null, $rawPhone ?: null, $rawCountry ?: null, $rawDomain ?: null, json_encode($payload, JSON_UNESCAPED_UNICODE), $leadId]);
    $after = crm_lead_pool_get($leadId);
    crm_customer_log('lead_pool_update', 'lead', $leadId, null, null, ['before' => $before, 'after' => $after], '编辑暂存客户');
    return $after;
}

function crm_duplicate_merge_cases(array $input = []): array
{
    crm_require('customer.merge');
    $status = trim((string)($input['status'] ?? 'pending')) ?: 'pending';
    $stmt = db()->prepare('SELECT * FROM crm_duplicate_merge_cases WHERE status = ? ORDER BY similarity_score DESC, id DESC LIMIT 100');
    $stmt->execute([$status]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['duplicate_customer_ids'] = json_decode((string)($row['duplicate_customer_ids_json'] ?? '[]'), true) ?: [];
        $row['merge_plan'] = json_decode((string)($row['merge_plan_json'] ?? '{}'), true) ?: [];
    }
    return ['rows' => $rows];
}

function crm_duplicate_merge_scan(): array
{
    crm_require('customer.merge');
    $customers = db()->query('SELECT id, customer_name, email, phone, website FROM crm_customers WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1000')->fetchAll();
    $created = 0;
    for ($i = 0; $i < count($customers); $i++) {
        for ($j = $i + 1; $j < count($customers); $j++) {
            $a = $customers[$i]; $b = $customers[$j];
            $score = crm_similarity_percent($a['customer_name'], $b['customer_name']);
            $type = 'name';
            if ($a['email'] && strtolower($a['email']) === strtolower($b['email'])) { $score = 100; $type = 'email'; }
            elseif (crm_domain_from_value((string)($a['website'] ?: $a['email'])) && crm_domain_from_value((string)($a['website'] ?: $a['email'])) === crm_domain_from_value((string)($b['website'] ?: $b['email']))) { $score = max($score, 92); $type = 'domain'; }
            elseif ($a['phone'] && preg_replace('/\D+/', '', $a['phone']) === preg_replace('/\D+/', '', (string)$b['phone'])) { $score = max($score, 95); $type = 'phone'; }
            if ($score >= (int)(crm_rule_config('duplicate_detection')['name_threshold'] ?? 80)) {
                db()->prepare('INSERT INTO crm_duplicate_merge_cases (master_customer_id, duplicate_customer_ids_json, match_type, similarity_score, status, merge_plan_json, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, "pending", ?, ?, NOW(), NOW())')
                    ->execute([(int)$a['id'], json_encode([(int)$b['id']], JSON_UNESCAPED_UNICODE), $type, $score, json_encode(['contacts'=>1,'addresses'=>1,'owners'=>1,'groups'=>1,'followups'=>1,'files'=>1,'materials'=>1,'logs'=>1], JSON_UNESCAPED_UNICODE), current_user()['id'] ?? null]);
                $created++;
            }
        }
    }
    crm_sensitive_audit('customer_merge', 'merge_scan', 'scan', null, ['created' => $created]);
    return ['created' => $created, 'cases' => crm_duplicate_merge_cases()];
}

function crm_customer_relation_create(array $input): array
{
    crm_require('customer.graph_manage');
    $customerId = (int)($input['customer_id'] ?? 0);
    $relatedId = (int)($input['related_customer_id'] ?? 0);
    $type = trim((string)($input['relation_type'] ?? 'other'));
    if (!$customerId || !$relatedId || $customerId === $relatedId) throw new RuntimeException('请选择有效客户关系。');
    crm_customer_get($customerId);
    crm_customer_get($relatedId);
    db()->prepare('INSERT INTO crm_customer_relations (customer_id, related_customer_id, relation_type, remark, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE remark=VALUES(remark), updated_at=NOW()')
        ->execute([$customerId, $relatedId, $type, trim((string)($input['remark'] ?? '')), current_user()['id'] ?? null]);
    crm_customer_timeline_add($customerId, 'customer_relation', '新增客户关系', $type, 'customer', (string)$relatedId);
    return ['relations' => crm_customer_relations($customerId)];
}

function crm_customer_event_create(array $input): array
{
    crm_require('customer.edit');
    $customerId = (int)($input['customer_id'] ?? 0);
    crm_customer_get($customerId);
    $type = trim((string)($input['event_type'] ?? crm_dictionary_default('customer_event_type', 'other')));
    $title = trim((string)($input['title'] ?? '客户事件'));
    db()->prepare('INSERT INTO crm_customer_events (customer_id, event_type, title, event_time, remind_time, status, remark, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, "open", ?, ?, NOW(), NOW())')
        ->execute([$customerId, $type, $title, ($input['event_time'] ?? '') ?: null, ($input['remind_time'] ?? '') ?: null, trim((string)($input['remark'] ?? '')), current_user()['id'] ?? null]);
    crm_customer_timeline_add($customerId, $type, $title, trim((string)($input['remark'] ?? '')), 'event', (string)db()->lastInsertId());
    return ['events' => crm_customer_events($customerId)];
}

function crm_customer_create_confirmed(array $input): array
{
    crm_customer_ensure_tables();
    crm_require('customer.create');
    $leadId = (int)($input['lead_id'] ?? 0);
    if (empty($input['lead_id'])) {
        $matches = crm_customer_duplicate_matches($input);
        $hasHighRisk = false;
        foreach ($matches as $match) {
            $reasons = (array)($match['reasons'] ?? []);
            if ((int)($match['similarity'] ?? 0) >= 90 || array_intersect($reasons, ['email_match','domain_match','phone_match','whatsapp_match','contact_email_match','contact_phone_match'])) {
                $hasHighRisk = true;
                break;
            }
        }
        $duplicateRules = crm_rule_config('duplicate_detection');
        $duplicateAction = (string)($duplicateRules['action'] ?? 'lead_pool');
        if ($hasHighRisk && (string)($input['entry_mode'] ?? '') !== 'force' && empty($input['duplicate_risk_confirmed'])) {
            if ($duplicateAction === 'block') {
                throw new RuntimeException('存在高风险重复客户，已按系统规则阻止保存。');
            }
            if ($duplicateAction === 'lead_pool' || $duplicateAction === 'admin_confirm') {
                return crm_lead_pool_create($input);
            }
        }
    }
    if ($leadId && !has_permission('customer.lead_pool') && !is_super_admin()) {
        throw new RuntimeException('无权处理暂存池客户。');
    }
    $pdo = db();
    $startedTx = false;
    if ($leadId) {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTx = true;
        }
        try {
            $leadStmt = $pdo->prepare('SELECT id, status, target_customer_id FROM crm_lead_pool WHERE id = ? FOR UPDATE');
            $leadStmt->execute([$leadId]);
            $leadRow = $leadStmt->fetch();
            if (!$leadRow) throw new RuntimeException('暂存客户不存在。');
            if (($leadRow['status'] ?? '') === 'confirmed' && (int)($leadRow['target_customer_id'] ?? 0) > 0) {
                $existingId = (int)$leadRow['target_customer_id'];
                if ($startedTx && $pdo->inTransaction()) $pdo->commit();
                return ['customer' => crm_customer_basic_row($existingId)];
            }
            if (($leadRow['status'] ?? '') !== 'pending') {
                throw new RuntimeException('该暂存客户已处理，不能重复加入正式库。');
            }
        } catch (Throwable $e) {
            if ($startedTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
    try {
    $data = crm_customer_validate($input);
    $user = current_user();
    $stmt = $pdo->prepare('INSERT INTO crm_customers (customer_code, customer_name, customer_name_en, country, city, address, website, email, phone, whatsapp, source, level, status, lifecycle_key, risk_level, do_not_contact, owner_user_id, owner_department, created_by, updated_by, remark, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
    $stmt->execute([$data['customer_code'], $data['customer_name'], $data['customer_name_en'], $data['country'], $data['city'], $data['address'], $data['website'], $data['email'], $data['phone'], $data['whatsapp'], $data['source'], $data['level'], $data['status'], $data['lifecycle_key'], $data['risk_level'], $data['do_not_contact'], $data['owner_user_id'], $data['owner_department'], $user['id'], $user['id'], $data['remark']]);
    $id = (int)$pdo->lastInsertId();
    crm_customer_apply_graph($id, $data['graph']);
    $initialContacts = crm_customer_initial_contacts($input);
    $createdContactCount = 0;
    foreach ($initialContacts as $contact) {
        if (trim((string)($contact['name'] ?? '')) === '') continue;
        $contact['customer_id'] = $id;
        crm_contact_create($contact, true);
        $createdContactCount++;
    }
    crm_customer_set_groups($id, array_filter(array_map('intval', (array)($input['group_ids'] ?? []))));
    if ($leadId) {
        $pdo->prepare('UPDATE crm_lead_pool SET status = "confirmed", target_customer_id = ?, updated_at = NOW() WHERE id = ?')->execute([$id, $leadId]);
    }
    crm_customer_log('customer_create', 'customer', $id, $id, null, ['customer' => $data, 'graph' => $data['graph'], 'initial_contacts' => $initialContacts, 'created_contact_count' => $createdContactCount], '从暂存池确认创建客户');
    crm_customer_timeline_add($id, 'customer_create', '新建客户', '从暂存池或准入规则创建正式客户', 'customer', (string)$id);
    if ($startedTx && $pdo->inTransaction()) $pdo->commit();
    return [
        'customer' => crm_customer_basic_row($id),
        'created_contact_count' => $createdContactCount,
    ];
    } catch (Throwable $e) {
        if ($startedTx && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function crm_customer_create(array $input): array
{
    $matches = crm_customer_duplicate_matches($input);
    if (crm_customer_should_enter_lead_pool($input, $matches)) {
        return crm_lead_pool_create($input);
    }
    return crm_customer_create_confirmed($input);
}

function crm_customer_update(int $id, array $input): array
{
    crm_customer_ensure_tables();
    crm_require('customer.edit');
    if ($id <= 0) throw new RuntimeException('缺少客户 ID，无法编辑客户。');
    $before = crm_customer_basic_row($id);
    $data = crm_customer_validate($input, $id);
    $user = current_user();
    db()->prepare('UPDATE crm_customers SET customer_code=?, customer_name=?, customer_name_en=?, country=?, city=?, address=?, website=?, email=?, backup_email=?, phone=?, whatsapp=?, wechat=?, linkedin=?, customer_domain=?, customer_type=?, industry=?, postal_code=?, shipping_address=?, billing_address=?, common_port=?, timezone=?, no_promotion_reason=?, blacklist_reason=?, customer_tags=?, visibility_scope=?, source=?, level=?, status=?, lifecycle_key=?, risk_level=?, do_not_contact=?, owner_user_id=?, owner_department=?, updated_by=?, remark=?, updated_at=NOW() WHERE id=?')
        ->execute([$data['customer_code'], $data['customer_name'], $data['customer_name_en'], $data['country'], $data['city'], $data['address'], $data['website'], $data['email'], $data['backup_email'], $data['phone'], $data['whatsapp'], $data['wechat'], $data['linkedin'], $data['customer_domain'], $data['customer_type'], $data['industry'], $data['postal_code'], $data['shipping_address'], $data['billing_address'], $data['common_port'], $data['timezone'], $data['no_promotion_reason'], $data['blacklist_reason'], $data['customer_tags'], $data['visibility_scope'], $data['source'], $data['level'], $data['status'], $data['lifecycle_key'], $data['risk_level'], $data['do_not_contact'], $data['owner_user_id'], $data['owner_department'], $user['id'], $data['remark'], $id]);
    crm_customer_apply_graph($id, $data['graph']);
    $initialContacts = crm_customer_initial_contacts($input);
    $existingContactsForDelete = [];
    if (array_key_exists('contacts_json', $input) || array_key_exists('contacts', $input)) {
        $beforeContactStmt = db()->prepare('SELECT id, name FROM crm_contacts WHERE customer_id = ? AND deleted_at IS NULL');
        $beforeContactStmt->execute([$id]);
        $existingContactsForDelete = $beforeContactStmt->fetchAll();
    }
    $createdContactCount = 0;
    $updatedContactCount = 0;
    $keptContactIds = [];
    foreach ($initialContacts as $contact) {
        if (trim((string)($contact['name'] ?? '')) === '') continue;
        $contactId = (int)($contact['id'] ?? 0);
        if ($contactId > 0) {
            $check = db()->prepare('SELECT id FROM crm_contacts WHERE id = ? AND customer_id = ? AND deleted_at IS NULL LIMIT 1');
            $check->execute([$contactId, $id]);
            if ($check->fetchColumn()) {
                $contact['customer_id'] = $id;
                crm_contact_update($contactId, $contact, true);
                $keptContactIds[] = $contactId;
                $updatedContactCount++;
                continue;
            }
        }
        $contact['customer_id'] = $id;
        crm_contact_create($contact, true);
        $createdContactCount++;
    }
    $deletedContactCount = 0;
    if (array_key_exists('contacts_json', $input) || array_key_exists('contacts', $input)) {
        $deleteIds = [];
        foreach ($existingContactsForDelete as $row) {
            $existingId = (int)$row['id'];
            if ($existingId > 0 && !in_array($existingId, $keptContactIds, true)) $deleteIds[] = $existingId;
        }
        if ($deleteIds) {
            $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
            $params = array_merge([current_user()['id'] ?? null], $deleteIds, [$id]);
            db()->prepare("UPDATE crm_contacts SET deleted_at = NOW(), deleted_by = ?, updated_at = NOW() WHERE id IN ({$placeholders}) AND customer_id = ? AND deleted_at IS NULL")->execute($params);
            $deletedContactCount = count($deleteIds);
            foreach ($existingContactsForDelete as $row) {
                if (in_array((int)$row['id'], $deleteIds, true)) {
                    crm_customer_log('contact_delete', 'contact', (int)$row['id'], $id, $row, ['deleted' => true, 'source' => 'customer_update_contacts_json'], '编辑客户时删除联系人');
                    crm_customer_timeline_add($id, 'contact_delete', '删除联系人', (string)$row['name'], 'contact', (string)(int)$row['id']);
                }
            }
        }
    }
    crm_customer_set_groups($id, array_filter(array_map('intval', (array)($input['group_ids'] ?? []))));
    $afterCustomer = crm_customer_basic_row($id);
    crm_customer_log('customer_update', 'customer', $id, $id, $before, ['customer' => $afterCustomer, 'contacts' => $initialContacts, 'created_contact_count' => $createdContactCount, 'updated_contact_count' => $updatedContactCount, 'deleted_contact_count' => $deletedContactCount], '编辑客户');
    crm_customer_timeline_add($id, 'customer_update', '编辑客户', ($createdContactCount || $updatedContactCount || $deletedContactCount) ? ('客户资料更新，联系人新增 ' . $createdContactCount . ' 个，更新 ' . $updatedContactCount . ' 个，删除 ' . $deletedContactCount . ' 个') : '客户基础资料或关系配置发生变化', 'customer', (string)$id);
    $after = ['customer' => $afterCustomer];
    $after['created_contact_count'] = $createdContactCount;
    $after['updated_contact_count'] = $updatedContactCount;
    $after['deleted_contact_count'] = $deletedContactCount;
    return $after;
}

function crm_customer_delete(int $id, string $reason = ''): void
{
    crm_require('customer.delete');
    $before = crm_customer_get($id)['customer'];
    $user = current_user();
    db()->prepare('UPDATE crm_customers SET deleted_at = NOW(), deleted_by = ?, delete_reason = ?, updated_at = NOW() WHERE id = ?')->execute([$user['id'], $reason, $id]);
    crm_customer_log('customer_delete', 'customer', $id, $id, $before, ['deleted_at' => date('Y-m-d H:i:s'), 'reason' => $reason], '软删除客户');
    crm_customer_timeline_add($id, 'customer_delete', '删除客户', $reason, 'customer', (string)$id);
    crm_sensitive_audit('customer_delete', 'customer', $id, $before, ['reason' => $reason]);
}

function crm_customer_restore(int $id): void
{
    crm_require('customer.restore');
    $stmt = db()->prepare('SELECT * FROM crm_customers WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $before = $stmt->fetch();
    if (!$before) throw new RuntimeException('客户不存在。');
    db()->prepare('UPDATE crm_customers SET deleted_at = NULL, deleted_by = NULL, delete_reason = NULL, updated_at = NOW() WHERE id = ?')->execute([$id]);
    crm_customer_log('customer_restore', 'customer', $id, $id, $before, ['restored' => true], '恢复客户');
    crm_customer_timeline_add($id, 'customer_restore', '恢复客户', '客户从软删除状态恢复', 'customer', (string)$id);
    crm_sensitive_audit('customer_restore', 'customer', $id, $before, ['restored' => true]);
}

function crm_customer_force_delete(int $id, string $reason = ''): void
{
    crm_require('customer.force_delete');
    if ($id <= 0) throw new RuntimeException('客户 ID 不正确。');
    $stmt = db()->prepare('SELECT * FROM crm_customers WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $before = $stmt->fetch();
    if (!$before) throw new RuntimeException('客户不存在。');
    if (empty($before['deleted_at'])) throw new RuntimeException('请先软删除客户，再执行强制删除。');
    if (trim($reason) === '') throw new RuntimeException('请填写强制删除原因。');

    $counts = crm_customer_force_delete_counts($id);
    crm_customer_log('customer_force_delete', 'customer', $id, $id, $before, ['reason' => $reason, 'counts' => $counts], '强制删除客户：' . $reason);
    crm_sensitive_audit('customer_force_delete', 'customer', $id, $before, ['reason' => $reason, 'counts' => $counts]);

    $contactIds = db()->prepare('SELECT id FROM crm_contacts WHERE customer_id = ?');
    $contactIds->execute([$id]);
    $contactIds = array_map('intval', array_column($contactIds->fetchAll(), 'id'));

    db()->beginTransaction();
    try {
        if ($contactIds) {
            $placeholders = implode(',', array_fill(0, count($contactIds), '?'));
            foreach (['crm_contact_role_tags', 'crm_contact_sources', 'crm_contact_promotions', 'crm_contact_communication_preferences'] as $table) {
                if (crm_table_exists_safe($table)) db()->prepare("DELETE FROM {$table} WHERE contact_id IN ({$placeholders})")->execute($contactIds);
            }
        }
        foreach ([
            'crm_contacts',
            'crm_customer_addresses',
            'crm_customer_source_tags',
            'crm_customer_promotion_channels',
            'crm_customer_promotion_status',
            'crm_customer_owners',
            'crm_customer_scores',
            'crm_customer_timeline',
            'crm_customer_relations',
            'crm_customer_protection',
            'crm_customer_events',
            'crm_customer_product_preferences',
            'crm_customer_communication_preferences',
            'crm_customer_group_relations',
            'crm_customer_followups',
            'crm_customer_files',
        ] as $table) {
            if (!crm_table_exists_safe($table)) continue;
            $column = $table === 'crm_customer_promotion_status' || $table === 'crm_customer_scores' || $table === 'crm_customer_protection' || $table === 'crm_customer_product_preferences' || $table === 'crm_customer_communication_preferences'
                ? 'customer_id'
                : 'customer_id';
            db()->prepare("DELETE FROM {$table} WHERE {$column} = ?")->execute([$id]);
        }
        if (crm_table_exists_safe('crm_customer_relations')) {
            db()->prepare('DELETE FROM crm_customer_relations WHERE related_customer_id = ?')->execute([$id]);
        }
        if (crm_table_exists_safe('crm_duplicate_merge_cases')) {
            db()->prepare('UPDATE crm_duplicate_merge_cases SET status = "force_deleted", updated_at = NOW() WHERE master_customer_id = ?')->execute([$id]);
        }
        db()->prepare('DELETE FROM crm_customers WHERE id = ?')->execute([$id]);
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        crm_customer_log('customer_force_delete_failed', 'customer', $id, $id, $before, ['reason' => $reason], '强制删除失败', false, $e->getMessage());
        throw $e;
    }
}

function crm_customer_force_delete_counts(int $id): array
{
    $counts = [];
    $tables = [
        'crm_contacts',
        'crm_customer_addresses',
        'crm_customer_source_tags',
        'crm_customer_promotion_channels',
        'crm_customer_promotion_status',
        'crm_customer_owners',
        'crm_customer_timeline',
        'crm_customer_relations',
        'crm_customer_events',
        'crm_customer_group_relations',
        'crm_customer_followups',
        'crm_customer_files',
    ];
    foreach ($tables as $table) {
        if (!crm_table_exists_safe($table)) continue;
        $stmt = db()->prepare("SELECT COUNT(*) FROM {$table} WHERE customer_id = ?");
        $stmt->execute([$id]);
        $counts[$table] = (int)$stmt->fetchColumn();
    }
    return $counts;
}

function crm_customer_import_headers(): array
{
    return [
        'customer_code' => ['客户代码','客户编号','代码','code','customer_code'],
        'customer_name' => ['客户名称','客户名','公司名称','name','customer_name'],
        'customer_name_en' => ['英文名称','英文名','english_name','customer_name_en'],
        'country' => ['国家','国家地区','国家/地区','country'],
        'city' => ['城市','地区','city','region'],
        'address' => ['地址','详细地址','address'],
        'website' => ['网站','网址','website','url'],
        'email' => ['邮箱','邮件','email','e-mail','mail','mail address','email address','e mail address'],
        'phone' => ['电话','手机','phone','tel','mobile','mobile phone','telephone','cell phone'],
        'whatsapp' => ['whatsapp','WhatsApp','WA'],
        'level' => ['客户等级','等级','level'],
        'status' => ['状态','客户状态','status'],
        'lifecycle_key' => ['生命周期','业务阶段','lifecycle'],
        'risk_level' => ['风险等级','风险','risk_level'],
        'source_tags' => ['来源标签','客户来源','来源','source_tags','source'],
        'promotion_channels' => ['推广方式','推广渠道','promotion_channels','channels'],
        'promotion_status' => ['推广状态','promotion_status'],
        'owner_user_id' => ['负责人ID','负责人 id','owner_user_id'],
        'owner_department' => ['部门','owner_department'],
        'contacts_json' => ['联系人明细JSON','联系人JSON','contacts_json','contacts'],
        'all_contacts' => ['所有联系人','联系人列表','all_contacts'],
        'contact1_name' => ['联系人1姓名','联系人1','主联系人','contact1_name'],
        'contact1_name_en' => ['联系人1英文名','contact1_name_en'],
        'contact1_position' => ['联系人1职位','contact1_position'],
        'contact1_department' => ['联系人1部门','contact1_department'],
        'contact1_email' => ['联系人1邮箱','主联系人邮箱','contact1_email'],
        'contact1_phone' => ['联系人1电话','主联系人电话','contact1_phone'],
        'contact1_whatsapp' => ['联系人1WhatsApp','主联系人WhatsApp','contact1_whatsapp'],
        'contact1_wechat' => ['联系人1微信','contact1_wechat'],
        'contact1_linkedin' => ['联系人1LinkedIn','contact1_linkedin'],
        'contact1_gender' => ['联系人1性别','contact1_gender'],
        'contact1_birthday' => ['联系人1生日','contact1_birthday'],
        'contact1_language' => ['联系人1语言','contact1_language'],
        'contact1_sources' => ['联系人1来源','contact1_sources'],
        'contact1_role_tags' => ['联系人1角色标签','contact1_role_tags'],
        'contact1_promotion_channels' => ['联系人1推广方式','contact1_promotion_channels'],
        'contact1_is_primary' => ['联系人1主联系人','contact1_is_primary'],
        'contact1_is_left' => ['联系人1已离职','contact1_is_left'],
        'contact1_remark' => ['联系人1备注','contact1_remark'],
        'contact2_name' => ['联系人2姓名','联系人2','contact2_name'],
        'contact2_name_en' => ['联系人2英文名','contact2_name_en'],
        'contact2_position' => ['联系人2职位','contact2_position'],
        'contact2_department' => ['联系人2部门','contact2_department'],
        'contact2_email' => ['联系人2邮箱','contact2_email'],
        'contact2_phone' => ['联系人2电话','contact2_phone'],
        'contact2_whatsapp' => ['联系人2WhatsApp','contact2_whatsapp'],
        'contact2_wechat' => ['联系人2微信','contact2_wechat'],
        'contact2_linkedin' => ['联系人2LinkedIn','contact2_linkedin'],
        'contact2_gender' => ['联系人2性别','contact2_gender'],
        'contact2_birthday' => ['联系人2生日','contact2_birthday'],
        'contact2_language' => ['联系人2语言','contact2_language'],
        'contact2_sources' => ['联系人2来源','contact2_sources'],
        'contact2_role_tags' => ['联系人2角色标签','contact2_role_tags'],
        'contact2_promotion_channels' => ['联系人2推广方式','contact2_promotion_channels'],
        'contact2_is_primary' => ['联系人2主联系人','contact2_is_primary'],
        'contact2_is_left' => ['联系人2已离职','contact2_is_left'],
        'contact2_remark' => ['联系人2备注','contact2_remark'],
        'contact3_name' => ['联系人3姓名','联系人3','contact3_name'],
        'contact3_name_en' => ['联系人3英文名','contact3_name_en'],
        'contact3_position' => ['联系人3职位','contact3_position'],
        'contact3_department' => ['联系人3部门','contact3_department'],
        'contact3_email' => ['联系人3邮箱','contact3_email'],
        'contact3_phone' => ['联系人3电话','contact3_phone'],
        'contact3_whatsapp' => ['联系人3WhatsApp','contact3_whatsapp'],
        'contact3_wechat' => ['联系人3微信','contact3_wechat'],
        'contact3_linkedin' => ['联系人3LinkedIn','contact3_linkedin'],
        'contact3_gender' => ['联系人3性别','contact3_gender'],
        'contact3_birthday' => ['联系人3生日','contact3_birthday'],
        'contact3_language' => ['联系人3语言','contact3_language'],
        'contact3_sources' => ['联系人3来源','contact3_sources'],
        'contact3_role_tags' => ['联系人3角色标签','contact3_role_tags'],
        'contact3_promotion_channels' => ['联系人3推广方式','contact3_promotion_channels'],
        'contact3_is_primary' => ['联系人3主联系人','contact3_is_primary'],
        'contact3_is_left' => ['联系人3已离职','contact3_is_left'],
        'contact3_remark' => ['联系人3备注','contact3_remark'],
        'contact4_name' => ['联系人4姓名','联系人4','contact4_name'],
        'contact4_name_en' => ['联系人4英文名','contact4_name_en'],
        'contact4_position' => ['联系人4职位','contact4_position'],
        'contact4_department' => ['联系人4部门','contact4_department'],
        'contact4_email' => ['联系人4邮箱','contact4_email'],
        'contact4_phone' => ['联系人4电话','contact4_phone'],
        'contact4_whatsapp' => ['联系人4WhatsApp','contact4_whatsapp'],
        'contact4_wechat' => ['联系人4微信','contact4_wechat'],
        'contact4_linkedin' => ['联系人4LinkedIn','contact4_linkedin'],
        'contact4_gender' => ['联系人4性别','contact4_gender'],
        'contact4_birthday' => ['联系人4生日','contact4_birthday'],
        'contact4_language' => ['联系人4语言','contact4_language'],
        'contact4_sources' => ['联系人4来源','contact4_sources'],
        'contact4_role_tags' => ['联系人4角色标签','contact4_role_tags'],
        'contact4_promotion_channels' => ['联系人4推广方式','contact4_promotion_channels'],
        'contact4_is_primary' => ['联系人4主联系人','contact4_is_primary'],
        'contact4_is_left' => ['联系人4已离职','contact4_is_left'],
        'contact4_remark' => ['联系人4备注','contact4_remark'],
        'contact5_name' => ['联系人5姓名','联系人5','contact5_name'],
        'contact5_name_en' => ['联系人5英文名','contact5_name_en'],
        'contact5_position' => ['联系人5职位','contact5_position'],
        'contact5_department' => ['联系人5部门','contact5_department'],
        'contact5_email' => ['联系人5邮箱','contact5_email'],
        'contact5_phone' => ['联系人5电话','contact5_phone'],
        'contact5_whatsapp' => ['联系人5WhatsApp','contact5_whatsapp'],
        'contact5_wechat' => ['联系人5微信','contact5_wechat'],
        'contact5_linkedin' => ['联系人5LinkedIn','contact5_linkedin'],
        'contact5_gender' => ['联系人5性别','contact5_gender'],
        'contact5_birthday' => ['联系人5生日','contact5_birthday'],
        'contact5_language' => ['联系人5语言','contact5_language'],
        'contact5_sources' => ['联系人5来源','contact5_sources'],
        'contact5_role_tags' => ['联系人5角色标签','contact5_role_tags'],
        'contact5_promotion_channels' => ['联系人5推广方式','contact5_promotion_channels'],
        'contact5_is_primary' => ['联系人5主联系人','contact5_is_primary'],
        'contact5_is_left' => ['联系人5已离职','contact5_is_left'],
        'contact5_remark' => ['联系人5备注','contact5_remark'],
        'remark' => ['备注','remark','note'],
    ];
}

function crm_customer_import_normalize_key(string $key): string
{
    $key = trim($key);
    $match = crm_customer_import_match_header($key);
    if ($match && (int)$match['score'] >= 72) return (string)$match['field'];
    return preg_replace('/[^a-z0-9_]/i', '_', strtolower($key));
}

function crm_customer_import_match_header(string $key): ?array
{
    $raw = trim($key);
    if ($raw === '') return null;
    $needle = crm_customer_import_header_signature($raw);
    $best = null;
    foreach (crm_customer_import_headers() as $field => $aliases) {
        foreach ($aliases as $alias) {
            $aliasSig = crm_customer_import_header_signature((string)$alias);
            if ($aliasSig === '') continue;
            $score = 0;
            if ($needle === $aliasSig) {
                $score = 100;
            } elseif (strpos($needle, $aliasSig) !== false || strpos($aliasSig, $needle) !== false) {
                $score = min(96, 72 + min(strlen($needle), strlen($aliasSig)));
            } else {
                similar_text($needle, $aliasSig, $pct);
                $score = (int)round($pct);
            }
            if (!$best || $score > $best['score']) {
                $best = ['field' => $field, 'alias' => (string)$alias, 'score' => $score, 'source_header' => $raw];
            }
        }
    }
    return $best;
}

function crm_customer_import_header_signature(string $value): string
{
    $value = mb_strtolower(trim($value));
    $value = str_replace(['（','）','【','】','[',']','(',')','/','\\','-','—','_',' ', "\t", "\n", "\r", ':', '：'], '', $value);
    $value = preg_replace('/^(必填|required|req\.?)/iu', '', $value);
    $value = preg_replace('/(必填|required|req\.?)$/iu', '', $value);
    $aliases = [
        '公司' => '客户名称',
        '公司名' => '客户名称',
        '公司名称' => '客户名称',
        '客户公司' => '客户名称',
        '客户编号' => '客户代码',
        '编号' => '客户代码',
        '国家地区' => '国家',
        '地区国家' => '国家',
        '地区' => '城市',
        '网址' => '网站',
        '联系人' => '联系人1姓名',
        '联系人姓名' => '联系人1姓名',
        '联系人邮箱' => '联系人1邮箱',
        '联系人电话' => '联系人1电话',
        '联系人手机' => '联系人1电话',
        '联系人职位' => '联系人1职位',
        'mailaddress' => '邮箱',
        'emailaddress' => '邮箱',
        'emailaddr' => '邮箱',
        'mobilephone' => '电话',
        'telephone' => '电话',
        'cellphone' => '电话',
    ];
    return $aliases[$value] ?? $value;
}

function crm_customer_import_read_csv(string $path): array
{
    $rows = [];
    $fh = fopen($path, 'r');
    if (!$fh) return $rows;
    $header = null;
    $rawHeader = null;
    while (($line = fgetcsv($fh)) !== false) {
        if ($line && isset($line[0])) $line[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$line[0]);
        if ($header === null && count($line) === 1) {
            $first = (string)$line[0];
            foreach (["\t", ';', ','] as $candidate) {
                if (substr_count($first, $candidate) > 0) {
                    $line = str_getcsv($first, $candidate);
                    break;
                }
            }
        }
        if ($header === null) {
            $rawHeader = array_map(fn($v) => trim((string)$v), $line);
            $header = array_map(fn($v) => crm_customer_import_normalize_key((string)$v), $rawHeader);
            continue;
        }
        if (!array_filter($line, fn($v) => trim((string)$v) !== '')) continue;
        $row = [];
        foreach ($header as $index => $field) $row[$field] = trim((string)($line[$index] ?? ''));
        $rows[] = $row;
    }
    fclose($fh);
    $GLOBALS['crm_customer_import_last_mapping'] = crm_customer_import_mapping_report($rawHeader ?: [], $header ?: []);
    return $rows;
}

function crm_customer_import_read_html_table(string $path): array
{
    $html = file_get_contents($path);
    if ($html === false || stripos($html, '<table') === false) return [];
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    if (!$loaded) return [];
    $trs = $dom->getElementsByTagName('tr');
    $header = null;
    $rawHeader = null;
    $rows = [];
    foreach ($trs as $tr) {
        $cells = [];
        foreach ($tr->childNodes as $cell) {
            if (!in_array(strtolower($cell->nodeName), ['th','td'], true)) continue;
            $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode($cell->textContent ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            $cells[] = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        }
        if (!$cells || !array_filter($cells, fn($v) => trim((string)$v) !== '')) continue;
        if ($header === null) {
            $rawHeader = array_map(fn($v) => trim((string)$v), $cells);
            $header = array_map(fn($v) => crm_customer_import_normalize_key((string)$v), $rawHeader);
            continue;
        }
        $row = [];
        foreach ($header as $index => $field) $row[$field] = trim((string)($cells[$index] ?? ''));
        $rows[] = $row;
    }
    $GLOBALS['crm_customer_import_last_mapping'] = crm_customer_import_mapping_report($rawHeader ?: [], $header ?: []);
    return $rows;
}

function crm_customer_import_read_xlsx(string $path): array
{
    $GLOBALS['crm_customer_import_sheet_previews'] = [];
    $shared = [];
    $sharedXml = crm_customer_import_xlsx_entry($path, 'xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $xml = @simplexml_load_string($sharedXml);
        if ($xml) {
            foreach (($xml->xpath('//*[local-name()="si"]') ?: []) as $si) {
                $parts = $si->xpath('.//*[local-name()="t"]') ?: [];
                $text = '';
                foreach ($parts as $part) $text .= (string)$part;
                $shared[] = trim($text);
            }
        } else {
            $shared = crm_customer_import_xlsx_shared_strings_regex($sharedXml);
        }
    }
    $sheetPaths = crm_customer_import_xlsx_sheet_paths($path);
    if (!$sheetPaths) {
        crm_customer_import_store_sheet_preview([], 'xlsx 文件', -1, '没有读取到 Excel 工作表。文件可能不是标准 .xlsx，或是旧版 .xls / WPS 加密文件 / 被损坏的压缩包。');
        return [];
    }
    foreach ($sheetPaths as $sheetPath) {
        $sheetXml = crm_customer_import_xlsx_entry($path, $sheetPath);
        if ($sheetXml === false) {
            crm_customer_import_store_sheet_preview([], $sheetPath, -1, '工作表 XML 读取失败，可能是文件结构异常或服务器无法解压该工作表。');
            continue;
        }
        $rows = crm_customer_import_xlsx_rows_from_sheet($sheetXml, $shared, $sheetPath);
        if ($rows) return $rows;
    }
    return [];
}

function crm_customer_import_xlsx_entry(string $path, string $entry)
{
    if (!preg_match('#^[A-Za-z0-9_./\\-]+$#', $entry)) return false;
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($path) === true) {
            $content = $zip->getFromName($entry);
            $zip->close();
            if ($content !== false) return $content;
        }
    }
    $content = crm_customer_import_zip_entry_pure($path, $entry);
    if ($content !== false) return $content;
    $unzip = trim((string)@shell_exec('command -v unzip 2>/dev/null'));
    if ($unzip === '') return false;
    $cmd = escapeshellcmd($unzip) . ' -p ' . escapeshellarg($path) . ' ' . escapeshellarg($entry) . ' 2>/dev/null';
    $content = @shell_exec($cmd);
    return $content === null || $content === '' ? false : $content;
}

function crm_customer_import_zip_entry_pure(string $path, string $entry)
{
    $data = @file_get_contents($path);
    if ($data === false || strlen($data) < 22) return false;
    $eocdPos = strrpos($data, "PK\x05\x06");
    if ($eocdPos === false || strlen($data) < $eocdPos + 22) return false;
    $eocd = unpack('vdisk/vstart_disk/ventries_disk/ventries/Vcd_size/Vcd_offset/vcomment_len', substr($data, $eocdPos + 4, 18));
    if (!$eocd || empty($eocd['cd_offset']) || empty($eocd['cd_size'])) return false;
    $pos = (int)$eocd['cd_offset'];
    $end = $pos + (int)$eocd['cd_size'];
    while ($pos + 46 <= $end && substr($data, $pos, 4) === "PK\x01\x02") {
        $h = unpack('vver_made/vver_needed/vflag/vmethod/vtime/vdate/Vcrc/Vcomp_size/Vuncomp_size/vname_len/vextra_len/vcomment_len/vdisk/vint_attr/Vext_attr/Vlocal_offset', substr($data, $pos + 4, 42));
        if (!$h) break;
        $name = substr($data, $pos + 46, $h['name_len']);
        if ($name === $entry) {
            $local = (int)$h['local_offset'];
            if (substr($data, $local, 4) !== "PK\x03\x04") return false;
            $lh = unpack('vver/vflag/vmethod/vtime/vdate/Vcrc/Vcomp_size/Vuncomp_size/vname_len/vextra_len', substr($data, $local + 4, 26));
            if (!$lh) return false;
            $start = $local + 30 + (int)$lh['name_len'] + (int)$lh['extra_len'];
            $compressed = substr($data, $start, (int)$h['comp_size']);
            if ((int)$h['method'] === 0) return $compressed;
            if ((int)$h['method'] === 8) {
                $inflated = @gzinflate($compressed);
                return $inflated === false ? false : $inflated;
            }
            return false;
        }
        $pos += 46 + (int)$h['name_len'] + (int)$h['extra_len'] + (int)$h['comment_len'];
    }
    return false;
}

function crm_customer_import_xlsx_sheet_paths(string $path): array
{
    $workbookXml = crm_customer_import_xlsx_entry($path, 'xl/workbook.xml');
    $relsXml = crm_customer_import_xlsx_entry($path, 'xl/_rels/workbook.xml.rels');
    $paths = [];
    if ($workbookXml !== false && $relsXml !== false) {
        $workbook = @simplexml_load_string($workbookXml);
        $rels = @simplexml_load_string($relsXml);
        if ($workbook && $rels) {
            $workbook->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $relMap = [];
            foreach ($rels->Relationship as $rel) {
                $target = str_replace('\\', '/', (string)$rel['Target']);
                if ($target === '') continue;
                if (strpos($target, '/') === 0) $target = ltrim($target, '/');
                elseif (strpos($target, 'xl/') !== 0) $target = 'xl/' . $target;
                $relMap[(string)$rel['Id']] = $target;
            }
            foreach (($workbook->xpath('//*[local-name()="sheet"]') ?: []) as $sheet) {
                $rid = (string)$sheet->attributes('r', true)['id'];
                if ($rid !== '' && !empty($relMap[$rid])) $paths[] = $relMap[$rid];
            }
        }
    }
    if (!$paths) {
        for ($i = 1; $i <= 20; $i++) {
            $candidate = 'xl/worksheets/sheet' . $i . '.xml';
            if (crm_customer_import_xlsx_entry($path, $candidate) !== false) $paths[] = $candidate;
        }
    }
    return array_values(array_unique($paths));
}

function crm_customer_import_xlsx_rows_from_sheet(string $sheetXml, array $shared, string $sheetPath = ''): array
{
    $xml = @simplexml_load_string($sheetXml);
    if (!$xml) {
        $matrix = crm_customer_import_xlsx_matrix_regex($sheetXml, $shared);
        if ($matrix) return crm_customer_import_rows_from_matrix($matrix, $sheetPath, 'XML 解析失败，已启用宽容解析。');
        crm_customer_import_store_sheet_preview([], $sheetPath, -1, '工作表 XML 无法解析，宽容解析也未读取到单元格。');
        return [];
    }
    $matrix = [];
    foreach (($xml->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: []) as $rowNode) {
        $row = [];
        foreach (($rowNode->xpath('./*[local-name()="c"]') ?: []) as $cell) {
            $ref = (string)$cell['r'];
            $col = crm_customer_import_xlsx_column_index($ref);
            $value = crm_customer_import_xlsx_cell_value($cell, $shared);
            $row[$col] = trim($value);
        }
        if (array_filter($row, fn($v) => trim((string)$v) !== '')) {
            ksort($row);
            $matrix[] = $row;
        }
    }
    if (!$matrix) {
        $fallbackMatrix = crm_customer_import_xlsx_matrix_regex($sheetXml, $shared);
        if ($fallbackMatrix) return crm_customer_import_rows_from_matrix($fallbackMatrix, $sheetPath, 'XPath 未读取到单元格，已启用宽容解析。');
    }
    return crm_customer_import_rows_from_matrix($matrix, $sheetPath);
}

function crm_customer_import_rows_from_matrix(array $matrix, string $sheetPath, string $parseNote = ''): array
{
    if (!$matrix) {
        crm_customer_import_store_sheet_preview([], $sheetPath, -1, '工作表为空，或内容不是可读取的单元格文本。');
        return [];
    }
    $headerRowIndex = crm_customer_import_detect_header_row($matrix);
    if ($headerRowIndex < 0) {
        $reason = '未找到包含“客户名称”以及“国家 / 客户代码 / 邮箱 / 电话”等字段的表头。';
        if ($parseNote !== '') $reason = $parseNote . ' ' . $reason;
        crm_customer_import_store_sheet_preview($matrix, $sheetPath, -1, $reason);
        return [];
    }
    $headerLine = $matrix[$headerRowIndex];
    $maxCol = max(array_keys($headerLine));
    $header = [];
    $rawHeader = [];
    for ($i = 0; $i <= $maxCol; $i++) {
        $rawHeader[$i] = trim((string)($headerLine[$i] ?? ''));
        $header[$i] = crm_customer_import_normalize_key($rawHeader[$i]);
    }
    $GLOBALS['crm_customer_import_last_mapping'] = crm_customer_import_mapping_report($rawHeader, $header);
    $rows = [];
    foreach (array_slice($matrix, $headerRowIndex + 1) as $line) {
        if (!array_filter($line, fn($v) => trim((string)$v) !== '')) continue;
        $data = [];
        foreach ($header as $index => $field) {
            if ($field === '') continue;
            $data[$field] = trim((string)($line[$index] ?? ''));
        }
        if (trim((string)($data['customer_name'] ?? '')) === '' && trim((string)($data['country'] ?? '')) === '') continue;
        $rows[] = $data;
    }
    if (!$rows) {
        $reason = '已识别表头，但表头下方没有客户数据行。';
        if ($parseNote !== '') $reason = $parseNote . ' ' . $reason;
        crm_customer_import_store_sheet_preview($matrix, $sheetPath, $headerRowIndex, $reason);
    }
    return $rows;
}

function crm_customer_import_xlsx_shared_strings_regex(string $sharedXml): array
{
    $shared = [];
    if (!preg_match_all('/<(?:[A-Za-z_][\w.\-]*:)?si\b[^>]*>(.*?)<\/(?:[A-Za-z_][\w.\-]*:)?si>/is', $sharedXml, $items)) return $shared;
    foreach ($items[1] as $itemXml) {
        $text = '';
        if (preg_match_all('/<(?:[A-Za-z_][\w.\-]*:)?t\b[^>]*>(.*?)<\/(?:[A-Za-z_][\w.\-]*:)?t>/is', $itemXml, $texts)) {
            foreach ($texts[1] as $part) $text .= html_entity_decode(strip_tags($part), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
        $shared[] = trim($text);
    }
    return $shared;
}

function crm_customer_import_xlsx_matrix_regex(string $sheetXml, array $shared): array
{
    $matrix = [];
    if (!preg_match_all('/<(?:[A-Za-z_][\w.\-]*:)?row\b[^>]*>(.*?)<\/(?:[A-Za-z_][\w.\-]*:)?row>/is', $sheetXml, $rowMatches)) return crm_customer_import_xlsx_text_matrix_regex($sheetXml);
    foreach ($rowMatches[1] as $rowXml) {
        $row = [];
        if (!preg_match_all('/<(?:[A-Za-z_][\w.\-]*:)?c\b([^>]*)>(.*?)<\/(?:[A-Za-z_][\w.\-]*:)?c>/is', $rowXml, $cellMatches, PREG_SET_ORDER)) continue;
        foreach ($cellMatches as $cellMatch) {
            $attrs = $cellMatch[1] ?? '';
            $body = $cellMatch[2] ?? '';
            $ref = '';
            $type = '';
            if (preg_match('/\br=["\']([^"\']+)["\']/i', $attrs, $m)) $ref = $m[1];
            if (preg_match('/\bt=["\']([^"\']+)["\']/i', $attrs, $m)) $type = $m[1];
            $col = crm_customer_import_xlsx_column_index($ref);
            $value = '';
            if ($type === 'inlineStr' && preg_match_all('/<(?:[A-Za-z_][\w.\-]*:)?t\b[^>]*>(.*?)<\/(?:[A-Za-z_][\w.\-]*:)?t>/is', $body, $texts)) {
                foreach ($texts[1] as $part) $value .= html_entity_decode(strip_tags($part), ENT_QUOTES | ENT_XML1, 'UTF-8');
            } elseif (preg_match('/<(?:[A-Za-z_][\w.\-]*:)?v\b[^>]*>(.*?)<\/(?:[A-Za-z_][\w.\-]*:)?v>/is', $body, $m)) {
                $raw = html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES | ENT_XML1, 'UTF-8');
                $value = $type === 's' ? (string)($shared[(int)$raw] ?? '') : $raw;
            } elseif (preg_match_all('/<(?:[A-Za-z_][\w.\-]*:)?t\b[^>]*>(.*?)<\/(?:[A-Za-z_][\w.\-]*:)?t>/is', $body, $texts)) {
                foreach ($texts[1] as $part) $value .= html_entity_decode(strip_tags($part), ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
            if (trim($value) !== '') $row[$col] = trim($value);
        }
        if (array_filter($row, fn($v) => trim((string)$v) !== '')) {
            ksort($row);
            $matrix[] = $row;
        }
    }
    return $matrix;
}

function crm_customer_import_xlsx_text_matrix_regex(string $sheetXml): array
{
    $text = trim(html_entity_decode(preg_replace('/\s+/u', ' ', strip_tags($sheetXml)), ENT_QUOTES | ENT_XML1, 'UTF-8'));
    if ($text === '') return [];
    $parts = preg_split('/\s{2,}|[\r\n\t]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    if (!$parts) $parts = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    $row = [];
    foreach (array_slice($parts ?: [], 0, 16) as $index => $part) {
        $row[$index] = trim((string)$part);
    }
    return $row ? [$row] : [];
}

function crm_customer_import_store_sheet_preview(array $matrix, string $sheetPath, int $headerRowIndex, string $reason): void
{
    $previewRows = [];
    $bestRow = -1;
    $bestHits = 0;
    $bestScore = 0;
    foreach ($matrix as $index => $line) {
        $hits = 0;
        $score = 0;
        foreach ($line as $cell) {
            $text = trim((string)$cell);
            if ($text === '') continue;
            $match = crm_customer_import_match_header($text);
            if ($match && (int)($match['score'] ?? 0) >= 45) {
                $hits++;
                $score += (int)$match['score'];
            }
        }
        if ($hits > $bestHits || ($hits === $bestHits && $score > $bestScore)) {
            $bestRow = $index;
            $bestHits = $hits;
            $bestScore = $score;
        }
    }
    foreach (array_slice($matrix, 0, 10, true) as $index => $line) {
        $maxCol = $line ? min(max(array_keys($line)), 15) : 0;
        $cells = [];
        for ($i = 0; $i <= $maxCol; $i++) {
            $cells[] = trim((string)($line[$i] ?? ''));
        }
        $previewRows[] = [
            'row_no' => (int)$index + 1,
            'cells' => $cells,
            'is_best_header_guess' => $index === $bestRow,
            'is_detected_header' => $index === $headerRowIndex,
        ];
    }
    $GLOBALS['crm_customer_import_sheet_previews'][] = [
        'sheet' => $sheetPath !== '' ? basename($sheetPath) : '工作表',
        'reason' => $reason,
        'detected_header_row' => $headerRowIndex >= 0 ? $headerRowIndex + 1 : null,
        'best_header_guess_row' => $bestRow >= 0 ? $bestRow + 1 : null,
        'best_header_hits' => $bestHits,
        'rows' => $previewRows,
    ];
}

function crm_customer_import_mapping_report(array $rawHeader, array $mappedHeader): array
{
    $fields = crm_customer_import_headers();
    $labels = crm_customer_import_field_labels();
    $mapped = [];
    $unknown = [];
    $duplicates = [];
    $seen = [];
    foreach ($rawHeader as $index => $source) {
        $source = trim((string)$source);
        $field = (string)($mappedHeader[$index] ?? '');
        $match = $source !== '' ? crm_customer_import_match_header($source) : null;
        $isKnown = $field !== '' && isset($fields[$field]);
        if ($isKnown) {
            if (isset($seen[$field])) $duplicates[] = ['field' => $field, 'label' => $labels[$field] ?? $field, 'source_header' => $source, 'first_header' => $seen[$field]];
            $seen[$field] = $seen[$field] ?? $source;
            $mapped[] = [
                'source_header' => $source,
                'field' => $field,
                'label' => $labels[$field] ?? $field,
                'confidence' => (int)($match['score'] ?? 100),
                'matched_alias' => (string)($match['alias'] ?? $source),
            ];
        } elseif ($source !== '') {
            $unknown[] = [
                'source_header' => $source,
                'suggestion' => $match && (int)$match['score'] >= 45 ? ['field' => $match['field'], 'label' => $labels[$match['field']] ?? $match['field'], 'confidence' => (int)$match['score']] : null,
            ];
        }
    }
    $required = ['customer_name' => '客户名称', 'country' => '国家'];
    $missingRequired = [];
    foreach ($required as $field => $label) {
        if (!isset($seen[$field])) $missingRequired[] = ['field' => $field, 'label' => $label];
    }
    return [
        'mapped' => $mapped,
        'unknown' => $unknown,
        'duplicates' => $duplicates,
        'missing_required' => $missingRequired,
        'mapped_count' => count($mapped),
        'unknown_count' => count($unknown),
        'total_columns' => count(array_filter($rawHeader, fn($v) => trim((string)$v) !== '')),
    ];
}

function crm_customer_import_field_labels(): array
{
    return [
        'customer_code' => '客户代码',
        'customer_name' => '客户名称',
        'customer_name_en' => '英文名称',
        'country' => '国家',
        'city' => '城市/地区',
        'address' => '地址',
        'website' => '网站',
        'email' => '邮箱',
        'phone' => '电话',
        'whatsapp' => 'WhatsApp',
        'level' => '客户等级',
        'status' => '客户状态',
        'lifecycle_key' => '生命周期',
        'risk_level' => '风险等级',
        'source_tags' => '来源标签',
        'promotion_channels' => '推广方式',
        'promotion_status' => '推广状态',
        'owner_user_id' => '负责人ID',
        'owner_department' => '部门',
        'contacts_json' => '联系人明细JSON',
        'all_contacts' => '所有联系人',
        'contact1_name' => '联系人1姓名',
        'contact1_name_en' => '联系人1英文名',
        'contact1_position' => '联系人1职位',
        'contact1_department' => '联系人1部门',
        'contact1_email' => '联系人1邮箱',
        'contact1_phone' => '联系人1电话',
        'contact1_whatsapp' => '联系人1WhatsApp',
        'contact1_wechat' => '联系人1微信',
        'contact1_linkedin' => '联系人1LinkedIn',
        'contact1_role_tags' => '联系人1角色标签',
        'contact1_promotion_channels' => '联系人1推广方式',
        'remark' => '备注',
    ];
}

function crm_customer_import_xlsx_first_sheet_path(string $path): string
{
    $paths = crm_customer_import_xlsx_sheet_paths($path);
    return $paths[0] ?? '';
}

function crm_customer_import_xlsx_column_index(string $ref): int
{
    preg_match('/([A-Z]+)/i', $ref, $m);
    $letters = strtoupper($m[1] ?? 'A');
    $col = 0;
    foreach (str_split($letters) as $ch) $col = $col * 26 + (ord($ch) - 64);
    return max(0, $col - 1);
}

function crm_customer_import_xlsx_cell_value(SimpleXMLElement $cell, array $shared): string
{
    $type = (string)$cell['t'];
    $valueNode = ($cell->xpath('./*[local-name()="v"]') ?: [])[0] ?? null;
    $rawValue = $valueNode ? (string)$valueNode : (string)($cell->v ?? '');
    if ($type === 's') {
        $idx = (int)$rawValue;
        return (string)($shared[$idx] ?? '');
    }
    if ($type === 'inlineStr') {
        $parts = $cell->xpath('.//*[local-name()="t"]') ?: [];
        $text = '';
        foreach ($parts as $part) $text .= (string)$part;
        return $text;
    }
    if ($type === 'str') return $rawValue;
    if ($type === 'b') return ($rawValue === '1') ? '是' : '';
    return $rawValue;
}

function crm_customer_import_detect_header_row(array $matrix): int
{
    $headers = crm_customer_import_headers();
    foreach ($matrix as $index => $line) {
        $fields = [];
        foreach ($line as $cell) {
            $text = trim((string)$cell);
            if ($text === '') continue;
            $field = crm_customer_import_normalize_key($text);
            if (isset($headers[$field])) $fields[$field] = true;
        }
        if (isset($fields['customer_name']) && (isset($fields['country']) || isset($fields['customer_code']) || isset($fields['email']) || isset($fields['phone']))) return (int)$index;
    }
    return -1;
}

function crm_customer_import_uploaded_rows(?array $file): array
{
    if (!$file || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) throw new RuntimeException('请先选择客户 Excel/CSV 文件。');
    $name = strtolower((string)($file['name'] ?? ''));
    $sample = file_get_contents($file['tmp_name'], false, null, 0, 2048) ?: '';
    if (stripos($sample, '<table') !== false || stripos($sample, '<html') !== false) {
        $rows = crm_customer_import_read_html_table($file['tmp_name']);
        if ($rows) return $rows;
        throw new RuntimeException('未能读取导出的 Excel 表格，请确认文件未被破坏。');
    }
    if (substr($name, -5) === '.xlsx') {
        $rows = crm_customer_import_read_xlsx($file['tmp_name']);
        if ($rows) return $rows;
        return [];
    }
    return crm_customer_import_read_csv($file['tmp_name']);
}

function crm_customer_import_exact_exists(string $field, string $value): bool
{
    if ($value === '' || !in_array($field, ['customer_code','customer_name','email','website'], true)) return false;
    $stmt = db()->prepare("SELECT id FROM crm_customers WHERE {$field} = ? AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([$value]);
    return (bool)$stmt->fetchColumn();
}

function crm_import_truthy($value): bool
{
    $value = mb_strtolower(trim((string)$value));
    return in_array($value, ['1','是','yes','y','true','主','primary','已离职','left'], true);
}

function crm_import_scalar_text($value): string
{
    if (is_array($value)) return implode(',', array_filter(array_map('strval', $value), fn($item) => trim($item) !== ''));
    return trim((string)$value);
}

function crm_import_contact_channels(array $contact): array
{
    $channels = crm_import_dictionary_values('promotion_channel', crm_import_scalar_text($contact['promotion_channels'] ?? ''));
    if (!$channels && !empty($contact['contact_promotion_channels'])) $channels = crm_import_dictionary_values('promotion_channel', crm_import_scalar_text($contact['contact_promotion_channels']));
    return $channels;
}

function crm_import_normalize_contact(array $contact, int $index = 0): array
{
    $channels = crm_import_contact_channels($contact);
    $sources = crm_import_scalar_text($contact['contact_sources'] ?? ($contact['source_tags'] ?? ($contact['source'] ?? '')));
    $roles = crm_import_scalar_text($contact['role_tags'] ?? ($contact['role'] ?? ''));
    return [
        'name' => trim((string)($contact['name'] ?? '')),
        'name_en' => trim((string)($contact['name_en'] ?? '')),
        'position' => trim((string)($contact['position'] ?? '')),
        'department' => trim((string)($contact['department'] ?? '')),
        'email' => trim((string)($contact['email'] ?? '')),
        'phone' => trim((string)($contact['phone'] ?? '')),
        'whatsapp' => trim((string)($contact['whatsapp'] ?? '')),
        'wechat' => trim((string)($contact['wechat'] ?? '')),
        'linkedin' => trim((string)($contact['linkedin'] ?? '')),
        'gender' => trim((string)($contact['gender'] ?? '')),
        'birthday' => trim((string)($contact['birthday'] ?? '')),
        'language' => trim((string)($contact['language'] ?? '')),
        'contact_sources' => $sources !== '' ? crm_import_dictionary_values('contact_source', $sources) : ['manual'],
        'role_tags' => $roles !== '' ? crm_import_dictionary_values('contact_role', $roles) : [],
        'is_primary' => isset($contact['is_primary']) ? (crm_import_truthy($contact['is_primary']) ? 1 : 0) : ($index === 1 ? 1 : 0),
        'is_left' => !empty($contact['is_left']) && crm_import_truthy($contact['is_left']) ? 1 : 0,
        'remark' => trim((string)($contact['remark'] ?? '')),
        'promotion_email' => in_array('email', $channels, true) ? 'active' : 'no_contact',
        'promotion_whatsapp' => in_array('whatsapp', $channels, true) ? 'active' : 'no_contact',
        'promotion_linkedin' => in_array('linkedin', $channels, true) ? 'active' : 'no_contact',
    ];
}

function crm_customer_import_contacts_from_row(array $row): array
{
    $contacts = [];
    $rawJson = trim((string)($row['contacts_json'] ?? ''));
    if ($rawJson !== '') {
        $decoded = json_decode($rawJson, true);
        if (is_array($decoded)) {
            foreach ($decoded as $idx => $contact) {
                if (is_array($contact)) $contacts[] = crm_import_normalize_contact($contact, $idx + 1);
            }
        }
    }
    for ($i = 1; $i <= 5; $i++) {
        $contact = [
            'name' => $row["contact{$i}_name"] ?? '',
            'name_en' => $row["contact{$i}_name_en"] ?? '',
            'position' => $row["contact{$i}_position"] ?? '',
            'department' => $row["contact{$i}_department"] ?? '',
            'email' => $row["contact{$i}_email"] ?? '',
            'phone' => $row["contact{$i}_phone"] ?? '',
            'whatsapp' => $row["contact{$i}_whatsapp"] ?? '',
            'wechat' => $row["contact{$i}_wechat"] ?? '',
            'linkedin' => $row["contact{$i}_linkedin"] ?? '',
            'gender' => $row["contact{$i}_gender"] ?? '',
            'birthday' => $row["contact{$i}_birthday"] ?? '',
            'language' => $row["contact{$i}_language"] ?? '',
            'contact_sources' => $row["contact{$i}_sources"] ?? '',
            'role_tags' => $row["contact{$i}_role_tags"] ?? '',
            'promotion_channels' => $row["contact{$i}_promotion_channels"] ?? '',
            'is_primary' => $row["contact{$i}_is_primary"] ?? ($i === 1 ? '是' : ''),
            'is_left' => $row["contact{$i}_is_left"] ?? '',
            'remark' => $row["contact{$i}_remark"] ?? '',
        ];
        if (trim(implode('', array_map('strval', $contact))) !== '') $contacts[] = crm_import_normalize_contact($contact, $i);
    }
    $legacy = trim((string)($row['all_contacts'] ?? ''));
    if ($legacy !== '') {
        foreach (preg_split('/[;；]+/', $legacy, -1, PREG_SPLIT_NO_EMPTY) as $idx => $part) {
            $pieces = array_map('trim', explode('|', $part));
            if (empty($pieces[0])) continue;
            $contacts[] = crm_import_normalize_contact([
                'name' => $pieces[0] ?? '',
                'position' => $pieces[1] ?? '',
                'email' => $pieces[2] ?? '',
                'phone' => $pieces[3] ?? '',
                'whatsapp' => $pieces[4] ?? '',
            ], $idx + 1);
        }
    }
    $unique = [];
    $seen = [];
    foreach ($contacts as $contact) {
        if (trim((string)($contact['name'] ?? '')) === '') continue;
        $key = mb_strtolower(($contact['name'] ?? '') . '|' . ($contact['email'] ?? '') . '|' . ($contact['phone'] ?? ''));
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $unique[] = $contact;
    }
    return $unique;
}

function crm_customer_import_preview(array $input, ?array $file): array
{
    crm_require('customer.import');
    $GLOBALS['crm_customer_import_last_mapping'] = ['mapped' => [], 'unknown' => [], 'duplicates' => [], 'missing_required' => [], 'mapped_count' => 0, 'unknown_count' => 0, 'total_columns' => 0];
    $GLOBALS['crm_customer_import_sheet_previews'] = [];
    $parseError = '';
    try {
        $rows = crm_customer_import_uploaded_rows($file);
    } catch (Throwable $e) {
        $rows = [];
        $parseError = $e->getMessage();
    }
    $mapping = $GLOBALS['crm_customer_import_last_mapping'] ?? [];
    $rawPreview = $GLOBALS['crm_customer_import_sheet_previews'] ?? [];
    $seenCodes = [];
    $seenNames = [];
    $preview = [];
    foreach ($rows as $index => $row) {
        $row['row_no'] = $index + 2;
        $errors = [];
        $warnings = [];
        $name = trim((string)($row['customer_name'] ?? ''));
        $code = trim((string)($row['customer_code'] ?? ''));
        $country = trim((string)($row['country'] ?? ''));
        $email = trim((string)($row['email'] ?? ''));
        $website = trim((string)($row['website'] ?? ''));
        $missing = [];
        if ($name === '') $errors[] = '缺少客户名称';
        if ($name === '') $missing[] = '客户名称';
        if ($country === '') $errors[] = '缺少国家';
        if ($country === '') $missing[] = '国家';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = '邮箱格式不正确';
        if ($website !== '' && !preg_match('/^https?:\\/\\//i', $website)) $website = 'https://' . $website;
        if ($website !== '' && !filter_var($website, FILTER_VALIDATE_URL)) $errors[] = '网站格式不正确';
        $contacts = crm_customer_import_contacts_from_row($row);
        foreach ($contacts as $contactIndex => $contact) {
            $contactEmail = trim((string)($contact['email'] ?? ''));
            if ($contactEmail !== '' && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) $errors[] = '联系人' . ($contactIndex + 1) . '邮箱格式不正确';
        }
        $row['contacts_json'] = json_encode($contacts, JSON_UNESCAPED_UNICODE);
        if ($contacts) $warnings[] = '识别联系人 ' . count($contacts) . ' 个';
        if ($code !== '' && crm_customer_import_exact_exists('customer_code', $code)) $errors[] = '客户代码已存在';
        if ($name !== '' && crm_customer_import_exact_exists('customer_name', $name)) $errors[] = '客户名称已存在';
        if ($code !== '' && isset($seenCodes[mb_strtolower($code)])) $errors[] = '文件内客户代码重复';
        if ($name !== '' && isset($seenNames[mb_strtolower($name)])) $errors[] = '文件内客户名称重复';
        if ($code !== '') $seenCodes[mb_strtolower($code)] = true;
        if ($name !== '') $seenNames[mb_strtolower($name)] = true;
        $matches = $name !== '' ? crm_customer_duplicate_matches($row) : [];
        if ($matches) $warnings[] = '疑似重复客户：' . implode('、', array_map(fn($m) => $m['customer_name'] . ' ' . $m['similarity'] . '%', array_slice($matches, 0, 3)));
        $row['duplicate_matches'] = $matches;
        $row['warnings'] = $warnings;
        $row['errors'] = $errors;
        $row['missing_fields'] = $missing;
        $row['issue_level'] = $errors ? 'error' : ($warnings ? 'warning' : 'ok');
        $row['problem_summary'] = $errors ? implode('；', $errors) : ($warnings ? implode('；', $warnings) : '可导入');
        $row['recognized_fields'] = array_values(array_filter([
            $code !== '' ? '客户代码' : '',
            $name !== '' ? '客户名称' : '',
            $country !== '' ? '国家' : '',
            $email !== '' ? '邮箱' : '',
            $website !== '' ? '网站' : '',
            $contacts ? ('联系人' . count($contacts) . '个') : '',
        ]));
        $row['valid'] = !$errors;
        $preview[] = $row;
    }
    crm_customer_log('customer_import_preview', 'customer', 'import', null, null, ['total' => count($preview), 'valid' => count(array_filter($preview, fn($r) => $r['valid']))], '预览客户导入');
    $invalid = count(array_filter($preview, fn($r) => !$r['valid']));
    $warningsCount = count(array_filter($preview, fn($r) => $r['valid'] && !empty($r['warnings'])));
    $tips = [];
    if (!empty($mapping['unknown'])) $tips[] = '有 ' . count($mapping['unknown']) . ' 个表头未识别，可改成系统字段名或导出模板后回填。';
    if (!empty($mapping['missing_required'])) $tips[] = '缺少必填列：' . implode('、', array_map(fn($r) => $r['label'], $mapping['missing_required']));
    if ($parseError !== '') $tips[] = '文件解析问题：' . $parseError;
    if (!$preview && $parseError === '') $tips[] = '请查看原始预览：如果表头不在前 10 行，或客户名称/国家被写成图片、合并标题、公式未保存值，系统会无法自动导入。';
    if ($invalid) $tips[] = '有 ' . $invalid . ' 行不能导入，请查看行级原因。';
    if ($warningsCount) $tips[] = '有 ' . $warningsCount . ' 行存在提醒，但可导入。';
    return [
        'rows' => $preview,
        'total' => count($preview),
        'valid' => count(array_filter($preview, fn($r) => $r['valid'])),
        'invalid' => $invalid,
        'warnings' => $warningsCount,
        'mapping' => $mapping,
        'raw_preview' => $rawPreview,
        'tips' => $tips,
        'parse_error' => $parseError,
        'can_commit' => $parseError === '' && count(array_filter($preview, fn($r) => $r['valid'])) > 0,
    ];
}

function crm_customer_import_commit(array $rows): array
{
    crm_require('customer.import');
    $success = 0;
    $failed = 0;
    $results = [];
    foreach ($rows as $row) {
        if (empty($row['valid'])) {
            $failed++;
            $results[] = ['row_no' => $row['row_no'] ?? '', 'customer_name' => $row['customer_name'] ?? '', 'success' => false, 'message' => implode('；', (array)($row['errors'] ?? ['预览未通过']))];
            continue;
        }
        try {
            $payload = $row;
            $payload['entry_mode'] = 'direct';
            $payload['source_tags'] = crm_import_dictionary_values('customer_source', (string)($row['source_tags'] ?? ''));
            $payload['promotion_channels'] = crm_import_dictionary_values('promotion_channel', (string)($row['promotion_channels'] ?? ''));
            $created = crm_customer_create_confirmed($payload);
            $success++;
            $results[] = ['row_no' => $row['row_no'] ?? '', 'customer_name' => $payload['customer_name'] ?? '', 'success' => true, 'message' => '导入成功', 'customer_id' => $created['customer']['id'] ?? null];
        } catch (Throwable $e) {
            $failed++;
            $results[] = ['row_no' => $row['row_no'] ?? '', 'customer_name' => $row['customer_name'] ?? '', 'success' => false, 'message' => $e->getMessage()];
        }
    }
    crm_customer_log('customer_import_commit', 'customer', 'import', null, null, ['success' => $success, 'failed' => $failed, 'results' => $results], "客户导入完成：成功 {$success}，失败 {$failed}");
    return ['success_count' => $success, 'failed_count' => $failed, 'results' => $results];
}

function crm_customer_export(array $input): void
{
    crm_require('customer.export');
    $filters = $input;
    $filters['page_size'] = 200;
    $filters['page'] = 1;
    $all = [];
    do {
        $page = crm_customer_list($filters);
        $all = array_merge($all, $page['rows'] ?? []);
        $filters['page']++;
    } while (count($all) < (int)($page['total'] ?? 0) && $filters['page'] <= 1000);
    crm_customer_log('customer_export', 'customer', 'export', null, null, ['count' => count($all), 'filters' => $input], '导出客户 Excel');
    $filename = 'customers_' . date('Ymd_His') . '.xls';
    $contactAt = fn($d, $i) => $d['contacts'][$i] ?? [];
    $contactSources = fn($c) => implode(',', $c['source_tags'] ?? []);
    $contactRoles = fn($c) => implode(',', $c['role_tags'] ?? []);
    $contactChannels = function ($c) {
        return implode(',', array_map(fn($p) => $p['channel'] ?? '', array_filter($c['promotions'] ?? [], fn($p) => ($p['status'] ?? '') === 'active')));
    };
    $contactDetailJson = function ($d) use ($contactSources, $contactRoles, $contactChannels) {
        $rows = [];
        foreach (($d['contacts'] ?? []) as $c) {
            $rows[] = [
                'name' => $c['name'] ?? '',
                'name_en' => $c['name_en'] ?? '',
                'position' => $c['position'] ?? '',
                'department' => $c['department'] ?? '',
                'email' => $c['email'] ?? '',
                'phone' => $c['phone'] ?? '',
                'whatsapp' => $c['whatsapp'] ?? '',
                'wechat' => $c['wechat'] ?? '',
                'linkedin' => $c['linkedin'] ?? '',
                'gender' => $c['gender'] ?? '',
                'birthday' => $c['birthday'] ?? '',
                'language' => $c['language'] ?? '',
                'contact_sources' => $contactSources($c),
                'role_tags' => $contactRoles($c),
                'promotion_channels' => $contactChannels($c),
                'is_primary' => !empty($c['is_primary']) ? '是' : '',
                'is_left' => !empty($c['is_left']) ? '是' : '',
                'remark' => $c['remark'] ?? '',
            ];
        }
        return json_encode($rows, JSON_UNESCAPED_UNICODE);
    };
    $columns = [
        'ID' => fn($r, $d) => $r['id'] ?? '',
        '客户代码' => fn($r, $d) => $r['customer_code'] ?? '',
        '客户名称' => fn($r, $d) => $r['customer_name'] ?? '',
        '英文名称' => fn($r, $d) => $r['customer_name_en'] ?? '',
        '国家' => fn($r, $d) => $r['country'] ?? '',
        '城市' => fn($r, $d) => $r['city'] ?? '',
        '地址' => fn($r, $d) => $r['address'] ?? '',
        '网站' => fn($r, $d) => $r['website'] ?? '',
        '邮箱' => fn($r, $d) => $r['email'] ?? '',
        '电话' => fn($r, $d) => $r['phone'] ?? '',
        'WhatsApp' => fn($r, $d) => $r['whatsapp'] ?? '',
        '客户来源' => fn($r, $d) => $r['source'] ?? '',
        '来源标签' => fn($r, $d) => implode(',', $d['source_tags'] ?? []),
        '客户等级' => fn($r, $d) => $r['level'] ?? '',
        '客户状态' => fn($r, $d) => $r['status'] ?? '',
        '生命周期' => fn($r, $d) => $r['lifecycle_key'] ?? '',
        '风险等级' => fn($r, $d) => $r['risk_level'] ?? '',
        '推广状态' => fn($r, $d) => $d['promotion_status'] ?? '',
        '推广方式' => fn($r, $d) => implode(',', $d['promotion_channels'] ?? []),
        '禁止联系' => fn($r, $d) => !empty($r['do_not_contact']) ? '是' : '',
        '黑名单原因' => fn($r, $d) => $r['blacklist_reason'] ?? '',
        '负责人ID' => fn($r, $d) => $r['owner_user_id'] ?? '',
        '负责人' => fn($r, $d) => $r['owner_name'] ?? '',
        '负责人部门' => fn($r, $d) => $r['owner_department'] ?? '',
        '多负责人' => fn($r, $d) => implode('; ', array_map(fn($o) => ($o['username'] ?? ('#' . ($o['user_id'] ?? ''))) . '/' . ($o['role_type'] ?? '') . (!empty($o['is_primary']) ? '/primary' : ''), $d['owners'] ?? [])),
        '分组' => fn($r, $d) => implode(',', array_map(fn($g) => $g['group_name'] ?? '', $d['groups'] ?? [])),
        '主地址类型' => fn($r, $d) => $d['addresses'][0]['address_type'] ?? '',
        '主地址国家' => fn($r, $d) => $d['addresses'][0]['country'] ?? '',
        '主地址城市' => fn($r, $d) => $d['addresses'][0]['city'] ?? '',
        '主地址详情' => fn($r, $d) => $d['addresses'][0]['address'] ?? '',
        '所有地址' => fn($r, $d) => implode('; ', array_map(fn($a) => ($a['address_type'] ?? '') . '|' . ($a['country'] ?? '') . '|' . ($a['city'] ?? '') . '|' . ($a['address'] ?? '') . (!empty($a['is_primary']) ? '|主' : ''), $d['addresses'] ?? [])),
        '联系人数量' => fn($r, $d) => count($d['contacts'] ?? []),
        '联系人明细JSON' => fn($r, $d) => $contactDetailJson($d),
        '主联系人' => fn($r, $d) => implode(',', array_map(fn($c) => !empty($c['is_primary']) ? ($c['name'] ?? '') : '', $d['contacts'] ?? [])),
        '主联系人邮箱' => fn($r, $d) => implode(',', array_map(fn($c) => !empty($c['is_primary']) ? ($c['email'] ?? '') : '', $d['contacts'] ?? [])),
        '主联系人电话' => fn($r, $d) => implode(',', array_map(fn($c) => !empty($c['is_primary']) ? ($c['phone'] ?? '') : '', $d['contacts'] ?? [])),
        '主联系人WhatsApp' => fn($r, $d) => implode(',', array_map(fn($c) => !empty($c['is_primary']) ? ($c['whatsapp'] ?? '') : '', $d['contacts'] ?? [])),
        '所有联系人' => fn($r, $d) => implode('; ', array_map(fn($c) => ($c['name'] ?? '') . '|' . ($c['position'] ?? '') . '|' . ($c['email'] ?? '') . '|' . ($c['phone'] ?? '') . '|' . ($c['whatsapp'] ?? ''), $d['contacts'] ?? [])),
        '联系人1姓名' => fn($r, $d) => $contactAt($d, 0)['name'] ?? '',
        '联系人1英文名' => fn($r, $d) => $contactAt($d, 0)['name_en'] ?? '',
        '联系人1职位' => fn($r, $d) => $contactAt($d, 0)['position'] ?? '',
        '联系人1部门' => fn($r, $d) => $contactAt($d, 0)['department'] ?? '',
        '联系人1邮箱' => fn($r, $d) => $contactAt($d, 0)['email'] ?? '',
        '联系人1电话' => fn($r, $d) => $contactAt($d, 0)['phone'] ?? '',
        '联系人1WhatsApp' => fn($r, $d) => $contactAt($d, 0)['whatsapp'] ?? '',
        '联系人1微信' => fn($r, $d) => $contactAt($d, 0)['wechat'] ?? '',
        '联系人1LinkedIn' => fn($r, $d) => $contactAt($d, 0)['linkedin'] ?? '',
        '联系人1性别' => fn($r, $d) => $contactAt($d, 0)['gender'] ?? '',
        '联系人1生日' => fn($r, $d) => $contactAt($d, 0)['birthday'] ?? '',
        '联系人1语言' => fn($r, $d) => $contactAt($d, 0)['language'] ?? '',
        '联系人1来源' => fn($r, $d) => $contactSources($contactAt($d, 0)),
        '联系人1角色标签' => fn($r, $d) => $contactRoles($contactAt($d, 0)),
        '联系人1推广方式' => fn($r, $d) => $contactChannels($contactAt($d, 0)),
        '联系人1主联系人' => fn($r, $d) => !empty($contactAt($d, 0)['is_primary']) ? '是' : '',
        '联系人1已离职' => fn($r, $d) => !empty($contactAt($d, 0)['is_left']) ? '是' : '',
        '联系人1备注' => fn($r, $d) => $contactAt($d, 0)['remark'] ?? '',
        '联系人2姓名' => fn($r, $d) => $contactAt($d, 1)['name'] ?? '',
        '联系人2英文名' => fn($r, $d) => $contactAt($d, 1)['name_en'] ?? '',
        '联系人2职位' => fn($r, $d) => $contactAt($d, 1)['position'] ?? '',
        '联系人2部门' => fn($r, $d) => $contactAt($d, 1)['department'] ?? '',
        '联系人2邮箱' => fn($r, $d) => $contactAt($d, 1)['email'] ?? '',
        '联系人2电话' => fn($r, $d) => $contactAt($d, 1)['phone'] ?? '',
        '联系人2WhatsApp' => fn($r, $d) => $contactAt($d, 1)['whatsapp'] ?? '',
        '联系人2微信' => fn($r, $d) => $contactAt($d, 1)['wechat'] ?? '',
        '联系人2LinkedIn' => fn($r, $d) => $contactAt($d, 1)['linkedin'] ?? '',
        '联系人2性别' => fn($r, $d) => $contactAt($d, 1)['gender'] ?? '',
        '联系人2生日' => fn($r, $d) => $contactAt($d, 1)['birthday'] ?? '',
        '联系人2语言' => fn($r, $d) => $contactAt($d, 1)['language'] ?? '',
        '联系人2来源' => fn($r, $d) => $contactSources($contactAt($d, 1)),
        '联系人2角色标签' => fn($r, $d) => $contactRoles($contactAt($d, 1)),
        '联系人2推广方式' => fn($r, $d) => $contactChannels($contactAt($d, 1)),
        '联系人2主联系人' => fn($r, $d) => !empty($contactAt($d, 1)['is_primary']) ? '是' : '',
        '联系人2已离职' => fn($r, $d) => !empty($contactAt($d, 1)['is_left']) ? '是' : '',
        '联系人2备注' => fn($r, $d) => $contactAt($d, 1)['remark'] ?? '',
        '联系人3姓名' => fn($r, $d) => $contactAt($d, 2)['name'] ?? '',
        '联系人3英文名' => fn($r, $d) => $contactAt($d, 2)['name_en'] ?? '',
        '联系人3职位' => fn($r, $d) => $contactAt($d, 2)['position'] ?? '',
        '联系人3部门' => fn($r, $d) => $contactAt($d, 2)['department'] ?? '',
        '联系人3邮箱' => fn($r, $d) => $contactAt($d, 2)['email'] ?? '',
        '联系人3电话' => fn($r, $d) => $contactAt($d, 2)['phone'] ?? '',
        '联系人3WhatsApp' => fn($r, $d) => $contactAt($d, 2)['whatsapp'] ?? '',
        '联系人3微信' => fn($r, $d) => $contactAt($d, 2)['wechat'] ?? '',
        '联系人3LinkedIn' => fn($r, $d) => $contactAt($d, 2)['linkedin'] ?? '',
        '联系人3性别' => fn($r, $d) => $contactAt($d, 2)['gender'] ?? '',
        '联系人3生日' => fn($r, $d) => $contactAt($d, 2)['birthday'] ?? '',
        '联系人3语言' => fn($r, $d) => $contactAt($d, 2)['language'] ?? '',
        '联系人3来源' => fn($r, $d) => $contactSources($contactAt($d, 2)),
        '联系人3角色标签' => fn($r, $d) => $contactRoles($contactAt($d, 2)),
        '联系人3推广方式' => fn($r, $d) => $contactChannels($contactAt($d, 2)),
        '联系人3主联系人' => fn($r, $d) => !empty($contactAt($d, 2)['is_primary']) ? '是' : '',
        '联系人3已离职' => fn($r, $d) => !empty($contactAt($d, 2)['is_left']) ? '是' : '',
        '联系人3备注' => fn($r, $d) => $contactAt($d, 2)['remark'] ?? '',
        '联系人4姓名' => fn($r, $d) => $contactAt($d, 3)['name'] ?? '',
        '联系人4英文名' => fn($r, $d) => $contactAt($d, 3)['name_en'] ?? '',
        '联系人4职位' => fn($r, $d) => $contactAt($d, 3)['position'] ?? '',
        '联系人4部门' => fn($r, $d) => $contactAt($d, 3)['department'] ?? '',
        '联系人4邮箱' => fn($r, $d) => $contactAt($d, 3)['email'] ?? '',
        '联系人4电话' => fn($r, $d) => $contactAt($d, 3)['phone'] ?? '',
        '联系人4WhatsApp' => fn($r, $d) => $contactAt($d, 3)['whatsapp'] ?? '',
        '联系人4微信' => fn($r, $d) => $contactAt($d, 3)['wechat'] ?? '',
        '联系人4LinkedIn' => fn($r, $d) => $contactAt($d, 3)['linkedin'] ?? '',
        '联系人4性别' => fn($r, $d) => $contactAt($d, 3)['gender'] ?? '',
        '联系人4生日' => fn($r, $d) => $contactAt($d, 3)['birthday'] ?? '',
        '联系人4语言' => fn($r, $d) => $contactAt($d, 3)['language'] ?? '',
        '联系人4来源' => fn($r, $d) => $contactSources($contactAt($d, 3)),
        '联系人4角色标签' => fn($r, $d) => $contactRoles($contactAt($d, 3)),
        '联系人4推广方式' => fn($r, $d) => $contactChannels($contactAt($d, 3)),
        '联系人4主联系人' => fn($r, $d) => !empty($contactAt($d, 3)['is_primary']) ? '是' : '',
        '联系人4已离职' => fn($r, $d) => !empty($contactAt($d, 3)['is_left']) ? '是' : '',
        '联系人4备注' => fn($r, $d) => $contactAt($d, 3)['remark'] ?? '',
        '联系人5姓名' => fn($r, $d) => $contactAt($d, 4)['name'] ?? '',
        '联系人5英文名' => fn($r, $d) => $contactAt($d, 4)['name_en'] ?? '',
        '联系人5职位' => fn($r, $d) => $contactAt($d, 4)['position'] ?? '',
        '联系人5部门' => fn($r, $d) => $contactAt($d, 4)['department'] ?? '',
        '联系人5邮箱' => fn($r, $d) => $contactAt($d, 4)['email'] ?? '',
        '联系人5电话' => fn($r, $d) => $contactAt($d, 4)['phone'] ?? '',
        '联系人5WhatsApp' => fn($r, $d) => $contactAt($d, 4)['whatsapp'] ?? '',
        '联系人5微信' => fn($r, $d) => $contactAt($d, 4)['wechat'] ?? '',
        '联系人5LinkedIn' => fn($r, $d) => $contactAt($d, 4)['linkedin'] ?? '',
        '联系人5性别' => fn($r, $d) => $contactAt($d, 4)['gender'] ?? '',
        '联系人5生日' => fn($r, $d) => $contactAt($d, 4)['birthday'] ?? '',
        '联系人5语言' => fn($r, $d) => $contactAt($d, 4)['language'] ?? '',
        '联系人5来源' => fn($r, $d) => $contactSources($contactAt($d, 4)),
        '联系人5角色标签' => fn($r, $d) => $contactRoles($contactAt($d, 4)),
        '联系人5推广方式' => fn($r, $d) => $contactChannels($contactAt($d, 4)),
        '联系人5主联系人' => fn($r, $d) => !empty($contactAt($d, 4)['is_primary']) ? '是' : '',
        '联系人5已离职' => fn($r, $d) => !empty($contactAt($d, 4)['is_left']) ? '是' : '',
        '联系人5备注' => fn($r, $d) => $contactAt($d, 4)['remark'] ?? '',
        '最近跟进' => fn($r, $d) => $r['last_followup_at'] ?? '',
        '资料完整度' => fn($r, $d) => $d['scores']['completeness_score'] ?? '',
        '健康度' => fn($r, $d) => $d['scores']['health_score'] ?? '',
        '活跃度' => fn($r, $d) => $d['scores']['activity_score'] ?? '',
        '成交可能性' => fn($r, $d) => $d['scores']['deal_probability'] ?? '',
        '跟进风险' => fn($r, $d) => $d['scores']['followup_risk'] ?? '',
        '保护状态' => fn($r, $d) => $d['protection']['protected'] ?? '',
        '保护原因' => fn($r, $d) => $d['protection']['reason'] ?? '',
        '创建人ID' => fn($r, $d) => $r['created_by'] ?? '',
        '更新人ID' => fn($r, $d) => $r['updated_by'] ?? '',
        '创建时间' => fn($r, $d) => $r['created_at'] ?? '',
        '更新时间' => fn($r, $d) => $r['updated_at'] ?? '',
        '删除时间' => fn($r, $d) => $r['deleted_at'] ?? '',
        '删除人ID' => fn($r, $d) => $r['deleted_by'] ?? '',
        '删除原因' => fn($r, $d) => $r['delete_reason'] ?? '',
        '备注' => fn($r, $d) => $r['remark'] ?? '',
    ];
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
    echo "\xEF\xBB\xBF";
    echo '<table border="1"><tr>';
    foreach (array_keys($columns) as $label) echo '<th>' . h($label) . '</th>';
    echo '</tr>';
    foreach ($all as $row) {
        $detail = crm_customer_get((int)$row['id']);
        $customer = array_merge($row, $detail['customer'] ?? []);
        echo '<tr>';
        foreach ($columns as $getter) echo '<td>' . h($getter($customer, $detail)) . '</td>';
        echo '</tr>';
    }
    echo '</table>';
}

function crm_customer_groups(): array
{
    crm_customer_ensure_tables();
    return db()->query('SELECT g.*, (SELECT COUNT(*) FROM crm_customer_group_relations r WHERE r.group_id = g.id) AS customer_count FROM crm_customer_groups g WHERE g.deleted_at IS NULL ORDER BY g.sort_order, g.id')->fetchAll();
}

function crm_customer_groups_for(int $customerId): array
{
    $stmt = db()->prepare('SELECT g.* FROM crm_customer_group_relations r JOIN crm_customer_groups g ON g.id = r.group_id WHERE r.customer_id = ? AND g.deleted_at IS NULL ORDER BY g.sort_order');
    $stmt->execute([$customerId]);
    return $stmt->fetchAll();
}

function crm_customer_set_groups(int $customerId, array $groupIds): void
{
    db()->prepare('DELETE FROM crm_customer_group_relations WHERE customer_id = ?')->execute([$customerId]);
    $insert = db()->prepare('INSERT IGNORE INTO crm_customer_group_relations (customer_id, group_id, created_by, created_at) VALUES (?, ?, ?, NOW())');
    foreach (array_unique($groupIds) as $groupId) $insert->execute([$customerId, $groupId, current_user()['id'] ?? null]);
}

function crm_group_create(array $input): array
{
    crm_require('customer.batch');
    $name = trim((string)($input['group_name'] ?? ''));
    if ($name === '') throw new RuntimeException('分组名称不能为空。');
    try {
        db()->prepare('INSERT INTO crm_customer_groups (group_name, group_color, sort_order, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())')
            ->execute([$name, trim((string)($input['group_color'] ?? '#2563eb')), (int)($input['sort_order'] ?? 100), current_user()['id'] ?? null]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') throw new RuntimeException('分组名称不能重复。');
        throw $e;
    }
    crm_customer_log('group_create', 'group', db()->lastInsertId(), null, null, $input, '新建客户分组');
    return ['groups' => crm_customer_groups()];
}

function crm_group_update(array $input): array
{
    crm_require('customer.batch');
    $groupId = (int)($input['group_id'] ?? 0);
    $name = trim((string)($input['group_name'] ?? ''));
    if (!$groupId || $name === '') throw new RuntimeException('请选择分组并填写名称。');
    $before = db()->prepare('SELECT * FROM crm_customer_groups WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $before->execute([$groupId]);
    $old = $before->fetch();
    if (!$old) throw new RuntimeException('分组不存在。');
    try {
        db()->prepare('UPDATE crm_customer_groups SET group_name = ?, group_color = ?, sort_order = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$name, trim((string)($input['group_color'] ?? '#2563eb')), (int)($input['sort_order'] ?? 100), $groupId]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') throw new RuntimeException('分组名称不能重复。');
        throw $e;
    }
    crm_customer_log('group_update', 'group', $groupId, null, $old, $input, '更新客户分组');
    return ['groups' => crm_customer_groups()];
}

function crm_group_delete(int $groupId): array
{
    crm_require('customer.batch');
    if (!$groupId) throw new RuntimeException('请选择分组。');
    $stmt = db()->prepare('SELECT * FROM crm_customer_groups WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([$groupId]);
    $old = $stmt->fetch();
    if (!$old) throw new RuntimeException('分组不存在。');
    db()->prepare('UPDATE crm_customer_groups SET deleted_at = NOW(), updated_at = NOW() WHERE id = ?')->execute([$groupId]);
    crm_customer_log('group_delete', 'group', $groupId, null, $old, ['deleted' => true], '删除客户分组');
    return ['groups' => crm_customer_groups()];
}

function crm_group_move_customers(array $customerIds, int $groupId): void
{
    crm_require('customer.batch');
    if (!$customerIds || !$groupId) throw new RuntimeException('请选择客户和目标分组。');
    $before = [];
    foreach ($customerIds as $id) $before[$id] = crm_customer_groups_for((int)$id);
    foreach ($customerIds as $id) crm_customer_set_groups((int)$id, [$groupId]);
    crm_customer_log('group_move_customers', 'group', $groupId, null, $before, ['customer_ids' => $customerIds, 'group_id' => $groupId], '移动客户分组');
    foreach ($customerIds as $id) crm_customer_timeline_add((int)$id, 'group_move', '移动客户分组', '客户分组已变更', 'group', (string)$groupId);
}

function crm_batch_assign(array $customerIds, int $ownerUserId, array $ownerUserIds = []): void
{
    crm_require('customer.assign');
    if (!$customerIds || !$ownerUserId) throw new RuntimeException('请选择客户和负责人。');
    $customerIds = array_values(array_filter(array_unique(array_map('intval', $customerIds))));
    $ownerUserIds = array_values(array_filter(array_unique(array_map('intval', array_merge([$ownerUserId], $ownerUserIds)))));
    if (!$customerIds) throw new RuntimeException('请选择客户。');

    $userPlaceholders = implode(',', array_fill(0, count($ownerUserIds), '?'));
    $stmt = db()->prepare("SELECT u.*, d.name AS department_name FROM crm_users u LEFT JOIN crm_departments d ON d.id = u.department_id WHERE u.status = 'active' AND u.id IN ({$userPlaceholders})");
    $stmt->execute($ownerUserIds);
    $users = [];
    foreach ($stmt->fetchAll() as $row) {
        $users[(int)$row['id']] = $row;
    }
    $owner = $users[$ownerUserId] ?? null;
    if (!$owner) throw new RuntimeException('负责人不存在。');
    $validOwnerIds = array_values(array_filter($ownerUserIds, fn($userId) => isset($users[$userId])));
    if (!in_array($ownerUserId, $validOwnerIds, true)) array_unshift($validOwnerIds, $ownerUserId);

    $placeholders = implode(',', array_fill(0, count($customerIds), '?'));
    db()->prepare("UPDATE crm_customers SET owner_user_id = ?, owner_department = ?, updated_by = ?, updated_at = NOW() WHERE id IN ({$placeholders})")
        ->execute(array_merge([$ownerUserId, $owner['department_name'] ?? '', current_user()['id']], $customerIds));
    $insert = db()->prepare('INSERT INTO crm_customer_owners (customer_id, user_id, role_type, is_primary, created_by, created_at) VALUES (?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE role_type = VALUES(role_type), is_primary = VALUES(is_primary)');
    foreach ($customerIds as $customerId) {
        db()->prepare('DELETE FROM crm_customer_owners WHERE customer_id = ?')->execute([(int)$customerId]);
        foreach ($validOwnerIds as $assignedUserId) {
            $isPrimary = (int)$assignedUserId === $ownerUserId;
            $insert->execute([(int)$customerId, $assignedUserId, $isPrimary ? 'primary' : 'secondary', $isPrimary ? 1 : 0, current_user()['id'] ?? null]);
        }
        crm_customer_timeline_add((int)$customerId, 'owner_change', '修改第一负责人', '客户负责人已转移', 'user', (string)$ownerUserId);
        crm_sensitive_audit('customer_primary_owner_change', 'customer', (int)$customerId, null, ['owner_user_id' => $ownerUserId, 'owner_user_ids' => $validOwnerIds]);
    }
    crm_customer_log('customer_batch_assign', 'customer', implode(',', $customerIds), null, null, ['owner_user_id' => $ownerUserId, 'owner_user_ids' => $validOwnerIds, 'customer_ids' => $customerIds], '批量分配客户');
}

function crm_customer_assign_options(): array
{
    crm_require('customer.assign');
    $users = db()->query("SELECT u.id, u.username, u.real_name, u.english_name, u.email, r.role_name, d.name AS department_name
        FROM crm_users u
        LEFT JOIN crm_roles r ON r.id = u.role_id
        LEFT JOIN crm_departments d ON d.id = u.department_id
        WHERE u.status = 'active'
        ORDER BY d.sort_order, d.id, u.real_name, u.username")->fetchAll();
    return ['users' => $users];
}

function crm_customer_transfer_public(array $customerIds, string $reason = ''): void
{
    crm_require('customer.transfer_public');
    $customerIds = array_values(array_filter(array_unique(array_map('intval', $customerIds))));
    if (!$customerIds) throw new RuntimeException('请选择要转入公海的客户。');
    $reason = trim($reason) ?: '手动转入公海';
    foreach ($customerIds as $customerId) {
        $before = crm_customer_get($customerId)['customer'];
        db()->prepare('UPDATE crm_customers SET owner_user_id = NULL, owner_department = "", updated_by = ?, updated_at = NOW() WHERE id = ?')
            ->execute([current_user()['id'] ?? null, $customerId]);
        db()->prepare('DELETE FROM crm_customer_owners WHERE customer_id = ? AND is_primary = 1')->execute([$customerId]);
        db()->prepare('INSERT INTO crm_customer_protection (customer_id, protection_status, reason, public_pool_at, updated_by, updated_at) VALUES (?, "public_pool", ?, NOW(), ?, NOW()) ON DUPLICATE KEY UPDATE protection_status = "public_pool", reason = VALUES(reason), public_pool_at = NOW(), updated_by = VALUES(updated_by), updated_at = NOW()')
            ->execute([$customerId, $reason, current_user()['id'] ?? null]);
        crm_customer_log('customer_public_pool', 'customer', $customerId, $customerId, $before, ['public_pool' => true, 'reason' => $reason], '转入公海');
        crm_customer_timeline_add($customerId, 'public_pool', '转入公海', $reason, 'customer', (string)$customerId);
        crm_sensitive_audit('customer_public_pool', 'customer', $customerId, $before, ['public_pool' => true, 'reason' => $reason]);
    }
}

function crm_customer_claim_public(int $customerId): array
{
    crm_require('customer.claim_public');
    if (!$customerId) throw new RuntimeException('请选择公海客户。');
    $before = crm_customer_get($customerId)['customer'];
    if (!empty($before['owner_user_id'])) throw new RuntimeException('该客户已有负责人，不能从公海领取。');
    $user = current_user();
    $ownerDepartment = (string)($user['department_name'] ?? '');
    db()->prepare('UPDATE crm_customers SET owner_user_id = ?, owner_department = ?, updated_by = ?, updated_at = NOW() WHERE id = ?')
        ->execute([(int)$user['id'], $ownerDepartment, (int)$user['id'], $customerId]);
    db()->prepare('DELETE FROM crm_customer_owners WHERE customer_id = ?')->execute([$customerId]);
    db()->prepare('INSERT INTO crm_customer_owners (customer_id, user_id, role_type, is_primary, created_by, created_at) VALUES (?, ?, "primary", 1, ?, NOW()) ON DUPLICATE KEY UPDATE role_type = "primary", is_primary = 1')
        ->execute([$customerId, (int)$user['id'], (int)$user['id']]);
    db()->prepare('INSERT INTO crm_customer_protection (customer_id, protection_status, reason, updated_by, updated_at) VALUES (?, "normal", "公海领取", ?, NOW()) ON DUPLICATE KEY UPDATE protection_status = "normal", reason = VALUES(reason), updated_by = VALUES(updated_by), updated_at = NOW()')
        ->execute([$customerId, (int)$user['id']]);
    crm_customer_log('customer_claim_public', 'customer', $customerId, $customerId, $before, ['owner_user_id' => (int)$user['id']], '领取公海客户');
    crm_customer_timeline_add($customerId, 'claim_public', '领取公海客户', '客户从公海池领取', 'user', (string)$user['id']);
    crm_sensitive_audit('customer_claim_public', 'customer', $customerId, $before, ['owner_user_id' => (int)$user['id']]);
    return crm_customer_get($customerId);
}

function crm_contact_validate(array $input, int $customerId, int $ignoreId = 0): array
{
    $name = trim((string)($input['name'] ?? ''));
    if ($name === '') throw new RuntimeException('联系人姓名不能为空。');
    $email = trim((string)($input['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('联系人邮箱格式不正确。');
    foreach (['email' => $email, 'phone' => trim((string)($input['phone'] ?? ''))] as $field => $value) {
        if ($value === '') continue;
        $params = [$customerId, $value];
        $extra = $ignoreId ? ' AND id <> ?' : '';
        if ($ignoreId) $params[] = $ignoreId;
        $stmt = db()->prepare("SELECT id FROM crm_contacts WHERE customer_id = ? AND {$field} = ? AND deleted_at IS NULL {$extra} LIMIT 1");
        $stmt->execute($params);
        if ($stmt->fetchColumn()) throw new RuntimeException($field === 'email' ? '同一客户内联系人邮箱不能重复。' : '同一客户内联系人电话不能重复。');
    }
    $options = crm_graph_options();
    $roleTags = crm_parse_keys($input['role_tags'] ?? [], $options['contact_role_tags']);
    $sourceTags = crm_parse_keys($input['contact_sources'] ?? ($input['source_tags'] ?? 'manual'), $options['contact_sources']);
    if (!$sourceTags) $sourceTags = ['manual'];
    $promotions = [];
    if (array_key_exists('contact_promotion_channels', $input) || array_key_exists('promotion_channels', $input)) {
        $channels = crm_parse_keys($input['contact_promotion_channels'] ?? ($input['promotion_channels'] ?? []), $options['promotion_channels']);
        foreach ($channels as $channel) $promotions[] = ['channel' => $channel, 'status' => 'active', 'last_contact_time' => null];
    } else {
        foreach ($options['promotion_channels'] as $channel) {
            $status = trim((string)($input['promotion_' . $channel] ?? 'no_contact'));
            if (!in_array($status, $options['contact_promotion_statuses'], true)) $status = 'no_contact';
            if ($status !== 'no_contact') $promotions[] = ['channel' => $channel, 'status' => $status, 'last_contact_time' => null];
        }
    }
    return [
        'name' => $name, 'name_en' => trim((string)($input['name_en'] ?? '')), 'position' => trim((string)($input['position'] ?? '')), 'department' => trim((string)($input['department'] ?? '')),
        'email' => $email, 'phone' => trim((string)($input['phone'] ?? '')), 'whatsapp' => trim((string)($input['whatsapp'] ?? '')), 'wechat' => trim((string)($input['wechat'] ?? '')),
        'linkedin' => trim((string)($input['linkedin'] ?? '')), 'gender' => trim((string)($input['gender'] ?? '')), 'birthday' => ($input['birthday'] ?? '') ?: null,
        'language' => trim((string)($input['language'] ?? '')), 'is_primary' => !empty($input['is_primary']) ? 1 : 0, 'is_left' => !empty($input['is_left']) ? 1 : 0, 'remark' => trim((string)($input['remark'] ?? '')),
        'graph' => ['role_tags' => $roleTags, 'source_tags' => $sourceTags, 'promotions' => $promotions],
    ];
}

function crm_contact_key_tags(int $contactId, string $table, string $column): array
{
    $stmt = db()->prepare("SELECT {$column} FROM {$table} WHERE contact_id = ? ORDER BY id");
    $stmt->execute([$contactId]);
    return array_map(fn($row) => $row[$column], $stmt->fetchAll());
}

function crm_contact_promotions(int $contactId): array
{
    $stmt = db()->prepare('SELECT * FROM crm_contact_promotions WHERE contact_id = ? ORDER BY FIELD(channel, "email","whatsapp","linkedin")');
    $stmt->execute([$contactId]);
    return $stmt->fetchAll();
}

function crm_contact_sync_key_table(int $contactId, string $table, string $column, array $keys): void
{
    db()->prepare("DELETE FROM {$table} WHERE contact_id = ?")->execute([$contactId]);
    $insert = db()->prepare("INSERT IGNORE INTO {$table} (contact_id, {$column}, created_by, created_at) VALUES (?, ?, ?, NOW())");
    foreach (array_values(array_unique($keys)) as $key) $insert->execute([$contactId, $key, current_user()['id'] ?? null]);
}

function crm_contact_sync_promotions(int $contactId, array $promotions): void
{
    db()->prepare('DELETE FROM crm_contact_promotions WHERE contact_id = ?')->execute([$contactId]);
    $insert = db()->prepare('INSERT INTO crm_contact_promotions (contact_id, channel, status, last_contact_time, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, NOW())');
    foreach ($promotions as $promotion) $insert->execute([$contactId, $promotion['channel'], $promotion['status'], $promotion['last_contact_time'], current_user()['id'] ?? null]);
}

function crm_contact_apply_graph(int $contactId, array $graph): void
{
    crm_contact_sync_key_table($contactId, 'crm_contact_role_tags', 'role_key', $graph['role_tags']);
    crm_contact_sync_key_table($contactId, 'crm_contact_sources', 'source_key', $graph['source_tags']);
    crm_contact_sync_promotions($contactId, $graph['promotions']);
}

function crm_contact_list(array $input): array
{
    $customerId = (int)($input['customer_id'] ?? 0);
    if (!$customerId) return ['rows' => []];
    $stmt = db()->prepare('SELECT * FROM crm_contacts WHERE customer_id = ? AND deleted_at IS NULL ORDER BY is_primary DESC, id DESC');
    $stmt->execute([$customerId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['role_tags'] = crm_contact_key_tags((int)$row['id'], 'crm_contact_role_tags', 'role_key');
        $row['source_tags'] = crm_contact_key_tags((int)$row['id'], 'crm_contact_sources', 'source_key');
        $row['promotions'] = crm_contact_promotions((int)$row['id']);
        $row['promotion_channels'] = array_values(array_map(
            static fn($promotion) => (string)$promotion['channel'],
            array_filter($row['promotions'], static fn($promotion) => ($promotion['status'] ?? '') === 'active')
        ));
        $row['promote'] = $row['promotion_channels'] ? 1 : 0;
    }
    return ['rows' => $rows];
}

function crm_contact_create(array $input, bool $skipPermission = false): array
{
    if (!$skipPermission) crm_require('contact.create');
    $customerId = (int)($input['customer_id'] ?? 0);
    crm_customer_get($customerId);
    $data = crm_contact_validate($input, $customerId);
    if ($data['is_primary']) db()->prepare('UPDATE crm_contacts SET is_primary = 0 WHERE customer_id = ?')->execute([$customerId]);
    db()->prepare('INSERT INTO crm_contacts (customer_id, name, name_en, position, department, email, phone, whatsapp, wechat, linkedin, gender, birthday, language, is_primary, is_left, remark, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())')
        ->execute([$customerId, $data['name'], $data['name_en'], $data['position'], $data['department'], $data['email'], $data['phone'], $data['whatsapp'], $data['wechat'], $data['linkedin'], $data['gender'], $data['birthday'], $data['language'], $data['is_primary'], $data['is_left'], $data['remark'], current_user()['id'], current_user()['id']]);
    $id = (int)db()->lastInsertId();
    crm_contact_apply_graph($id, $data['graph']);
    crm_customer_log('contact_create', 'contact', $id, $customerId, null, $data, '新建联系人');
    crm_customer_timeline_add($customerId, 'contact_create', '添加联系人', $data['name'], 'contact', (string)$id);
    return crm_customer_get($customerId);
}

function crm_contact_update(int $id, array $input, bool $skipPermission = false): array
{
    if (!$skipPermission) crm_require('contact.edit');
    $stmt = db()->prepare('SELECT * FROM crm_contacts WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([$id]);
    $before = $stmt->fetch();
    if (!$before) throw new RuntimeException('联系人不存在。');
    crm_customer_get((int)$before['customer_id']);
    $data = crm_contact_validate($input, (int)$before['customer_id'], $id);
    if ($data['is_primary']) db()->prepare('UPDATE crm_contacts SET is_primary = 0 WHERE customer_id = ? AND id <> ?')->execute([$before['customer_id'], $id]);
    db()->prepare('UPDATE crm_contacts SET name=?, name_en=?, position=?, department=?, email=?, phone=?, whatsapp=?, wechat=?, linkedin=?, gender=?, birthday=?, language=?, is_primary=?, is_left=?, remark=?, updated_by=?, updated_at=NOW() WHERE id=?')
        ->execute([$data['name'], $data['name_en'], $data['position'], $data['department'], $data['email'], $data['phone'], $data['whatsapp'], $data['wechat'], $data['linkedin'], $data['gender'], $data['birthday'], $data['language'], $data['is_primary'], $data['is_left'], $data['remark'], current_user()['id'], $id]);
    crm_contact_apply_graph($id, $data['graph']);
    crm_customer_log('contact_update', 'contact', $id, (int)$before['customer_id'], $before, $data, '编辑联系人');
    crm_customer_timeline_add((int)$before['customer_id'], 'contact_update', '编辑联系人', $data['name'], 'contact', (string)$id);
    return crm_customer_get((int)$before['customer_id']);
}

function crm_contact_bulk_update_promotions(int $customerId, array $input): array
{
    crm_require('contact.edit');
    $customer = crm_customer_get($customerId);
    $options = crm_graph_options();
    $channels = crm_parse_keys($input['promotion_channels'] ?? ($input['contact_promotion_channels'] ?? []), $options['promotion_channels']);
    $stmt = db()->prepare('SELECT id, name FROM crm_contacts WHERE customer_id = ? AND deleted_at IS NULL ORDER BY is_primary DESC, id DESC');
    $stmt->execute([$customerId]);
    $contacts = $stmt->fetchAll();
    if (!$contacts) throw new RuntimeException('当前客户没有联系人，无法批量设置。');
    $promotions = array_map(fn($channel) => ['channel' => $channel, 'status' => 'active', 'last_contact_time' => null], $channels);
    $before = [];
    foreach ($contacts as $contact) {
        $contactId = (int)$contact['id'];
        $before[(string)$contactId] = crm_contact_promotions($contactId);
        crm_contact_sync_promotions($contactId, $promotions);
    }
    $after = [
        'contact_count' => count($contacts),
        'promotion_channels' => $channels,
        'contact_ids' => array_map(fn($row) => (int)$row['id'], $contacts),
    ];
    crm_customer_log('contact_promotion_batch_update', 'contact', 0, $customerId, $before, $after, '批量设置联系人推广方式');
    crm_customer_timeline_add($customerId, 'contact_promotion_batch_update', '批量设置联系人推广方式', '已应用到 ' . count($contacts) . ' 个联系人：' . implode('、', $channels), 'customer', (string)$customerId);
    return crm_customer_get($customerId);
}

function crm_contact_delete(int $id): void
{
    crm_require('contact.delete');
    $stmt = db()->prepare('SELECT * FROM crm_contacts WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([$id]);
    $before = $stmt->fetch();
    if (!$before) throw new RuntimeException('联系人不存在。');
    db()->prepare('UPDATE crm_contacts SET deleted_at = NOW(), deleted_by = ?, updated_at = NOW() WHERE id = ?')->execute([current_user()['id'], $id]);
    crm_customer_log('contact_delete', 'contact', $id, (int)$before['customer_id'], $before, ['deleted' => true], '删除联系人');
    crm_customer_timeline_add((int)$before['customer_id'], 'contact_delete', '删除联系人', (string)$before['name'], 'contact', (string)$id);
}

function crm_import_dictionary_values(string $typeKey, string $value): array
{
    $parts = preg_split('/[,，;；、\s]+/', (string)$value, -1, PREG_SPLIT_NO_EMPTY);
    if (!$parts) return [];
    $items = function_exists('crm_dictionary_items') ? crm_dictionary_items($typeKey, false) : [];
    $aliases = [];
    foreach ($items as $item) {
        foreach (['item_key','name_cn','name_en','short_name'] as $field) {
            $alias = mb_strtolower(trim((string)($item[$field] ?? '')));
            if ($alias !== '') $aliases[$alias] = $item['item_key'];
        }
    }
    $keys = [];
    foreach ($parts as $part) {
        $needle = mb_strtolower(trim((string)$part));
        if ($needle === '') continue;
        $keys[] = $aliases[$needle] ?? $needle;
    }
    return array_values(array_unique($keys));
}

function crm_followup_list(array $input): array
{
    $customerId = (int)($input['customer_id'] ?? 0);
    if (!$customerId) return ['rows' => []];
    $stmt = db()->prepare('SELECT f.*, u.username AS creator_name, ct.name AS contact_name FROM crm_customer_followups f LEFT JOIN crm_users u ON u.id = f.created_by LEFT JOIN crm_contacts ct ON ct.id = f.contact_id WHERE f.customer_id = ? AND f.deleted_at IS NULL ORDER BY f.followup_time DESC LIMIT 100');
    $stmt->execute([$customerId]);
    return ['rows' => $stmt->fetchAll()];
}

function crm_followup_create(array $input): array
{
    crm_require('follow.create');
    $customerId = (int)($input['customer_id'] ?? 0);
    crm_customer_get($customerId);
    $content = trim((string)($input['content'] ?? ''));
    if ($content === '') throw new RuntimeException('跟进内容不能为空。');
    $followupTime = function_exists('crm_task_datetime') ? crm_task_datetime($input['followup_time'] ?? '') : null;
    if (!$followupTime) $followupTime = date('Y-m-d H:i:s');
    $nextRemind = function_exists('crm_task_datetime') ? crm_task_datetime($input['next_remind_time'] ?? '') : null;
    $status = preg_replace('/[^a-z_]/i', '', (string)($input['status'] ?? 'open')) ?: 'open';
    db()->prepare('INSERT INTO crm_customer_followups (customer_id, contact_id, followup_time, followup_type, content, next_plan, next_remind_time, status, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())')
        ->execute([$customerId, (int)($input['contact_id'] ?? 0) ?: null, $followupTime, trim((string)($input['followup_type'] ?? '')) ?: 'other', $content, trim((string)($input['next_plan'] ?? '')), $nextRemind, $status, current_user()['id'], current_user()['id']]);
    $id = (int)db()->lastInsertId();
    crm_customer_log('followup_create', 'followup', $id, $customerId, null, $input, '新建跟进');
    crm_customer_timeline_add($customerId, 'followup_create', '新建跟进', $content, 'followup', (string)$id);
    if (function_exists('crm_task_upsert_from_followup')) {
        crm_task_upsert_from_followup([
            'id' => $id,
            'customer_id' => $customerId,
            'contact_id' => (int)($input['contact_id'] ?? 0) ?: null,
            'followup_time' => $followupTime,
            'followup_type' => trim((string)($input['followup_type'] ?? '')) ?: 'other',
            'content' => $content,
            'next_plan' => trim((string)($input['next_plan'] ?? '')),
            'next_remind_time' => $nextRemind,
            'status' => $status,
            'created_by' => current_user()['id'] ?? 0,
        ]);
    }
    $detail = [
        'customer' => crm_customer_basic_row($customerId),
        'followup' => crm_followup_get($id)['followup'],
    ];
    if (db_table_exists('crm_tasks')) {
        $taskStmt = db()->prepare("SELECT id, title, status, due_at, reminder_at FROM crm_tasks WHERE source_type = 'followup' AND source_id = ? AND task_type = 'customer_followup' AND deleted_at IS NULL LIMIT 1");
        $taskStmt->execute([(string)$id]);
        $detail['task'] = $taskStmt->fetch() ?: null;
    }
    return $detail;
}

function crm_followup_get(int $id): array
{
    if ($id <= 0) throw new RuntimeException('缺少跟进记录。');
    $stmt = db()->prepare('SELECT f.*, c.customer_name, ct.name AS contact_name FROM crm_customer_followups f LEFT JOIN crm_customers c ON c.id = f.customer_id LEFT JOIN crm_contacts ct ON ct.id = f.contact_id WHERE f.id = ? AND f.deleted_at IS NULL LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) throw new RuntimeException('跟进记录不存在或已删除。');
    crm_customer_get((int)$row['customer_id']);
    return ['followup' => $row];
}

function crm_followup_update(int $id, array $input): array
{
    crm_require('follow.edit');
    $current = crm_followup_get($id)['followup'];
    $customerId = (int)($input['customer_id'] ?? $current['customer_id']);
    crm_customer_get($customerId);
    $content = trim((string)($input['content'] ?? ''));
    if ($content === '') throw new RuntimeException('跟进内容不能为空。');
    $followupTime = crm_task_datetime($input['followup_time'] ?? $current['followup_time']) ?: date('Y-m-d H:i:s');
    $nextRemind = crm_task_datetime($input['next_remind_time'] ?? '');
    $status = preg_replace('/[^a-z_]/i', '', (string)($input['status'] ?? $current['status'] ?? 'open')) ?: 'open';
    $data = [
        'customer_id' => $customerId,
        'contact_id' => (int)($input['contact_id'] ?? 0) ?: null,
        'followup_time' => $followupTime,
        'followup_type' => trim((string)($input['followup_type'] ?? '')) ?: 'other',
        'content' => $content,
        'next_plan' => trim((string)($input['next_plan'] ?? '')),
        'next_remind_time' => $nextRemind,
        'status' => $status,
        'updated_by' => current_user()['id'] ?? 0,
    ];
    db()->prepare('UPDATE crm_customer_followups SET customer_id=?, contact_id=?, followup_time=?, followup_type=?, content=?, next_plan=?, next_remind_time=?, status=?, updated_by=?, updated_at=NOW() WHERE id=?')
        ->execute([$data['customer_id'], $data['contact_id'], $data['followup_time'], $data['followup_type'], $data['content'], $data['next_plan'], $data['next_remind_time'], $data['status'], $data['updated_by'], $id]);
    $after = crm_followup_get($id)['followup'];
    crm_customer_log('followup_update', 'followup', $id, $customerId, $current, $after, '编辑跟进');
    crm_customer_timeline_add($customerId, 'followup_update', '编辑跟进', $content, 'followup', (string)$id);
    if (function_exists('crm_task_upsert_from_followup')) crm_task_upsert_from_followup($after);
    return ['followup' => $after, 'customer' => crm_customer_get($customerId)['customer'] ?? null];
}

function crm_followup_delete(array $input): array
{
    crm_require('follow.delete');
    $id = (int)($input['followup_id'] ?? 0);
    if ($id <= 0) throw new RuntimeException('缺少跟进记录。');
    $stmt = db()->prepare('SELECT * FROM crm_customer_followups WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) throw new RuntimeException('跟进记录不存在或已删除。');
    $customerId = (int)$row['customer_id'];
    crm_customer_get($customerId);

    db()->prepare('UPDATE crm_customer_followups SET deleted_at = NOW(), updated_by = ?, updated_at = NOW() WHERE id = ?')
        ->execute([current_user()['id'] ?? 0, $id]);
    db()->prepare('UPDATE crm_tasks SET deleted_at = NOW(), updated_at = NOW() WHERE source_type = ? AND source_id = ? AND deleted_at IS NULL')
        ->execute(['followup', $id]);

    crm_customer_log('followup_delete', 'followup', $id, $customerId, $row, ['deleted_at' => date('Y-m-d H:i:s')], '删除跟进');
    crm_customer_timeline_add($customerId, 'followup_delete', '删除跟进', (string)($row['content'] ?? ''), 'followup', (string)$id);
    return crm_customer_get($customerId);
}

function crm_customer_summary(int $customerId, ?array $linkage = null): array
{
    $contacts = (int)db()->query('SELECT COUNT(*) FROM crm_contacts WHERE customer_id = ' . (int)$customerId . ' AND deleted_at IS NULL')->fetchColumn();
    $chatGroups = (int)db()->query('SELECT COUNT(*) FROM crm_customer_chat_groups WHERE customer_id = ' . (int)$customerId . ' AND deleted_at IS NULL AND status = "active"')->fetchColumn();
    $followups = (int)db()->query('SELECT COUNT(*) FROM crm_customer_followups WHERE customer_id = ' . (int)$customerId . ' AND deleted_at IS NULL')->fetchColumn();
    $lastFollow = db()->query('SELECT MAX(followup_time) FROM crm_customer_followups WHERE customer_id = ' . (int)$customerId . ' AND deleted_at IS NULL')->fetchColumn() ?: '暂无';
    if ($linkage === null) {
        $stmt = db()->prepare('SELECT * FROM crm_customers WHERE id = ? LIMIT 1');
        $stmt->execute([$customerId]);
        $customer = $stmt->fetch() ?: [];
        $linkage = $customer ? crm_customer_linkage_summary($customer) : [];
    }
    $quote = $linkage['quote'] ?? ['total' => 0, 'pending' => 0, 'latest_at' => '', 'price_visible' => false];
    $bom = $linkage['bom'] ?? ['total' => 0, 'cost_alerts' => 0, 'latest_at' => '', 'cost_visible' => false];
    $orders = $linkage['orders'] ?? ['total' => 0, 'open' => 0, 'latest_at' => ''];
    $documents = $linkage['documents'] ?? ['total' => 0, 'ready' => 0, 'latest_at' => ''];
    $shipments = $linkage['shipments'] ?? ['total' => 0, 'open' => 0, 'latest_at' => ''];
    $receivables = $linkage['receivables'] ?? ['balance_total' => null, 'paid_percent' => 0, 'unpaid_count' => 0, 'price_visible' => false, 'status_text' => '暂无收款数据'];
    $mail = crm_customer_mail_summary($customerId);
    return [
        'contacts' => ['label' => '联系人', 'value' => $contacts . ' 个', 'hint' => '主联系人和全部联系人'],
        'chat_groups' => ['label' => '客户群', 'value' => $chatGroups . ' 个', 'hint' => '微信群 / WhatsApp群执行清单'],
        'followups' => ['label' => '跟进', 'value' => $followups . ' 次', 'hint' => '最近 ' . $lastFollow],
        'mail' => ['label' => '邮件', 'value' => (int)$mail['total'] . ' 封', 'unreplied' => (int)$mail['unreplied'] . ' 封', 'hint' => '未回复 ' . (int)$mail['unreplied'] . '，最近 ' . ($mail['latest_at'] ?: '暂无')],
        'quote' => ['label' => '报价', 'value' => (int)($quote['total'] ?? 0) . ' 份', 'hint' => '待确认 ' . (int)($quote['pending'] ?? 0) . '，最近 ' . (($quote['latest_at'] ?? '') ?: '暂无')],
        'plm' => ['label' => 'PLM', 'value' => '0 项', 'hint' => '暂无项目或样品记录'],
        'bom' => ['label' => 'BOM', 'value' => (int)($bom['total'] ?? 0) . ' 条', 'hint' => !empty($bom['cost_visible']) ? ('异常 ' . (int)($bom['cost_alerts'] ?? 0) . '，最近 ' . (($bom['latest_at'] ?? '') ?: '暂无')) : '已关联，成本字段按权限隐藏'],
        'dispatch' => ['label' => '派工', 'value' => '0 个', 'hint' => '逾期、执行人、完成时间'],
        'orders' => ['label' => '订单', 'value' => (int)($orders['total'] ?? 0) . ' 张', 'hint' => '未完成 ' . (int)($orders['open'] ?? 0) . '，最近 ' . (($orders['latest_at'] ?? '') ?: '暂无')],
        'documents' => ['label' => '单证', 'value' => (int)($documents['total'] ?? 0) . ' 份', 'hint' => '已生成 ' . (int)($documents['ready'] ?? 0) . '，最近 ' . (($documents['latest_at'] ?? '') ?: '暂无')],
        'shipments' => ['label' => '出货', 'value' => (int)($shipments['total'] ?? 0) . ' 批', 'hint' => '未完成 ' . (int)($shipments['open'] ?? 0) . '，最近 ' . (($shipments['latest_at'] ?? '') ?: '暂无')],
        'receivables' => ['label' => '欠款', 'value' => !empty($receivables['price_visible']) ? trim((string)($receivables['currency'] ?? '') . ' ' . crm_money($receivables['balance_total'] ?? 0)) : '***', 'hint' => '收款 ' . (int)($receivables['paid_percent'] ?? 0) . '%，' . ($receivables['status_text'] ?? '暂无收款数据')],
        'materials' => ['label' => '资料', 'value' => '0 包', 'hint' => '资料包、发送记录'],
    ];
}

function crm_customer_count_records_for_customer(string $table, int $customerId): int
{
    if ($customerId <= 0 || !preg_match('/^[a-zA-Z0-9_]+$/', $table) || !crm_table_exists_safe($table)) {
        return 0;
    }
    $cols = crm_table_columns_safe($table);
    if (!in_array('customer_id', $cols, true)) {
        return 0;
    }
    $where = ['`customer_id` = ?'];
    if (in_array('deleted_at', $cols, true)) {
        $where[] = '`deleted_at` IS NULL';
    }
    $stmt = db()->prepare('SELECT COUNT(*) FROM `' . $table . '` WHERE ' . implode(' AND ', $where));
    $stmt->execute([$customerId]);
    return (int)$stmt->fetchColumn();
}

function crm_customer_sales_action_stats(int $customerId, array $customer, ?array $linkage = null): array
{
    $mail = crm_customer_mail_summary($customerId);
    $quote = $linkage['quote'] ?? crm_customer_quote_summary($customer);
    $followups = crm_customer_count_records_for_customer('crm_customer_followups', $customerId);
    $visits = crm_customer_count_records_for_customer('crm_visit_records', $customerId);
    $opportunities = crm_customer_count_records_for_customer('crm_opportunities', $customerId);
    $quotes = (int)($quote['total'] ?? 0);
    $mails = (int)($mail['total'] ?? 0);
    $unreplied = (int)($mail['unreplied'] ?? 0);

    return [
        'followups' => $followups,
        'followup_count' => $followups,
        'mail' => $mails,
        'mail_count' => $mails,
        'quotes' => $quotes,
        'quote_count' => $quotes,
        'opportunities' => $opportunities,
        'opportunity_count' => $opportunities,
        'visits' => $visits,
        'visit_count' => $visits,
        'unreplied' => $unreplied,
        'unreplied_count' => $unreplied,
        'latest_mail_at' => (string)($mail['latest_at'] ?? ''),
        'latest_quote_at' => (string)($quote['latest_at'] ?? ''),
    ];
}

function crm_customer_mail_summary(int $customerId): array
{
    if ($customerId <= 0 || !crm_table_exists_safe('crm_mails')) {
        return ['total' => 0, 'unreplied' => 0, 'latest_at' => ''];
    }
    $mailCols = crm_table_columns_safe('crm_mails');
    $canAll = has_permission('customer.mail_summary') || has_permission('mail.view');
    $canOwn = has_permission('mail.view_own') || has_permission('mail.account_bind_own');
    if (!$canAll && !$canOwn && !is_super_admin()) {
        return ['total' => 0, 'unreplied' => 0, 'latest_at' => '无权限'];
    }

    $where = [];
    $params = [];
    if (in_array('linked_customer_id', $mailCols, true)) {
        $where[] = '(m.linked_customer_id = ?)';
        $params[] = $customerId;
    }

    $emailRows = [];
    $stmt = db()->prepare('SELECT email FROM crm_customers WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([$customerId]);
    $customerEmail = trim((string)$stmt->fetchColumn());
    if ($customerEmail !== '' && filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
        $emailRows[] = strtolower($customerEmail);
    }
    $stmt = db()->prepare('SELECT email FROM crm_contacts WHERE customer_id = ? AND deleted_at IS NULL AND email IS NOT NULL AND email <> ""');
    $stmt->execute([$customerId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $email) {
        $email = strtolower(trim((string)$email));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) $emailRows[] = $email;
    }
    $emails = array_values(array_unique($emailRows));
    $emailFields = array_values(array_filter(['from_email', 'to_emails', 'cc_emails', 'bcc_emails'], fn($field) => in_array($field, $mailCols, true)));
    foreach ($emails as $email) {
        $parts = [];
        foreach ($emailFields as $field) {
            if ($field === 'from_email') {
                $parts[] = 'LOWER(m.`from_email`) = ?';
                $params[] = $email;
            } else {
                $parts[] = 'LOWER(m.`' . $field . '`) LIKE ?';
                $params[] = '%' . $email . '%';
            }
        }
        if ($parts) $where[] = '(' . implode(' OR ', $parts) . ')';
    }

    $deletedExpr = in_array('is_deleted', $mailCols, true) ? 'm.is_deleted = 0' : '1=1';
    $unrepliedExpr = in_array('is_unreplied', $mailCols, true) ? 'm.is_unreplied = 1' : '0';
    $dateParts = array_values(array_filter(['received_at', 'sent_at', 'created_at'], fn($field) => in_array($field, $mailCols, true)));
    $dateExpr = $dateParts ? 'COALESCE(' . implode(', ', array_map(fn($field) => 'm.`' . $field . '`', $dateParts)) . ')' : 'NULL';
    if (!$where) {
        return ['total' => 0, 'unreplied' => 0, 'latest_at' => ''];
    }
    $scope = [$deletedExpr, '(' . implode(' OR ', $where) . ')'];
    if (!$canAll && !is_super_admin() && in_array('user_id', $mailCols, true)) {
        $scope[] = 'm.user_id = ?';
        $params[] = (int)(current_user()['id'] ?? 0);
    } elseif (!$canAll && !is_super_admin()) {
        return ['total' => 0, 'unreplied' => 0, 'latest_at' => ''];
    }
    $sql = 'SELECT COUNT(*) AS total, SUM(CASE WHEN ' . $unrepliedExpr . ' THEN 1 ELSE 0 END) AS unreplied, MAX(' . $dateExpr . ') AS latest_at FROM crm_mails m WHERE ' . implode(' AND ', $scope);
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch() ?: [];
    return [
        'total' => (int)($row['total'] ?? 0),
        'unreplied' => (int)($row['unreplied'] ?? 0),
        'latest_at' => (string)($row['latest_at'] ?? ''),
    ];
}

function crm_customer_mail_match_scope(int $customerId, array $mailCols, array &$params): array
{
    $where = [];
    if (in_array('linked_customer_id', $mailCols, true)) {
        $where[] = '(m.linked_customer_id = ?)';
        $params[] = $customerId;
    }

    $emailRows = [];
    $stmt = db()->prepare('SELECT email FROM crm_customers WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([$customerId]);
    $customerEmail = trim((string)$stmt->fetchColumn());
    if ($customerEmail !== '' && filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
        $emailRows[] = strtolower($customerEmail);
    }
    $stmt = db()->prepare('SELECT email FROM crm_contacts WHERE customer_id = ? AND deleted_at IS NULL AND email IS NOT NULL AND email <> ""');
    $stmt->execute([$customerId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $email) {
        $email = strtolower(trim((string)$email));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) $emailRows[] = $email;
    }
    $emails = array_values(array_unique($emailRows));
    $emailFields = array_values(array_filter(['from_email', 'to_emails', 'cc_emails', 'bcc_emails'], fn($field) => in_array($field, $mailCols, true)));
    foreach ($emails as $email) {
        $parts = [];
        foreach ($emailFields as $field) {
            if ($field === 'from_email') {
                $parts[] = 'LOWER(m.`from_email`) = ?';
                $params[] = $email;
            } else {
                $parts[] = 'LOWER(m.`' . $field . '`) LIKE ?';
                $params[] = '%' . $email . '%';
            }
        }
        if ($parts) $where[] = '(' . implode(' OR ', $parts) . ')';
    }
    return $where;
}

function crm_customer_mail_rows(int $customerId, int $limit = 50): array
{
    if ($customerId <= 0 || !crm_table_exists_safe('crm_mails')) return [];
    $mailCols = crm_table_columns_safe('crm_mails');
    $canAll = has_permission('customer.mail_summary') || has_permission('mail.view');
    $canOwn = has_permission('mail.view_own') || has_permission('mail.account_bind_own');
    if (!$canAll && !$canOwn && !is_super_admin()) return [];

    $params = [];
    $where = crm_customer_mail_match_scope($customerId, $mailCols, $params);
    if (!$where) return [];

    $deletedExpr = in_array('is_deleted', $mailCols, true) ? 'm.is_deleted = 0' : '1=1';
    $dateParts = array_values(array_filter(['received_at', 'sent_at', 'created_at'], fn($field) => in_array($field, $mailCols, true)));
    $dateExpr = $dateParts ? 'COALESCE(' . implode(', ', array_map(fn($field) => 'm.`' . $field . '`', $dateParts)) . ')' : 'm.id';
    $selects = [
        'm.id',
        in_array('subject', $mailCols, true) ? 'm.subject' : 'NULL AS subject',
        in_array('from_email', $mailCols, true) ? 'm.from_email' : 'NULL AS from_email',
        in_array('from_name', $mailCols, true) ? 'm.from_name' : 'NULL AS from_name',
        in_array('to_emails', $mailCols, true) ? 'm.to_emails' : 'NULL AS to_emails',
        in_array('folder', $mailCols, true) ? 'm.folder' : 'NULL AS folder',
        in_array('received_at', $mailCols, true) ? 'm.received_at' : 'NULL AS received_at',
        in_array('sent_at', $mailCols, true) ? 'm.sent_at' : 'NULL AS sent_at',
        in_array('created_at', $mailCols, true) ? 'm.created_at' : 'NULL AS created_at',
        in_array('attachment_count', $mailCols, true) ? 'm.attachment_count' : '0 AS attachment_count',
        in_array('is_unreplied', $mailCols, true) ? 'm.is_unreplied' : '0 AS is_unreplied',
        in_array('is_read', $mailCols, true) ? 'm.is_read' : '1 AS is_read',
        in_array('send_status', $mailCols, true) ? 'm.send_status' : 'NULL AS send_status',
        in_array('followup_status', $mailCols, true) ? 'm.followup_status' : 'NULL AS followup_status',
    ];
    $scope = [$deletedExpr, '(' . implode(' OR ', $where) . ')'];
    if (!$canAll && !is_super_admin() && in_array('user_id', $mailCols, true)) {
        $scope[] = 'm.user_id = ?';
        $params[] = (int)(current_user()['id'] ?? 0);
    } elseif (!$canAll && !is_super_admin()) {
        return [];
    }
    $limit = max(1, min(100, $limit));
    $sql = 'SELECT ' . implode(', ', $selects) . ' FROM crm_mails m WHERE ' . implode(' AND ', $scope) . ' ORDER BY ' . $dateExpr . ' DESC, m.id DESC LIMIT ' . $limit;
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $folder = (string)($row['folder'] ?? '');
        $row['direction'] = $folder === 'sent' ? '发件' : ($folder === 'draft' ? '草稿' : '收件');
        $row['status'] = !empty($row['send_status']) ? (string)$row['send_status'] : (!empty($row['is_unreplied']) ? '未回复' : (empty($row['is_read']) ? '未读' : '已读'));
    }
    unset($row);
    return $rows;
}

function crm_customer_mail_preview(int $customerId, int $mailId): array
{
    if ($customerId <= 0 || $mailId <= 0) throw new RuntimeException('客户或邮件无效。');
    if (!has_permission('customer.mail_summary') && !has_permission('mail.view') && !is_super_admin()) {
        throw new RuntimeException('没有权限查看该客户邮件正文。');
    }
    // Enforce customer visibility with the existing customer scope rules.
    crm_customer_get($customerId, 'overview');
    if (!crm_table_exists_safe('crm_mails')) throw new RuntimeException('邮件表不存在。');

    $mailCols = crm_table_columns_safe('crm_mails');
    $params = [$mailId];
    $where = crm_customer_mail_match_scope($customerId, $mailCols, $params);
    if (!$where) throw new RuntimeException('该客户没有可匹配的邮件。');
    $deletedExpr = in_array('is_deleted', $mailCols, true) ? 'm.is_deleted = 0' : '1=1';
    $stmt = db()->prepare('SELECT m.*, c.customer_name AS linked_customer_name FROM crm_mails m LEFT JOIN crm_customers c ON c.id = m.linked_customer_id WHERE m.id = ? AND ' . $deletedExpr . ' AND (' . implode(' OR ', $where) . ') LIMIT 1');
    $stmt->execute($params);
    $mail = $stmt->fetch();
    if (!$mail) throw new RuntimeException('邮件不存在、未关联该客户，或无权查看。');

    if (function_exists('crm_mail_clean_utf8')) {
        $mail['body_html'] = crm_mail_clean_utf8((string)($mail['body_html'] ?? ''));
        $mail['body_text'] = crm_mail_clean_utf8((string)($mail['body_text'] ?? ''));
    }
    if (trim((string)($mail['body_html'] ?? '')) === '' && trim((string)($mail['body_text'] ?? '')) !== '') {
        $mail['body_html'] = nl2br(htmlspecialchars((string)$mail['body_text'], ENT_QUOTES, 'UTF-8'));
    }
    if (trim(strip_tags((string)($mail['body_html'] ?? ''))) === '' && (string)($mail['body_html'] ?? '') !== '') {
        $mail['body_text'] = trim((string)($mail['body_text'] ?? '')) ?: '此邮件包含图片、表格或特殊格式正文。';
    }

    $attachments = [];
    if (crm_table_exists_safe('crm_mail_attachments')) {
        $attach = db()->prepare('SELECT id, file_name, filename, original_filename, file_path, file_size, mime_type, attachment_type, message_id, content_id, is_inline, is_signature_image, preview_status, CASE WHEN COALESCE(is_inline,0) = 0 AND COALESCE(is_signature_image,0) = 0 THEN 1 ELSE 0 END AS is_visible_attachment, CASE WHEN file_path IS NOT NULL AND file_path <> "" AND COALESCE(is_inline,0) = 0 AND COALESCE(is_signature_image,0) = 0 THEN 1 ELSE 0 END AS can_forward_file FROM crm_mail_attachments WHERE mail_id = ? AND user_id = ? ORDER BY id');
        $attach->execute([$mailId, (int)($mail['user_id'] ?? 0)]);
        $attachments = $attach->fetchAll();
        if (function_exists('crm_mail_inline_body_html')) {
            $mail['body_html'] = crm_mail_inline_body_html((string)($mail['body_html'] ?? ''), $attachments);
        }
    }
    $visibleAttachmentCount = 0;
    foreach ($attachments as $attachment) {
        if ((int)($attachment['is_visible_attachment'] ?? 0) === 1) $visibleAttachmentCount++;
    }
    if ($visibleAttachmentCount > 0 && ((int)($mail['has_body'] ?? 1) === 0 || (string)($mail['body_status'] ?? '') === 'no_body_with_attachments')) {
        $mail['body_html'] = function_exists('crm_mail_no_body_attachment_html') ? crm_mail_no_body_attachment_html() : '<div>此邮件没有正文，但包含附件。</div>';
        $mail['body_text'] = '此邮件没有可解析正文，但包含附件。';
        $mail['body_status'] = 'no_body_with_attachments';
        $mail['has_body'] = 0;
    }
    $mail['attachments'] = $attachments;
    $mail['has_attachment'] = $visibleAttachmentCount > 0 ? 1 : 0;
    $mail['attachment_count'] = $visibleAttachmentCount;
    $mail['source_flags'] = json_decode((string)($mail['source_flags_json'] ?? '[]'), true) ?: [];
    $mail['tags'] = json_decode((string)($mail['tags_json'] ?? '[]'), true) ?: [];
    if (function_exists('crm_log_event')) {
        crm_log_event('customer', 'customer_mail_preview', 'mail', (string)$mailId, null, ['customer_id' => $customerId]);
    }
    return ['mail' => $mail];
}

function crm_customer_linkage_summary(array $customer): array
{
    return [
        'quote' => crm_customer_quote_summary($customer),
        'bom' => crm_customer_bom_summary($customer),
        'orders' => $orders = crm_customer_order_summary($customer),
        'documents' => crm_customer_document_summary($customer),
        'shipments' => crm_customer_shipment_summary($customer),
        'receivables' => crm_customer_receivable_summary($orders),
    ];
}

function crm_linkage_data(array $input): array
{
    crm_require('customer.view');
    $customerId = (int)($input['customer_id'] ?? 0);
    $customer = null;
    if ($customerId > 0) {
        $customer = crm_customer_get($customerId)['customer'];
    }
    return [
        'customer_id' => $customerId,
        'customer_name' => $customer['customer_name'] ?? '',
        'quote' => crm_customer_quote_summary($customer ?: []),
        'bom' => crm_customer_bom_summary($customer ?: []),
        'plm' => ['total' => 0, 'rows' => [], 'status' => 'pending', 'message' => 'PLM 联动未接入 CRM 只读服务。'],
        'dispatch' => ['total' => 0, 'rows' => [], 'status' => 'pending', 'message' => '派工联动请进入派工待办或使用 @派工 预览。'],
        'naming' => ['total' => 0, 'rows' => [], 'status' => 'pending', 'message' => '命名系统已支持派工 @命名 预览，CRM 列表联动待接入。'],
        'orders' => $orders = crm_customer_order_summary($customer ?: []),
        'documents' => crm_customer_document_summary($customer ?: []),
        'shipments' => crm_customer_shipment_summary($customer ?: []),
        'receivables' => crm_customer_receivable_summary($orders),
        'reconcile' => ['total' => 0, 'rows' => [], 'status' => 'pending', 'message' => '对账联动未接入。'],
    ];
}

function crm_customer_quote_summary(array $customer): array
{
    $canView = crm_external_can('quote', 'view') || has_permission('customer.quote_summary');
    $canPrice = crm_quote_can_view_price();
    if (!$canView) {
        return ['total' => 0, 'pending' => 0, 'sent' => 0, 'latest_at' => '', 'amount_total' => null, 'price_visible' => false, 'rows' => [], 'status' => 'denied', 'message' => '无报价系统查看权限。'];
    }
    if (!crm_table_exists_safe('quote_orders')) {
        return ['total' => 0, 'pending' => 0, 'sent' => 0, 'latest_at' => '', 'amount_total' => null, 'price_visible' => $canPrice, 'rows' => [], 'status' => 'missing', 'message' => '报价系统表 quote_orders 不存在。'];
    }
    $cols = crm_table_columns_safe('quote_orders');
    $where = [];
    $params = [];
    crm_customer_external_match_clauses($customer, $cols, [
        'customer_name' => ['customer_name', 'customer_name_en', 'customer_code'],
        'customer_json' => ['customer_name', 'customer_name_en', 'customer_code', 'email', 'website'],
        'quote_no' => ['customer_code'],
    ], $where, $params);
    if (in_array('customer_id', $cols, true) && !empty($customer['id'])) {
        $where[] = '`customer_id` = ?';
        $params[] = (string)$customer['id'];
        $where[] = '`customer_id` = ?';
        $params[] = 'crm_' . (string)$customer['id'];
    }
    if (in_array('quote_no', $cols, true) && trim((string)($customer['customer_code'] ?? '')) !== '') {
        $where[] = '`quote_no` LIKE ?';
        $params[] = '%' . trim((string)$customer['customer_code']) . '%';
    }
    if (!$where) {
        $where[] = '1=1';
    }
    $dateExpr = in_array('quote_date', $cols, true) ? 'quote_date' : (in_array('created_at', $cols, true) ? 'created_at' : 'id');
    $statusExpr = in_array('quote_status', $cols, true) ? 'quote_status' : (in_array('status', $cols, true) ? 'status' : "''");
    $amountExpr = in_array('amount', $cols, true) ? 'amount' : '0';
    $sql = 'SELECT * FROM quote_orders WHERE (' . implode(' OR ', $where) . ') ORDER BY ' . $dateExpr . ' DESC, id DESC LIMIT 30';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $flow = ['records' => [], 'summary' => ['quote_total' => 0, 'order_total' => 0, 'unreplied' => 0, 'payment_due' => 0], 'updated_at' => date('Y-m-d H:i:s')];
    if (function_exists('crm_task_quote_flow_records')) {
        $flowWhere = [];
        $flowParams = [];
        crm_customer_external_match_clauses($customer, $cols, [
            'customer_name' => ['customer_name', 'customer_name_en', 'customer_code'],
            'customer_json' => ['customer_name', 'customer_name_en', 'customer_code', 'email', 'website'],
            'quote_no' => ['customer_code'],
        ], $flowWhere, $flowParams, 'q');
        if (in_array('customer_id', $cols, true) && !empty($customer['id'])) {
            $flowWhere[] = 'q.`customer_id` = ?';
            $flowParams[] = (string)$customer['id'];
            $flowWhere[] = 'q.`customer_id` = ?';
            $flowParams[] = 'crm_' . (string)$customer['id'];
        }
        if (in_array('quote_no', $cols, true) && trim((string)($customer['customer_code'] ?? '')) !== '') {
            $flowWhere[] = 'q.`quote_no` LIKE ?';
            $flowParams[] = '%' . trim((string)$customer['customer_code']) . '%';
        }
        if ($flowWhere) {
            $flowRecords = crm_task_quote_flow_records($cols, crm_table_columns_safe('quote_sales_orders'), crm_table_columns_safe('quote_shipments'), ['(' . implode(' OR ', $flowWhere) . ')'], $flowParams);
            $flow = crm_customer_quote_flow_summary_from_records($flowRecords);
        }
    }
    $rows = [];
    $pending = 0;
    $sent = 0;
    $amountTotal = 0.0;
    $latest = '';
    foreach ($stmt->fetchAll() as $row) {
        $status = (string)($row['quote_status'] ?? ($row['status'] ?? ''));
        if (preg_match('/pending|待|draft|open|确认/i', $status)) $pending++;
        if (preg_match('/sent|已发|send/i', $status)) $sent++;
        if ($latest === '') $latest = (string)($row['quote_date'] ?? ($row['created_at'] ?? ''));
        if ($canPrice && is_numeric($row['amount'] ?? null)) $amountTotal += (float)$row['amount'];
        $items = crm_quote_items_from_row($row, $canPrice);
        $rows[] = [
            'id' => (int)($row['id'] ?? 0),
            'quote_no' => (string)($row['quote_no'] ?? ''),
            'customer_name' => (string)($row['customer_name'] ?? ''),
            'quote_date' => (string)($row['quote_date'] ?? ($row['created_at'] ?? '')),
            'status' => $status,
            'currency' => (string)($row['currency'] ?? ''),
            'qty' => (string)($row['qty'] ?? ''),
            'amount' => $canPrice ? crm_money($row['amount'] ?? 0) : '***',
            'amount_raw' => $canPrice ? (float)($row['amount'] ?? 0) : null,
            'owner' => (string)($row['user_name'] ?? ($row['created_by_name'] ?? '')),
            'items' => $items,
        ];
    }
    return [
        'total' => count($rows),
        'pending' => $pending,
        'sent' => $sent,
        'latest_at' => $latest,
        'amount_total' => $canPrice ? crm_money($amountTotal) : null,
        'price_visible' => $canPrice,
        'rows' => $rows,
        'flow' => $flow,
        'status' => 'connected',
        'message' => $canPrice ? '已读取报价系统真实数据。' : '已读取报价系统真实数据，价格字段按权限隐藏。',
        'status_expr' => $statusExpr,
        'amount_expr' => $amountExpr,
    ];
}

function crm_customer_bom_summary(array $customer): array
{
    $canView = crm_external_can('bom', 'view');
    $canCost = crm_bom_can_view_cost();
    $canSupplier = crm_bom_can_view_supplier();
    if (!$canView) {
        return ['total' => 0, 'cost_alerts' => 0, 'latest_at' => '', 'cost_visible' => false, 'supplier_visible' => false, 'rows' => [], 'status' => 'denied', 'message' => '无 BOM 查看权限。'];
    }
    if (!crm_table_exists_safe('bom_projects')) {
        return ['total' => 0, 'cost_alerts' => 0, 'latest_at' => '', 'cost_visible' => $canCost, 'supplier_visible' => $canSupplier, 'rows' => [], 'status' => 'missing', 'message' => 'BOM 系统表 bom_projects 不存在。'];
    }
    $cols = crm_table_columns_safe('bom_projects');
    $where = [];
    $params = [];
    crm_customer_external_match_clauses($customer, $cols, [
        'customer' => ['customer_name', 'customer_name_en', 'customer_code'],
        'linked_title' => ['customer_name', 'customer_name_en', 'customer_code'],
    ], $where, $params);
    if (!$where) {
        $where[] = '1=1';
    }
    $active = in_array('is_active', $cols, true) ? ' AND (is_active = 1 OR is_active IS NULL)' : '';
    $dateExpr = in_array('updated_at', $cols, true) ? 'updated_at' : 'id';
    $stmt = db()->prepare('SELECT * FROM bom_projects WHERE (' . implode(' OR ', $where) . ')' . $active . ' ORDER BY ' . $dateExpr . ' DESC, id DESC LIMIT 30');
    $stmt->execute($params);
    $rows = [];
    $alerts = 0;
    $latest = '';
    foreach ($stmt->fetchAll() as $row) {
        if ($latest === '') $latest = (string)($row['updated_at'] ?? ($row['created_at'] ?? ''));
        $items = crm_bom_items_from_project($row, $canCost, $canSupplier);
        $cost = crm_bom_items_cost($items) + (float)($row['labor'] ?? 0) + (float)($row['other'] ?? 0);
        if ($canCost && $cost <= 0 && count($items) > 0) $alerts++;
        $rows[] = [
            'id' => (int)($row['id'] ?? 0),
            'project_uid' => (string)($row['project_uid'] ?? ''),
            'model' => (string)($row['model'] ?? ($row['naming_model_no'] ?? '')),
            'name' => (string)($row['name'] ?? ''),
            'customer' => (string)($row['customer'] ?? ''),
            'product_type' => (string)($row['product_type'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? ''),
            'updated_by' => (string)($row['updated_by'] ?? ''),
            'currency' => (string)($row['currency'] ?? 'RMB'),
            'cost' => $canCost ? crm_money($cost) : '***',
            'cost_raw' => $canCost ? $cost : null,
            'items' => $items,
        ];
    }
    return [
        'total' => count($rows),
        'cost_alerts' => $alerts,
        'latest_at' => $latest,
        'cost_visible' => $canCost,
        'supplier_visible' => $canSupplier,
        'rows' => $rows,
        'status' => 'connected',
        'message' => $canCost ? '已读取 BOM 系统真实数据。' : '已读取 BOM 系统真实数据，成本/供应商按权限隐藏。',
    ];
}

function crm_customer_quote_flow_summary_from_records(array $records): array
{
    $summary = ['quote_total' => count($records), 'order_total' => 0, 'unreplied' => 0, 'payment_due' => 0];
    $risks = ['review_rejected' => 0, 'unreplied_overdue' => 0, 'payment_due' => 0];
    foreach ($records as $row) {
        if ((int)($row['order_id'] ?? 0) > 0) $summary['order_total']++;
        $stages = (array)($row['stages'] ?? []);
        if (in_array('unreplied', $stages, true)) {
            $summary['unreplied']++;
            if ((int)($row['no_reply_days'] ?? 0) >= 7) $risks['unreplied_overdue']++;
        }
        if ((float)($row['balance_amount'] ?? 0) > 0) {
            $summary['payment_due']++;
            $risks['payment_due']++;
        }
        if (in_array('review_rejected', $stages, true)) $risks['review_rejected']++;
    }
    return [
        'records' => $records,
        'summary' => $summary,
        'risks' => [
            ['key' => 'review_rejected', 'label' => '审核驳回', 'count' => $risks['review_rejected']],
            ['key' => 'unreplied_overdue', 'label' => '未回复超期', 'count' => $risks['unreplied_overdue']],
            ['key' => 'payment_due', 'label' => '未收款/尾款', 'count' => $risks['payment_due']],
        ],
        'updated_at' => date('Y-m-d H:i:s'),
    ];
}

function crm_customer_order_summary(array $customer): array
{
    $canView = crm_external_can('quote', 'view') || crm_external_can('quote', 'order_convert') || has_permission('customer.order_summary');
    $canPrice = crm_quote_can_view_price();
    if (!$canView) {
        return ['total' => 0, 'open' => 0, 'latest_at' => '', 'amount_total' => null, 'price_visible' => false, 'rows' => [], 'status' => 'denied', 'message' => '无订单查看权限。'];
    }
    if (!crm_table_exists_safe('quote_sales_orders')) {
        return ['total' => 0, 'open' => 0, 'latest_at' => '', 'amount_total' => null, 'price_visible' => $canPrice, 'rows' => [], 'status' => 'missing', 'message' => '订单表 quote_sales_orders 不存在。'];
    }
    $cols = crm_table_columns_safe('quote_sales_orders');
    $where = [];
    $params = [];
    crm_customer_external_match_clauses($customer, $cols, [
        'customer_name' => ['customer_name', 'customer_name_en', 'customer_code'],
        'customer_json' => ['customer_name', 'customer_name_en', 'customer_code', 'email', 'website'],
        'quote_no' => ['customer_code'],
        'order_no' => ['customer_code'],
    ], $where, $params);
    if (!$where) $where[] = '1=0';
    $dateExpr = in_array('order_date', $cols, true) ? 'order_date' : (in_array('created_at', $cols, true) ? 'created_at' : 'id');
    $stmt = db()->prepare('SELECT * FROM quote_sales_orders WHERE (' . implode(' OR ', $where) . ') ORDER BY ' . $dateExpr . ' DESC, id DESC LIMIT 30');
    $stmt->execute($params);
    $orderRecords = $stmt->fetchAll();
    $auditMap = crm_customer_quote_audit_map($orderRecords);
    $rows = [];
    $open = 0;
    $amountTotal = 0.0;
    $latest = '';
    foreach ($orderRecords as $row) {
        $status = (string)($row['status'] ?? '');
        $shipmentStatus = (string)($row['shipment_status'] ?? '');
        $paymentStatus = (string)($row['payment_status'] ?? '');
        $amountRaw = is_numeric($row['amount'] ?? null) ? (float)$row['amount'] : 0.0;
        $paidRaw = is_numeric($row['paid_amount'] ?? null) ? (float)$row['paid_amount'] : 0.0;
        $balanceRaw = is_numeric($row['balance_amount'] ?? null) ? (float)$row['balance_amount'] : max(0.0, $amountRaw - $paidRaw);
        if (!preg_match('/已完成|已出货|取消|作废/i', $status . ' ' . $shipmentStatus)) $open++;
        if ($latest === '') $latest = (string)($row['order_date'] ?? ($row['created_at'] ?? ''));
        if ($canPrice && is_numeric($row['amount'] ?? null)) $amountTotal += $amountRaw;
        $paymentProgress = $amountRaw > 0 ? (int)max(0, min(100, round(($paidRaw / $amountRaw) * 100))) : (preg_match('/已收|paid|结清|完成/i', $paymentStatus) ? 100 : 0);
        $audit = crm_customer_quote_audit_for_order($row, $auditMap);
        $rows[] = [
            'id' => (int)($row['id'] ?? 0),
            'order_no' => (string)($row['order_no'] ?? ''),
            'quote_id' => (int)($audit['quote_id'] ?? ($row['quote_id'] ?? 0)),
            'quote_no' => (string)($row['quote_no'] ?? ''),
            'customer_name' => (string)($row['customer_name'] ?? ''),
            'order_date' => (string)($row['order_date'] ?? ($row['created_at'] ?? '')),
            'status' => $status,
            'shipment_status' => $shipmentStatus,
            'payment_status' => $paymentStatus,
            'currency' => (string)($row['currency'] ?? ''),
            'qty' => (string)($row['qty'] ?? ''),
            'amount' => $canPrice ? crm_money($row['amount'] ?? 0) : '***',
            'paid_amount' => $canPrice ? crm_money($row['paid_amount'] ?? 0) : '***',
            'balance_amount' => $canPrice ? crm_money($balanceRaw) : '***',
            'amount_raw' => $canPrice ? $amountRaw : null,
            'paid_raw' => $canPrice ? $paidRaw : null,
            'balance_raw' => $canPrice ? $balanceRaw : null,
            'order_progress' => crm_order_progress_percent($status),
            'shipment_progress' => crm_shipment_progress_percent($shipmentStatus),
            'payment_progress' => $paymentProgress,
            'owner' => (string)($row['user_name'] ?? ($row['created_by'] ?? '')),
            'doc_title' => (string)($row['order_doc_title'] ?? ($row['contract_title'] ?? '')),
            'note' => (string)($row['note'] ?? ''),
            'items' => crm_quote_items_from_row($row, $canPrice),
            'audit_status_code' => (string)($audit['audit_status_code'] ?? ''),
            'audit_status' => (string)($audit['audit_status'] ?? ''),
            'audit_user' => (string)($audit['audit_user'] ?? ''),
            'audit_time' => (string)($audit['audit_time'] ?? ''),
            'audit_reject_reason' => (string)($audit['audit_reject_reason'] ?? ''),
            'can_convert_to_order' => !empty($audit['can_convert_to_order']) ? 1 : 0,
            'converted_order_no' => (string)($audit['converted_order_no'] ?? ($row['order_no'] ?? '')),
            'converted_at' => (string)($audit['converted_at'] ?? ($row['order_date'] ?? ($row['created_at'] ?? ''))),
            'workflow_warning' => (string)($audit['workflow_warning'] ?? ''),
        ];
    }
    return [
        'total' => count($rows),
        'open' => $open,
        'latest_at' => $latest,
        'amount_total' => $canPrice ? crm_money($amountTotal) : null,
        'price_visible' => $canPrice,
        'rows' => $rows,
        'status' => 'connected',
        'message' => $canPrice ? '已读取订单真实数据。' : '已读取订单真实数据，金额字段按权限隐藏。',
    ];
}

function crm_customer_order_quote_lookup_keys(array $row): array
{
    $keys = [];
    $quoteId = (int)($row['quote_id'] ?? ($row['source_quote_id'] ?? 0));
    $quoteNo = trim((string)($row['quote_no'] ?? ''));
    if ($quoteId > 0) $keys[] = 'id:' . $quoteId;
    if ($quoteNo !== '') $keys[] = 'no:' . $quoteNo;
    return $keys;
}

function crm_customer_quote_audit_map(array $orders): array
{
    if (!crm_table_exists_safe('quote_orders')) return [];
    $quoteCols = crm_table_columns_safe('quote_orders');
    $ids = [];
    $nos = [];
    foreach ($orders as $row) {
        $quoteId = (int)($row['quote_id'] ?? ($row['source_quote_id'] ?? 0));
        $quoteNo = trim((string)($row['quote_no'] ?? ''));
        if ($quoteId > 0) $ids[] = $quoteId;
        if ($quoteNo !== '') $nos[] = $quoteNo;
    }
    $where = [];
    $params = [];
    $ids = array_values(array_unique($ids));
    $nos = array_values(array_unique($nos));
    if ($ids && in_array('id', $quoteCols, true)) {
        $where[] = 'id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        foreach ($ids as $id) $params[] = $id;
    }
    if ($nos && in_array('quote_no', $quoteCols, true)) {
        $where[] = 'quote_no IN (' . implode(',', array_fill(0, count($nos), '?')) . ')';
        foreach ($nos as $no) $params[] = $no;
    }
    if (!$where) return [];
    $stmt = db()->prepare('SELECT * FROM quote_orders WHERE ' . implode(' OR ', $where) . ' ORDER BY id DESC');
    $stmt->execute($params);
    $map = [];
    foreach ($stmt->fetchAll() as $quote) {
        $audit = crm_customer_quote_audit_record($quote);
        if (!empty($quote['id'])) $map['id:' . (int)$quote['id']] = $audit;
        if (!empty($quote['quote_no'])) $map['no:' . trim((string)$quote['quote_no'])] = $audit;
    }
    return $map;
}

function crm_customer_quote_audit_record(array $quote): array
{
    $raw = strtolower(trim((string)($quote['approval_status'] ?? ($quote['audit_status'] ?? ($quote['review_status'] ?? '')))));
    $submittedAt = trim((string)($quote['submitted_at'] ?? ''));
    $approvedAt = trim((string)($quote['approved_at'] ?? ''));
    $rejectedAt = trim((string)($quote['rejected_at'] ?? ''));
    $code = 'missing';
    $label = '无审核记录';
    if (preg_match('/approved|pass|通过|已审|已审核/i', $raw) || $approvedAt !== '') {
        $code = 'approved';
        $label = '已通过';
    } elseif (preg_match('/reject|驳回|拒绝/i', $raw) || $rejectedAt !== '') {
        $code = 'rejected';
        $label = '已驳回';
    } elseif (preg_match('/pending|review|待审|审核中/i', $raw)) {
        $code = 'pending';
        $label = '待审核';
    } elseif ($raw === '' && $submittedAt === '' && $approvedAt === '' && $rejectedAt === '') {
        $code = 'unsubmitted';
        $label = '未提交审核';
    } elseif ($raw !== '') {
        $code = 'pending';
        $label = (string)($quote['approval_status'] ?? $quote['audit_status'] ?? $quote['review_status'] ?? '待审核');
    }
    $user = '';
    $time = '';
    if ($code === 'approved') {
        $user = (string)($quote['approved_by'] ?? '');
        $time = $approvedAt;
    } elseif ($code === 'rejected') {
        $user = (string)($quote['rejected_by'] ?? '');
        $time = $rejectedAt;
    } else {
        $user = (string)($quote['submitted_by'] ?? ($quote['user_name'] ?? ''));
        $time = $submittedAt;
    }
    return [
        'quote_id' => (int)($quote['id'] ?? 0),
        'quote_no' => (string)($quote['quote_no'] ?? ''),
        'audit_status_code' => $code,
        'audit_status' => $label,
        'audit_user' => $user,
        'audit_time' => $time,
        'audit_reject_reason' => (string)($quote['reject_reason_detail'] ?? ($quote['approval_note'] ?? ($quote['reject_reason_custom'] ?? ''))),
        'can_convert_to_order' => $code === 'approved',
        'converted_order_no' => (string)($quote['converted_order_no'] ?? ''),
        'converted_at' => (string)($quote['converted_at'] ?? ($quote['order_date'] ?? '')),
        'workflow_warning' => '',
    ];
}

function crm_customer_quote_audit_for_order(array $order, array $auditMap): array
{
    foreach (crm_customer_order_quote_lookup_keys($order) as $key) {
        if (isset($auditMap[$key])) {
            $audit = $auditMap[$key];
            if (empty($audit['converted_order_no'])) $audit['converted_order_no'] = (string)($order['order_no'] ?? '');
            if (empty($audit['converted_at'])) $audit['converted_at'] = (string)($order['order_date'] ?? ($order['created_at'] ?? ''));
            if (($audit['audit_status_code'] ?? '') !== 'approved') {
                $audit['workflow_warning'] = '订单已存在，但报价未审核通过。';
            }
            return $audit;
        }
    }
    return [
        'quote_id' => 0,
        'quote_no' => (string)($order['quote_no'] ?? ''),
        'audit_status_code' => 'missing',
        'audit_status' => '审核记录缺失',
        'audit_user' => '',
        'audit_time' => '',
        'audit_reject_reason' => '',
        'can_convert_to_order' => false,
        'converted_order_no' => (string)($order['order_no'] ?? ''),
        'converted_at' => (string)($order['order_date'] ?? ($order['created_at'] ?? '')),
        'workflow_warning' => '异常：订单缺少审核链路',
    ];
}

function crm_order_progress_percent(string $status): int
{
    if (preg_match('/取消|作废|cancel/i', $status)) return 0;
    if (preg_match('/已完成|完成|done|closed/i', $status)) return 100;
    if (preg_match('/已确认|confirmed|生产|production/i', $status)) return 60;
    if (preg_match('/已下单|订单|open|待生产/i', $status)) return 35;
    return $status === '' ? 10 : 45;
}

function crm_shipment_progress_percent(string $status): int
{
    if (preg_match('/取消|作废|cancel/i', $status)) return 0;
    if (preg_match('/已完成|已出货|完成|shipped|done/i', $status)) return 100;
    if (preg_match('/部分|partial|出货中|shipping/i', $status)) return 70;
    if (preg_match('/待出|待发|ready|packing|备货/i', $status)) return 45;
    return $status === '' ? 0 : 25;
}

function crm_customer_receivable_summary(array $orders): array
{
    $canPrice = !empty($orders['price_visible']);
    if (!$canPrice) {
        return [
            'total_orders' => (int)($orders['total'] ?? 0),
            'paid_percent' => 0,
            'amount_total' => null,
            'paid_total' => null,
            'balance_total' => null,
            'unpaid_count' => 0,
            'rows' => [],
            'price_visible' => false,
            'status' => $orders['status'] ?? 'connected',
            'status_text' => '金额按权限隐藏',
            'message' => '已读取订单收款状态，金额字段按权限隐藏。',
        ];
    }
    $amount = 0.0;
    $paid = 0.0;
    $balance = 0.0;
    $currencies = [];
    $rows = [];
    foreach (($orders['rows'] ?? []) as $row) {
        $amountRaw = (float)($row['amount_raw'] ?? 0);
        $paidRaw = (float)($row['paid_raw'] ?? 0);
        $balanceRaw = (float)($row['balance_raw'] ?? max(0, $amountRaw - $paidRaw));
        $currency = trim((string)($row['currency'] ?? ''));
        if ($currency !== '' && !in_array($currency, $currencies, true)) $currencies[] = $currency;
        $amount += $amountRaw;
        $paid += $paidRaw;
        $balance += $balanceRaw;
        if ($balanceRaw > 0.0001 || (int)($row['payment_progress'] ?? 0) < 100) {
            $rows[] = [
                'id' => (int)($row['id'] ?? 0),
                'order_no' => (string)($row['order_no'] ?? ''),
                'order_date' => (string)($row['order_date'] ?? ''),
                'currency' => (string)($row['currency'] ?? ''),
                'amount' => crm_money($amountRaw),
                'paid_amount' => crm_money($paidRaw),
                'balance_amount' => crm_money($balanceRaw),
                'payment_status' => (string)($row['payment_status'] ?? ''),
                'payment_progress' => (int)($row['payment_progress'] ?? 0),
                'owner' => (string)($row['owner'] ?? ''),
            ];
        }
    }
    $summaryCurrency = count($currencies) === 1 ? $currencies[0] : (count($currencies) > 1 ? '多币种' : '');
    $percent = $amount > 0 ? (int)max(0, min(100, round(($paid / $amount) * 100))) : 0;
    $statusText = $amount <= 0 ? '暂无订单收款' : ($balance <= 0.0001 ? '已收齐' : ($paid > 0 ? '部分收款，仍有欠款' : '未收款'));
    return [
        'total_orders' => (int)($orders['total'] ?? 0),
        'paid_percent' => $percent,
        'currency' => $summaryCurrency,
        'amount_total' => crm_money($amount),
        'paid_total' => crm_money($paid),
        'balance_total' => crm_money($balance),
        'unpaid_count' => count($rows),
        'rows' => $rows,
        'price_visible' => true,
        'status' => $orders['status'] ?? 'connected',
        'status_text' => $statusText,
        'message' => '已按订单收款字段计算收款进度和欠款明细。',
    ];
}

function crm_customer_document_summary(array $customer): array
{
    $shipments = crm_customer_shipment_rows($customer);
    if (isset($shipments['error'])) return ['total' => 0, 'ready' => 0, 'latest_at' => '', 'rows' => [], 'status' => $shipments['status'] ?? 'missing', 'message' => $shipments['error']];
    $rows = [];
    $ready = 0;
    $latest = '';
    foreach ($shipments['rows'] as $row) {
        $docs = [
            ['type' => 'PL', 'name' => 'Packing List', 'no' => (string)($row['packing_list_no'] ?? ''), 'generated_at' => (string)($row['pl_generated_at'] ?? '')],
            ['type' => 'CI', 'name' => 'Commercial Invoice', 'no' => (string)($row['commercial_invoice_no'] ?? ''), 'generated_at' => (string)($row['ci_generated_at'] ?? '')],
        ];
        foreach ($docs as $doc) {
            if ($doc['no'] === '' && $doc['generated_at'] === '') continue;
            if ($doc['generated_at'] !== '') $ready++;
            if ($latest === '') $latest = $doc['generated_at'] ?: (string)($row['updated_at'] ?? $row['created_at'] ?? '');
            $rows[] = [
                'shipment_id' => (int)($row['id'] ?? 0),
                'order_id' => (int)($row['order_id'] ?? 0),
                'order_no' => (string)($row['order_no'] ?? ''),
                'shipment_no' => (string)($row['shipment_no'] ?? ''),
                'type' => $doc['type'],
                'name' => $doc['name'],
                'document_no' => $doc['no'],
                'generated_at' => $doc['generated_at'],
                'status' => $doc['generated_at'] !== '' ? '已生成' : '待生成',
                'ship_date' => (string)($row['ship_date'] ?? ''),
                'customer_name' => (string)($row['customer_name'] ?? ''),
            ];
        }
    }
    return ['total' => count($rows), 'ready' => $ready, 'latest_at' => $latest, 'rows' => $rows, 'status' => 'connected', 'message' => '已读取订单单证真实数据。'];
}

function crm_customer_shipment_summary(array $customer): array
{
    $data = crm_customer_shipment_rows($customer);
    if (isset($data['error'])) return ['total' => 0, 'open' => 0, 'latest_at' => '', 'rows' => [], 'status' => $data['status'] ?? 'missing', 'message' => $data['error']];
    $rows = [];
    $open = 0;
    $latest = '';
    foreach ($data['rows'] as $row) {
        $status = (string)($row['status'] ?? '');
        if (!preg_match('/已完成|已出货|取消|作废/i', $status)) $open++;
        if ($latest === '') $latest = (string)($row['ship_date'] ?? ($row['updated_at'] ?? ($row['created_at'] ?? '')));
        $shipmentId = (int)($row['id'] ?? 0);
        $orderId = (int)($row['order_id'] ?? 0);
        $rows[] = [
            'id' => $shipmentId,
            'order_id' => $orderId,
            'order_no' => (string)($row['order_no'] ?? ''),
            'shipment_no' => (string)($row['shipment_no'] ?? ''),
            'ship_date' => (string)($row['ship_date'] ?? ''),
            'status' => $status,
            'forwarder' => (string)($row['forwarder'] ?? ''),
            'ship_method' => (string)($row['ship_method'] ?? ''),
            'port_loading' => (string)($row['port_loading'] ?? ''),
            'port_destination' => (string)($row['port_destination'] ?? ''),
            'total_qty' => (string)($row['total_qty'] ?? ''),
            'total_cartons' => (string)($row['total_cartons'] ?? ''),
            'total_nw' => (string)($row['total_nw'] ?? ''),
            'total_gw' => (string)($row['total_gw'] ?? ''),
            'total_cbm' => (string)($row['total_cbm'] ?? ''),
            'items' => crm_customer_shipment_items($shipmentId, $orderId),
            'cartons' => crm_customer_shipment_cartons($shipmentId, $orderId),
        ];
    }
    return ['total' => count($rows), 'open' => $open, 'latest_at' => $latest, 'rows' => $rows, 'status' => 'connected', 'message' => '已读取出货进度真实数据。'];
}

function crm_customer_shipment_rows(array $customer): array
{
    $canView = crm_external_can('quote', 'view') || crm_external_can('quote', 'order_convert') || has_permission('customer.order_summary');
    if (!$canView) return ['error' => '无订单/出货查看权限。', 'status' => 'denied'];
    if (!crm_table_exists_safe('quote_shipments') || !crm_table_exists_safe('quote_sales_orders')) return ['error' => '出货表 quote_shipments 或订单表 quote_sales_orders 不存在。', 'status' => 'missing'];
    $cols = crm_table_columns_safe('quote_sales_orders');
    $where = [];
    $params = [];
    crm_customer_external_match_clauses($customer, $cols, [
        'customer_name' => ['customer_name', 'customer_name_en', 'customer_code'],
        'customer_json' => ['customer_name', 'customer_name_en', 'customer_code', 'email', 'website'],
        'quote_no' => ['customer_code'],
        'order_no' => ['customer_code'],
    ], $where, $params);
    $orderWhere = $where ? array_map(fn($w) => 'o.' . $w, $where) : ['1=0'];
    $stmt = db()->prepare('SELECT s.*, o.order_no, o.quote_no, o.customer_name, o.currency, o.amount FROM quote_shipments s LEFT JOIN quote_sales_orders o ON o.id = s.order_id WHERE (' . implode(' OR ', $orderWhere) . ') ORDER BY COALESCE(s.ship_date, s.updated_at, s.created_at) DESC, s.id DESC LIMIT 30');
    $stmt->execute($params);
    return ['rows' => $stmt->fetchAll()];
}

function crm_customer_shipment_items(int $shipmentId, int $orderId): array
{
    if (!crm_table_exists_safe('quote_shipment_items')) return [];
    $params = [$shipmentId];
    $where = 'shipment_id = ?';
    if ($orderId > 0) { $where .= ' OR order_id = ?'; $params[] = $orderId; }
    $stmt = db()->prepare('SELECT * FROM quote_shipment_items WHERE ' . $where . ' ORDER BY item_index ASC, id ASC LIMIT 80');
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[] = [
            'product_code' => (string)($row['product_code'] ?? ''),
            'product_name' => (string)($row['product_name'] ?? ''),
            'specification' => (string)($row['specification'] ?? ''),
            'qty' => (string)($row['qty'] ?? ''),
            'cartons' => (string)($row['cartons'] ?? ''),
            'carton_size' => (string)($row['carton_size'] ?? ''),
            'nw' => (string)($row['nw'] ?? ''),
            'gw' => (string)($row['gw'] ?? ''),
            'cbm' => (string)($row['cbm'] ?? ''),
        ];
    }
    return $out;
}

function crm_customer_shipment_cartons(int $shipmentId, int $orderId): array
{
    if (!crm_table_exists_safe('quote_shipment_cartons')) return [];
    $params = [$shipmentId];
    $where = 'shipment_id = ?';
    if ($orderId > 0) { $where .= ' OR order_id = ?'; $params[] = $orderId; }
    $stmt = db()->prepare('SELECT * FROM quote_shipment_cartons WHERE ' . $where . ' ORDER BY id ASC LIMIT 80');
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[] = [
            'carton_no' => (string)($row['carton_no'] ?? ($row['carton_range'] ?? '')),
            'items_text' => (string)($row['items_text'] ?? ''),
            'qty' => (string)($row['qty'] ?? ''),
            'carton_size' => (string)($row['carton_size'] ?? ''),
            'nw' => (string)($row['nw'] ?? ''),
            'gw' => (string)($row['gw'] ?? ''),
            'cbm' => (string)($row['cbm'] ?? ''),
        ];
    }
    return $out;
}

function crm_customer_external_match_clauses(array $customer, array $cols, array $map, array &$where, array &$params, string $alias = ''): void
{
    $values = [];
    foreach (['customer_name', 'customer_name_en', 'customer_code', 'email', 'website'] as $key) {
        $value = trim((string)($customer[$key] ?? ''));
        if ($value !== '') $values[$key] = $value;
    }
    foreach ($map as $column => $keys) {
        if (!in_array($column, $cols, true)) continue;
        foreach ($keys as $key) {
            $value = $values[$key] ?? '';
            if ($value === '') continue;
            $field = ($alias !== '' ? $alias . '.' : '') . "`{$column}`";
            if ($column === 'quote_no') {
                $where[] = "{$field} = ?";
                $params[] = $value;
            } else {
                $where[] = "{$field} LIKE ?";
                $params[] = '%' . $value . '%';
            }
        }
    }
}

function crm_customer_quote_exists_condition(): string
{
    $cols = crm_table_columns_safe('quote_orders');
    $parts = [];
    if (in_array('customer_name', $cols, true)) {
        $parts[] = "LOCATE(CONVERT(c.customer_name USING utf8mb4) COLLATE utf8mb4_unicode_ci, CONVERT(q.customer_name USING utf8mb4) COLLATE utf8mb4_unicode_ci) > 0";
    }
    if (in_array('customer_json', $cols, true)) {
        $parts[] = "LOCATE(CONVERT(c.customer_name USING utf8mb4) COLLATE utf8mb4_unicode_ci, CONVERT(q.customer_json USING utf8mb4) COLLATE utf8mb4_unicode_ci) > 0";
        $parts[] = "(c.customer_code IS NOT NULL AND c.customer_code <> '' AND LOCATE(CONVERT(c.customer_code USING utf8mb4) COLLATE utf8mb4_unicode_ci, CONVERT(q.customer_json USING utf8mb4) COLLATE utf8mb4_unicode_ci) > 0)";
    }
    if (in_array('quote_no', $cols, true)) {
        $parts[] = "(c.customer_code IS NOT NULL AND c.customer_code <> '' AND LOCATE(CONVERT(c.customer_code USING utf8mb4) COLLATE utf8mb4_unicode_ci, CONVERT(q.quote_no USING utf8mb4) COLLATE utf8mb4_unicode_ci) > 0)";
    }
    if (!$parts) return '1 = 0';
    return 'EXISTS (SELECT 1 FROM quote_orders q WHERE ' . implode(' OR ', $parts) . ')';
}

function crm_quote_items_from_row(array $row, bool $canPrice): array
{
    $items = json_decode((string)($row['items_json'] ?? ''), true);
    if (!is_array($items) || !$items) {
        $product = json_decode((string)($row['product_json'] ?? ''), true);
        if (is_array($product) && $product) {
            $items = [['product' => $product, 'qty' => $row['qty'] ?? '', 'price' => $row['price'] ?? '', 'amount' => $row['amount'] ?? '']];
        }
    }
    $out = [];
    foreach (array_values(is_array($items) ? $items : []) as $i => $item) {
        if (!is_array($item)) continue;
        $p = is_array($item['product'] ?? null) ? $item['product'] : [];
        $line = [
            'index' => $i + 1,
            'model' => crm_first_value($p, ['code', 'model', 'model_no', 'naming_model_no', 'product_code']) ?: crm_first_value($item, ['product_code', 'code', 'model', 'model_no']),
            'name' => crm_first_value($p, ['name', 'product_name', 'title']) ?: crm_first_value($item, ['product_name', 'name', 'title']),
            'size' => crm_first_value($p, ['size', 'dimension', 'dimensions']) ?: crm_first_value($item, ['size', 'dimension', 'dimensions']),
            'power' => crm_first_value($p, ['power', 'watt', 'wattage']) ?: crm_first_value($item, ['power', 'watt', 'wattage']),
            'qty' => (string)($item['qty'] ?? ''),
            'spec' => trim((string)($item['extra_spec'] ?? ($item['specification'] ?? '')) . ' ' . crm_quote_spec_summary($p)),
        ];
        if ($canPrice) {
            $line['price'] = crm_money($item['price'] ?? 0);
            $line['amount'] = crm_money($item['amount'] ?? 0);
        }
        $out[] = $line;
    }
    return $out;
}

function crm_bom_items_from_project(array $project, bool $canCost, bool $canSupplier): array
{
    $items = json_decode((string)($project['rows_json'] ?? ''), true);
    $out = [];
    foreach (array_values(is_array($items) ? $items : []) as $i => $row) {
        if (!is_array($row)) continue;
        $qty = is_numeric($row['qty'] ?? null) ? (float)$row['qty'] : 0.0;
        $price = is_numeric($row['price'] ?? null) ? (float)$row['price'] : 0.0;
        $process = is_numeric($row['process'] ?? null) ? (float)$row['process'] : 0.0;
        $finish = is_numeric($row['finishCost'] ?? ($row['finish_cost'] ?? null)) ? (float)($row['finishCost'] ?? ($row['finish_cost'] ?? 0)) : 0.0;
        $amount = $qty * ($price + $process + $finish);
        $line = [
            'index' => $i + 1,
            'category' => (string)($row['category'] ?? ''),
            'name' => (string)($row['name'] ?? ''),
            'model' => (string)($row['model'] ?? ($row['materialId'] ?? '')),
            'spec' => (string)($row['spec'] ?? ''),
            'qty' => (string)($row['qty'] ?? ''),
            'unit' => (string)($row['unit'] ?? ''),
            'finish' => trim((string)($row['finish'] ?? '') . ' ' . (string)($row['finish2'] ?? '')),
        ];
        if ($canSupplier) $line['supplier'] = (string)($row['supplier'] ?? '');
        if ($canCost) {
            $line['price'] = crm_money($price);
            $line['process'] = crm_money($process + $finish);
            $line['amount'] = crm_money($amount);
            $line['_amount_num'] = $amount;
        }
        $out[] = $line;
    }
    return $out;
}

function crm_bom_items_cost(array $items): float
{
    $sum = 0.0;
    foreach ($items as $item) $sum += (float)($item['_amount_num'] ?? 0);
    return $sum;
}

function crm_quote_spec_summary(array $p): string
{
    $parts = [];
    foreach (['category', 'series', 'cutout', 'beam_angle'] as $key) {
        $value = trim((string)($p[$key] ?? ''));
        if ($value !== '') $parts[] = $value;
    }
    return implode(' / ', $parts);
}

function crm_quote_can_view_price(): bool
{
    return crm_external_can('quote', 'admin') || crm_external_can('quote', 'approve') || crm_external_can('quote', 'export');
}

function crm_bom_can_view_cost(): bool
{
    return crm_external_can('bom', 'admin') || crm_external_can('bom', 'cost_view');
}

function crm_bom_can_view_supplier(): bool
{
    return crm_external_can('bom', 'admin') || crm_external_can('bom', 'supplier_view');
}

function crm_external_can(string $module, string $cap): bool
{
    if (function_exists('artdon_sso_can')) return artdon_sso_can($module, $cap, current_user());
    return is_super_admin() || has_permission($module . '.' . $cap);
}

function crm_table_exists_safe(string $table): bool
{
    if (function_exists('db_table_exists')) return db_table_exists($table);
    $stmt = db()->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function crm_table_columns_safe(string $table): array
{
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !crm_table_exists_safe($table)) return $cache[$table] = [];
    $rows = db()->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll();
    return $cache[$table] = array_map(fn($row) => (string)$row['Field'], $rows);
}

function crm_first_value(array $row, array $keys): string
{
    foreach ($keys as $key) {
        $value = trim((string)($row[$key] ?? ''));
        if ($value !== '') return $value;
    }
    return '';
}

function crm_money($value): string
{
    return is_numeric($value) ? number_format((float)$value, 2, '.', '') : '';
}

function crm_customer_logs(int $customerId): array
{
    if (!has_permission('customer.view_logs') && !has_permission('logs.view_own') && !is_super_admin()) return [];
    $stmt = db()->prepare('SELECT l.*, u.username FROM crm_logs l LEFT JOIN crm_users u ON u.id = l.user_id WHERE l.customer_id = ? ORDER BY l.id DESC LIMIT 100');
    $stmt->execute([$customerId]);
    return $stmt->fetchAll();
}

function crm_customer_today_logs(array $input = []): array
{
    if (!has_permission('customer.view_logs') && !has_permission('logs.view_own') && !is_super_admin()) return ['rows' => []];
    $params = [];
    $where = ["l.module = 'customer'"];
    $scope = (string)($input['scope'] ?? 'today');
    if ($scope !== 'all') {
        $where[] = 'l.created_at >= CURDATE()';
    }
    $customerId = (int)($input['customer_id'] ?? 0);
    if ($customerId > 0) {
        $where[] = 'l.customer_id = ?';
        $params[] = $customerId;
    }
    $q = trim((string)($input['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(l.action LIKE ? OR l.message LIKE ? OR l.object_type LIKE ? OR u.username LIKE ? OR c.customer_name LIKE ? OR c.customer_code LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like, $like, $like);
    }
    $stmt = db()->prepare('SELECT l.*, u.username, c.customer_code, c.customer_name
        FROM crm_logs l
        LEFT JOIN crm_users u ON u.id = l.user_id
        LEFT JOIN crm_customers c ON c.id = l.customer_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY l.id DESC
        LIMIT 200');
    $stmt->execute($params);
    return ['rows' => $stmt->fetchAll()];
}
