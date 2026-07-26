<?php
declare(strict_types=1);
return [
 'version'=>'20260726_007_supplier_profiles',
 'description'=>'Supplier commercial profiles and price approval fields',
 'up'=>[
  "CREATE TABLE IF NOT EXISTS mc_supplier_profiles (supplier_id BIGINT UNSIGNED PRIMARY KEY,default_currency CHAR(3) NOT NULL DEFAULT 'CNY',payment_terms VARCHAR(160) NULL,quality_grade VARCHAR(30) NULL,tax_rate DECIMAL(7,4) NULL,notes TEXT NULL,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
  "CREATE TABLE IF NOT EXISTS mc_supplier_attachments (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,supplier_id BIGINT UNSIGNED NOT NULL,attachment_type VARCHAR(50) NOT NULL,file_name VARCHAR(255) NOT NULL,file_path VARCHAR(500) NOT NULL,mime_type VARCHAR(120) NOT NULL,file_size BIGINT UNSIGNED NOT NULL,sha256 CHAR(64) NOT NULL,created_by BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL,deleted_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
 ],
 'down'=>['DROP TABLE IF EXISTS mc_supplier_attachments','DROP TABLE IF EXISTS mc_supplier_profiles'],
];
