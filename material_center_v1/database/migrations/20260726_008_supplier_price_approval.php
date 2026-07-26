<?php
declare(strict_types=1);
return[
 'version'=>'20260726_008_supplier_price_approval',
 'description'=>'Supplier preferred source, price approval and change review state',
 'up'=>[
  "ALTER TABLE mc_supplier_materials ADD COLUMN is_preferred TINYINT(1) NOT NULL DEFAULT 0 AFTER status",
  "ALTER TABLE mc_supplier_prices ADD COLUMN approval_status VARCHAR(20) NOT NULL DEFAULT 'approved' AFTER valid_to, ADD COLUMN approval_id BIGINT UNSIGNED NULL AFTER approval_status, ADD INDEX idx_mc_supplier_price_approval(approval_status,created_at)",
 ],
 'down'=>[
  "ALTER TABLE mc_supplier_prices DROP INDEX idx_mc_supplier_price_approval, DROP COLUMN approval_id, DROP COLUMN approval_status",
  "ALTER TABLE mc_supplier_materials DROP COLUMN is_preferred",
 ],
];
