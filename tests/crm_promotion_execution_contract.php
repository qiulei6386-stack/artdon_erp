<?php
/** No DB or application bootstrap. Verifies lifecycle integration and immutable queue rules. */
$root=dirname(__DIR__);
$execution=file_get_contents($root.'/crm_marketing_execution.php');
$marketing=file_get_contents($root.'/crm_marketing.php');
$delivery=file_get_contents($root.'/crm_marketing_delivery.php');
$js=file_get_contents($root.'/assets/crm/crm.js');
$composer=file_get_contents($root.'/assets/crm/promotion-composer.js');
$task=file_get_contents($root.'/crm_task_center.php');
$notifications=file_get_contents($root.'/notification_service.php');
foreach ([
    [$execution,"'mail_sent'=>0"],[$execution,"'manual_remediated_count'=>0"],
    [$execution,"source_type='marketing_target'"],[$execution,'ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)'],
    [$marketing,'crm_promotion_execution_summaries($stmt->fetchAll())'],[$marketing,'crm_promotion_refresh_status($taskId)'],
    [$marketing,'crm_marketing_manual_execute_locked'],[$marketing,'crm_marketing_with_task_lock'],
    [$marketing,'SELECT q.id'],[$marketing,'WHERE q.id=?'],[$marketing,'$expandedGroupIds[$chatGroupId]'],
    [$delivery,"'channel_basis'=>crm_delivery_channel_basis"],[$delivery,"\$meta['frozen_vars']"],[$delivery,'冻结内容校验失败'],
    [$delivery,"\$signatureKey!=='personal'"],[$delivery,'crm_promotion_create_manual_task'],
    [$js,'data-promo-manual-result'],[$js,'manual_result:actualResult'],[$js,"manual_pending: '待人工执行'"],
    [$js,'data-promo-execution-content'],[$composer,'实际发件账号签名（逐邮箱核对）'],
    [$task,"crm_marketing_manual_execute(['task_id'=>\$promotionId"],[$notifications,"source_type='marketing_target' AND reminder_at<=NOW()"],
] as [$source,$needle]) if (strpos($source,$needle)===false) throw new RuntimeException('Missing execution guard: '.$needle);
if (strpos($js,"return '计划 ' + time + ' 送达")!==false) throw new RuntimeException('Start time must not claim delivery');
echo "Promotion execution integration contracts passed.\n";
