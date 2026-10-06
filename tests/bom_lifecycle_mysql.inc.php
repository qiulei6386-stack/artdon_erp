<?php
// Loaded only after the guarded socket-only BOM workflow integration fixture.
wfCheck(isset($pdo)&&getenv('CRM_PHASE1_MYSQL_TEST')==='1','Isolated fixture required');
preg_match('/CREATE TABLE (?:IF NOT EXISTS )?bom_materials\([\s\S]*?\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4/',$src,$m);$pdo->exec($m[0]);
foreach(['qid','bom_material_trim','bom_material_norm_key','bom_material_select_cols','bom_material_active_where','bom_material_public_row','bom_material_exact_duplicates'] as $fn)if(!function_exists($fn))wfExtract($fn);
require_once dirname(__DIR__).'/tools/bom_lifecycle_migrate.php';
$backup=dirname($socket).'/lifecycle-backup';mkdir($backup,0700);
$plan=bl_migration_plan($pdo);$oldSnapshots=$pdo->query('SELECT * FROM bom_snapshots ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$oldRows=$pdo->query('SELECT project_uid,rows_json FROM bom_projects ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
try{bl_migration_apply($pdo,str_repeat('0',64),$backup);throw new LogicException('Stale migration accepted');}catch(RuntimeException $e){wfCheck(strpos($e->getMessage(),'重新扫描')!==false,'Migration plan guard');}
$migration=bl_migration_apply($pdo,$plan['hash'],$backup);
wfCheck(bl_ready($pdo)&&$migration['counts']['to_preliminary']>0,'Historical unpublished-review entries migrate');
wfCheck($oldSnapshots===$pdo->query('SELECT * FROM bom_snapshots ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),'Migration preserves every snapshot');
wfCheck($oldRows===$pdo->query('SELECT project_uid,rows_json FROM bom_projects ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),'Migration preserves every original BOM row');
wfCheck(bl_migration_apply($pdo,$plan['hash'],$backup)['already_applied']===true,'Migration retry idempotent');
wfCheck(is_file($backup.'/committed.json')&&is_file($backup.'/before.jsonl'),'Private backup exists before migration commit');

$d=['project_uid'=>'LIFE','name'=>'Lifecycle fixture','model'=>'57.99991','rows'=>[['name'=>'Synthetic lifecycle material','qty'=>2,'price'=>10,'priceStatus'=>'confirmed']],'labor'=>1,'other'=>0,'profit_rate'=>30,'quote_mode'=>'markup','exchange_rate'=>1,'currency'=>'RMB','version_no'=>'V1','variant_label'=>'General'];
$alice=['id'=>701,'username'=>'alice'];
bw_execute($pdo,'save_project',requestData($pdo,'LIFE',$d),'Alice',true,$alice);
$st=$pdo->prepare('SELECT COUNT(*) FROM bom_cost_publications WHERE project_uid=?');$st->execute(['LIFE']);wfCheck((int)$st->fetchColumn()===0,'New drafts never publish');
bw_execute($pdo,'submit_review',requestData($pdo,'LIFE'),'Alice',true,$alice);
wfCheck(bw_get($pdo,'LIFE')['review_status']==='preliminary','Draft becomes editable preliminary');
$first=qbv_resolve($pdo,['model'=>'57.99991']);wfCheck($first['patch']['cost_rmb']===21.0&&$first['version']['snapshot_id']<0,'Preliminary quote gets immutable reference');
$d['rows'][0]['price']=12;
try{bw_execute($pdo,'save_project',requestData($pdo,'LIFE',$d),'Alice',true,$alice);throw new LogicException('Reasonless price change accepted');}catch(BomWorkflowError $e){wfCheck($e->reason==='validation','Price reason required');}
wfCheck(bw_totals(bw_get($pdo,'LIFE'))['total']===21.0,'Failed audit rolls back saved rows');
$d['price_reason']='Supplier adjustment';$save=requestData($pdo,'LIFE',$d);bw_execute($pdo,'save_project',$save,'Alice',true,$alice);
$count=(int)$pdo->query('SELECT COUNT(*) FROM bom_price_events')->fetchColumn();bw_execute($pdo,'save_project',$save,'Alice',true,$alice);wfCheck((int)$pdo->query('SELECT COUNT(*) FROM bom_price_events')->fetchColumn()===$count,'Retry does not duplicate price events');
wfCheck(qbv_resolve($pdo,['model'=>'57.99991'])['patch']['cost_rmb']===25.0,'Preliminary edit auto publishes');
wfCheck(qbv_resolve($pdo,['model'=>'57.99991'],$first['version']['snapshot_id'])['patch']===$first['patch'],'Historical reference stays unchanged');
$mid=(int)$pdo->query("SELECT material_id FROM bom_material_usages WHERE project_uid='LIFE'")->fetchColumn();
$mat=$pdo->query('SELECT * FROM bom_materials WHERE id='.$mid)->fetch(PDO::FETCH_ASSOC);unset($mat['image']);$mat['unit']='PCS';$mat['price']=15;$mat['price_status']='confirmed';$mat['identity_confirmed']=true;$mat['price_reason']='Confirmed supplier standard';
$pdo->beginTransaction();bl_save_material($pdo,$mat,'Alice',$alice,'save_material','test-standard',true,true);bl_propagate($pdo,[$mid],'Alice','Confirmed supplier standard','test-standard');$pdo->commit();
wfCheck(qbv_resolve($pdo,['model'=>'57.99991'])['patch']['cost_rmb']===31.0,'Confirmed standard propagates to preliminary');
bw_execute($pdo,'approve_project',requestData($pdo,'LIFE'),'Reviewer',true,['id'=>702,'username'=>'reviewer']);
$final=bw_get($pdo,'LIFE');$snapshot=$pdo->query('SELECT * FROM bom_snapshots WHERE id='.(int)$final['latest_snapshot_id'])->fetch(PDO::FETCH_ASSOC);
wfCheck((float)json_decode($snapshot['totals_json'],true)['total']===31.0,'Final approval seals effective cost');
$originalRows=$final['rows_json'];$mat['price']=17;$mat['price_reason']='Second supplier adjustment';
$pdo->beginTransaction();bl_save_material($pdo,$mat,'Alice',$alice,'save_material','test-final-change',true,true);bl_propagate($pdo,[$mid],'Alice','Second supplier adjustment','test-final-change');$pdo->commit();
wfCheck(qbv_resolve($pdo,['model'=>'57.99991'])['patch']['cost_rmb']===35.0,'Final reference follows material standard');
wfCheck(bw_get($pdo,'LIFE')['rows_json']===$originalRows&&bw_get($pdo,'LIFE')['review_status']==='approved','Final structure and stage unchanged');
wfCheck($pdo->query('SELECT * FROM bom_snapshots WHERE id='.(int)$final['latest_snapshot_id'])->fetch(PDO::FETCH_ASSOC)===$snapshot,'Approval snapshot byte fields immutable');
$summary=bl_summary($pdo,bw_get($pdo,'LIFE'));wfCheck($summary['approval_cost']===31.0&&$summary['approval_delta']===4.0,'Dual cost difference');
$mat['price']=25;$mat['price_status']='pending';$mat['price_reason']='Unconfirmed offer';
$pdo->beginTransaction();bl_save_material($pdo,$mat,'Alice',$alice,'save_material','test-pending',true,true);bl_propagate($pdo,[$mid],'Alice','Unconfirmed offer','test-pending');$pdo->commit();
wfCheck(qbv_resolve($pdo,['model'=>'57.99991'])['patch']['cost_rmb']===35.0,'Pending offer never overwrites confirmed standard');
try{bw_execute($pdo,'save_project',requestData($pdo,'LIFE',$d),'Alice',true,$alice);throw new LogicException('Final structure edited');}catch(BomWorkflowError $e){wfCheck($e->reason==='locked','Final edit lock');}
wfCheck(count(bl_where_used($pdo,$mid,true)['usages'])===1&&!isset(bl_where_used($pdo,$mid,false)['usages'][0]['price']),'Where used respects cost permission');
bw_execute($pdo,'unapprove_project',requestData($pdo,'LIFE',['review_note'=>'Change assembly']),'Reviewer',true);
wfCheck(bw_get($pdo,'LIFE')['review_status']==='preliminary','Final reverts explicitly to preliminary');
$copy=$d;$copy['project_uid']='LIFE2';bw_execute($pdo,'save_project',requestData($pdo,'LIFE2',$copy),'Alice',true,$alice);bw_execute($pdo,'submit_review',requestData($pdo,'LIFE2'),'Alice',true,$alice);
wfCheck(qbv_resolve($pdo,['model'=>'57.99991'])['choose']===true,'Multiple preliminary BOMs require explicit choice');
wfCheck(!empty(bcp_find(['57.99991'],bcp_map($pdo))[1]['ambiguous']),'Cost mapping never chooses highest same-SKU BOM');
$history=bl_price_history($pdo,['material_id'=>$mid]);wfCheck($history['total']>=4&&strpos($history['events'][0]['actor_account'],'alice')!==false,'Material price history includes authenticated account');
$quote=['items_json'=>bw_json([['product'=>array_merge(['model'=>'57.99991'],$first['patch'])]])];qbv_validate_save($pdo,$quote);
$forged=$quote;$items=json_decode($forged['items_json'],true);$items[0]['product']['cost_rmb']=999;$forged['items_json']=bw_json($items);
try{qbv_validate_save($pdo,$forged);throw new LogicException('Forged reference accepted');}catch(RuntimeException $e){}
echo "BOM lifecycle: preliminary publication, audit rollback/retry, confirmed/pending standard propagation, immutable final snapshot, dual costs, historical quote validation, exact where-used and ambiguity passed\n";
