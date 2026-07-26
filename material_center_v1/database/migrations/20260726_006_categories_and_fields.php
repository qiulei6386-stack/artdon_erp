<?php
declare(strict_types=1);
return [
 'version'=>'20260726_006_categories_and_fields',
 'description'=>'Seven material categories and dynamic field registry seeds',
 'up'=>[
  "INSERT IGNORE INTO mc_material_categories(code,name,status,sort_order,created_at,updated_at) VALUES
  ('chip','芯片','active',20,NOW(),NOW()),('optical','光学','active',30,NOW(),NOW()),('profile','型材 / 散热件','active',40,NOW(),NOW()),('connector','接头 / 安装件','active',50,NOW(),NOW()),('accessory','配件','active',60,NOW(),NOW()),('packaging','包装','active',70,NOW(),NOW())",
  "INSERT IGNORE INTO mc_field_registry(field_code,field_name,data_type,unit,is_required,validation_json,default_json,allow_batch,is_sensitive,customer_visible,allow_import,use_for_duplicate,use_for_adaptation,status,sort_order) VALUES
  ('brand','品牌','text',NULL,0,'{\"maxLength\":120}',NULL,1,0,1,1,1,1,'active',10),
  ('model','型号','text',NULL,0,'{\"maxLength\":160}',NULL,1,0,1,1,1,1,'active',20),
  ('spec_summary','关键规格','textarea',NULL,0,'{\"maxLength\":2000}',NULL,1,0,1,1,1,1,'active',30),
  ('purchase_price','采购价','decimal','currency',0,'{\"min\":0}',NULL,1,1,0,1,0,0,'active',40),
  ('power.nominal_power_w','额定功率','decimal','W',0,'{\"min\":0,\"max\":10000}',NULL,1,0,1,1,1,1,'active',100),
  ('power.output_current_ma','输出电流','decimal','mA',0,'{\"min\":0}',NULL,1,0,1,1,1,1,'active',110),
  ('chip.cri','显色指数','decimal','Ra',0,'{\"min\":0,\"max\":100}',NULL,1,0,1,1,0,1,'active',200),
  ('optical.beam_angle','光束角','decimal','°',0,'{\"min\":0,\"max\":180}',NULL,1,0,1,1,0,1,'active',300),
  ('profile.material_grade','材料牌号','text',NULL,0,'{\"maxLength\":100}',NULL,1,0,1,1,1,1,'active',400),
  ('connector.interface_type','接口','text',NULL,0,'{\"maxLength\":100}',NULL,1,0,1,1,1,1,'active',500),
  ('accessory.accessory_type','配件类别','enum',NULL,0,NULL,NULL,1,0,1,1,1,1,'active',600),
  ('packaging.packaging_type','包装类别','text',NULL,0,'{\"maxLength\":80}',NULL,1,0,1,1,1,0,'active',700)",
 ],
 'down'=>[
  "DELETE FROM mc_field_registry WHERE field_code IN('brand','model','spec_summary','purchase_price','power.nominal_power_w','power.output_current_ma','chip.cri','optical.beam_angle','profile.material_grade','connector.interface_type','accessory.accessory_type','packaging.packaging_type')",
  "DELETE FROM mc_material_categories WHERE code IN('chip','optical','profile','connector','accessory','packaging') AND NOT EXISTS(SELECT 1 FROM mc_materials WHERE mc_materials.category_id=mc_material_categories.id)",
 ],
];
