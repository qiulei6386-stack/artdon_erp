<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$pdo = db();
$userId = (int)$pdo->query("SELECT id FROM crm_users WHERE is_super_admin=1 AND status NOT IN ('disabled','rejected','locked') ORDER BY id LIMIT 1")->fetchColumn();
if ($userId <= 0) throw new RuntimeException('No active super administrator is available for integration testing.');
$_SESSION['user_id'] = $userId;

require_once dirname(__DIR__) . '/crm_config.php';
require_once dirname(__DIR__) . '/crm_auth.php';
require_once dirname(__DIR__) . '/crm_log.php';
require_once dirname(__DIR__) . '/crm_customer.php';
require_once dirname(__DIR__) . '/crm_task_center.php';

crm_task_center_ensure_tables();
$quote = $pdo->query("SELECT id,quote_no FROM quote_orders ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$customerId = (int)$pdo->query("SELECT id FROM crm_customers WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1")->fetchColumn();
if (!$quote || $customerId <= 0) throw new RuntimeException('A legacy quote and customer are required for integration testing.');

$token = 'CODEX_QFH_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
$taskId = 0;
$activityId = 0;
$fileId = 0;

try {
    $pdo->prepare("INSERT INTO crm_tasks (task_type,title,description,source_type,source_id,customer_id,quote_id,assigned_user_id,collaborator_user_ids_json,priority,status,result,created_by,created_at,updated_at) VALUES ('quote_followup',?,?,?,?,?,?,?,JSON_ARRAY(),'normal','pending','',?,NOW(),NOW())")
        ->execute([$token,$token,'quote',(string)$quote['id'],$customerId,(string)$quote['quote_no'],$userId,$userId]);
    $taskId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO crm_quote_followup_activities (quote_source,quote_id,quote_no,task_id,customer_id,mode,channel,contacted_at,result,content,next_plan,customer_replied,created_by,created_at,updated_at) VALUES ('legacy',?,?,?,?,'online','email',NOW(),'waiting_reply',?,'',0,?,NOW(),NOW())")
        ->execute([(int)$quote['id'],(string)$quote['quote_no'],$taskId,$customerId,$token . '_before',$userId]);
    $activityId = (int)$pdo->lastInsertId();
    crm_quote_followup_sync_timeline($pdo, [
        'id'=>$activityId,'customer_id'=>$customerId,'quote_no'=>(string)$quote['quote_no'],
        'mode'=>'online','channel'=>'email','contacted_at'=>date('Y-m-d H:i:s'),'content'=>$token . '_before','created_by'=>$userId,
    ]);
    $pdo->prepare("INSERT INTO crm_quote_followup_files (activity_id,customer_id,file_name,original_name,file_path,file_size,mime_type,uploaded_by,uploaded_at) VALUES (?,?,?,?,?,0,'image/png',?,NOW())")
        ->execute([$activityId,$customerId,$token . '.png',$token . '.png','uploads/crm_quote_followups/' . $activityId . '/' . $token . '.png',$userId]);
    $fileId = (int)$pdo->lastInsertId();

    $updatedContent = $token . '_updated';
    crm_quote_followup_update([
        'activity_id'=>$activityId,'mode'=>'offline','channel'=>'phone','contacted_at'=>date('Y-m-d H:i:s', time() - 60),
        'result'=>'interested','content'=>$updatedContent,'next_plan'=>'integration next','customer_replied'=>1,
    ]);
    $activity = $pdo->query("SELECT * FROM crm_quote_followup_activities WHERE id=" . $activityId)->fetch(PDO::FETCH_ASSOC);
    if (($activity['content'] ?? '') !== $updatedContent || ($activity['channel'] ?? '') !== 'phone') throw new RuntimeException('Quote follow-up update did not persist.');
    $timelineCount = (int)$pdo->query("SELECT COUNT(*) FROM crm_customer_timeline WHERE related_type='quote_followup' AND CAST(related_id AS UNSIGNED)=" . $activityId . " AND detail LIKE " . $pdo->quote('%' . $updatedContent . '%'))->fetchColumn();
    if ($timelineCount !== 1) throw new RuntimeException('Quote follow-up timeline was not synchronized exactly once.');
    $taskStatus = (string)$pdo->query("SELECT status FROM crm_tasks WHERE id=" . $taskId)->fetchColumn();
    if ($taskStatus !== 'done') throw new RuntimeException('Quote follow-up task was not refreshed after update.');

    crm_quote_followup_delete(['activity_id'=>$activityId]);
    $deleted = $pdo->query("SELECT deleted_at FROM crm_quote_followup_activities WHERE id=" . $activityId)->fetchColumn();
    $fileDeleted = $pdo->query("SELECT deleted_at FROM crm_quote_followup_files WHERE id=" . $fileId)->fetchColumn();
    $timelineLeft = (int)$pdo->query("SELECT COUNT(*) FROM crm_customer_timeline WHERE related_type='quote_followup' AND CAST(related_id AS UNSIGNED)=" . $activityId)->fetchColumn();
    $task = $pdo->query("SELECT status,result,result_note FROM crm_tasks WHERE id=" . $taskId)->fetch(PDO::FETCH_ASSOC);
    if (!$deleted || !$fileDeleted || $timelineLeft !== 0) throw new RuntimeException('Quote follow-up soft delete did not clean its visible projections.');
    if (($task['status'] ?? '') !== 'pending' || ($task['result'] ?? '') !== '' || $task['result_note'] !== null) throw new RuntimeException('Quote follow-up task was not reset after deleting its final activity.');

} finally {
    if ($activityId > 0) {
        $pdo->prepare("DELETE FROM crm_operation_logs WHERE target_type='quote_followup' AND CAST(target_id AS UNSIGNED)=?")->execute([$activityId]);
        $pdo->prepare("DELETE FROM crm_customer_timeline WHERE related_type='quote_followup' AND CAST(related_id AS UNSIGNED)=?")->execute([$activityId]);
        $pdo->prepare("DELETE FROM crm_quote_followup_files WHERE activity_id=?")->execute([$activityId]);
        $pdo->prepare("DELETE FROM crm_quote_followup_activities WHERE id=?")->execute([$activityId]);
    }
    if ($taskId > 0) $pdo->prepare("DELETE FROM crm_tasks WHERE id=?")->execute([$taskId]);
}

$residue = 0;
if ($activityId > 0) {
    $residue += (int)$pdo->query("SELECT COUNT(*) FROM crm_quote_followup_activities WHERE id=" . $activityId)->fetchColumn();
    $residue += (int)$pdo->query("SELECT COUNT(*) FROM crm_quote_followup_files WHERE activity_id=" . $activityId)->fetchColumn();
    $residue += (int)$pdo->query("SELECT COUNT(*) FROM crm_customer_timeline WHERE related_type='quote_followup' AND CAST(related_id AS UNSIGNED)=" . $activityId)->fetchColumn();
    $residue += (int)$pdo->query("SELECT COUNT(*) FROM crm_operation_logs WHERE target_type='quote_followup' AND CAST(target_id AS UNSIGNED)=" . $activityId)->fetchColumn();
}
if ($taskId > 0) $residue += (int)$pdo->query("SELECT COUNT(*) FROM crm_tasks WHERE id=" . $taskId)->fetchColumn();
if ($residue !== 0) throw new RuntimeException('Quote follow-up history integration test left database residue.');

echo "CRM quote follow-up history actions integration: OK\n";
