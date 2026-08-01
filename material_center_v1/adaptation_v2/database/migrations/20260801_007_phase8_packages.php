<?php
declare(strict_types=1);

return [
    'version' => '20260801_007_phase8_packages',
    'description' => 'Product adaptation V2 phase 8 channel configuration package center',
    'up' => [
        "CREATE TABLE IF NOT EXISTS mc_pa2_config_packages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            package_code VARCHAR(100) NOT NULL,
            package_name VARCHAR(180) NOT NULL,
            channel_code VARCHAR(80) NOT NULL,
            package_type VARCHAR(80) NOT NULL,
            description VARCHAR(800) NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'draft',
            active_version_id BIGINT UNSIGNED NULL,
            valid_from DATE NULL,
            valid_to DATE NULL,
            created_by BIGINT UNSIGNED NULL,
            updated_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uk_mc_pa2_package_code (package_code),
            KEY idx_mc_pa2_packages_channel (channel_code,package_type,status),
            KEY idx_mc_pa2_packages_active_version (active_version_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS mc_pa2_config_package_versions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            package_id BIGINT UNSIGNED NOT NULL,
            version_no VARCHAR(60) NOT NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'draft',
            source_product_config_version_id BIGINT UNSIGNED NULL,
            snapshot_json JSON NULL,
            package_rules_json JSON NULL,
            created_by BIGINT UNSIGNED NULL,
            published_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            published_at DATETIME NULL,
            UNIQUE KEY uk_mc_pa2_package_version (package_id,version_no),
            KEY idx_mc_pa2_package_versions_package (package_id,status,created_at),
            KEY idx_mc_pa2_package_versions_source (source_product_config_version_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS mc_pa2_config_package_groups (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            package_version_id BIGINT UNSIGNED NOT NULL,
            group_code VARCHAR(80) NOT NULL,
            group_definition_id BIGINT UNSIGNED NULL,
            display_name VARCHAR(180) NOT NULL,
            lock_mode VARCHAR(40) NOT NULL DEFAULT 'open',
            is_required TINYINT(1) NOT NULL DEFAULT 0,
            allow_empty TINYINT(1) NOT NULL DEFAULT 1,
            min_select INT NOT NULL DEFAULT 0,
            max_select INT NOT NULL DEFAULT 1,
            allowed_scope_json JSON NULL,
            default_selection_json JSON NULL,
            price_rule_json JSON NULL,
            inventory_rule_json JSON NULL,
            moq_rule_json JSON NULL,
            lead_time_rule_json JSON NULL,
            sort_order INT NOT NULL DEFAULT 100,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uk_mc_pa2_package_group (package_version_id,group_code),
            KEY idx_mc_pa2_package_groups_definition (group_definition_id,lock_mode),
            KEY idx_mc_pa2_package_groups_sort (package_version_id,sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS mc_pa2_config_package_options (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            package_group_id BIGINT UNSIGNED NOT NULL,
            option_key VARCHAR(120) NOT NULL,
            option_type VARCHAR(40) NOT NULL DEFAULT 'attribute',
            material_id BIGINT UNSIGNED NULL,
            option_definition_id BIGINT UNSIGNED NULL,
            option_code VARCHAR(120) NULL,
            option_label VARCHAR(220) NOT NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            is_locked TINYINT(1) NOT NULL DEFAULT 0,
            price_delta DECIMAL(14,4) NULL,
            currency VARCHAR(12) NULL,
            moq INT NULL,
            stock_qty INT NULL,
            lead_time_days INT NULL,
            valid_from DATE NULL,
            valid_to DATE NULL,
            rule_json JSON NULL,
            sort_order INT NOT NULL DEFAULT 100,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uk_mc_pa2_package_option (package_group_id,option_key),
            KEY idx_mc_pa2_package_options_material (material_id),
            KEY idx_mc_pa2_package_options_rule (option_type,is_locked,is_default)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "INSERT INTO mc_pa2_config_packages(package_code,package_name,channel_code,package_type,description,status,created_at,updated_at) VALUES
            ('commercial_flexible','Commercial Flexible','commercial','commercial_flexible','商务中心灵活配置包：保留核心配置组，默认开放选择，允许按项目调整价格、MOQ、库存和交期。','draft',NOW(),NOW()),
            ('singapore_standard','Singapore Standard','singapore','singapore_standard','新加坡标准品配置包：固定核心结构，只开放指定光学角度和外观颜色范围。','draft',NOW(),NOW()),
            ('singapore_dali','Singapore DALI','singapore','singapore_dali','新加坡 DALI 配置包：固定 DALI 调光和 DALI 电源规则，用于 DALI 渠道商品。','draft',NOW(),NOW()),
            ('singapore_ready_stock','Singapore Ready Stock','singapore','singapore_ready_stock','新加坡现货配置包：关键物料全部锁定，并要求库存、MOQ 和交期规则可读。','draft',NOW(),NOW())
            ON DUPLICATE KEY UPDATE package_name=VALUES(package_name),channel_code=VALUES(channel_code),package_type=VALUES(package_type),description=VALUES(description),updated_at=NOW()",
        "INSERT INTO mc_pa2_config_package_versions(package_id,version_no,status,snapshot_json,package_rules_json,created_at)
            SELECT p.id,'draft-1','draft',
                JSON_OBJECT('seed','phase8','package_code',p.package_code,'package_type',p.package_type),
                JSON_OBJECT('lock_policy','channel_package','price','package_option_or_group_rule','moq','package_option_or_group_rule','inventory','package_option_or_group_rule','lead_time','package_option_or_group_rule'),
                NOW()
            FROM mc_pa2_config_packages p
            WHERE NOT EXISTS (
                SELECT 1 FROM mc_pa2_config_package_versions v WHERE v.package_id=p.id AND v.version_no='draft-1'
            )",
        "UPDATE mc_pa2_config_packages p
            JOIN mc_pa2_config_package_versions v ON v.package_id=p.id AND v.version_no='draft-1'
            SET p.active_version_id=v.id, p.updated_at=NOW()
            WHERE p.active_version_id IS NULL",
        "INSERT INTO mc_pa2_config_package_groups(package_version_id,group_code,group_definition_id,display_name,lock_mode,is_required,allow_empty,min_select,max_select,allowed_scope_json,default_selection_json,price_rule_json,inventory_rule_json,moq_rule_json,lead_time_rule_json,sort_order,created_at,updated_at)
            SELECT v.id,x.group_code,d.id,x.display_name,x.lock_mode,x.is_required,x.allow_empty,x.min_select,x.max_select,x.allowed_scope_json,x.default_selection_json,x.price_rule_json,x.inventory_rule_json,x.moq_rule_json,x.lead_time_rule_json,x.sort_order,NOW(),NOW()
            FROM mc_pa2_config_package_versions v
            JOIN mc_pa2_config_packages p ON p.id=v.package_id AND p.active_version_id=v.id
            JOIN (
                SELECT 'commercial_flexible' package_code,'chip' group_code,'芯片 / 光源' display_name,'open' lock_mode,1 is_required,0 allow_empty,1 min_select,1 max_select,'{\"scope\":\"all_official_materials\"}' allowed_scope_json,'{}' default_selection_json,'{\"mode\":\"inherit_product\"}' price_rule_json,'{\"mode\":\"optional\"}' inventory_rule_json,'{\"mode\":\"optional\"}' moq_rule_json,'{\"mode\":\"optional\"}' lead_time_rule_json,10 sort_order UNION ALL
                SELECT 'commercial_flexible','driver','电源 / 驱动','open',1,0,1,1,'{\"scope\":\"all_official_materials\"}','{}','{\"mode\":\"inherit_product\"}','{\"mode\":\"optional\"}','{\"mode\":\"optional\"}','{\"mode\":\"optional\"}',20 UNION ALL
                SELECT 'commercial_flexible','optical','光学 / 透镜','open',1,0,1,1,'{\"scope\":\"all_official_materials\"}','{}','{\"mode\":\"inherit_product\"}','{\"mode\":\"optional\"}','{\"mode\":\"optional\"}','{\"mode\":\"optional\"}',30 UNION ALL
                SELECT 'commercial_flexible','finish_color','外观颜色','range_limited',0,1,0,1,'{\"option_codes\":[\"white\",\"black\",\"custom\"]}','{\"option_code\":\"white\"}','{\"mode\":\"option_delta\"}','{\"mode\":\"optional\"}','{\"mode\":\"optional\"}','{\"mode\":\"optional\"}',40 UNION ALL
                SELECT 'commercial_flexible','dimming','调光方式','range_limited',0,1,0,1,'{\"option_codes\":[\"non_dim\",\"dali\",\"zero_to_ten\",\"triac\"]}','{\"option_code\":\"non_dim\"}','{\"mode\":\"option_delta\"}','{\"mode\":\"optional\"}','{\"mode\":\"optional\"}','{\"mode\":\"optional\"}',50 UNION ALL
                SELECT 'singapore_standard','chip','芯片 / 光源','default_locked',1,0,1,1,'{\"scope\":\"product_published_default\"}','{\"source\":\"published_product_default\"}','{\"mode\":\"lock_default\"}','{\"mode\":\"optional\"}','{\"mode\":\"standard\"}','{\"mode\":\"standard\"}',10 UNION ALL
                SELECT 'singapore_standard','driver','电源 / 驱动','default_locked',1,0,1,1,'{\"scope\":\"product_published_default\"}','{\"source\":\"published_product_default\"}','{\"mode\":\"lock_default\"}','{\"mode\":\"optional\"}','{\"mode\":\"standard\"}','{\"mode\":\"standard\"}',20 UNION ALL
                SELECT 'singapore_standard','optical','光学 / 透镜','range_limited',1,0,1,1,'{\"beam_angles\":[15,24,36,50]}','{\"beam_angle\":24}','{\"mode\":\"option_delta\"}','{\"mode\":\"optional\"}','{\"mode\":\"standard\"}','{\"mode\":\"standard\"}',30 UNION ALL
                SELECT 'singapore_standard','finish_color','外观颜色','range_limited',1,0,1,1,'{\"option_codes\":[\"white\",\"black\"]}','{\"option_code\":\"white\"}','{\"mode\":\"option_delta\"}','{\"mode\":\"optional\"}','{\"mode\":\"standard\"}','{\"mode\":\"standard\"}',40 UNION ALL
                SELECT 'singapore_dali','driver','电源 / 驱动','locked',1,0,1,1,'{\"driver_type\":\"DALI\",\"input\":\"220-240V\"}','{\"option_code\":\"dali_driver\"}','{\"mode\":\"lock_default\"}','{\"mode\":\"optional\"}','{\"mode\":\"dali\"}','{\"mode\":\"dali\"}',10 UNION ALL
                SELECT 'singapore_dali','dimming','调光方式','locked',1,0,1,1,'{\"option_codes\":[\"dali\"]}','{\"option_code\":\"dali\"}','{\"mode\":\"lock_default\"}','{\"mode\":\"optional\"}','{\"mode\":\"dali\"}','{\"mode\":\"dali\"}',20 UNION ALL
                SELECT 'singapore_dali','chip','芯片 / 光源','default_locked',1,0,1,1,'{\"scope\":\"product_published_default\"}','{\"source\":\"published_product_default\"}','{\"mode\":\"lock_default\"}','{\"mode\":\"optional\"}','{\"mode\":\"dali\"}','{\"mode\":\"dali\"}',30 UNION ALL
                SELECT 'singapore_dali','optical','光学 / 透镜','range_limited',1,0,1,1,'{\"beam_angles\":[24,36]}','{\"beam_angle\":24}','{\"mode\":\"option_delta\"}','{\"mode\":\"optional\"}','{\"mode\":\"dali\"}','{\"mode\":\"dali\"}',40 UNION ALL
                SELECT 'singapore_ready_stock','chip','芯片 / 光源','locked',1,0,1,1,'{\"scope\":\"ready_stock_locked\"}','{\"source\":\"ready_stock_default\"}','{\"mode\":\"lock_default\"}','{\"require_stock\":true,\"min_stock\":1}','{\"mode\":\"ready_stock\",\"min_moq\":1}','{\"mode\":\"stock\",\"max_days\":3}',10 UNION ALL
                SELECT 'singapore_ready_stock','driver','电源 / 驱动','locked',1,0,1,1,'{\"scope\":\"ready_stock_locked\"}','{\"source\":\"ready_stock_default\"}','{\"mode\":\"lock_default\"}','{\"require_stock\":true,\"min_stock\":1}','{\"mode\":\"ready_stock\",\"min_moq\":1}','{\"mode\":\"stock\",\"max_days\":3}',20 UNION ALL
                SELECT 'singapore_ready_stock','optical','光学 / 透镜','locked',1,0,1,1,'{\"scope\":\"ready_stock_locked\"}','{\"source\":\"ready_stock_default\"}','{\"mode\":\"lock_default\"}','{\"require_stock\":true,\"min_stock\":1}','{\"mode\":\"ready_stock\",\"min_moq\":1}','{\"mode\":\"stock\",\"max_days\":3}',30 UNION ALL
                SELECT 'singapore_ready_stock','finish_color','外观颜色','locked',1,0,1,1,'{\"option_codes\":[\"white\",\"black\"]}','{\"option_code\":\"white\"}','{\"mode\":\"lock_default\"}','{\"require_stock\":true,\"min_stock\":1}','{\"mode\":\"ready_stock\",\"min_moq\":1}','{\"mode\":\"stock\",\"max_days\":3}',40
            ) x ON x.package_code=p.package_code
            LEFT JOIN mc_pa2_group_definitions d ON d.group_code=x.group_code
            WHERE NOT EXISTS (
                SELECT 1 FROM mc_pa2_config_package_groups g WHERE g.package_version_id=v.id AND g.group_code=x.group_code
            )",
        "INSERT INTO mc_pa2_config_package_options(package_group_id,option_key,option_type,option_code,option_label,is_default,is_locked,price_delta,currency,moq,stock_qty,lead_time_days,rule_json,sort_order,created_at,updated_at)
            SELECT g.id,x.option_key,x.option_type,x.option_code,x.option_label,x.is_default,x.is_locked,x.price_delta,x.currency,x.moq,x.stock_qty,x.lead_time_days,x.rule_json,x.sort_order,NOW(),NOW()
            FROM mc_pa2_config_package_groups g
            JOIN mc_pa2_config_package_versions v ON v.id=g.package_version_id
            JOIN mc_pa2_config_packages p ON p.id=v.package_id AND p.active_version_id=v.id
            JOIN (
                SELECT 'commercial_flexible' package_code,'finish_color' group_code,'color_white' option_key,'attribute' option_type,'white' option_code,'白色' option_label,1 is_default,0 is_locked,0.0000 price_delta,'USD' currency,NULL moq,NULL stock_qty,NULL lead_time_days,'{\"source\":\"group_option\"}' rule_json,10 sort_order UNION ALL
                SELECT 'commercial_flexible','finish_color','color_black','attribute','black','黑色',0,0,0.0000,'USD',NULL,NULL,NULL,'{\"source\":\"group_option\"}',20 UNION ALL
                SELECT 'commercial_flexible','finish_color','color_custom','attribute','custom','自定义颜色',0,0,0.0000,'USD',NULL,NULL,NULL,'{\"source\":\"group_option\"}',30 UNION ALL
                SELECT 'singapore_standard','optical','beam_24','attribute','beam_24','24° 光学',1,0,0.0000,'SGD',100,NULL,14,'{\"scope\":\"specified_optics\"}',10 UNION ALL
                SELECT 'singapore_standard','optical','beam_36','attribute','beam_36','36° 光学',0,0,0.0000,'SGD',100,NULL,14,'{\"scope\":\"specified_optics\"}',20 UNION ALL
                SELECT 'singapore_standard','finish_color','color_white','attribute','white','白色',1,0,0.0000,'SGD',100,NULL,14,'{\"scope\":\"specified_color\"}',10 UNION ALL
                SELECT 'singapore_standard','finish_color','color_black','attribute','black','黑色',0,0,0.0000,'SGD',100,NULL,14,'{\"scope\":\"specified_color\"}',20 UNION ALL
                SELECT 'singapore_dali','dimming','dali','attribute','dali','DALI 调光',1,1,0.0000,'SGD',100,NULL,21,'{\"fixed\":\"DALI\"}',10 UNION ALL
                SELECT 'singapore_dali','driver','dali_driver','rule','dali_driver','DALI 电源规则',1,1,0.0000,'SGD',100,NULL,21,'{\"driver_type\":\"DALI\",\"locked\":true}',10 UNION ALL
                SELECT 'singapore_ready_stock','chip','ready_chip','rule','ready_chip','现货默认芯片',1,1,0.0000,'SGD',1,50,3,'{\"ready_stock_locked\":true}',10 UNION ALL
                SELECT 'singapore_ready_stock','driver','ready_driver','rule','ready_driver','现货默认电源',1,1,0.0000,'SGD',1,50,3,'{\"ready_stock_locked\":true}',10 UNION ALL
                SELECT 'singapore_ready_stock','optical','ready_optical','rule','ready_optical','现货默认光学',1,1,0.0000,'SGD',1,50,3,'{\"ready_stock_locked\":true}',10 UNION ALL
                SELECT 'singapore_ready_stock','finish_color','ready_white','attribute','white','现货白色',1,1,0.0000,'SGD',1,50,3,'{\"ready_stock_locked\":true}',10
            ) x ON x.package_code=p.package_code AND x.group_code=g.group_code
            WHERE NOT EXISTS (
                SELECT 1 FROM mc_pa2_config_package_options o WHERE o.package_group_id=g.id AND o.option_key=x.option_key
            )",
    ],
    'down' => [
        "DROP TABLE IF EXISTS mc_pa2_config_package_options",
        "DROP TABLE IF EXISTS mc_pa2_config_package_groups",
        "DROP TABLE IF EXISTS mc_pa2_config_package_versions",
        "DROP TABLE IF EXISTS mc_pa2_config_packages",
    ],
];
