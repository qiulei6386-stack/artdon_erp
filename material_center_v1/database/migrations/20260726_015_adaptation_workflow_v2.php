<?php
declare(strict_types=1);

return [
    'version' => '20260726_015_adaptation_workflow_v2',
    'description' => 'Add typed groups, completion constraints, candidate decisions and visual conditions to product adaptation',
    'up' => [
        "ALTER TABLE mc_adaptation_groups
            ADD COLUMN business_type VARCHAR(40) NOT NULL DEFAULT 'custom' AFTER group_type,
            ADD COLUMN material_category_code VARCHAR(40) NULL AFTER business_type,
            ADD COLUMN is_required TINYINT(1) NOT NULL DEFAULT 0 AFTER material_category_code,
            ADD COLUMN selection_mode VARCHAR(20) NOT NULL DEFAULT 'single' AFTER is_required,
            ADD COLUMN min_select INT NOT NULL DEFAULT 0 AFTER selection_mode,
            ADD COLUMN max_select INT NOT NULL DEFAULT 1 AFTER min_select,
            ADD COLUMN template_key VARCHAR(80) NULL AFTER max_select,
            ADD COLUMN is_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
            ADD COLUMN updated_by BIGINT UNSIGNED NULL AFTER created_by",
        "UPDATE mc_adaptation_groups SET
            business_type=CASE group_type
                WHEN 'power' THEN 'power'
                WHEN 'chip' THEN 'chip'
                WHEN 'optical' THEN 'optical'
                WHEN 'connector' THEN 'installation'
                WHEN 'accessory' THEN 'accessory'
                WHEN 'packaging' THEN 'accessory'
                ELSE 'custom' END,
            material_category_code=CASE group_type
                WHEN 'power' THEN 'power_supply'
                WHEN 'chip' THEN 'chip'
                WHEN 'optical' THEN 'optical'
                WHEN 'connector' THEN 'connector'
                WHEN 'accessory' THEN 'accessory'
                WHEN 'packaging' THEN 'packaging'
                ELSE NULL END,
            is_enabled=IF(status='approved',1,0)",
        "UPDATE mc_adaptation_groups g
            LEFT JOIN mc_adaptation_options o ON o.group_id=g.id
            LEFT JOIN mc_adaptation_approvals a ON a.product_id=g.product_id
            LEFT JOIN mc_adaptation_groups gx ON gx.product_id=g.product_id AND gx.group_code='power_driver' AND gx.id<>g.id
            SET
            g.group_code='power_driver',
            g.group_name='电源 / 驱动',
            g.group_type='power',
            g.business_type='power',
            g.material_category_code='power_supply',
            g.is_required=1,
            g.selection_mode='single',
            g.min_select=1,
            g.max_select=1,
            g.template_key='power_driver',
            g.sort_order=20,
            g.updated_at=NOW()
            WHERE g.group_type='power' AND g.group_name REGEXP '^[0-9]+$'
            AND o.id IS NULL AND a.id IS NULL AND gx.id IS NULL",
        "ALTER TABLE mc_adaptation_options
            ADD COLUMN match_level VARCHAR(30) NOT NULL DEFAULT 'needs_approval' AFTER material_id,
            ADD COLUMN match_reason_json JSON NULL AFTER match_level,
            ADD COLUMN requires_approval TINYINT(1) NOT NULL DEFAULT 0 AFTER match_reason_json,
            ADD COLUMN exception_approved TINYINT(1) NOT NULL DEFAULT 0 AFTER requires_approval",
        "ALTER TABLE mc_adaptation_conditions
            ADD COLUMN condition_group_no INT NOT NULL DEFAULT 1 AFTER option_id,
            ADD COLUMN boolean_connector VARCHAR(10) NOT NULL DEFAULT 'AND' AFTER condition_group_no",
        "CREATE INDEX idx_mc_adaptation_group_template ON mc_adaptation_groups(product_id,template_key)",
        "CREATE INDEX idx_mc_adaptation_option_approval ON mc_adaptation_options(group_id,requires_approval,exception_approved)",
    ],
    'down' => [
        "DROP INDEX idx_mc_adaptation_option_approval ON mc_adaptation_options",
        "DROP INDEX idx_mc_adaptation_group_template ON mc_adaptation_groups",
        "ALTER TABLE mc_adaptation_conditions
            DROP COLUMN boolean_connector,
            DROP COLUMN condition_group_no",
        "ALTER TABLE mc_adaptation_options
            DROP COLUMN exception_approved,
            DROP COLUMN requires_approval,
            DROP COLUMN match_reason_json,
            DROP COLUMN match_level",
        "ALTER TABLE mc_adaptation_groups
            DROP COLUMN updated_by,
            DROP COLUMN is_enabled,
            DROP COLUMN template_key,
            DROP COLUMN max_select,
            DROP COLUMN min_select,
            DROP COLUMN selection_mode,
            DROP COLUMN is_required,
            DROP COLUMN material_category_code,
            DROP COLUMN business_type",
    ],
];
