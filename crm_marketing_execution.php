<?php
/** Execution facts: SMTP and human work are separate, including historical tasks. */
function crm_promotion_execution_summaries(array $tasks): array
{
    if (!$tasks) return [];
    $ids = array_map('intval', array_column($tasks, 'id'));
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stats = [];
    foreach ($ids as $id) $stats[$id] = ['mail_sent'=>0,'mail_failed'=>0,'mail_active'=>0,'mail_due'=>0,'manual_success'=>0,'manual_failed'=>0,'manual_pending_count'=>0,'manual_remediated_count'=>0,'skipped_count'=>0,'last_sent_at'=>null];
    $s = db()->prepare("SELECT task_id,send_status,COUNT(*) n,SUM(planned_server_time<=NOW()) due,MAX(sent_at) last_sent_at FROM crm_marketing_send_queue WHERE task_id IN ($marks) GROUP BY task_id,send_status");
    $s->execute($ids);
    foreach ($s->fetchAll() as $r) {
        $v = &$stats[(int)$r['task_id']];
        if ($r['send_status']==='sent') { $v['mail_sent']+=(int)$r['n']; $v['last_sent_at']=$r['last_sent_at']; }
        elseif ($r['send_status']==='failed') $v['mail_failed']+=(int)$r['n'];
        elseif (in_array($r['send_status'],['pending','scheduled','sending','waiting_retry'],true)) { $v['mail_active']+=(int)$r['n']; $v['mail_due']+=(int)$r['due']; }
        unset($v);
    }
    $s = db()->prepare("SELECT task_id,channel_key,target_status,COUNT(*) n,SUM(manual_checked_by_user_id IS NOT NULL) manual_checked FROM crm_marketing_task_targets WHERE task_id IN ($marks) GROUP BY task_id,channel_key,target_status");
    $s->execute($ids);
    foreach ($s->fetchAll() as $r) {
        $v = &$stats[(int)$r['task_id']]; $n=(int)$r['n'];
        if ($r['target_status']==='skipped') $v['skipped_count']+=$n;
        if (crm_marketing_is_email_channel($r['channel_key'])) {
            if ($r['target_status']==='success') $v['manual_remediated_count']+=(int)$r['manual_checked'];
        } elseif (crm_marketing_is_manual_channel($r['channel_key'])) {
            if ($r['target_status']==='success') $v['manual_success']+=$n;
            elseif ($r['target_status']==='failed') $v['manual_failed']+=$n;
            elseif ($r['target_status']==='pending') $v['manual_pending_count']+=$n;
        }
        unset($v);
    }
    foreach ($tasks as &$t) {
        $v=$stats[(int)$t['id']]; $t=array_merge($t,$v);
        $t['success_count']=$v['mail_sent']+$v['manual_success'];
        $t['failed_count']=$v['mail_failed']+$v['manual_failed'];
        if (!in_array($t['task_status'],['draft','paused','cancelled'],true)) {
            $t['task_status']=$v['mail_active'] ? ($v['mail_due']?'running':'scheduled') : ($v['manual_pending_count'] ? 'manual_pending' : ($t['failed_count'] ? ($t['success_count']?'partial_failed':'failed') : 'completed'));
        }
    }
    unset($t);
    return $tasks;
}

function crm_promotion_refresh_status(int $taskId): void
{
    $s=db()->prepare('SELECT id,task_status FROM crm_marketing_tasks WHERE id=?'); $s->execute([$taskId]);
    $task=$s->fetch(); if (!$task) return;
    $task=crm_promotion_execution_summaries([$task])[0];
    db()->prepare("UPDATE crm_marketing_tasks SET task_status=CASE WHEN task_status IN ('draft','paused','cancelled') THEN task_status ELSE ? END,success_count=?,failed_count=?,updated_at=NOW() WHERE id=?")->execute([$task['task_status'],$task['success_count'],$task['failed_count'],$taskId]);
}

/** Existing task-center schema, unique (created_by,request_token). No DDL inside confirmation. */
function crm_promotion_create_manual_task(array $task, array $item): void
{
    $id=(int)$item['target_id'];
    $description='推广 #'.(int)$task['id'].' · '.$item['channel'].' · '.$item['contact_method']."\n".
        '请实际联系后填写结果；选择对象不代表自动发送。'."\n".
        mb_substr(html_entity_decode(strip_tags(preg_replace('/<br\s*\/?\s*>|<\/p>/i',"\n",(string)($item['body_html'] ?? ''))),ENT_QUOTES,'UTF-8'),0,4000);
    db()->prepare("INSERT INTO crm_tasks (task_type,title,description,source_type,source_id,customer_id,contact_id,assigned_user_id,priority,status,due_at,reminder_at,request_token,created_by,created_at,updated_at)
        VALUES (?,?,?,'marketing_target',?,?,?,?,'important','pending',?,?,?, ?,NOW(),NOW())
        ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)")
        ->execute([in_array($item['channel'],['wechat_group','whatsapp_group'],true)?'group_promotion':'promotion_manual',mb_substr($task['task_name'].' · '.$item['customer_name'],0,250),$description,(string)$id,$item['customer_id'],$item['contact_id']?:null,$item['executor_id'],date('Y-m-d H:i:s',strtotime($item['planned_at'])+86400),$item['planned_at'],'promotion_target_'.$id,$task['created_by']]);
}

