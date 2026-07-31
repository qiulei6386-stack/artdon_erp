<?php
declare(strict_types=1);

return [
    'version' => '20260731_023_config_template_center',
    'description' => 'Add dynamic product-category configuration template center',
    'up' => [
        "CREATE TABLE IF NOT EXISTS mc_config_templates (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            template_code VARCHAR(80) NOT NULL UNIQUE,
            template_name VARCHAR(160) NOT NULL,
            scope_type VARCHAR(30) NOT NULL DEFAULT 'category',
            product_type VARCHAR(120) NULL,
            product_series VARCHAR(160) NULL,
            product_id BIGINT UNSIGNED NULL,
            parent_template_id BIGINT UNSIGNED NULL,
            version_no VARCHAR(40) NOT NULL DEFAULT 'v1.0',
            status VARCHAR(30) NOT NULL DEFAULT 'draft',
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            description VARCHAR(500) NULL,
            settings_json JSON NULL,
            usage_count INT NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED NULL,
            updated_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_mc_config_templates_scope (scope_type,product_type,product_series,product_id,is_enabled,status),
            KEY idx_mc_config_templates_parent (parent_template_id),
            KEY idx_mc_config_templates_updated (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS mc_config_group_definitions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            group_code VARCHAR(80) NOT NULL UNIQUE,
            group_name VARCHAR(160) NOT NULL,
            group_type VARCHAR(30) NOT NULL DEFAULT 'material',
            business_type VARCHAR(60) NOT NULL DEFAULT 'custom',
            material_category_code VARCHAR(60) NULL,
            description VARCHAR(500) NULL,
            icon VARCHAR(40) NULL,
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_by BIGINT UNSIGNED NULL,
            updated_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_mc_config_group_definitions_type (group_type,business_type,is_enabled)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS mc_config_template_groups (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            template_id BIGINT UNSIGNED NOT NULL,
            group_definition_id BIGINT UNSIGNED NOT NULL,
            group_code VARCHAR(80) NOT NULL,
            group_name_override VARCHAR(160) NULL,
            is_required TINYINT(1) NOT NULL DEFAULT 0,
            selection_mode VARCHAR(20) NOT NULL DEFAULT 'single',
            allow_empty TINYINT(1) NOT NULL DEFAULT 1,
            min_select INT NOT NULL DEFAULT 0,
            max_select INT NOT NULL DEFAULT 1,
            allow_default TINYINT(1) NOT NULL DEFAULT 1,
            salesperson_editable TINYINT(1) NOT NULL DEFAULT 1,
            customer_selectable TINYINT(1) NOT NULL DEFAULT 0,
            affects_price TINYINT(1) NOT NULL DEFAULT 0,
            affects_lead_time TINYINT(1) NOT NULL DEFAULT 0,
            requires_approval TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 100,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            settings_json JSON NULL,
            created_by BIGINT UNSIGNED NULL,
            updated_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uk_mc_config_template_group (template_id,group_code),
            KEY idx_mc_config_template_groups_template (template_id,sort_order),
            KEY idx_mc_config_template_groups_definition (group_definition_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS mc_config_group_options (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            group_definition_id BIGINT UNSIGNED NOT NULL,
            option_code VARCHAR(80) NOT NULL,
            option_name VARCHAR(160) NOT NULL,
            option_image VARCHAR(500) NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 100,
            price_effect DECIMAL(12,2) NOT NULL DEFAULT 0,
            lead_time_effect INT NOT NULL DEFAULT 0,
            settings_json JSON NULL,
            created_by BIGINT UNSIGNED NULL,
            updated_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uk_mc_config_group_option (group_definition_id,option_code),
            KEY idx_mc_config_group_options_definition (group_definition_id,sort_order,is_enabled)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS mc_config_group_conditions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            template_group_id BIGINT UNSIGNED NOT NULL,
            source_field VARCHAR(120) NOT NULL,
            operator VARCHAR(30) NOT NULL DEFAULT 'eq',
            expected_value VARCHAR(240) NULL,
            action_type VARCHAR(40) NOT NULL DEFAULT 'show',
            action_target VARCHAR(120) NULL,
            condition_json JSON NULL,
            sort_order INT NOT NULL DEFAULT 100,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_by BIGINT UNSIGNED NULL,
            updated_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_mc_config_group_conditions_group (template_group_id,is_enabled,sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS mc_config_group_material_filters (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            template_group_id BIGINT UNSIGNED NOT NULL,
            material_category_code VARCHAR(80) NULL,
            material_subcategory_code VARCHAR(120) NULL,
            brand_limit VARCHAR(160) NULL,
            model_limit VARCHAR(160) NULL,
            installation_type VARCHAR(120) NULL,
            power_min DECIMAL(12,3) NULL,
            power_max DECIMAL(12,3) NULL,
            io_type VARCHAR(120) NULL,
            ip_rating VARCHAR(40) NULL,
            formal_status VARCHAR(60) NOT NULL DEFAULT 'official',
            approved_required TINYINT(1) NOT NULL DEFAULT 1,
            allow_pending TINYINT(1) NOT NULL DEFAULT 0,
            allow_alternative TINYINT(1) NOT NULL DEFAULT 1,
            filter_json JSON NULL,
            created_by BIGINT UNSIGNED NULL,
            updated_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_mc_config_group_material_filters_group (template_group_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS mc_config_template_versions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            template_id BIGINT UNSIGNED NOT NULL,
            version_no VARCHAR(40) NOT NULL,
            snapshot_json JSON NOT NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uk_mc_config_template_version (template_id,version_no),
            KEY idx_mc_config_template_versions_template (template_id,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS mc_config_template_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            template_id BIGINT UNSIGNED NULL,
            product_id BIGINT UNSIGNED NULL,
            action VARCHAR(80) NOT NULL,
            before_json JSON NULL,
            after_json JSON NULL,
            actor_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            KEY idx_mc_config_template_logs_template (template_id,created_at),
            KEY idx_mc_config_template_logs_product (product_id,created_at),
            KEY idx_mc_config_template_logs_action (action,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "INSERT INTO crm_permissions (permission_key,module,action,description,risk_level,created_at) VALUES
            ('config_template.view','config_template','view','查看产品分类配置模板',1,NOW()),
            ('config_template.create','config_template','create','新建产品分类配置模板',2,NOW()),
            ('config_template.edit','config_template','edit','编辑产品分类配置模板',2,NOW()),
            ('config_template.delete','config_template','delete','删除未使用草稿模板',3,NOW()),
            ('config_template.disable','config_template','disable','停用配置模板',3,NOW()),
            ('config_template.apply','config_template','apply','套用配置模板到产品',2,NOW()),
            ('config_template.create_group','config_template','create_group','新增配置组定义',2,NOW()),
            ('config_template.edit_group','config_template','edit_group','编辑配置组规则',2,NOW()),
            ('config_template.create_option','config_template','create_option','维护属性配置选项',2,NOW()),
            ('config_template.manage_condition','config_template','manage_condition','维护配置组显示条件',2,NOW()),
            ('config_template.publish','config_template','publish','发布配置模板版本',3,NOW())
            ON DUPLICATE KEY UPDATE module=VALUES(module),action=VALUES(action),description=VALUES(description),risk_level=VALUES(risk_level)",
        "INSERT IGNORE INTO crm_role_permissions(role_id,permission_key)
            SELECT r.id,p.permission_key FROM crm_roles r JOIN crm_permissions p
            WHERE r.role_key='admin' AND p.permission_key LIKE 'config_template.%'",
        "INSERT IGNORE INTO crm_role_permissions(role_id,permission_key)
            SELECT r.id,p.permission_key FROM crm_roles r JOIN crm_permissions p
            WHERE r.role_key IN('manager') AND p.permission_key IN('config_template.view','config_template.create','config_template.edit','config_template.apply','config_template.create_group','config_template.edit_group','config_template.create_option','config_template.manage_condition','config_template.publish')",
        "INSERT IGNORE INTO crm_role_permissions(role_id,permission_key)
            SELECT r.id,p.permission_key FROM crm_roles r JOIN crm_permissions p
            WHERE r.role_key IN('sales','marketing','finance','viewer') AND p.permission_key='config_template.view'",
        "INSERT INTO mc_config_group_definitions(group_code,group_name,group_type,business_type,material_category_code,description,icon,is_system,is_enabled,created_at,updated_at) VALUES
            ('light_source','芯片 / 光源','material','chip','chip','从正式芯片或光源物料中选择核心发光件','▦',1,1,NOW(),NOW()),
            ('optical_lens','光学 / 透镜','material','optical','optical','透镜、反光杯、光学件等正式物料','◉',1,1,NOW(),NOW()),
            ('connector_type','接头类型','attribute','custom',NULL,'普通导轨接头或 INTRACK 接头等属性选择','⇄',1,1,NOW(),NOW()),
            ('connector_wire','接头线制','attribute','custom',NULL,'2线、3线、4线、6线等线制选择','⑂',1,1,NOW(),NOW()),
            ('internal_driver','内置电源','material','power','power_supply','普通导轨灯内置电源正式物料','ϟ',1,1,NOW(),NOW()),
            ('intrack_driver','INTRACK电源','material','power','power_supply','INTRACK 系统专用电源正式物料','ϟ',1,1,NOW(),NOW()),
            ('accessories','配件','material','accessory','accessory','可选配件正式物料','＋',1,1,NOW(),NOW()),
            ('finish_color','外观颜色','attribute','color',NULL,'黑色、白色或自定义颜色属性','●',1,1,NOW(),NOW()),
            ('external_driver','外置电源','material','power','power_supply','嵌入式灯具外置电源正式物料','ϟ',1,1,NOW(),NOW()),
            ('mounting_spring','安装弹簧','material','installation','connector','嵌入式灯具安装弹簧或安装件','⌁',1,1,NOW(),NOW()),
            ('cutout_size','开孔尺寸','attribute','custom',NULL,'嵌入式产品开孔尺寸属性','□',1,1,NOW(),NOW()),
            ('honeycomb','蜂巢网','material','honeycomb','accessory','蜂巢网防眩附件','◎',1,1,NOW(),NOW()),
            ('protective_glass','玻璃','material','glass','optical','保护玻璃或面罩','▯',1,1,NOW(),NOW()),
            ('magnetic_head','磁吸头','material','installation','connector','磁吸系统头部或连接件','◆',1,1,NOW(),NOW()),
            ('magnetic_driver','磁吸式电源','material','power','power_supply','磁吸系统电源正式物料','ϟ',1,1,NOW(),NOW()),
            ('body_length','灯体长度','attribute','custom',NULL,'磁吸灯体长款 / 短款属性','━',1,1,NOW(),NOW()),
            ('magnetic_mounting','磁吸安装形式','attribute','installation',NULL,'磁吸灯嵌入式、明装式、吊装式安装属性','⌁',1,1,NOW(),NOW()),
            ('waterproof_structure','防水结构','mixed','custom','accessory','户外灯可扩展防水结构组，先选属性再筛正式物料','☔',1,1,NOW(),NOW())
            ON DUPLICATE KEY UPDATE group_name=VALUES(group_name),group_type=VALUES(group_type),business_type=VALUES(business_type),material_category_code=VALUES(material_category_code),description=VALUES(description),icon=VALUES(icon),is_system=VALUES(is_system),is_enabled=VALUES(is_enabled),updated_at=NOW()",
        "INSERT INTO mc_config_group_options(group_definition_id,option_code,option_name,is_default,is_enabled,sort_order,created_at,updated_at)
            SELECT d.id,x.option_code,x.option_name,x.is_default,1,x.sort_order,NOW(),NOW()
            FROM mc_config_group_definitions d
            JOIN (
                SELECT 'connector_type' group_code,'normal_track' option_code,'普通导轨接头' option_name,1 is_default,10 sort_order UNION ALL
                SELECT 'connector_type','intrack','INTRACK接头',0,20 UNION ALL
                SELECT 'connector_wire','2wire','2线',1,10 UNION ALL
                SELECT 'connector_wire','3wire','3线',0,20 UNION ALL
                SELECT 'connector_wire','4wire','4线',0,30 UNION ALL
                SELECT 'connector_wire','6wire','6线',0,40 UNION ALL
                SELECT 'finish_color','white','白色',1,10 UNION ALL
                SELECT 'finish_color','black','黑色',0,20 UNION ALL
                SELECT 'finish_color','custom','自定义颜色',0,30 UNION ALL
                SELECT 'body_length','short','短款',1,10 UNION ALL
                SELECT 'body_length','long','长款',0,20 UNION ALL
                SELECT 'magnetic_mounting','recessed','嵌入式',1,10 UNION ALL
                SELECT 'magnetic_mounting','surface','明装式',0,20 UNION ALL
                SELECT 'magnetic_mounting','pendant','吊装式',0,30
            ) x ON x.group_code=d.group_code
            ON DUPLICATE KEY UPDATE option_name=VALUES(option_name),is_default=VALUES(is_default),is_enabled=VALUES(is_enabled),sort_order=VALUES(sort_order),updated_at=NOW()",
        "INSERT INTO mc_config_templates(template_code,template_name,scope_type,product_type,version_no,status,is_enabled,description,created_at,updated_at) VALUES
            ('system_common','系统通用模板','system',NULL,'v1.0','active',1,'所有产品可继承的基础配置模板',NOW(),NOW()),
            ('track_lighting_template','导轨灯模板','category','导轨灯','v1.0','active',1,'导轨灯分类模板：光源、光学、接头类型、线制、内置/INTRACK电源和配件',NOW(),NOW()),
            ('recessed_lighting_template','嵌入式灯具模板','category','嵌入式灯具','v1.0','active',1,'嵌入式灯具分类模板：光源、光学、外置电源、弹簧、开孔、蜂巢网、玻璃和配件',NOW(),NOW()),
            ('magnetic_lighting_template','磁吸式灯具模板','category','磁吸式灯具','v1.0','active',1,'磁吸式灯具分类模板：磁吸头、磁吸电源、芯片、光学、灯体长度和安装形式',NOW(),NOW())
            ON DUPLICATE KEY UPDATE template_name=VALUES(template_name),scope_type=VALUES(scope_type),product_type=VALUES(product_type),version_no=VALUES(version_no),status=VALUES(status),is_enabled=VALUES(is_enabled),description=VALUES(description),updated_at=NOW()",
        "INSERT INTO mc_config_template_groups(template_id,group_definition_id,group_code,is_required,selection_mode,allow_empty,min_select,max_select,allow_default,salesperson_editable,customer_selectable,affects_price,affects_lead_time,requires_approval,sort_order,is_enabled,settings_json,created_at,updated_at)
            SELECT t.id,d.id,d.group_code,x.is_required,x.selection_mode,x.allow_empty,x.min_select,x.max_select,1,1,x.customer_selectable,x.affects_price,x.affects_lead_time,x.requires_approval,x.sort_order,1,JSON_OBJECT('inheritance','seed'),NOW(),NOW()
            FROM mc_config_templates t
            JOIN (
                SELECT 'system_common' template_code,'light_source' group_code,1 is_required,'single' selection_mode,0 allow_empty,1 min_select,1 max_select,0 customer_selectable,1 affects_price,1 affects_lead_time,1 requires_approval,10 sort_order UNION ALL
                SELECT 'system_common','optical_lens',1,'single',0,1,1,0,1,0,0,20 UNION ALL
                SELECT 'track_lighting_template','light_source',1,'single',0,1,1,0,1,1,1,10 UNION ALL
                SELECT 'track_lighting_template','optical_lens',1,'single',0,1,1,0,1,0,0,20 UNION ALL
                SELECT 'track_lighting_template','connector_type',1,'single',0,1,1,1,1,0,0,30 UNION ALL
                SELECT 'track_lighting_template','connector_wire',1,'single',0,1,1,1,0,0,0,40 UNION ALL
                SELECT 'track_lighting_template','internal_driver',0,'single',1,0,1,0,1,1,1,50 UNION ALL
                SELECT 'track_lighting_template','intrack_driver',0,'single',1,0,1,0,1,1,1,60 UNION ALL
                SELECT 'track_lighting_template','accessories',0,'multi',1,0,12,1,1,1,0,70 UNION ALL
                SELECT 'track_lighting_template','finish_color',0,'multi',1,0,8,1,1,0,0,80 UNION ALL
                SELECT 'recessed_lighting_template','light_source',1,'single',0,1,1,0,1,1,1,10 UNION ALL
                SELECT 'recessed_lighting_template','optical_lens',1,'single',0,1,1,0,1,0,0,20 UNION ALL
                SELECT 'recessed_lighting_template','external_driver',1,'single',0,1,1,0,1,1,1,30 UNION ALL
                SELECT 'recessed_lighting_template','mounting_spring',1,'single',0,1,1,0,0,0,0,40 UNION ALL
                SELECT 'recessed_lighting_template','cutout_size',0,'single',1,0,1,1,0,0,0,50 UNION ALL
                SELECT 'recessed_lighting_template','honeycomb',0,'single',1,0,1,1,1,0,0,60 UNION ALL
                SELECT 'recessed_lighting_template','protective_glass',0,'single',1,0,1,1,1,0,0,70 UNION ALL
                SELECT 'recessed_lighting_template','accessories',0,'multi',1,0,12,1,1,1,0,80 UNION ALL
                SELECT 'recessed_lighting_template','finish_color',0,'multi',1,0,8,1,1,0,0,90 UNION ALL
                SELECT 'magnetic_lighting_template','magnetic_head',1,'single',0,1,1,0,1,1,1,10 UNION ALL
                SELECT 'magnetic_lighting_template','magnetic_driver',1,'single',0,1,1,0,1,1,1,20 UNION ALL
                SELECT 'magnetic_lighting_template','light_source',1,'single',0,1,1,0,1,1,1,30 UNION ALL
                SELECT 'magnetic_lighting_template','optical_lens',0,'single',1,0,1,0,1,0,0,40 UNION ALL
                SELECT 'magnetic_lighting_template','body_length',1,'single',0,1,1,1,1,0,0,50 UNION ALL
                SELECT 'magnetic_lighting_template','magnetic_mounting',0,'single',1,0,1,1,0,0,0,60 UNION ALL
                SELECT 'magnetic_lighting_template','finish_color',0,'multi',1,0,8,1,1,0,0,70
            ) x ON x.template_code=t.template_code
            JOIN mc_config_group_definitions d ON d.group_code=x.group_code
            ON DUPLICATE KEY UPDATE is_required=VALUES(is_required),selection_mode=VALUES(selection_mode),allow_empty=VALUES(allow_empty),min_select=VALUES(min_select),max_select=VALUES(max_select),customer_selectable=VALUES(customer_selectable),affects_price=VALUES(affects_price),affects_lead_time=VALUES(affects_lead_time),requires_approval=VALUES(requires_approval),sort_order=VALUES(sort_order),is_enabled=VALUES(is_enabled),updated_at=NOW()",
        "INSERT INTO mc_config_group_conditions(template_group_id,source_field,operator,expected_value,action_type,action_target,condition_json,sort_order,is_enabled,created_at,updated_at)
            SELECT tg.id,x.source_field,x.operator,x.expected_value,x.action_type,x.action_target,JSON_OBJECT('seed',1),x.sort_order,1,NOW(),NOW()
            FROM mc_config_templates t
            JOIN mc_config_template_groups tg ON tg.template_id=t.id
            JOIN (
                SELECT 'track_lighting_template' template_code,'connector_wire' group_code,'connector_type' source_field,'in' operator,'normal_track,intrack' expected_value,'show' action_type,'connector_wire' action_target,10 sort_order UNION ALL
                SELECT 'track_lighting_template','internal_driver','connector_type','eq','normal_track','show','internal_driver',10 UNION ALL
                SELECT 'track_lighting_template','intrack_driver','connector_type','eq','intrack','show','intrack_driver',10
            ) x ON x.template_code=t.template_code AND x.group_code=tg.group_code
            WHERE NOT EXISTS(SELECT 1 FROM mc_config_group_conditions c WHERE c.template_group_id=tg.id AND c.source_field=x.source_field AND c.expected_value=x.expected_value AND c.action_type=x.action_type)",
        "INSERT INTO mc_config_group_material_filters(template_group_id,material_category_code,material_subcategory_code,installation_type,formal_status,approved_required,allow_pending,allow_alternative,filter_json,created_at,updated_at)
            SELECT tg.id,COALESCE(d.material_category_code,x.material_category_code),x.material_subcategory_code,x.installation_type,'official',1,0,1,JSON_OBJECT('system_type',x.system_type,'seed',1),NOW(),NOW()
            FROM mc_config_template_groups tg
            JOIN mc_config_group_definitions d ON d.id=tg.group_definition_id
            LEFT JOIN (
                SELECT 'internal_driver' group_code,'power_supply' material_category_code,NULL material_subcategory_code,'内置' installation_type,'normal' system_type UNION ALL
                SELECT 'external_driver','power_supply',NULL,'外置','external' UNION ALL
                SELECT 'intrack_driver','power_supply',NULL,'INTRACK','intrack' UNION ALL
                SELECT 'magnetic_driver','power_supply',NULL,'磁吸','magnetic' UNION ALL
                SELECT 'magnetic_head','connector',NULL,'磁吸','magnetic' UNION ALL
                SELECT 'connector_wire','connector',NULL,'导轨','track' UNION ALL
                SELECT 'mounting_spring','connector',NULL,'嵌入式','recessed'
            ) x ON x.group_code=tg.group_code
            WHERE d.group_type IN('material','mixed') AND NOT EXISTS(SELECT 1 FROM mc_config_group_material_filters f WHERE f.template_group_id=tg.id)",
    ],
    'down' => [
        "DELETE FROM crm_role_permissions WHERE permission_key LIKE 'config_template.%'",
        "DELETE FROM crm_permissions WHERE permission_key LIKE 'config_template.%'",
        "DROP TABLE IF EXISTS mc_config_template_logs",
        "DROP TABLE IF EXISTS mc_config_template_versions",
        "DROP TABLE IF EXISTS mc_config_group_material_filters",
        "DROP TABLE IF EXISTS mc_config_group_conditions",
        "DROP TABLE IF EXISTS mc_config_group_options",
        "DROP TABLE IF EXISTS mc_config_template_groups",
        "DROP TABLE IF EXISTS mc_config_group_definitions",
        "DROP TABLE IF EXISTS mc_config_templates",
    ],
];
