<?php
declare(strict_types=1);

return [
    'version' => '20260801_008_phase9_channel_api',
    'description' => 'Product adaptation V2 phase 9 downstream channel API, signatures, cache, snapshots and logs',
    'up' => [
        "CREATE TABLE IF NOT EXISTS mc_pa2_channel_clients (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_code VARCHAR(100) NOT NULL,
            client_name VARCHAR(180) NOT NULL,
            channel_code VARCHAR(80) NOT NULL,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            signature_required TINYINT(1) NOT NULL DEFAULT 1,
            allowed_scope_json JSON NULL,
            last_used_at DATETIME NULL,
            created_by BIGINT UNSIGNED NULL,
            updated_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uk_mc_pa2_channel_client (client_code),
            KEY idx_mc_pa2_channel_clients_channel (channel_code,is_enabled)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS mc_pa2_channel_package_snapshots (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            channel_code VARCHAR(80) NOT NULL,
            package_id BIGINT UNSIGNED NOT NULL,
            package_version_id BIGINT UNSIGNED NOT NULL,
            snapshot_type VARCHAR(60) NOT NULL DEFAULT 'published_payload',
            payload_json JSON NOT NULL,
            payload_hash CHAR(64) NOT NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uk_mc_pa2_channel_package_snapshot (channel_code,package_version_id,snapshot_type,payload_hash),
            KEY idx_mc_pa2_channel_package_snapshots_pkg (package_id,package_version_id),
            KEY idx_mc_pa2_channel_package_snapshots_channel (channel_code,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS mc_pa2_channel_cache (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            cache_key VARCHAR(190) NOT NULL,
            channel_code VARCHAR(80) NOT NULL,
            package_id BIGINT UNSIGNED NULL,
            package_version_id BIGINT UNSIGNED NULL,
            payload_json JSON NOT NULL,
            payload_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uk_mc_pa2_channel_cache_key (cache_key),
            KEY idx_mc_pa2_channel_cache_expire (channel_code,expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS mc_pa2_channel_access_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_code VARCHAR(100) NULL,
            channel_code VARCHAR(80) NULL,
            action VARCHAR(80) NOT NULL,
            request_hash CHAR(64) NULL,
            response_hash CHAR(64) NULL,
            status_code INT NOT NULL DEFAULT 200,
            message VARCHAR(500) NULL,
            ip_address VARCHAR(80) NULL,
            user_agent VARCHAR(300) NULL,
            created_at DATETIME NOT NULL,
            KEY idx_mc_pa2_channel_access_client (client_code,created_at),
            KEY idx_mc_pa2_channel_access_action (action,status_code,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS mc_pa2_channel_order_snapshots (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            channel_code VARCHAR(80) NOT NULL,
            client_code VARCHAR(100) NOT NULL,
            external_order_no VARCHAR(160) NOT NULL,
            package_id BIGINT UNSIGNED NOT NULL,
            package_version_id BIGINT UNSIGNED NOT NULL,
            payload_json JSON NOT NULL,
            payload_hash CHAR(64) NOT NULL,
            source_system VARCHAR(100) NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uk_mc_pa2_channel_order_snapshot (channel_code,external_order_no,package_version_id,payload_hash),
            KEY idx_mc_pa2_channel_order_snapshots_pkg (package_id,package_version_id),
            KEY idx_mc_pa2_channel_order_snapshots_channel (channel_code,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "INSERT INTO mc_pa2_channel_clients(client_code,client_name,channel_code,is_enabled,signature_required,allowed_scope_json,created_at,updated_at) VALUES
            ('commercial_center','商务中心','commercial',1,1,JSON_OBJECT('env_secret','PA2_CHANNEL_SECRET_COMMERCIAL_CENTER','read','published_packages','write','order_snapshots'),NOW(),NOW()),
            ('singapore_site','新加坡网站','singapore',1,1,JSON_OBJECT('env_secret','PA2_CHANNEL_SECRET_SINGAPORE_SITE','read','published_packages','write','order_snapshots'),NOW(),NOW())
            ON DUPLICATE KEY UPDATE client_name=VALUES(client_name),channel_code=VALUES(channel_code),is_enabled=VALUES(is_enabled),signature_required=VALUES(signature_required),allowed_scope_json=VALUES(allowed_scope_json),updated_at=NOW()",
    ],
    'down' => [
        "DROP TABLE IF EXISTS mc_pa2_channel_order_snapshots",
        "DROP TABLE IF EXISTS mc_pa2_channel_access_logs",
        "DROP TABLE IF EXISTS mc_pa2_channel_cache",
        "DROP TABLE IF EXISTS mc_pa2_channel_package_snapshots",
        "DROP TABLE IF EXISTS mc_pa2_channel_clients",
    ],
];
