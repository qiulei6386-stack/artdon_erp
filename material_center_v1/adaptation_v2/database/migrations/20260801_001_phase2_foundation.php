<?php
declare(strict_types=1);

return [
    'version' => '20260801_001_phase2_foundation',
    'description' => 'Product adaptation V2 phase 2 foundation categories and group definitions',
    'up' => [
        "CREATE TABLE IF NOT EXISTS mc_pa2_product_categories (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            category_code VARCHAR(80) NOT NULL,
            category_name VARCHAR(160) NOT NULL,
            parent_id BIGINT UNSIGNED NULL,
            description VARCHAR(600) NULL,
            default_template_id BIGINT UNSIGNED NULL,
            sort_order INT NOT NULL DEFAULT 100,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_by BIGINT UNSIGNED NULL,
            updated_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uk_mc_pa2_category_code (category_code),
            KEY idx_mc_pa2_categories_parent (parent_id,sort_order),
            KEY idx_mc_pa2_categories_enabled (is_enabled,sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS mc_pa2_product_category_mappings (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_id BIGINT UNSIGNED NOT NULL,
            category_id BIGINT UNSIGNED NOT NULL,
            category_code VARCHAR(80) NOT NULL,
            category_name VARCHAR(160) NOT NULL,
            series_code VARCHAR(120) NULL,
            source_type VARCHAR(40) NOT NULL DEFAULT 'manual',
            confidence DECIMAL(5,2) NOT NULL DEFAULT 100.00,
            confirmed_by BIGINT UNSIGNED NULL,
            confirmed_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uk_mc_pa2_product_mapping (product_id),
            KEY idx_mc_pa2_mapping_category (category_id,series_code),
            KEY idx_mc_pa2_mapping_source (source_type,confidence)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS mc_pa2_group_definitions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            group_code VARCHAR(80) NOT NULL,
            group_name VARCHAR(160) NOT NULL,
            group_type VARCHAR(40) NOT NULL DEFAULT 'material_select',
            icon VARCHAR(40) NULL,
            description VARCHAR(600) NULL,
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 100,
            created_by BIGINT UNSIGNED NULL,
            updated_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uk_mc_pa2_group_code (group_code),
            KEY idx_mc_pa2_group_type (group_type,is_enabled,sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS mc_pa2_group_option_definitions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            group_definition_id BIGINT UNSIGNED NOT NULL,
            option_code VARCHAR(80) NOT NULL,
            option_name VARCHAR(160) NOT NULL,
            option_image VARCHAR(500) NULL,
            description VARCHAR(600) NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 100,
            price_effect_json JSON NULL,
            lead_time_effect_json JSON NULL,
            settings_json JSON NULL,
            created_by BIGINT UNSIGNED NULL,
            updated_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uk_mc_pa2_group_option (group_definition_id,option_code),
            KEY idx_mc_pa2_group_options_group (group_definition_id,sort_order,is_enabled)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "INSERT INTO crm_permissions(permission_key,module,action,description,risk_level,created_at) VALUES
            ('adaptation_v2.view','adaptation_v2','view','产品适配 V2 - 查看','low',NOW()),
            ('adaptation_v2.manage_category','adaptation_v2','manage_category','产品适配 V2 - 维护产品分类','high',NOW()),
            ('adaptation_v2.manage_group_definition','adaptation_v2','manage_group_definition','产品适配 V2 - 维护配置组定义','high',NOW()),
            ('adaptation_v2.manage_template','adaptation_v2','manage_template','产品适配 V2 - 维护模板','high',NOW()),
            ('adaptation_v2.publish_template','adaptation_v2','publish_template','产品适配 V2 - 发布模板','high',NOW()),
            ('adaptation_v2.configure_product','adaptation_v2','configure_product','产品适配 V2 - 配置产品','high',NOW()),
            ('adaptation_v2.override_product','adaptation_v2','override_product','产品适配 V2 - 产品级覆盖','high',NOW()),
            ('adaptation_v2.select_material','adaptation_v2','select_material','产品适配 V2 - 选择正式物料','medium',NOW()),
            ('adaptation_v2.override_conflict','adaptation_v2','override_conflict','产品适配 V2 - 冲突例外','dangerous',NOW()),
            ('adaptation_v2.manage_rule','adaptation_v2','manage_rule','产品适配 V2 - 维护规则','high',NOW()),
            ('adaptation_v2.manage_package','adaptation_v2','manage_package','产品适配 V2 - 维护配置包','high',NOW()),
            ('adaptation_v2.approve','adaptation_v2','approve','产品适配 V2 - 审批','high',NOW()),
            ('adaptation_v2.publish','adaptation_v2','publish','产品适配 V2 - 渠道发布','high',NOW()),
            ('adaptation_v2.view_price','adaptation_v2','view_price','产品适配 V2 - 查看价格影响','high',NOW()),
            ('adaptation_v2.manage_channel','adaptation_v2','manage_channel','产品适配 V2 - 维护渠道','high',NOW()),
            ('adaptation_v2.view_log','adaptation_v2','view_log','产品适配 V2 - 查看日志','low',NOW())
            ON DUPLICATE KEY UPDATE module=VALUES(module),action=VALUES(action),description=VALUES(description),risk_level=VALUES(risk_level)",
        "INSERT IGNORE INTO crm_role_permissions(role_id,permission_key)
            SELECT r.id,p.permission_key FROM crm_roles r JOIN crm_permissions p
            WHERE r.role_key IN('admin','super_admin') AND p.permission_key LIKE 'adaptation_v2.%'",
        "INSERT IGNORE INTO crm_role_permissions(role_id,permission_key)
            SELECT r.id,p.permission_key FROM crm_roles r JOIN crm_permissions p
            WHERE r.role_key IN('manager','engineer','engineering') AND p.permission_key IN(
                'adaptation_v2.view','adaptation_v2.manage_category','adaptation_v2.manage_group_definition','adaptation_v2.configure_product','adaptation_v2.select_material','adaptation_v2.view_log'
            )",
        "INSERT IGNORE INTO crm_role_permissions(role_id,permission_key)
            SELECT r.id,p.permission_key FROM crm_roles r JOIN crm_permissions p
            WHERE r.role_key IN('sales','marketing','finance','viewer') AND p.permission_key='adaptation_v2.view'",
        "INSERT INTO mc_pa2_product_categories(category_code,category_name,parent_id,description,sort_order,is_enabled,created_at,updated_at) VALUES
            ('track_light','导轨灯',NULL,'商业照明导轨灯产品分类；后续模板可区分普通导轨和 INTRACK。',10,1,NOW(),NOW()),
            ('recessed','嵌入式',NULL,'嵌入式筒灯、射灯、无边灯具等。',20,1,NOW(),NOW()),
            ('magnetic','磁吸式',NULL,'磁吸灯、磁吸轨道系统灯具。',30,1,NOW(),NOW()),
            ('surface_mounted','明装式',NULL,'明装筒灯、明装射灯、明装线性灯。',40,1,NOW(),NOW()),
            ('linear','线性',NULL,'线性灯、型材灯、条形灯。',50,1,NOW(),NOW()),
            ('led_strip','灯带',NULL,'软灯带、硬灯条及配套系统。',60,1,NOW(),NOW()),
            ('outdoor','户外',NULL,'户外灯具及防水结构产品。',70,1,NOW(),NOW()),
            ('cabinet','柜体灯',NULL,'柜体照明、展示照明。',80,1,NOW(),NOW()),
            ('power_supply','电源',NULL,'独立电源或驱动类产品。',90,1,NOW(),NOW()),
            ('accessory','配件',NULL,'接头、附件、安装件、包装配件等。',100,1,NOW(),NOW())
            ON DUPLICATE KEY UPDATE category_name=VALUES(category_name),description=VALUES(description),sort_order=VALUES(sort_order),is_enabled=VALUES(is_enabled),updated_at=NOW()",
        "INSERT INTO mc_pa2_group_definitions(group_code,group_name,group_type,icon,description,is_system,is_enabled,sort_order,created_at,updated_at) VALUES
            ('chip','芯片 / 光源','material_select','▦','从正式芯片或光源物料中选择核心发光件。',1,1,10,NOW(),NOW()),
            ('driver','电源 / 驱动','material_select','ϟ','内置、外置、INTRACK 或磁吸系统电源。',1,1,20,NOW(),NOW()),
            ('external_driver','外置电源','material_select','ϟ','嵌入式灯具外置电源。',1,1,30,NOW(),NOW()),
            ('intrack_driver','INTRACK 电源','material_select','ϟ','INTRACK 系统专用电源。',1,1,40,NOW(),NOW()),
            ('optical','光学 / 透镜','material_select','◉','透镜、反光杯、光学组件。',1,1,50,NOW(),NOW()),
            ('track_connector','普通导轨接头','material_select','⇄','普通导轨接头及连接件。',1,1,60,NOW(),NOW()),
            ('intrack_connector','INTRACK 接头','material_select','⇄','INTRACK 接头和线制相关连接件。',1,1,70,NOW(),NOW()),
            ('magnetic_head','磁吸头','material_select','◆','磁吸系统头部或连接模块。',1,1,80,NOW(),NOW()),
            ('body_length','灯体长度','enum_select','━','短款、长款或自定义长度属性。',1,1,90,NOW(),NOW()),
            ('profile','型材','material_select','▰','线性灯或灯带用型材。',1,1,100,NOW(),NOW()),
            ('led_strip','灯带','material_select','≈','灯带或灯板物料。',1,1,110,NOW(),NOW()),
            ('diffuser','扩散罩','material_select','▯','扩散罩、玻璃或面罩。',1,1,120,NOW(),NOW()),
            ('hanging_wire','吊线','material_select','⌁','吊装线材和安装附件。',1,1,130,NOW(),NOW()),
            ('end_cap','端盖','material_select','▣','端盖、尾盖、封口配件。',1,1,140,NOW(),NOW()),
            ('installation','安装方式','enum_select','⌁','嵌入式、明装、吊装、轨道等安装方式。',1,1,150,NOW(),NOW()),
            ('finish_color','外观颜色','enum_select','●','白色、黑色或自定义颜色。',1,1,160,NOW(),NOW()),
            ('dimming','调光方式','enum_select','◐','不调光、DALI、0-10V、TRIAC 等调光方式。',1,1,170,NOW(),NOW()),
            ('special_requirement','特殊要求','text_input','✦','客户或项目特殊要求。',1,1,180,NOW(),NOW())
            ON DUPLICATE KEY UPDATE group_name=VALUES(group_name),group_type=VALUES(group_type),icon=VALUES(icon),description=VALUES(description),is_system=VALUES(is_system),is_enabled=VALUES(is_enabled),sort_order=VALUES(sort_order),updated_at=NOW()",
        "INSERT INTO mc_pa2_group_option_definitions(group_definition_id,option_code,option_name,description,is_default,is_enabled,sort_order,created_at,updated_at)
            SELECT d.id,x.option_code,x.option_name,x.description,x.is_default,1,x.sort_order,NOW(),NOW()
            FROM mc_pa2_group_definitions d
            JOIN (
                SELECT 'body_length' group_code,'short' option_code,'短款' option_name,'磁吸灯短款灯体。' description,1 is_default,10 sort_order UNION ALL
                SELECT 'body_length','long','长款','磁吸灯长款灯体。',0,20 UNION ALL
                SELECT 'installation','recessed','嵌入式','嵌入式安装。',1,10 UNION ALL
                SELECT 'installation','surface','明装式','明装安装。',0,20 UNION ALL
                SELECT 'installation','track','导轨式','导轨安装。',0,30 UNION ALL
                SELECT 'installation','pendant','吊装式','吊装安装。',0,40 UNION ALL
                SELECT 'finish_color','white','白色','标准白色。',1,10 UNION ALL
                SELECT 'finish_color','black','黑色','标准黑色。',0,20 UNION ALL
                SELECT 'finish_color','custom','自定义颜色','客户或项目指定颜色。',0,30 UNION ALL
                SELECT 'dimming','non_dim','不调光','标准不调光。',1,10 UNION ALL
                SELECT 'dimming','dali','DALI','DALI 调光。',0,20 UNION ALL
                SELECT 'dimming','zero_to_ten','0-10V','0-10V 调光。',0,30 UNION ALL
                SELECT 'dimming','triac','TRIAC','可控硅调光。',0,40
            ) x ON x.group_code=d.group_code
            ON DUPLICATE KEY UPDATE option_name=VALUES(option_name),description=VALUES(description),is_default=VALUES(is_default),is_enabled=VALUES(is_enabled),sort_order=VALUES(sort_order),updated_at=NOW()",
    ],
    'down' => [
        "DELETE FROM crm_role_permissions WHERE permission_key LIKE 'adaptation_v2.%'",
        "DELETE FROM crm_permissions WHERE permission_key LIKE 'adaptation_v2.%'",
        "DROP TABLE IF EXISTS mc_pa2_group_option_definitions",
        "DROP TABLE IF EXISTS mc_pa2_group_definitions",
        "DROP TABLE IF EXISTS mc_pa2_product_category_mappings",
        "DROP TABLE IF EXISTS mc_pa2_product_categories",
    ],
];
