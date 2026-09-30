<?php
// Included by the socket-only BOM workflow suite, never application configuration.
require_once dirname(__DIR__).'/includes/bom_unreviewed_sync.php';
$seed=$d;$seed['project_uid']='SYNC-NEW';$seed['model']='52.87654';$seed['expected_revision']='';$seed['request_id']=bin2hex(random_bytes(16));$seed['rows']=[['qty'=>2,'price'=>10,'name'=>'Synthetic initial','priceStatus'=>'confirmed']];
$pdo->exec("INSERT INTO quote_price_policies VALUES(2,'naming','','52.87654',0,0,0,'','','','',NOW())");
$saved=bw_execute($pdo,'save_project',$seed,'Tester',true);
wfCheck(strpos($saved['message'],'未审核冻结')!==false,'First-save feedback');
$pub=$pdo->query("SELECT * FROM bom_cost_publications WHERE project_uid='SYNC-NEW'")->fetch();
wfCheck((float)$pub['cost']===20.0&&$pub['source']==='legacy_unreviewed'&&$pub['snapshot_id']===null,'First save freezes unreviewed not approval');
wfCheck((float)$pdo->query('SELECT bom_cost_rmb FROM quote_price_policies WHERE id=2')->fetchColumn()===20.0,'First save policy matches quote cost');
wfCheck(bw_execute($pdo,'save_project',$seed,'Tester',true)===$saved,'First save retry has one publication');
$seed['expected_revision']=bw_revision(bw_get($pdo,'SYNC-NEW'));$seed['request_id']=bin2hex(random_bytes(16));$seed['rows'][0]['price']=15;
bw_execute($pdo,'save_project',$seed,'Tester',true);
wfCheck($pub===$pdo->query("SELECT * FROM bom_cost_publications WHERE project_uid='SYNC-NEW'")->fetch(),'Draft edit cannot update freeze');
wfCheck((float)$pdo->query('SELECT bom_cost_rmb FROM quote_price_policies WHERE id=2')->fetchColumn()===20.0,'Draft cannot update policy');
bw_execute($pdo,'submit_review',requestData($pdo,'SYNC-NEW'),'Tester',true);bw_execute($pdo,'approve_project',requestData($pdo,'SYNC-NEW'),'Reviewer',true);
wfCheck((float)$pdo->query("SELECT cost FROM bom_cost_publications WHERE project_uid='SYNC-NEW'")->fetchColumn()===30.0,'Approval updates cost');
// Existing missing draft and pending costs can be repaired without saving their documents.
foreach(['SYNC-OLD'=>'draft','SYNC-PENDING'=>'pending','SYNC-APPROVED'=>'approved','SYNC-ZERO'=>'draft'] as $uid=>$status){$pdo->prepare('INSERT INTO bom_projects(project_uid,name,model,rows_json,review_status) VALUES(?,?,?,?,?)')->execute([$uid,'Synthetic repair','52.87653',$uid==='SYNC-ZERO'?'[{"qty":1,"price":0}]':'[{"qty":1,"price":7}]',$status]);}
$projects=$pdo->query('SELECT project_uid,workflow_version,review_status,rows_json,updated_at FROM bom_projects ORDER BY id')->fetchAll();$pubs=$pdo->query('SELECT * FROM bom_cost_publications ORDER BY project_uid')->fetchAll();$snaps=$pdo->query('SELECT id,project_uid,totals_json FROM bom_snapshots ORDER BY id')->fetchAll();
$plan=bus_plan($pdo);wfCheck(count($plan['entries'])===2&&count($plan['skipped'])===1,'Only two missing unreviewed valid costs');
$pdo->exec("UPDATE bom_projects SET labor=1 WHERE project_uid='SYNC-OLD'");
try{bus_apply($pdo,$plan['hash'],'Tester');throw new LogicException('Changed document accepted');}catch(RuntimeException $e){wfCheck(strpos($e->getMessage(),'重新扫描')!==false,'Real edit invalidates scan');}
$pdo->exec("UPDATE bom_projects SET labor=0 WHERE project_uid='SYNC-OLD'");
try{bus_apply($pdo,str_repeat('0',64),'Tester');throw new LogicException('Stale plan accepted');}catch(RuntimeException $e){wfCheck(strpos($e->getMessage(),'重新扫描')!==false,'Stale plan denied');}
wfCheck($pubs===$pdo->query('SELECT * FROM bom_cost_publications ORDER BY project_uid')->fetchAll(),'Stale plan no changes');
$pdo->exec("INSERT INTO quote_price_policies VALUES(3,'naming','','52.87653',0,0,0,'','','','',NOW())");
$pdo->exec("CREATE TRIGGER fail_sync BEFORE UPDATE ON quote_price_policies FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic cache failure'");
$events=(int)$pdo->query('SELECT COUNT(*) FROM bom_workflow_events')->fetchColumn();
try{bus_apply($pdo,$plan['hash'],'Tester');throw new LogicException('Cache failure accepted');}catch(PDOException $e){}
wfCheck($pubs===$pdo->query('SELECT * FROM bom_cost_publications ORDER BY project_uid')->fetchAll()&&$events===(int)$pdo->query('SELECT COUNT(*) FROM bom_workflow_events')->fetchColumn(),'Policy failure rolls back publications and audit');
$pdo->exec('DROP TRIGGER fail_sync');
$result=bus_apply($pdo,$plan['hash'],'Tester');wfCheck($result['inserted']===2,'Additive repair');
wfCheck($projects===$pdo->query('SELECT project_uid,workflow_version,review_status,rows_json,updated_at FROM bom_projects ORDER BY id')->fetchAll(),'Repair never modifies BOM or review status');
wfCheck($snaps===$pdo->query('SELECT id,project_uid,totals_json FROM bom_snapshots ORDER BY id')->fetchAll(),'Repair never creates or edits snapshots');
foreach($pubs as $old){$st=$pdo->prepare('SELECT * FROM bom_cost_publications WHERE project_uid=?');$st->execute([$old['project_uid']]);wfCheck($old===$st->fetch(),'Existing publication preserved');}
wfCheck(bus_apply($pdo,bus_plan($pdo)['hash'],'Tester')['inserted']===0,'Second plan no duplicate inserts');
$bad=$seed;$bad['project_uid']='SYNC-EMPTY';$bad['expected_revision']='';$bad['request_id']=bin2hex(random_bytes(16));$bad['rows']=[];$bad['labor']=0;$bad['other']=0;
$empty=bw_execute($pdo,'save_project',$bad,'Tester',true);wfCheck(strpos($empty['message'],'未同步')!==false,'Incomplete draft saved with explicit reason');
$bad['expected_revision']=bw_revision(bw_get($pdo,'SYNC-EMPTY'));$bad['request_id']=bin2hex(random_bytes(16));$bad['rows']=$seed['rows'];bw_execute($pdo,'save_project',$bad,'Tester',true);
wfCheck((float)$pdo->query("SELECT cost FROM bom_cost_publications WHERE project_uid='SYNC-EMPTY'")->fetchColumn()===30.0,'First valid save publishes after incomplete draft');
$race=$seed;$race['project_uid']='SYNC-RACE';$race['expected_revision']='';$race['model']='52.87652';$workers=[];
foreach([1,2] as $n){$race['request_id']=bin2hex(random_bytes(16));$pipes=[];$proc=proc_open([PHP_BINARY,'-n',dirname(__FILE__).'/bom_workflow_mysql.php','worker',base64_encode(bw_json($race)),'save_project'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$workers[]=[$proc,$pipes];}
$won=0;$conflicts=0;foreach($workers as [$proc,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);wfCheck(proc_close($proc)===0,'save worker '.$err);$r=json_decode($out,true);if($r['ok']??false)$won++;elseif(($r['code']??'')==='revision_conflict')$conflicts++;}
wfCheck($won===1&&$conflicts===1&&(int)$pdo->query("SELECT COUNT(*) FROM bom_cost_publications WHERE project_uid='SYNC-RACE'")->fetchColumn()===1,'Concurrent first save one project and one freeze');
echo "BOM unreviewed sync MySQL: first save/retry/concurrency, draft freeze, approval update, real edit/stale-plan/cache-failure rollback, additive pending/draft repair and immutable existing documents passed\n";
