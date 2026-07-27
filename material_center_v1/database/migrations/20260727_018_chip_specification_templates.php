<?php
declare(strict_types=1);

return [
    'version' => '20260727_018_chip_specification_templates',
    'description' => 'Add versioned chip specification templates, material variants and product adaptation selections',
    'up' => [
        "CREATE TABLE IF NOT EXISTS mc_chip_spec_templates (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            template_code VARCHAR(80) NOT NULL UNIQUE,
            template_name VARCHAR(160) NOT NULL,
            description VARCHAR(500) NULL,
            is_system_default TINYINT(1) NOT NULL DEFAULT 0,
            status ENUM('active','disabled') NOT NULL DEFAULT 'active',
            current_version_no INT UNSIGNED NOT NULL DEFAULT 1,
            created_by BIGINT UNSIGNED NULL,
            updated_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_mc_chip_templates_status (status,is_system_default)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS mc_chip_spec_template_versions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            template_id BIGINT UNSIGNED NOT NULL,
            version_no INT UNSIGNED NOT NULL,
            selection_json JSON NOT NULL,
            combinations_json JSON NOT NULL,
            change_note VARCHAR(500) NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_mc_chip_template_version (template_id,version_no),
            CONSTRAINT fk_mc_chip_template_version_template FOREIGN KEY (template_id) REFERENCES mc_chip_spec_templates(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS mc_chip_material_templates (
            material_id BIGINT UNSIGNED NOT NULL,
            template_id BIGINT UNSIGNED NOT NULL,
            applied_version_no INT UNSIGNED NOT NULL,
            applied_by BIGINT UNSIGNED NULL,
            applied_at DATETIME NOT NULL,
            synced_at DATETIME NULL,
            PRIMARY KEY (material_id,template_id),
            KEY idx_mc_chip_material_template_version (template_id,applied_version_no),
            CONSTRAINT fk_mc_chip_material_template_material FOREIGN KEY (material_id) REFERENCES mc_materials(id) ON DELETE CASCADE,
            CONSTRAINT fk_mc_chip_material_template_template FOREIGN KEY (template_id) REFERENCES mc_chip_spec_templates(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS mc_chip_spec_variants (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            material_id BIGINT UNSIGNED NOT NULL,
            variant_code VARCHAR(120) NOT NULL,
            spec_key CHAR(64) NOT NULL,
            cct_k INT UNSIGNED NULL,
            cct_min_k INT UNSIGNED NULL,
            cct_max_k INT UNSIGNED NULL,
            cri DECIMAL(6,2) NULL,
            sdcm DECIMAL(6,2) NULL,
            r9 DECIMAL(7,2) NULL,
            luminous_flux_lm DECIMAL(12,3) NULL,
            efficacy_lm_w DECIMAL(12,3) NULL,
            supplier_spec_code VARCHAR(160) NULL,
            purchase_price DECIMAL(14,4) NULL,
            currency CHAR(3) NOT NULL DEFAULT 'USD',
            stock_quantity DECIMAL(14,3) NULL,
            lead_time_days INT UNSIGNED NULL,
            source_type ENUM('template','manual','legacy') NOT NULL DEFAULT 'manual',
            source_template_id BIGINT UNSIGNED NULL,
            source_template_version_no INT UNSIGNED NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            needs_confirmation TINYINT(1) NOT NULL DEFAULT 0,
            status ENUM('active','disabled') NOT NULL DEFAULT 'active',
            sort_order INT NOT NULL DEFAULT 100,
            created_by BIGINT UNSIGNED NULL,
            updated_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_mc_chip_variant_spec (material_id,spec_key),
            UNIQUE KEY uq_mc_chip_variant_code (material_id,variant_code),
            KEY idx_mc_chip_variant_active (material_id,status,is_default,sort_order),
            KEY idx_mc_chip_variant_template (source_template_id,source_template_version_no),
            CONSTRAINT fk_mc_chip_variant_material FOREIGN KEY (material_id) REFERENCES mc_materials(id) ON DELETE CASCADE,
            CONSTRAINT fk_mc_chip_variant_template FOREIGN KEY (source_template_id) REFERENCES mc_chip_spec_templates(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS mc_adaptation_option_chip_variants (
            option_id BIGINT UNSIGNED NOT NULL,
            chip_variant_id BIGINT UNSIGNED NOT NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            status ENUM('active','disabled') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (option_id,chip_variant_id),
            KEY idx_mc_adaptation_chip_variant (chip_variant_id,status),
            CONSTRAINT fk_mc_adaptation_chip_variant_option FOREIGN KEY (option_id) REFERENCES mc_adaptation_options(id) ON DELETE CASCADE,
            CONSTRAINT fk_mc_adaptation_chip_variant_spec FOREIGN KEY (chip_variant_id) REFERENCES mc_chip_spec_variants(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS mc_chip_template_sync_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            template_ids_json JSON NOT NULL,
            target_material_ids_json JSON NOT NULL,
            mode ENUM('fill_missing','replace') NOT NULL,
            preview_json JSON NULL,
            result_json JSON NULL,
            actor_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            KEY idx_mc_chip_template_sync_actor (actor_id,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "INSERT INTO mc_chip_spec_templates
            (template_code,template_name,description,is_system_default,status,current_version_no,created_at,updated_at)
            SELECT 'SYSTEM-DEFAULT','系统默认芯片规格','维护常用色温、显指和色容差；模板修改后需明确同步到芯片。',1,'active',1,NOW(),NOW()
            WHERE NOT EXISTS(SELECT 1 FROM mc_chip_spec_templates WHERE is_system_default=1 AND status='active')",
        "INSERT INTO mc_chip_spec_template_versions
            (template_id,version_no,selection_json,combinations_json,change_note,created_at)
            SELECT id,1,'{\"cct\":[],\"cri\":[],\"sdcm\":[]}','[]','系统初始化',NOW()
            FROM mc_chip_spec_templates t
            WHERE t.template_code='SYSTEM-DEFAULT'
            AND NOT EXISTS(SELECT 1 FROM mc_chip_spec_template_versions v WHERE v.template_id=t.id AND v.version_no=1)",
        "INSERT IGNORE INTO mc_chip_spec_variants
            (material_id,variant_code,spec_key,cct_k,cct_min_k,cct_max_k,cri,sdcm,r9,luminous_flux_lm,efficacy_lm_w,
             source_type,is_default,needs_confirmation,status,sort_order,created_at,updated_at)
            SELECT c.material_id,CONCAT('LEGACY-',c.material_id),
                   SHA2(CONCAT_WS('|','legacy',COALESCE(c.cct_min_k,''),COALESCE(c.cct_max_k,''),COALESCE(c.cri,''),COALESCE(c.sdcm,''),COALESCE(c.r9,'')),256),
                   IF(c.cct_min_k IS NOT NULL AND c.cct_min_k=c.cct_max_k,c.cct_min_k,NULL),
                   c.cct_min_k,c.cct_max_k,c.cri,c.sdcm,c.r9,c.luminous_flux_lm,c.efficacy_lm_w,
                   'legacy',1,
                   IF(c.cct_min_k IS NULL OR c.cct_max_k IS NULL OR c.cct_min_k<>c.cct_max_k OR c.cri IS NULL OR c.sdcm IS NULL,1,0),
                   'active',10,NOW(),NOW()
            FROM mc_material_chip c
            WHERE c.cct_min_k IS NOT NULL OR c.cct_max_k IS NOT NULL OR c.cri IS NOT NULL OR c.sdcm IS NOT NULL",
        "INSERT IGNORE INTO mc_adaptation_option_chip_variants
            (option_id,chip_variant_id,is_default,status,created_at,updated_at)
            SELECT o.id,v.id,v.is_default,'active',NOW(),NOW()
            FROM mc_adaptation_options o
            JOIN mc_materials m ON m.id=o.material_id
            JOIN mc_material_categories c ON c.id=m.category_id AND c.code='chip'
            JOIN mc_chip_spec_variants v ON v.material_id=m.id AND v.status='active'",
    ],
    'down' => [
        'DROP TABLE IF EXISTS mc_chip_template_sync_logs',
        'DROP TABLE IF EXISTS mc_adaptation_option_chip_variants',
        'DROP TABLE IF EXISTS mc_chip_spec_variants',
        'DROP TABLE IF EXISTS mc_chip_material_templates',
        'DROP TABLE IF EXISTS mc_chip_spec_template_versions',
        'DROP TABLE IF EXISTS mc_chip_spec_templates',
    ],
];
