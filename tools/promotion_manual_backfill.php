<?php
// Defaults to dry-run. Uses only a confirmed manifest, with no mail worker invocation.
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
$options=getopt('',['task:','apply']);
$id=filter_var($options['task'] ?? 0,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if (!$id) { fwrite(STDERR,"Usage: php tools/promotion_manual_backfill.php --task=ID [--apply]\n"); exit(2); }
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/crm_config.php';
require_once dirname(__DIR__).'/crm_auth.php';
require_once dirname(__DIR__).'/crm_log.php';
require_once dirname(__DIR__).'/crm_task_center.php';
require_once dirname(__DIR__).'/crm_marketing.php';
db()->exec('SET SESSION innodb_lock_wait_timeout=5');
db()->exec('SET SESSION max_execution_time=8000');
echo json_encode(crm_promotion_repair_manual_tasks((int)$id,array_key_exists('apply',$options)),JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
