<?php
declare(strict_types=1);

/**
 * 测试用：在 SQLite 里建出正式库已有的客户相关表（字段与旧代码 crm_customer_ensure_tables 建出的一致）。
 * 新系统本身不建表，只在测试里用。
 */

function customer_test_schema(PDO $pdo): void
{
    $tables = [
        'CREATE TABLE crm_customers (id INTEGER PRIMARY KEY AUTOINCREMENT, customer_code VARCHAR(80) NULL, customer_name VARCHAR(190) NOT NULL, customer_name_en VARCHAR(190) NULL,
            country VARCHAR(120) NOT NULL, city VARCHAR(120) NULL, address VARCHAR(500) NULL, website VARCHAR(255) NULL, email VARCHAR(190) NULL, phone VARCHAR(80) NULL, whatsapp VARCHAR(80) NULL,
            source VARCHAR(80) NULL, level VARCHAR(40) NULL, status VARCHAR(40) NOT NULL DEFAULT \'active\', owner_user_id INTEGER NULL, owner_department VARCHAR(120) NULL,
            created_by INTEGER NULL, updated_by INTEGER NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            deleted_at DATETIME NULL, deleted_by INTEGER NULL, delete_reason VARCHAR(500) NULL, remark TEXT NULL,
            lifecycle_key VARCHAR(120) NOT NULL DEFAULT \'lead\', risk_level VARCHAR(120) NOT NULL DEFAULT \'healthy\', do_not_contact INTEGER NOT NULL DEFAULT 0, blacklist_reason VARCHAR(500) NULL,
            customer_type VARCHAR(120) NULL, industry VARCHAR(120) NULL, backup_email VARCHAR(190) NULL, wechat VARCHAR(120) NULL, linkedin VARCHAR(255) NULL, customer_domain VARCHAR(190) NULL,
            postal_code VARCHAR(60) NULL, shipping_address VARCHAR(500) NULL, billing_address VARCHAR(500) NULL, common_port VARCHAR(120) NULL, timezone VARCHAR(120) NULL,
            no_promotion_reason VARCHAR(500) NULL, customer_tags VARCHAR(500) NULL, visibility_scope VARCHAR(80) NULL)',
        'CREATE TABLE crm_contacts (id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER NOT NULL, name VARCHAR(120) NOT NULL, name_en VARCHAR(120) NULL, position VARCHAR(120) NULL,
            department VARCHAR(120) NULL, email VARCHAR(190) NULL, phone VARCHAR(80) NULL, whatsapp VARCHAR(80) NULL, wechat VARCHAR(120) NULL, linkedin VARCHAR(255) NULL, gender VARCHAR(20) NULL,
            birthday DATE NULL, language VARCHAR(80) NULL, is_primary INTEGER NOT NULL DEFAULT 0, is_left INTEGER NOT NULL DEFAULT 0, prefer_personal_contact INTEGER NOT NULL DEFAULT 0,
            avoid_group_contact INTEGER NOT NULL DEFAULT 0, remark TEXT NULL, created_by INTEGER NULL, updated_by INTEGER NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, deleted_at DATETIME NULL, deleted_by INTEGER NULL, do_not_contact INTEGER NOT NULL DEFAULT 0,
            unsubscribe_email INTEGER NOT NULL DEFAULT 0, no_whatsapp INTEGER NOT NULL DEFAULT 0)',
        'CREATE TABLE crm_customer_addresses (id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER NOT NULL, address_type VARCHAR(20) NOT NULL DEFAULT \'Other\', country VARCHAR(120) NOT NULL,
            city VARCHAR(120) NULL, address VARCHAR(500) NULL, is_primary INTEGER NOT NULL DEFAULT 0, created_by INTEGER NULL, updated_by INTEGER NULL, created_at DATETIME NULL, updated_at DATETIME NULL,
            postal_code VARCHAR(60) NULL, contact_name VARCHAR(120) NULL, phone VARCHAR(80) NULL, deleted_at DATETIME NULL, deleted_by INTEGER NULL)',
        'CREATE TABLE crm_customer_source_tags (id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER NOT NULL, source_key VARCHAR(40) NOT NULL, created_by INTEGER NULL, created_at DATETIME NULL, UNIQUE (customer_id, source_key))',
        'CREATE TABLE crm_customer_promotion_channels (id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER NOT NULL, channel_key VARCHAR(40) NOT NULL, created_by INTEGER NULL, created_at DATETIME NULL, UNIQUE (customer_id, channel_key))',
        'CREATE TABLE crm_customer_promotion_status (customer_id INTEGER PRIMARY KEY, status VARCHAR(40) NOT NULL DEFAULT \'not_promoted\', updated_by INTEGER NULL, updated_at DATETIME NULL)',
        'CREATE TABLE crm_customer_owners (id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER NOT NULL, user_id INTEGER NOT NULL, role_type VARCHAR(20) NOT NULL DEFAULT \'viewer\', is_primary INTEGER NOT NULL DEFAULT 0, created_by INTEGER NULL, created_at DATETIME NULL, UNIQUE (customer_id, user_id))',
        'CREATE TABLE crm_lead_pool (id INTEGER PRIMARY KEY AUTOINCREMENT, raw_name VARCHAR(190) NOT NULL, raw_email VARCHAR(190) NULL, raw_phone VARCHAR(80) NULL, raw_country VARCHAR(120) NULL,
            raw_domain VARCHAR(190) NULL, payload_json TEXT NULL, similarity_matches_json TEXT NULL, status VARCHAR(20) NOT NULL DEFAULT \'pending\', target_customer_id INTEGER NULL, created_by INTEGER NULL,
            created_at DATETIME NULL, updated_at DATETIME NULL)',
        'CREATE TABLE crm_customer_timeline (id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER NOT NULL, event_type VARCHAR(120) NOT NULL, title VARCHAR(190) NOT NULL, detail VARCHAR(1000) NULL,
            related_type VARCHAR(80) NULL, related_id VARCHAR(120) NULL, event_time DATETIME NULL, created_by INTEGER NULL, created_at DATETIME NULL)',
        'CREATE TABLE crm_customer_protection (customer_id INTEGER PRIMARY KEY, protection_status VARCHAR(80) NOT NULL DEFAULT \'normal\', protected_until DATETIME NULL, reason VARCHAR(500) NULL,
            public_pool_at DATETIME NULL, claimed_by INTEGER NULL, claimed_at DATETIME NULL, updated_by INTEGER NULL, updated_at DATETIME NULL)',
        'CREATE TABLE crm_customer_groups (id INTEGER PRIMARY KEY AUTOINCREMENT, group_name VARCHAR(120) NOT NULL UNIQUE, group_color VARCHAR(40) NULL, sort_order INTEGER NOT NULL DEFAULT 100,
            created_by INTEGER NULL, created_at DATETIME NULL, updated_at DATETIME NULL, deleted_at DATETIME NULL)',
        'CREATE TABLE crm_customer_group_relations (id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER NOT NULL, group_id INTEGER NOT NULL, created_by INTEGER NULL, created_at DATETIME NULL, UNIQUE (customer_id, group_id))',
        'CREATE TABLE crm_customer_followups (id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER NOT NULL, contact_id INTEGER NULL, followup_time DATETIME NOT NULL, followup_type VARCHAR(40) NOT NULL DEFAULT \'other\',
            content TEXT NOT NULL, next_plan TEXT NULL, next_remind_time DATETIME NULL, status VARCHAR(40) NOT NULL DEFAULT \'open\', related_email_id INTEGER NULL, related_quote_id INTEGER NULL,
            request_token VARCHAR(100) NULL, source_type VARCHAR(60) NOT NULL DEFAULT \'\', source_id VARCHAR(80) NOT NULL DEFAULT \'\', created_by INTEGER NULL, updated_by INTEGER NULL,
            created_at DATETIME NULL, updated_at DATETIME NULL, deleted_at DATETIME NULL)',
        'CREATE TABLE crm_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NULL, module VARCHAR(80) NOT NULL, action VARCHAR(120) NOT NULL, object_type VARCHAR(80) NULL, object_id VARCHAR(120) NULL,
            customer_id INTEGER NULL, before_json TEXT NULL, after_json TEXT NULL, message VARCHAR(500) NULL, ip_address VARCHAR(64) NULL, user_agent VARCHAR(500) NULL, success INTEGER NOT NULL DEFAULT 1,
            error_message VARCHAR(500) NULL, created_at DATETIME NULL)',
        'CREATE TABLE crm_sensitive_audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NULL, action_key VARCHAR(120) NOT NULL, target_type VARCHAR(80) NULL, target_id VARCHAR(120) NULL,
            before_json TEXT NULL, after_json TEXT NULL, ip_address VARCHAR(64) NULL, user_agent VARCHAR(500) NULL, created_at DATETIME NULL)',
        'CREATE TABLE crm_rule_configs (id INTEGER PRIMARY KEY AUTOINCREMENT, rule_key VARCHAR(120) NOT NULL UNIQUE, rule_name VARCHAR(190) NULL, rule_group VARCHAR(80) NULL, config_json TEXT NULL,
            is_enabled INTEGER NOT NULL DEFAULT 1, created_at DATETIME NULL, updated_at DATETIME NULL)',
        'CREATE TABLE crm_customer_relations (id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER NOT NULL, related_customer_id INTEGER NOT NULL, relation_type VARCHAR(120) NOT NULL, remark VARCHAR(500) NULL,
            created_by INTEGER NULL, created_at DATETIME NULL, updated_at DATETIME NULL, UNIQUE (customer_id, related_customer_id, relation_type))',
        'CREATE TABLE crm_customer_chat_groups (id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER NOT NULL, group_name VARCHAR(190) NOT NULL, group_platform VARCHAR(20) NOT NULL DEFAULT \'wechat_group\',
            group_owner VARCHAR(120) NULL, linked_contact_ids_json TEXT NULL, use_for_promotion INTEGER NOT NULL DEFAULT 1, status VARCHAR(20) NOT NULL DEFAULT \'active\', remark VARCHAR(500) NULL,
            last_promoted_at DATETIME NULL, created_by INTEGER NULL, updated_by INTEGER NULL, created_at DATETIME NULL, updated_at DATETIME NULL, deleted_at DATETIME NULL, deleted_by INTEGER NULL)',
        'CREATE TABLE crm_duplicate_merge_cases (id INTEGER PRIMARY KEY AUTOINCREMENT, master_customer_id INTEGER NULL, duplicate_customer_ids_json TEXT NULL, match_type VARCHAR(80) NOT NULL DEFAULT \'\',
            similarity_score INTEGER NOT NULL DEFAULT 0, status VARCHAR(40) NOT NULL DEFAULT \'pending\', merge_plan_json TEXT NULL, merged_by INTEGER NULL, merged_at DATETIME NULL, created_by INTEGER NULL,
            created_at DATETIME NULL, updated_at DATETIME NULL)',
        'CREATE TABLE crm_customer_files (id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER NOT NULL, file_name VARCHAR(190) NOT NULL, file_path VARCHAR(500) NOT NULL, file_type VARCHAR(80) NULL,
            file_size INTEGER NOT NULL DEFAULT 0, category VARCHAR(80) NULL, remark VARCHAR(500) NULL, uploaded_by INTEGER NULL, created_at DATETIME NULL, deleted_at DATETIME NULL, deleted_by INTEGER NULL)',
        // 其他模块的表（只放合并客户会动到的字段）
        'CREATE TABLE crm_mails (id INTEGER PRIMARY KEY AUTOINCREMENT, subject VARCHAR(190) NULL, linked_customer_id INTEGER NULL)',
        'CREATE TABLE quote_orders (id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id VARCHAR(40) NULL)',
    ];
    foreach ($tables as $sql) {
        $pdo->exec($sql);
    }
    \Artdon\Centers\Base\Db::set($pdo);
}

