<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bom_workflow.php';
function check($condition,$message){if(!$condition)throw new RuntimeException($message);}
$p=array('name'=>'Synthetic','rows'=>array(array('name'=>'Synthetic material','quantity'=>2,'unit_price'=>12,'finish_cost2'=>3,'priceStatus'=>'confirmed')),'labor'=>1,'other'=>2,'profit_rate'=>0,'quote_mode'=>'markup','exchange_rate'=>1,'currency'=>'RMB');
$r=bw_validate($p,true);check($r[0]['qty']===2.0,'legacy quantity');check(bw_totals($p)['total']===33.0,'two finish costs');check(bw_totals($p)['suggest']===33.0,'zero profit');
$p['rows'][0]['unit_price']=0;check(bw_validate($p)[0]['price']===0.0,'zero price preserved');
$p['rows'][0]['quantity']=0;try{bw_validate($p,true);throw new RuntimeException('zero qty submitted');}catch(BomWorkflowError $e){check($e->reason==='validation','zero qty error');}
foreach(array('oops','NaN','1e999',-1,array(1)) as $bad){$invalid=$p;$invalid['rows'][0]['unit_price']=$bad;try{bw_validate($invalid);throw new RuntimeException('invalid price accepted');}catch(BomWorkflowError $e){check($e->reason==='validation','invalid price refused');}}
$invalid=$p;$invalid['labor']=1e12;try{bw_validate($invalid);throw new RuntimeException('overflow accepted');}catch(BomWorkflowError $e){check($e->reason==='validation','storage overflow refused');}
$a=array('project_uid'=>'A','rows_json'=>'[]','workflow_version'=>1);$b=$a;$b['workflow_version']=2;check(bw_revision($a)!==bw_revision($b),'monotonic revision');
$frozen=array('review_status'=>'approved','latest_snapshot_id'=>7,'rows_json'=>'[{"qty":1,"price":20}]');$snapshot=array('id'=>7,'rows_json'=>'[{"qty":1,"price":10}]');check(!bw_initial_snapshot_matches($frozen,$snapshot),'different old snapshot must not replace current cost');$snapshot['rows_json']=$frozen['rows_json'];check(bw_initial_snapshot_matches($frozen,$snapshot),'matching approved snapshot recognized');
$api=file_get_contents(dirname(__DIR__).'/bom_api.php');check(strpos($api,"(int)(\$p['workflow_version']??0)===0")!==false,'explicit empty saved rows cannot recover legacy rows');
check(strpos($api,"\$rows=bw_rows(\$s['rows_json'] ?? '[]')")!==false,'legacy snapshot aliases normalized for display without rewriting stored snapshot');
$src=file_get_contents(dirname(__DIR__).'/includes/bom_workflow.php');check(strpos($src,'FOR UPDATE')!==false,'locking');check(strpos($src,'bom_workflow_requests')!==false,'idempotency');
$quote=file_get_contents(dirname(__DIR__).'/quote_api.php');$start=strpos($quote,'function bom_add_precise_projects_to_cost_map(');$end=strpos($quote,'function bom_debug_report(',$start);$part=substr($quote,$start,$end-$start);check(strpos($part,'bom_cost_publications')!==false&&strpos($part,'SELECT * FROM `bom_projects`')===false,'published costs only');
echo "BOM workflow canonical money/revision and publication contract: OK\n";
