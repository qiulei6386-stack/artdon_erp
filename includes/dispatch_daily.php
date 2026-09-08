<?php
declare(strict_types=1);

/** Daily reports are derived from immutable, transaction-bound task facts, not editable UI logs. */
function dd_fields(): array
{
    return ['id','task_no','task_type','dispatch_mode','parent_group_id','title','project','description','priority','status','created_by','assigned_to','task_date','due_at','completed_at','progress','is_deleted','created_at'];
}

function dd_snapshot_sql(string $alias): string
{
    if (!in_array($alias, ['NEW','OLD','t'], true)) throw new InvalidArgumentException('Invalid snapshot alias');
    $parts=[];
    foreach (dd_fields() as $field) { $parts[]="'{$field}'"; $parts[]="{$alias}.`{$field}`"; }
    return 'JSON_OBJECT('.implode(',', $parts).')';
}

/** Explicit CLI migration only; never DDL on a report request. Existing business rows are not changed. */
function dd_install(PDO $pdo): array
{
    if (!(int)$pdo->query("SELECT GET_LOCK('dispatch_daily_install_v1',10)")->fetchColumn()) throw new RuntimeException('日报初始化正在运行');
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS dispatch_daily_meta (id TINYINT PRIMARY KEY, started_at DATETIME(6) NOT NULL, version INT NOT NULL) ENGINE=InnoDB");
        $pdo->exec("CREATE TABLE IF NOT EXISTS dispatch_daily_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, task_id BIGINT UNSIGNED NOT NULL,
            owner_id INT NOT NULL, previous_owner_id INT NOT NULL, actor_id INT NOT NULL DEFAULT 0,
            event_type VARCHAR(16) NOT NULL, occurred_at DATETIME(6) NOT NULL,
            before_json JSON NULL, after_json JSON NULL,
            KEY dd_task (task_id,id), KEY dd_time (occurred_at,id),
            KEY dd_owner (owner_id,task_id), KEY dd_previous_owner (previous_owner_id,task_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS dispatch_daily_notes (
            report_date DATE NOT NULL,user_id INT NOT NULL,note TEXT NOT NULL,version INT NOT NULL DEFAULT 0,
            updated_at DATETIME(6) NOT NULL,PRIMARY KEY(report_date,user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $new=dd_snapshot_sql('NEW'); $old=dd_snapshot_sql('OLD');
        $ownerNew='IF(NEW.assigned_to>0,NEW.assigned_to,NEW.created_by)';
        $ownerOld='IF(OLD.assigned_to>0,OLD.assigned_to,OLD.created_by)';
        $columns='task_id,owner_id,previous_owner_id,actor_id,event_type,occurred_at,before_json,after_json';
        $actor='COALESCE(@dispatch_daily_actor,0)';
        $bodies=[
            'insert'=>"BEGIN INSERT INTO dispatch_daily_events ({$columns}) VALUES (NEW.id,{$ownerNew},0,{$actor},'create',UTC_TIMESTAMP(6),NULL,{$new}); END",
            'update'=>"BEGIN IF NOT (CAST({$old} AS BINARY) <=> CAST({$new} AS BINARY)) THEN INSERT INTO dispatch_daily_events ({$columns}) VALUES (NEW.id,{$ownerNew},{$ownerOld},{$actor},'update',UTC_TIMESTAMP(6),{$old},{$new}); END IF; END",
            'delete'=>"BEGIN INSERT INTO dispatch_daily_events ({$columns}) VALUES (OLD.id,0,{$ownerOld},{$actor},'delete',UTC_TIMESTAMP(6),{$old},NULL); END",
        ];
        foreach ($bodies as $event=>$body) {
            $name='dispatch_daily_'. $event .'_v1';
            $st=$pdo->prepare('SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?');$st->execute([$name]);
            $existing=$st->fetchColumn();
            if ($existing !== false && trim((string)$existing)!==trim($body)) throw new RuntimeException('已有日报触发器与版本不一致，停止初始化');
            if ($existing === false) $pdo->exec("CREATE TRIGGER {$name} AFTER ".strtoupper($event)." ON dispatch_next_tasks FOR EACH ROW {$body}");
        }
        // Progress notes matter even when the percentage is unchanged; record only successful writes.
        $task=dd_snapshot_sql('t');$owner='IF(t.assigned_to>0,t.assigned_to,t.created_by)';
        foreach (['insert','update','delete'] as $event) {
            $row=$event==='delete'?'OLD':'NEW';
            $before=$event==='insert'?'NULL':"JSON_SET({$task},'$.daily_comment',OLD.comment)";
            $after=$event==='delete'?'NULL':"JSON_SET({$task},'$.daily_comment',NEW.comment)";
            $who=$event==='insert'?'NEW.user_id':$actor;
            $insert="INSERT INTO dispatch_daily_events ({$columns}) SELECT t.id,{$owner},{$owner},{$who},'comment_{$event}',UTC_TIMESTAMP(6),{$before},{$after} FROM dispatch_next_tasks t WHERE t.id={$row}.task_id;";
            $body=$event==='update'?"BEGIN IF NOT (CAST(OLD.comment AS BINARY) <=> CAST(NEW.comment AS BINARY)) THEN {$insert} END IF; END":"BEGIN {$insert} END";
            $name='dispatch_daily_comment_'.$event.'_v1';
            $st=$pdo->prepare('SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?');$st->execute([$name]);$existing=$st->fetchColumn();
            if ($existing!==false && trim((string)$existing)!==trim($body)) throw new RuntimeException('已有进度日报触发器与版本不一致');
            if ($existing===false) $pdo->exec("CREATE TRIGGER {$name} AFTER ".strtoupper($event)." ON dispatch_next_comments FOR EACH ROW {$body}");
        }
        if (!$pdo->query('SELECT version FROM dispatch_daily_meta WHERE id=1')->fetchColumn()) {
            $pdo->beginTransaction();
            // Lock the existing task range while taking the initial checkpoint. Subsequent facts are trigger-atomic.
            $pdo->query('SELECT id FROM dispatch_next_tasks ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
            $started=(string)$pdo->query('SELECT UTC_TIMESTAMP(6)')->fetchColumn();
            $st=$pdo->prepare("INSERT INTO dispatch_daily_events ({$columns}) SELECT t.id,IF(t.assigned_to>0,t.assigned_to,t.created_by),0,0,'baseline',?,NULL,".dd_snapshot_sql('t').' FROM dispatch_next_tasks t');$st->execute([$started]);
            $pdo->prepare('INSERT INTO dispatch_daily_meta VALUES(1,?,1)')->execute([$started]);
            $pdo->commit();
        }
        return $pdo->query('SELECT started_at,version FROM dispatch_daily_meta WHERE id=1')->fetch();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    finally { $pdo->query("SELECT RELEASE_LOCK('dispatch_daily_install_v1')"); }
}

function dd_day(string $date, ?DateTimeImmutable $now=null): array
{
    $zone=new DateTimeZone('Asia/Shanghai');$now=($now ?: new DateTimeImmutable('now',$zone))->setTimezone($zone);
    $day=DateTimeImmutable::createFromFormat('!Y-m-d',$date,$zone);
    if (!$day || $day->format('Y-m-d')!==$date || $date>$now->format('Y-m-d')) throw new InvalidArgumentException('请选择有效的今天或历史日期');
    $end=$day->modify('+1 day');$utc=new DateTimeZone('UTC');
    return ['date'=>$date,'today'=>$now->format('Y-m-d'),'start'=>$day->setTimezone($utc)->format('Y-m-d H:i:s.u'),
        'end'=>$end->setTimezone($utc)->format('Y-m-d H:i:s.u'),'tomorrow'=>$end->format('Y-m-d'),'historical'=>$date<$now->format('Y-m-d')];
}

function dd_scope(array $input, int $uid, bool $admin): int
{
    if ($uid<=0) throw new RuntimeException('请先登录',401);
    $target=isset($input['user_id']) ? filter_var($input['user_id'],FILTER_VALIDATE_INT) : $uid;
    if ($target===false || $target<0) throw new InvalidArgumentException('人员参数无效');
    if (!$admin && $target!==$uid) throw new RuntimeException('只能查看自己的待办总结',403);
    return (int)$target; // Admin: zero means team, never normal-user wildcard.
}

function dd_text($value, int $length=240): string
{
    $text=trim(html_entity_decode(strip_tags((string)($value ?? '')), ENT_QUOTES | ENT_HTML5,'UTF-8'));
    return function_exists('mb_substr') ? mb_substr($text,0,$length,'UTF-8') : substr($text,0,$length);
}

function dd_owner(array $task): int { return (int)($task['assigned_to'] ?: $task['created_by']); }

function dd_task_card(array $t): array
{
    $card=array_intersect_key($t,array_flip(['id','task_no','task_type','dispatch_mode','parent_group_id','status','task_date','due_at','completed_at','progress']));
    $card['title']=dd_text($t['title']);$card['owner_id']=dd_owner($t);$card['id']=(int)$t['id'];
    return $card;
}

/** Pure aggregation, testable without production data. Counts never depend on client filters. */
function dd_aggregate(array $states, array $events, array $day, int $target): array
{
    $lists=['completed'=>[],'pending'=>[],'overdue'=>[],'tomorrow'=>[],'active'=>[],'changes'=>[]];$team=[];
    foreach ($states as $t) {
        if (!$t || !empty($t['is_deleted'])) continue;
        $owner=dd_owner($t);if ($target && $owner!==$target) continue;
        $card=dd_task_card($t);$status=(string)$t['status'];$due=substr((string)($t['due_at'] ?: $t['task_date']),0,10);
        $kind=null;
        if ($status==='done') { if (substr((string)$t['completed_at'],0,10)===$day['date']) $kind='completed'; }
        elseif ($status!=='cancelled') {
            if ($due===$day['date']) $kind='pending';
            elseif ($due!=='' && $due<$day['date']) {$kind='overdue';$card['overdue_days']=(int)(new DateTimeImmutable($due))->diff(new DateTimeImmutable($day['date']))->days;}
            elseif ($due===$day['tomorrow']) $kind='tomorrow';
            else $kind='active';
        }
        if ($kind) {$lists[$kind][]=$card;$team[$owner][$kind]=($team[$owner][$kind]??0)+1;}
    }
    $labels=['title'=>'标题','project'=>'任务内容','description'=>'详细说明','priority'=>'优先级','status'=>'状态','assigned_to'=>'负责人','task_date'=>'任务日期','due_at'=>'截止时间','completed_at'=>'完成时间','progress'=>'进度','is_deleted'=>'删除状态','task_type'=>'待办类型','dispatch_mode'=>'派工方式'];
    foreach ($events as $event) {
        if ($event['event_type']==='baseline') continue;
        if ($event['occurred_at']<$day['start'] || $event['occurred_at']>=$day['end']) continue;
        $before=$event['before']??null;$after=$event['after']??null;$t=$after ?: $before;if (!$t) continue;
        if ($target && (int)$event['owner_id']!==$target && (int)$event['previous_owner_id']!==$target) continue;
        $transferred=$target && $before && $after && dd_owner($before)!==dd_owner($after);
        if ($transferred) $t=dd_owner($after)===$target?$after:$before;
        $changes=[];
        if (strpos($event['event_type'],'comment_')===0) $changes[]=['field'=>'comment','label'=>['comment_insert'=>'新增进度','comment_update'=>'修改进度','comment_delete'=>'删除进度'][$event['event_type']]??'进度记录','before'=>dd_text($before['daily_comment']??'',400),'after'=>dd_text($after['daily_comment']??'',400)];
        elseif (!$before) $changes[]=['field'=>'create','label'=>'新增任务','before'=>'','after'=>'已创建'];
        elseif (!$after) $changes[]=['field'=>'delete','label'=>'删除任务','before'=>'存在','after'=>'已删除'];
        else foreach ($labels as $key=>$label) {
            // At an ownership boundary only disclose the transfer itself, never another owner's old/private text.
            if ($transferred && $key!=='assigned_to') continue;
            if ((string)($before[$key]??'')!==(string)($after[$key]??'')) $changes[]=['field'=>$key,'label'=>$label,'before'=>dd_text($before[$key]??'',400),'after'=>dd_text($after[$key]??'',400)];
        }
        if (!$changes) continue;
        $card=dd_task_card($t);$card['event_id']=(int)$event['id'];$card['actor_id']=(int)$event['actor_id'];
        $card['time']=(new DateTimeImmutable($event['occurred_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('H:i:s');
        $card['changes']=$changes;$lists['changes'][]=$card;
        $owners=array_unique(array_filter([(int)$event['owner_id'],(int)$event['previous_owner_id']]));
        foreach ($owners as $owner) $team[$owner]['changes']=($team[$owner]['changes']??0)+1;
    }
    usort($lists['changes'],static fn($a,$b)=>$b['event_id']<=>$a['event_id']);
    foreach (['pending','overdue','tomorrow','active'] as $key) usort($lists[$key],static fn($a,$b)=>strcmp((string)($a['due_at'] ?: $a['task_date']),(string)($b['due_at'] ?: $b['task_date'])) ?: $a['id']<=>$b['id']);
    return ['counts'=>array_map('count',$lists),'lists'=>$lists,'team'=>$team];
}

function dd_report(PDO $pdo, array $input, int $uid, bool $admin): array
{
    dd_scope($input,$uid,$admin);
    if ($pdo->inTransaction()) throw new RuntimeException('日报查询不能嵌套业务事务');
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('SET TRANSACTION READ ONLY');$pdo->beginTransaction();
    try {$result=dd_report_data($pdo,$input,$uid,$admin);$pdo->commit();return $result;}
    catch (Throwable $e) {if ($pdo->inTransaction()) $pdo->rollBack();throw $e;}
}

function dd_report_data(PDO $pdo, array $input, int $uid, bool $admin): array
{
    $target=dd_scope($input,$uid,$admin);$day=dd_day((string)($input['date']??(new DateTimeImmutable('now',new DateTimeZone('Asia/Shanghai')))->format('Y-m-d')));
    $ready=$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='dispatch_daily_meta'")->fetchColumn();
    if (!$ready) throw new RuntimeException('今日总结正在初始化，请稍后重试');
    $meta=$pdo->query('SELECT started_at FROM dispatch_daily_meta WHERE id=1')->fetch();
    if (!$meta) throw new RuntimeException('日报初始记录尚未完成');
    $started=(new DateTimeImmutable($meta['started_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i:s');
    if ($day['date']<substr($started,0,10)) throw new InvalidArgumentException('日报从 '.substr($started,0,10).' 开始记录，更早日期无法可靠还原');
    // Owner history determines eligible task IDs; final ownership is checked again by the reducer.
    $scope=$target ? " AND task_id IN (SELECT task_id FROM dispatch_daily_events WHERE owner_id={$target} UNION SELECT task_id FROM dispatch_daily_events WHERE previous_owner_id={$target})" : '';
    $st=$pdo->prepare("SELECT e.after_json FROM dispatch_daily_events e JOIN (SELECT MAX(id) id FROM dispatch_daily_events WHERE occurred_at<? AND event_type IN ('baseline','create','update','delete') {$scope} GROUP BY task_id) latest ON latest.id=e.id");$st->execute([$day['end']]);
    $states=[];while ($r=$st->fetch()) if ($r['after_json']!==null) $states[]=json_decode($r['after_json'],true,512,JSON_THROW_ON_ERROR);
    $scope=$target ? " AND (owner_id={$target} OR previous_owner_id={$target})" : '';
    $st=$pdo->prepare("SELECT id,task_id,owner_id,previous_owner_id,actor_id,event_type,occurred_at,before_json,after_json FROM dispatch_daily_events WHERE occurred_at>=? AND occurred_at<? AND occurred_at>=? {$scope} ORDER BY id");$st->execute([$day['start'],$day['end'],$meta['started_at']]);
    $events=[];while ($r=$st->fetch()) {$r['before']=$r['before_json']===null?null:json_decode($r['before_json'],true,512,JSON_THROW_ON_ERROR);$r['after']=$r['after_json']===null?null:json_decode($r['after_json'],true,512,JSON_THROW_ON_ERROR);unset($r['before_json'],$r['after_json']);$events[]=$r;}
    $result=dd_aggregate($states,$events,$day,$target);
    $names=[];$users=[];
    foreach ($pdo->query("SELECT id,COALESCE(NULLIF(real_name,''),username) name FROM crm_users ORDER BY id") as $row) {$names[(int)$row['id']]=$row['name'];if ($admin) $users[]=['id'=>(int)$row['id'],'name'=>$row['name']];}
    $section=(string)($input['section']??'completed');if (!isset($result['lists'][$section])) throw new InvalidArgumentException('日报分类无效');
    $pages=max(1,(int)ceil($result['counts'][$section]/20));$page=max(1,min($pages,(int)($input['page']??1)));
    $items=array_slice($result['lists'][$section],($page-1)*20,20);
    foreach ($items as &$item) {$item['owner_name']=$names[$item['owner_id']]??'未分配';$item['actor_name']=empty($item['actor_id'])?'系统 / 外部联动':($names[$item['actor_id']]??'历史账号');
        foreach ($item['changes']??[] as $i=>$change) if ($change['field']==='assigned_to') {foreach (['before','after'] as $side) $item['changes'][$i][$side]=$names[(int)$change[$side]]??'未分配';}}
    unset($item);
    $team=[];if (!$target) {
        $teamUsers=$users;
        foreach ($result['team'] as $owner=>$counts) if (!isset($names[$owner])) $teamUsers[]=['id'=>(int)$owner,'name'=>$owner?'历史账号 #'.$owner:'未分配'];
        foreach ($teamUsers as $u) $team[]=$u+['counts'=>array_replace(array_fill_keys(array_keys($result['counts']),0),$result['team'][$u['id']]??[])];
    }
    $note=['note'=>'','version'=>0];if ($target) {$st=$pdo->prepare('SELECT note,version FROM dispatch_daily_notes WHERE report_date=? AND user_id=?');$st->execute([$day['date'],$target]);$note=$st->fetch() ?: $note;}
    return ['date'=>$day['date'],'today'=>$day['today'],'historical'=>$day['historical'],'started_at'=>$started,'partial'=>$day['date']===substr($started,0,10),
        'user_id'=>$target,'user_name'=>$target?($names[$target]??'历史账号'):'全员汇总','is_admin'=>$admin,'users'=>$users,'counts'=>$result['counts'],
        'items'=>$items,'section'=>$section,'page'=>$page,'pages'=>$pages,'team'=>$team,'note'=>$note,'can_edit_note'=>$target===$uid && !$day['historical']];
}

function dd_save_note(PDO $pdo, array $input, int $uid): array
{
    $day=dd_day((string)($input['date']??''));
    if ($day['historical'] || (int)($input['user_id']??0)!==$uid || $uid<=0) throw new RuntimeException('只能补充自己当天的总结',403);
    $note=trim((string)($input['note']??''));if (strlen($note)>4000) throw new InvalidArgumentException('补充说明过长，请控制在约1000字内');
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT IGNORE INTO dispatch_daily_notes VALUES (?,?,'',0,UTC_TIMESTAMP(6))")->execute([$day['date'],$uid]);
        $st=$pdo->prepare('SELECT version FROM dispatch_daily_notes WHERE report_date=? AND user_id=? FOR UPDATE');$st->execute([$day['date'],$uid]);$v=(int)$st->fetchColumn();
        if ($v!==(int)($input['version']??-1)) throw new RuntimeException('说明已在其他窗口更新，请复制保留输入后刷新再修改',409);
        $pdo->prepare('UPDATE dispatch_daily_notes SET note=?,version=version+1,updated_at=UTC_TIMESTAMP(6) WHERE report_date=? AND user_id=?')->execute([$note,$day['date'],$uid]);
        $pdo->commit();return ['note'=>$note,'version'=>$v+1];
    } catch (Throwable $e) {if ($pdo->inTransaction()) $pdo->rollBack();throw $e;}
}
