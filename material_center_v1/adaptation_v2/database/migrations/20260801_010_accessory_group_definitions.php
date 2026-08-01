<?php
declare(strict_types=1);

return [
    'version' => '20260801_010_accessory_group_definitions',
    'description' => 'Product adaptation V2 accessory, glass, honeycomb, four-leaf louver and optical film group definitions',
    'up' => [
        "INSERT INTO mc_pa2_group_definitions(group_code,group_name,group_type,icon,description,is_system,is_enabled,sort_order,created_at,updated_at) VALUES
            ('accessory','配件','material_select','✣','通用配件配置组，从正式配件物料中选择，可用于补充项目附件。',1,1,121,NOW(),NOW()),
            ('glass','玻璃','material_select','▯','玻璃、面罩或透明件配置组，从正式配件物料中选择。',1,1,122,NOW(),NOW()),
            ('honeycomb','蜂窝网','material_select','▦','蜂窝网、防眩蜂巢网等配件配置组。',1,1,123,NOW(),NOW()),
            ('four_leaf_louver','四叶片','material_select','✤','四叶片、防眩叶片或格栅类配件配置组。',1,1,124,NOW(),NOW()),
            ('optical_film','光学膜','material_select','◌','扩散膜、柔光膜、防眩膜等光学膜配置组。',1,1,125,NOW(),NOW())
            ON DUPLICATE KEY UPDATE group_name=VALUES(group_name),group_type=VALUES(group_type),icon=VALUES(icon),description=VALUES(description),is_system=VALUES(is_system),is_enabled=VALUES(is_enabled),sort_order=VALUES(sort_order),updated_at=NOW()",
        "INSERT INTO mc_pa2_group_behavior_settings(group_definition_id,group_code,selection_kind,source_mode,material_category_code,material_filter_json,attribute_source_json,numeric_unit,text_format,is_required_default,selection_mode_default,min_select_default,max_select_default,default_rule_json,visibility_condition_json,validation_json,created_at,updated_at)
            SELECT d.id,d.group_code,'material','official_material','accessory',x.material_filter_json,NULL,NULL,NULL,x.is_required,x.selection_mode,x.min_select,x.max_select,x.default_rule_json,x.visibility_condition_json,x.validation_json,NOW(),NOW()
            FROM mc_pa2_group_definitions d
            JOIN (
                SELECT 'accessory' group_code,JSON_OBJECT('formal_status','official','accessory_type','general_accessory') material_filter_json,0 is_required,'multiple' selection_mode,0 min_select,99 max_select,JSON_OBJECT('strategy','optional_accessories') default_rule_json,NULL visibility_condition_json,JSON_OBJECT('allow_multiple',true,'must_be_official',true) validation_json UNION ALL
                SELECT 'glass',JSON_OBJECT('formal_status','official','accessory_type','glass','keyword','玻璃'),0,'single',0,1,JSON_OBJECT('strategy','match_size_or_default'),NULL,JSON_OBJECT('must_be_official',true) UNION ALL
                SELECT 'honeycomb',JSON_OBJECT('formal_status','official','accessory_type','honeycomb','keyword','蜂'),0,'single',0,1,JSON_OBJECT('strategy','match_diameter_or_family'),NULL,JSON_OBJECT('must_be_official',true,'allow_with_glass',true) UNION ALL
                SELECT 'four_leaf_louver',JSON_OBJECT('formal_status','official','accessory_type','four_leaf_louver','keyword','四叶片'),0,'single',0,1,JSON_OBJECT('strategy','match_diameter_or_family'),NULL,JSON_OBJECT('must_be_official',true) UNION ALL
                SELECT 'optical_film',JSON_OBJECT('formal_status','official','accessory_type','optical_film','keyword','膜'),0,'multiple',0,9,JSON_OBJECT('strategy','optional_optical_film'),NULL,JSON_OBJECT('allow_multiple',true,'must_be_official',true)
            ) x ON x.group_code=d.group_code
            ON DUPLICATE KEY UPDATE selection_kind=VALUES(selection_kind),source_mode=VALUES(source_mode),material_category_code=VALUES(material_category_code),material_filter_json=VALUES(material_filter_json),attribute_source_json=VALUES(attribute_source_json),numeric_unit=VALUES(numeric_unit),text_format=VALUES(text_format),is_required_default=VALUES(is_required_default),selection_mode_default=VALUES(selection_mode_default),min_select_default=VALUES(min_select_default),max_select_default=VALUES(max_select_default),default_rule_json=VALUES(default_rule_json),visibility_condition_json=VALUES(visibility_condition_json),validation_json=VALUES(validation_json),updated_at=NOW()",
    ],
    'down' => [
        "DELETE FROM mc_pa2_group_behavior_settings WHERE group_code IN('accessory','glass','honeycomb','four_leaf_louver','optical_film')",
        "DELETE FROM mc_pa2_group_definitions WHERE group_code IN('accessory','glass','honeycomb','four_leaf_louver','optical_film')",
    ],
];
