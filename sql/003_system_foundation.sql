CREATE TABLE IF NOT EXISTS sys_backup_records (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  backup_type ENUM('template','data','full') NOT NULL,
  version_no VARCHAR(60) NOT NULL,
  title VARCHAR(180) NOT NULL,
  module_scope VARCHAR(120) NOT NULL DEFAULT 'all',
  status ENUM('created','restored','failed') NOT NULL DEFAULT 'created',
  file_path VARCHAR(500) NULL,
  file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
  remark VARCHAR(500) NULL,
  operator_id INT UNSIGNED NULL,
  operator_name VARCHAR(120) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  restored_at DATETIME NULL,
  UNIQUE KEY uk_backup_version (version_no),
  KEY idx_backup_type (backup_type),
  KEY idx_backup_created (created_at),
  KEY idx_backup_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sys_backup_files (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  backup_id BIGINT UNSIGNED NOT NULL,
  file_role ENUM('manifest','data','config','schema','snapshot') NOT NULL DEFAULT 'data',
  file_path VARCHAR(500) NOT NULL,
  file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
  checksum VARCHAR(128) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_backup_file_backup (backup_id),
  KEY idx_backup_file_role (file_role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sys_action_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  operator_name VARCHAR(120) NULL,
  module VARCHAR(80) NOT NULL,
  action VARCHAR(120) NOT NULL,
  target_type VARCHAR(80) NULL,
  target_id VARCHAR(120) NULL,
  before_json JSON NULL,
  after_json JSON NULL,
  risk_level ENUM('normal','sensitive','danger') NOT NULL DEFAULT 'normal',
  remark VARCHAR(500) NULL,
  ip VARCHAR(64) NULL,
  user_agent VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sys_action_user (user_id),
  KEY idx_sys_action_module (module),
  KEY idx_sys_action_risk (risk_level),
  KEY idx_sys_action_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sys_login_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  username VARCHAR(120) NULL,
  status ENUM('success','fail','logout') NOT NULL,
  ip VARCHAR(64) NULL,
  user_agent VARCHAR(500) NULL,
  message VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sys_login_user (user_id),
  KEY idx_sys_login_status (status),
  KEY idx_sys_login_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sys_homepage_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  operator_name VARCHAR(120) NULL,
  event_type ENUM('view','module_click','quick_click','leave') NOT NULL,
  module_key VARCHAR(120) NULL,
  target_url VARCHAR(500) NULL,
  payload_json JSON NULL,
  ip VARCHAR(64) NULL,
  user_agent VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_homepage_user (user_id),
  KEY idx_homepage_event (event_type),
  KEY idx_homepage_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sys_backup_schedule (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  schedule_type ENUM('daily','weekly','monthly') NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  run_time TIME NOT NULL DEFAULT '02:00:00',
  retain_count INT UNSIGNED NOT NULL DEFAULT 7,
  backup_type ENUM('template','data','full') NOT NULL DEFAULT 'full',
  module_scope VARCHAR(120) NOT NULL DEFAULT 'all',
  last_run_at DATETIME NULL,
  next_run_at DATETIME NULL,
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_backup_schedule_type (schedule_type, backup_type, module_scope),
  KEY idx_backup_schedule_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sys_linkage_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  module_key VARCHAR(120) NOT NULL,
  event_type VARCHAR(120) NOT NULL,
  title VARCHAR(180) NOT NULL,
  detail_json JSON NULL,
  status ENUM('success','warning','failed') NOT NULL DEFAULT 'success',
  related_module VARCHAR(120) NULL,
  related_id VARCHAR(120) NULL,
  created_by INT UNSIGNED NULL,
  created_by_name VARCHAR(120) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_linkage_module (module_key),
  KEY idx_linkage_event_type (event_type),
  KEY idx_linkage_status (status),
  KEY idx_linkage_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sys_notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  type VARCHAR(80) NOT NULL DEFAULT 'system',
  title VARCHAR(180) NOT NULL,
  content VARCHAR(800) NULL,
  payload_json JSON NULL,
  read_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_notification_user (user_id),
  KEY idx_notification_type (type),
  KEY idx_notification_read (read_at),
  KEY idx_notification_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO sys_backup_schedule (schedule_type, enabled, run_time, retain_count, backup_type, module_scope)
VALUES
('daily', 0, '02:00:00', 7, 'full', 'all'),
('weekly', 0, '03:00:00', 8, 'full', 'all'),
('monthly', 0, '04:00:00', 12, 'full', 'all');

INSERT INTO crm_schema_migrations (migration_key, description)
VALUES ('003_system_foundation', 'System backup, action log, homepage log and backup schedule base')
ON DUPLICATE KEY UPDATE description = VALUES(description);
