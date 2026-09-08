<?php
if (PHP_SAPI!=='cli' || !in_array('--install',$argv,true)) {http_response_code(403);exit('CLI --install required');}
require_once dirname(__DIR__).'/includes/db.php';
require_once dirname(__DIR__).'/includes/dispatch_daily.php';
echo json_encode(dd_install(db()),JSON_UNESCAPED_UNICODE),PHP_EOL;
