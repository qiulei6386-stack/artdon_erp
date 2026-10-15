<?php
declare(strict_types=1);

/**
 * 邮箱测试用的表（SQLite），字段与正式库的 crm_user_mail_accounts、crm_mails、crm_mail_attachments、crm_mail_drafts、crm_mail_send_jobs 一致。
 * 需要先建好客户中心的表（customer_test_schema），这里会替换掉其中只有几个字段的 crm_mails。
 */

function mail_test_schema(PDO $pdo): void
{
    $pdo->exec('DROP TABLE IF EXISTS crm_mails');
    foreach ([
        'CREATE TABLE crm_user_mail_accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, email_address VARCHAR(190) NOT NULL, email_username VARCHAR(190) NULL,
            email_password_encrypted TEXT NULL, imap_host VARCHAR(190) NOT NULL DEFAULT \'imap.exmail.qq.com\', imap_port INTEGER NOT NULL DEFAULT 993, imap_secure VARCHAR(20) NOT NULL DEFAULT \'ssl\',
            smtp_host VARCHAR(190) NOT NULL DEFAULT \'smtp.exmail.qq.com\', smtp_port INTEGER NOT NULL DEFAULT 465, smtp_secure VARCHAR(20) NOT NULL DEFAULT \'ssl\', sender_name VARCHAR(190) NULL,
            signature_id INTEGER NULL, signature_html MEDIUMTEXT NULL, delay_send_minutes INTEGER NOT NULL DEFAULT 0, is_enabled INTEGER NOT NULL DEFAULT 1, is_default INTEGER NOT NULL DEFAULT 0,
            last_sync_time DATETIME NULL, sync_status VARCHAR(40) NULL, sync_error TEXT NULL, sync_fail_count INTEGER NOT NULL DEFAULT 0, sync_backoff_until DATETIME NULL,
            created_at DATETIME NULL, updated_at DATETIME NULL, deleted_at DATETIME NULL)',
        'CREATE TABLE crm_mails (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, mail_account_id INTEGER NOT NULL, message_uid VARCHAR(190) NOT NULL, message_id_header VARCHAR(500) NULL,
            folder VARCHAR(40) NOT NULL DEFAULT \'inbox\', subject VARCHAR(500) NULL, from_email VARCHAR(190) NULL, from_name VARCHAR(190) NULL, to_emails TEXT NULL, cc_emails TEXT NULL, bcc_emails TEXT NULL,
            received_at DATETIME NULL, sent_at DATETIME NULL, body_html MEDIUMTEXT NULL, body_text MEDIUMTEXT NULL, body_status VARCHAR(40) NULL, has_body INTEGER NOT NULL DEFAULT 1,
            has_attachment INTEGER NOT NULL DEFAULT 0, attachment_count INTEGER NOT NULL DEFAULT 0, is_read INTEGER NOT NULL DEFAULT 0, is_replied INTEGER NOT NULL DEFAULT 0, is_deleted INTEGER NOT NULL DEFAULT 0,
            is_archived INTEGER NOT NULL DEFAULT 0, is_starred INTEGER NOT NULL DEFAULT 0, is_important INTEGER NOT NULL DEFAULT 0, is_unreplied INTEGER NOT NULL DEFAULT 0,
            linked_customer_id INTEGER NULL, linked_contact_id INTEGER NULL, customer_match_json TEXT NULL, tags_json TEXT NULL, raw_headers_json TEXT NULL, raw_headers MEDIUMTEXT NULL, raw_eml_path VARCHAR(500) NULL,
            parse_status VARCHAR(40) NULL, parse_error TEXT NULL, mail_source VARCHAR(40) NOT NULL DEFAULT \'imap_inbox\', source_flags_json TEXT NULL, crm_send_id VARCHAR(190) NULL, body_hash VARCHAR(64) NULL,
            imap_mailbox VARCHAR(190) NULL, send_status VARCHAR(40) NULL, mail_category VARCHAR(40) NULL, followup_status VARCHAR(40) NULL, next_followup_at DATETIME NULL,
            created_at DATETIME NULL, updated_at DATETIME NULL, UNIQUE (mail_account_id, folder, message_uid))',
        'CREATE TABLE crm_mail_attachments (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, mail_id INTEGER NOT NULL, file_name VARCHAR(255) NULL, filename VARCHAR(255) NULL, original_filename VARCHAR(500) NULL,
            file_path VARCHAR(500) NULL, file_size INTEGER NOT NULL DEFAULT 0, mime_type VARCHAR(190) NULL, attachment_type VARCHAR(40) NULL, message_id VARCHAR(255) NULL, content_id VARCHAR(255) NULL,
            is_inline INTEGER NOT NULL DEFAULT 0, is_signature_image INTEGER NOT NULL DEFAULT 0, preview_status VARCHAR(40) NOT NULL DEFAULT \'pending\', created_at DATETIME NULL)',
        'CREATE TABLE crm_mail_drafts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, mail_account_id INTEGER NULL, reply_to_mail_id INTEGER NULL, mode VARCHAR(20) NULL,
            to_emails TEXT NULL, cc_emails TEXT NULL, bcc_emails TEXT NULL, subject VARCHAR(500) NULL, body_html MEDIUMTEXT NULL, attachments_json TEXT NULL, draft_meta_json TEXT NULL,
            linked_customer_id INTEGER NULL, linked_contact_id INTEGER NULL, auto_saved INTEGER NOT NULL DEFAULT 0, updated_at DATETIME NULL, created_at DATETIME NULL)',
        'CREATE TABLE crm_mail_send_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, job_id VARCHAR(120) NOT NULL UNIQUE, user_id INTEGER NOT NULL, mail_account_id INTEGER NOT NULL, status VARCHAR(40) NOT NULL DEFAULT \'scheduled\',
            scheduled_at DATETIME NULL, payload_json MEDIUMTEXT NULL, attachments_json TEXT NULL, sent_mail_id INTEGER NULL, error_message TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)',
    ] as $sql) {
        $pdo->exec($sql);
    }
}

/** 测试用插入一封邮件，返回编号。 */
function mail_test_insert(PDO $pdo, array $row): int
{
    static $n = 0;
    $n++;
    $row += ['message_uid' => 'uid-' . $n, 'folder' => 'inbox', 'subject' => 'Mail ' . $n, 'from_email' => 'x@example.com', 'to_emails' => '', 'is_read' => 0,
        'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')];
    $cols = array_keys($row);
    $pdo->prepare('INSERT INTO crm_mails (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')->execute(array_values($row));
    return (int) $pdo->lastInsertId();
}
