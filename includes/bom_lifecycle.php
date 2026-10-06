<?php
declare(strict_types=1);
require_once __DIR__.'/bom_material_usage.php';
// Explicitly installed by the release CLI. Including this file performs no IO.
function bl_ready(PDO $pdo): bool {
    try{return $pdo->query("SELECT value FROM bom_workflow_meta WHERE name='lifecycle_v2'")->fetchColumn()==='ready';}catch(Throwable $e){return false;}
}
function bl_schema(PDO $pdo): void {
    bw_schema($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS bom_material_state(material_id BIGINT PRIMARY KEY,identity_status VARCHAR(30) NOT NULL DEFAULT 'confirmed',price_status VARCHAR(30) NOT NULL DEFAULT 'historical',confirmed_price DECIMAL(14,4) NULL,price_version BIGINT NOT NULL DEFAULT 0,revision BIGINT NOT NULL DEFAULT 0,identity_key CHAR(64) NULL,origin VARCHAR(80) NOT NULL DEFAULT '',updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY idx_identity(identity_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS bom_material_usages(project_uid VARCHAR(100) NOT NULL,row_no INT NOT NULL,material_id BIGINT NOT NULL,row_hash CHAR(64) NOT NULL,binding_status VARCHAR(30) NOT NULL,PRIMARY KEY(project_uid,row_no),KEY idx_material(material_id,project_uid)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS bom_price_events(id BIGINT AUTO_INCREMENT PRIMARY KEY,material_id BIGINT NULL,project_uid VARCHAR(100) NULL,row_no INT NULL,field_name VARCHAR(40) NOT NULL,old_price DECIMAL(18,4) NULL,new_price DECIMAL(18,4) NULL,reason TEXT NOT NULL,actor VARCHAR(160) NOT NULL,actor_account VARCHAR(160) NOT NULL,source VARCHAR(80) NOT NULL,batch_id VARCHAR(100) NOT NULL,details_json LONGTEXT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY idx_material(material_id,id),KEY idx_project(project_uid,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS bom_reference_versions(id BIGINT AUTO_INCREMENT PRIMARY KEY,project_uid VARCHAR(100) NOT NULL,stage VARCHAR(30) NOT NULL,workflow_version BIGINT NOT NULL,approval_snapshot_id BIGINT NULL,cost DECIMAL(18,4) NOT NULL,payload_json LONGTEXT NOT NULL,digest CHAR(64) NOT NULL,actor VARCHAR(160) NOT NULL,reason TEXT NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY idx_project(project_uid,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function bl_lock(PDO $pdo): void {
    if(!$pdo->inTransaction())throw new LogicException('BOM lifecycle requires a transaction');
    $pdo->query("SELECT value FROM bom_workflow_meta WHERE name='legacy_costs_frozen' FOR UPDATE")->fetchColumn();
}
function bl_identity(array $r): string {
    $parts=[];foreach(['name','model','spec'] as $key)$parts[]=mb_strtolower(preg_replace('/\s+/u',' ',trim((string)($r[$key]??''))),'UTF-8');
    return hash('sha256',bw_json($parts));
}
function bl_identity_matches(array $r,array $m): bool {
    $norm=static fn($v)=>mb_strtolower(preg_replace('/\s+/u',' ',trim((string)$v)),'UTF-8');
    if(!empty($r['unit'])&&!empty($m['unit'])&&$norm($r['unit'])!==$norm($m['unit']))return false;
    if(bl_identity($r)===bl_identity($m))return true;
    // Recognize only the exact display strings emitted by the existing material picker.
    // Never remove dimensions, infer a model, or merge two different master IDs.
    if(!empty($r['model'])&&$norm($r['model'])!==$norm($m['model']??''))return false;
    $names=[$m['name']??''];if(!empty($m['brand']))$names[]=trim($m['brand']).' / '.trim($m['name']??'');
    $specs=[$m['spec']??''];if(!empty($m['model'])){if(empty($m['spec']))$specs[]=$m['model'];else $specs[]=trim($m['model']).' / '.trim($m['spec']);}
    return in_array($norm($r['name']??''),array_map($norm,$names),true)&&in_array($norm($r['spec']??''),array_map($norm,$specs),true);
}
function bl_row_hash(array $r): string {
    return hash('sha256',bw_json(array_intersect_key($r,array_flip(['name','model','spec','materialId','qty','price','process','finishCost','finishCost2']))));
}
function bl_event(PDO $pdo,?int $material,?string $uid,?int $row,string $field,$old,$new,string $reason,string $actor,array $user,string $source,string $batch,array $details=[]): int {
    if(trim($reason)===''||mb_strlen($reason)>2000)throw new BomWorkflowError('validation','价格变更必须填写原因');
    $account=$user?((string)($user['_user_table']??'users').':'.(string)($user['_user_id']??$user['id']??'').':'.(string)($user['username']??'')):'system:'.$actor;
    $pdo->prepare('INSERT INTO bom_price_events(material_id,project_uid,row_no,field_name,old_price,new_price,reason,actor,actor_account,source,batch_id,details_json) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$material,$uid,$row,$field,$old,$new,$reason,$actor,$account,$source,$batch,bw_json($details)]);
    return (int)$pdo->lastInsertId();
}
function bl_material_index(PDO $pdo): array {
    $out=[];$st=$pdo->query('SELECT id,brand,name,model,spec,unit,price,is_active FROM bom_materials ORDER BY id');
    while($m=$st->fetch(PDO::FETCH_ASSOC)){$out['ids'][(int)$m['id']]=$m;if((int)$m['is_active']===1)$out['keys'][bl_identity($m)][]=(int)$m['id'];}
    return $out;
}
function bl_bind(PDO $pdo,array $p,string $actor,array &$index,string $batch): array {
    // Bind in a separate index: final BOM rows and all approval snapshots stay byte-for-byte intact.
    $uid=(string)$p['project_uid'];$pdo->prepare('DELETE FROM bom_material_usages WHERE project_uid=?')->execute([$uid]);$counts=['bound'=>0,'created'=>0,'uncertain'=>0,'empty'=>0];
    foreach(bw_rows($p['rows_json']) as $i=>$r){
        if(trim($r['name'].($r['model']??'').$r['spec'])===''){$counts['empty']++;continue;}
        $key=bl_identity($r);$id=(int)$r['materialId'];$status='exact';
        if($id&&isset($index['ids'][$id])&&(int)$index['ids'][$id]['is_active']===1){
            if(!bl_identity_matches($r,$index['ids'][$id])&&empty($r['materialBindingConfirmed']))$status='needs_identity';
        }else{
            $ids=$index['keys'][$key]??[];
            if(count($ids)===1)$id=$ids[0];else{
                // Ambiguous identities are isolated candidates; never guess one of multiple masters.
                $status=count($ids)>1?'needs_identity':'provisional';$m=$r;
                foreach(['name'=>255,'model'=>160,'spec'=>500] as $field=>$limit)if(mb_strlen((string)($m[$field]??''))>$limit)throw new RuntimeException('物料字段超过库容量，迁移已停止：'.$uid.' 第'.($i+1).'行');
                $pdo->prepare("INSERT INTO bom_materials(category,brand,name,model,spec,price,unit,supplier,keyword,image) VALUES(?,?,?,?,?,?,?,?,?,'')")->execute([mb_substr((string)($r['category']??''),0,120),mb_substr((string)($r['brand']??''),0,120),$r['name']?:'待确认物料', (string)($r['model']??''),$r['spec'],$r['price'],trim((string)($r['unit']??'')),mb_substr((string)($r['supplier']??''),0,160),'BOM迁入；单位、身份及标准价待确认']);
                $id=(int)$pdo->lastInsertId();$index['ids'][$id]=array_merge($r,['id'=>$id,'is_active'=>1]);if(!$ids)$index['keys'][$key]=[$id];
                $pdo->prepare("INSERT INTO bom_material_state(material_id,identity_status,price_status,identity_key,origin) VALUES(?,?,'pending',?,'bom_migration')")->execute([$id,$status,$key]);
                bl_event($pdo,$id,$uid,$i,'price',null,$r['price'],'BOM历史物料迁入；来源价待确认',$actor,[],'migration',$batch,['currency'=>$p['currency'],'unit'=>$r['unit']??'']);$counts['created']++;
            }
        }
        $pdo->prepare('INSERT INTO bom_material_usages(project_uid,row_no,material_id,row_hash,binding_status) VALUES(?,?,?,?,?)')->execute([$uid,$i,$id,bl_row_hash($r),$status]);
        $counts['bound']++;if($status==='needs_identity')$counts['uncertain']++;
    }
    return $counts;
}
function bl_alias_plan(PDO $pdo): array {
    $index=bl_material_index($pdo);$st=$pdo->query("SELECT u.project_uid,u.row_no,u.material_id,u.row_hash,b.rows_json FROM bom_material_usages u JOIN bom_projects b ON b.project_uid=u.project_uid WHERE u.binding_status='needs_identity' AND b.is_active=1 ORDER BY u.project_uid,u.row_no");$entries=[];
    while($u=$st->fetch(PDO::FETCH_ASSOC)){$r=bw_rows($u['rows_json'])[(int)$u['row_no']]??null;$m=$index['ids'][(int)$u['material_id']]??null;
        if($r&&$m&&(int)$m['is_active']===1&&bl_identity_matches($r,$m)&&hash_equals($u['row_hash'],bl_row_hash($r))){unset($u['rows_json']);$u['material_identity']=hash('sha256',bw_json($m));$entries[]=$u;}
    }
    return ['entries'=>$entries,'hash'=>hash('sha256',bw_json($entries))];
}
function bl_alias_apply(PDO $pdo,string $expected,string $backup): array {
    if(!bl_ready($pdo)||!is_dir($backup)||is_link($backup))throw new RuntimeException('需已初始化版本及私有备份目录');
    $pdo->beginTransaction();
    try{bl_lock($pdo);$plan=bl_alias_plan($pdo);if(!hash_equals($expected,$plan['hash']))throw new RuntimeException('显示格式修复计划已变化');
        $file=$backup.'/alias-bindings-before.json';$f=fopen($file,'x');if(!$f)throw new RuntimeException('不能创建关联恢复记录');chmod($file,0600);$bytes=bw_json($plan);if(fwrite($f,$bytes)!==strlen($bytes))throw new RuntimeException('恢复记录写入不完整');fclose($f);
        $ids=[];foreach($plan['entries'] as $u){$pdo->prepare("UPDATE bom_material_usages SET binding_status='exact' WHERE project_uid=? AND row_no=? AND material_id=? AND row_hash=? AND binding_status='needs_identity'")->execute([$u['project_uid'],$u['row_no'],$u['material_id'],$u['row_hash']]);$ids[]=(int)$u['material_id'];}
        $sync=bl_propagate($pdo,array_values(array_unique($ids)),'历史显示格式识别','现有物料ID与页面显示名称/规格精确一致','alias-'.$expected);
        $pdo->commit();return ['updated'=>count($plan['entries']),'sync'=>$sync,'hash'=>$expected];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function bl_current(PDO $pdo,array $p): array {
    $st=$pdo->prepare('SELECT u.*,s.confirmed_price,s.price_version,s.identity_status FROM bom_material_usages u LEFT JOIN bom_material_state s ON s.material_id=u.material_id WHERE u.project_uid=?');$st->execute([$p['project_uid']]);$bindings=[];foreach($st->fetchAll(PDO::FETCH_ASSOC) as $u)$bindings[(int)$u['row_no']]=$u;
    $rows=bw_rows($p['rows_json']);$prices=[];$pending=0;
    foreach($rows as $i=>&$r){$u=$bindings[$i]??null;$price=$r['price'];$source='bom_line';$version=0;
        if($u&&hash_equals($u['row_hash'],bl_row_hash($r))){
            $r['materialId']=(string)$u['material_id'];
            if($u['binding_status']!=='needs_identity'&&$u['identity_status']==='confirmed'&&$u['confirmed_price']!==null&&($r['priceMode']??'library')!=='override'){$r['price']=(float)$u['confirmed_price'];$version=(int)$u['price_version'];$source='material_standard';}
            else $pending++;
        }else $pending++;
        $prices[]=['row_no'=>$i,'material_id'=>(int)($u['material_id']??0),'price_version'=>$version,'source'=>$source,'stored_price'=>$price,'current_price'=>$r['price'],'delta'=>round($r['qty']*($r['price']-$price),4)];
        $r=array_intersect_key($r,array_flip(['category','brand','name','model','spec','unit','qty','price','process','finish','finishCost','finish2','finishCost2','priceStatus','priceSource','priceNote','priceMode','materialId']));
    }unset($r);
    $value=$p;$value['rows']=$rows;
    return ['cost'=>round(bw_totals($value)['total'],4),'stored_cost'=>round(bw_totals($p)['total'],4),'rows'=>$rows,'material_prices'=>$prices,'pending_prices'=>$pending];
}
function bl_publish(PDO $pdo,array $p,string $actor,string $reason,string $batch,array $user=[],bool $resume=false): ?int {
    if(!in_array($p['review_status'],['preliminary','approved'],true))return null;
    $st=$pdo->prepare('SELECT source,cost,payload_json FROM bom_cost_publications WHERE project_uid=? FOR UPDATE');$st->execute([$p['project_uid']]);$old=$st->fetch(PDO::FETCH_ASSOC);
    if($old&&$old['source']==='voided'&&!$resume)return null;
    $current=bl_current($pdo,$p);$payload=array_merge(bw_publication_payload($p),$current,['stage'=>$p['review_status'],'workflow_version'=>(int)$p['workflow_version'],'approval_snapshot_id'=>(int)($p['latest_snapshot_id']??0)]);
    $digest=hash('sha256',bw_json($payload));
    $prior=$old?json_decode($old['payload_json'],true):[];
    if(($prior['reference_digest']??'')===$digest)return (int)$prior['reference_id'];
    $pdo->prepare('INSERT INTO bom_reference_versions(project_uid,stage,workflow_version,approval_snapshot_id,cost,payload_json,digest,actor,reason) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$p['project_uid'],$p['review_status'],(int)$p['workflow_version'],$payload['approval_snapshot_id']?:null,$current['cost'],bw_json($payload),$digest,$actor,$reason]);$id=(int)$pdo->lastInsertId();
    $meta=bw_publication_payload($p);$meta['stage']=$p['review_status'];$meta['reference_id']=$id;$meta['reference_digest']=$digest;$meta['pending_prices']=$current['pending_prices'];$meta['workflow_version']=(int)$p['workflow_version'];
    $source=$p['review_status']==='approved'?'final_reference':'preliminary_reference';
    // Foreign-currency BOMs remain visible for correction but cannot be quoted as RMB.
    $meta['quote_eligible']=in_array(strtoupper(trim((string)$p['currency'])),['RMB','CNY','人民币'],true)&&(bool)(bcp_models($p['model'])||($p['linked_id']??''))&&($current['cost']>0||$p['review_status']==='approved');
    $pdo->prepare('INSERT INTO bom_cost_publications(project_uid,snapshot_id,source,payload_json,cost) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE snapshot_id=VALUES(snapshot_id),source=VALUES(source),payload_json=VALUES(payload_json),cost=VALUES(cost),updated_at=NOW()')->execute([$p['project_uid'],$payload['approval_snapshot_id']?:null,$source,bw_json($meta),$current['cost']]);
    bl_event($pdo,null,$p['project_uid'],null,'reference_cost',$old?$old['cost']:null,$current['cost'],$reason,$actor,$user,'reference_sync',$batch,['reference_id'=>$id,'stage'=>$p['review_status'],'pending_prices'=>$current['pending_prices']]);
    return $id;
}
function bl_audit_rows(PDO $pdo,?array $before,array $after,array $d,string $actor,array $user): void {
    if(!$before)return;$old=bw_rows($before['rows_json']);$new=bw_rows($after['rows_json']);
    foreach($new as $i=>$r){if(!isset($old[$i]))continue;if(($r['priceMode']??'library')!==($old[$i]['priceMode']??'library'))bl_event($pdo,(int)$r['materialId']?:null,$after['project_uid'],$i,'price_mode',$old[$i]['price'],$r['price'],trim((string)($d['price_reason']??'')),$actor,$user,'bom_edit',$d['request_id'],['before'=>$old[$i]['priceMode']??'library','after'=>$r['priceMode']??'library']);foreach(['price','process','finishCost','finishCost2'] as $field){
        if(abs($r[$field]-$old[$i][$field])<0.00005)continue;
        bl_event($pdo,(int)$r['materialId']?:null,$after['project_uid'],$i,$field,$old[$i][$field],$r[$field],trim((string)($d['price_reason']??'')),$actor,$user,'bom_edit',$d['request_id'],['before_name'=>$old[$i]['name'],'after_name'=>$r['name']]);
    }}
}
function bl_policy_sync(PDO $pdo,array $projects,string $actor): int {
    require_once __DIR__.'/bom_unreviewed_sync.php';return bus_sync_policies($pdo,$projects,$actor);
}
function bl_save_material(PDO $pdo,array $d,string $actor,array $user,string $source,string $batch,bool $canCost,bool $canSupplier): int {
    bl_lock($pdo);$id=(int)($d['id']??0);$old=null;
    if($id){$st=$pdo->prepare('SELECT id,name,model,spec,price,unit,supplier FROM bom_materials WHERE id=? AND is_active=1 FOR UPDATE');$st->execute([$id]);$old=$st->fetch(PDO::FETCH_ASSOC);if(!$old)throw new RuntimeException('物料不存在或已停用');}
    $values=[];foreach(['category'=>120,'brand'=>120,'name'=>255,'model'=>160,'spec'=>500,'unit'=>40,'supplier'=>160,'keyword'=>500] as $field=>$limit){$values[$field]=trim((string)($d[$field]??''));if(mb_strlen($values[$field])>$limit)throw new RuntimeException('物料字段过长：'.$field);}
    if($values['name']==='')throw new RuntimeException('物料名称不能为空');
    if(bom_material_exact_duplicates($pdo,$values['name'],$values['model'],$values['spec'],$id))throw new RuntimeException('物料名称、型号及规格重复，请选择已有物料');
    $values['price']=$canCost?round(bw_valid_number($d['price']??0,'物料单价'),4):($old['price']??0);
    if(!$canSupplier)$values['supplier']=$old['supplier']??'';
    $st=$pdo->prepare('SELECT * FROM bom_material_state WHERE material_id=?');$st->execute([$id]);$state=$st->fetch(PDO::FETCH_ASSOC)?:['price_status'=>'historical','confirmed_price'=>null,'price_version'=>0,'identity_status'=>'provisional','revision'=>0];
    $expected=$d['expected_material_revision']??$d['revision']??null;if($id&&$expected!==null&&(int)$expected!==(int)$state['revision'])throw new RuntimeException('物料已被其他人修改，请重新打开后核对');
    $status=$canCost?(string)($d['price_status']??$state['price_status']):$state['price_status'];
    if(!in_array($status,['confirmed','pending','historical','estimated'],true))throw new RuntimeException('请选择有效价格状态');
    $confirm=$status==='confirmed';
    if($confirm&&$values['unit']==='')throw new RuntimeException('确认标准价前请填写计价单位');
    $changed=!$old||abs((float)$values['price']-(float)$old['price'])>0.00005||$status!==$state['price_status']||($confirm&&($state['confirmed_price']===null||abs((float)$values['price']-(float)$state['confirmed_price'])>0.00005));
    $reason=trim((string)($d['price_reason']??''));if($changed&&$reason==='')throw new RuntimeException('新增或调整价格时必须填写原因');
    if($old&&$state['confirmed_price']!==null&&(bl_identity($old)!==bl_identity($values)||$old['unit']!==$values['unit']))throw new RuntimeException('已确认标准价的物料身份或计价单位不能直接替换，请建立另一物料并在预审BOM中换料');
    if(array_key_exists('image',$d)&&empty($d['image_unchanged']))$values['image']=(string)$d['image'];
    if($id){$args=array_values($values);$args[]=$id;$pdo->prepare('UPDATE bom_materials SET '.implode(',',array_map(static fn($k)=>"`$k`=?",array_keys($values))).',updated_at=NOW() WHERE id=?')->execute($args);}
    else{$pdo->prepare('INSERT INTO bom_materials(`'.implode('`,`',array_keys($values)).'`) VALUES('.implode(',',array_fill(0,count($values),'?')).')')->execute(array_values($values));$id=(int)$pdo->lastInsertId();}
    $event=(int)$state['price_version'];
    if($changed)$event=bl_event($pdo,$id,null,null,'price',$old['price']??null,$values['price'],$reason,$actor,$user,$source,$batch,['old_status'=>$state['price_status'],'new_status'=>$status,'old_standard'=>$state['confirmed_price'],'new_standard'=>$confirm?$values['price']:$state['confirmed_price']]);
    $identity=$values['unit']!==''&&!empty($d['identity_confirmed'])?'confirmed':$state['identity_status'];
    if($confirm&&$identity!=='confirmed')throw new RuntimeException('确认标准价前请勾选“身份与计价单位已核对”');
    $pdo->prepare('INSERT INTO bom_material_state(material_id,identity_status,price_status,confirmed_price,price_version,identity_key,origin) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE identity_status=VALUES(identity_status),price_status=VALUES(price_status),confirmed_price=VALUES(confirmed_price),price_version=VALUES(price_version),identity_key=VALUES(identity_key),revision=revision+1,updated_at=NOW()')->execute([$id,$identity,$status,$confirm?$values['price']:$state['confirmed_price'],$confirm?$event:$state['price_version'],bl_identity($values),$source]);
    return $id;
}
function bl_propagate(PDO $pdo,array $ids,string $actor,string $reason,string $batch,array $user=[]): array {
    if(!$ids)return ['projects'=>0];$marks=implode(',',array_fill(0,count($ids),'?'));
    $st=$pdo->prepare("SELECT DISTINCT project_uid FROM bom_material_usages WHERE material_id IN ($marks) ORDER BY project_uid");$st->execute(array_values($ids));$projects=[];
    foreach($st->fetchAll(PDO::FETCH_COLUMN) as $uid){$p=bw_get($pdo,$uid,true);if(!$p||(int)$p['is_active']!==1)continue;bl_publish($pdo,$p,$actor,$reason,$batch,$user);$projects[]=$p;}
    bl_policy_sync($pdo,$projects,$actor);return ['projects'=>count($projects)];
}
function bl_summary(PDO $pdo,array $p): array {
    $now=bl_current($pdo,$p);unset($now['rows']);$now['approval_cost']=null;
    if(!empty($p['latest_snapshot_id'])){$st=$pdo->prepare('SELECT totals_json FROM bom_snapshots WHERE id=? AND project_uid=?');$st->execute([$p['latest_snapshot_id'],$p['project_uid']]);$t=json_decode((string)$st->fetchColumn(),true);$now['approval_cost']=isset($t['total'])?(float)$t['total']:null;}
    $now['approval_delta']=$now['approval_cost']===null?null:round($now['cost']-$now['approval_cost'],4);return $now;
}
function bl_where_used(PDO $pdo,int $id,bool $canCost): array {
    return bmu_where_used($pdo,$id,$canCost);
}
function bl_price_history(PDO $pdo,array $d): array {
    $id=(int)($d['material_id']??0);$uid=trim((string)($d['project_uid']??''));if(!$id&&$uid==='')throw new RuntimeException('缺少物料或BOM标识');
    $page=max(1,(int)($d['page']??1));$where=$id?'material_id=?':'project_uid=?';$args=[$id?:$uid];
    $st=$pdo->prepare('SELECT COUNT(*) FROM bom_price_events WHERE '.$where);$st->execute($args);$count=(int)$st->fetchColumn();
    $st=$pdo->prepare('SELECT *,new_price-old_price AS delta FROM bom_price_events WHERE '.$where.' ORDER BY id DESC LIMIT 50 OFFSET '.(($page-1)*50));$st->execute($args);
    return ['ok'=>true,'events'=>$st->fetchAll(PDO::FETCH_ASSOC),'page'=>$page,'pages'=>max(1,(int)ceil($count/50)),'total'=>$count];
}
