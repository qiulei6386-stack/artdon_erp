<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
require_once dirname(__DIR__).'/includes/bom_unreviewed_sync.php';
require_once dirname(__DIR__).'/includes/db.php';
if(in_array('--plan',$argv,true)){
    $pdo=db();$pdo->exec('START TRANSACTION READ ONLY');try{echo bw_json(bus_plan($pdo)),"\n";}finally{$pdo->rollBack();}
}elseif(in_array('--apply',$argv,true)){
    $hash='';$actor='';foreach($argv as $a){if(strpos($a,'--expected=')===0)$hash=substr($a,11);if(strpos($a,'--actor=')===0)$actor=substr($a,8);}
    echo bw_json(bus_apply(db(),$hash,$actor)),"\n";
}else{fwrite(STDERR,"Explicit --plan or --apply --expected=<plan hash> --actor=<operator> required\n");exit(2);}
