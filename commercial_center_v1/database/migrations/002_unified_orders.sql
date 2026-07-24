-- M1-O unified order foundation. Creates cc_* tables only.
CREATE TABLE IF NOT EXISTS cc_external_orders (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, channel VARCHAR(40) NOT NULL, external_order_no VARCHAR(120) NOT NULL,
 external_reference VARCHAR(190) NOT NULL, idempotency_key VARCHAR(190) NOT NULL, payload_hash CHAR(64) NOT NULL,
 route_type VARCHAR(40) NOT NULL DEFAULT 'stock_order', sync_status VARCHAR(40) NOT NULL DEFAULT 'pending_validation',
 sync_attempts INT UNSIGNED NOT NULL DEFAULT 0, last_sync_at DATETIME NULL, last_sync_error VARCHAR(500) NULL,
 received_at DATETIME NOT NULL, is_test TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
 PRIMARY KEY(id), UNIQUE KEY uq_cc_external_order_channel_no(channel,external_order_no),
 UNIQUE KEY uq_cc_external_order_idempotency(channel,idempotency_key), KEY idx_cc_external_order_sync(sync_status,received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS cc_orders (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, order_no VARCHAR(80) NOT NULL, external_order_id BIGINT UNSIGNED NULL,
 order_source VARCHAR(50) NOT NULL, sales_channel VARCHAR(50) NOT NULL, external_order_no VARCHAR(120) NULL,
 legacy_quote_id BIGINT UNSIGNED NULL, legacy_quote_version_id BIGINT UNSIGNED NULL, legacy_customer_id BIGINT UNSIGNED NULL,
 customer_name VARCHAR(190) NULL, currency CHAR(3) NOT NULL DEFAULT 'USD', language VARCHAR(10) NOT NULL DEFAULT 'en',
 total_amount DECIMAL(18,4) NOT NULL DEFAULT 0, internal_status VARCHAR(50) NOT NULL DEFAULT 'pending_review',
 customer_status VARCHAR(50) NOT NULL DEFAULT 'Order Received', payment_status VARCHAR(40) NOT NULL DEFAULT 'pending',
 stock_status VARCHAR(40) NOT NULL DEFAULT 'pending', packaging_status VARCHAR(40) NOT NULL DEFAULT 'pending',
 shipment_status VARCHAR(40) NOT NULL DEFAULT 'pending', expected_ship_at DATE NULL, status VARCHAR(30) NOT NULL DEFAULT 'active',
 is_test TINYINT(1) NOT NULL DEFAULT 0, created_by_legacy_user_id INT UNSIGNED NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
 PRIMARY KEY(id), UNIQUE KEY uq_cc_order_no(order_no), UNIQUE KEY uq_cc_order_external(external_order_id),
 KEY idx_cc_order_source(order_source,internal_status), KEY idx_cc_order_customer(legacy_customer_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS cc_order_items (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, order_id BIGINT UNSIGNED NOT NULL, legacy_product_id BIGINT UNSIGNED NULL,
 inventory_sku_id BIGINT UNSIGNED NULL, sku_code VARCHAR(120) NULL, product_snapshot LONGTEXT NOT NULL,
 configuration_snapshot LONGTEXT NULL, quantity DECIMAL(18,3) NOT NULL, unit_price DECIMAL(18,4) NOT NULL,
 line_amount DECIMAL(18,4) NOT NULL, is_test TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
 PRIMARY KEY(id), KEY idx_cc_order_item_order(order_id,id), KEY idx_cc_order_item_sku(inventory_sku_id,sku_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS cc_order_status_history (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, order_id BIGINT UNSIGNED NOT NULL, from_status VARCHAR(50) NULL,
 to_status VARCHAR(50) NOT NULL, customer_status VARCHAR(50) NULL, reason VARCHAR(500) NULL,
 actor_legacy_user_id INT UNSIGNED NULL, is_test TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL,
 PRIMARY KEY(id), KEY idx_cc_order_history(order_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS cc_external_order_events (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, external_order_id BIGINT UNSIGNED NOT NULL, event_key VARCHAR(100) NOT NULL,
 event_status VARCHAR(40) NOT NULL, payload_hash CHAR(64) NULL, message VARCHAR(500) NULL,
 is_test TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL,
 PRIMARY KEY(id), KEY idx_cc_external_event(external_order_id,created_at), KEY idx_cc_external_event_status(event_status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
