<?php
declare(strict_types=1);

return [
    'version' => '20260726_012_supplier_documents',
    'description' => 'Versioned supplier attachments with access metadata',
    'up' => [
        "CREATE TABLE IF NOT EXISTS mc_supplier_documents (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            supplier_id BIGINT UNSIGNED NOT NULL,
            document_type VARCHAR(50) NOT NULL DEFAULT 'attachment',
            file_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            mime_type VARCHAR(120) NOT NULL,
            file_size BIGINT UNSIGNED NOT NULL,
            sha256 CHAR(64) NOT NULL,
            version_no INT NOT NULL DEFAULT 1,
            access_level VARCHAR(30) NOT NULL DEFAULT 'purchasing',
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            deleted_at DATETIME NULL,
            INDEX idx_mc_supplier_document(supplier_id,deleted_at,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ],
    'down' => [
        'DROP TABLE IF EXISTS mc_supplier_documents',
    ],
];