function crm_promotion_manual_content(array $input): array
{
    crm_require('promotion.execute');
    $s=db()->prepare('SELECT mt.*,t.created_by FROM crm_marketing_task_targets mt JOIN crm_marketing_tasks t ON t.id=mt.task_id WHERE mt.id=?');
    $s->execute([(int)($input['target_id'] ?? 0)]); $target=$s->fetch();
    if (!$target) throw new RuntimeException('推广对象不存在。');
    $actor=(int)(current_user()['id'] ?? 0);
    if ((int)$target['executor_user_id']!==$actor && (int)$target['created_by']!==$actor && !is_super_admin() && !crm_can('promotion.manage')) throw new RuntimeException('无权查看此执行对象。');
    $s=db()->prepare('SELECT manifest FROM crm_marketing_delivery_previews WHERE task_id=? AND confirmed_at IS NOT NULL ORDER BY confirmed_at DESC LIMIT 1');
    $s->execute([$target['task_id']]); $manifest=json_decode((string)$s->fetchColumn(),true) ?: [];
    foreach ($manifest['items'] ?? [] as $item) if ((int)$item['target_id']===(int)$target['id']) {
        $item=crm_delivery_expand_item($manifest,$item);
        $plain=preg_replace('/<style\b[^>]*>.*?<\/style>/is','',$item['body_html']);
        $plain=preg_replace('/<br\s*\/?\s*>|<\/p>/i',"\n",$plain);
        return ['channel'=>$item['channel'],'channel_basis'=>$item['channel_basis'] ?? '历史预览未记录判定来源','contact_method'=>$item['contact_method'] ?? $item['receiver_email'] ?? '',
            'content'=>trim(html_entity_decode(strip_tags($plain),ENT_QUOTES,'UTF-8')),'has_images'=>stripos($item['body_html'],'<img')!==false];
    }
    throw new RuntimeException('该对象未进入已确认的执行预览；请先处理排除原因，不能使用未确认内容。');
}

function crm_promotion_sync_manual_result(int $targetId, string $status, string $result, string $note, int $actor): void
{
    db()->prepare("UPDATE crm_tasks SET status=?,result=?,result_note=?,completed_at=IF(?='success',NOW(),NULL),completed_by=?,updated_at=NOW() WHERE source_type='marketing_target' AND source_id=? AND deleted_at IS NULL")
        ->execute([$status==='success'?'done':($status==='skipped'?'cancelled':'pending'),mb_substr($result,0,120),$note,$status,$actor,(string)$targetId]);
}

/** CLI-only maintenance: attach reminders to already-confirmed manual work, never add recipients. */
function crm_promotion_repair_manual_tasks(int $taskId, bool $apply=false): array
{
    if (PHP_SAPI!=='cli') throw new RuntimeException('仅允许受控维护入口。');
    return crm_marketing_with_task_lock($taskId,static function($task) use($taskId,$apply) {
        if (in_array($task['task_status'],['draft','paused','cancelled'],true)) throw new RuntimeException('当前项目状态不允许补齐待办。');
        $s=db()->prepare('SELECT manifest FROM crm_marketing_delivery_previews WHERE task_id=? AND confirmed_at IS NOT NULL ORDER BY confirmed_at DESC LIMIT 1');
        $s->execute([$taskId]); $manifest=json_decode((string)$s->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
        $target=db()->prepare('SELECT * FROM crm_marketing_task_targets WHERE id=? AND task_id=? FOR UPDATE');
        $existing=db()->prepare("SELECT id FROM crm_tasks WHERE created_by=? AND request_token=?");
        $missing=[]; $unchanged=0; $mismatch=0;
        foreach($manifest['items'] ?? [] as $item) {
            if (($item['mode'] ?? '')!=='manual') continue;
            $target->execute([$item['target_id'],$taskId]); $row=$target->fetch();
            if (!$row || !in_array($row['target_status'],['pending','failed'],true)) continue;
            if (!crm_marketing_is_manual_channel($row['channel_key']) || $row['channel_key']!==$item['channel'] || (int)$row['executor_user_id']!==(int)$item['executor_id'] || (int)$item['executor_id']<=0 || (int)$row['customer_id']!==(int)$item['customer_id'] || (int)$row['contact_id']!==(int)$item['contact_id']) { $mismatch++; continue; }
            $existing->execute([$task['created_by'],'promotion_target_'.(int)$item['target_id']]);
            if ($existing->fetchColumn()) { $unchanged++; continue; }
            $missing[]=(int)$item['target_id'];
            if ($apply) crm_promotion_create_manual_task($task,crm_delivery_expand_item($manifest,$item));
        }
        if ($apply) {
            crm_promotion_refresh_status($taskId);
            if($missing)db()->prepare("INSERT INTO crm_marketing_logs(task_id,action_key,result_status,detail_json,touched_at,created_at) VALUES(?,'manual_task_backfill','success',?,NOW(),NOW())")->execute([$taskId,json_encode(['target_ids'=>$missing,'source'=>'confirmed_preview','queue_changed'=>false])]);
        }
        return ['task_id'=>$taskId,'apply'=>$apply,'missing_count'=>count($missing),'existing_count'=>$unchanged,'mismatch_count'=>$mismatch,'target_ids'=>$missing,'mail_queue_changed'=>false];
    });
}
