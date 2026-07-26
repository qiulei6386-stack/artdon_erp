<?php
declare(strict_types=1);

$fields = [
    ['chip', 'chip.series_name', '系列', 'text', null, 'mc_material_chip.series_name', 115],
    ['optical', 'optical.compatible_les', '适配 LES', 'text', null, 'mc_material_optical.compatible_les', 115],
    ['optical', 'optical.length_mm', '长度', 'decimal', 'mm', 'mc_material_optical.length_mm', 125],
    ['optical', 'optical.width_mm', '宽度', 'decimal', 'mm', 'mc_material_optical.width_mm', 130],
    ['optical', 'optical.beam_angle_text', '光束角', 'text', null, 'mc_material_optical.beam_angle_text', 135],
    ['optical', 'optical.is_focusable', '是否调焦', 'boolean', null, 'mc_material_optical.is_focusable', 205],
    ['optical', 'optical.ies_file_text', 'IES 文件', 'text', null, 'mc_material_optical.ies_file_text', 210],
    ['optical', 'optical.photometric_curve_text', '配光曲线', 'text', null, 'mc_material_optical.photometric_curve_text', 215],
    ['accessory', 'accessory.interface_type', '接口', 'text', null, 'mc_material_accessory.interface_type', 135],
    ['packaging', 'packaging.die_file_text', '刀模图', 'text', null, 'mc_material_packaging.die_file_text', 210],
    ['packaging', 'packaging.label_template_text', '标签模板', 'text', null, 'mc_material_packaging.label_template_text', 220],
];

$quote = static fn (?string $value): string => $value === null
    ? 'NULL'
    : "'" . str_replace("'", "''", $value) . "'";

$up = [
    "ALTER TABLE mc_source_mappings ADD COLUMN source_snapshot_hash CHAR(64) NULL AFTER status, ADD COLUMN last_reviewed_at DATETIME NULL AFTER source_snapshot_hash, ADD UNIQUE KEY uq_mc_source_mapping_record(source_record_id)",
    "ALTER TABLE mc_material_metadata ADD COLUMN supplier_text VARCHAR(200) NULL AFTER spec_summary, ADD COLUMN remark TEXT NULL AFTER supplier_text",
    "ALTER TABLE mc_material_chip ADD COLUMN series_name VARCHAR(120) NULL AFTER light_source_type",
    "ALTER TABLE mc_material_optical ADD COLUMN compatible_les VARCHAR(160) NULL AFTER compatible_chip, ADD COLUMN length_mm DECIMAL(10,3) NULL AFTER diameter_mm, ADD COLUMN width_mm DECIMAL(10,3) NULL AFTER length_mm, ADD COLUMN beam_angle_text VARCHAR(80) NULL AFTER width_mm, ADD COLUMN is_focusable TINYINT(1) NULL AFTER mounting_structure, ADD COLUMN ies_file_text VARCHAR(500) NULL AFTER photometric_file_id, ADD COLUMN photometric_curve_text VARCHAR(500) NULL AFTER ies_file_text",
    "ALTER TABLE mc_material_accessory ADD COLUMN interface_type VARCHAR(120) NULL AFTER material_text",
    "ALTER TABLE mc_material_packaging ADD COLUMN die_file_text VARCHAR(500) NULL AFTER die_file_id, ADD COLUMN label_template_text VARCHAR(500) NULL AFTER label_file_id",
    "UPDATE mc_field_registry SET field_name='芯片类型' WHERE field_code='chip.light_source_type'",
    "UPDATE mc_field_registry SET field_name='封装类型' WHERE field_code='chip.package_type'",
    "UPDATE mc_field_registry SET field_name='芯片尺寸' WHERE field_code='chip.size_text'",
    "UPDATE mc_field_registry SET field_name='LES 尺寸' WHERE field_code='chip.pad_text'",
    "UPDATE mc_field_registry SET data_type='enum',validation_json=NULL WHERE field_code='optical.optical_type'",
];

