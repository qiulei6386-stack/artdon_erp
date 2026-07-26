-- Steps 9-10 approval escalation, conversion and legacy document linkage.
CREATE TABLE IF NOT EXISTS cc_quote_review_actions (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,quote_id BIGINT UNSIGNED NOT NULL,quote_version_id BIGINT UNSIGNED NULL,
 action_code VARCHAR(40) NOT NULL,risk_level VARCHAR(20) NULL,risk_snapshot LONGTEXT NULL,opinion VARCHAR(2000) NULL,
 target_reviewer VARCHAR(190) NULL,actor_legacy_user_id INT UNSIGNED NULL,actor_name VARCHAR(190) NULL,created_at DATETIME NOT NULL,
 PRIMARY KEY(id),KEY idx_cc_review_quote(quote_id,created_at),KEY idx_cc_review_action(action_code,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS cc_quote_order_links (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,quote_id BIGINT UNSIGNED NOT NULL,quote_version_id BIGINT UNSIGNED NOT NULL,
 legacy_order_id BIGINT UNSIGNED NOT NULL,order_no VARCHAR(120) NOT NULL,conversion_snapshot LONGTEXT NOT NULL,
 snapshot_hash CHAR(64) NOT NULL,converted_by_legacy_user_id INT UNSIGNED NULL,converted_by_name VARCHAR(190) NULL,converted_at DATETIME NOT NULL,
 PRIMARY KEY(id),UNIQUE KEY uq_cc_quote_order_link(quote_id),UNIQUE KEY uq_cc_legacy_order_link(legacy_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS cc_legacy_document_snapshots (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,legacy_order_id BIGINT UNSIGNED NOT NULL,shipment_id BIGINT UNSIGNED NULL,
 document_type VARCHAR(20) NOT NULL,document_no VARCHAR(120) NOT NULL,version_no INT UNSIGNED NOT NULL DEFAULT 1,
 snapshot_json LONGTEXT NOT NULL,snapshot_hash CHAR(64) NOT NULL,template_version VARCHAR(40) NOT NULL DEFAULT 'legacy_v1',
 preview_path VARCHAR(500) NULL,pdf_path VARCHAR(500) NULL,excel_path VARCHAR(500) NULL,file_hash CHAR(64) NULL,
 generated_by_legacy_user_id INT UNSIGNED NULL,generated_at DATETIME NOT NULL,
 PRIMARY KEY(id),UNIQUE KEY uq_cc_legacy_document(legacy_order_id,shipment_id,document_type,version_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
