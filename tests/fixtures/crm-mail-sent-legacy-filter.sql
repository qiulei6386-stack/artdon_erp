NOT EXISTS (
            SELECT 1 FROM crm_mails m2
            WHERE m2.user_id = m.user_id
              AND m2.mail_account_id = m.mail_account_id
              AND m2.folder = 'sent'
              AND m2.is_deleted = 0
              AND m2.id <> m.id
              AND (
                (m.message_id_header IS NOT NULL AND m.message_id_header <> '' AND m2.message_id_header IS NOT NULL AND m2.message_id_header <> '' AND LOWER(REPLACE(REPLACE(TRIM(m2.message_id_header), '<', ''), '>', '')) = LOWER(REPLACE(REPLACE(TRIM(m.message_id_header), '<', ''), '>', '')) AND m2.id < m.id)
                OR
                (m.message_uid NOT LIKE 'send\_%' AND (m2.message_uid LIKE 'send\_%' OR m2.crm_send_id IS NOT NULL OR m2.mail_source = 'crm_sent') AND m2.subject = m.subject AND m2.to_emails = m.to_emails AND ABS(TIMESTAMPDIFF(MINUTE, COALESCE(m2.sent_at, m2.created_at), COALESCE(m.sent_at, m.created_at))) <= 10)
                OR
                (m.message_uid NOT LIKE 'send\_%' AND (m2.message_uid LIKE 'send\_%' OR m2.crm_send_id IS NOT NULL OR m2.mail_source = 'crm_sent') AND m2.subject = m.subject AND m.body_hash IS NOT NULL AND m.body_hash <> '' AND m2.body_hash = m.body_hash AND ABS(TIMESTAMPDIFF(MINUTE, COALESCE(m2.sent_at, m2.created_at), COALESCE(m.sent_at, m.created_at))) <= 10)
                OR
                (m.message_uid NOT LIKE 'send\_%' AND (m2.message_uid LIKE 'send\_%' OR m2.crm_send_id IS NOT NULL OR m2.mail_source = 'crm_sent') AND m.message_id_header IS NOT NULL AND m.message_id_header <> '' AND m2.message_id_header IS NOT NULL AND m2.message_id_header <> '' AND LOCATE(LOWER(REPLACE(REPLACE(TRIM(m2.message_id_header), '<', ''), '>', '')), LOWER(REPLACE(REPLACE(TRIM(m.message_id_header), '<', ''), '>', ''))) > 0)
              )
        )
