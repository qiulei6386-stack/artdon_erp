-- Step 3 unified quotation model.
-- Extends the existing cc_quotes / cc_quote_versions / cc_quote_items chain
-- with cc_* tables only. Legacy quote_* tables remain read-only.

CREATE TABLE IF NOT EXISTS cc_quote_details (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    quote_id BIGINT UNSIGNED NOT NULL,
    source_type VARCHAR(40) NOT NULL DEFAULT 'manual',
    source_order_no VARCHAR(120) NULL,
    source_snapshot LONGTEXT NULL,
    edit_mode VARCHAR(30) NOT NULL DEFAULT 'semi_free',
    contact_name VARCHAR(190) NULL,
    contact_phone VARCHAR(80) NULL,
    contact_email VARCHAR(190) NULL,
    country VARCHAR(120) NULL,
    exchange_rate_snapshot DECIMAL(18,8) NOT NULL DEFAULT 1,
    quote_date DATE NOT NULL,
    valid_until DATE NULL,
    owner_legacy_user_id INT UNSIGNED NULL,
    owner_name VARCHAR(190) NULL,
    payment_terms VARCHAR(500) NULL,
    trade_terms VARCHAR(190) NULL,
    price_template_id BIGINT UNSIGNED NULL,
    project_ref VARCHAR(190) NULL,
    subtotal_amount DECIMAL(18,4) NOT NULL DEFAULT 0,
    discount_amount DECIMAL(18,4) NOT NULL DEFAULT 0,
    shipping_amount DECIMAL(18,4) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(18,4) NOT NULL DEFAULT 0,
    other_amount DECIMAL(18,4) NOT NULL DEFAULT 0,
    commission_amount DECIMAL(18,4) NOT NULL DEFAULT 0,
    gross_profit DECIMAL(18,4) NOT NULL DEFAULT 0,
    gross_margin DECIMAL(9,4) NOT NULL DEFAULT 0,
    customer_note LONGTEXT NULL,
    internal_note LONGTEXT NULL,
    converted_order_id BIGINT UNSIGNED NULL,
    converted_order_no VARCHAR(120) NULL,
    converted_at DATETIME NULL,
    converted_by_legacy_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cc_quote_detail_quote (quote_id),
    KEY idx_cc_quote_detail_source (source_type, source_order_no),
    KEY idx_cc_quote_detail_owner (owner_legacy_user_id, quote_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cc_quote_item_details (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    quote_item_id BIGINT UNSIGNED NOT NULL,
    product_source VARCHAR(40) NOT NULL DEFAULT 'manual',
    sku_code VARCHAR(120) NULL,
    model_no VARCHAR(190) NULL,
    product_name VARCHAR(500) NULL,
    image_path VARCHAR(500) NULL,
    unit VARCHAR(40) NOT NULL DEFAULT 'PCS',
    lead_time VARCHAR(190) NULL,
    customer_note VARCHAR(1000) NULL,
    internal_note VARCHAR(1000) NULL,
    locked TINYINT(1) NOT NULL DEFAULT 0,
    unlock_reason VARCHAR(500) NULL,
    source_line_snapshot LONGTEXT NULL,
    custom_fields_json LONGTEXT NULL,
    reference_product_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cc_quote_item_detail_item (quote_item_id),
    KEY idx_cc_quote_item_model (model_no),
    KEY idx_cc_quote_item_sku (sku_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cc_quote_files (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    quote_id BIGINT UNSIGNED NOT NULL,
    version_no INT UNSIGNED NOT NULL DEFAULT 1,
    file_type VARCHAR(40) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    storage_path VARCHAR(500) NOT NULL,
    mime_type VARCHAR(120) NULL,
    file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    file_hash CHAR(64) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    uploaded_by_legacy_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_cc_quote_file_quote (quote_id, version_no, status),
    KEY idx_cc_quote_file_hash (file_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cc_quote_item_files (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    quote_item_id BIGINT UNSIGNED NOT NULL,
    file_type VARCHAR(40) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    storage_path VARCHAR(500) NOT NULL,
    mime_type VARCHAR(120) NULL,
    file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    file_hash CHAR(64) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    uploaded_by_legacy_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_cc_quote_item_file_item (quote_item_id, status),
    KEY idx_cc_quote_item_file_hash (file_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cc_quote_snapshots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    quote_id BIGINT UNSIGNED NOT NULL,
    quote_version_id BIGINT UNSIGNED NOT NULL,
    snapshot_type VARCHAR(40) NOT NULL DEFAULT 'draft',
    snapshot_json LONGTEXT NOT NULL,
    snapshot_hash CHAR(64) NOT NULL,
    created_by_legacy_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cc_quote_snapshot (quote_id, quote_version_id, snapshot_type, snapshot_hash),
    KEY idx_cc_quote_snapshot_lookup (quote_id, snapshot_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cc_quote_legacy_links (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    quote_id BIGINT UNSIGNED NULL,
    legacy_table VARCHAR(120) NOT NULL DEFAULT 'quote_orders',
    legacy_id BIGINT UNSIGNED NOT NULL,
    legacy_quote_no VARCHAR(120) NULL,
    link_status VARCHAR(30) NOT NULL DEFAULT 'active',
    migration_snapshot LONGTEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cc_quote_legacy_source (legacy_table, legacy_id),
    KEY idx_cc_quote_legacy_quote (quote_id, link_status),
    KEY idx_cc_quote_legacy_no (legacy_quote_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
