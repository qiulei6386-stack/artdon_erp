<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
$required=['mc_schema_migrations','mc_material_categories','mc_materials','mc_material_attributes','mc_material_attribute_values','mc_suppliers','mc_supplier_materials','mc_supplier_prices','mc_material_alternatives','mc_material_documents','mc_material_versions','mc_legacy_links','mc_activity_logs','mc_power_supply_specs','mc_power_bands','mc_power_supply_dimming_modes','mc_power_supply_current_options','mc_material_import_staging','mc_material_parse_results','mc_duplicate_candidates'];
foreach($required as$table){if(!mc_table_exists($table)){fwrite(STDERR,"missing {$table}\n");exit(1);}}
$bands=(int)db()->query('SELECT COUNT(*) FROM mc_power_bands')->fetchColumn();if($bands<8){fwrite(STDERR,"power bands missing\n");exit(1);}
$legacyBefore=(int)db()->query('SELECT COUNT(*) FROM bom_materials')->fetchColumn();
(new Artdon\MaterialCenter\Services\PowerStandardizationService())->stagePilot();
$legacyAfter=(int)db()->query('SELECT COUNT(*) FROM bom_materials')->fetchColumn();
if($legacyBefore!==$legacyAfter){fwrite(STDERR,"legacy row count changed\n");exit(1);}
echo "MM schema and legacy read-only count test passed. staging=".(int)db()->query('SELECT COUNT(*) FROM mc_material_import_staging WHERE is_pilot=1')->fetchColumn()."\n";
