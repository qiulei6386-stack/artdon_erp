-- Artdon Commercial Center V1 foundation plan.
-- PLAN ONLY: do not execute without explicit approval and a fresh database snapshot.
-- Target database verified in production: artdon_new_erp.
-- This file creates cc_* objects only and does not alter legacy tables.

CREATE TABLE IF NOT EXISTS cc_schema_migrations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    migration_name VARCHAR(190) NOT NULL,
    checksum CHAR(64) NOT NULL,
    execution_status VARCHAR(40) NOT NULL DEFAULT 'applied',
    applied_by_legacy_user_id INT UNSIGNED NULL,
    applied_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cc_schema_migration_name (migration_name),
    KEY idx_cc_schema_migration_status (execution_status, applied_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cc_entity_links (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    entity_type VARCHAR(80) NOT NULL,
    cc_entity_id BIGINT UNSIGNED NOT NULL,
    source_system VARCHAR(80) NOT NULL,
    source_table VARCHAR(120) NOT NULL,
    source_id VARCHAR(120) NOT NULL,
    source_code VARCHAR(190) NULL,
    link_status VARCHAR(40) NOT NULL DEFAULT 'active',
    last_checked_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cc_entity_source (entity_type, cc_entity_id, source_system, source_table, source_id),
    KEY idx_cc_entity_source_lookup (source_system, source_table, source_id),
    KEY idx_cc_entity_code (source_system, source_code),
    KEY idx_cc_entity_status (link_status, last_checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cc_integration_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    integration_key VARCHAR(100) NOT NULL,
    direction VARCHAR(20) NOT NULL DEFAULT 'read',
    operation VARCHAR(100) NOT NULL,
    status VARCHAR(40) NOT NULL,
    source_system VARCHAR(80) NULL,
    source_table VARCHAR(120) NULL,
    source_id VARCHAR(120) NULL,
    cc_entity_type VARCHAR(80) NULL,
    cc_entity_id BIGINT UNSIGNED NULL,
    correlation_id CHAR(36) NULL,
    message VARCHAR(500) NULL,
    context_json LONGTEXT NULL,
    is_test TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_cc_integration_key_status (integration_key, status, created_at),
    KEY idx_cc_integration_source (source_system, source_table, source_id),
    KEY idx_cc_integration_correlation (correlation_id),
    KEY idx_cc_integration_test (is_test, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cc_activity_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    legacy_user_id INT UNSIGNED NULL,
    actor_name VARCHAR(190) NULL,
    activity_key VARCHAR(120) NOT NULL,
    entity_type VARCHAR(80) NULL,
    entity_id BIGINT UNSIGNED NULL,
    result_status VARCHAR(40) NOT NULL,
    request_id CHAR(36) NULL,
    ip_hash CHAR(64) NULL,
    user_agent_hash CHAR(64) NULL,
    context_json LONGTEXT NULL,
    is_test TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_cc_activity_actor (legacy_user_id, created_at),
    KEY idx_cc_activity_entity (entity_type, entity_id, created_at),
    KEY idx_cc_activity_key (activity_key, result_status, created_at),
    KEY idx_cc_activity_test (is_test, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
