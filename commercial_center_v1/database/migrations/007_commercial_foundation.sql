-- Formal commercial-center foundation. All objects are isolated with cc_ prefix.
CREATE TABLE IF NOT EXISTS cc_commercial_tasks (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, task_no VARCHAR(80) NOT NULL,
 summary VARCHAR(500) NOT NULL, customer_name VARCHAR(190) NULL, source VARCHAR(80) NULL,
 current_node VARCHAR(120) NULL, owner_user_id INT UNSIGNED NULL, due_at DATETIME NULL,
 status VARCHAR(40) NOT NULL DEFAULT 'pending', next_action VARCHAR(190) NULL,
 created_by_legacy_user_id INT UNSIGNED NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
 PRIMARY KEY(id), UNIQUE KEY uq_cc_task_no(task_no), KEY idx_cc_task_queue(status,due_at,owner_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cc_quotation_logs (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, quote_id BIGINT UNSIGNED NULL, action_code VARCHAR(80) NOT NULL,
 status VARCHAR(40) NOT NULL, actor_user_id INT UNSIGNED NULL, message VARCHAR(500) NULL,
 request_id CHAR(36) NULL, created_at DATETIME NOT NULL, PRIMARY KEY(id),
 KEY idx_cc_quote_log(quote_id,created_at), KEY idx_cc_quote_log_action(action_code,status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cc_commercial_settings (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, setting_key VARCHAR(120) NOT NULL, setting_value LONGTEXT NULL,
 scope_type VARCHAR(30) NOT NULL DEFAULT 'system', scope_id VARCHAR(80) NULL, status VARCHAR(30) NOT NULL DEFAULT 'active',
 updated_by_legacy_user_id INT UNSIGNED NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
 PRIMARY KEY(id), UNIQUE KEY uq_cc_setting_scope(setting_key,scope_type,scope_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cc_commercial_permissions (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, role_code VARCHAR(60) NOT NULL, permission_code VARCHAR(120) NOT NULL,
 effect VARCHAR(10) NOT NULL DEFAULT 'allow', status VARCHAR(30) NOT NULL DEFAULT 'active',
 created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY(id),
 UNIQUE KEY uq_cc_role_permission(role_code,permission_code), KEY idx_cc_permission_status(status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cc_approval_flows (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, flow_code VARCHAR(80) NOT NULL, flow_name VARCHAR(190) NOT NULL,
 entity_type VARCHAR(80) NOT NULL, version_no INT UNSIGNED NOT NULL DEFAULT 1, definition_json LONGTEXT NOT NULL,
 status VARCHAR(30) NOT NULL DEFAULT 'draft', created_by_legacy_user_id INT UNSIGNED NULL,
 created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY(id),
 UNIQUE KEY uq_cc_flow_version(flow_code,version_no), KEY idx_cc_flow_entity(entity_type,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