$codes = [];
foreach ($fields as [$category, $code, $label, $type, $unit, $target, $sort]) {
    $codes[] = $code;
    $validation = in_array($type, ['decimal', 'integer'], true)
        ? '{"min":0}'
        : (in_array($type, ['text', 'textarea'], true) ? '{"maxLength":2000}' : null);
    $up[] = "INSERT INTO mc_field_registry(field_code,storage_target,field_name,data_type,unit,is_required,validation_json,default_json,allow_batch,is_sensitive,customer_visible,allow_import,use_for_duplicate,use_for_adaptation,status,sort_order)
        VALUES(" . $quote($code) . ',' . $quote($target) . ',' . $quote($label) . ',' . $quote($type) . ',' . $quote($unit) . ",0," . $quote($validation) . ",NULL,1,0,1,1,1,1,'active'," . (int) $sort . ")
        ON DUPLICATE KEY UPDATE storage_target=VALUES(storage_target),field_name=VALUES(field_name),data_type=VALUES(data_type),unit=VALUES(unit),validation_json=VALUES(validation_json),sort_order=VALUES(sort_order)";
    $up[] = "INSERT IGNORE INTO mc_category_field_map(category_code,field_id,is_required,sort_order)
        SELECT " . $quote($category) . ",id,0," . (int) $sort . " FROM mc_field_registry WHERE field_code=" . $quote($code);
}

$opticalTypeOptions = [
    ['透镜', '透镜', 10],
    ['反光杯', '反光杯', 20],
    ['柔光片', '柔光片', 30],
    ['导光板', '导光板', 40],
    ['调焦模组', '调焦模组', 50],
    ['玻璃', '玻璃', 60],
    ['其他', '其他', 70],
];
foreach ($opticalTypeOptions as [$value, $label, $sort]) {
    $up[] = "INSERT INTO mc_field_options(field_id,option_value,option_label,sort_order,status)
        SELECT id," . $quote($value) . ',' . $quote($label) . ',' . (int) $sort . ",'active' FROM mc_field_registry WHERE field_code='optical.optical_type'
        ON DUPLICATE KEY UPDATE option_label=VALUES(option_label),sort_order=VALUES(sort_order),status='active'";
}

$codeList = implode(',', array_map($quote, $codes));
$optionList = implode(',', array_map($quote, array_column($opticalTypeOptions, 0)));

return [
    'version' => '20260726_016_source_material_organizing',
    'description' => 'Unified idempotent source organizing and complete category drawer fields',
    'up' => $up,
    'down' => [
        "DELETE o FROM mc_field_options o JOIN mc_field_registry f ON f.id=o.field_id WHERE f.field_code='optical.optical_type' AND o.option_value IN($optionList)",
        "UPDATE mc_field_registry SET data_type='text' WHERE field_code='optical.optical_type'",
        "UPDATE mc_field_registry SET field_name='光源类型' WHERE field_code='chip.light_source_type'",
        "UPDATE mc_field_registry SET field_name='封装' WHERE field_code='chip.package_type'",
        "UPDATE mc_field_registry SET field_name='尺寸' WHERE field_code='chip.size_text'",
        "UPDATE mc_field_registry SET field_name='焊盘' WHERE field_code='chip.pad_text'",
        "DELETE m FROM mc_category_field_map m JOIN mc_field_registry f ON f.id=m.field_id WHERE f.field_code IN($codeList)",
        "DELETE FROM mc_field_registry WHERE field_code IN($codeList)",
        "ALTER TABLE mc_material_packaging DROP COLUMN label_template_text, DROP COLUMN die_file_text",
        "ALTER TABLE mc_material_accessory DROP COLUMN interface_type",
        "ALTER TABLE mc_material_optical DROP COLUMN photometric_curve_text, DROP COLUMN ies_file_text, DROP COLUMN is_focusable, DROP COLUMN beam_angle_text, DROP COLUMN width_mm, DROP COLUMN length_mm, DROP COLUMN compatible_les",
        "ALTER TABLE mc_material_chip DROP COLUMN series_name",
        "ALTER TABLE mc_material_metadata DROP COLUMN remark, DROP COLUMN supplier_text",
        "ALTER TABLE mc_source_mappings DROP INDEX uq_mc_source_mapping_record, DROP COLUMN last_reviewed_at, DROP COLUMN source_snapshot_hash",
    ],
];
