<?php

function crm_setting_column_exists(string $table, string $column): bool
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function crm_setting_modify_column(string $table, string $column, string $definition): void
{
    if (crm_setting_column_exists($table, $column)) {
        db()->exec("ALTER TABLE {$table} MODIFY {$column} {$definition}");
    }
}

function crm_settings_schema_version(): string
{
    return '20260706_crm_settings_v4';
}

function crm_settings_schema_ready(): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        if (!db_table_exists('crm_rule_configs')) {
            $ready = false;
            return false;
        }
        $stmt = db()->prepare('SELECT config_json FROM crm_rule_configs WHERE rule_key = ? LIMIT 1');
        $stmt->execute(['crm_settings_schema_version']);
        $config = json_decode((string)$stmt->fetchColumn(), true) ?: [];
        $ready = (($config['version'] ?? '') === crm_settings_schema_version());
        return $ready;
    } catch (Throwable $e) {
        $ready = false;
        return false;
    }
}

function crm_settings_mark_schema_ready(): void
{
    db()->prepare('INSERT INTO crm_rule_configs (rule_key, rule_name, rule_group, config_json, is_enabled, created_at, updated_at) VALUES (?, ?, ?, ?, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE config_json=VALUES(config_json), updated_at=NOW()')
        ->execute(['crm_settings_schema_version', 'CRM 设置结构版本', 'system', json_encode(['version' => crm_settings_schema_version()], JSON_UNESCAPED_UNICODE)]);
}

