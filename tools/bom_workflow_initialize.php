<?php
declare(strict_types=1);
// Deliberate CLI-only, additive migration. No automatic approval or historical document rewrite.
if(PHP_SAPI!=='cli'||!in_array('--freeze-legacy-costs',$argv??array(),true)){fwrite(STDERR,"Explicit CLI --freeze-legacy-costs required\n");exit(2);}
require_once dirname(__DIR__).'/includes/db.php';
require_once dirname(__DIR__).'/includes/bom_workflow.php';
echo bw_json(bw_freeze_legacy(db())),"\n";
