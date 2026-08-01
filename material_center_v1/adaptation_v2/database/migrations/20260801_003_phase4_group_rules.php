<?php
declare(strict_types=1);

return [
    'version' => '20260801_003_phase4_group_rules',
    'description' => 'Product adaptation V2 phase 4 group behavior material sources and visual rules',
    'up' => [
        "CREATE TABLE IF NOT EXISTS mc_pa2_group_behavior_settings (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            group_definition_id BIGINT UNSIGNED NOT NULL,
            group_code VARCHAR(80) NOT NULL,
            selection_kind VARCHAR(40) NOT NULL DEFAULT 'material',
            source_mode VARCHAR(40) NOT NULL DEFAULT 'official_material',
            material_category_code VARCHAR(80) NULL,
            material_filter_json JSON NULL,
            attribute_source_json JSON NULL,
            numeric_unit VARCHAR(40) NULL,
            text_format VARCHAR(80) NULL,
            is_required_default TINYINT(1) NOT NULL DEFAULT 0,
            selection_mode_default VARCHAR(20) NOT NULL DEFAULT 'single',
            min_select_default INT NOT NULL DEFAULT 0,
            max_select_default INT NOT NULL DEFAULT 1,
            default_rule_json JSON NULL,
            visibility_condition_json JSON NULL,
            validation_json JSON NULL,
            created_by BIGINT UNSIGNED NULL,
            updated_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uk_mc_pa2_group_behavior_group (group_definition_id),
            UNIQUE KEY uk_mc_pa2_group_behavior_code (group_code),
            KEY idx_mc_pa2_group_behavior_kind (selection_kind,source_mode,material_category_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS mc_pa2_rule_definitions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            rule_code VARCHAR(100) NOT NULL,
            rule_name VARCHAR(180) NOT NULL,
            rule_scope VARCHAR(40) NOT NULL DEFAULT 'template',
            template_id BIGINT UNSIGNED NULL,
            product_category_id BIGINT UNSIGNED NULL,
            trigger_group_code VARCHAR(80) NOT NULL,
            trigger_operator VARCHAR(30) NOT NULL DEFAULT 'eq',
            trigger_value VARCHAR(240) NULL,
            target_group_code VARCHAR(80) NOT NULL,
            effect_action VARCHAR(40) NOT NULL DEFAULT 'show',
            effect_json JSON NULL,
            priority INT NOT NULL DEFAULT 100,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            description VARCHAR(700) NULL,
            created_by BIGINT UNSIGNED NULL,
            updated_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uk_mc_pa2_rule_code (rule_code),
            KEY idx_mc_pa2_rule_trigger (trigger_group_code,is_enabled,priority),
            KEY idx_mc_pa2_rule_target (target_group_code,is_enabled,priority),
            KEY idx_mc_pa2_rule_scope (rule_scope,template_id,product_category_id,is_enabled)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "INSERT INTO mc_pa2_group_definitions(group_code,group_name,group_type,icon,description,is_system,is_enabled,sort_order,created_at,updated_at) VALUES
            ('track_system','导轨系统','enum_select','⇆','普通导轨或 INTRACK 系统选择，用于驱动接头和电源显示规则。',1,1,55,NOW(),NOW()),
            ('waterproof_structure','防水结构','hybrid_select','☔','户外或特殊项目的混合选择组：先选防水等级属性，再筛正式物料。',1,1,175,NOW(),NOW()),
            ('power_range','功率范围','number_input','W','灯具或模组功率范围数值组，用于后续适配计算和过滤。',1,1,176,NOW(),NOW())
            ON DUPLICATE KEY UPDATE group_name=VALUES(group_name),group_type=VALUES(group_type),icon=VALUES(icon),description=VALUES(description),is_system=VALUES(is_system),is_enabled=VALUES(is_enabled),sort_order=VALUES(sort_order),updated_at=NOW()",
        "INSERT INTO mc_pa2_group_option_definitions(group_definition_id,option_code,option_name,description,is_default,is_enabled,sort_order,settings_json,created_at,updated_at)
            SELECT d.id,x.option_code,x.option_name,x.description,x.is_default,1,x.sort_order,JSON_OBJECT('phase',4,'business_key',x.option_code),NOW(),NOW()
            FROM mc_pa2_group_definitions d
            JOIN (
                SELECT 'track_system' group_code,'standard_track' option_code,'普通导轨' option_name,'普通导轨系统，显示普通接头和普通内置电源。' description,1 is_default,10 sort_order UNION ALL
                SELECT 'track_system','intrack','INTRACK','INTRACK 系统，显示 INTRACK 接头和 INTRACK 电源。',0,20 UNION ALL
                SELECT 'waterproof_structure','ip20','IP20','普通室内结构。',1,10 UNION ALL
                SELECT 'waterproof_structure','ip44','IP44','基础防潮结构。',0,20 UNION ALL
                SELECT 'waterproof_structure','ip65','IP65','户外防水结构。',0,30
            ) x ON x.group_code=d.group_code
            ON DUPLICATE KEY UPDATE option_name=VALUES(option_name),description=VALUES(description),is_default=VALUES(is_default),is_enabled=VALUES(is_enabled),sort_order=VALUES(sort_order),settings_json=VALUES(settings_json),updated_at=NOW()",
        "INSERT INTO mc_pa2_template_groups(template_id,group_definition_id,group_code,is_required,selection_mode,allow_empty,min_select,max_select,sort_order,is_enabled,inheritance_action,settings_json,created_at,updated_at)
            SELECT t.id,g.id,g.group_code,1,'single',0,1,1,35,1,'add',JSON_OBJECT('phase',4,'purpose','track system switch'),NOW(),NOW()
            FROM mc_pa2_templates t JOIN mc_pa2_group_definitions g ON g.group_code='track_system'
            WHERE t.template_code='track_light_base'
            ON DUPLICATE KEY UPDATE group_definition_id=VALUES(group_definition_id),is_required=VALUES(is_required),selection_mode=VALUES(selection_mode),allow_empty=VALUES(allow_empty),min_select=VALUES(min_select),max_select=VALUES(max_select),sort_order=VALUES(sort_order),is_enabled=VALUES(is_enabled),settings_json=VALUES(settings_json),updated_at=NOW()",
        "INSERT INTO mc_pa2_group_behavior_settings(group_definition_id,group_code,selection_kind,source_mode,material_category_code,material_filter_json,attribute_source_json,numeric_unit,text_format,is_required_default,selection_mode_default,min_select_default,max_select_default,default_rule_json,visibility_condition_json,validation_json,created_at,updated_at)
            SELECT d.id,d.group_code,x.selection_kind,x.source_mode,x.material_category_code,x.material_filter_json,x.attribute_source_json,x.numeric_unit,x.text_format,x.is_required,x.selection_mode,x.min_select,x.max_select,x.default_rule_json,x.visibility_condition_json,x.validation_json,NOW(),NOW()
            FROM mc_pa2_group_definitions d
            JOIN (
                SELECT 'chip' group_code,'material' selection_kind,'official_material' source_mode,'chip' material_category_code,JSON_OBJECT('formal_status','official','approved_required',true) material_filter_json,NULL attribute_source_json,NULL numeric_unit,NULL text_format,1 is_required,'single' selection_mode,1 min_select,1 max_select,JSON_OBJECT('strategy','first_compatible') default_rule_json,NULL visibility_condition_json,JSON_OBJECT('must_select',true) validation_json UNION ALL
                SELECT 'driver','material','official_material','power_supply',JSON_OBJECT('formal_status','official','driver_type','normal'),NULL,NULL,NULL,1,'single',1,1,JSON_OBJECT('strategy','match_power_current'),NULL,JSON_OBJECT('must_select',true) UNION ALL
                SELECT 'intrack_driver','material','official_material','power_supply',JSON_OBJECT('formal_status','official','driver_type','intrack'),NULL,NULL,NULL,0,'single',0,1,JSON_OBJECT('strategy','match_track_system'),JSON_OBJECT('controlled_by','track_system','value','intrack'),JSON_OBJECT('must_select_when_visible',true) UNION ALL
                SELECT 'external_driver','material','official_material','power_supply',JSON_OBJECT('formal_status','official','driver_type','external'),NULL,NULL,NULL,1,'single',1,1,JSON_OBJECT('strategy','match_power_current'),NULL,JSON_OBJECT('must_select',true) UNION ALL
                SELECT 'optical','material','official_material','optical',JSON_OBJECT('formal_status','official'),NULL,NULL,NULL,1,'single',1,1,JSON_OBJECT('strategy','match_beam_angle'),NULL,JSON_OBJECT('must_select',true) UNION ALL
                SELECT 'track_connector','material','official_material','connector',JSON_OBJECT('formal_status','official','track_system','standard_track'),NULL,NULL,NULL,1,'single',1,1,JSON_OBJECT('strategy','standard_track_default'),JSON_OBJECT('controlled_by','track_system','value','standard_track'),JSON_OBJECT('must_select_when_visible',true) UNION ALL
                SELECT 'intrack_connector','material','official_material','connector',JSON_OBJECT('formal_status','official','track_system','intrack'),NULL,NULL,NULL,0,'single',0,1,JSON_OBJECT('strategy','intrack_default'),JSON_OBJECT('controlled_by','track_system','value','intrack'),JSON_OBJECT('must_select_when_visible',true) UNION ALL
                SELECT 'magnetic_head','material','official_material','connector',JSON_OBJECT('formal_status','official','system','magnetic'),NULL,NULL,NULL,1,'single',1,1,JSON_OBJECT('strategy','match_body_length'),NULL,JSON_OBJECT('must_select',true) UNION ALL
                SELECT 'track_system','attribute','static_options',NULL,NULL,JSON_OBJECT('source','mc_pa2_group_option_definitions'),'','',1,'single',1,1,JSON_OBJECT('option_code','standard_track'),NULL,JSON_OBJECT('allowed_options','standard_track,intrack') UNION ALL
                SELECT 'body_length','attribute','static_options',NULL,NULL,JSON_OBJECT('source','mc_pa2_group_option_definitions'),NULL,NULL,1,'single',1,1,JSON_OBJECT('option_code','short'),NULL,JSON_OBJECT('allowed_options','short,long') UNION ALL
                SELECT 'installation','attribute','static_options',NULL,NULL,JSON_OBJECT('source','mc_pa2_group_option_definitions'),NULL,NULL,1,'single',1,1,JSON_OBJECT('strategy','category_default'),NULL,JSON_OBJECT('must_select',true) UNION ALL
                SELECT 'finish_color','attribute','static_options',NULL,NULL,JSON_OBJECT('source','mc_pa2_group_option_definitions'),NULL,NULL,0,'single',0,1,JSON_OBJECT('option_code','white'),NULL,JSON_OBJECT('allow_custom',true) UNION ALL
                SELECT 'dimming','attribute','static_options',NULL,NULL,JSON_OBJECT('source','mc_pa2_group_option_definitions'),NULL,NULL,0,'single',0,1,JSON_OBJECT('option_code','non_dim'),NULL,JSON_OBJECT('allow_custom',false) UNION ALL
                SELECT 'waterproof_structure','hybrid','mixed','accessory',JSON_OBJECT('formal_status','official','structure','waterproof'),JSON_OBJECT('source','mc_pa2_group_option_definitions'),NULL,NULL,0,'single',0,1,JSON_OBJECT('option_code','ip20'),NULL,JSON_OBJECT('allow_material_filter',true) UNION ALL
                SELECT 'power_range','number','manual_input',NULL,NULL,NULL,'W','decimal_range',0,'single',0,1,JSON_OBJECT('min',0,'max',50),NULL,JSON_OBJECT('unit','W','min',0,'max',9999) UNION ALL
                SELECT 'special_requirement','text','manual_text',NULL,NULL,NULL,NULL,'plain_text',0,'single',0,1,JSON_OBJECT('value',''),NULL,JSON_OBJECT('max_length',800)
            ) x ON x.group_code=d.group_code
            ON DUPLICATE KEY UPDATE selection_kind=VALUES(selection_kind),source_mode=VALUES(source_mode),material_category_code=VALUES(material_category_code),material_filter_json=VALUES(material_filter_json),attribute_source_json=VALUES(attribute_source_json),numeric_unit=VALUES(numeric_unit),text_format=VALUES(text_format),is_required_default=VALUES(is_required_default),selection_mode_default=VALUES(selection_mode_default),min_select_default=VALUES(min_select_default),max_select_default=VALUES(max_select_default),default_rule_json=VALUES(default_rule_json),visibility_condition_json=VALUES(visibility_condition_json),validation_json=VALUES(validation_json),updated_at=NOW()",
        "INSERT INTO mc_pa2_rule_definitions(rule_code,rule_name,rule_scope,template_id,product_category_id,trigger_group_code,trigger_operator,trigger_value,target_group_code,effect_action,effect_json,priority,is_enabled,description,created_at,updated_at)
            SELECT x.rule_code,x.rule_name,'template',t.id,c.id,x.trigger_group_code,x.trigger_operator,x.trigger_value,x.target_group_code,x.effect_action,x.effect_json,x.priority,1,x.description,NOW(),NOW()
            FROM (
                SELECT 'track_intrack_show_connector' rule_code,'选择 INTRACK 时显示 INTRACK 接头' rule_name,'track_light_base' template_code,'track_light' category_code,'track_system' trigger_group_code,'eq' trigger_operator,'intrack' trigger_value,'intrack_connector' target_group_code,'show' effect_action,JSON_OBJECT('reason','INTRACK 系统需要专用接头') effect_json,10 priority,'导轨灯选择 INTRACK 后显示 INTRACK 接头。' description UNION ALL
                SELECT 'track_intrack_show_driver','选择 INTRACK 时显示 INTRACK 电源','track_light_base','track_light','track_system','eq','intrack','intrack_driver','show',JSON_OBJECT('reason','INTRACK 系统需要专用电源'),11,'导轨灯选择 INTRACK 后显示 INTRACK 电源。' UNION ALL
                SELECT 'track_intrack_hide_standard_connector','选择 INTRACK 时隐藏普通接头','track_light_base','track_light','track_system','eq','intrack','track_connector','hide',JSON_OBJECT('reason','INTRACK 不使用普通导轨接头'),12,'导轨灯选择 INTRACK 后隐藏普通接头。' UNION ALL
                SELECT 'track_intrack_hide_standard_driver','选择 INTRACK 时隐藏普通内置电源','track_light_base','track_light','track_system','eq','intrack','driver','hide',JSON_OBJECT('reason','INTRACK 不使用普通内置电源'),13,'导轨灯选择 INTRACK 后隐藏普通内置电源。' UNION ALL
                SELECT 'track_standard_show_connector','选择普通导轨时显示普通接头','track_light_base','track_light','track_system','eq','standard_track','track_connector','show',JSON_OBJECT('reason','普通导轨使用普通接头'),20,'普通导轨显示普通接头。' UNION ALL
                SELECT 'track_standard_show_driver','选择普通导轨时显示普通电源','track_light_base','track_light','track_system','eq','standard_track','driver','show',JSON_OBJECT('reason','普通导轨使用普通内置电源'),21,'普通导轨显示普通电源。' UNION ALL
                SELECT 'track_standard_hide_intrack_connector','选择普通导轨时隐藏 INTRACK 接头','track_light_base','track_light','track_system','eq','standard_track','intrack_connector','hide',JSON_OBJECT('reason','普通导轨不使用 INTRACK 接头'),22,'普通导轨隐藏 INTRACK 接头。' UNION ALL
                SELECT 'track_standard_hide_intrack_driver','选择普通导轨时隐藏 INTRACK 电源','track_light_base','track_light','track_system','eq','standard_track','intrack_driver','hide',JSON_OBJECT('reason','普通导轨不使用 INTRACK 电源'),23,'普通导轨隐藏 INTRACK 电源。' UNION ALL
                SELECT 'magnetic_short_filter_head','磁吸灯短款过滤短款磁吸头','magnetic_base','magnetic','body_length','eq','short','magnetic_head','material_filter',JSON_OBJECT('keyword','短款','body_length','short'),30,'磁吸灯选择短款后，只显示短款相关物料。'
            ) x
            LEFT JOIN mc_pa2_templates t ON t.template_code=x.template_code
            LEFT JOIN mc_pa2_product_categories c ON c.category_code=x.category_code
            ON DUPLICATE KEY UPDATE rule_name=VALUES(rule_name),rule_scope=VALUES(rule_scope),template_id=VALUES(template_id),product_category_id=VALUES(product_category_id),trigger_group_code=VALUES(trigger_group_code),trigger_operator=VALUES(trigger_operator),trigger_value=VALUES(trigger_value),target_group_code=VALUES(target_group_code),effect_action=VALUES(effect_action),effect_json=VALUES(effect_json),priority=VALUES(priority),is_enabled=VALUES(is_enabled),description=VALUES(description),updated_at=NOW()",
    ],
    'down' => [
        "DELETE FROM mc_pa2_rule_definitions WHERE rule_code IN('track_intrack_show_connector','track_intrack_show_driver','track_intrack_hide_standard_connector','track_intrack_hide_standard_driver','track_standard_show_connector','track_standard_show_driver','track_standard_hide_intrack_connector','track_standard_hide_intrack_driver','magnetic_short_filter_head')",
        "DELETE FROM mc_pa2_group_behavior_settings WHERE group_code IN('chip','driver','intrack_driver','external_driver','optical','track_connector','intrack_connector','magnetic_head','track_system','body_length','installation','finish_color','dimming','waterproof_structure','power_range','special_requirement')",
        "DELETE FROM mc_pa2_template_groups WHERE group_code='track_system'",
        "DELETE o FROM mc_pa2_group_option_definitions o JOIN mc_pa2_group_definitions g ON g.id=o.group_definition_id WHERE g.group_code IN('track_system','waterproof_structure')",
        "DELETE FROM mc_pa2_group_definitions WHERE group_code IN('track_system','waterproof_structure','power_range')",
        "DROP TABLE IF EXISTS mc_pa2_rule_definitions",
        "DROP TABLE IF EXISTS mc_pa2_group_behavior_settings",
    ],
];
