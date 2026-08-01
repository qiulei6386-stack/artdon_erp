<?php
declare(strict_types=1);

return [
    'version' => '20260801_006_phase7_versions',
    'description' => 'Product adaptation V2 phase 7 product diffs, approval workflow, publishing, snapshots and rollback',
    'up' => [
        "CREATE TABLE IF NOT EXISTS mc_pa2_product_version_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_config_id BIGINT UNSIGNED NOT NULL,
            product_config_version_id BIGINT UNSIGNED NOT NULL,
            event_type VARCHAR(60) NOT NULL,
            from_status VARCHAR(40) NULL,
            to_status VARCHAR(40) NULL,
            actor_user_id BIGINT UNSIGNED NULL,
            note VARCHAR(700) NULL,
            payload_json JSON NULL,
            created_at DATETIME NOT NULL,
            KEY idx_mc_pa2_version_events_config (product_config_id,created_at),
            KEY idx_mc_pa2_version_events_version (product_config_version_id,event_type,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS mc_pa2_product_version_snapshots (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_config_id BIGINT UNSIGNED NOT NULL,
            product_config_version_id BIGINT UNSIGNED NOT NULL,
            snapshot_type VARCHAR(50) NOT NULL,
            snapshot_json JSON NOT NULL,
            snapshot_hash CHAR(64) NOT NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            KEY idx_mc_pa2_version_snapshots_config (product_config_id,snapshot_type,created_at),
            KEY idx_mc_pa2_version_snapshots_hash (snapshot_hash),
            UNIQUE KEY uk_mc_pa2_version_snapshot_once (product_config_version_id,snapshot_type,snapshot_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS mc_pa2_product_version_diffs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_config_id BIGINT UNSIGNED NOT NULL,
            base_version_id BIGINT UNSIGNED NULL,
            compare_version_id BIGINT UNSIGNED NOT NULL,
            diff_json JSON NOT NULL,
            diff_hash CHAR(64) NOT NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            KEY idx_mc_pa2_version_diffs_config (product_config_id,created_at),
            UNIQUE KEY uk_mc_pa2_version_diff (product_config_id,base_version_id,compare_version_id,diff_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ],
    'down' => [
        "DROP TABLE IF EXISTS mc_pa2_product_version_diffs",
        "DROP TABLE IF EXISTS mc_pa2_product_version_snapshots",
        "DROP TABLE IF EXISTS mc_pa2_product_version_events",
    ],
];
