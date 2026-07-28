<?php
declare(strict_types=1);

return [
    'version' => '20260728_020_adaptation_power_range',
    'description' => 'Replace single product power target with a minimum and maximum power envelope',
    'up' => [
        "ALTER TABLE mc_product_power_rules
            ADD COLUMN lamp_power_min_w DECIMAL(8,2) NULL AFTER lamp_power_w,
            ADD COLUMN lamp_power_max_w DECIMAL(8,2) NULL AFTER lamp_power_min_w",
        "UPDATE mc_product_power_rules
            SET lamp_power_min_w=lamp_power_w,lamp_power_max_w=lamp_power_w
            WHERE lamp_power_w IS NOT NULL AND (lamp_power_min_w IS NULL OR lamp_power_max_w IS NULL)",
    ],
    'down' => [
        "ALTER TABLE mc_product_power_rules DROP COLUMN lamp_power_max_w, DROP COLUMN lamp_power_min_w",
    ],
];
