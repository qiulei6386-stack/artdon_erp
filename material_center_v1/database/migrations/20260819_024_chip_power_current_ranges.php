<?php
declare(strict_types=1);

$quote = static fn (?string $value): string => $value === null
    ? 'NULL'
    : "'" . str_replace("'", "''", $value) . "'";

$fields = [
    ['chip.min_power_w', '最小功率', 'decimal', 'W', 'mc_material_chip.min_power_w', 120],
    ['chip.max_power_w', '最大功率', 'decimal', 'W', 'mc_material_chip.max_power_w', 130],
    ['chip.voltage_v', '电压', 'decimal', 'V', 'mc_material_chip.voltage_v', 140],
    ['chip.current_min_ma', '最小电流', 'decimal', 'mA', 'mc_material_chip.current_min_ma', 150],
    ['chip.current_max_ma', '最大电流', 'decimal', 'mA', 'mc_material_chip.current_max_ma', 160],
    ['chip.cct_k', '色温', 'integer', 'K', 'mc_material_chip.cct_k', 170],
    ['chip.cri', '显色指数', 'decimal', 'Ra', 'mc_material_chip.cri', 180],
    ['chip.luminous_flux_lm', '光通量', 'decimal', 'lm', 'mc_material_chip.luminous_flux_lm', 190],
    ['chip.efficacy_lm_w', '光效', 'decimal', 'lm/W', 'mc_material_chip.efficacy_lm_w', 200],
    ['chip.sdcm', 'SDCM', 'decimal', null, 'mc_material_chip.sdcm', 210],
    ['chip.r9', 'R9', 'decimal', null, 'mc_material_chip.r9', 220],
    ['chip.size_text', '芯片尺寸', 'text', null, 'mc_material_chip.size_text', 230],
    ['chip.pad_text', 'LES 尺寸', 'text', null, 'mc_material_chip.pad_text', 240],
    ['chip.lifetime_hours', '寿命', 'integer', 'h', 'mc_material_chip.lifetime_hours', 250],
    ['chip.certification', '认证', 'text', null, 'mc_material_chip.certification', 260],
];

$up = [
    "ALTER TABLE mc_material_chip
        ADD COLUMN min_power_w DECIMAL(10,3) NULL AFTER rated_power_w,
        ADD COLUMN current_min_ma DECIMAL(10,3) NULL AFTER current_ma,
        ADD COLUMN current_max_ma DECIMAL(10,3) NULL AFTER current_min_ma,
        ADD COLUMN cct_k INT NULL AFTER current_max_ma",
    "UPDATE mc_material_chip
        SET min_power_w=COALESCE(min_power_w,rated_power_w),
            current_max_ma=COALESCE(current_max_ma,current_ma),
            cct_k=COALESCE(cct_k,IF(cct_min_k IS NOT NULL AND cct_min_k=cct_max_k,cct_min_k,NULL))
        WHERE min_power_w IS NULL
           OR current_max_ma IS NULL
           OR cct_k IS NULL",
    "UPDATE mc_field_registry
        SET status='disabled'
        WHERE field_code IN('chip.rated_power_w','chip.current_ma','chip.cct_min_k','chip.cct_max_k')",
];

foreach ($fields as [$code, $label, $type, $unit, $target, $sort]) {
    $validation = in_array($type, ['decimal', 'integer'], true)
        ? '{"min":0}'
        : (in_array($type, ['text', 'textarea'], true) ? '{"maxLength":2000}' : null);
    $up[] = "INSERT INTO mc_field_registry(field_code,storage_target,field_name,data_type,unit,is_required,validation_json,default_json,allow_batch,is_sensitive,customer_visible,allow_import,use_for_duplicate,use_for_adaptation,status,sort_order)
        VALUES(" . $quote($code) . ',' . $quote($target) . ',' . $quote($label) . ',' . $quote($type) . ',' . $quote($unit) . ",0," . $quote($validation) . ",NULL,1,0,1,1,1,1,'active'," . (int) $sort . ")
        ON DUPLICATE KEY UPDATE storage_target=VALUES(storage_target),field_name=VALUES(field_name),data_type=VALUES(data_type),unit=VALUES(unit),validation_json=VALUES(validation_json),status='active',sort_order=VALUES(sort_order)";
    $up[] = "INSERT INTO mc_category_field_map(category_code,field_id,is_required,sort_order)
        SELECT 'chip',id,0," . (int) $sort . " FROM mc_field_registry WHERE field_code=" . $quote($code) . "
        ON DUPLICATE KEY UPDATE is_required=VALUES(is_required),sort_order=VALUES(sort_order)";
}

return [
    'version' => '20260819_024_chip_power_current_ranges',
    'description' => 'Replace chip rated power, single current and CCT range fields with power/current ranges and single CCT',
    'up' => $up,
    'down' => [
        "UPDATE mc_field_registry SET status='active' WHERE field_code IN('chip.rated_power_w','chip.current_ma','chip.cct_min_k','chip.cct_max_k')",
        "DELETE m FROM mc_category_field_map m JOIN mc_field_registry f ON f.id=m.field_id WHERE f.field_code IN('chip.min_power_w','chip.current_min_ma','chip.current_max_ma','chip.cct_k')",
        "DELETE FROM mc_field_registry WHERE field_code IN('chip.min_power_w','chip.current_min_ma','chip.current_max_ma','chip.cct_k')",
        "ALTER TABLE mc_material_chip DROP COLUMN cct_k, DROP COLUMN current_max_ma, DROP COLUMN current_min_ma, DROP COLUMN min_power_w",
    ],
];
