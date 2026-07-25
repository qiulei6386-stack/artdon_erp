<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap.php';
$db=db();
$legacyBefore=(int)$db->query('SELECT COUNT(*) FROM bom_materials')->fetchColumn();
$legacyColumnsBefore=$db->query('SHOW COLUMNS FROM bom_materials')->fetchAll(PDO::FETCH_COLUMN);
$service=new Artdon\MaterialCenter\Services\PowerWorkbenchService();
$source=$service->source();$service->staging('organize');$service->staging('confirm');$service->staging('duplicates');$service->formal('formal');$service->formal('archived');$service->formal('all');
$legacyAfter=(int)$db->query('SELECT COUNT(*) FROM bom_materials')->fetchColumn();
$legacyColumnsAfter=$db->query('SHOW COLUMNS FROM bom_materials')->fetchAll(PDO::FETCH_COLUMN);
if(!$source){fwrite(STDERR,"source data blank\n");exit(1);}
if($legacyBefore!==$legacyAfter||$legacyColumnsBefore!==$legacyColumnsAfter){fwrite(STDERR,"legacy BOM changed\n");exit(1);}
echo "Power UI regression test passed; source=".count($source)."; legacy={$legacyAfter}; legacy changes=0.\n";
