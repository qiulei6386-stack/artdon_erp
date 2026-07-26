-- Step 7 custom quote attachment ordering. Existing attachment tables remain unchanged.

CREATE TABLE IF NOT EXISTS cc_quote_file_orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    quote_file_id BIGINT UNSIGNED NULL,
    quote_item_file_id BIGINT UNSIGNED NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cc_quote_file_order_quote (quote_file_id),
    UNIQUE KEY uq_cc_quote_file_order_item (quote_item_file_id),
    KEY idx_cc_quote_file_order_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cc_quote_handoffs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    quote_id BIGINT UNSIGNED NOT NULL,
    handoff_type VARCHAR(20) NOT NULL,
    snapshot_json LONGTEXT NOT NULL,
    snapshot_hash CHAR(64) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'created',
    created_by_legacy_user_id INT UNSIGNED NULL,
    created_by_name VARCHAR(190) NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cc_quote_handoff (quote_id,handoff_type),
    KEY idx_cc_quote_handoff_status (handoff_type,status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
