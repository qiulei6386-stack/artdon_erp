<?php
// Included only by the guarded socket-only BOM workflow test, after its version fixtures.
if(!isset($v1,$v2,$vp,$pdo)||getenv('CRM_PHASE1_MYSQL_TEST')!=='1')throw new RuntimeException('Sandbox fixture required');
function voidDenied(callable $fn,string $why){try{$fn();}catch(RuntimeException $e){return;}throw new LogicException('Expected rejection: '.$why);}
$sid1=$v1['version']['snapshot_id'];$sid2=$v2['version']['snapshot_id'];
$sealed=$pdo->query("SELECT * FROM bom_snapshots WHERE project_uid='QBV' ORDER BY id")->fetchAll();
$voidData=requestData($pdo,'QBV',array('snapshot_id'=>$sid2,'review_note'=>'Wrong material; use checked previous version','replacement_snapshot_id'=>$sid1));
voidDenied(fn()=>bw_execute($pdo,'void_snapshot',array_merge($voidData,array('review_note'=>'')),'Reviewer',true),'reason');
voidDenied(fn()=>bw_execute($pdo,'void_snapshot',$voidData,'Reviewer',false),'hidden cost permission');
voidDenied(fn()=>bw_execute($pdo,'void_snapshot',array_merge($voidData,array('snapshot_id'=>999999)),'Reviewer',true),'wrong project');
voidDenied(fn()=>bw_execute($pdo,'void_snapshot',array_merge($voidData,array('replacement_snapshot_id'=>0)),'Reviewer',true),'no implicit fallback');
$voidResult=bw_execute($pdo,'void_snapshot',$voidData,'Reviewer',true);
wfCheck(bw_execute($pdo,'void_snapshot',$voidData,'Reviewer',true)===$voidResult,'void idempotency');
wfCheck($pdo->query("SELECT snapshot_id FROM bom_cost_publications WHERE project_uid='QBV'")->fetchColumn()==$sid1,'explicit replacement published');
wfCheck(bw_snapshot_voids($pdo,'QBV')[$sid2]['void_reason']===$voidData['review_note'],'append-only reason/operator/time');
wfCheck($sealed===$pdo->query("SELECT * FROM bom_snapshots WHERE project_uid='QBV' ORDER BY id")->fetchAll(),'no snapshot body mutation');
voidDenied(fn()=>qbv_resolve($pdo,$vp,$sid2),'revoked cannot be selected');
wfCheck(qbv_resolve($pdo,$vp,$sid2,null,true)['version']['voided'],'revoked readable');
$cat=qbv_catalog($pdo,$vp);wfCheck(count(array_filter($cat['versions'],fn($v)=>$v['snapshot_id']===$sid2&&$v['voided']))===1,'catalog shows revocation');
$existing=array('items_json'=>bw_json(array(array('product'=>array_merge($vp,$v2['patch'])))));
$pdo->exec('CREATE TABLE quote_orders(id INT PRIMARY KEY,items_json LONGTEXT) ENGINE=InnoDB');
$pdo->prepare('INSERT INTO quote_orders VALUES(1,?)')->execute(array($existing['items_json']));
qbv_validate_save($pdo,$existing,array('id'=>1));
voidDenied(fn()=>qbv_validate_save($pdo,$existing),'copy into new quote blocked');
$double=array('items_json'=>bw_json(array(array('product'=>array_merge($vp,$v2['patch'])),array('product'=>array_merge($vp,$v2['patch'])))));
voidDenied(fn()=>qbv_validate_save($pdo,$double,array('id'=>1)),'cannot duplicate revoked row');
voidDenied(fn()=>bw_execute($pdo,'void_snapshot',requestData($pdo,'QBV',array('snapshot_id'=>$sid1,'replacement_snapshot_id'=>$sid2,'review_note'=>'Invalid replacement')),'Reviewer',true),'revoked replacement');
$pause=requestData($pdo,'QBV',array('snapshot_id'=>$sid1,'publication_mode'=>'pause','review_note'=>'Stop new quoting pending correction'));
$GLOBALS['failPolicy']=true;voidDenied(fn()=>bw_execute($pdo,'void_snapshot',$pause,'Reviewer',true),'rollback on cache failure');$GLOBALS['failPolicy']=false;
wfCheck(!isset(bw_snapshot_voids($pdo,'QBV')[$sid1]),'failed void rolled back event');
wfCheck($pdo->query("SELECT source FROM bom_cost_publications WHERE project_uid='QBV'")->fetchColumn()==='approved_snapshot','failed void kept publication');
bw_execute($pdo,'void_snapshot',$pause,'Reviewer',true);
wfCheck($pdo->query("SELECT source FROM bom_cost_publications WHERE project_uid='QBV'")->fetchColumn()==='voided','pause explicit');
$testMap=array();bcp_add_publication($testMap,$pdo->query("SELECT * FROM bom_cost_publications WHERE project_uid='QBV'")->fetch());wfCheck(!$testMap,'paused not legacy');
wfCheck(qbv_resolve($pdo,$vp)['choose'],'paused match never silently chooses another BOM');
$legacy=array('items_json'=>bw_json(array(array('product'=>$vp,'qty'=>1))));
voidDenied(fn()=>qbv_validate_save($pdo,$legacy),'legacy bypass on new quote');
$pdo->prepare('INSERT INTO quote_orders VALUES(2,?)')->execute(array($legacy['items_json']));qbv_validate_save($pdo,$legacy,array('id'=>2));
bw_execute($pdo,'unapprove_project',requestData($pdo,'QBV',array('review_note'=>'Correct and reapprove')),'Reviewer',true);
bw_execute($pdo,'submit_review',requestData($pdo,'QBV'),'Tester',true);bw_execute($pdo,'approve_project',requestData($pdo,'QBV'),'Reviewer',true);
$newSid=(int)$pdo->query("SELECT snapshot_id FROM bom_cost_publications WHERE project_uid='QBV'")->fetchColumn();
wfCheck($newSid!==$sid2&&!qbv_resolve($pdo,$vp,$newSid)['version']['voided'],'new approval restores availability without reviving old snapshots');
$race=requestData($pdo,'QBV',array('snapshot_id'=>$newSid,'publication_mode'=>'pause','review_note'=>'Concurrent synthetic void'));$workers=array();
foreach(array(1,2) as $n){$payload=$race;$payload['request_id']=bin2hex(random_bytes(16));$pipes=array();$proc=proc_open(array(PHP_BINARY,'-n',dirname(__FILE__).'/bom_workflow_mysql.php','worker',base64_encode(bw_json($payload)),'void_snapshot'),array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes);fclose($pipes[0]);$workers[]=array($proc,$pipes);}
$won=0;$conflicts=0;foreach($workers as [$proc,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);wfCheck(proc_close($proc)===0,'void worker '.$err);$r=json_decode($out,true);if($r['ok']??false)$won++;elseif(($r['code']??'')==='revision_conflict')$conflicts++;}wfCheck($won===1&&$conflicts===1,'concurrent void one winner');
echo "Snapshot void MySQL: reason, ownership, permission, replacement/pause, immutable history, idempotency, rollback, old-reference preservation, new/copy rejection, reapproval and concurrent void passed\n";
