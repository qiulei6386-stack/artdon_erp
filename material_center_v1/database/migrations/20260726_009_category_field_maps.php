<?php
declare(strict_types=1);
return[
 'version'=>'20260726_009_category_field_maps',
 'description'=>'Connect seven category extension fields to the database field registry',
 'up'=>[
  "INSERT IGNORE INTO mc_category_field_map(category_code,field_id,is_required,sort_order) SELECT 'power_supply',id,is_required,sort_order FROM mc_field_registry WHERE field_code IN('power.nominal_power_w','power.output_current_ma')",
  "INSERT IGNORE INTO mc_category_field_map(category_code,field_id,is_required,sort_order) SELECT 'chip',id,is_required,sort_order FROM mc_field_registry WHERE field_code='chip.cri'",
  "INSERT IGNORE INTO mc_category_field_map(category_code,field_id,is_required,sort_order) SELECT 'optical',id,is_required,sort_order FROM mc_field_registry WHERE field_code='optical.beam_angle'",
  "INSERT IGNORE INTO mc_category_field_map(category_code,field_id,is_required,sort_order) SELECT 'profile',id,is_required,sort_order FROM mc_field_registry WHERE field_code='profile.material_grade'",
  "INSERT IGNORE INTO mc_category_field_map(category_code,field_id,is_required,sort_order) SELECT 'connector',id,is_required,sort_order FROM mc_field_registry WHERE field_code='connector.interface_type'",
  "INSERT IGNORE INTO mc_category_field_map(category_code,field_id,is_required,sort_order) SELECT 'accessory',id,is_required,sort_order FROM mc_field_registry WHERE field_code='accessory.accessory_type'",
  "INSERT IGNORE INTO mc_category_field_map(category_code,field_id,is_required,sort_order) SELECT 'packaging',id,is_required,sort_order FROM mc_field_registry WHERE field_code='packaging.packaging_type'",
  "INSERT IGNORE INTO mc_field_options(field_id,option_value,option_label,sort_order,status) SELECT id,'optical_accessory','光学配件',10,'active' FROM mc_field_registry WHERE field_code='accessory.accessory_type'",
  "INSERT IGNORE INTO mc_field_options(field_id,option_value,option_label,sort_order,status) SELECT id,'mounting_accessory','安装配件',20,'active' FROM mc_field_registry WHERE field_code='accessory.accessory_type'",
  "INSERT IGNORE INTO mc_field_options(field_id,option_value,option_label,sort_order,status) SELECT id,'electrical_accessory','电气配件',30,'active' FROM mc_field_registry WHERE field_code='accessory.accessory_type'",
 ],
 'down'=>[
  "DELETE o FROM mc_field_options o JOIN mc_field_registry f ON f.id=o.field_id WHERE f.field_code='accessory.accessory_type' AND o.option_value IN('optical_accessory','mounting_accessory','electrical_accessory')",
  "DELETE m FROM mc_category_field_map m JOIN mc_field_registry f ON f.id=m.field_id WHERE f.field_code IN('power.nominal_power_w','power.output_current_ma','chip.cri','optical.beam_angle','profile.material_grade','connector.interface_type','accessory.accessory_type','packaging.packaging_type')",
 ],
];
