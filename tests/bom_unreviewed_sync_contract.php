<?php
require dirname(__DIR__).'/includes/bom_unreviewed_sync.php';
function buscheck($ok,$why){if(!$ok)throw new RuntimeException($why);}
$p=['project_uid'=>'TEST','name'=>'Synthetic','customer'=>'','model'=>'52.12345','linked_system'=>'NAMING','linked_id'=>'1','currency'=>'RMB','labor'=>0,'other'=>0,'row_bytes'=>30,'rows_json'=>'[{"qty":2,"price":10}]','review_status'=>'draft'];
$plan=bus_plan_rows([$p]);buscheck(count($plan['entries'])===1&&$plan['entries'][0]['cost']===20.0,'Draft can freeze valid cost');
$next=$p;$next['rows_json']='[{"qty":2,"price":11}]';buscheck(bus_plan_rows([$next])['hash']!==$plan['hash'],'Plan changes on price edit');
foreach(['currency'=>'USD','rows_json'=>'[]','row_bytes'=>3000000] as $key=>$v){$bad=$p;$bad[$key]=$v;buscheck(count(bus_plan_rows([$bad])['skipped'])===1,'Unsafe row skipped '.$key);}
foreach(['oops',-1,'1e999'] as $v){$bad=$p;$bad['rows_json']=bw_json([['qty'=>1,'unit_price'=>$v]]);buscheck(count(bus_plan_rows([$bad])['skipped'])===1,'Invalid raw alias refused');}
$bad=$p;$bad['rows_json']='[{"qty":1,"price":0}]';buscheck(count(bus_plan_rows([$bad])['skipped'])===1,'Zero must be reviewed');
$newMap=[];bcp_add_publication($newMap,['source'=>'legacy_unreviewed','snapshot_id'=>null,'cost'=>20,'updated_at'=>'','payload_json'=>bw_json(['model'=>'52.12345','unreviewed_sync'=>true,'initial_freeze'=>true])]);buscheck(bcp_find(['52.12345'],$newMap)[1]['source_table']==='BOM未审核（冻结）','New cost explicitly unreviewed');
$src=file_get_contents(dirname(__DIR__).'/includes/bom_unreviewed_sync.php');
buscheck(strpos($src,'c.project_uid IS NULL')!==false,'Existing publication never overwritten');
buscheck(strpos($src,"b.review_status IN ('draft','pending')")!==false,'Approved projects excluded');
buscheck(strpos($src,'ON DUPLICATE KEY')===false,'No destructive publication upsert');
buscheck(strpos($src,'UPDATE bom_projects')===false&&strpos($src,'quote_orders')===false&&strpos($src,'INSERT INTO bom_snapshots')===false,'No approval/quote/snapshot mutations');
$map=[];bcp_add_publication($map,['source'=>'approved_snapshot','snapshot_id'=>1,'cost'=>5,'updated_at'=>'','payload_json'=>bw_json(['model'=>'52.12345'])]);bcp_add_publication($map,['source'=>'legacy_unreviewed','snapshot_id'=>null,'cost'=>99,'updated_at'=>'','payload_json'=>bw_json(['model'=>'52.12345','initial_freeze'=>true])]);buscheck(bcp_find(['52.12345'],$map)[1]['cost_rmb']===5.0,'Unreviewed freeze cannot override reviewed cost');
echo "BOM additive unreviewed sync plans, validation, publication guards and reviewed cost precedence passed\n";
