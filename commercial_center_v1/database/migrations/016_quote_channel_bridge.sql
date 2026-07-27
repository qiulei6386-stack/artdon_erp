-- Quote product-type/channel separation and Singapore offline outbox.
-- CREATE-only migration: existing quotation and material-center tables remain untouched.

CREATE TABLE IF NOT EXISTS cc_quote_channel_context (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    quote_id BIGINT UNSIGNED NOT NULL,
    sales_channel VARCHAR(40) NOT NULL DEFAULT 'guangzhou_direct',
    fulfillment_mode VARCHAR(40) NOT NULL DEFAULT 'standard_production',
    configuration_level VARCHAR(30) NOT NULL DEFAULT 'standard',
    push_status VARCHAR(30) NOT NULL DEFAULT 'not_required',
    external_order_id VARCHAR(190) NULL,
    last_outbox_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cc_quote_channel_quote (quote_id),
    KEY idx_cc_quote_channel_status (sales_channel, push_status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cc_quote_item_adaptation_refs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    quote_item_id BIGINT UNSIGNED NOT NULL,
    configuration_level VARCHAR(30) NOT NULL DEFAULT 'standard',
    adaptation_product_id BIGINT UNSIGNED NULL,
    adaptation_version_no INT UNSIGNED NULL,
    configuration_passport_hash CHAR(64) NULL,
    adaptation_snapshot LONGTEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cc_quote_item_adaptation_item (quote_item_id),
    KEY idx_cc_quote_item_adaptation_product (adaptation_product_id, adaptation_version_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cc_channel_outbox (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    channel_code VARCHAR(60) NOT NULL,
    operation_type VARCHAR(40) NOT NULL,
    entity_type VARCHAR(40) NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    idempotency_key VARCHAR(190) NOT NULL,
    payload_json LONGTEXT NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts INT UNSIGNED NOT NULL DEFAULT 5,
    available_at DATETIME NOT NULL,
    external_reference VARCHAR(190) NULL,
    response_json LONGTEXT NULL,
    last_error VARCHAR(500) NULL,
    is_test TINYINT(1) NOT NULL DEFAULT 0,
    created_by_legacy_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    sent_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cc_channel_outbox_idempotency (idempotency_key),
    KEY idx_cc_channel_outbox_dispatch (channel_code, status, available_at),
    KEY idx_cc_channel_outbox_entity (entity_type, entity_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cc_channel_entity_links (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    channel_code VARCHAR(60) NOT NULL,
    entity_type VARCHAR(40) NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    external_id VARCHAR(190) NULL,
    sync_status VARCHAR(30) NOT NULL DEFAULT 'pending',
    last_payload_hash CHAR(64) NULL,
    last_synced_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cc_channel_entity (channel_code, entity_type, entity_id),
    KEY idx_cc_channel_external (channel_code, external_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
