<?php
declare(strict_types=1);

return [
    'version' => '20260801_002_phase3_templates',
    'description' => 'Product adaptation V2 phase 3 templates and inheritance engine',
    'up' => [
        "CREATE TABLE IF NOT EXISTS mc_pa2_templates (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            template_code VARCHAR(100) NOT NULL,
            template_name VARCHAR(180) NOT NULL,
            template_level VARCHAR(40) NOT NULL DEFAULT 'category',
            scope_type VARCHAR(40) NOT NULL DEFAULT 'category',
            product_category_id BIGINT UNSIGNED NULL,
            series_code VARCHAR(120) NULL,
            product_id BIGINT UNSIGNED NULL,
            parent_template_id BIGINT UNSIGNED NULL,
            active_version_id BIGINT UNSIGNED NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'draft',
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            description VARCHAR(700) NULL,
            settings_json JSON NULL,
            created_by BIGINT UNSIGNED NULL,
            updated_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uk_mc_pa2_template_code (template_code),
            KEY idx_mc_pa2_templates_scope (template_level,scope_type,product_category_id,series_code,product_id,is_enabled,status),
            KEY idx_mc_pa2_templates_parent (parent_template_id),
            KEY idx_mc_pa2_templates_version (active_version_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS mc_pa2_template_versions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            template_id BIGINT UNSIGNED NOT NULL,
            version_no VARCHAR(40) NOT NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'published',
            snapshot_json JSON NOT NULL,
            created_by BIGINT UNSIGNED NULL,
            approved_by BIGINT UNSIGNED NULL,
            published_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            approved_at DATETIME NULL,
            published_at DATETIME NULL,
            UNIQUE KEY uk_mc_pa2_template_version (template_id,version_no),
            KEY idx_mc_pa2_template_versions_template (template_id,status,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS mc_pa2_template_groups (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            template_id BIGINT UNSIGNED NOT NULL,
            group_definition_id BIGINT UNSIGNED NOT NULL,
            group_code VARCHAR(80) NOT NULL,
            group_name_override VARCHAR(160) NULL,
            group_type_override VARCHAR(40) NULL,
            is_required TINYINT(1) NOT NULL DEFAULT 0,
            selection_mode VARCHAR(20) NOT NULL DEFAULT 'single',
            allow_empty TINYINT(1) NOT NULL DEFAULT 1,
            min_select INT NOT NULL DEFAULT 0,
            max_select INT NOT NULL DEFAULT 1,
            allow_default TINYINT(1) NOT NULL DEFAULT 1,
            customer_selectable TINYINT(1) NOT NULL DEFAULT 0,
            affects_price TINYINT(1) NOT NULL DEFAULT 0,
            affects_lead_time TINYINT(1) NOT NULL DEFAULT 0,
            requires_approval TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 100,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            inheritance_action VARCHAR(30) NOT NULL DEFAULT 'add',
            settings_json JSON NULL,
            created_by BIGINT UNSIGNED NULL,
            updated_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uk_mc_pa2_template_group (template_id,group_code),
            KEY idx_mc_pa2_template_groups_template (template_id,sort_order,is_enabled),
            KEY idx_mc_pa2_template_groups_definition (group_definition_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "INSERT INTO mc_pa2_templates(template_code,template_name,template_level,scope_type,product_category_id,parent_template_id,status,is_enabled,description,created_at,updated_at)
            VALUES
            ('system_common','系统通用模板','system','global',NULL,NULL,'draft',1,'所有产品默认继承的最小配置结构。',NOW(),NOW())
            ON DUPLICATE KEY UPDATE template_name=VALUES(template_name),description=VALUES(description),updated_at=NOW()",
        "INSERT INTO mc_pa2_templates(template_code,template_name,template_level,scope_type,product_category_id,parent_template_id,status,is_enabled,description,created_at,updated_at)
            SELECT 'track_light_base','导轨灯模板','category','category',c.id,p.id,'draft',1,'导轨灯分类模板，继承系统通用模板。',NOW(),NOW()
            FROM mc_pa2_product_categories c JOIN mc_pa2_templates p ON p.template_code='system_common'
            WHERE c.category_code='track_light'
            ON DUPLICATE KEY UPDATE product_category_id=VALUES(product_category_id),parent_template_id=VALUES(parent_template_id),template_name=VALUES(template_name),description=VALUES(description),updated_at=NOW()",
        "INSERT INTO mc_pa2_templates(template_code,template_name,template_level,scope_type,product_category_id,parent_template_id,status,is_enabled,description,created_at,updated_at)
            SELECT 'recessed_base','嵌入式模板','category','category',c.id,p.id,'draft',1,'嵌入式灯具分类模板，继承系统通用模板。',NOW(),NOW()
            FROM mc_pa2_product_categories c JOIN mc_pa2_templates p ON p.template_code='system_common'
            WHERE c.category_code='recessed'
            ON DUPLICATE KEY UPDATE product_category_id=VALUES(product_category_id),parent_template_id=VALUES(parent_template_id),template_name=VALUES(template_name),description=VALUES(description),updated_at=NOW()",
        "INSERT INTO mc_pa2_templates(template_code,template_name,template_level,scope_type,product_category_id,parent_template_id,status,is_enabled,description,created_at,updated_at)
            SELECT 'magnetic_base','磁吸式模板','category','category',c.id,p.id,'draft',1,'磁吸式灯具分类模板，继承系统通用模板。',NOW(),NOW()
            FROM mc_pa2_product_categories c JOIN mc_pa2_templates p ON p.template_code='system_common'
            WHERE c.category_code='magnetic'
            ON DUPLICATE KEY UPDATE product_category_id=VALUES(product_category_id),parent_template_id=VALUES(parent_template_id),template_name=VALUES(template_name),description=VALUES(description),updated_at=NOW()",
        "INSERT INTO mc_pa2_template_groups(template_id,group_definition_id,group_code,is_required,selection_mode,allow_empty,min_select,max_select,sort_order,is_enabled,inheritance_action,created_at,updated_at)
            SELECT t.id,g.id,g.group_code,x.is_required,x.selection_mode,x.allow_empty,x.min_select,x.max_select,x.sort_order,1,'add',NOW(),NOW()
            FROM mc_pa2_templates t
            JOIN (
                SELECT 'system_common' template_code,'chip' group_code,1 is_required,'single' selection_mode,0 allow_empty,1 min_select,1 max_select,10 sort_order UNION ALL
                SELECT 'system_common','optical',1,'single',0,1,1,20 UNION ALL
                SELECT 'system_common','finish_color',0,'single',1,0,1,90 UNION ALL
                SELECT 'system_common','special_requirement',0,'single',1,0,1,100 UNION ALL
                SELECT 'track_light_base','driver',1,'single',0,1,1,30 UNION ALL
                SELECT 'track_light_base','track_connector',1,'single',0,1,1,40 UNION ALL
                SELECT 'track_light_base','intrack_connector',0,'single',1,0,1,50 UNION ALL
                SELECT 'track_light_base','intrack_driver',0,'single',1,0,1,60 UNION ALL
                SELECT 'track_light_base','dimming',0,'single',1,0,1,70 UNION ALL
                SELECT 'recessed_base','external_driver',1,'single',0,1,1,30 UNION ALL
                SELECT 'recessed_base','installation',1,'single',0,1,1,40 UNION ALL
                SELECT 'recessed_base','dimming',0,'single',1,0,1,70 UNION ALL
                SELECT 'magnetic_base','magnetic_head',1,'single',0,1,1,30 UNION ALL
                SELECT 'magnetic_base','driver',1,'single',0,1,1,40 UNION ALL
                SELECT 'magnetic_base','body_length',1,'single',0,1,1,50 UNION ALL
                SELECT 'magnetic_base','installation',0,'single',1,0,1,60
            ) x ON x.template_code=t.template_code
            JOIN mc_pa2_group_definitions g ON g.group_code=x.group_code
            ON DUPLICATE KEY UPDATE group_definition_id=VALUES(group_definition_id),is_required=VALUES(is_required),selection_mode=VALUES(selection_mode),allow_empty=VALUES(allow_empty),min_select=VALUES(min_select),max_select=VALUES(max_select),sort_order=VALUES(sort_order),is_enabled=VALUES(is_enabled),updated_at=NOW()",
    ],
    'down' => [
        "DROP TABLE IF EXISTS mc_pa2_template_groups",
        "DROP TABLE IF EXISTS mc_pa2_template_versions",
        "DROP TABLE IF EXISTS mc_pa2_templates",
    ],
];
