<?php
declare(strict_types=1);

return [
    'version' => '20260801_005_phase6_engine',
    'description' => 'Product adaptation V2 phase 6 compatibility calculation, conflict engine, cache and recalculation jobs',
    'up' => [
        "CREATE TABLE IF NOT EXISTS mc_pa2_adaptation_result_cache (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_config_version_id BIGINT UNSIGNED NOT NULL,
            product_group_config_id BIGINT UNSIGNED NOT NULL,
            group_code VARCHAR(80) NOT NULL,
            candidate_type VARCHAR(40) NOT NULL DEFAULT 'material',
            material_id BIGINT UNSIGNED NULL,
            option_definition_id BIGINT UNSIGNED NULL,
            result_status VARCHAR(40) NOT NULL,
            match_score DECIMAL(6,2) NOT NULL DEFAULT 0,
            reason_json JSON NULL,
            conflict_fields_json JSON NULL,
            rule_trace_json JSON NULL,
            calculated_hash CHAR(64) NOT NULL,
            calculated_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_mc_pa2_result_version (product_config_version_id,result_status,match_score),
            KEY idx_mc_pa2_result_group (product_group_config_id,candidate_type,material_id,option_definition_id),
            UNIQUE KEY uk_mc_pa2_result_hash (calculated_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS mc_pa2_adaptation_conflicts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_config_version_id BIGINT UNSIGNED NOT NULL,
            product_group_config_id BIGINT UNSIGNED NULL,
            group_code VARCHAR(80) NULL,
            conflict_code VARCHAR(120) NOT NULL,
            conflict_level VARCHAR(40) NOT NULL DEFAULT 'warning',
            result_status VARCHAR(40) NOT NULL,
            material_id BIGINT UNSIGNED NULL,
            option_definition_id BIGINT UNSIGNED NULL,
            conflict_fields_json JSON NULL,
            reason_text VARCHAR(700) NULL,
            is_resolved TINYINT(1) NOT NULL DEFAULT 0,
            resolution_type VARCHAR(80) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_mc_pa2_conflict_version (product_config_version_id,is_resolved,conflict_level),
            KEY idx_mc_pa2_conflict_group (product_group_config_id,result_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS mc_pa2_adaptation_recalc_jobs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_config_version_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'pending',
            request_reason VARCHAR(240) NULL,
            requested_by BIGINT UNSIGNED NULL,
            started_at DATETIME NULL,
            finished_at DATETIME NULL,
            summary_json JSON NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_mc_pa2_recalc_version (product_config_version_id,status,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ],
    'down' => [
        "DROP TABLE IF EXISTS mc_pa2_adaptation_recalc_jobs",
        "DROP TABLE IF EXISTS mc_pa2_adaptation_conflicts",
        "DROP TABLE IF EXISTS mc_pa2_adaptation_result_cache",
    ],
];
