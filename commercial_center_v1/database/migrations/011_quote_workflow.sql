-- Step 4 quotation workflow, approval and audit records. cc_* only.

CREATE TABLE IF NOT EXISTS cc_quote_approvals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    quote_id BIGINT UNSIGNED NOT NULL,
    quote_version_id BIGINT UNSIGNED NOT NULL,
    action_code VARCHAR(40) NOT NULL,
    approval_status VARCHAR(40) NOT NULL,
    reason VARCHAR(1000) NULL,
    actor_legacy_user_id INT UNSIGNED NULL,
    actor_name VARCHAR(190) NULL,
    before_snapshot_hash CHAR(64) NULL,
    after_snapshot_hash CHAR(64) NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_cc_quote_approval_queue (approval_status, created_at),
    KEY idx_cc_quote_approval_quote (quote_id, quote_version_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cc_quote_state_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    quote_id BIGINT UNSIGNED NOT NULL,
    quote_version_id BIGINT UNSIGNED NOT NULL,
    from_status VARCHAR(40) NOT NULL,
    to_status VARCHAR(40) NOT NULL,
    reason VARCHAR(1000) NULL,
    actor_legacy_user_id INT UNSIGNED NULL,
    actor_name VARCHAR(190) NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_cc_quote_state_quote (quote_id, created_at),
    KEY idx_cc_quote_state_target (to_status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cc_quote_audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    quote_id BIGINT UNSIGNED NOT NULL,
    quote_no VARCHAR(80) NOT NULL,
    quote_type VARCHAR(30) NOT NULL,
    object_type VARCHAR(80) NOT NULL DEFAULT 'quotation',
    object_id VARCHAR(120) NULL,
    action_code VARCHAR(80) NOT NULL,
    actor_legacy_user_id INT UNSIGNED NULL,
    actor_name VARCHAR(190) NULL,
    reason VARCHAR(1000) NULL,
    before_json LONGTEXT NULL,
    after_json LONGTEXT NULL,
    ip_hash CHAR(64) NULL,
    user_agent_hash CHAR(64) NULL,
    request_id CHAR(36) NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_cc_quote_audit_quote (quote_id, created_at),
    KEY idx_cc_quote_audit_action (action_code, created_at),
    KEY idx_cc_quote_audit_actor (actor_legacy_user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
