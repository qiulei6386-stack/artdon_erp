<?php
declare(strict_types=1);
// Deliberate CLI-only, additive migration. No automatic approval or historical document rewrite.
if(PHP_SAPI!=='cli'||!in_array('--freeze-legacy-costs',$argv??array(),true)){fwrite(STDERR,"Explicit CLI --freeze-legacy-costs required\n");exit(2);}
require_once dirname(__DIR__).'/includes/db.php';
require_once dirname(__DIR__).'/includes/bom_workflow.php';
$expected=null;foreach($argv as $arg)if(strpos($arg,'--expected-cost-hash=')===0)$expected=substr($arg,21);
if($expected!==null&&!preg_match('/^[a-f0-9]{64}$/D',$expected))throw new RuntimeException('Invalid expected cost hash');
echo bw_json(bw_freeze_legacy(db(),$expected)),"\n";
