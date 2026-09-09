<?php
declare(strict_types=1);
// Opt-in only: the disposable socket-only database runner supplies these values.
$socket=(string)getenv('CRM_PHASE1_MYSQL_SOCKET');$schema=(string)getenv('CRM_PHASE1_MYSQL_SCHEMA');
if(PHP_SAPI!=='cli'||getenv('CRM_PHASE1_MYSQL_TEST')!=='1'||!preg_match('#^/tmp/crm-phase1-mysql-20260906-[A-Za-z0-9]{8}/mysql.sock$#D',$socket)||!preg_match('/^bom_workflow_[a-f0-9]{12}$/D',$schema))throw new RuntimeException('Refusing non-sandbox database');
require_once dirname(__DIR__).'/includes/bom_workflow.php';
function connectTest(): PDO {
    global $socket,$schema;
    $pdo=new PDO('mysql:unix_socket='.$socket.';dbname='.$schema.';charset=utf8mb4','root','',array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false));
    $id=$pdo->query('SELECT @@socket socket,@@datadir datadir,@@skip_networking isolated')->fetch();
    if($id['socket']!==$socket||$id['datadir']!==dirname($socket).'/data/'||(int)$id['isolated']!==1)throw new RuntimeException('Instance mismatch');
    return $pdo;
}
function wfCheck($ok,$message){if(!$ok)throw new RuntimeException($message);}
function wfExtract(string $name): void {
    $src=file_get_contents(dirname(__DIR__).'/bom_api.php');preg_match('/^function '.preg_quote($name,'/').'\(/m',$src,$m,PREG_OFFSET_CAPTURE);wfCheck(isset($m[0][1]),'function found');$tail=substr($src,$m[0][1]);preg_match('/^function /m',substr($tail,1),$end,PREG_OFFSET_CAPTURE);eval(substr($tail,0,$end[0][1]+1));
}
foreach(array('bom_project_rows','bom_project_totals_snapshot','bom_price_summary_snapshot','bom_snapshot_uid','bom_insert_snapshot') as $f)wfExtract($f);
function bom_sync_quote_cost_snapshot($pdo,$uid,$actor){if($GLOBALS['failPolicy']??false)throw new RuntimeException('Injected policy failure');return array('updated'=>1);}
if(($argv[1]??'')==='worker'){
    $pdo=connectTest();$d=json_decode(base64_decode($argv[2]),true);
    try{echo bw_json(bw_execute($pdo,'approve_project',$d,'test-reviewer',true));}
    catch(BomWorkflowError $e){echo bw_json(array('ok'=>false,'code'=>$e->reason));}
    exit;
}
$pdo=connectTest();wfCheck((int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()===0,'Fresh schema required');
$src=file_get_contents(dirname(__DIR__).'/bom_api.php');
foreach(array('bom_projects','bom_snapshots') as $table){preg_match('/CREATE TABLE (?:IF NOT EXISTS )?'.preg_quote($table,'/').'\([\s\S]*?\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4/',$src,$m);wfCheck(isset($m[0]),'schema extraction');$pdo->exec($m[0]);}
$legacy=array('qty'=>2,'price'=>10,'name'=>'Synthetic LED','priceStatus'=>'confirmed');
$pdo->prepare('INSERT INTO bom_projects(project_uid,name,model,rows_json,profit_rate) VALUES(?,?,?,?,?)')->execute(array('A','Synthetic A','TEST-A',bw_json(array($legacy)),0));
$baseline=bw_freeze_legacy($pdo);wfCheck($baseline['frozen']===1,'legacy freeze');wfCheck(isset(bw_freeze_legacy($pdo)['already_initialized']),'freeze idempotent');
wfCheck(json_decode($pdo->query('SELECT payload_json FROM bom_cost_publications')->fetchColumn(),true)['initial_freeze']===true,'initial freeze preserves legacy priority');
function requestData(PDO $pdo,string $uid,array $extra=array()): array {return array_merge(array('project_uid'=>$uid,'expected_revision'=>bw_revision(bw_get($pdo,$uid)),'request_id'=>bin2hex(random_bytes(16))),$extra);}
$d=requestData($pdo,'A',array('name'=>'Synthetic A','model'=>'TEST-A','rows'=>array(array_merge($legacy,array('price'=>12))),'labor'=>0,'other'=>0,'profit_rate'=>0,'quote_mode'=>'markup','exchange_rate'=>1,'currency'=>'RMB'));
$old=$d['expected_revision'];$save=bw_execute($pdo,'save_project',$d,'test-author',true);wfCheck($save['revision']!==$old,'revision increments');wfCheck((float)$pdo->query('SELECT cost FROM bom_cost_publications')->fetchColumn()===20.0,'draft cannot change frozen price');
wfCheck(bw_execute($pdo,'save_project',$d,'test-author',true)===$save,'lost response replay');
$stale=$d;$stale['request_id']=bin2hex(random_bytes(16));try{bw_execute($pdo,'save_project',$stale,'test-author',true);throw new LogicException('stale save accepted');}catch(BomWorkflowError $e){wfCheck($e->reason==='revision_conflict','stale save rejected');}
$deny=requestData($pdo,'A',$d);$deny['request_id']=bin2hex(random_bytes(16));$deny['expected_revision']=bw_revision(bw_get($pdo,'A'));try{bw_execute($pdo,'save_project',$deny,'limited',false);throw new LogicException('hidden costs saved');}catch(BomWorkflowError $e){wfCheck($e->reason==='permission','hidden cost guard');}
bw_execute($pdo,'submit_review',requestData($pdo,'A'),'test-author',true);
$locked=requestData($pdo,'A',array_diff_key($d,array_flip(array('expected_revision','request_id'))));try{bw_execute($pdo,'save_project',$locked,'test-author',true);throw new LogicException('pending saved');}catch(BomWorkflowError $e){wfCheck($e->reason==='locked','pending locked');}
$approve=requestData($pdo,'A');$GLOBALS['failPolicy']=true;
try{bw_execute($pdo,'approve_project',$approve,'test-reviewer',true);throw new LogicException('injected failure ignored');}catch(RuntimeException $e){wfCheck($e->getMessage()==='Injected policy failure','failure reached');}
wfCheck(bw_get($pdo,'A')['review_status']==='pending','failed approval rolls back state');wfCheck((int)$pdo->query('SELECT COUNT(*) FROM bom_snapshots')->fetchColumn()===0,'failed approval rolls back snapshot');wfCheck((float)$pdo->query('SELECT cost FROM bom_cost_publications')->fetchColumn()===20.0,'failed approval rolls back price publication');
$GLOBALS['failPolicy']=false;$ok=bw_execute($pdo,'approve_project',$approve,'test-reviewer',true);wfCheck(bw_execute($pdo,'approve_project',$approve,'test-reviewer',true)===$ok,'approval idempotent');
wfCheck((int)$pdo->query('SELECT COUNT(*) FROM bom_snapshots')->fetchColumn()===1,'only one snapshot');wfCheck((float)$pdo->query('SELECT profit_rate FROM bom_snapshots')->fetchColumn()===0.0,'zero profit sealed');wfCheck((float)$pdo->query('SELECT cost FROM bom_cost_publications')->fetchColumn()===24.0,'approved cost published');
wfCheck(json_decode($pdo->query('SELECT payload_json FROM bom_cost_publications')->fetchColumn(),true)['initial_freeze']===false,'explicit approval releases freeze priority');
bw_execute($pdo,'create_snapshot',requestData($pdo,'A'),'test-reviewer',true);wfCheck((int)$pdo->query('SELECT COUNT(*) FROM bom_snapshots')->fetchColumn()===1,'manual snapshot returns current no duplicate');
bw_execute($pdo,'unapprove_project',requestData($pdo,'A',array('review_note'=>'Synthetic change')),'test-reviewer',true);wfCheck((float)$pdo->query('SELECT cost FROM bom_cost_publications')->fetchColumn()===24.0,'unapprove keeps published version');
bw_execute($pdo,'submit_review',requestData($pdo,'A'),'test-author',true);
$a=requestData($pdo,'A');$b=$a;$b['request_id']=bin2hex(random_bytes(16));$workers=array();
foreach(array($a,$b) as $payload){$pipes=array();$proc=proc_open(array(PHP_BINARY,'-n',__FILE__,'worker',base64_encode(bw_json($payload))),array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes);fclose($pipes[0]);$workers[]=array($proc,$pipes);}
$won=0;$conflicts=0;foreach($workers as [$proc,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);wfCheck(proc_close($proc)===0,'worker failed '.$err);$r=json_decode($out,true);if($r['ok']??false)$won++;elseif(($r['code']??'')==='revision_conflict')$conflicts++;}
wfCheck($won===1&&$conflicts===1,'two reviewers one winner');wfCheck((int)$pdo->query('SELECT COUNT(*) FROM bom_snapshots')->fetchColumn()===2,'one snapshot per actual approval');
echo "BOM MySQL: freeze, save, stale versions, hidden costs, pending lock, atomic approval/publication rollback, lost-response replay, zero profit, snapshot idempotency, unapprove, two-reviewer concurrency OK\n";
