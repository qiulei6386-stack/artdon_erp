<?php
declare(strict_types=1);

return [
    'version' => '20260728_021_power_output_range',
    'description' => 'Store the minimum output power of each driver for product power-range matching',
    'up' => [
        "ALTER TABLE mc_power_supply_specs ADD COLUMN min_output_power_w DECIMAL(8,2) NULL AFTER nominal_power_w",
        "INSERT INTO mc_field_registry(field_code,storage_target,field_name,data_type,unit,is_required,validation_json,default_json,allow_batch,is_sensitive,customer_visible,allow_import,use_for_duplicate,use_for_adaptation,status,sort_order) VALUES('power.min_output_power_w','mc_power_supply_specs.min_output_power_w','最低输出功率','decimal','W',0,JSON_OBJECT('min',0),NULL,1,0,1,1,1,1,'active',125) ON DUPLICATE KEY UPDATE storage_target=VALUES(storage_target),field_name=VALUES(field_name),data_type=VALUES(data_type),unit=VALUES(unit),validation_json=VALUES(validation_json),sort_order=VALUES(sort_order)",
        "INSERT IGNORE INTO mc_category_field_map(category_code,field_id,is_required,sort_order) SELECT 'power_supply',id,0,125 FROM mc_field_registry WHERE field_code='power.min_output_power_w'",
    ],
    'down' => [
        "DELETE m FROM mc_category_field_map m JOIN mc_field_registry f ON f.id=m.field_id WHERE f.field_code='power.min_output_power_w'",
        "DELETE FROM mc_field_registry WHERE field_code='power.min_output_power_w'",
        "ALTER TABLE mc_power_supply_specs DROP COLUMN min_output_power_w",
    ],
];