function crm_settings_ensure_tables(): void
{
    static $done = false;
    if ($done) return;
    if (crm_settings_schema_ready()) {
        $done = true;
        return;
    }

    crm_ensure_tables();
    db()->exec("CREATE TABLE IF NOT EXISTS crm_dictionary_types (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        type_key VARCHAR(80) NOT NULL,
        type_name VARCHAR(120) NOT NULL,
        description VARCHAR(500) NULL,
        is_system TINYINT(1) NOT NULL DEFAULT 1,
        is_enabled TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 100,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_dictionary_type (type_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS crm_dictionary_items (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        type_key VARCHAR(80) NOT NULL,
        item_key VARCHAR(120) NOT NULL,
        name_cn VARCHAR(160) NOT NULL,
        name_en VARCHAR(160) NULL,
        short_name VARCHAR(80) NULL,
        color VARCHAR(40) NULL,
        icon VARCHAR(40) NULL,
        description VARCHAR(500) NULL,
        extra_config_json JSON NULL,
        is_default TINYINT(1) NOT NULL DEFAULT 0,
        is_enabled TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 100,
        created_by INT UNSIGNED NULL,
        updated_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        deleted_at DATETIME NULL,
        UNIQUE KEY uk_dictionary_item (type_key, item_key),
        KEY idx_dictionary_item_type (type_key),
        KEY idx_dictionary_item_enabled (is_enabled),
        KEY idx_dictionary_item_sort (sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS crm_rule_configs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        rule_key VARCHAR(120) NOT NULL,
        rule_name VARCHAR(160) NOT NULL,
        rule_group VARCHAR(80) NOT NULL,
        config_json JSON NULL,
        is_enabled TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT UNSIGNED NULL,
        updated_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_rule_key (rule_key),
        KEY idx_rule_group (rule_group)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS crm_field_configs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        module_name VARCHAR(80) NOT NULL,
        entity_type VARCHAR(80) NOT NULL,
        field_key VARCHAR(120) NOT NULL,
        field_label VARCHAR(160) NOT NULL,
        field_type VARCHAR(60) NOT NULL DEFAULT 'text',
        is_visible TINYINT(1) NOT NULL DEFAULT 1,
        is_required TINYINT(1) NOT NULL DEFAULT 0,
        is_readonly TINYINT(1) NOT NULL DEFAULT 0,
        default_value VARCHAR(500) NULL,
        sort_order INT NOT NULL DEFAULT 100,
        group_name VARCHAR(120) NULL,
        permission_key VARCHAR(160) NULL,
        config_json JSON NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_field_config (module_name, entity_type, field_key),
        KEY idx_field_config_entity (module_name, entity_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    foreach ([
        ['crm_customer_source_tags', 'source_key'],
        ['crm_customer_promotion_channels', 'channel_key'],
        ['crm_contact_role_tags', 'role_key'],
        ['crm_contact_sources', 'source_key'],
    ] as $pair) {
        crm_setting_modify_column($pair[0], $pair[1], 'VARCHAR(120) NOT NULL');
    }
    crm_setting_modify_column('crm_customer_addresses', 'address_type', 'VARCHAR(120) NOT NULL DEFAULT "Other"');
    crm_setting_modify_column('crm_customer_promotion_status', 'status', 'VARCHAR(120) NOT NULL DEFAULT "not_promoted"');
    crm_setting_modify_column('crm_customer_owners', 'role_type', 'VARCHAR(120) NOT NULL DEFAULT "viewer"');
    crm_setting_modify_column('crm_contact_promotions', 'channel', 'VARCHAR(120) NOT NULL');
    crm_setting_modify_column('crm_contact_promotions', 'status', 'VARCHAR(120) NOT NULL DEFAULT "active"');

    crm_seed_dictionary_types();
    crm_seed_dictionary_items();
    crm_seed_rule_configs();
    crm_seed_field_configs();
    crm_settings_ensure_permissions();
    crm_settings_mark_schema_ready();
    $done = true;
}

function crm_dictionary_type_defaults(): array
{
    return [
        ['customer_level', '客户等级', '客户价值和优先级'],
        ['customer_source', '客户来源', '客户来源和归因'],
        ['management_category', '管理分类', '客户管理分类'],
        ['promotion_channel', '推广方向 / 推广方式', '客户和联系人推广方式'],
        ['promotion_status', '推广状态', '推广阶段和禁用状态'],
        ['contact_role', '联系人角色标签', '联系人决策和职能标签'],
        ['contact_source', '联系人来源', '联系人来源归因'],
        ['address_type', '地址类型', '客户多地址分类'],
        ['owner_role', '负责人角色', '客户负责人关系权限'],
        ['customer_status', '客户状态', '客户生命周期状态'],
        ['customer_lifecycle', '客户生命周期', '客户当前业务阶段'],
        ['customer_risk_level', '客户风险等级', '客户健康和风险分层'],
        ['customer_relation_type', '客户关系类型', '客户之间的关联关系'],
        ['customer_event_type', '客户重要事件类型', '客户提醒和时间轴事件'],
        ['followup_type', '跟进方式', '客户跟进方式'],
        ['country_region', '国家 / 地区预设', '国家、区号和区域'],
        ['city_region', '城市 / 地区预设', '常用城市、省州、商业区域和照明市场地区'],
    ];
}

function crm_seed_dictionary_types(): void
{
    $stmt = db()->prepare('INSERT INTO crm_dictionary_types (type_key, type_name, description, is_system, is_enabled, sort_order, created_at, updated_at) VALUES (?, ?, ?, 1, 1, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE type_name=VALUES(type_name), description=VALUES(description), updated_at=NOW()');
    $sort = 10;
    foreach (crm_dictionary_type_defaults() as $type) {
        $stmt->execute([$type[0], $type[1], $type[2], $sort]);
        $sort += 10;
    }
}

function crm_dictionary_item_defaults(): array
{
    return [
        'customer_level' => [
            ['P0','战略 VIP','Strategic VIP','P0','#7c3aed','★',['allow_promotion'=>1,'allow_quote'=>1,'important_pool'=>1,'count_stats'=>1],1],
            ['P1','重点客户','Key Account','P1','#2563eb','◆',['allow_promotion'=>1,'allow_quote'=>1,'important_pool'=>1,'count_stats'=>1],0],
            ['P2','活跃客户','Active','P2','#059669','●',['allow_promotion'=>1,'allow_quote'=>1,'important_pool'=>0,'count_stats'=>1],0],
            ['P3','潜力客户','Potential','P3','#0ea5e9','○',['allow_promotion'=>1,'allow_quote'=>1,'important_pool'=>0,'count_stats'=>1],0],
            ['P4','低优先级','Low Priority','P4','#64748b','◇',['allow_promotion'=>1,'allow_quote'=>0,'important_pool'=>0,'count_stats'=>1],0],
            ['P5','不匹配','Not Fit','P5','#94a3b8','×',['allow_promotion'=>0,'allow_quote'=>0,'important_pool'=>0,'count_stats'=>0],0],
            ['Blacklist','黑名单','Blacklist','BL','#dc2626','!',['allow_promotion'=>0,'allow_quote'=>0,'important_pool'=>0,'count_stats'=>0],0],
        ],
        'customer_source' => [
            ['exhibition','展会','Exhibition','展会','#2563eb','EX',['source_type'=>'offline','promotion_stats'=>1,'source_analysis'=>1,'auto_attribution'=>1],0],
            ['website','官网','Website','官网','#0ea5e9','WEB',['source_type'=>'online','promotion_stats'=>1,'source_analysis'=>1,'auto_attribution'=>1],1],
            ['social','社媒','Social Media','社媒','#8b5cf6','SM',['source_type'=>'online','promotion_stats'=>1,'source_analysis'=>1,'auto_attribution'=>1],0],
            ['mail','邮件','Email','邮件','#0891b2','@',['source_type'=>'online','promotion_stats'=>1,'source_analysis'=>1,'auto_attribution'=>1],0],
            ['whatsapp','WhatsApp','WhatsApp','WA','#059669','WA',['source_type'=>'online','promotion_stats'=>1,'source_analysis'=>1,'auto_attribution'=>1],0],
            ['referral','老客户介绍','Referral','转介','#16a34a','RF',['source_type'=>'referral','promotion_stats'=>1,'source_analysis'=>1,'auto_attribution'=>0],0],
            ['manual_sales','主动开发','Manual Sales','开发','#f59e0b','MS',['source_type'=>'manual','promotion_stats'=>1,'source_analysis'=>1,'auto_attribution'=>0],0],
            ['visit','地推拜访','Visit','拜访','#d97706','V',['source_type'=>'offline','promotion_stats'=>1,'source_analysis'=>1,'auto_attribution'=>0],0],
            ['edm','EDM/邮件开发','EDM','EDM','#0284c7','EDM',['source_type'=>'campaign','promotion_stats'=>1,'source_analysis'=>1,'auto_attribution'=>1],0],
            ['google','Google','Google','G','#4285f4','G',['source_type'=>'online','promotion_stats'=>1,'source_analysis'=>1,'auto_attribution'=>1],0],
            ['ads','广告','Ads','Ads','#ef4444','AD',['source_type'=>'ads','promotion_stats'=>1,'source_analysis'=>1,'auto_attribution'=>1],0],
            ['agent','代理商','Agent','代理','#6366f1','AG',['source_type'=>'partner','promotion_stats'=>1,'source_analysis'=>1,'auto_attribution'=>0],0],
            ['distributor','经销商','Distributor','经销','#14b8a6','DS',['source_type'=>'partner','promotion_stats'=>1,'source_analysis'=>1,'auto_attribution'=>0],0],
            ['other','其他','Other','其他','#64748b','OT',['source_type'=>'other','promotion_stats'=>0,'source_analysis'=>1,'auto_attribution'=>0],0],
        ],
        'management_category' => [
            ['project','项目客户','Project Customer','项目','#2563eb','PJ',['need_owner'=>1,'allow_promotion'=>1,'allow_quote'=>1,'show_dashboard'=>1],1],
            ['agent','经销商/代理','Dealer / Agent','代理','#6366f1','AG',['need_owner'=>1,'allow_promotion'=>1,'allow_quote'=>1,'show_dashboard'=>1],0],
            ['designer','设计师/顾问','Designer / Consultant','设计','#8b5cf6','DS',['need_owner'=>1,'allow_promotion'=>1,'allow_quote'=>1,'show_dashboard'=>1],0],
            ['contractor','工程商','Contractor','工程','#0ea5e9','CT',['need_owner'=>1,'allow_promotion'=>1,'allow_quote'=>1,'show_dashboard'=>1],0],
            ['owner','终端业主','End Owner','业主','#059669','EO',['need_owner'=>1,'allow_promotion'=>1,'allow_quote'=>1,'show_dashboard'=>1],0],
            ['paused','暂停跟进','Paused','暂停','#94a3b8','PA',['need_owner'=>0,'allow_promotion'=>0,'allow_quote'=>0,'show_dashboard'=>0],0],
            ['supplier_customer','供应商转客户','Supplier to Customer','供转客','#f59e0b','SC',['need_owner'=>1,'allow_promotion'=>0,'allow_quote'=>1,'show_dashboard'=>1],0],
            ['competitor','竞争对手','Competitor','竞品','#dc2626','CP',['need_owner'=>0,'allow_promotion'=>0,'allow_quote'=>0,'show_dashboard'=>0],0],
            ['other','其他','Other','其他','#64748b','OT',['need_owner'=>0,'allow_promotion'=>1,'allow_quote'=>1,'show_dashboard'=>0],0],
        ],
        'promotion_channel' => [
            ['email','邮件','Email','邮件','#2563eb','@',['bulk'=>1,'auto'=>1,'need_email'=>1,'stats'=>1,'contact_level'=>1,'customer_level'=>1],1],
            ['whatsapp','WhatsApp','WhatsApp','WA','#059669','WA',['bulk'=>1,'auto'=>0,'need_whatsapp'=>1,'stats'=>1,'contact_level'=>1,'customer_level'=>1],0],
            ['wechat','微信','WeChat','微信','#16a34a','WX',['bulk'=>0,'auto'=>0,'need_phone'=>0,'stats'=>1,'contact_level'=>1,'customer_level'=>1],0],
            ['wechat_group','微信群','WeChat Group','微信群','#15803d','群',['bulk'=>1,'auto'=>0,'manual_only'=>1,'stats'=>1,'contact_level'=>0,'customer_level'=>1],0],
            ['whatsapp_group','WhatsApp群','WhatsApp Group','WA群','#047857','WG',['bulk'=>1,'auto'=>0,'manual_only'=>1,'stats'=>1,'contact_level'=>0,'customer_level'=>1],0],
            ['phone','电话','Phone','电话','#f59e0b','☎',['bulk'=>0,'auto'=>0,'need_phone'=>1,'stats'=>1,'contact_level'=>1,'customer_level'=>1],0],
            ['linkedin','LinkedIn','LinkedIn','in','#0a66c2','in',['bulk'=>1,'auto'=>0,'need_linkedin'=>1,'stats'=>1,'contact_level'=>1,'customer_level'=>1],0],
            ['offline','展会/线下','Offline','线下','#7c3aed','OF',['bulk'=>0,'auto'=>0,'stats'=>1,'contact_level'=>0,'customer_level'=>1],0],
            ['edm','EDM','EDM','EDM','#0284c7','EDM',['bulk'=>1,'auto'=>1,'need_email'=>1,'stats'=>1,'contact_level'=>1,'customer_level'=>1],0],
            ['google_ads','Google Ads','Google Ads','Ads','#ef4444','AD',['bulk'=>1,'auto'=>1,'stats'=>1,'contact_level'=>0,'customer_level'=>1],0],
            ['social','社媒','Social','社媒','#8b5cf6','SM',['bulk'=>1,'auto'=>0,'stats'=>1,'contact_level'=>1,'customer_level'=>1],0],
            ['referral','老客户转介绍','Referral','转介','#16a34a','RF',['bulk'=>0,'auto'=>0,'stats'=>1,'contact_level'=>0,'customer_level'=>1],0],
            ['agent_dev','代理商开发','Agent Dev','代理','#6366f1','AG',['bulk'=>0,'auto'=>0,'stats'=>1,'contact_level'=>0,'customer_level'=>1],0],
            ['visit','主动拜访','Visit','拜访','#d97706','V',['bulk'=>0,'auto'=>0,'stats'=>1,'contact_level'=>0,'customer_level'=>1],0],
            ['no_promotion','不推广','No Promotion','不推广','#64748b','NO',['bulk'=>0,'auto'=>0,'stats'=>1,'contact_level'=>1,'customer_level'=>1],0],
            ['maintenance_only','仅维护','Maintenance Only','维护','#94a3b8','MO',['bulk'=>0,'auto'=>0,'stats'=>1,'contact_level'=>1,'customer_level'=>1],0],
        ],
        'promotion_status' => [
            ['not_promoted','未推广','Not Promoted','未推','#64748b','NP',['enter_promotion'=>1,'email'=>1,'whatsapp'=>1,'stats'=>1],1],
            ['promoting','推广中','Promoting','推广中','#2563eb','PG',['enter_promotion'=>1,'email'=>1,'whatsapp'=>1,'stats'=>1],0],
            ['promoted','已推广','Promoted','已推','#059669','PD',['enter_promotion'=>1,'email'=>1,'whatsapp'=>1,'stats'=>1],0],
            ['paused','暂停推广','Paused','暂停','#f59e0b','PA',['enter_promotion'=>0,'email'=>0,'whatsapp'=>0,'stats'=>1],0],
            ['stopped','停止推广','Stopped','停止','#dc2626','ST',['enter_promotion'=>0,'email'=>0,'whatsapp'=>0,'stats'=>1],0],
            ['no_promotion','不推广','No Promotion','不推广','#64748b','NO',['enter_promotion'=>0,'email'=>0,'whatsapp'=>0,'stats'=>1],0],
            ['blacklist','黑名单','Blacklist','黑名单','#111827','BL',['enter_promotion'=>0,'email'=>0,'whatsapp'=>0,'stats'=>0],0],
            ['maintenance_only','仅维护','Maintenance Only','维护','#94a3b8','MO',['enter_promotion'=>0,'email'=>0,'whatsapp'=>0,'stats'=>1],0],
        ],
        'contact_role' => [
            ['decision_maker','决策人','Decision Maker','决策','#dc2626','DM',['key_contact'=>1,'overview'=>1,'promotion_priority'=>100],0],
            ['boss','老板','Boss','老板','#7c3aed','BO',['key_contact'=>1,'overview'=>1,'promotion_priority'=>95],0],
            ['buyer','采购','Buyer','采购','#2563eb','BY',['key_contact'=>1,'overview'=>1,'promotion_priority'=>90],1],
            ['engineer','工程师','Engineer','工程','#0ea5e9','EN',['key_contact'=>1,'overview'=>1,'promotion_priority'=>80],0],
            ['designer','设计师','Designer','设计','#8b5cf6','DS',['key_contact'=>1,'overview'=>1,'promotion_priority'=>75],0],
            ['project_owner','项目负责人','Project Owner','项目','#059669','PO',['key_contact'=>1,'overview'=>1,'promotion_priority'=>85],0],
            ['finance','财务','Finance','财务','#f59e0b','FI',['key_contact'=>0,'overview'=>0,'promotion_priority'=>40],0],
            ['merchandiser','跟单','Merchandiser','跟单','#14b8a6','MC',['key_contact'=>0,'overview'=>0,'promotion_priority'=>60],0],
            ['middleman','中间人','Middleman','中间','#64748b','MM',['key_contact'=>0,'overview'=>0,'promotion_priority'=>50],0],
            ['consultant','顾问','Consultant','顾问','#6366f1','CS',['key_contact'=>0,'overview'=>0,'promotion_priority'=>55],0],
            ['unknown','未知','Unknown','未知','#94a3b8','UN',['key_contact'=>0,'overview'=>0,'promotion_priority'=>10],0],
        ],
        'contact_source' => [
            ['exhibition','展会','Exhibition','展会','#2563eb','EX',[],0], ['website','官网','Website','官网','#0ea5e9','WEB',[],1], ['linkedin','LinkedIn','LinkedIn','in','#0a66c2','in',[],0], ['whatsapp','WhatsApp','WhatsApp','WA','#059669','WA',[],0], ['referral','客户介绍','Referral','介绍','#16a34a','RF',[],0], ['mail','邮件往来','Mail','邮件','#0891b2','@',[],0], ['manual','手动录入','Manual','手动','#64748b','M',[],0], ['import','历史导入','Import','导入','#94a3b8','IM',[],0], ['other','其他','Other','其他','#64748b','OT',[],0],
        ],
        'address_type' => [
            ['HQ','总部','Headquarters','总部','#2563eb','HQ',['defaultable'=>1,'shipping'=>0,'billing'=>1,'visit'=>1],1], ['Office','办公室','Office','办公','#0ea5e9','OF',['defaultable'=>1,'shipping'=>0,'billing'=>1,'visit'=>1],0], ['Factory','工厂','Factory','工厂','#f59e0b','FC',['defaultable'=>1,'shipping'=>1,'billing'=>0,'visit'=>1],0], ['Warehouse','仓库','Warehouse','仓库','#059669','WH',['defaultable'=>1,'shipping'=>1,'billing'=>0,'visit'=>1],0], ['Store','门店','Store','门店','#8b5cf6','ST',['defaultable'=>1,'shipping'=>1,'billing'=>0,'visit'=>1],0], ['Project','项目地址','Project Site','项目','#7c3aed','PJ',['defaultable'=>0,'shipping'=>1,'billing'=>0,'visit'=>1],0], ['Shipping','收货地址','Shipping','收货','#14b8a6','SH',['defaultable'=>0,'shipping'=>1,'billing'=>0,'visit'=>0],0], ['Billing','账单地址','Billing','账单','#6366f1','BI',['defaultable'=>0,'shipping'=>0,'billing'=>1,'visit'=>0],0], ['Other','其他','Other','其他','#64748b','OT',['defaultable'=>1,'shipping'=>0,'billing'=>0,'visit'=>0],0],
        ],
        'owner_role' => [
            ['primary','第一负责人','Primary Owner','主责','#dc2626','P',['permission_level'=>100,'edit'=>1,'view'=>1,'quote'=>1,'cost'=>0,'assign'=>1,'delete'=>1,'primary'=>1],1],
            ['secondary','协助负责人','Secondary Owner','协助','#2563eb','S',['permission_level'=>80,'edit'=>1,'view'=>1,'quote'=>1,'cost'=>0,'assign'=>0,'delete'=>0,'primary'=>0],0],
            ['collaborator','协作人','Collaborator','协作','#059669','C',['permission_level'=>50,'edit'=>0,'view'=>1,'quote'=>0,'cost'=>0,'assign'=>0,'delete'=>0,'primary'=>0],0],
            ['viewer','只读人员','Viewer','只读','#64748b','V',['permission_level'=>20,'edit'=>0,'view'=>1,'quote'=>0,'cost'=>0,'assign'=>0,'delete'=>0,'primary'=>0],0],
            ['merchandiser','跟单负责人','Merchandiser','跟单','#14b8a6','M',['permission_level'=>70,'edit'=>1,'view'=>1,'quote'=>0,'cost'=>0,'assign'=>0,'delete'=>0,'primary'=>0],0],
            ['technical','技术负责人','Technical Owner','技术','#0ea5e9','T',['permission_level'=>70,'edit'=>0,'view'=>1,'quote'=>0,'cost'=>0,'assign'=>0,'delete'=>0,'primary'=>0],0],
            ['quote_owner','报价负责人','Quote Owner','报价','#8b5cf6','Q',['permission_level'=>70,'edit'=>0,'view'=>1,'quote'=>1,'cost'=>0,'assign'=>0,'delete'=>0,'primary'=>0],0],
            ['service_owner','售后负责人','Service Owner','售后','#f59e0b','SV',['permission_level'=>60,'edit'=>0,'view'=>1,'quote'=>0,'cost'=>0,'assign'=>0,'delete'=>0,'primary'=>0],0],
        ],
        'customer_status' => [
            ['lead','线索','Lead','线索','#64748b','L',[],1], ['official','正式客户','Official','正式','#2563eb','O',[],0], ['active','活跃客户','Active','活跃','#059669','A',[],0], ['paused','暂停跟进','Paused','暂停','#f59e0b','P',[],0], ['deal','成交客户','Deal','成交','#7c3aed','D',[],0], ['lost','流失客户','Lost','流失','#94a3b8','LO',[],0], ['blacklist','黑名单','Blacklist','黑名单','#111827','BL',[],0], ['deleted','已删除','Deleted','删除','#dc2626','DEL',[],0],
        ],
        'customer_lifecycle' => [
            ['lead','线索','Lead','线索','#64748b','L',[],1], ['temp','暂存','Temporary','暂存','#94a3b8','T',[],0], ['pool','暂存池','Lead Pool','池','#94a3b8','P',[],0], ['pending_confirm','待确认','Pending Confirm','待确','#f59e0b','PC',[],0], ['active','活跃客户','Active','活跃','#059669','A',[],0], ['official','正式客户','Official Customer','正式','#2563eb','OC',[],0], ['key_follow','重点跟进','Key Follow-up','重点','#7c3aed','KF',[],0], ['quoting','报价中','Quoting','报价','#0ea5e9','Q',[],0], ['sampling','样品中','Sampling','样品','#14b8a6','S',[],0], ['deal','成交客户','Deal Customer','成交','#059669','D',[],0], ['dormant','沉睡客户','Dormant','沉睡','#64748b','DM',[],0], ['sleeping','沉睡客户','Sleeping','沉睡','#64748b','SL',[],0], ['lost','流失客户','Lost','流失','#94a3b8','LO',[],0], ['blacklist','黑名单','Blacklist','黑名单','#111827','BL',[],0],
        ],
        'customer_risk_level' => [
            ['healthy','健康','Healthy','健康','#059669','OK',[],1], ['warning','预警','Warning','预警','#f59e0b','WA',[],0], ['risky','高风险','Risky','风险','#dc2626','RK',[],0], ['cold','冷客户','Cold','冷','#64748b','CD',[],0],
        ],
        'customer_relation_type' => [
            ['parent','母公司','Parent Company','母','#2563eb','P',[],0], ['subsidiary','子公司','Subsidiary','子','#0ea5e9','S',[],0], ['same_group','同集团','Same Group','集团','#6366f1','G',[],0], ['agent','代理商','Agent','代理','#7c3aed','AG',[],0], ['distributor','经销商','Distributor','经销','#14b8a6','DS',[],0], ['designer','设计方','Designer','设计','#8b5cf6','DE',[],0], ['contractor','工程方','Contractor','工程','#f59e0b','CT',[],0], ['owner','业主','Owner','业主','#059669','OW',[],0], ['partner','合作伙伴','Partner','伙伴','#16a34a','PA',[],0], ['competitor','竞争关系','Competitor','竞争','#dc2626','CP',[],0], ['other','其他','Other','其他','#64748b','OT',[],1],
        ],
        'customer_event_type' => [
            ['first_contact','第一次接触','First Contact','首触','#2563eb','FC',[],1], ['exhibition_met','展会认识','Met at Exhibition','展会','#7c3aed','EX',[],0], ['next_visit','下次拜访','Next Visit','拜访','#f59e0b','V',[],0], ['sample_sent','样品寄出','Sample Sent','样品','#14b8a6','S',[],0], ['quote_expiry','报价有效期','Quote Expiry','报价','#0ea5e9','Q',[],0], ['purchase_time','预计采购时间','Expected Purchase','采购','#059669','PO',[],0], ['birthday','客户生日','Birthday','生日','#ec4899','BD',[],0], ['anniversary','公司周年','Company Anniversary','周年','#6366f1','AN',[],0], ['project_milestone','项目节点','Project Milestone','节点','#8b5cf6','MS',[],0], ['other','其他','Other','其他','#64748b','OT',[],0],
        ],
        'followup_type' => [
            ['mail','邮件','Email','邮件','#2563eb','@',[],1], ['phone','电话','Phone','电话','#f59e0b','☎',[],0], ['whatsapp','WhatsApp','WhatsApp','WA','#059669','WA',[],0], ['wechat','微信','WeChat','微信','#16a34a','WX',[],0], ['visit','拜访','Visit','拜访','#d97706','V',[],0], ['exhibition','展会','Exhibition','展会','#7c3aed','EX',[],0], ['quote','报价','Quote','报价','#8b5cf6','Q',[],0], ['sample','样品','Sample','样品','#0ea5e9','S',[],0], ['service','售后','After-sales','售后','#dc2626','AS',[],0], ['meeting','会议','Meeting','会议','#6366f1','MT',[],0], ['other','其他','Other','其他','#64748b','OT',[],0],
        ],
        'country_region' => [
            ['IN','印度','India','India','#f59e0b','IN',['iso'=>'IN','phone_code'=>'+91','region'=>'Asia','pinned'=>1],0],
            ['KR','韩国','South Korea','Korea','#2563eb','KR',['iso'=>'KR','phone_code'=>'+82','region'=>'Asia','pinned'=>1],0],
            ['AE','阿联酋 / 迪拜','United Arab Emirates / Dubai','UAE','#059669','AE',['iso'=>'AE','phone_code'=>'+971','region'=>'Middle East','pinned'=>1],0],
            ['SA','沙特阿拉伯','Saudi Arabia','Saudi','#16a34a','SA',['iso'=>'SA','phone_code'=>'+966','region'=>'Middle East','pinned'=>1],0],
            ['DE','德国','Germany','Germany','#111827','DE',['iso'=>'DE','phone_code'=>'+49','region'=>'Europe','pinned'=>1],0],
            ['US','美国','United States','USA','#2563eb','US',['iso'=>'US','phone_code'=>'+1','region'=>'North America','pinned'=>1],0],
            ['GB','英国','United Kingdom','UK','#1d4ed8','GB',['iso'=>'GB','phone_code'=>'+44','region'=>'Europe','pinned'=>1],0],
            ['CN','中国','China','China','#dc2626','CN',['iso'=>'CN','phone_code'=>'+86','region'=>'Asia','pinned'=>1],0],
            ['HK','中国香港','Hong Kong','HK','#ef4444','HK',['iso'=>'HK','phone_code'=>'+852','region'=>'Asia','pinned'=>1],0],
            ['TW','中国台湾','Taiwan','Taiwan','#ef4444','TW',['iso'=>'TW','phone_code'=>'+886','region'=>'Asia','pinned'=>0],0],
            ['JP','日本','Japan','Japan','#ef4444','JP',['iso'=>'JP','phone_code'=>'+81','region'=>'Asia','pinned'=>0],0],
            ['SG','新加坡','Singapore','Singapore','#dc2626','SG',['iso'=>'SG','phone_code'=>'+65','region'=>'Asia','pinned'=>1],0],
            ['MY','马来西亚','Malaysia','Malaysia','#0ea5e9','MY',['iso'=>'MY','phone_code'=>'+60','region'=>'Asia','pinned'=>0],0],
            ['TH','泰国','Thailand','Thailand','#2563eb','TH',['iso'=>'TH','phone_code'=>'+66','region'=>'Asia','pinned'=>0],0],
            ['VN','越南','Vietnam','Vietnam','#dc2626','VN',['iso'=>'VN','phone_code'=>'+84','region'=>'Asia','pinned'=>0],0],
            ['ID','印度尼西亚','Indonesia','Indonesia','#ef4444','ID',['iso'=>'ID','phone_code'=>'+62','region'=>'Asia','pinned'=>0],0],
            ['PH','菲律宾','Philippines','Philippines','#2563eb','PH',['iso'=>'PH','phone_code'=>'+63','region'=>'Asia','pinned'=>0],0],
            ['BD','孟加拉','Bangladesh','Bangladesh','#16a34a','BD',['iso'=>'BD','phone_code'=>'+880','region'=>'Asia','pinned'=>0],0],
            ['PK','巴基斯坦','Pakistan','Pakistan','#059669','PK',['iso'=>'PK','phone_code'=>'+92','region'=>'Asia','pinned'=>0],0],
            ['LK','斯里兰卡','Sri Lanka','Sri Lanka','#f59e0b','LK',['iso'=>'LK','phone_code'=>'+94','region'=>'Asia','pinned'=>0],0],
            ['NP','尼泊尔','Nepal','Nepal','#dc2626','NP',['iso'=>'NP','phone_code'=>'+977','region'=>'Asia','pinned'=>0],0],
            ['MM','缅甸','Myanmar','Myanmar','#f59e0b','MM',['iso'=>'MM','phone_code'=>'+95','region'=>'Asia','pinned'=>0],0],
            ['KH','柬埔寨','Cambodia','Cambodia','#2563eb','KH',['iso'=>'KH','phone_code'=>'+855','region'=>'Asia','pinned'=>0],0],
            ['LA','老挝','Laos','Laos','#2563eb','LA',['iso'=>'LA','phone_code'=>'+856','region'=>'Asia','pinned'=>0],0],
            ['MN','蒙古','Mongolia','Mongolia','#0ea5e9','MN',['iso'=>'MN','phone_code'=>'+976','region'=>'Asia','pinned'=>0],0],
            ['KZ','哈萨克斯坦','Kazakhstan','Kazakhstan','#0ea5e9','KZ',['iso'=>'KZ','phone_code'=>'+7','region'=>'Central Asia','pinned'=>0],0],
            ['UZ','乌兹别克斯坦','Uzbekistan','Uzbekistan','#059669','UZ',['iso'=>'UZ','phone_code'=>'+998','region'=>'Central Asia','pinned'=>0],0],
            ['KG','吉尔吉斯斯坦','Kyrgyzstan','Kyrgyzstan','#dc2626','KG',['iso'=>'KG','phone_code'=>'+996','region'=>'Central Asia','pinned'=>0],0],
            ['TJ','塔吉克斯坦','Tajikistan','Tajikistan','#16a34a','TJ',['iso'=>'TJ','phone_code'=>'+992','region'=>'Central Asia','pinned'=>0],0],
            ['TM','土库曼斯坦','Turkmenistan','Turkmenistan','#059669','TM',['iso'=>'TM','phone_code'=>'+993','region'=>'Central Asia','pinned'=>0],0],
            ['TR','土耳其','Turkey','Turkey','#dc2626','TR',['iso'=>'TR','phone_code'=>'+90','region'=>'Middle East','pinned'=>1],0],
            ['QA','卡塔尔','Qatar','Qatar','#7c3aed','QA',['iso'=>'QA','phone_code'=>'+974','region'=>'Middle East','pinned'=>0],0],
            ['KW','科威特','Kuwait','Kuwait','#16a34a','KW',['iso'=>'KW','phone_code'=>'+965','region'=>'Middle East','pinned'=>0],0],
            ['OM','阿曼','Oman','Oman','#dc2626','OM',['iso'=>'OM','phone_code'=>'+968','region'=>'Middle East','pinned'=>0],0],
            ['BH','巴林','Bahrain','Bahrain','#dc2626','BH',['iso'=>'BH','phone_code'=>'+973','region'=>'Middle East','pinned'=>0],0],
            ['IL','以色列','Israel','Israel','#2563eb','IL',['iso'=>'IL','phone_code'=>'+972','region'=>'Middle East','pinned'=>0],0],
            ['JO','约旦','Jordan','Jordan','#16a34a','JO',['iso'=>'JO','phone_code'=>'+962','region'=>'Middle East','pinned'=>0],0],
            ['LB','黎巴嫩','Lebanon','Lebanon','#dc2626','LB',['iso'=>'LB','phone_code'=>'+961','region'=>'Middle East','pinned'=>0],0],
            ['EG','埃及','Egypt','Egypt','#dc2626','EG',['iso'=>'EG','phone_code'=>'+20','region'=>'Middle East','pinned'=>0],0],
            ['IR','伊朗','Iran','Iran','#059669','IR',['iso'=>'IR','phone_code'=>'+98','region'=>'Middle East','pinned'=>0],0],
            ['IQ','伊拉克','Iraq','Iraq','#16a34a','IQ',['iso'=>'IQ','phone_code'=>'+964','region'=>'Middle East','pinned'=>0],0],
            ['FR','法国','France','France','#2563eb','FR',['iso'=>'FR','phone_code'=>'+33','region'=>'Europe','pinned'=>0],0],
            ['IT','意大利','Italy','Italy','#16a34a','IT',['iso'=>'IT','phone_code'=>'+39','region'=>'Europe','pinned'=>0],0],
            ['ES','西班牙','Spain','Spain','#f59e0b','ES',['iso'=>'ES','phone_code'=>'+34','region'=>'Europe','pinned'=>0],0],
            ['PT','葡萄牙','Portugal','Portugal','#16a34a','PT',['iso'=>'PT','phone_code'=>'+351','region'=>'Europe','pinned'=>0],0],
            ['NL','荷兰','Netherlands','Netherlands','#f97316','NL',['iso'=>'NL','phone_code'=>'+31','region'=>'Europe','pinned'=>0],0],
            ['BE','比利时','Belgium','Belgium','#111827','BE',['iso'=>'BE','phone_code'=>'+32','region'=>'Europe','pinned'=>0],0],
            ['LU','卢森堡','Luxembourg','Luxembourg','#0ea5e9','LU',['iso'=>'LU','phone_code'=>'+352','region'=>'Europe','pinned'=>0],0],
            ['CH','瑞士','Switzerland','Switzerland','#dc2626','CH',['iso'=>'CH','phone_code'=>'+41','region'=>'Europe','pinned'=>0],0],
            ['AT','奥地利','Austria','Austria','#dc2626','AT',['iso'=>'AT','phone_code'=>'+43','region'=>'Europe','pinned'=>0],0],
            ['IE','爱尔兰','Ireland','Ireland','#16a34a','IE',['iso'=>'IE','phone_code'=>'+353','region'=>'Europe','pinned'=>0],0],
            ['DK','丹麦','Denmark','Denmark','#dc2626','DK',['iso'=>'DK','phone_code'=>'+45','region'=>'Europe','pinned'=>0],0],
            ['SE','瑞典','Sweden','Sweden','#0ea5e9','SE',['iso'=>'SE','phone_code'=>'+46','region'=>'Europe','pinned'=>0],0],
            ['NO','挪威','Norway','Norway','#2563eb','NO',['iso'=>'NO','phone_code'=>'+47','region'=>'Europe','pinned'=>0],0],
            ['FI','芬兰','Finland','Finland','#2563eb','FI',['iso'=>'FI','phone_code'=>'+358','region'=>'Europe','pinned'=>0],0],
            ['IS','冰岛','Iceland','Iceland','#2563eb','IS',['iso'=>'IS','phone_code'=>'+354','region'=>'Europe','pinned'=>0],0],
            ['PL','波兰','Poland','Poland','#dc2626','PL',['iso'=>'PL','phone_code'=>'+48','region'=>'Europe','pinned'=>0],0],
            ['CZ','捷克','Czech Republic','Czech','#2563eb','CZ',['iso'=>'CZ','phone_code'=>'+420','region'=>'Europe','pinned'=>0],0],
            ['SK','斯洛伐克','Slovakia','Slovakia','#2563eb','SK',['iso'=>'SK','phone_code'=>'+421','region'=>'Europe','pinned'=>0],0],
            ['HU','匈牙利','Hungary','Hungary','#16a34a','HU',['iso'=>'HU','phone_code'=>'+36','region'=>'Europe','pinned'=>0],0],
            ['RO','罗马尼亚','Romania','Romania','#2563eb','RO',['iso'=>'RO','phone_code'=>'+40','region'=>'Europe','pinned'=>0],0],
            ['BG','保加利亚','Bulgaria','Bulgaria','#16a34a','BG',['iso'=>'BG','phone_code'=>'+359','region'=>'Europe','pinned'=>0],0],
            ['GR','希腊','Greece','Greece','#2563eb','GR',['iso'=>'GR','phone_code'=>'+30','region'=>'Europe','pinned'=>0],0],
            ['HR','克罗地亚','Croatia','Croatia','#2563eb','HR',['iso'=>'HR','phone_code'=>'+385','region'=>'Europe','pinned'=>0],0],
            ['SI','斯洛文尼亚','Slovenia','Slovenia','#2563eb','SI',['iso'=>'SI','phone_code'=>'+386','region'=>'Europe','pinned'=>0],0],
            ['RS','塞尔维亚','Serbia','Serbia','#2563eb','RS',['iso'=>'RS','phone_code'=>'+381','region'=>'Europe','pinned'=>0],0],
            ['UA','乌克兰','Ukraine','Ukraine','#0ea5e9','UA',['iso'=>'UA','phone_code'=>'+380','region'=>'Europe','pinned'=>0],0],
            ['RU','俄罗斯','Russia','Russia','#2563eb','RU',['iso'=>'RU','phone_code'=>'+7','region'=>'Europe / Asia','pinned'=>0],0],
            ['CA','加拿大','Canada','Canada','#dc2626','CA',['iso'=>'CA','phone_code'=>'+1','region'=>'North America','pinned'=>0],0],
            ['MX','墨西哥','Mexico','Mexico','#16a34a','MX',['iso'=>'MX','phone_code'=>'+52','region'=>'North America','pinned'=>0],0],
            ['BR','巴西','Brazil','Brazil','#16a34a','BR',['iso'=>'BR','phone_code'=>'+55','region'=>'South America','pinned'=>0],0],
            ['AR','阿根廷','Argentina','Argentina','#0ea5e9','AR',['iso'=>'AR','phone_code'=>'+54','region'=>'South America','pinned'=>0],0],
            ['CL','智利','Chile','Chile','#dc2626','CL',['iso'=>'CL','phone_code'=>'+56','region'=>'South America','pinned'=>0],0],
            ['CO','哥伦比亚','Colombia','Colombia','#f59e0b','CO',['iso'=>'CO','phone_code'=>'+57','region'=>'South America','pinned'=>0],0],
            ['PE','秘鲁','Peru','Peru','#dc2626','PE',['iso'=>'PE','phone_code'=>'+51','region'=>'South America','pinned'=>0],0],
            ['EC','厄瓜多尔','Ecuador','Ecuador','#f59e0b','EC',['iso'=>'EC','phone_code'=>'+593','region'=>'South America','pinned'=>0],0],
            ['UY','乌拉圭','Uruguay','Uruguay','#0ea5e9','UY',['iso'=>'UY','phone_code'=>'+598','region'=>'South America','pinned'=>0],0],
            ['PY','巴拉圭','Paraguay','Paraguay','#dc2626','PY',['iso'=>'PY','phone_code'=>'+595','region'=>'South America','pinned'=>0],0],
            ['BO','玻利维亚','Bolivia','Bolivia','#16a34a','BO',['iso'=>'BO','phone_code'=>'+591','region'=>'South America','pinned'=>0],0],
            ['VE','委内瑞拉','Venezuela','Venezuela','#f59e0b','VE',['iso'=>'VE','phone_code'=>'+58','region'=>'South America','pinned'=>0],0],
            ['PA','巴拿马','Panama','Panama','#2563eb','PA',['iso'=>'PA','phone_code'=>'+507','region'=>'Central America','pinned'=>0],0],
            ['CR','哥斯达黎加','Costa Rica','Costa Rica','#2563eb','CR',['iso'=>'CR','phone_code'=>'+506','region'=>'Central America','pinned'=>0],0],
            ['GT','危地马拉','Guatemala','Guatemala','#2563eb','GT',['iso'=>'GT','phone_code'=>'+502','region'=>'Central America','pinned'=>0],0],
            ['DO','多米尼加','Dominican Republic','Dominican','#2563eb','DO',['iso'=>'DO','phone_code'=>'+1-809','region'=>'Caribbean','pinned'=>0],0],
            ['ZA','南非','South Africa','South Africa','#16a34a','ZA',['iso'=>'ZA','phone_code'=>'+27','region'=>'Africa','pinned'=>0],0],
            ['NG','尼日利亚','Nigeria','Nigeria','#16a34a','NG',['iso'=>'NG','phone_code'=>'+234','region'=>'Africa','pinned'=>0],0],
            ['KE','肯尼亚','Kenya','Kenya','#111827','KE',['iso'=>'KE','phone_code'=>'+254','region'=>'Africa','pinned'=>0],0],
            ['TZ','坦桑尼亚','Tanzania','Tanzania','#16a34a','TZ',['iso'=>'TZ','phone_code'=>'+255','region'=>'Africa','pinned'=>0],0],
            ['UG','乌干达','Uganda','Uganda','#f59e0b','UG',['iso'=>'UG','phone_code'=>'+256','region'=>'Africa','pinned'=>0],0],
            ['ET','埃塞俄比亚','Ethiopia','Ethiopia','#16a34a','ET',['iso'=>'ET','phone_code'=>'+251','region'=>'Africa','pinned'=>0],0],
            ['GH','加纳','Ghana','Ghana','#f59e0b','GH',['iso'=>'GH','phone_code'=>'+233','region'=>'Africa','pinned'=>0],0],
            ['CI','科特迪瓦','Ivory Coast','Ivory Coast','#f59e0b','CI',['iso'=>'CI','phone_code'=>'+225','region'=>'Africa','pinned'=>0],0],
            ['SN','塞内加尔','Senegal','Senegal','#16a34a','SN',['iso'=>'SN','phone_code'=>'+221','region'=>'Africa','pinned'=>0],0],
            ['MA','摩洛哥','Morocco','Morocco','#dc2626','MA',['iso'=>'MA','phone_code'=>'+212','region'=>'Africa','pinned'=>0],0],
            ['DZ','阿尔及利亚','Algeria','Algeria','#16a34a','DZ',['iso'=>'DZ','phone_code'=>'+213','region'=>'Africa','pinned'=>0],0],
            ['TN','突尼斯','Tunisia','Tunisia','#dc2626','TN',['iso'=>'TN','phone_code'=>'+216','region'=>'Africa','pinned'=>0],0],
            ['LY','利比亚','Libya','Libya','#16a34a','LY',['iso'=>'LY','phone_code'=>'+218','region'=>'Africa','pinned'=>0],0],
            ['AO','安哥拉','Angola','Angola','#dc2626','AO',['iso'=>'AO','phone_code'=>'+244','region'=>'Africa','pinned'=>0],0],
            ['ZM','赞比亚','Zambia','Zambia','#16a34a','ZM',['iso'=>'ZM','phone_code'=>'+260','region'=>'Africa','pinned'=>0],0],
            ['ZW','津巴布韦','Zimbabwe','Zimbabwe','#16a34a','ZW',['iso'=>'ZW','phone_code'=>'+263','region'=>'Africa','pinned'=>0],0],
            ['MZ','莫桑比克','Mozambique','Mozambique','#16a34a','MZ',['iso'=>'MZ','phone_code'=>'+258','region'=>'Africa','pinned'=>0],0],
            ['MU','毛里求斯','Mauritius','Mauritius','#0ea5e9','MU',['iso'=>'MU','phone_code'=>'+230','region'=>'Africa','pinned'=>0],0],
            ['AU','澳大利亚','Australia','Australia','#2563eb','AU',['iso'=>'AU','phone_code'=>'+61','region'=>'Oceania','pinned'=>0],0],
            ['NZ','新西兰','New Zealand','New Zealand','#111827','NZ',['iso'=>'NZ','phone_code'=>'+64','region'=>'Oceania','pinned'=>0],0],
            ['FJ','斐济','Fiji','Fiji','#2563eb','FJ',['iso'=>'FJ','phone_code'=>'+679','region'=>'Oceania','pinned'=>0],0],
        ],
        'city_region' => [
            ['HK_HONG_KONG','香港','Hong Kong','HK','#ef4444','HK',['country'=>'HK','region'=>'Asia','type'=>'city','aliases'=>['Hong Kong','香港','HK'],'pinned'=>1],1],
            ['CN_GUANGDONG','广东','Guangdong','Guangdong','#dc2626','GD',['country'=>'CN','region'=>'Asia','type'=>'province','aliases'=>['Guangdong','广东','GD'],'pinned'=>1],0],
            ['CN_SHENZHEN','深圳','Shenzhen','Shenzhen','#dc2626','SZ',['country'=>'CN','region'=>'Asia','type'=>'city','aliases'=>['Shenzhen','深圳','SZ'],'pinned'=>1],0],
            ['CN_GUANGZHOU','广州','Guangzhou','Guangzhou','#dc2626','GZ',['country'=>'CN','region'=>'Asia','type'=>'city','aliases'=>['Guangzhou','广州','GZ'],'pinned'=>1],0],
            ['CN_SHANGHAI','上海','Shanghai','Shanghai','#dc2626','SH',['country'=>'CN','region'=>'Asia','type'=>'city','aliases'=>['Shanghai','上海','SH'],'pinned'=>1],0],
            ['CN_BEIJING','北京','Beijing','Beijing','#dc2626','BJ',['country'=>'CN','region'=>'Asia','type'=>'city','aliases'=>['Beijing','北京','BJ'],'pinned'=>0],0],
            ['CN_ZHEJIANG','浙江','Zhejiang','Zhejiang','#dc2626','ZJ',['country'=>'CN','region'=>'Asia','type'=>'province','aliases'=>['Zhejiang','浙江','ZJ'],'pinned'=>0],0],
            ['CN_JIANGSU','江苏','Jiangsu','Jiangsu','#dc2626','JS',['country'=>'CN','region'=>'Asia','type'=>'province','aliases'=>['Jiangsu','江苏','JS'],'pinned'=>0],0],
            ['TW_TAIPEI','台北','Taipei','Taipei','#ef4444','TPE',['country'=>'TW','region'=>'Asia','type'=>'city','aliases'=>['Taipei','台北','TPE'],'pinned'=>0],0],
            ['IN_MUMBAI','孟买','Mumbai','Mumbai','#f59e0b','BOM',['country'=>'IN','region'=>'Asia','type'=>'city','aliases'=>['Mumbai','Bombay','孟买','BOM'],'pinned'=>1],0],
            ['IN_DELHI_NCR','德里 NCR','Delhi NCR','Delhi NCR','#f59e0b','DEL',['country'=>'IN','region'=>'Asia','type'=>'metro','aliases'=>['Delhi','New Delhi','Delhi NCR','德里','DEL'],'pinned'=>1],0],
            ['IN_BENGALURU','班加罗尔','Bengaluru','Bengaluru','#f59e0b','BLR',['country'=>'IN','region'=>'Asia','type'=>'city','aliases'=>['Bengaluru','Bangalore','班加罗尔','BLR'],'pinned'=>1],0],
            ['IN_CHENNAI','金奈','Chennai','Chennai','#f59e0b','MAA',['country'=>'IN','region'=>'Asia','type'=>'city','aliases'=>['Chennai','Madras','金奈','MAA'],'pinned'=>1],0],
            ['IN_HYDERABAD','海得拉巴','Hyderabad','Hyderabad','#f59e0b','HYD',['country'=>'IN','region'=>'Asia','type'=>'city','aliases'=>['Hyderabad','海得拉巴','HYD'],'pinned'=>0],0],
            ['IN_PUNE','浦那','Pune','Pune','#f59e0b','PNQ',['country'=>'IN','region'=>'Asia','type'=>'city','aliases'=>['Pune','浦那','PNQ'],'pinned'=>0],0],
            ['IN_AHMEDABAD','艾哈迈达巴德','Ahmedabad','Ahmedabad','#f59e0b','AMD',['country'=>'IN','region'=>'Asia','type'=>'city','aliases'=>['Ahmedabad','艾哈迈达巴德','AMD'],'pinned'=>0],0],
            ['IN_KOLKATA','加尔各答','Kolkata','Kolkata','#f59e0b','CCU',['country'=>'IN','region'=>'Asia','type'=>'city','aliases'=>['Kolkata','Calcutta','加尔各答','CCU'],'pinned'=>0],0],
            ['IN_KERALA','喀拉拉','Kerala','Kerala','#f59e0b','KL',['country'=>'IN','region'=>'Asia','type'=>'state','aliases'=>['Kerala','喀拉拉','KL'],'pinned'=>0],0],
            ['IN_GUJARAT','古吉拉特','Gujarat','Gujarat','#f59e0b','GJ',['country'=>'IN','region'=>'Asia','type'=>'state','aliases'=>['Gujarat','古吉拉特','GJ'],'pinned'=>0],0],
            ['IN_MAHARASHTRA','马哈拉施特拉','Maharashtra','Maharashtra','#f59e0b','MH',['country'=>'IN','region'=>'Asia','type'=>'state','aliases'=>['Maharashtra','马哈拉施特拉','MH'],'pinned'=>0],0],
            ['KR_SEOUL','首尔','Seoul','Seoul','#2563eb','SEL',['country'=>'KR','region'=>'Asia','type'=>'city','aliases'=>['Seoul','首尔','SEL'],'pinned'=>1],0],
            ['KR_BUSAN','釜山','Busan','Busan','#2563eb','PUS',['country'=>'KR','region'=>'Asia','type'=>'city','aliases'=>['Busan','釜山','PUS'],'pinned'=>0],0],
            ['JP_TOKYO','东京','Tokyo','Tokyo','#ef4444','TYO',['country'=>'JP','region'=>'Asia','type'=>'city','aliases'=>['Tokyo','东京','TYO'],'pinned'=>0],0],
            ['JP_OSAKA','大阪','Osaka','Osaka','#ef4444','OSA',['country'=>'JP','region'=>'Asia','type'=>'city','aliases'=>['Osaka','大阪','OSA'],'pinned'=>0],0],
            ['SG_SINGAPORE','新加坡','Singapore','Singapore','#dc2626','SIN',['country'=>'SG','region'=>'Asia','type'=>'city','aliases'=>['Singapore','新加坡','SIN'],'pinned'=>1],0],
            ['MY_KUALA_LUMPUR','吉隆坡','Kuala Lumpur','Kuala Lumpur','#0ea5e9','KUL',['country'=>'MY','region'=>'Asia','type'=>'city','aliases'=>['Kuala Lumpur','吉隆坡','KL','KUL'],'pinned'=>0],0],
            ['MY_PENANG','槟城','Penang','Penang','#0ea5e9','PEN',['country'=>'MY','region'=>'Asia','type'=>'state','aliases'=>['Penang','槟城','PEN'],'pinned'=>0],0],
            ['TH_BANGKOK','曼谷','Bangkok','Bangkok','#2563eb','BKK',['country'=>'TH','region'=>'Asia','type'=>'city','aliases'=>['Bangkok','曼谷','BKK'],'pinned'=>0],0],
            ['VN_HO_CHI_MINH','胡志明市','Ho Chi Minh City','Ho Chi Minh','#dc2626','SGN',['country'=>'VN','region'=>'Asia','type'=>'city','aliases'=>['Ho Chi Minh','Saigon','胡志明','SGN'],'pinned'=>0],0],
            ['VN_HANOI','河内','Hanoi','Hanoi','#dc2626','HAN',['country'=>'VN','region'=>'Asia','type'=>'city','aliases'=>['Hanoi','河内','HAN'],'pinned'=>0],0],
            ['ID_JAKARTA','雅加达','Jakarta','Jakarta','#ef4444','JKT',['country'=>'ID','region'=>'Asia','type'=>'city','aliases'=>['Jakarta','雅加达','JKT'],'pinned'=>0],0],
            ['ID_SURABAYA','泗水','Surabaya','Surabaya','#ef4444','SUB',['country'=>'ID','region'=>'Asia','type'=>'city','aliases'=>['Surabaya','泗水','SUB'],'pinned'=>0],0],
            ['PH_MANILA','马尼拉','Manila','Manila','#2563eb','MNL',['country'=>'PH','region'=>'Asia','type'=>'city','aliases'=>['Manila','马尼拉','MNL'],'pinned'=>0],0],
            ['PH_CEBU','宿务','Cebu','Cebu','#2563eb','CEB',['country'=>'PH','region'=>'Asia','type'=>'city','aliases'=>['Cebu','宿务','CEB'],'pinned'=>0],0],
            ['AE_DUBAI','迪拜','Dubai','Dubai','#059669','DXB',['country'=>'AE','region'=>'Middle East','type'=>'city','aliases'=>['Dubai','迪拜','DXB'],'pinned'=>1],0],
            ['AE_ABU_DHABI','阿布扎比','Abu Dhabi','Abu Dhabi','#059669','AUH',['country'=>'AE','region'=>'Middle East','type'=>'city','aliases'=>['Abu Dhabi','阿布扎比','AUH'],'pinned'=>1],0],
            ['AE_SHARJAH','沙迦','Sharjah','Sharjah','#059669','SHJ',['country'=>'AE','region'=>'Middle East','type'=>'city','aliases'=>['Sharjah','沙迦','SHJ'],'pinned'=>0],0],
            ['SA_RIYADH','利雅得','Riyadh','Riyadh','#16a34a','RUH',['country'=>'SA','region'=>'Middle East','type'=>'city','aliases'=>['Riyadh','利雅得','RUH'],'pinned'=>1],0],
            ['SA_JEDDAH','吉达','Jeddah','Jeddah','#16a34a','JED',['country'=>'SA','region'=>'Middle East','type'=>'city','aliases'=>['Jeddah','吉达','JED'],'pinned'=>1],0],
            ['SA_DAMMAM','达曼','Dammam','Dammam','#16a34a','DMM',['country'=>'SA','region'=>'Middle East','type'=>'city','aliases'=>['Dammam','达曼','DMM'],'pinned'=>0],0],
            ['QA_DOHA','多哈','Doha','Doha','#7c3aed','DOH',['country'=>'QA','region'=>'Middle East','type'=>'city','aliases'=>['Doha','多哈','DOH'],'pinned'=>0],0],
            ['KW_KUWAIT_CITY','科威特城','Kuwait City','Kuwait City','#16a34a','KWI',['country'=>'KW','region'=>'Middle East','type'=>'city','aliases'=>['Kuwait City','科威特城','KWI'],'pinned'=>0],0],
            ['OM_MUSCAT','马斯喀特','Muscat','Muscat','#dc2626','MCT',['country'=>'OM','region'=>'Middle East','type'=>'city','aliases'=>['Muscat','马斯喀特','MCT'],'pinned'=>0],0],
            ['BH_MANAMA','麦纳麦','Manama','Manama','#dc2626','BAH',['country'=>'BH','region'=>'Middle East','type'=>'city','aliases'=>['Manama','麦纳麦','BAH'],'pinned'=>0],0],
            ['TR_ISTANBUL','伊斯坦布尔','Istanbul','Istanbul','#dc2626','IST',['country'=>'TR','region'=>'Middle East','type'=>'city','aliases'=>['Istanbul','伊斯坦布尔','IST'],'pinned'=>0],0],
            ['TR_ANKARA','安卡拉','Ankara','Ankara','#dc2626','ANK',['country'=>'TR','region'=>'Middle East','type'=>'city','aliases'=>['Ankara','安卡拉','ANK'],'pinned'=>0],0],
            ['EG_CAIRO','开罗','Cairo','Cairo','#dc2626','CAI',['country'=>'EG','region'=>'Middle East','type'=>'city','aliases'=>['Cairo','开罗','CAI'],'pinned'=>0],0],
            ['JO_AMMAN','安曼','Amman','Amman','#16a34a','AMM',['country'=>'JO','region'=>'Middle East','type'=>'city','aliases'=>['Amman','安曼','AMM'],'pinned'=>0],0],
            ['IL_TEL_AVIV','特拉维夫','Tel Aviv','Tel Aviv','#2563eb','TLV',['country'=>'IL','region'=>'Middle East','type'=>'city','aliases'=>['Tel Aviv','特拉维夫','TLV'],'pinned'=>0],0],
            ['DE_BERLIN','柏林','Berlin','Berlin','#111827','BER',['country'=>'DE','region'=>'Europe','type'=>'city','aliases'=>['Berlin','柏林','BER'],'pinned'=>1],0],
            ['DE_FRANKFURT','法兰克福','Frankfurt','Frankfurt','#111827','FRA',['country'=>'DE','region'=>'Europe','type'=>'city','aliases'=>['Frankfurt','法兰克福','FRA'],'pinned'=>1],0],
            ['DE_MUNICH','慕尼黑','Munich','Munich','#111827','MUC',['country'=>'DE','region'=>'Europe','type'=>'city','aliases'=>['Munich','Muenchen','慕尼黑','MUC'],'pinned'=>0],0],
            ['DE_HAMBURG','汉堡','Hamburg','Hamburg','#111827','HAM',['country'=>'DE','region'=>'Europe','type'=>'city','aliases'=>['Hamburg','汉堡','HAM'],'pinned'=>0],0],
            ['GB_LONDON','伦敦','London','London','#1d4ed8','LON',['country'=>'GB','region'=>'Europe','type'=>'city','aliases'=>['London','伦敦','LON'],'pinned'=>1],0],
            ['GB_MANCHESTER','曼彻斯特','Manchester','Manchester','#1d4ed8','MAN',['country'=>'GB','region'=>'Europe','type'=>'city','aliases'=>['Manchester','曼彻斯特','MAN'],'pinned'=>0],0],
            ['FR_PARIS','巴黎','Paris','Paris','#2563eb','PAR',['country'=>'FR','region'=>'Europe','type'=>'city','aliases'=>['Paris','巴黎','PAR'],'pinned'=>0],0],
            ['FR_LYON','里昂','Lyon','Lyon','#2563eb','LYS',['country'=>'FR','region'=>'Europe','type'=>'city','aliases'=>['Lyon','里昂','LYS'],'pinned'=>0],0],
            ['IT_MILAN','米兰','Milan','Milan','#16a34a','MIL',['country'=>'IT','region'=>'Europe','type'=>'city','aliases'=>['Milan','Milano','米兰','MIL'],'pinned'=>0],0],
            ['IT_ROME','罗马','Rome','Rome','#16a34a','ROM',['country'=>'IT','region'=>'Europe','type'=>'city','aliases'=>['Rome','Roma','罗马','ROM'],'pinned'=>0],0],
            ['ES_MADRID','马德里','Madrid','Madrid','#f59e0b','MAD',['country'=>'ES','region'=>'Europe','type'=>'city','aliases'=>['Madrid','马德里','MAD'],'pinned'=>0],0],
            ['ES_BARCELONA','巴塞罗那','Barcelona','Barcelona','#f59e0b','BCN',['country'=>'ES','region'=>'Europe','type'=>'city','aliases'=>['Barcelona','巴塞罗那','BCN'],'pinned'=>0],0],
            ['PT_LISBON','里斯本','Lisbon','Lisbon','#16a34a','LIS',['country'=>'PT','region'=>'Europe','type'=>'city','aliases'=>['Lisbon','Lisboa','里斯本','LIS'],'pinned'=>0],0],
            ['NL_AMSTERDAM','阿姆斯特丹','Amsterdam','Amsterdam','#f97316','AMS',['country'=>'NL','region'=>'Europe','type'=>'city','aliases'=>['Amsterdam','阿姆斯特丹','AMS'],'pinned'=>0],0],
            ['BE_BRUSSELS','布鲁塞尔','Brussels','Brussels','#111827','BRU',['country'=>'BE','region'=>'Europe','type'=>'city','aliases'=>['Brussels','布鲁塞尔','BRU'],'pinned'=>0],0],
            ['CH_ZURICH','苏黎世','Zurich','Zurich','#dc2626','ZRH',['country'=>'CH','region'=>'Europe','type'=>'city','aliases'=>['Zurich','Zuerich','苏黎世','ZRH'],'pinned'=>0],0],
            ['AT_VIENNA','维也纳','Vienna','Vienna','#dc2626','VIE',['country'=>'AT','region'=>'Europe','type'=>'city','aliases'=>['Vienna','Wien','维也纳','VIE'],'pinned'=>0],0],
            ['SE_STOCKHOLM','斯德哥尔摩','Stockholm','Stockholm','#0ea5e9','STO',['country'=>'SE','region'=>'Europe','type'=>'city','aliases'=>['Stockholm','斯德哥尔摩','STO'],'pinned'=>0],0],
            ['DK_COPENHAGEN','哥本哈根','Copenhagen','Copenhagen','#dc2626','CPH',['country'=>'DK','region'=>'Europe','type'=>'city','aliases'=>['Copenhagen','哥本哈根','CPH'],'pinned'=>0],0],
            ['NO_OSLO','奥斯陆','Oslo','Oslo','#2563eb','OSL',['country'=>'NO','region'=>'Europe','type'=>'city','aliases'=>['Oslo','奥斯陆','OSL'],'pinned'=>0],0],
            ['FI_HELSINKI','赫尔辛基','Helsinki','Helsinki','#2563eb','HEL',['country'=>'FI','region'=>'Europe','type'=>'city','aliases'=>['Helsinki','赫尔辛基','HEL'],'pinned'=>0],0],
            ['PL_WARSAW','华沙','Warsaw','Warsaw','#dc2626','WAW',['country'=>'PL','region'=>'Europe','type'=>'city','aliases'=>['Warsaw','华沙','WAW'],'pinned'=>0],0],
            ['CZ_PRAGUE','布拉格','Prague','Prague','#2563eb','PRG',['country'=>'CZ','region'=>'Europe','type'=>'city','aliases'=>['Prague','Praha','布拉格','PRG'],'pinned'=>0],0],
            ['HU_BUDAPEST','布达佩斯','Budapest','Budapest','#16a34a','BUD',['country'=>'HU','region'=>'Europe','type'=>'city','aliases'=>['Budapest','布达佩斯','BUD'],'pinned'=>0],0],
            ['RO_BUCHAREST','布加勒斯特','Bucharest','Bucharest','#2563eb','BUH',['country'=>'RO','region'=>'Europe','type'=>'city','aliases'=>['Bucharest','布加勒斯特','BUH'],'pinned'=>0],0],
            ['GR_ATHENS','雅典','Athens','Athens','#2563eb','ATH',['country'=>'GR','region'=>'Europe','type'=>'city','aliases'=>['Athens','雅典','ATH'],'pinned'=>0],0],
            ['IE_DUBLIN','都柏林','Dublin','Dublin','#16a34a','DUB',['country'=>'IE','region'=>'Europe','type'=>'city','aliases'=>['Dublin','都柏林','DUB'],'pinned'=>0],0],
            ['US_NEW_YORK','纽约','New York','New York','#2563eb','NYC',['country'=>'US','region'=>'North America','type'=>'city','aliases'=>['New York','纽约','NYC'],'pinned'=>1],0],
            ['US_LOS_ANGELES','洛杉矶','Los Angeles','Los Angeles','#2563eb','LA',['country'=>'US','region'=>'North America','type'=>'city','aliases'=>['Los Angeles','洛杉矶','LA','LAX'],'pinned'=>1],0],
            ['US_CHICAGO','芝加哥','Chicago','Chicago','#2563eb','CHI',['country'=>'US','region'=>'North America','type'=>'city','aliases'=>['Chicago','芝加哥','CHI'],'pinned'=>0],0],
            ['US_HOUSTON','休斯敦','Houston','Houston','#2563eb','HOU',['country'=>'US','region'=>'North America','type'=>'city','aliases'=>['Houston','休斯敦','HOU'],'pinned'=>0],0],
            ['US_MIAMI','迈阿密','Miami','Miami','#2563eb','MIA',['country'=>'US','region'=>'North America','type'=>'city','aliases'=>['Miami','迈阿密','MIA'],'pinned'=>0],0],
            ['CA_TORONTO','多伦多','Toronto','Toronto','#dc2626','YYZ',['country'=>'CA','region'=>'North America','type'=>'city','aliases'=>['Toronto','多伦多','YYZ'],'pinned'=>0],0],
            ['CA_VANCOUVER','温哥华','Vancouver','Vancouver','#dc2626','YVR',['country'=>'CA','region'=>'North America','type'=>'city','aliases'=>['Vancouver','温哥华','YVR'],'pinned'=>0],0],
            ['CA_MONTREAL','蒙特利尔','Montreal','Montreal','#dc2626','YUL',['country'=>'CA','region'=>'North America','type'=>'city','aliases'=>['Montreal','蒙特利尔','YUL'],'pinned'=>0],0],
            ['MX_MEXICO_CITY','墨西哥城','Mexico City','Mexico City','#16a34a','MEX',['country'=>'MX','region'=>'North America','type'=>'city','aliases'=>['Mexico City','墨西哥城','MEX'],'pinned'=>0],0],
            ['BR_SAO_PAULO','圣保罗','Sao Paulo','Sao Paulo','#16a34a','SAO',['country'=>'BR','region'=>'South America','type'=>'city','aliases'=>['Sao Paulo','São Paulo','圣保罗','SAO'],'pinned'=>0],0],
            ['BR_RIO','里约热内卢','Rio de Janeiro','Rio','#16a34a','RIO',['country'=>'BR','region'=>'South America','type'=>'city','aliases'=>['Rio de Janeiro','Rio','里约','RIO'],'pinned'=>0],0],
            ['AR_BUENOS_AIRES','布宜诺斯艾利斯','Buenos Aires','Buenos Aires','#0ea5e9','BUE',['country'=>'AR','region'=>'South America','type'=>'city','aliases'=>['Buenos Aires','布宜诺斯艾利斯','BUE'],'pinned'=>0],0],
            ['CL_SANTIAGO','圣地亚哥','Santiago','Santiago','#dc2626','SCL',['country'=>'CL','region'=>'South America','type'=>'city','aliases'=>['Santiago','圣地亚哥','SCL'],'pinned'=>0],0],
            ['CO_BOGOTA','波哥大','Bogota','Bogota','#f59e0b','BOG',['country'=>'CO','region'=>'South America','type'=>'city','aliases'=>['Bogota','Bogotá','波哥大','BOG'],'pinned'=>0],0],
            ['PE_LIMA','利马','Lima','Lima','#dc2626','LIM',['country'=>'PE','region'=>'South America','type'=>'city','aliases'=>['Lima','利马','LIM'],'pinned'=>0],0],
            ['ZA_JOHANNESBURG','约翰内斯堡','Johannesburg','Johannesburg','#16a34a','JNB',['country'=>'ZA','region'=>'Africa','type'=>'city','aliases'=>['Johannesburg','约翰内斯堡','JNB'],'pinned'=>0],0],
            ['ZA_CAPE_TOWN','开普敦','Cape Town','Cape Town','#16a34a','CPT',['country'=>'ZA','region'=>'Africa','type'=>'city','aliases'=>['Cape Town','开普敦','CPT'],'pinned'=>0],0],
            ['NG_LAGOS','拉各斯','Lagos','Lagos','#16a34a','LOS',['country'=>'NG','region'=>'Africa','type'=>'city','aliases'=>['Lagos','拉各斯','LOS'],'pinned'=>0],0],
            ['NG_ABUJA','阿布贾','Abuja','Abuja','#16a34a','ABV',['country'=>'NG','region'=>'Africa','type'=>'city','aliases'=>['Abuja','阿布贾','ABV'],'pinned'=>0],0],
            ['KE_NAIROBI','内罗毕','Nairobi','Nairobi','#111827','NBO',['country'=>'KE','region'=>'Africa','type'=>'city','aliases'=>['Nairobi','内罗毕','NBO'],'pinned'=>0],0],
            ['MA_CASABLANCA','卡萨布兰卡','Casablanca','Casablanca','#dc2626','CMN',['country'=>'MA','region'=>'Africa','type'=>'city','aliases'=>['Casablanca','卡萨布兰卡','CMN'],'pinned'=>0],0],
            ['DZ_ALGIERS','阿尔及尔','Algiers','Algiers','#16a34a','ALG',['country'=>'DZ','region'=>'Africa','type'=>'city','aliases'=>['Algiers','阿尔及尔','ALG'],'pinned'=>0],0],
            ['TN_TUNIS','突尼斯市','Tunis','Tunis','#dc2626','TUN',['country'=>'TN','region'=>'Africa','type'=>'city','aliases'=>['Tunis','突尼斯市','TUN'],'pinned'=>0],0],
            ['GH_ACCRA','阿克拉','Accra','Accra','#f59e0b','ACC',['country'=>'GH','region'=>'Africa','type'=>'city','aliases'=>['Accra','阿克拉','ACC'],'pinned'=>0],0],
            ['ET_ADDIS_ABABA','亚的斯亚贝巴','Addis Ababa','Addis Ababa','#16a34a','ADD',['country'=>'ET','region'=>'Africa','type'=>'city','aliases'=>['Addis Ababa','亚的斯亚贝巴','ADD'],'pinned'=>0],0],
            ['AU_SYDNEY','悉尼','Sydney','Sydney','#2563eb','SYD',['country'=>'AU','region'=>'Oceania','type'=>'city','aliases'=>['Sydney','悉尼','SYD'],'pinned'=>0],0],
            ['AU_MELBOURNE','墨尔本','Melbourne','Melbourne','#2563eb','MEL',['country'=>'AU','region'=>'Oceania','type'=>'city','aliases'=>['Melbourne','墨尔本','MEL'],'pinned'=>0],0],
            ['AU_BRISBANE','布里斯班','Brisbane','Brisbane','#2563eb','BNE',['country'=>'AU','region'=>'Oceania','type'=>'city','aliases'=>['Brisbane','布里斯班','BNE'],'pinned'=>0],0],
            ['AU_PERTH','珀斯','Perth','Perth','#2563eb','PER',['country'=>'AU','region'=>'Oceania','type'=>'city','aliases'=>['Perth','珀斯','PER'],'pinned'=>0],0],
            ['NZ_AUCKLAND','奥克兰','Auckland','Auckland','#111827','AKL',['country'=>'NZ','region'=>'Oceania','type'=>'city','aliases'=>['Auckland','奥克兰','AKL'],'pinned'=>0],0],
        ],
    ];
}

function crm_seed_dictionary_items(): void
{
    $stmt = db()->prepare('INSERT INTO crm_dictionary_items (type_key, item_key, name_cn, name_en, short_name, color, icon, description, extra_config_json, is_default, is_enabled, sort_order, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NULL, NULL, NOW(), NOW()) ON DUPLICATE KEY UPDATE name_cn=VALUES(name_cn), name_en=VALUES(name_en), short_name=VALUES(short_name), color=VALUES(color), icon=VALUES(icon), extra_config_json=VALUES(extra_config_json), is_default=VALUES(is_default), sort_order=VALUES(sort_order), updated_at=NOW()');
    foreach (crm_dictionary_item_defaults() as $type => $items) {
        $sort = 10;
        foreach ($items as $item) {
            $stmt->execute([$type, $item[0], $item[1], $item[2], $item[3], $item[4], $item[5], '', json_encode($item[6], JSON_UNESCAPED_UNICODE), $item[7], $sort]);
            $sort += 10;
        }
    }
}

function crm_rule_defaults(): array
{
    $customerTabs = [
        ['key' => 'overview', 'name' => '概览', 'short' => '概览', 'icon' => 'OV', 'enabled' => 1, 'sort' => 10, 'permission' => '', 'lifecycles' => ['*'], 'readonly' => 0, 'required' => 1],
        ['key' => 'contacts', 'name' => '联系人', 'short' => '联系', 'icon' => 'CT', 'enabled' => 1, 'sort' => 20, 'permission' => 'contact.view', 'lifecycles' => ['lead','official','key_follow','quoting','sampling','deal','sleeping','lost'], 'readonly' => 0],
        ['key' => 'followups', 'name' => '跟进', 'short' => '跟进', 'icon' => 'FU', 'enabled' => 1, 'sort' => 30, 'permission' => 'follow.view', 'lifecycles' => ['lead','official','key_follow','quoting','sampling','deal','sleeping','lost'], 'readonly' => 0],
        ['key' => 'visits', 'name' => '拜访 / 来访', 'short' => '拜访', 'icon' => 'VS', 'enabled' => 1, 'sort' => 35, 'permission' => 'visit.view', 'lifecycles' => ['lead','official','key_follow','quoting','sampling','deal','sleeping','lost'], 'readonly' => 0],
        ['key' => 'opportunities', 'name' => '商机', 'short' => '商机', 'icon' => 'OP', 'enabled' => 1, 'sort' => 37, 'permission' => 'opportunity.view', 'lifecycles' => ['lead','official','key_follow','quoting','sampling','deal','sleeping','lost'], 'readonly' => 0],
        ['key' => 'mail', 'name' => '邮件', 'short' => '邮件', 'icon' => 'ML', 'enabled' => 1, 'sort' => 40, 'permission' => 'customer.mail_summary', 'lifecycles' => ['lead','official','key_follow','quoting','sampling','deal','sleeping','lost'], 'readonly' => 1],
        ['key' => 'quote', 'name' => '报价', 'short' => '报价', 'icon' => 'QT', 'enabled' => 1, 'sort' => 50, 'permission' => 'customer.quote_summary', 'lifecycles' => ['official','key_follow','quoting','sampling','deal','sleeping','lost'], 'readonly' => 1],
        ['key' => 'plm', 'name' => 'PLM', 'short' => 'PLM', 'icon' => 'PL', 'enabled' => 1, 'sort' => 60, 'permission' => 'customer.plm_summary', 'lifecycles' => ['official','key_follow','quoting','sampling','deal'], 'readonly' => 1],
        ['key' => 'bom', 'name' => 'BOM', 'short' => 'BOM', 'icon' => 'BM', 'enabled' => 1, 'sort' => 70, 'permission' => 'customer.bom_summary', 'lifecycles' => ['official','key_follow','quoting','sampling','deal'], 'readonly' => 1],
        ['key' => 'dispatch', 'name' => '派工', 'short' => '派工', 'icon' => 'DP', 'enabled' => 1, 'sort' => 80, 'permission' => 'customer.dispatch_summary', 'lifecycles' => ['official','key_follow','quoting','sampling','deal'], 'readonly' => 1],
        ['key' => 'orders', 'name' => '订单', 'short' => '订单', 'icon' => 'OD', 'enabled' => 1, 'sort' => 90, 'permission' => 'customer.order_summary', 'lifecycles' => ['deal'], 'readonly' => 1],
        ['key' => 'documents', 'name' => '单证', 'short' => '单证', 'icon' => 'DC', 'enabled' => 1, 'sort' => 92, 'permission' => 'customer.order_summary', 'lifecycles' => ['deal'], 'readonly' => 1],
        ['key' => 'shipments', 'name' => '出货进度', 'short' => '出货', 'icon' => 'SP', 'enabled' => 1, 'sort' => 94, 'permission' => 'customer.order_summary', 'lifecycles' => ['deal'], 'readonly' => 1],
        ['key' => 'materials', 'name' => '资料', 'short' => '资料', 'icon' => 'MT', 'enabled' => 1, 'sort' => 100, 'permission' => 'customer.material_summary', 'lifecycles' => ['official','key_follow','quoting','sampling','deal'], 'readonly' => 0],
        ['key' => 'relations', 'name' => '关系', 'short' => '关系', 'icon' => 'GR', 'enabled' => 1, 'sort' => 110, 'permission' => 'customer.graph_manage', 'lifecycles' => ['lead','official','key_follow','quoting','sampling','deal','sleeping','lost'], 'readonly' => 0],
        ['key' => 'logs', 'name' => '日志', 'short' => '日志', 'icon' => 'LG', 'enabled' => 1, 'sort' => 120, 'permission' => 'customer.view_logs', 'lifecycles' => ['*'], 'readonly' => 1, 'required' => 1],
        ['key' => 'duplicate', 'name' => '查重', 'short' => '查重', 'icon' => 'DU', 'enabled' => 1, 'sort' => 130, 'permission' => 'customer.merge', 'lifecycles' => ['pool','temp','pending_confirm'], 'readonly' => 0],
        ['key' => 'timeline', 'name' => '时间轴', 'short' => '时间', 'icon' => 'TL', 'enabled' => 0, 'sort' => 140, 'permission' => 'customer.timeline_view', 'lifecycles' => ['*'], 'readonly' => 1],
        ['key' => 'events', 'name' => '事件', 'short' => '事件', 'icon' => 'EV', 'enabled' => 0, 'sort' => 150, 'permission' => 'customer.event_manage', 'lifecycles' => ['*'], 'readonly' => 0],
        ['key' => 'preferences', 'name' => '偏好', 'short' => '偏好', 'icon' => 'PF', 'enabled' => 0, 'sort' => 160, 'permission' => 'customer.view', 'lifecycles' => ['*'], 'readonly' => 1],
        ['key' => 'files', 'name' => '文件', 'short' => '文件', 'icon' => 'FL', 'enabled' => 0, 'sort' => 170, 'permission' => 'customer.file_upload', 'lifecycles' => ['official','key_follow','quoting','sampling','deal'], 'readonly' => 0],
    ];
    return [
        'customer_admission' => ['rule_name' => '客户准入规则', 'rule_group' => 'lead_pool', 'config' => ['mode' => 'lead_pool_first', 'enabled' => 1, 'external_sources_to_pool' => ['website','mail','whatsapp','linkedin','ads'], 'import_to_pool' => 1, 'duplicate_risk_to_pool' => 1]],
        'lead_pool' => ['rule_name' => '暂存池规则', 'rule_group' => 'lead_pool', 'config' => ['enabled' => 1, 'need_admin_confirm' => 0, 'sales_can_process' => 1, 'retention_days' => 180, 'allow_delete' => 1, 'allow_merge' => 1, 'allow_confirm' => 1]],
        'duplicate_detection' => ['rule_name' => '查重规则', 'rule_group' => 'duplicate', 'config' => ['fields' => ['customer_name','customer_name_en','email','email_domain','website_domain','phone','whatsapp','country_name','address','contact_name_company','contact_email'], 'name_threshold' => 80, 'address_threshold' => 75, 'domain_exact' => 1, 'phone_exact' => 1, 'email_exact' => 1, 'action' => 'lead_pool']],
        'default_values' => ['rule_name' => '默认值配置', 'rule_group' => 'defaults', 'config' => ['customer_level' => 'P3', 'customer_status' => 'lead', 'management_category' => 'project', 'promotion_status' => 'not_promoted', 'source' => 'website', 'country' => 'IN', 'promotion_channels' => ['email'], 'contact_status' => 'active', 'contact_source' => 'manual', 'contact_promotions' => ['email']]],
        'promotion_rules' => ['rule_name' => '推广规则', 'rule_group' => 'promotion', 'config' => ['allow_levels' => ['P0','P1','P2','P3','P4'], 'allow_statuses' => ['lead','official','active'], 'skip_no_promotion_contacts' => 1, 'skip_left_contacts' => 1, 'primary_contact_first' => 1, 'role_priority' => ['decision_maker','boss','buyer','project_owner','engineer']]],
        'customer_detail_tabs' => ['rule_name' => '客户中心 Tab 配置', 'rule_group' => 'customer_ui', 'config' => ['label_mode' => 'icon_short', 'overflow_after' => 10, 'tabs' => $customerTabs, 'lifecycle_presets' => ['blacklist' => ['overview','logs'], 'pool' => ['overview','duplicate'], 'temp' => ['overview','duplicate'], 'pending_confirm' => ['overview','duplicate'], 'lead' => ['overview','contacts','followups','visits','opportunities','mail','quote','materials','relations','logs'], 'deal' => ['*']]]],
    ];
}

function crm_seed_rule_configs(): void
{
    $stmt = db()->prepare('INSERT INTO crm_rule_configs (rule_key, rule_name, rule_group, config_json, is_enabled, created_at, updated_at) VALUES (?, ?, ?, ?, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE rule_name=VALUES(rule_name), rule_group=VALUES(rule_group), updated_at=NOW()');
    foreach (crm_rule_defaults() as $key => $rule) {
        $stmt->execute([$key, $rule['rule_name'], $rule['rule_group'], json_encode($rule['config'], JSON_UNESCAPED_UNICODE)]);
    }
}

function crm_seed_field_configs(): void
{
    $fields = [
        ['customers','customer','customer_name','客户名称','text',1,1,0,'','基础信息',10],
        ['customers','customer','customer_name_en','英文名','text',1,0,0,'','基础信息',20],
        ['customers','customer','country','国家','dictionary:country_region',1,1,0,'IN','基础信息',30],
        ['customers','customer','level','客户等级','dictionary:customer_level',1,0,0,'P3','关系状态',40],
        ['customers','customer','source_tags','来源标签','dictionary_multi:customer_source',1,0,0,'website','关系状态',50],
        ['customers','customer','promotion_channels','推广方式','dictionary_multi:promotion_channel',1,0,0,'email','推广',60],
        ['customers','contact','name','联系人姓名','text',1,1,0,'','基础信息',10],
        ['customers','contact','email','邮箱','email',1,0,0,'','联系方式',20],
        ['customers','contact','phone','电话','text',1,0,0,'','联系方式',30],
        ['customers','contact','role_tags','联系人角色','dictionary_multi:contact_role',1,0,0,'buyer','关系状态',40],
    ];
    $stmt = db()->prepare('INSERT INTO crm_field_configs (module_name, entity_type, field_key, field_label, field_type, is_visible, is_required, is_readonly, default_value, sort_order, group_name, config_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE field_label=VALUES(field_label), field_type=VALUES(field_type), sort_order=VALUES(sort_order), group_name=VALUES(group_name), updated_at=NOW()');
    foreach ($fields as $f) {
        $stmt->execute([$f[0], $f[1], $f[2], $f[3], $f[4], $f[5], $f[6], $f[7], $f[8], $f[10], $f[9], '{}']);
    }
}

function crm_settings_ensure_permissions(): void
{
    $permissions = [
        ['crm.config.view','crm_config','view','查看 CRM 配置中心','medium'],
        ['crm.config.base','crm_config','base','修改基础配置','high'],
        ['crm.config.customer_level','crm_config','customer_level','修改客户等级','high'],
        ['crm.config.customer_source','crm_config','customer_source','修改客户来源','high'],
        ['crm.config.promotion','crm_config','promotion','修改推广方式和规则','high'],
        ['crm.config.lead_pool','crm_config','lead_pool','修改暂存池规则','high'],
        ['crm.config.duplicate','crm_config','duplicate','修改查重规则','high'],
        ['crm.config.field','crm_config','field','修改字段配置','high'],
        ['crm.config.defaults','crm_config','defaults','修改默认值','high'],
        ['crm.config.country','crm_config','country','修改国家预设','high'],
        ['crm.config.owner_role','crm_config','owner_role','修改负责人角色','high'],
        ['crm.config.logs','crm_config','logs','查看配置日志','medium'],
        ['crm.config.restore','crm_config','restore','恢复默认配置','dangerous'],
    ];
    $stmt = db()->prepare('INSERT IGNORE INTO crm_permissions (permission_key, module, action, description, risk_level, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
    foreach ($permissions as $permission) $stmt->execute($permission);
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key) SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p WHERE r.role_key IN ('super_admin','admin') AND p.module = 'crm_config'");
}

function crm_dictionary_items(string $typeKey, bool $enabledOnly = true): array
{
    crm_settings_ensure_tables();
    $sql = 'SELECT * FROM crm_dictionary_items WHERE type_key = ? AND deleted_at IS NULL';
    if ($enabledOnly) $sql .= ' AND is_enabled = 1';
    $sql .= ' ORDER BY sort_order, id';
    $stmt = db()->prepare($sql);
    $stmt->execute([$typeKey]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) $row['extra_config'] = json_decode((string)($row['extra_config_json'] ?? '{}'), true) ?: [];
    return $rows;
}

function crm_dictionary_keys(string $typeKey, bool $enabledOnly = true): array
{
    return array_map(fn($row) => $row['item_key'], crm_dictionary_items($typeKey, $enabledOnly));
}

function crm_dictionary_default(string $typeKey, string $fallback = ''): string
{
    foreach (crm_dictionary_items($typeKey) as $row) if ((int)$row['is_default'] === 1) return $row['item_key'];
    $items = crm_dictionary_items($typeKey);
    return $items[0]['item_key'] ?? $fallback;
}

function crm_rule_config(string $ruleKey): array
{
    crm_settings_ensure_tables();
    $stmt = db()->prepare('SELECT * FROM crm_rule_configs WHERE rule_key = ? LIMIT 1');
    $stmt->execute([$ruleKey]);
    $row = $stmt->fetch();
    if (!$row) return crm_rule_defaults()[$ruleKey]['config'] ?? [];
    return json_decode((string)$row['config_json'], true) ?: [];
}

function crm_dictionary_save_item(array $input): array
{
    crm_require('crm.config.base');
    $type = preg_replace('/[^a-z0-9_\\-]/i', '', (string)($input['type_key'] ?? ''));
    $key = preg_replace('/[^a-z0-9_\\-]/i', '', (string)($input['item_key'] ?? ''));
    if ($type === '' || $key === '') throw new RuntimeException('字典类型和编码不能为空。');
    $before = null;
    $stmt = db()->prepare('SELECT * FROM crm_dictionary_items WHERE type_key = ? AND item_key = ? LIMIT 1');
    $stmt->execute([$type, $key]);
    $before = $stmt->fetch() ?: null;
    $extra = $input['extra_config'] ?? [];
    if (is_string($extra)) $extra = json_decode($extra, true) ?: [];
    db()->prepare('INSERT INTO crm_dictionary_items (type_key, item_key, name_cn, name_en, short_name, color, icon, description, extra_config_json, is_default, is_enabled, sort_order, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE name_cn=VALUES(name_cn), name_en=VALUES(name_en), short_name=VALUES(short_name), color=VALUES(color), icon=VALUES(icon), description=VALUES(description), extra_config_json=VALUES(extra_config_json), is_default=VALUES(is_default), is_enabled=VALUES(is_enabled), sort_order=VALUES(sort_order), updated_by=VALUES(updated_by), updated_at=NOW()')
        ->execute([$type, $key, trim((string)($input['name_cn'] ?? $key)), trim((string)($input['name_en'] ?? '')), trim((string)($input['short_name'] ?? '')), trim((string)($input['color'] ?? '#64748b')), trim((string)($input['icon'] ?? '')), trim((string)($input['description'] ?? '')), json_encode($extra, JSON_UNESCAPED_UNICODE), !empty($input['is_default']) ? 1 : 0, isset($input['is_enabled']) ? (int)!empty($input['is_enabled']) : 1, (int)($input['sort_order'] ?? 100), current_user()['id'] ?? null, current_user()['id'] ?? null]);
    crm_log_event('settings', 'dictionary_save', 'dictionary', $type . ':' . $key, $before, $input);
    return ['items' => crm_dictionary_items($type, false)];
}

function crm_dictionary_disable_item(string $type, string $key): array
{
    crm_require('crm.config.base');
    $stmt = db()->prepare('SELECT * FROM crm_dictionary_items WHERE type_key = ? AND item_key = ? LIMIT 1');
    $stmt->execute([$type, $key]);
    $before = $stmt->fetch();
    db()->prepare('UPDATE crm_dictionary_items SET is_enabled = 0, updated_by = ?, updated_at = NOW() WHERE type_key = ? AND item_key = ?')->execute([current_user()['id'] ?? null, $type, $key]);
    crm_log_event('settings', 'dictionary_disable', 'dictionary', $type . ':' . $key, $before, ['is_enabled' => 0]);
    return ['items' => crm_dictionary_items($type, false)];
}

function crm_rule_save(string $ruleKey, array $config): array
{
    if (!is_super_admin() && !has_permission('crm.config.base') && !has_permission('settings.edit')) {
        crm_require('crm.config.base');
    }
    $before = crm_rule_config($ruleKey);
    $defaults = crm_rule_defaults();
    $ruleName = $defaults[$ruleKey]['rule_name'] ?? $ruleKey;
    $group = $defaults[$ruleKey]['rule_group'] ?? 'custom';
    db()->prepare('INSERT INTO crm_rule_configs (rule_key, rule_name, rule_group, config_json, is_enabled, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, 1, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE config_json=VALUES(config_json), updated_by=VALUES(updated_by), updated_at=NOW()')
        ->execute([$ruleKey, $ruleName, $group, json_encode($config, JSON_UNESCAPED_UNICODE), current_user()['id'] ?? null, current_user()['id'] ?? null]);
    crm_log_event('settings', 'rule_save', 'rule', $ruleKey, $before, $config);
    return crm_rule_config($ruleKey);
}

function crm_field_configs(string $module, string $entity): array
{
    crm_settings_ensure_tables();
    $stmt = db()->prepare('SELECT * FROM crm_field_configs WHERE module_name = ? AND entity_type = ? ORDER BY sort_order, id');
    $stmt->execute([$module, $entity]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) $row['config'] = json_decode((string)($row['config_json'] ?? '{}'), true) ?: [];
    return $rows;
}

function crm_field_config_save(array $input): array
{
    crm_require('crm.config.field');
    $before = crm_field_configs((string)($input['module_name'] ?? 'customers'), (string)($input['entity_type'] ?? 'customer'));
    db()->prepare('INSERT INTO crm_field_configs (module_name, entity_type, field_key, field_label, field_type, is_visible, is_required, is_readonly, default_value, sort_order, group_name, permission_key, config_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE field_label=VALUES(field_label), field_type=VALUES(field_type), is_visible=VALUES(is_visible), is_required=VALUES(is_required), is_readonly=VALUES(is_readonly), default_value=VALUES(default_value), sort_order=VALUES(sort_order), group_name=VALUES(group_name), permission_key=VALUES(permission_key), config_json=VALUES(config_json), updated_at=NOW()')
        ->execute([(string)($input['module_name'] ?? 'customers'), (string)($input['entity_type'] ?? 'customer'), (string)($input['field_key'] ?? ''), (string)($input['field_label'] ?? ''), (string)($input['field_type'] ?? 'text'), !empty($input['is_visible']) ? 1 : 0, !empty($input['is_required']) ? 1 : 0, !empty($input['is_readonly']) ? 1 : 0, (string)($input['default_value'] ?? ''), (int)($input['sort_order'] ?? 100), (string)($input['group_name'] ?? ''), (string)($input['permission_key'] ?? ''), json_encode($input['config'] ?? [], JSON_UNESCAPED_UNICODE)]);
    crm_log_event('settings', 'field_config_save', 'field', (string)($input['field_key'] ?? ''), $before, $input);
    return crm_field_configs((string)($input['module_name'] ?? 'customers'), (string)($input['entity_type'] ?? 'customer'));
}

function crm_config_bootstrap(): array
{
    crm_settings_ensure_tables();
    $types = db()->query('SELECT * FROM crm_dictionary_types WHERE is_enabled = 1 ORDER BY sort_order, id')->fetchAll();
    $items = [];
    foreach ($types as $type) $items[$type['type_key']] = crm_dictionary_items($type['type_key'], false);
    $rules = [];
    foreach (array_keys(crm_rule_defaults()) as $key) $rules[$key] = crm_rule_config($key);
    return ['types' => $types, 'items' => $items, 'rules' => $rules, 'fields' => ['customer' => crm_field_configs('customers', 'customer'), 'contact' => crm_field_configs('customers', 'contact')]];
}
