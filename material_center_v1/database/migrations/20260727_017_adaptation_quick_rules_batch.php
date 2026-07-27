<?php
declare(strict_types=1);

$fields = [
    ['accessory.diameter_mm', '直径', 'decimal', 'mm', 'mc_material_accessory.diameter_mm', 125],
    ['accessory.thickness_mm', '厚度 / 叠加高度', 'decimal', 'mm', 'mc_material_accessory.thickness_mm', 130],
];

$quote = static fn (?string $value): string => $value === null
    ? 'NULL'
    : "'" . str_replace("'", "''", $value) . "'";

$up = [
    "ALTER TABLE mc_adaptation_groups ADD COLUMN rule_json JSON NULL AFTER template_key",
    "ALTER TABLE mc_material_accessory
        ADD COLUMN diameter_mm DECIMAL(10,3) NULL AFTER size_text,
        ADD COLUMN thickness_mm DECIMAL(10,3) NULL AFTER diameter_mm",
];

foreach ($fields as [$code, $label, $type, $unit, $target, $sort]) {
    $up[] = "INSERT INTO mc_field_registry(field_code,storage_target,field_name,data_type,unit,is_required,validation_json,default_json,allow_batch,is_sensitive,customer_visible,allow_import,use_for_duplicate,use_for_adaptation,status,sort_order)
        VALUES(" . $quote($code) . ',' . $quote($target) . ',' . $quote($label) . ',' . $quote($type) . ',' . $quote($unit) . ",0,'{\"min\":0}',NULL,1,0,1,1,1,1,'active'," . (int) $sort . ")
        ON DUPLICATE KEY UPDATE storage_target=VALUES(storage_target),field_name=VALUES(field_name),data_type=VALUES(data_type),unit=VALUES(unit),validation_json=VALUES(validation_json),allow_batch=1,use_for_adaptation=1,sort_order=VALUES(sort_order)";
    $up[] = "INSERT IGNORE INTO mc_category_field_map(category_code,field_id,is_required,sort_order)
        SELECT 'accessory',id,0," . (int) $sort . " FROM mc_field_registry WHERE field_code=" . $quote($code);
}

return [
    'version' => '20260727_017_adaptation_quick_rules_batch',
    'description' => 'Add product component quick rules, accessory dimensions and batch adaptation support',
    'up' => $up,
    'down' => [
        "DELETE m FROM mc_category_field_map m JOIN mc_field_registry f ON f.id=m.field_id WHERE f.field_code IN('accessory.diameter_mm','accessory.thickness_mm')",
        "DELETE FROM mc_field_registry WHERE field_code IN('accessory.diameter_mm','accessory.thickness_mm')",
        "ALTER TABLE mc_material_accessory DROP COLUMN thickness_mm, DROP COLUMN diameter_mm",
        "ALTER TABLE mc_adaptation_groups DROP COLUMN rule_json",
    ],
];
