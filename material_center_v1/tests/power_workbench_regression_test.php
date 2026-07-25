<?php
declare(strict_types=1);require_once dirname(__DIR__).'/bootstrap.php';
$before=(int)db()->query('SELECT COUNT(*) FROM bom_materials')->fetchColumn();(new Artdon\MaterialCenter\Services\PowerWorkbenchService())->source();$after=(int)db()->query('SELECT COUNT(*) FROM bom_materials')->fetchColumn();
if($before!==$after){fwrite(STDERR,"legacy BOM changed\n");exit(1);}foreach(['mc_materials','mc_material_import_staging','mc_legacy_links']as$table)if(!mc_table_exists($table)){fwrite(STDERR,"missing {$table}\n");exit(1);}
echo "Power workbench regression test passed; legacy={$after}.\n";
