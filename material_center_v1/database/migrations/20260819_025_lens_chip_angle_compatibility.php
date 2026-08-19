<?php
declare(strict_types=1);

$quote = static fn (?string $value): string => $value === null
    ? 'NULL'
    : "'" . str_replace("'", "''", $value) . "'";

$fieldCode = 'optical.beam_angle_options';

return [
    'version' => '20260819_025_lens_chip_angle_compatibility',
    'description' => 'Add lens chip angle compatibility table and optical beam angle option field',
    'up' => [
        "ALTER TABLE mc_material_optical ADD COLUMN beam_angle_options VARCHAR(300) NULL AFTER beam_angle_max",
        "CREATE TABLE IF NOT EXISTS mc_lens_chip_angle_compatibilities (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            lens_material_id BIGINT UNSIGNED NOT NULL,
            chip_material_id BIGINT UNSIGNED NULL,
            chip_keyword VARCHAR(200) NULL,
            lens_beam_angle_deg DECIMAL(8,2) NULL,
            actual_beam_angle_deg DECIMAL(8,2) NOT NULL,
            beam_angle_label VARCHAR(80) NULL,
            les_text VARCHAR(120) NULL,
            note VARCHAR(500) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            sort_order INT NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED NULL,
            updated_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_lens_status (lens_material_id,status,sort_order),
            KEY idx_chip_status (chip_material_id,status),
            KEY idx_lens_actual (lens_material_id,actual_beam_angle_deg)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "INSERT INTO mc_field_registry(field_code,storage_target,field_name,data_type,unit,is_required,validation_json,default_json,allow_batch,is_sensitive,customer_visible,allow_import,use_for_duplicate,use_for_adaptation,status,sort_order)
            VALUES(".$quote($fieldCode).",".$quote('mc_material_optical.beam_angle_options').",".$quote('光束角选项').",".$quote('text').",".$quote('°').",0,".$quote('{"maxLength":300}').",NULL,1,0,1,1,1,1,'active',155)
            ON DUPLICATE KEY UPDATE storage_target=VALUES(storage_target),field_name=VALUES(field_name),data_type=VALUES(data_type),unit=VALUES(unit),validation_json=VALUES(validation_json),status='active',sort_order=VALUES(sort_order)",
        "INSERT INTO mc_category_field_map(category_code,field_id,is_required,sort_order)
            SELECT 'optical',id,0,155 FROM mc_field_registry WHERE field_code=".$quote($fieldCode)."
            ON DUPLICATE KEY UPDATE is_required=VALUES(is_required),sort_order=VALUES(sort_order)",
    ],
    'down' => [
        "DELETE m FROM mc_category_field_map m JOIN mc_field_registry f ON f.id=m.field_id WHERE f.field_code=".$quote($fieldCode),
        "DELETE FROM mc_field_registry WHERE field_code=".$quote($fieldCode),
        "DROP TABLE IF EXISTS mc_lens_chip_angle_compatibilities",
        "ALTER TABLE mc_material_optical DROP COLUMN beam_angle_options",
    ],
];
