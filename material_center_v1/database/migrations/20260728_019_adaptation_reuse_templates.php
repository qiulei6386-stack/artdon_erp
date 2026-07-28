<?php
declare(strict_types=1);

return [
    'version' => '20260728_019_adaptation_reuse_templates',
    'description' => 'Add reusable product-adaptation mapping templates with selectable configuration groups',
    'up' => [
        "CREATE TABLE IF NOT EXISTS mc_adaptation_reuse_templates (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            template_code VARCHAR(80) NOT NULL UNIQUE,
            template_name VARCHAR(160) NOT NULL,
            description VARCHAR(500) NULL,
            source_product_id BIGINT UNSIGNED NOT NULL,
            source_group_ids_json JSON NOT NULL,
            include_power_rule TINYINT(1) NOT NULL DEFAULT 0,
            status ENUM('active','disabled') NOT NULL DEFAULT 'active',
            created_by BIGINT UNSIGNED NULL,
            updated_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_mc_adaptation_reuse_template_source (source_product_id,status),
            KEY idx_mc_adaptation_reuse_template_updated (status,updated_at),
            CONSTRAINT fk_mc_adaptation_reuse_template_source FOREIGN KEY (source_product_id) REFERENCES mc_products(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ],
    'down' => [
        'DROP TABLE IF EXISTS mc_adaptation_reuse_templates',
    ],
];
