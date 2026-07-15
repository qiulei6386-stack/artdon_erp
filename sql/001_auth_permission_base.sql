CREATE TABLE IF NOT EXISTS crm_departments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  code VARCHAR(60) NOT NULL,
  parent_id INT UNSIGNED NULL,
  manager_user_id INT UNSIGNED NULL,
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_department_code (code),
  KEY idx_department_parent (parent_id),
  KEY idx_department_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_roles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_key VARCHAR(80) NOT NULL,
  role_name VARCHAR(120) NOT NULL,
  description VARCHAR(255) NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_role_key (role_key),
  KEY idx_role_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(80) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  real_name VARCHAR(120) NOT NULL,
  english_name VARCHAR(120) NULL,
  email VARCHAR(190) NOT NULL,
  phone VARCHAR(60) NULL,
  department_id INT UNSIGNED NULL,
  role_id INT UNSIGNED NULL,
  status ENUM('pending','active','rejected','disabled','locked') NOT NULL DEFAULT 'pending',
  is_super_admin TINYINT(1) NOT NULL DEFAULT 0,
  force_password_change TINYINT(1) NOT NULL DEFAULT 0,
  last_login_at DATETIME NULL,
  last_login_ip VARCHAR(64) NULL,
  failed_login_count INT NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  approved_at DATETIME NULL,
  approved_by INT UNSIGNED NULL,
  rejected_at DATETIME NULL,
  rejected_by INT UNSIGNED NULL,
  reject_reason VARCHAR(500) NULL,
  UNIQUE KEY uk_user_username (username),
  UNIQUE KEY uk_user_email (email),
  KEY idx_user_status (status),
  KEY idx_user_department (department_id),
  KEY idx_user_role (role_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_permissions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  permission_key VARCHAR(120) NOT NULL,
  module VARCHAR(60) NOT NULL,
  action VARCHAR(80) NOT NULL,
  description VARCHAR(255) NULL,
  risk_level ENUM('low','medium','high','dangerous') NOT NULL DEFAULT 'low',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_permission_key (permission_key),
  KEY idx_permission_module (module),
  KEY idx_permission_risk (risk_level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_role_permissions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_id INT UNSIGNED NOT NULL,
  permission_key VARCHAR(120) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_role_permission (role_id, permission_key),
  KEY idx_rp_permission (permission_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_user_permissions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  permission_key VARCHAR(120) NOT NULL,
  effect ENUM('allow','deny') NOT NULL DEFAULT 'allow',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_user_permission (user_id, permission_key),
  KEY idx_up_permission (permission_key),
  KEY idx_up_effect (effect)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_user_scopes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  module VARCHAR(60) NOT NULL,
  scope_type ENUM('none','self','assigned','department','team','country','customer_group','all') NOT NULL DEFAULT 'self',
  scope_value VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_user_module_scope (user_id, module),
  KEY idx_scope_module (module),
  KEY idx_scope_type (scope_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_field_permissions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  field_key VARCHAR(120) NOT NULL,
  can_view TINYINT(1) NOT NULL DEFAULT 1,
  can_export TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_field_role_user (field_key, role_id, user_id),
  KEY idx_field_role (role_id),
  KEY idx_field_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_login_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  username VARCHAR(80) NULL,
  status ENUM('success','fail','logout') NOT NULL,
  ip VARCHAR(64) NULL,
  user_agent VARCHAR(500) NULL,
  message VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_login_user (user_id),
  KEY idx_login_username (username),
  KEY idx_login_status (status),
  KEY idx_login_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  operator_name VARCHAR(120) NULL,
  action VARCHAR(120) NOT NULL,
  module VARCHAR(80) NOT NULL,
  target_type VARCHAR(80) NULL,
  target_id VARCHAR(80) NULL,
  before_json JSON NULL,
  after_json JSON NULL,
  ip VARCHAR(64) NULL,
  user_agent VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_user (user_id),
  KEY idx_audit_action (action),
  KEY idx_audit_module (module),
  KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_system_settings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  setting_key VARCHAR(120) NOT NULL,
  setting_value TEXT NULL,
  setting_group VARCHAR(80) NOT NULL DEFAULT 'system',
  description VARCHAR(255) NULL,
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_setting_key (setting_key),
  KEY idx_setting_group (setting_group)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_schema_migrations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  migration_key VARCHAR(120) NOT NULL,
  description VARCHAR(255) NULL,
  executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_migration_key (migration_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_user_approval_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  action ENUM('approve','reject','disable','enable') NOT NULL,
  operator_id INT UNSIGNED NULL,
  operator_name VARCHAR(120) NULL,
  reason VARCHAR(500) NULL,
  before_json JSON NULL,
  after_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_approval_user (user_id),
  KEY idx_approval_action (action),
  KEY idx_approval_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO crm_departments (name, code, status, sort_order) VALUES
('总部', 'headquarters', 'disabled', 0),
('总经办', 'general_office', 'active', 1),
('业务部', 'sales', 'active', 10),
('外贸部', 'overseas_sales', 'active', 11),
('市场推广部', 'marketing', 'active', 12),
('工程部', 'engineering', 'active', 20),
('研发部', 'rd', 'active', 21),
('PLM项目部', 'plm', 'active', 22),
('BOM成本部', 'bom_cost', 'active', 23),
('生产部', 'production', 'active', 30),
('品质部', 'quality', 'active', 31),
('采购部', 'purchasing', 'active', 32),
('仓库部', 'warehouse', 'active', 33),
('财务部', 'finance', 'active', 40),
('行政人事部', 'hr_admin', 'active', 50),
('IT系统部', 'it', 'active', 60),
('售后服务部', 'after_sales', 'active', 70)
ON DUPLICATE KEY UPDATE name = VALUES(name), status = VALUES(status), sort_order = VALUES(sort_order);

INSERT INTO crm_roles (role_key, role_name, description, is_system, status) VALUES
('super_admin', '超级管理员', '拥有全部权限', 1, 'active'),
('admin', '系统管理员', '系统管理员，不可管理 super_admin', 1, 'active'),
('manager', '主管', '主管，可管理本部门数据', 1, 'active'),
('sales', '业务员', '业务员', 1, 'active'),
('marketing', '推广人员', '推广人员', 1, 'active'),
('finance', '财务', '财务', 1, 'active'),
('viewer', '只读', '只读账号', 1, 'active'),
('pending', '待审核', '待审核，无系统权限', 1, 'active')
ON DUPLICATE KEY UPDATE role_name = VALUES(role_name), description = VALUES(description), is_system = VALUES(is_system), status = VALUES(status);

INSERT INTO crm_permissions (permission_key, module, action, description, risk_level) VALUES
('dashboard.view','dashboard','view','查看工作台','low'),
('mail.view','mail','view','查看邮箱中心','low'),
('mail.compose','mail','compose','写邮件','medium'),
('mail.send','mail','send','发送邮件','high'),
('mail.reply','mail','reply','回复邮件','medium'),
('mail.delete','mail','delete','删除邮件','high'),
('mail.archive_to_customer','mail','archive_to_customer','归档邮件到客户','medium'),
('mail.manage_accounts','mail','manage_accounts','管理邮箱账号','high'),
('customer.view','customer','view','查看客户','low'),
('customer.create','customer','create','创建客户','medium'),
('customer.edit','customer','edit','编辑客户','medium'),
('customer.delete','customer','delete','删除客户','high'),
('customer.import','customer','import','导入客户','high'),
('customer.export','customer','export','导出客户','high'),
('customer.assign','customer','assign','分配客户','medium'),
('customer.view_logs','customer','view_logs','查看客户日志','medium'),
('customer.manage_temp_pool','customer','manage_temp_pool','管理客户暂存池','medium'),
('customer.stop_promotion','customer','stop_promotion','停止客户推广','high'),
('customer.restore_promotion','customer','restore_promotion','恢复客户推广','high'),
('contact.view','contact','view','查看联系人','low'),
('contact.create','contact','create','创建联系人','medium'),
('contact.edit','contact','edit','编辑联系人','medium'),
('contact.delete','contact','delete','删除联系人','high'),
('contact.stop_promotion','contact','stop_promotion','停止联系人推广','high'),
('contact.restore_promotion','contact','restore_promotion','恢复联系人推广','high'),
('follow.view','follow','view','查看跟进','low'),
('follow.create','follow','create','创建跟进','medium'),
('follow.edit','follow','edit','编辑跟进','medium'),
('follow.delete','follow','delete','删除跟进','high'),
('follow.view_all','follow','view_all','查看全部跟进','high'),
('promotion.view','promotion','view','查看推广','low'),
('promotion.create_project','promotion','create_project','创建推广项目','medium'),
('promotion.edit_project','promotion','edit_project','编辑推广项目','medium'),
('promotion.delete_project','promotion','delete_project','删除推广项目','high'),
('promotion.create_group','promotion','create_group','创建推广分组','medium'),
('promotion.edit_group','promotion','edit_group','编辑推广分组','medium'),
('promotion.delete_group','promotion','delete_group','删除推广分组','high'),
('promotion.move_customer','promotion','move_customer','移动推广客户','medium'),
('promotion.stop_promotion','promotion','stop_promotion','停止推广','high'),
('promotion.restore_promotion','promotion','restore_promotion','恢复推广','high'),
('promotion.export','promotion','export','导出推广数据','high'),
('logs.view_own','logs','view_own','查看本人日志','low'),
('logs.view_department','logs','view_department','查看部门日志','medium'),
('logs.view_all','logs','view_all','查看全部日志','high'),
('logs.export','logs','export','导出日志','high'),
('import.customer','import','customer','导入客户','high'),
('import.contact','import','contact','导入联系人','high'),
('import.preview','import','preview','预览导入','medium'),
('import.confirm','import','confirm','确认导入','high'),
('export.customer','export','customer','导出客户','high'),
('export.contact','export','contact','导出联系人','high'),
('export.logs','export','logs','导出日志','high'),
('settings.view','settings','view','查看设置','low'),
('settings.edit','settings','edit','编辑设置','high'),
('settings.schema_scan','settings','schema_scan','扫描结构','high'),
('settings.schema_repair','settings','schema_repair','修复结构','dangerous'),
('notifications.view_own','notifications','view_own','查看自己的通知','low'),
('notifications.manage','notifications','manage','管理系统通知','high'),
('linkage.view','linkage','view','查看系统联动中心','low'),
('linkage.audit','linkage','audit','查看联动事件审计','medium'),
('linkage.manage','linkage','manage','管理联动注册配置','high'),
('users.view','users','view','查看用户','medium'),
('users.create','users','create','后台新增账号','high'),
('users.approve','users','approve','审核用户','high'),
('users.reject','users','reject','驳回用户','high'),
('users.disable','users','disable','禁用用户','high'),
('users.enable','users','enable','启用用户','high'),
('users.reset_password','users','reset_password','重置密码','high'),
('users.assign_role','users','assign_role','分配角色','high'),
('users.assign_department','users','assign_department','分配部门','high'),
('users.assign_permission','users','assign_permission','分配用户权限','high'),
('users.manage_roles','users','manage_roles','管理角色','high'),
('users.manage_departments','users','manage_departments','管理部门','high'),
('dangerous.bulk_delete','dangerous','bulk_delete','批量删除','dangerous'),
('dangerous.bulk_export','dangerous','bulk_export','批量导出','dangerous'),
('dangerous.hard_delete_customer','dangerous','hard_delete_customer','硬删除客户','dangerous'),
('dangerous.schema_repair','dangerous','schema_repair','结构修复','dangerous'),
('dangerous.permission_admin','dangerous','permission_admin','权限管理员','dangerous'),
('dangerous.view_all_mail','dangerous','view_all_mail','查看全部邮件','dangerous'),
('dangerous.view_all_customer','dangerous','view_all_customer','查看全部客户','dangerous'),
('customer.view_email','field','customer.view_email','查看客户邮箱','medium'),
('customer.view_phone','field','customer.view_phone','查看客户电话','medium'),
('customer.view_whatsapp','field','customer.view_whatsapp','查看 WhatsApp','medium'),
('customer.view_address','field','customer.view_address','查看客户地址','medium'),
('customer.view_private_note','field','customer.view_private_note','查看客户私密备注','high'),
('mail.view_body','field','mail.view_body','查看邮件正文','medium'),
('mail.view_attachments','field','mail.view_attachments','查看邮件附件','medium'),
('quote.view_amount','field','quote.view_amount','查看报价金额','high'),
('order.view_amount','field','order.view_amount','查看订单金额','high')
ON DUPLICATE KEY UPDATE module = VALUES(module), action = VALUES(action), description = VALUES(description), risk_level = VALUES(risk_level);

INSERT IGNORE INTO crm_role_permissions (role_id, permission_key)
SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p WHERE r.role_key = 'super_admin';

INSERT IGNORE INTO crm_role_permissions (role_id, permission_key)
SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p
WHERE r.role_key = 'admin' AND p.permission_key NOT IN ('dangerous.hard_delete_customer','dangerous.schema_repair','dangerous.view_all_mail','dangerous.view_all_customer','linkage.audit','linkage.manage');

INSERT IGNORE INTO crm_role_permissions (role_id, permission_key)
SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p
WHERE r.role_key = 'admin' AND p.permission_key = 'linkage.view';

INSERT IGNORE INTO crm_role_permissions (role_id, permission_key)
SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p
WHERE r.role_key IN ('manager','sales','marketing','finance','viewer') AND p.permission_key = 'notifications.view_own';

INSERT IGNORE INTO crm_role_permissions (role_id, permission_key)
SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p
WHERE r.role_key = 'manager' AND p.permission_key IN ('dashboard.view','mail.view','customer.view','customer.create','customer.edit','customer.assign','customer.view_logs','contact.view','contact.create','contact.edit','follow.view','follow.create','follow.edit','follow.view_all','promotion.view','logs.view_own','logs.view_department','settings.view','customer.view_email','customer.view_phone','customer.view_whatsapp','customer.view_address','mail.view_body','mail.view_attachments');

INSERT IGNORE INTO crm_role_permissions (role_id, permission_key)
SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p
WHERE r.role_key = 'sales' AND p.permission_key IN ('dashboard.view','mail.view','customer.view','customer.create','customer.edit','contact.view','contact.create','contact.edit','follow.view','follow.create','follow.edit','logs.view_own','customer.view_email','customer.view_phone','customer.view_whatsapp','mail.view_body');

INSERT IGNORE INTO crm_role_permissions (role_id, permission_key)
SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p
WHERE r.role_key = 'marketing' AND p.permission_key IN ('dashboard.view','promotion.view','promotion.create_project','promotion.edit_project','promotion.create_group','promotion.edit_group','promotion.move_customer','customer.view','contact.view','logs.view_own','customer.view_email');

INSERT IGNORE INTO crm_role_permissions (role_id, permission_key)
SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p
WHERE r.role_key = 'finance' AND p.permission_key IN ('dashboard.view','customer.view','logs.view_own','quote.view_amount','order.view_amount');

INSERT IGNORE INTO crm_role_permissions (role_id, permission_key)
SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p
WHERE r.role_key = 'viewer' AND p.permission_key IN ('dashboard.view','customer.view','contact.view','follow.view','promotion.view','logs.view_own');

INSERT INTO crm_system_settings (setting_key, setting_value, setting_group, description)
VALUES
('system_name', 'Artdon Office V18', 'system', '系统名称'),
('installed', '0', 'system', '安装状态'),
('login_lock_threshold', '5', 'security', '连续失败锁定阈值'),
('login_lock_minutes', '15', 'security', '临时锁定分钟数')
ON DUPLICATE KEY UPDATE description = VALUES(description);

INSERT INTO crm_schema_migrations (migration_key, description)
VALUES ('001_auth_permission_base', 'Auth, permission, audit, settings base schema')
ON DUPLICATE KEY UPDATE description = VALUES(description);
