<?php
declare(strict_types=1);

return [
    'version' => '20260729_022_adaptation_product_profiles',
    'description' => 'Store product-level engineering envelopes independently of reusable adaptation groups',
    'up' => [
        "CREATE TABLE IF NOT EXISTS mc_adaptation_product_profiles (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_id BIGINT UNSIGNED NOT NULL,
            profile_json JSON NOT NULL,
            confirmed_by BIGINT UNSIGNED NULL,
            confirmed_at DATETIME NULL,
            created_by BIGINT UNSIGNED NULL,
            updated_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_mc_adaptation_profile_product (product_id),
            KEY idx_mc_adaptation_profile_updated (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ],
    'down' => [
        'DROP TABLE IF EXISTS mc_adaptation_product_profiles',
    ],
];
