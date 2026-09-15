<?php
// Explicit, additive release migration; never reachable via HTTP.
if(PHP_SAPI!=='cli'||($argv[1]??'')!=='--install-order-page-v1')exit(2);
$root=realpath($argv[2]??'');
if($root!=='/www/wwwroot/Artdon/artdon_erp')throw new RuntimeException('Unexpected application root');
require dirname(__DIR__).'/includes/quote_order_paging.php';
require $root.'/includes/db.php';
$pdo=db();
op_install($pdo);
$start=microtime(true);$page=op_page($pdo,['overview'=>true]);
echo json_encode(['installed'=>true,'total'=>$page['total'],'rows'=>count($page['orders']),'bytes'=>strlen(json_encode($page)),'ms'=>round((microtime(true)-$start)*1000),'peak'=>memory_get_peak_usage(true)],JSON_UNESCAPED_UNICODE)."\n";