/** 测试字典：等级、生命周期、状态、来源、推广方式、推广状态、国家。 */
function customer_test_dictionary(PDO $pdo): void
{
    $ins = $pdo->prepare('INSERT INTO crm_dictionary_items (type_key, item_key, name_cn, name_en, is_default, is_enabled, sort_order) VALUES (?, ?, ?, ?, ?, 1, ?)');
    $sort = 0;
    foreach ([
        ['customer_level', 'P1', '重点客户', 'Key', 0], ['customer_level', 'P2', '优质客户', 'Good', 0], ['customer_level', 'P3', '普通客户', 'Normal', 1],
        ['customer_lifecycle', 'lead', '线索', 'Lead', 1], ['customer_lifecycle', 'official', '正式客户', 'Official', 0], ['customer_lifecycle', 'quoting', '报价中', 'Quoting', 0], ['customer_lifecycle', 'deal', '已成交', 'Deal', 0],
        ['customer_status', 'lead', '线索', 'Lead', 1], ['customer_status', 'active', '活跃', 'Active', 0],
        ['customer_source', 'website', '网站', 'Website', 1], ['customer_source', 'exhibition', '展会', 'Exhibition', 0], ['customer_source', 'referral', '介绍', 'Referral', 0],
        ['promotion_channel', 'email', '邮件', 'Email', 1], ['promotion_channel', 'whatsapp', 'WhatsApp', 'WhatsApp', 0],
        ['promotion_status', 'not_promoted', '未推广', 'Not promoted', 1], ['promotion_status', 'promoting', '推广中', 'Promoting', 0],
        ['country_region', 'IN', '印度', 'India', 0], ['country_region', 'AE', '阿联酋', 'United Arab Emirates', 0], ['country_region', 'DE', '德国', 'Germany', 0],
    ] as [$type, $key, $cn, $en, $def]) {
        $ins->execute([$type, $key, $cn, $en, $def, ++$sort]);
    }
    \Artdon\Centers\Base\Dictionary::clearCache();
}
