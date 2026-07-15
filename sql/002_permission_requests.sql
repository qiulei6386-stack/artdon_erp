CREATE TABLE IF NOT EXISTS crm_permission_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  requester_id INT UNSIGNED NOT NULL,
  requester_name VARCHAR(120) NOT NULL,
  department_id INT UNSIGNED NULL,
  role_id INT UNSIGNED NULL,
  permission_key VARCHAR(120) NOT NULL,
  module VARCHAR(60) NOT NULL,
  action VARCHAR(80) NOT NULL,
  request_scope_type ENUM('none','self','assigned','department','team','country','customer_group','all') NOT NULL DEFAULT 'self',
  request_scope_value VARCHAR(255) NULL,
  field_key VARCHAR(120) NULL,
  related_type VARCHAR(80) NULL,
  related_id VARCHAR(120) NULL,
  reason TEXT NOT NULL,
  urgency ENUM('normal','urgent','critical') NOT NULL DEFAULT 'normal',
  expected_days INT UNSIGNED NULL,
  status ENUM('pending','approved','rejected','cancelled','expired') NOT NULL DEFAULT 'pending',
  approved_by INT UNSIGNED NULL,
  approved_by_name VARCHAR(120) NULL,
  approved_at DATETIME NULL,
  rejected_by INT UNSIGNED NULL,
  rejected_by_name VARCHAR(120) NULL,
  rejected_at DATETIME NULL,
  reject_reason VARCHAR(500) NULL,
  approval_note TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_pr_requester (requester_id),
  KEY idx_pr_department (department_id),
  KEY idx_pr_permission (permission_key),
  KEY idx_pr_status (status),
  KEY idx_pr_urgency (urgency),
  KEY idx_pr_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_permission_grants (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id BIGINT UNSIGNED NULL,
  user_id INT UNSIGNED NOT NULL,
  permission_key VARCHAR(120) NOT NULL,
  module VARCHAR(60) NOT NULL,
  action VARCHAR(80) NOT NULL,
  effect ENUM('allow','deny') NOT NULL DEFAULT 'allow',
  scope_type ENUM('none','self','assigned','department','team','country','customer_group','all') NOT NULL DEFAULT 'self',
  scope_value VARCHAR(255) NULL,
  field_key VARCHAR(120) NULL,
  related_type VARCHAR(80) NULL,
  related_id VARCHAR(120) NULL,
  granted_by INT UNSIGNED NULL,
  granted_by_name VARCHAR(120) NULL,
  granted_at DATETIME NULL,
  starts_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NULL,
  is_temporary TINYINT(1) NOT NULL DEFAULT 1,
  status ENUM('active','expired','revoked') NOT NULL DEFAULT 'active',
  revoked_by INT UNSIGNED NULL,
  revoked_by_name VARCHAR(120) NULL,
  revoked_at DATETIME NULL,
  revoke_reason VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_pg_user_permission (user_id, permission_key),
  KEY idx_pg_request (request_id),
  KEY idx_pg_status (status),
  KEY idx_pg_expires (expires_at),
  KEY idx_pg_related (related_type, related_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_permission_request_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id BIGINT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  operator_id INT UNSIGNED NULL,
  operator_name VARCHAR(120) NULL,
  action ENUM('submit','approve','reject','cancel','revoke','expire','extend') NOT NULL,
  before_json JSON NULL,
  after_json JSON NULL,
  note TEXT NULL,
  ip VARCHAR(64) NULL,
  user_agent VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_prl_request (request_id),
  KEY idx_prl_user (user_id),
  KEY idx_prl_operator (operator_id),
  KEY idx_prl_action (action),
  KEY idx_prl_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO crm_permissions (permission_key, module, action, description, risk_level) VALUES
('permission_request.create','permission_request','create','提交权限申请','low'),
('permission_request.view_own','permission_request','view_own','查看自己的权限申请','low'),
('permission_request.cancel_own','permission_request','cancel_own','取消自己的权限申请','low'),
('permission_request.approve_department','permission_request','approve_department','审批本部门权限申请','medium'),
('permission_request.approve_all','permission_request','approve_all','审批全部权限申请','high'),
('permission_request.revoke','permission_request','revoke','撤销临时授权','high'),
('permission_request.expire_check','permission_request','expire_check','检查过期临时授权','medium')
ON DUPLICATE KEY UPDATE module = VALUES(module), action = VALUES(action), description = VALUES(description), risk_level = VALUES(risk_level);

INSERT IGNORE INTO crm_roles (role_key, role_name, description, is_system, status)
VALUES ('team_leader', '组长', '组长，可审批本组小范围短期普通权限', 1, 'active');

INSERT IGNORE INTO crm_role_permissions (role_id, permission_key)
SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p
WHERE r.role_key = 'admin' AND p.permission_key IN ('permission_request.create','permission_request.view_own','permission_request.cancel_own','permission_request.approve_all','permission_request.revoke','permission_request.expire_check');

INSERT IGNORE INTO crm_role_permissions (role_id, permission_key)
SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p
WHERE r.role_key = 'manager' AND p.permission_key IN ('permission_request.create','permission_request.view_own','permission_request.cancel_own','permission_request.approve_department');

INSERT IGNORE INTO crm_role_permissions (role_id, permission_key)
SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p
WHERE r.role_key = 'team_leader' AND p.permission_key IN ('dashboard.view','permission_request.create','permission_request.view_own','permission_request.cancel_own','permission_request.approve_department');

INSERT IGNORE INTO crm_role_permissions (role_id, permission_key)
SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p
WHERE r.role_key IN ('sales','marketing','finance','viewer') AND p.permission_key IN ('permission_request.create','permission_request.view_own','permission_request.cancel_own');

INSERT INTO crm_schema_migrations (migration_key, description)
VALUES ('002_permission_requests', 'Permission request, temporary grant, expiry workflow')
ON DUPLICATE KEY UPDATE description = VALUES(description);
