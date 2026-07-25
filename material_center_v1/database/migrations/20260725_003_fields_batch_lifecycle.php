<?php
declare(strict_types=1);
return [
 'version'=>'20260725_003_fields_batch_lifecycle',
 'description'=>'A6 field registry and batch operations, A8 field permissions, A9 lifecycle',
 'up'=>[
  "CREATE TABLE IF NOT EXISTS mc_field_definitions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,category_code VARCHAR(50) NOT NULL,field_key VARCHAR(80) NOT NULL,label VARCHAR(120) NOT NULL,data_type VARCHAR(30) NOT NULL,storage_target VARCHAR(100) NOT NULL,options_json JSON NULL,validation_json JSON NULL,is_sensitive TINYINT(1) NOT NULL DEFAULT 0,is_batch_editable TINYINT(1) NOT NULL DEFAULT 0,sort_order INT NOT NULL DEFAULT 0,status VARCHAR(20) NOT NULL DEFAULT 'active',UNIQUE KEY uq_mc_field(category_code,field_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
  "CREATE TABLE IF NOT EXISTS mc_field_permission_rules (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,subject_type VARCHAR(20) NOT NULL,subject_id VARCHAR(80) NOT NULL,category_code VARCHAR(50) NOT NULL,field_key VARCHAR(80) NOT NULL,access_level VARCHAR(20) NOT NULL DEFAULT 'read',created_by BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,UNIQUE KEY uq_mc_field_permission(subject_type,subject_id,category_code,field_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
  "CREATE TABLE IF NOT EXISTS mc_batch_jobs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,job_uuid CHAR(36) NOT NULL UNIQUE,entity_type VARCHAR(40) NOT NULL,selection_scope VARCHAR(30) NOT NULL,selection_json JSON NOT NULL,changes_json JSON NOT NULL,overwrite_policy VARCHAR(40) NOT NULL,status VARCHAR(30) NOT NULL DEFAULT 'preview',total_count INT NOT NULL DEFAULT 0,success_count INT NOT NULL DEFAULT 0,skipped_count INT NOT NULL DEFAULT 0,failed_count INT NOT NULL DEFAULT 0,created_by BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL,executed_at DATETIME NULL,rolled_back_at DATETIME NULL,INDEX idx_mc_batch_status(status,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
  "CREATE TABLE IF NOT EXISTS mc_batch_job_items (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,batch_job_id BIGINT UNSIGNED NOT NULL,entity_id BIGINT UNSIGNED NOT NULL,before_json JSON NULL,after_json JSON NULL,result VARCHAR(20) NOT NULL DEFAULT 'pending',error_message VARCHAR(500) NULL,UNIQUE KEY uq_mc_batch_item(batch_job_id,entity_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
  "CREATE TABLE IF NOT EXISTS mc_material_lifecycle_events (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,material_id BIGINT UNSIGNED NOT NULL,from_status VARCHAR(30) NULL,to_status VARCHAR(30) NOT NULL,action VARCHAR(30) NOT NULL,reason VARCHAR(500) NULL,actor_id BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL,INDEX idx_mc_lifecycle(material_id,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
  "INSERT IGNORE INTO mc_permissions(permission_key,name,level,created_at) VALUES('material_center.material.create','新建物料','action',NOW()),('material_center.material.edit','编辑物料','action',NOW()),('material_center.material.batch','批量设置物料','action',NOW()),('material_center.material.lifecycle','物料生命周期','action',NOW()),('material_center.field.sensitive','查看敏感字段','field',NOW())",
  "INSERT IGNORE INTO mc_field_definitions(category_code,field_key,label,data_type,storage_target,options_json,validation_json,is_sensitive,is_batch_editable,sort_order,status) VALUES
  ('all','brand','品牌','text','mc_materials.brand',NULL,'{\"maxLength\":120}',0,1,10,'active'),
  ('all','status','状态','enum','mc_materials.status','[\"draft\",\"pending_review\",\"official\",\"disabled\",\"archived\"]',NULL,0,1,20,'active'),
  ('power_supply','power_band_id','功率档','relation','mc_power_supply_specs.power_band_id',NULL,NULL,0,1,30,'active'),
  ('power_supply','installation_type','安装方式','enum','mc_power_supply_specs.installation_type','[\"internal\",\"external\",\"remote\",\"integrated\",\"track_builtin\",\"junction_box\",\"unknown\"]',NULL,0,1,40,'active'),
  ('power_supply','output_type','输出类型','enum','mc_power_supply_specs.output_type','[\"constant_current\",\"constant_voltage\",\"unknown\"]',NULL,0,1,50,'active'),
  ('power_supply','nominal_power_w','标称功率','decimal','mc_power_supply_specs.nominal_power_w',NULL,'{\"min\":0,\"max\":10000}',0,1,60,'active'),
  ('power_supply','max_output_power_w','最大输出功率','decimal','mc_power_supply_specs.max_output_power_w',NULL,'{\"min\":0,\"max\":10000}',0,1,70,'active'),
  ('power_supply','supplier_warranty_years','供应商质保','decimal','mc_power_supply_specs.supplier_warranty_years',NULL,'{\"min\":0,\"max\":20}',0,1,80,'active'),
  ('power_supply','purchase_price','采购价','decimal','mc_power_supply_specs.purchase_price',NULL,'{\"min\":0}',1,1,90,'active')",
 ],
 'down'=>['DROP TABLE IF EXISTS mc_material_lifecycle_events','DROP TABLE IF EXISTS mc_batch_job_items','DROP TABLE IF EXISTS mc_batch_jobs','DROP TABLE IF EXISTS mc_field_permission_rules','DROP TABLE IF EXISTS mc_field_definitions'],
];
