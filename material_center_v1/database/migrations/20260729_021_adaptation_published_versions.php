<?php
declare(strict_types=1);

return [
    'version' => '20260729_021_adaptation_published_versions',
    'description' => 'Store immutable published product-adaptation configuration versions for commercial readers',
    'up' => [
        "CREATE TABLE IF NOT EXISTS mc_adaptation_published_versions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_id BIGINT UNSIGNED NOT NULL,
            version_no INT UNSIGNED NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'published',
            snapshot_json JSON NOT NULL,
            approval_id BIGINT UNSIGNED NULL,
            published_by BIGINT UNSIGNED NULL,
            published_at DATETIME NOT NULL,
            UNIQUE KEY uq_mc_adaptation_published_version (product_id,version_no),
            KEY idx_mc_adaptation_published_product (product_id,status,published_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ],
    'down' => [
        'DROP TABLE IF EXISTS mc_adaptation_published_versions',
    ],
];
