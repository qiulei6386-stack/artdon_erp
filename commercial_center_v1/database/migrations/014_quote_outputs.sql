-- Step 8 unified quotation output snapshots, artifacts and deliveries.

CREATE TABLE IF NOT EXISTS cc_quote_output_snapshots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    quote_id BIGINT UNSIGNED NOT NULL,
    quote_version_id BIGINT UNSIGNED NOT NULL,
    version_no INT UNSIGNED NOT NULL,
    quote_status VARCHAR(30) NOT NULL,
    watermark VARCHAR(80) NULL,
    snapshot_json LONGTEXT NOT NULL,
    snapshot_hash CHAR(64) NOT NULL,
    created_by_legacy_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cc_quote_output_snapshot (quote_id,quote_version_id,quote_status,snapshot_hash),
    KEY idx_cc_quote_output_quote (quote_id,version_no,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cc_quote_output_artifacts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    output_snapshot_id BIGINT UNSIGNED NOT NULL,
    artifact_type VARCHAR(20) NOT NULL,
    storage_path VARCHAR(500) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    file_hash CHAR(64) NOT NULL,
    generated_by_legacy_user_id INT UNSIGNED NULL,
    generated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cc_quote_output_artifact (output_snapshot_id,artifact_type),
    KEY idx_cc_quote_output_artifact_hash (file_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cc_quote_deliveries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    quote_id BIGINT UNSIGNED NOT NULL,
    output_snapshot_id BIGINT UNSIGNED NOT NULL,
    artifact_id BIGINT UNSIGNED NULL,
    recipient_email VARCHAR(500) NOT NULL,
    cc_email VARCHAR(1000) NULL,
    subject VARCHAR(500) NOT NULL,
    message_body LONGTEXT NULL,
    delivery_status VARCHAR(30) NOT NULL,
    error_message VARCHAR(1000) NULL,
    sent_by_legacy_user_id INT UNSIGNED NULL,
    sent_by_name VARCHAR(190) NULL,
    sent_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_cc_quote_delivery_quote (quote_id,created_at),
    KEY idx_cc_quote_delivery_status (delivery_status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
