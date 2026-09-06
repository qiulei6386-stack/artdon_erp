<?php

/** Preserve legacy sent-mail visibility, without re-reading large body columns
 * for every correlated comparison or running that work twice for count/page.
 * Every source is restricted to the already-authorized account and owner.
 */
function crm_mail_sent_duplicate_query(): string
{
    $normalize = "LOWER(REPLACE(REPLACE(TRIM(message_id_header), '<', ''), '>', ''))";
    $sendPattern = "'send\\_%'";
    // LIMIT prevents MySQL 5.7 derived-table merging: only lean metadata is
    // materialized, never body_html/body_text or raw message contents.
    $thin = "SELECT id,user_id,mail_account_id,folder,is_deleted,message_uid,message_id_header,crm_send_id,mail_source,subject,to_emails,body_hash,sent_at,created_at,{$normalize} AS message_key
        FROM crm_mails WHERE user_id=? AND mail_account_id=? AND folder='sent' AND is_deleted=0";
    $senderThin = $thin . " AND (message_uid LIKE {$sendPattern} OR crm_send_id IS NOT NULL OR mail_source='crm_sent') LIMIT 18446744073709551615";
    $thin .= ' LIMIT 18446744073709551615';
    $groups = "SELECT {$normalize} AS message_key,MIN(id) AS keep_id FROM crm_mails
        WHERE user_id=? AND mail_account_id=? AND folder='sent' AND is_deleted=0
        AND message_id_header IS NOT NULL AND message_id_header<>''
        GROUP BY message_key HAVING COUNT(*)>1";
    return "SELECT m.id FROM ({$thin}) m
        LEFT JOIN ({$groups}) d ON d.message_key=m.message_key AND d.keep_id<m.id
        WHERE NOT ((m.message_id_header IS NULL OR m.message_id_header='' OR d.keep_id IS NULL)
        AND NOT EXISTS (SELECT 1 FROM ({$senderThin}) m2 WHERE m2.id<>m.id AND m.message_uid NOT LIKE {$sendPattern} AND (
          (m2.subject=m.subject AND m2.to_emails=m.to_emails AND ABS(TIMESTAMPDIFF(MINUTE,COALESCE(m2.sent_at,m2.created_at),COALESCE(m.sent_at,m.created_at)))<=10)
          OR (m2.subject=m.subject AND m.body_hash IS NOT NULL AND m.body_hash<>'' AND m2.body_hash=m.body_hash AND ABS(TIMESTAMPDIFF(MINUTE,COALESCE(m2.sent_at,m2.created_at),COALESCE(m.sent_at,m.created_at)))<=10)
          OR (m.message_id_header IS NOT NULL AND m.message_id_header<>'' AND m2.message_id_header IS NOT NULL AND m2.message_id_header<>'' AND LOCATE(m2.message_key,m.message_key)>0)
        )))";
}

function crm_mail_sent_duplicate_ids(array $account): array
{
    $owner = (int)($account['user_id'] ?? 0);
    $id = (int)($account['id'] ?? 0);
    if ($owner <= 0 || $id <= 0) throw new RuntimeException('邮箱范围无效。');
    $stmt = db()->prepare(crm_mail_sent_duplicate_query());
    $stmt->execute([$owner, $id, $owner, $id, $owner, $id]);
    return array_values(array_unique(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
}
