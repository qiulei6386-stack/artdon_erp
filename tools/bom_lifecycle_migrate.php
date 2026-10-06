<?php
declare(strict_types=1);
// CLI only. Plan is read-only; apply requires the exact plan digest and a private backup directory.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/includes/bom_workflow.php';
function bl_migration_plan(PDO $pdo,bool $lock=false): array {
    $projects=$pdo->query("SELECT id,project_uid,name,customer,model,version_no,variant_label,linked_system,linked_id,currency,exchange_rate,labor,other,profit_rate,quote_mode,review_status,latest_snapshot_id,workflow_version,updated_at,is_active,OCTET_LENGTH(rows_json) AS row_bytes,IF(OCTET_LENGTH(rows_json)<=2097152,rows_json,NULL) AS rows_json FROM bom_projects WHERE is_active=1 ORDER BY id".($lock?' FOR UPDATE':''))->fetchAll(PDO::FETCH_ASSOC);
    $materials=$pdo->query('SELECT id,is_active,name,model,spec,unit,price,updated_at FROM bom_materials ORDER BY id'.($lock?' FOR UPDATE':''))->fetchAll(PDO::FETCH_ASSOC);
    $publications=$pdo->query('SELECT * FROM bom_cost_publications ORDER BY project_uid'.($lock?' FOR UPDATE':''))->fetchAll(PDO::FETCH_ASSOC);
    $pubs=array_column($publications,null,'project_uid');$counts=['projects'=>count($projects),'to_preliminary'=>0,'final'=>0,'draft'=>0,'rows'=>0];
    foreach($projects as $p){if($p['rows_json']===null)throw new RuntimeException('明细超过2MiB，需单独检查');$counts['rows']+=count(bw_rows($p['rows_json']));
        if($p['review_status']==='approved')$counts['final']++;
        elseif(isset($pubs[$p['project_uid']])&&$pubs[$p['project_uid']]['source']==='legacy_unreviewed')$counts['to_preliminary']++;
        else $counts['draft']++;
    }
    return ['hash'=>hash('sha256',bw_json([$projects,$materials,$publications])),'counts'=>$counts,'projects'=>$projects,'publications'=>$pubs];
}
function bl_migration_apply(PDO $pdo,string $expected,string $backup): array {
    if(bl_ready($pdo))return ['already_applied'=>true];
    if(!preg_match('/^[a-f0-9]{64}$/D',$expected)||!is_dir($backup)||is_link($backup)||str_starts_with(realpath($backup),realpath(dirname(__DIR__))))throw new RuntimeException('需扫描校验值及站点外私有备份目录');
    bl_schema($pdo);$pdo->beginTransaction();
    try{
        bl_lock($pdo);$plan=bl_migration_plan($pdo,true);if(!hash_equals($expected,$plan['hash']))throw new RuntimeException('扫描后资料发生变化，请重新扫描；未迁移');
        $path=$backup.'/before.jsonl';$f=fopen($path,'x');if(!$f)throw new RuntimeException('不能创建备份');chmod($path,0600);
        // Stream full records including images without accumulating the whole material library.
        foreach(['bom_projects','bom_materials','bom_cost_publications','bom_workflow_meta','quote_price_policies'] as $table){
            $ids=$pdo->query("SELECT ".($table==='bom_cost_publications'?'project_uid':($table==='bom_workflow_meta'?'name':'id'))." FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
            $pk=$table==='bom_cost_publications'?'project_uid':($table==='bom_workflow_meta'?'name':'id');$st=$pdo->prepare("SELECT * FROM `$table` WHERE `$pk`=?");
            foreach($ids as $id){$st->execute([$id]);$line=bw_json(['table'=>$table,'row'=>$st->fetch(PDO::FETCH_ASSOC)])."\n";if(fwrite($f,$line)!==strlen($line))throw new RuntimeException('备份写入不完整');}
        }fflush($f);fclose($f);
        $batch='lifecycle-'.date('YmdHis');$actor='历史迁移（用户授权）';$index=bl_material_index($pdo);$totals=['bound'=>0,'created'=>0,'uncertain'=>0,'empty'=>0];
        $pdo->exec("INSERT IGNORE INTO bom_material_state(material_id,identity_status,price_status,origin) SELECT id,IF(TRIM(unit)='','provisional','confirmed'),'historical','existing_library' FROM bom_materials");
        foreach($plan['projects'] as $p){
            $before=bw_revision($p);$pub=$plan['publications'][$p['project_uid']]??null;
            if($p['review_status']!=='approved'&&$pub&&$pub['source']==='legacy_unreviewed'){
                $pdo->prepare("UPDATE bom_projects SET review_status='preliminary',workflow_version=workflow_version+1 WHERE project_uid=?")->execute([$p['project_uid']]);$p['review_status']='preliminary';$p['workflow_version']++;
                $pdo->prepare("INSERT INTO bom_workflow_events(project_uid,action,actor,before_revision,after_revision,note,changes_json) VALUES(?,'migrate_preliminary',?,?,?,?,?)")->execute([$p['project_uid'],$actor,$before,bw_revision($p),'历史未审核成本迁为预审；不代表人工审核',bw_json(['plan_hash'=>$expected,'batch_id'=>$batch])]);
            }
            $result=bl_bind($pdo,$p,$actor,$index,$batch);foreach($totals as $k=>$v)$totals[$k]+=$result[$k];
            bl_publish($pdo,$p,$actor,'历史迁移：重新同步当前BOM成本',$batch);
        }
        $pdo->exec("INSERT INTO bom_workflow_meta(name,value) VALUES('lifecycle_v2','ready') ON DUPLICATE KEY UPDATE value='ready'");
        $policies=bl_policy_sync($pdo,$plan['projects'],$actor);
        $result=['hash'=>$expected,'batch_id'=>$batch,'counts'=>$plan['counts'],'materials'=>$totals,'policies_updated'=>$policies,'backup_sha256'=>hash_file('sha256',$path)];
        file_put_contents($backup.'/result-before-commit.json',bw_json($result),LOCK_EX);$pdo->commit();file_put_contents($backup.'/committed.json',bw_json($result),LOCK_EX);return $result;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    require_once dirname(__DIR__).'/includes/db.php';$pdo=db();
    try{
        if(($argv[1]??'')==='alias-apply')$out=bl_alias_apply($pdo,$argv[2]??'',$argv[3]??'');
        elseif(($argv[1]??'')==='alias-plan'){$pdo->exec('SET TRANSACTION READ ONLY');$pdo->beginTransaction();$plan=bl_alias_plan($pdo);$out=['hash'=>$plan['hash'],'count'=>count($plan['entries'])];$pdo->rollBack();}
        elseif(($argv[1]??'plan')==='apply')$out=bl_migration_apply($pdo,$argv[2]??'',$argv[3]??'');
        else{$pdo->exec('SET TRANSACTION READ ONLY');$pdo->beginTransaction();$p=bl_migration_plan($pdo);$out=['hash'=>$p['hash'],'counts'=>$p['counts']];$pdo->rollBack();}
        echo bw_json($out)."\n";
    }catch(Throwable $e){fwrite(STDERR,get_class($e).': '.$e->getMessage()."\n");exit(1);}
}
