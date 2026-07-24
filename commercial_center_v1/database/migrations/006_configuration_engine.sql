-- Configuration engine V1. Creates cc_* tables only; no legacy writes or foreign keys.
CREATE TABLE IF NOT EXISTS cc_config_templates (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, permanent_id CHAR(36) NOT NULL, template_code VARCHAR(100) NOT NULL,
 name VARCHAR(190) NOT NULL, product_type VARCHAR(40) NOT NULL DEFAULT 'standard', status VARCHAR(30) NOT NULL DEFAULT 'active',
 current_version INT UNSIGNED NOT NULL DEFAULT 1, created_by_legacy_user_id INT UNSIGNED NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
 PRIMARY KEY(id), UNIQUE KEY uq_cc_config_template_pid(permanent_id), UNIQUE KEY uq_cc_config_template_code(template_code),
 KEY idx_cc_config_template_status(product_type,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS cc_config_template_versions (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, template_id BIGINT UNSIGNED NOT NULL, version_no INT UNSIGNED NOT NULL,
 status VARCHAR(30) NOT NULL DEFAULT 'draft', change_note VARCHAR(500) NULL, schema_hash CHAR(64) NOT NULL,
 created_by_legacy_user_id INT UNSIGNED NULL, created_at DATETIME NOT NULL,
 PRIMARY KEY(id), UNIQUE KEY uq_cc_config_template_version(template_id,version_no), KEY idx_cc_config_template_version_status(status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS cc_config_group_settings (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, group_id BIGINT UNSIGNED NOT NULL, input_type VARCHAR(30) NOT NULL DEFAULT 'single',
 default_value_json LONGTEXT NULL, is_advanced TINYINT(1) NOT NULL DEFAULT 0, customer_visible TINYINT(1) NOT NULL DEFAULT 1,
 affects_cost TINYINT(1) NOT NULL DEFAULT 0, affects_price TINYINT(1) NOT NULL DEFAULT 0, affects_moq TINYINT(1) NOT NULL DEFAULT 0,
 affects_lead_time TINYINT(1) NOT NULL DEFAULT 0, allow_custom TINYINT(1) NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
 PRIMARY KEY(id), UNIQUE KEY uq_cc_config_group_setting(group_id), KEY idx_cc_config_group_input(input_type,is_advanced)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS cc_config_template_groups (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, template_version_id BIGINT UNSIGNED NOT NULL, group_id BIGINT UNSIGNED NOT NULL,
 sort_order INT NOT NULL DEFAULT 0, is_required_override TINYINT(1) NULL, default_value_json LONGTEXT NULL,
 status VARCHAR(30) NOT NULL DEFAULT 'active', created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
 PRIMARY KEY(id), UNIQUE KEY uq_cc_config_template_group(template_version_id,group_id), KEY idx_cc_config_template_group_order(template_version_id,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS cc_product_config_templates (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, legacy_product_id BIGINT UNSIGNED NOT NULL, template_id BIGINT UNSIGNED NOT NULL,
 product_type VARCHAR(40) NOT NULL, is_default TINYINT(1) NOT NULL DEFAULT 1, status VARCHAR(30) NOT NULL DEFAULT 'active',
 created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
 PRIMARY KEY(id), UNIQUE KEY uq_cc_product_config_template(legacy_product_id,template_id), KEY idx_cc_product_config_default(legacy_product_id,is_default,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS cc_product_allowed_options (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, legacy_product_id BIGINT UNSIGNED NOT NULL, group_id BIGINT UNSIGNED NOT NULL,
 option_id BIGINT UNSIGNED NULL, custom_allowed TINYINT(1) NOT NULL DEFAULT 0, status VARCHAR(30) NOT NULL DEFAULT 'active',
 created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
 PRIMARY KEY(id), UNIQUE KEY uq_cc_product_allowed_option(legacy_product_id,group_id,option_id), KEY idx_cc_product_allowed_lookup(legacy_product_id,group_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS cc_config_presets (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, permanent_id CHAR(36) NOT NULL, preset_code VARCHAR(120) NOT NULL, name VARCHAR(190) NOT NULL,
 preset_type VARCHAR(40) NOT NULL, scope_type VARCHAR(30) NOT NULL DEFAULT 'global', template_id BIGINT UNSIGNED NULL,
 legacy_product_id BIGINT UNSIGNED NULL, legacy_customer_id BIGINT UNSIGNED NULL, owner_legacy_user_id INT UNSIGNED NULL,
 channel_code VARCHAR(60) NULL, source_reference VARCHAR(190) NULL, version_no INT UNSIGNED NOT NULL DEFAULT 1,
 status VARCHAR(30) NOT NULL DEFAULT 'active', created_by_legacy_user_id INT UNSIGNED NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
 PRIMARY KEY(id), UNIQUE KEY uq_cc_config_preset_pid(permanent_id), UNIQUE KEY uq_cc_config_preset_code(preset_code),
 KEY idx_cc_config_preset_scope(scope_type,legacy_product_id,legacy_customer_id,owner_legacy_user_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS cc_config_preset_values (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, preset_id BIGINT UNSIGNED NOT NULL, group_id BIGINT UNSIGNED NOT NULL,
 value_json LONGTEXT NOT NULL, is_locked TINYINT(1) NOT NULL DEFAULT 0, lock_type VARCHAR(30) NULL,
 created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
 PRIMARY KEY(id), UNIQUE KEY uq_cc_config_preset_value(preset_id,group_id), KEY idx_cc_config_preset_value_lock(preset_id,is_locked)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS cc_config_lock_rules (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, permanent_id CHAR(36) NOT NULL, rule_code VARCHAR(120) NOT NULL, name VARCHAR(190) NOT NULL,
 lock_type VARCHAR(30) NOT NULL, scope_type VARCHAR(30) NOT NULL, legacy_product_id BIGINT UNSIGNED NULL,
 inventory_sku_id BIGINT UNSIGNED NULL, template_id BIGINT UNSIGNED NULL, group_id BIGINT UNSIGNED NOT NULL,
 condition_json LONGTEXT NULL, approval_required TINYINT(1) NOT NULL DEFAULT 0, priority INT NOT NULL DEFAULT 100,
 status VARCHAR(30) NOT NULL DEFAULT 'active', created_by_legacy_user_id INT UNSIGNED NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
 PRIMARY KEY(id), UNIQUE KEY uq_cc_config_lock_pid(permanent_id), UNIQUE KEY uq_cc_config_lock_code(rule_code),
 KEY idx_cc_config_lock_scope(scope_type,legacy_product_id,inventory_sku_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS cc_option_material_mappings (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, option_id BIGINT UNSIGNED NOT NULL, material_id BIGINT UNSIGNED NOT NULL,
 mapping_type VARCHAR(30) NOT NULL DEFAULT 'default', cost_delta DECIMAL(18,4) NOT NULL DEFAULT 0,
 priority INT NOT NULL DEFAULT 100, status VARCHAR(30) NOT NULL DEFAULT 'active', created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
 PRIMARY KEY(id), UNIQUE KEY uq_cc_option_material(option_id,material_id,mapping_type), KEY idx_cc_option_material_lookup(option_id,mapping_type,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS cc_configuration_instances (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, permanent_id CHAR(36) NOT NULL, legacy_product_id BIGINT UNSIGNED NULL,
 inventory_sku_id BIGINT UNSIGNED NULL, template_version_id BIGINT UNSIGNED NULL, preset_id BIGINT UNSIGNED NULL,
 mode VARCHAR(30) NOT NULL DEFAULT 'quick', product_type VARCHAR(40) NOT NULL, values_json LONGTEXT NOT NULL,
 differences_json LONGTEXT NULL, validation_status VARCHAR(30) NOT NULL DEFAULT 'unchecked', approval_status VARCHAR(30) NOT NULL DEFAULT 'not_required',
 base_cost DECIMAL(18,4) NOT NULL DEFAULT 0, total_cost DECIMAL(18,4) NOT NULL DEFAULT 0,
 suggested_price DECIMAL(18,4) NOT NULL DEFAULT 0, current_price DECIMAL(18,4) NOT NULL DEFAULT 0,
 moq DECIMAL(18,3) NOT NULL DEFAULT 1, lead_time_days INT UNSIGNED NOT NULL DEFAULT 0,
 status VARCHAR(30) NOT NULL DEFAULT 'draft', is_test TINYINT(1) NOT NULL DEFAULT 0,
 created_by_legacy_user_id INT UNSIGNED NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
 PRIMARY KEY(id), UNIQUE KEY uq_cc_configuration_instance_pid(permanent_id),
 KEY idx_cc_configuration_instance_owner(created_by_legacy_user_id,status,updated_at), KEY idx_cc_configuration_instance_product(legacy_product_id,inventory_sku_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS cc_configuration_snapshots (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, permanent_id CHAR(36) NOT NULL, configuration_instance_id BIGINT UNSIGNED NULL,
 snapshot_type VARCHAR(30) NOT NULL, snapshot_json LONGTEXT NOT NULL, passport_hash CHAR(64) NOT NULL,
 template_version_id BIGINT UNSIGNED NULL, preset_id BIGINT UNSIGNED NULL, locked_at DATETIME NULL,
 created_by_legacy_user_id INT UNSIGNED NULL, created_at DATETIME NOT NULL,
 PRIMARY KEY(id), UNIQUE KEY uq_cc_configuration_snapshot_pid(permanent_id),
 UNIQUE KEY uq_cc_configuration_snapshot_hash(passport_hash), KEY idx_cc_configuration_snapshot_instance(configuration_instance_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
