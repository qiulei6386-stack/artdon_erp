<?php
declare(strict_types=1);require_once dirname(__DIR__).'/bootstrap.php';
$service=new Artdon\MaterialCenter\Services\PowerWorkbenchService();$source=$service->source();
if(!$source){fwrite(STDERR,"source unexpectedly empty\n");exit(1);}foreach(['source_system','source_table','source_id','name','spec','hash','mapping_status']as$key)if(!array_key_exists($key,$source[0])){fwrite(STDERR,"source field missing {$key}\n");exit(1);}
foreach(['organize','confirm','duplicates']as$tab)$service->staging($tab);foreach(['formal','archived']as$tab)$service->formal($tab);
echo "Power workbench tab test passed. source=".count($source)."\n";
