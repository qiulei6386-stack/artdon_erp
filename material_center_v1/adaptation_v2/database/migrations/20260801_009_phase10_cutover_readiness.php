<?php
declare(strict_types=1);

return [
    'version' => '20260801_009_phase10_cutover_readiness',
    'description' => 'Product adaptation V2 phase 10 cutover readiness audits and final acceptance checks',
    'up' => [
        "CREATE TABLE IF NOT EXISTS mc_pa2_cutover_audits (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            audit_code VARCHAR(120) NOT NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'draft',
            readiness_json JSON NULL,
            legacy_mutated TINYINT(1) NOT NULL DEFAULT 0,
            old_bom_mutated TINYINT(1) NOT NULL DEFAULT 0,
            menu_switched TINYINT(1) NOT NULL DEFAULT 0,
            note VARCHAR(800) NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uk_mc_pa2_cutover_audit_code (audit_code),
            KEY idx_mc_pa2_cutover_audits_status (status,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS mc_pa2_cutover_check_items (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            audit_id BIGINT UNSIGNED NOT NULL,
            check_code VARCHAR(120) NOT NULL,
            check_name VARCHAR(220) NOT NULL,
            result VARCHAR(40) NOT NULL DEFAULT 'pending',
            severity VARCHAR(40) NOT NULL DEFAULT 'normal',
            evidence_json JSON NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uk_mc_pa2_cutover_check (audit_id,check_code),
            KEY idx_mc_pa2_cutover_checks_result (result,severity)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ],
    'down' => [
        "DROP TABLE IF EXISTS mc_pa2_cutover_check_items",
        "DROP TABLE IF EXISTS mc_pa2_cutover_audits",
    ],
];
