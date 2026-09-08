<?php
/** Standalone disposable socket-only MySQL. No application bootstrap or production configuration. */
$source=file_get_contents(__DIR__.'/crm_marketing_mysql_integration.php');
foreach(['mit_assert','mit_config','mit_connect'] as $name){preg_match('/^function '.preg_quote($name,'/').'\(/m',$source,$m,PREG_OFFSET_CAPTURE);$start=$m[0][1];$end=strpos($source,"\n}",$start);eval(substr($source,$start,$end+2-$start));}
$pdo=mit_connect(mit_config());
mit_assert((int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()===0,'Fresh sandbox required');
require dirname(__DIR__).'/includes/dispatch_daily.php';
$columns=[];foreach(dd_fields() as $f){if($f==='id')$type='BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';elseif(in_array($f,['created_by','assigned_to','parent_group_id','progress','is_deleted']))$type='INT DEFAULT 0';else $type='TEXT NULL';$columns[]="`{$f}` {$type}";}
$pdo->exec('CREATE TABLE dispatch_next_tasks ('.implode(',',$columns).', is_read INT DEFAULT 0, sort_order INT DEFAULT 0) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE dispatch_next_comments(id INT AUTO_INCREMENT PRIMARY KEY,task_id BIGINT UNSIGNED,user_id INT,comment TEXT) ENGINE=InnoDB');
$pdo->exec("CREATE TABLE crm_users(id INT PRIMARY KEY,username VARCHAR(80),real_name VARCHAR(80)) ENGINE=InnoDB");
$pdo->exec("INSERT INTO crm_users VALUES(1,'one','人员甲'),(2,'two','人员乙')");
$today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
$tomorrow=(new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');
$yesterday=(new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
$insert=$pdo->prepare("INSERT INTO dispatch_next_tasks(task_no,title,task_type,dispatch_mode,status,created_by,assigned_to,task_date,due_at,created_at) VALUES('TEST',?,'personal','single','in_progress',?,?,?,?,'2026-01-01 00:00:00')");
$insert->execute(['初始任务',1,1,$today,$today.' 18:00:00']);
$meta=dd_install($pdo);$initial=(int)$pdo->query('SELECT COUNT(*) FROM dispatch_daily_events')->fetchColumn();
dd_install($pdo);mit_assert((int)$pdo->query('SELECT COUNT(*) FROM dispatch_daily_events')->fetchColumn()===$initial,'Idempotent migration');
$pdo->exec('SET @dispatch_daily_actor=1');
$insert->execute(['人员乙私人任务',2,2,$today,$today.' 18:00:00']);
$pdo->exec("UPDATE dispatch_next_tasks SET task_type='private' WHERE id=2");
$pdo->exec("UPDATE dispatch_next_tasks SET title='修改后任务' WHERE id=1");
$before=(int)$pdo->query('SELECT COUNT(*) FROM dispatch_daily_events')->fetchColumn();
$pdo->exec('UPDATE dispatch_next_tasks SET is_read=1,sort_order=42 WHERE id=1');
mit_assert((int)$pdo->query('SELECT COUNT(*) FROM dispatch_daily_events')->fetchColumn()===$before,'Read/sort must not inflate activity');
$pdo->beginTransaction();$pdo->exec("UPDATE dispatch_next_tasks SET title='ROLLBACK' WHERE id=1");$pdo->rollBack();
mit_assert((int)$pdo->query('SELECT COUNT(*) FROM dispatch_daily_events')->fetchColumn()===$before,'Audit fact rolls back with task');
$mine=dd_report($pdo,['date'=>$today,'section'=>'changes'],1,false);
mit_assert($mine['counts']['pending']===1 && count($mine['users'])===0 && $mine['counts']['changes']===1,'No other private tasks or people selector exposed');
try{dd_report($pdo,['date'=>$today,'user_id'=>2],1,false);throw new LogicException('Foreign report allowed');}catch(RuntimeException $e){mit_assert($e->getCode()===403,'Foreign report permission');}
try{dd_report($pdo,['date'=>$today,'user_id'=>0],1,false);throw new LogicException('Team allowed');}catch(RuntimeException $e){mit_assert($e->getCode()===403,'Team permission');}
$team=dd_report($pdo,['date'=>$today,'user_id'=>0],1,true);mit_assert($team['counts']['pending']===2 && count($team['team'])===2,'Admin sees all including private');
$pdo->prepare("UPDATE dispatch_next_tasks SET status='done',completed_at=? WHERE id=1")->execute([$today.' 10:00:00']);
mit_assert(dd_report($pdo,['date'=>$today],1,false)['counts']['completed']===1,'Completion on old task counted today');
$pdo->exec("UPDATE dispatch_next_tasks SET status='in_progress',completed_at=NULL WHERE id=1");
mit_assert(dd_report($pdo,['date'=>$today],1,false)['counts']['completed']===0,'Reopening removes current completion credit');
$pdo->exec('UPDATE dispatch_next_tasks SET assigned_to=2 WHERE id=1');
mit_assert(dd_report($pdo,['date'=>$today],1,false)['counts']['pending']===0 && dd_report($pdo,['date'=>$today],2,false)['counts']['pending']===2,'Transfer changes ownership once');
$saved=dd_save_note($pdo,['date'=>$today,'user_id'=>1,'note'=>'需要协助','version'=>0],1);mit_assert($saved['version']===1,'Own supplement');
try{dd_save_note($pdo,['date'=>$today,'user_id'=>2,'note'=>'fake','version'=>0],1);throw new LogicException('Foreign note');}catch(RuntimeException $e){mit_assert($e->getCode()===403,'Foreign note rejected');}
try{dd_save_note($pdo,['date'=>$today,'user_id'=>1,'note'=>'stale','version'=>0],1);throw new LogicException('Stale note');}catch(RuntimeException $e){mit_assert($e->getCode()===409,'Concurrent revision rejected');}
try{dd_save_note($pdo,['date'=>$yesterday,'user_id'=>1,'note'=>'rewrite','version'=>0],1);throw new LogicException('Old note');}catch(RuntimeException $e){mit_assert($e->getCode()===403,'Past note immutable');}
// Move only synthetic checkpoint facts into yesterday, then prove later edits/deletion cannot rewrite it.
$pdo->prepare('UPDATE dispatch_daily_meta SET started_at=? WHERE id=1')->execute([dd_day($yesterday)['start']]);
$pdo->prepare('UPDATE dispatch_daily_events SET occurred_at=?')->execute([dd_day($yesterday)['start']]);
$past=dd_report($pdo,['date'=>$yesterday,'user_id'=>2,'section'=>'changes'],1,true);
$pdo->exec("UPDATE dispatch_next_tasks SET title='Later update' WHERE id=1");$pdo->exec('DELETE FROM dispatch_next_tasks WHERE id=2');
$again=dd_report($pdo,['date'=>$yesterday,'user_id'=>2,'section'=>'changes'],1,true);
mit_assert($past===$again,'Later edits and hard deletion preserve historical report');
$pdo->exec("INSERT INTO dispatch_next_comments(task_id,user_id,comment) VALUES (1,2,'等待客户图片')");
$commentId=(int)$pdo->lastInsertId();
$notes=dd_report($pdo,['date'=>$today,'section'=>'changes'],2,false);
mit_assert($notes['items'][0]['changes'][0]['field']==='comment' && $notes['items'][0]['actor_id']===2,'Progress text captured without changing percentage');
$pdo->exec("DELETE FROM dispatch_next_comments WHERE id={$commentId}");
$notes=dd_report($pdo,['date'=>$today,'section'=>'changes'],2,false);
mit_assert($notes['items'][0]['changes'][0]['before']==='等待客户图片' && $notes['counts']['pending']===1,'Deleted comment retained as activity, never deletes task state');
// Pagination retains full totals; inserts automatically journal without a PHP audit caller.
for($i=0;$i<43;$i++)$insert->execute(['分页 '.$i,1,1,$today,$today.' 18:00:00']);
mit_assert((int)$pdo->lastInsertId()===(int)$pdo->query('SELECT MAX(id) FROM dispatch_next_tasks')->fetchColumn(),'Trigger must preserve task insert identity');
$paged=dd_report($pdo,['date'=>$today,'section'=>'pending','page'=>3],1,false);
mit_assert($paged['counts']['pending']===43 && count($paged['items'])===3 && $paged['pages']===3,'Exact totals / bounded pages');
$pdo->beginTransaction();for($i=0;$i<3000;$i++)$insert->execute(['规模测试 '.$i,2,2,$today,$today.' 18:00:00']);$pdo->commit();
$start=microtime(true);$large=dd_report($pdo,['date'=>$today,'user_id'=>0,'section'=>'pending'],1,true);
mit_assert($large['counts']['pending']===3044 && count($large['items'])===20,'3000+ tasks retain exact admin totals and bounded response');
echo 'Daily report 3000+ synthetic tasks: seconds='.round(microtime(true)-$start,3).', response_bytes='.strlen(json_encode($large)).', peak_bytes='.memory_get_peak_usage(true)."\n";
// Multi-party card enrichment is batched, as-of and membership-scoped, never copied from mutable group totals.
$insert->execute(['多人项目',1,1,$today,$today.' 18:00:00']);$multiId=(int)$pdo->lastInsertId();
$insert->execute(['不得向甲导出的其他人正文',1,2,$today,$today.' 18:00:00']);$sibling=(int)$pdo->lastInsertId();
$pdo->exec("UPDATE dispatch_next_tasks SET task_type='dispatch',dispatch_mode='multi',parent_group_id=99,project='项目内容',description='详细说明' WHERE id={$multiId}");
$pdo->exec("UPDATE dispatch_next_tasks SET task_type='dispatch',dispatch_mode='multi',parent_group_id=99,project='FOREIGN_SECRET_PROJECT',description='FOREIGN_SECRET_DESCRIPTION' WHERE id={$sibling}");
$multi=dd_report($pdo,['date'=>$today,'section'=>'changes'],1,false);
$card=array_values(array_filter($multi['items'],fn($r)=>$r['id']===$multiId))[0];
mit_assert($card['project']==='项目内容' && $card['description']==='详细说明' && $card['creator_name']==='人员甲','Card content and creator from own fact snapshot');
mit_assert($card['group_summary']['member_count']===2 && $card['group_summary']['member_names']===['人员甲','人员乙'],'Personal report can count its dispatch group without whole-team access');
mit_assert(strpos(json_encode($multi),'FOREIGN_SECRET')===false,'Sibling project and description never leak in personal response');
$pdo->exec("UPDATE dispatch_next_tasks SET assigned_to=2 WHERE id={$multiId}");
$departed=dd_report($pdo,['date'=>$today,'section'=>'changes'],1,false);
foreach($departed['items'] as $item)if($item['id']===$multiId)mit_assert($item['group_summary']===null,'Transferred-out events cannot disclose new recipient roster');
$pdo->exec("UPDATE dispatch_next_tasks SET assigned_to=1 WHERE id={$multiId}");
// Preserve a same-day cohort, then move only synthetic facts to a past date for exact historical replay.
$pdo->exec("UPDATE dispatch_next_tasks SET dispatch_mode='recurring' WHERE id IN ({$multiId},{$sibling})");
$insert->execute(['下一批次',1,2,$tomorrow,$tomorrow.' 18:00:00']);$futureId=(int)$pdo->lastInsertId();
$pdo->exec("UPDATE dispatch_next_tasks SET task_type='dispatch',dispatch_mode='recurring',parent_group_id=99 WHERE id={$futureId}");
$pdo->prepare('UPDATE dispatch_daily_events SET occurred_at=? WHERE task_id IN (?,?,?)')->execute([dd_day($yesterday)['start'],$multiId,$sibling,$futureId]);
$history=dd_report($pdo,['date'=>$yesterday,'section'=>'changes'],1,false);
$oldCard=array_values(array_filter($history['items'],fn($r)=>$r['id']===$multiId && $r['dispatch_mode']==='recurring'))[0];
mit_assert($oldCard['group_summary']['member_count']===2 && $oldCard['group_summary']['task_count']===2 && $oldCard['group_summary']['as_of']==='所选日日终','Historical recurring group counts same task-date cohort only');
$pdo->exec("UPDATE dispatch_next_tasks SET project='NEW_BODY',description='NEW_DESCRIPTION' WHERE id={$multiId}");
$pdo->exec("UPDATE dispatch_next_tasks SET is_deleted=1 WHERE id={$sibling}");
mit_assert(dd_report($pdo,['date'=>$yesterday,'section'=>'changes'],1,false)===$history,'Current content and roster edits cannot rewrite historical enrichment');
echo "Dispatch daily MySQL: atomic triggers, idempotent baseline, privacy/admin, transfer/reopen, read/sort exclusion, notes concurrency, historical replay and pagination passed.\n";
