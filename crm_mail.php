<?php
require_once __DIR__ . '/crm_settings_config.php';

function crm_mail_key(): string
{
    $config = function_exists('db_config') ? db_config() : [];
    $seed = (string)($config['app_key'] ?? $config['db_name'] ?? __DIR__);
    return hash('sha256', $seed, true);
}

function crm_mail_encrypt(string $value): string
{
    if ($value === '') return '';
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($value, 'AES-256-CBC', crm_mail_key(), OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . ($cipher ?: ''));
}

function crm_mail_decrypt(?string $value): string
{
    $value = (string)$value;
    if ($value === '') return '';
    $raw = base64_decode($value, true);
    if ($raw === false || strlen($raw) <= 16) return '';
    $iv = substr($raw, 0, 16);
    $cipher = substr($raw, 16);
    $plain = openssl_decrypt($cipher, 'AES-256-CBC', crm_mail_key(), OPENSSL_RAW_DATA, $iv);
    return is_string($plain) ? $plain : '';
}

function crm_mail_ensure_tables(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    db()->exec("CREATE TABLE IF NOT EXISTS crm_user_mail_accounts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        email_address VARCHAR(190) NOT NULL,
        email_username VARCHAR(190) NOT NULL,
        email_password_encrypted TEXT NULL,
        imap_host VARCHAR(190) NOT NULL DEFAULT 'imap.exmail.qq.com',
        imap_port INT NOT NULL DEFAULT 993,
        imap_secure VARCHAR(20) NOT NULL DEFAULT 'ssl',
        smtp_host VARCHAR(190) NOT NULL DEFAULT 'smtp.exmail.qq.com',
        smtp_port INT NOT NULL DEFAULT 465,
        smtp_secure VARCHAR(20) NOT NULL DEFAULT 'ssl',
        sender_name VARCHAR(190) NULL,
        signature_id INT UNSIGNED NULL,
        signature_html MEDIUMTEXT NULL,
        delay_send_minutes INT NOT NULL DEFAULT 0,
        is_enabled TINYINT(1) NOT NULL DEFAULT 1,
        last_sync_time DATETIME NULL,
        sync_status VARCHAR(40) NOT NULL DEFAULT 'never',
        sync_error VARCHAR(500) NULL,
        sync_fail_count INT NOT NULL DEFAULT 0,
        sync_backoff_until DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_mail_account_user (user_id),
        KEY idx_mail_account_email (email_address)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    crm_mail_ensure_account_schema();

    db()->exec("CREATE TABLE IF NOT EXISTS crm_mails (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        mail_account_id INT UNSIGNED NOT NULL,
        message_uid VARCHAR(190) NULL,
        message_id_header VARCHAR(255) NULL,
        folder VARCHAR(80) NOT NULL DEFAULT 'inbox',
        subject VARCHAR(500) NULL,
        from_email VARCHAR(190) NULL,
        from_name VARCHAR(190) NULL,
        to_emails TEXT NULL,
        cc_emails TEXT NULL,
        bcc_emails TEXT NULL,
        received_at DATETIME NULL,
        sent_at DATETIME NULL,
        body_html MEDIUMTEXT NULL,
        body_text MEDIUMTEXT NULL,
        body_status VARCHAR(80) NOT NULL DEFAULT 'normal',
        has_body TINYINT(1) NOT NULL DEFAULT 1,
        has_attachment TINYINT(1) NOT NULL DEFAULT 0,
        attachment_count INT NOT NULL DEFAULT 0,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        is_replied TINYINT(1) NOT NULL DEFAULT 0,
        is_deleted TINYINT(1) NOT NULL DEFAULT 0,
        is_archived TINYINT(1) NOT NULL DEFAULT 0,
        is_starred TINYINT(1) NOT NULL DEFAULT 0,
        is_important TINYINT(1) NOT NULL DEFAULT 0,
        is_unreplied TINYINT(1) NOT NULL DEFAULT 0,
        linked_customer_id INT UNSIGNED NULL,
        linked_contact_id INT UNSIGNED NULL,
        customer_match_json JSON NULL,
        tags_json JSON NULL,
        raw_headers_json JSON NULL,
        raw_headers MEDIUMTEXT NULL,
        raw_eml_path VARCHAR(500) NULL,
        parse_status VARCHAR(40) NOT NULL DEFAULT 'parsed',
        parse_error TEXT NULL,
        mail_source VARCHAR(40) NOT NULL DEFAULT 'imap_inbox',
        source_flags_json JSON NULL,
        crm_send_id VARCHAR(80) NULL,
        body_hash CHAR(40) NULL,
        imap_mailbox VARCHAR(190) NULL,
        send_status VARCHAR(40) NULL,
        mail_category VARCHAR(40) NOT NULL DEFAULT 'normal',
        followup_status VARCHAR(40) NOT NULL DEFAULT 'open',
        next_followup_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_mail_uid (mail_account_id, folder, message_uid),
        KEY idx_mail_user_folder (user_id, folder),
        KEY idx_mail_received (received_at),
        KEY idx_mail_customer (linked_customer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS crm_mail_attachments (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        mail_id BIGINT UNSIGNED NOT NULL,
        file_name VARCHAR(255) NOT NULL,
        filename VARCHAR(255) NULL,
        original_filename VARCHAR(500) NULL,
        file_path VARCHAR(500) NULL,
        file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
        mime_type VARCHAR(120) NULL,
        attachment_type VARCHAR(40) NOT NULL DEFAULT 'normal',
        message_id VARCHAR(255) NULL,
        content_id VARCHAR(190) NULL,
        is_inline TINYINT(1) NOT NULL DEFAULT 0,
        is_signature_image TINYINT(1) NOT NULL DEFAULT 0,
        preview_status VARCHAR(40) NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_mail_attach_mail (mail_id),
        KEY idx_mail_attach_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS crm_mail_drafts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        mail_account_id INT UNSIGNED NOT NULL,
        reply_to_mail_id BIGINT UNSIGNED NULL,
        mode VARCHAR(40) NOT NULL DEFAULT 'compose',
        to_emails TEXT NULL,
        cc_emails TEXT NULL,
        bcc_emails TEXT NULL,
        subject VARCHAR(500) NULL,
        body_html MEDIUMTEXT NULL,
        attachments_json JSON NULL,
        linked_customer_id INT UNSIGNED NULL,
        linked_contact_id INT UNSIGNED NULL,
        auto_saved TINYINT(1) NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_mail_draft_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS crm_mail_sync_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        mail_account_id INT UNSIGNED NULL,
        sync_id VARCHAR(80) NOT NULL,
        stage VARCHAR(80) NOT NULL DEFAULT 'created',
        percent INT NOT NULL DEFAULT 0,
        current_count INT NOT NULL DEFAULT 0,
        total_count INT NOT NULL DEFAULT 0,
        new_count INT NOT NULL DEFAULT 0,
        duplicate_count INT NOT NULL DEFAULT 0,
        attachment_count INT NOT NULL DEFAULT 0,
        skipped_count INT NOT NULL DEFAULT 0,
        failed_count INT NOT NULL DEFAULT 0,
        folders_json JSON NULL,
        status VARCHAR(40) NOT NULL DEFAULT 'running',
        message VARCHAR(500) NULL,
        error_message VARCHAR(500) NULL,
        started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        finished_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_mail_sync_user (user_id),
        KEY idx_mail_sync_id (sync_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS crm_mail_send_jobs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        mail_account_id INT UNSIGNED NULL,
        job_id VARCHAR(80) NOT NULL,
        draft_id BIGINT UNSIGNED NULL,
        to_emails TEXT NULL,
        subject VARCHAR(500) NULL,
        status VARCHAR(40) NOT NULL DEFAULT 'running',
        stage VARCHAR(80) NOT NULL DEFAULT 'created',
        percent INT NOT NULL DEFAULT 0,
        error_message VARCHAR(500) NULL,
        scheduled_at DATETIME NULL,
        payload_json JSON NULL,
        attachments_json JSON NULL,
        sent_mail_id BIGINT UNSIGNED NULL,
        finished_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_mail_send_job (job_id),
        KEY idx_mail_send_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS crm_mail_signature_templates (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        template_name VARCHAR(190) NOT NULL,
        template_html MEDIUMTEXT NULL,
        apply_scope_json JSON NULL,
        is_default TINYINT(1) NOT NULL DEFAULT 0,
        created_by INT UNSIGNED NULL,
        updated_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    crm_mail_ensure_permissions();
    crm_mail_ensure_performance_indexes();
    crm_mail_ensure_parse_schema();
}

function crm_mail_ensure_parse_schema(): void
{
    $mailColumns = [
        'raw_headers' => 'ALTER TABLE crm_mails ADD COLUMN raw_headers MEDIUMTEXT NULL AFTER raw_headers_json',
        'raw_eml_path' => 'ALTER TABLE crm_mails ADD COLUMN raw_eml_path VARCHAR(500) NULL AFTER raw_headers',
        'parse_status' => "ALTER TABLE crm_mails ADD COLUMN parse_status VARCHAR(40) NOT NULL DEFAULT 'parsed' AFTER raw_eml_path",
        'parse_error' => 'ALTER TABLE crm_mails ADD COLUMN parse_error TEXT NULL AFTER parse_status',
        'mail_source' => "ALTER TABLE crm_mails ADD COLUMN mail_source VARCHAR(40) NOT NULL DEFAULT 'imap_inbox' AFTER parse_error",
        'source_flags_json' => 'ALTER TABLE crm_mails ADD COLUMN source_flags_json JSON NULL AFTER mail_source',
        'crm_send_id' => 'ALTER TABLE crm_mails ADD COLUMN crm_send_id VARCHAR(80) NULL AFTER source_flags_json',
        'body_hash' => 'ALTER TABLE crm_mails ADD COLUMN body_hash CHAR(40) NULL AFTER crm_send_id',
        'imap_mailbox' => 'ALTER TABLE crm_mails ADD COLUMN imap_mailbox VARCHAR(190) NULL AFTER body_hash',
        'send_status' => 'ALTER TABLE crm_mails ADD COLUMN send_status VARCHAR(40) NULL AFTER imap_mailbox',
        'mail_category' => "ALTER TABLE crm_mails ADD COLUMN mail_category VARCHAR(40) NOT NULL DEFAULT 'normal' AFTER send_status",
        'followup_status' => "ALTER TABLE crm_mails ADD COLUMN followup_status VARCHAR(40) NOT NULL DEFAULT 'open' AFTER mail_category",
        'next_followup_at' => 'ALTER TABLE crm_mails ADD COLUMN next_followup_at DATETIME NULL AFTER followup_status',
    ];
    foreach ($mailColumns as $column => $sql) {
        if (!crm_setting_column_exists('crm_mails', $column)) db()->exec($sql);
    }
    $syncColumns = [
        'skipped_count' => 'ALTER TABLE crm_mail_sync_logs ADD COLUMN skipped_count INT NOT NULL DEFAULT 0 AFTER attachment_count',
        'folders_json' => 'ALTER TABLE crm_mail_sync_logs ADD COLUMN folders_json JSON NULL AFTER failed_count',
    ];
    foreach ($syncColumns as $column => $sql) {
        if (!crm_setting_column_exists('crm_mail_sync_logs', $column)) db()->exec($sql);
    }
    $attachmentColumns = [
        'filename' => 'ALTER TABLE crm_mail_attachments ADD COLUMN filename VARCHAR(255) NULL AFTER file_name',
        'original_filename' => 'ALTER TABLE crm_mail_attachments ADD COLUMN original_filename VARCHAR(500) NULL AFTER filename',
        'message_id' => 'ALTER TABLE crm_mail_attachments ADD COLUMN message_id VARCHAR(255) NULL AFTER attachment_type',
        'preview_status' => "ALTER TABLE crm_mail_attachments ADD COLUMN preview_status VARCHAR(40) NOT NULL DEFAULT 'pending' AFTER is_signature_image",
    ];
    foreach ($attachmentColumns as $column => $sql) {
        if (!crm_setting_column_exists('crm_mail_attachments', $column)) db()->exec($sql);
    }
    if (!crm_setting_column_exists('crm_mail_drafts', 'auto_saved')) {
        db()->exec('ALTER TABLE crm_mail_drafts ADD COLUMN auto_saved TINYINT(1) NOT NULL DEFAULT 0 AFTER linked_contact_id');
    }
    if (!crm_setting_column_exists('crm_mail_drafts', 'draft_meta_json')) {
        db()->exec('ALTER TABLE crm_mail_drafts ADD COLUMN draft_meta_json JSON NULL AFTER attachments_json');
    }
}

function crm_mail_ensure_account_schema(): void
{
    if (!crm_setting_column_exists('crm_user_mail_accounts', 'is_default')) {
        db()->exec('ALTER TABLE crm_user_mail_accounts ADD COLUMN is_default TINYINT(1) NOT NULL DEFAULT 0 AFTER is_enabled');
    }
    if (!crm_setting_column_exists('crm_user_mail_accounts', 'deleted_at')) {
        db()->exec('ALTER TABLE crm_user_mail_accounts ADD COLUMN deleted_at DATETIME NULL AFTER updated_at');
    }
    if (!crm_setting_column_exists('crm_user_mail_accounts', 'sync_fail_count')) {
        db()->exec('ALTER TABLE crm_user_mail_accounts ADD COLUMN sync_fail_count INT NOT NULL DEFAULT 0 AFTER sync_error');
    }
    if (!crm_setting_column_exists('crm_user_mail_accounts', 'sync_backoff_until')) {
        db()->exec('ALTER TABLE crm_user_mail_accounts ADD COLUMN sync_backoff_until DATETIME NULL AFTER sync_fail_count');
    }
    if (!crm_setting_column_exists('crm_user_mail_accounts', 'delay_send_minutes')) {
        db()->exec('ALTER TABLE crm_user_mail_accounts ADD COLUMN delay_send_minutes INT NOT NULL DEFAULT 0 AFTER signature_html');
    }
    foreach ([
        'scheduled_at' => 'ALTER TABLE crm_mail_send_jobs ADD COLUMN scheduled_at DATETIME NULL AFTER error_message',
        'payload_json' => 'ALTER TABLE crm_mail_send_jobs ADD COLUMN payload_json JSON NULL AFTER scheduled_at',
        'attachments_json' => 'ALTER TABLE crm_mail_send_jobs ADD COLUMN attachments_json JSON NULL AFTER payload_json',
        'sent_mail_id' => 'ALTER TABLE crm_mail_send_jobs ADD COLUMN sent_mail_id BIGINT UNSIGNED NULL AFTER attachments_json',
        'finished_at' => 'ALTER TABLE crm_mail_send_jobs ADD COLUMN finished_at DATETIME NULL AFTER sent_mail_id',
    ] as $column => $sql) {
        if (!crm_setting_column_exists('crm_mail_send_jobs', $column)) db()->exec($sql);
    }
    $stmt = db()->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $stmt->execute(['crm_user_mail_accounts', 'uk_mail_account_user']);
    if ((int)$stmt->fetchColumn() > 0) {
        db()->exec('ALTER TABLE crm_user_mail_accounts DROP INDEX uk_mail_account_user');
    }
    $stmt->execute(['crm_user_mail_accounts', 'idx_mail_account_user']);
    if ((int)$stmt->fetchColumn() === 0) {
        db()->exec('ALTER TABLE crm_user_mail_accounts ADD KEY idx_mail_account_user (user_id)');
    }
    $stmt->execute(['crm_user_mail_accounts', 'idx_mail_sync_due']);
    if ((int)$stmt->fetchColumn() === 0) {
        db()->exec('ALTER TABLE crm_user_mail_accounts ADD KEY idx_mail_sync_due (is_enabled, deleted_at, sync_backoff_until, last_sync_time)');
    }
    $stmt->execute(['crm_mail_send_jobs', 'idx_mail_send_due']);
    if ((int)$stmt->fetchColumn() === 0) {
        db()->exec('ALTER TABLE crm_mail_send_jobs ADD KEY idx_mail_send_due (status, scheduled_at)');
    }
}

function crm_mail_index_exists(string $table, string $index): bool
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $stmt->execute([$table, $index]);
    return (int)$stmt->fetchColumn() > 0;
}

function crm_mail_ensure_performance_indexes(): void
{
    if (!crm_mail_index_exists('crm_mails', 'idx_mail_list_fast')) {
        db()->exec('ALTER TABLE crm_mails ADD KEY idx_mail_list_fast (user_id, mail_account_id, folder, is_deleted, received_at, sent_at, created_at, id)');
    }
    if (!crm_mail_index_exists('crm_mails', 'idx_mail_flags_fast')) {
        db()->exec('ALTER TABLE crm_mails ADD KEY idx_mail_flags_fast (user_id, mail_account_id, is_deleted, is_read, is_unreplied, is_starred, linked_customer_id)');
    }
    if (!crm_mail_index_exists('crm_mail_attachments', 'idx_mail_attach_visible')) {
        db()->exec('ALTER TABLE crm_mail_attachments ADD KEY idx_mail_attach_visible (user_id, mail_id, is_inline, is_signature_image)');
    }
    if (!crm_mail_index_exists('crm_mail_drafts', 'idx_mail_draft_account')) {
        db()->exec('ALTER TABLE crm_mail_drafts ADD KEY idx_mail_draft_account (user_id, mail_account_id)');
    }
}

function crm_mail_ensure_permissions(): void
{
    $permissions = [
        ['mail.view', 'mail', 'view', '查看邮箱中心', 'medium'],
        ['mail.view_own', 'mail', 'view_own', '查看自己邮箱', 'medium'],
        ['mail.account_bind_own', 'mail', 'account_bind_own', '绑定自己邮箱', 'high'],
        ['mail.account_manage_all', 'mail', 'account_manage_all', '管理员维护员工邮箱', 'dangerous'],
        ['mail.sync', 'mail', 'sync', '收取邮件', 'high'],
        ['mail.send', 'mail', 'send', '发送邮件', 'high'],
        ['mail.delete', 'mail', 'delete', '删除邮件', 'high'],
        ['mail.attachment_download', 'mail', 'attachment_download', '下载附件', 'high'],
        ['mail.link_customer', 'mail', 'link_customer', '邮件关联客户', 'medium'],
        ['mail.signature_manage_own', 'mail', 'signature_own', '管理个人签名', 'medium'],
        ['mail.signature_batch_apply', 'mail', 'signature_batch', '批量应用签名', 'dangerous'],
        ['mail.view_logs', 'mail', 'view_logs', '查看邮件日志', 'medium'],
    ];
    $stmt = db()->prepare('INSERT IGNORE INTO crm_permissions (permission_key, module, action, description, risk_level, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
    foreach ($permissions as $permission) $stmt->execute($permission);
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key) SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p WHERE r.role_key = 'super_admin' AND p.module = 'mail'");
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key) SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p WHERE r.role_key = 'admin' AND p.permission_key IN ('mail.account_manage_all','mail.signature_batch_apply')");
    db()->exec("INSERT IGNORE INTO crm_role_permissions (role_id, permission_key) SELECT r.id, p.permission_key FROM crm_roles r JOIN crm_permissions p WHERE r.role_key IN ('admin','manager','sales','marketing') AND p.permission_key IN ('mail.view','mail.view_own','mail.account_bind_own','mail.sync','mail.send','mail.delete','mail.attachment_download','mail.link_customer','mail.signature_manage_own','mail.view_logs')");
}

function crm_mail_target_user_id(array $input = []): int
{
    $currentId = (int)(current_user()['id'] ?? 0);
    $targetId = (int)($input['target_user_id'] ?? $_POST['target_user_id'] ?? $_GET['target_user_id'] ?? 0);
    if ($targetId <= 0 || $targetId === $currentId) return $currentId;
    if (!crm_can('mail.account_manage_all') && !is_super_admin()) throw new RuntimeException('无权维护其他员工邮箱。');
    $stmt = db()->prepare('SELECT COUNT(*) FROM crm_users WHERE id = ? AND status = "active"');
    $stmt->execute([$targetId]);
    if ((int)$stmt->fetchColumn() <= 0) throw new RuntimeException('员工账号不存在或已停用。');
    return $targetId;
}

function crm_mail_user_context(int $userId): array
{
    $stmt = db()->prepare('SELECT u.*, r.role_name, d.name AS department_name FROM crm_users u LEFT JOIN crm_roles r ON r.id = u.role_id LEFT JOIN crm_departments d ON d.id = u.department_id WHERE u.id = ? LIMIT 1');
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: (current_user() ?: []);
}

function crm_mail_session_key(int $userId): string
{
    return 'crm_mail_account_id_' . $userId;
}

function crm_mail_current_account(bool $withSecret = false, ?int $accountId = null, ?int $targetUserId = null): ?array
{
    crm_mail_ensure_tables();
    $userId = $targetUserId ?: crm_mail_target_user_id();
    $sessionKey = crm_mail_session_key($userId);
    $accountId = $accountId ?: (int)($_POST['mail_account_id'] ?? $_GET['mail_account_id'] ?? $_SESSION[$sessionKey] ?? 0);
    if ($accountId > 0) {
        $stmt = db()->prepare('SELECT * FROM crm_user_mail_accounts WHERE id = ? AND user_id = ? AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([$accountId, $userId]);
        $row = $stmt->fetch();
        if (!$row) {
            unset($_SESSION[$sessionKey]);
            return crm_mail_current_account($withSecret, 0, $userId);
        }
    } else {
        $stmt = db()->prepare('SELECT * FROM crm_user_mail_accounts WHERE user_id = ? AND deleted_at IS NULL ORDER BY is_default DESC, id DESC LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
    }
    if (!$row) return null;
    $_SESSION[$sessionKey] = (int)$row['id'];
    if ($withSecret) $row['mail_secret'] = crm_mail_decrypt($row['email_password_encrypted'] ?? '');
    unset($row['email_password_encrypted']);
    return $row;
}

function crm_mail_account_list_own(array $input = []): array
{
    crm_require('mail.view');
    $userId = crm_mail_target_user_id($input);
    $stmt = db()->prepare('SELECT * FROM crm_user_mail_accounts WHERE user_id = ? AND deleted_at IS NULL ORDER BY is_default DESC, id DESC');
    $stmt->execute([$userId]);
    $accounts = [];
    foreach ($stmt->fetchAll() as $row) {
        $accounts[] = crm_mail_account_payload($row)['account'];
    }
    $current = crm_mail_current_account(false, null, $userId);
    return ['accounts' => $accounts, 'current_id' => $current ? (int)$current['id'] : 0, 'target_user_id' => $userId, 'target_user' => crm_mail_user_context($userId)];
}

function crm_mail_account_set_current(int $accountId, array $input = []): array
{
    crm_require('mail.view');
    $targetUserId = crm_mail_target_user_id($input);
    $account = crm_mail_current_account(false, $accountId, $targetUserId);
    if (!$account) throw new RuntimeException('邮箱不存在或无权访问。');
    $_SESSION[crm_mail_session_key((int)$account['user_id'])] = (int)$account['id'];
    crm_log_event('mail', 'account_switch', 'mail_account', (string)$account['id']);
    return crm_mail_account_payload($account) + crm_mail_account_list_own(['target_user_id' => (int)$account['user_id']]);
}

function crm_mail_template_defaults(): array
{
    return [
        'imap_host' => 'imap.exmail.qq.com',
        'imap_port' => 993,
        'imap_secure' => 'ssl',
        'smtp_host' => 'smtp.exmail.qq.com',
        'smtp_port' => 465,
        'smtp_secure' => 'ssl',
        'max_attachment_mb' => 25,
        'max_total_attachment_mb' => 50,
        'allowed_types' => 'pdf,xls,xlsx,doc,docx,jpg,jpeg,png,zip,rar,txt',
    ];
}

function crm_mail_account_payload(?array $account): array
{
    $defaults = crm_mail_template_defaults();
    if (!$account) {
        return ['bound' => false, 'template' => $defaults, 'account' => null, 'signature_variables' => crm_mail_signature_variables(null)];
    }
    return ['bound' => true, 'template' => $defaults, 'account' => [
        'id' => (int)$account['id'],
        'user_id' => (int)$account['user_id'],
        'email_address' => $account['email_address'],
        'email_username' => $account['email_username'],
        'imap_host' => $account['imap_host'],
        'imap_port' => (int)$account['imap_port'],
        'imap_secure' => $account['imap_secure'],
        'smtp_host' => $account['smtp_host'],
        'smtp_port' => (int)$account['smtp_port'],
        'smtp_secure' => $account['smtp_secure'],
        'sender_name' => $account['sender_name'],
        'signature_html' => $account['signature_html'],
        'delay_send_minutes' => (int)($account['delay_send_minutes'] ?? 0),
        'signature_variables' => crm_mail_signature_variables($account),
        'signature_preview_html' => crm_mail_render_signature_variables((string)($account['signature_html'] ?? ''), $account),
        'is_enabled' => (int)$account['is_enabled'],
        'is_default' => (int)($account['is_default'] ?? 0),
        'last_sync_time' => $account['last_sync_time'],
        'sync_status' => $account['sync_status'],
        'sync_error' => $account['sync_error'],
    ]];
}

function crm_mail_signature_variables(?array $account = null): array
{
    $user = !empty($account['user_id']) ? crm_mail_user_context((int)$account['user_id']) : (current_user() ?: []);
    $mailUserName = (string)($account['sender_name'] ?? '');
    if ($mailUserName === '') {
        $mailUserName = (string)($user['real_name'] ?: ($user['username'] ?? ''));
    }
    $mailUserMobile = (string)($user['phone'] ?? '');
    $sendEmail = (string)($account['email_address'] ?? ($user['email'] ?? ''));
    $mailUserPosition = trim((string)($user['position'] ?? ''));
    return [
        '{customer_name}' => '',
        '{mail_user_name}' => $mailUserName,
        '{mail_user_mobile}' => $mailUserMobile,
        '{send_email}' => $sendEmail,
        '{mail_user_position}' => $mailUserPosition,
        // Backward-compatible aliases for old saved signatures. New UI only exposes the five variables above.
        '{user_name}' => $mailUserName,
        '{position}' => $mailUserPosition,
        '{email}' => $sendEmail,
        '{mobile}' => $mailUserMobile,
    ];
}

function crm_mail_render_signature_variables(string $html, ?array $account = null): string
{
    if ($html === '') return '';
    return strtr($html, crm_mail_signature_variables($account));
}

function crm_mail_generate_message_id(array $account): string
{
    $domain = strtolower(trim((string)substr(strrchr((string)($account['email_address'] ?? ''), '@') ?: '', 1)));
    if ($domain === '' || !preg_match('/^[a-z0-9.-]+$/i', $domain)) $domain = 'artdon.local';
    return '<crm-' . date('YmdHis') . '-' . bin2hex(random_bytes(8)) . '@' . $domain . '>';
}

function crm_mail_normalize_message_id(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') return '';
    return strtolower(trim($value, " \t\r\n<>"));
}

function crm_mail_message_id_variants(?string $value): array
{
    $normalized = crm_mail_normalize_message_id($value);
    if ($normalized === '') return [];
    $variants = [$normalized => true];
    if (preg_match_all('/crm-\d{14}-[a-f0-9]{16}@[a-z0-9.-]+/i', $normalized, $matches)) {
        foreach ($matches[0] as $match) {
            $variants[strtolower($match)] = true;
        }
    }
    return array_keys($variants);
}

function crm_mail_body_hash(string $bodyHtml, string $bodyText = ''): string
{
    $plain = trim(strip_tags($bodyHtml !== '' ? $bodyHtml : $bodyText));
    $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $plain = strtolower(trim(preg_replace('/\s+/u', ' ', $plain) ?: ''));
    return $plain !== '' ? sha1($plain) : '';
}

function crm_mail_source_flags(array $flags): array
{
    $allowed = ['crm_sent', 'imap_sent', 'imap_inbox', 'imap_synced', 'draft'];
    $out = [];
    foreach ($flags as $flag) {
        $flag = trim((string)$flag);
        if ($flag !== '' && in_array($flag, $allowed, true)) $out[$flag] = true;
    }
    return array_keys($out);
}

function crm_mail_source_label(?string $source, array $flags = []): string
{
    $flags = crm_mail_source_flags($flags);
    if (in_array('crm_sent', $flags, true) && (in_array('imap_sent', $flags, true) || in_array('imap_synced', $flags, true))) return 'CRM发送 + 腾讯同步';
    if (in_array('crm_sent', $flags, true) || $source === 'crm_sent') return 'CRM发送';
    if (in_array('imap_sent', $flags, true) || $source === 'imap_sent') return '腾讯同步';
    if (in_array('draft', $flags, true) || $source === 'draft') return '草稿';
    return '收件箱同步';
}

function crm_mail_source_tags(?string $source, array $flags = [], array $tags = []): array
{
    $tags = array_values(array_filter(array_map('strval', $tags)));
    $tags[] = crm_mail_source_label($source, $flags);
    return array_values(array_unique($tags));
}

function crm_mail_category_detect(array $mail): string
{
    $manual = trim((string)($mail['mail_category'] ?? ''));
    if ($manual !== '' && $manual !== 'normal') return $manual;
    $text = strtolower((string)($mail['subject'] ?? '') . ' ' . (string)($mail['body_text'] ?? ''));
    if (preg_match('/promotion|edm|campaign|newsletter|推广|促销|营销/i', $text)) return 'promotion';
    if (preg_match('/quote|quotation|price|offer|pi\b|proforma|报价|价格|形式发票/i', $text)) return 'quote';
    if (preg_match('/sample|prototype|样品|寄样/i', $text)) return 'sample';
    if (preg_match('/after-sales|service|warranty|complaint|repair|售后|维修|质保|投诉/i', $text)) return 'service';
    return 'normal';
}

function crm_mail_category_label(string $category): string
{
    return [
        'promotion' => '推广邮件',
        'quote' => '报价邮件',
        'sample' => '样品邮件',
        'service' => '售后邮件',
        'normal' => '普通邮件',
    ][$category] ?? '普通邮件';
}

function crm_mail_account_get_own(array $input = []): array
{
    crm_require('mail.view');
    $userId = crm_mail_target_user_id($input);
    return crm_mail_account_payload(crm_mail_current_account(false, null, $userId)) + crm_mail_account_list_own(['target_user_id' => $userId]);
}

function crm_mail_dashboard_summary(): array
{
    crm_require('mail.view');
    $account = crm_mail_current_account(false);
    if (!$account) {
        return [
            'bound' => false,
            'account' => null,
            'today_received' => 0,
            'unread' => 0,
            'unreplied' => 0,
            'with_attachments' => 0,
            'folder_counts' => [],
            'last_sync_time' => null,
            'sync_status' => 'unbound',
        ];
    }
    $params = [(int)$account['user_id'], (int)$account['id']];
    $stmt = db()->prepare("SELECT
        SUM(CASE WHEN folder = 'inbox' AND DATE(COALESCE(received_at, created_at)) = CURDATE() THEN 1 ELSE 0 END) AS today_received,
        SUM(CASE WHEN is_read = 0 AND is_deleted = 0 THEN 1 ELSE 0 END) AS unread,
        SUM(CASE WHEN is_unreplied = 1 AND is_deleted = 0 THEN 1 ELSE 0 END) AS unreplied,
        SUM(CASE WHEN is_deleted = 0 AND EXISTS (SELECT 1 FROM crm_mail_attachments a WHERE a.mail_id = m.id AND a.user_id = m.user_id AND COALESCE(a.is_inline,0) = 0 AND COALESCE(a.is_signature_image,0) = 0) THEN 1 ELSE 0 END) AS with_attachments
        FROM crm_mails m WHERE user_id = ? AND mail_account_id = ?");
    $stmt->execute($params);
    $row = $stmt->fetch() ?: [];
    return [
        'bound' => true,
        'account' => [
            'id' => (int)$account['id'],
            'email_address' => (string)$account['email_address'],
            'sender_name' => (string)($account['sender_name'] ?? ''),
        ],
        'today_received' => (int)($row['today_received'] ?? 0),
        'unread' => (int)($row['unread'] ?? 0),
        'unreplied' => (int)($row['unreplied'] ?? 0),
        'with_attachments' => (int)($row['with_attachments'] ?? 0),
        'folder_counts' => crm_mail_folder_counts($account),
        'last_sync_time' => $account['last_sync_time'],
        'sync_status' => $account['sync_status'] ?: 'ready',
    ];
}

function crm_mail_folder_counts(?array $account = null): array
{
    $account = $account ?: crm_mail_current_account(false);
    if (!$account) return [];
    $userId = (int)$account['user_id'];
    $accountId = (int)$account['id'];
    $stmt = db()->prepare("SELECT
        SUM(CASE WHEN folder = 'inbox' AND is_deleted = 0 THEN 1 ELSE 0 END) AS inbox,
        SUM(CASE WHEN folder = 'sent' AND is_deleted = 0 THEN 1 ELSE 0 END) AS sent,
        SUM(CASE WHEN folder = 'archive' AND is_deleted = 0 THEN 1 ELSE 0 END) AS archive,
        SUM(CASE WHEN is_deleted = 1 OR folder = 'deleted' THEN 1 ELSE 0 END) AS deleted,
        SUM(CASE WHEN is_read = 0 AND is_deleted = 0 THEN 1 ELSE 0 END) AS unread,
        SUM(CASE WHEN is_starred = 1 AND is_deleted = 0 THEN 1 ELSE 0 END) AS starred,
        SUM(CASE WHEN is_unreplied = 1 AND is_deleted = 0 THEN 1 ELSE 0 END) AS unreplied,
        SUM(CASE WHEN is_deleted = 0 AND EXISTS (SELECT 1 FROM crm_mail_attachments a WHERE a.mail_id = m.id AND a.user_id = m.user_id AND COALESCE(a.is_inline,0) = 0 AND COALESCE(a.is_signature_image,0) = 0) THEN 1 ELSE 0 END) AS attachments,
        SUM(CASE WHEN linked_customer_id IS NOT NULL AND is_deleted = 0 THEN 1 ELSE 0 END) AS linked,
        SUM(CASE WHEN linked_customer_id IS NULL AND is_deleted = 0 THEN 1 ELSE 0 END) AS unlinked,
        SUM(CASE WHEN is_starred = 1 AND is_deleted = 0 THEN 1 ELSE 0 END) AS important,
        SUM(CASE WHEN is_deleted = 0 AND (LOWER(subject) LIKE '%promotion%' OR LOWER(subject) LIKE '%edm%' OR LOWER(subject) LIKE '%推广%') THEN 1 ELSE 0 END) AS promotion,
        SUM(CASE WHEN is_deleted = 0 AND (LOWER(subject) LIKE '%quote%' OR LOWER(subject) LIKE '%quotation%' OR LOWER(subject) LIKE '%报价%') THEN 1 ELSE 0 END) AS quote,
        SUM(CASE WHEN is_deleted = 0 AND (LOWER(subject) LIKE '%sample%' OR LOWER(subject) LIKE '%样品%') THEN 1 ELSE 0 END) AS sample,
        SUM(CASE WHEN is_deleted = 0 AND (LOWER(subject) LIKE '%service%' OR LOWER(subject) LIKE '%after-sales%' OR LOWER(subject) LIKE '%售后%') THEN 1 ELSE 0 END) AS service
        FROM crm_mails m WHERE user_id = ? AND mail_account_id = ?");
    $stmt->execute([$userId, $accountId]);
    $row = $stmt->fetch() ?: [];
    $draftStmt = db()->prepare('SELECT COUNT(*) FROM crm_mail_drafts WHERE user_id = ? AND mail_account_id = ?');
    $draftStmt->execute([$userId, $accountId]);
    $row['drafts'] = (int)$draftStmt->fetchColumn();
    $scheduledStmt = db()->prepare("SELECT COUNT(*) FROM crm_mail_send_jobs WHERE user_id = ? AND mail_account_id = ? AND status IN ('scheduled','running')");
    $scheduledStmt->execute([$userId, $accountId]);
    $row['scheduled'] = (int)$scheduledStmt->fetchColumn();
    $keys = ['inbox','scheduled','sent','drafts','archive','deleted','unread','starred','unreplied','attachments','linked','unlinked','important','promotion','quote','sample','service'];
    $counts = [];
    foreach ($keys as $key) $counts[$key] = (int)($row[$key] ?? 0);
    return $counts;
}

function crm_mail_account_save_own(array $input): array
{
    $userId = crm_mail_target_user_id($input);
    if ($userId === (int)(current_user()['id'] ?? 0)) crm_require('mail.account_bind_own');
    else crm_require('mail.account_manage_all');
    $defaults = crm_mail_template_defaults();
    $accountId = (int)($input['mail_account_id'] ?? 0);
    $email = trim((string)($input['email_address'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('邮箱地址格式不正确。');
    $secret = (string)($input['mail_secret'] ?? '');
    $existing = $accountId > 0 ? crm_mail_current_account(true, $accountId, $userId) : null;
    $encrypted = $secret !== '' ? crm_mail_encrypt($secret) : ($existing ? crm_mail_encrypt((string)($existing['mail_secret'] ?? '')) : '');
    if ($encrypted === '') throw new RuntimeException('授权码 / 密码不能为空。');
    $payload = [
        $userId,
        $email,
        trim((string)($input['email_username'] ?? $email)) ?: $email,
        $encrypted,
        trim((string)($input['imap_host'] ?? $defaults['imap_host'])) ?: $defaults['imap_host'],
        (int)($input['imap_port'] ?? $defaults['imap_port']),
        trim((string)($input['imap_secure'] ?? $defaults['imap_secure'])) ?: 'ssl',
        trim((string)($input['smtp_host'] ?? $defaults['smtp_host'])) ?: $defaults['smtp_host'],
        (int)($input['smtp_port'] ?? $defaults['smtp_port']),
        trim((string)($input['smtp_secure'] ?? $defaults['smtp_secure'])) ?: 'ssl',
        trim((string)($input['sender_name'] ?? crm_mail_user_context($userId)['username'] ?? '')),
        (string)($input['signature_html'] ?? ''),
        max(0, min(10, (int)($input['delay_send_minutes'] ?? 0))),
    ];
    if ($accountId > 0) {
        db()->prepare('UPDATE crm_user_mail_accounts SET email_address = ?, email_username = ?, email_password_encrypted = ?, imap_host = ?, imap_port = ?, imap_secure = ?, smtp_host = ?, smtp_port = ?, smtp_secure = ?, sender_name = ?, signature_html = ?, delay_send_minutes = ?, is_enabled = 1, sync_fail_count = 0, sync_backoff_until = NULL, updated_at = NOW() WHERE id = ? AND user_id = ? AND deleted_at IS NULL')
            ->execute([$payload[1], $payload[2], $payload[3], $payload[4], $payload[5], $payload[6], $payload[7], $payload[8], $payload[9], $payload[10], $payload[11], $payload[12], $accountId, $userId]);
    } else {
        $hasAccount = db()->prepare('SELECT COUNT(*) FROM crm_user_mail_accounts WHERE user_id = ? AND deleted_at IS NULL');
        $hasAccount->execute([$userId]);
        db()->prepare('INSERT INTO crm_user_mail_accounts (user_id, email_address, email_username, email_password_encrypted, imap_host, imap_port, imap_secure, smtp_host, smtp_port, smtp_secure, sender_name, signature_html, delay_send_minutes, is_enabled, is_default, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW(), NOW())')
            ->execute(array_merge($payload, [(int)$hasAccount->fetchColumn() === 0 ? 1 : 0]));
        $accountId = (int)db()->lastInsertId();
    }
    if (!empty($input['is_default'])) {
        db()->prepare('UPDATE crm_user_mail_accounts SET is_default = 0 WHERE user_id = ?')->execute([$userId]);
        db()->prepare('UPDATE crm_user_mail_accounts SET is_default = 1 WHERE id = ? AND user_id = ?')->execute([$accountId, $userId]);
    }
    $_SESSION[crm_mail_session_key($userId)] = $accountId;
    crm_log_event('mail', 'account_save', 'mail_account', (string)$accountId, null, ['email' => $email, 'target_user_id' => $userId]);
    return crm_mail_account_payload(crm_mail_current_account(false, $accountId, $userId)) + crm_mail_account_list_own(['target_user_id' => $userId]);
}

function crm_mail_add_customer_timeline_for_mail(array $mail, string $eventType, string $title, string $detail = ''): void
{
    $customerId = (int)($mail['linked_customer_id'] ?? 0);
    if ($customerId <= 0 || !function_exists('crm_customer_timeline_add')) return;
    $mailId = (string)(int)($mail['id'] ?? 0);
    $subject = (string)($mail['subject'] ?? '(无主题)');
    $body = $detail !== '' ? $detail : $subject;
    crm_customer_timeline_add($customerId, $eventType, $title . '：' . $subject, $body, 'mail', $mailId);
}

function crm_mail_refresh_unreplied_flags(array $account, int $days = 3): void
{
    $days = max(1, min(60, $days));
    $userId = (int)$account['user_id'];
    $accountId = (int)$account['id'];
    db()->prepare("UPDATE crm_mails m SET is_unreplied = 0, updated_at = NOW()
        WHERE m.user_id = ? AND m.mail_account_id = ? AND m.folder = 'sent'
          AND (m.is_unreplied = 1)
          AND (
            COALESCE(m.followup_status,'open') IN ('replied','done','no_follow')
            OR EXISTS (
              SELECT 1 FROM crm_mails r
              WHERE r.user_id = m.user_id AND r.mail_account_id = m.mail_account_id AND r.folder = 'inbox' AND r.is_deleted = 0
                AND COALESCE(r.received_at, r.created_at) > COALESCE(m.sent_at, m.created_at)
                AND (
                  (m.linked_customer_id IS NOT NULL AND m.linked_customer_id = r.linked_customer_id)
                  OR (m.to_emails <> '' AND r.from_email <> '' AND LOCATE(LOWER(r.from_email), LOWER(m.to_emails)) > 0)
                )
            )
            OR EXISTS (
              SELECT 1 FROM crm_customer_followups f
              WHERE f.related_email_id = m.id AND f.deleted_at IS NULL
            )
          )")->execute([$userId, $accountId]);
    db()->prepare("UPDATE crm_mails m SET is_unreplied = 1, updated_at = NOW()
        WHERE m.user_id = ? AND m.mail_account_id = ? AND m.folder = 'sent' AND m.is_deleted = 0
          AND COALESCE(m.followup_status,'open') NOT IN ('replied','done','no_follow')
          AND COALESCE(m.sent_at, m.created_at) <= DATE_SUB(NOW(), INTERVAL {$days} DAY)
          AND NOT EXISTS (
            SELECT 1 FROM crm_mails r
            WHERE r.user_id = m.user_id AND r.mail_account_id = m.mail_account_id AND r.folder = 'inbox' AND r.is_deleted = 0
              AND COALESCE(r.received_at, r.created_at) > COALESCE(m.sent_at, m.created_at)
              AND (
                (m.linked_customer_id IS NOT NULL AND m.linked_customer_id = r.linked_customer_id)
                OR (m.to_emails <> '' AND r.from_email <> '' AND LOCATE(LOWER(r.from_email), LOWER(m.to_emails)) > 0)
              )
          )
          AND NOT EXISTS (
            SELECT 1 FROM crm_customer_followups f
            WHERE f.related_email_id = m.id AND f.deleted_at IS NULL
          )")->execute([$userId, $accountId]);
}

function crm_mail_account_delete_own(int $accountId, array $input = []): array
{
    $userId = crm_mail_target_user_id($input);
    if ($userId === (int)(current_user()['id'] ?? 0)) crm_require('mail.account_bind_own');
    else crm_require('mail.account_manage_all');
    $account = crm_mail_current_account(false, $accountId, $userId);
    if (!$account) throw new RuntimeException('邮箱不存在或无权删除。');

    $mailIdsStmt = db()->prepare('SELECT id FROM crm_mails WHERE user_id = ? AND mail_account_id = ?');
    $mailIdsStmt->execute([$userId, $accountId]);
    $mailIds = array_map('intval', array_column($mailIdsStmt->fetchAll(), 'id'));
    $attachmentPaths = [];
    if ($mailIds && db_table_exists('crm_mail_attachments')) {
        $placeholders = implode(',', array_fill(0, count($mailIds), '?'));
        $pathStmt = db()->prepare("SELECT file_path FROM crm_mail_attachments WHERE user_id = ? AND mail_id IN ({$placeholders}) AND file_path IS NOT NULL AND file_path <> ''");
        $pathStmt->execute(array_merge([$userId], $mailIds));
        $attachmentPaths = array_values(array_filter(array_map('strval', array_column($pathStmt->fetchAll(), 'file_path'))));
    }

    db()->beginTransaction();
    try {
        if ($mailIds && db_table_exists('crm_mail_attachments')) {
            $placeholders = implode(',', array_fill(0, count($mailIds), '?'));
            db()->prepare("DELETE FROM crm_mail_attachments WHERE user_id = ? AND mail_id IN ({$placeholders})")->execute(array_merge([$userId], $mailIds));
        }
        foreach ([
            'crm_mails' => 'DELETE FROM crm_mails WHERE user_id = ? AND mail_account_id = ?',
            'crm_mail_drafts' => 'DELETE FROM crm_mail_drafts WHERE user_id = ? AND mail_account_id = ?',
            'crm_mail_sync_logs' => 'DELETE FROM crm_mail_sync_logs WHERE user_id = ? AND mail_account_id = ?',
            'crm_mail_send_jobs' => 'DELETE FROM crm_mail_send_jobs WHERE user_id = ? AND mail_account_id = ?',
        ] as $table => $sql) {
            if (db_table_exists($table)) db()->prepare($sql)->execute([$userId, $accountId]);
        }
        db()->prepare('UPDATE crm_user_mail_accounts SET deleted_at = NOW(), is_enabled = 0, is_default = 0 WHERE id = ? AND user_id = ?')->execute([$accountId, $userId]);
        $next = db()->prepare('SELECT id FROM crm_user_mail_accounts WHERE user_id = ? AND deleted_at IS NULL ORDER BY is_default DESC, id DESC LIMIT 1');
        $next->execute([$userId]);
        $nextId = (int)$next->fetchColumn();
        if ($nextId) db()->prepare('UPDATE crm_user_mail_accounts SET is_default = 1 WHERE id = ? AND user_id = ?')->execute([$nextId, $userId]);
        $_SESSION[crm_mail_session_key($userId)] = $nextId;
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }

    foreach ($attachmentPaths as $path) {
        $fullPath = $path;
        if ($fullPath !== '' && $fullPath[0] !== DIRECTORY_SEPARATOR) $fullPath = __DIR__ . '/' . ltrim($fullPath, '/');
        if (is_file($fullPath) && strpos(realpath($fullPath) ?: '', realpath(__DIR__) ?: __DIR__) === 0) {
            @unlink($fullPath);
        }
    }
    crm_log_event('mail', 'account_delete', 'mail_account', (string)$accountId, ['email' => $account['email_address']], ['deleted_mails' => count($mailIds)]);
    return crm_mail_account_get_own(['target_user_id' => $userId]) + ['deleted_account_id' => $accountId, 'deleted_mail_count' => count($mailIds)];
}

function crm_mail_signature_template_save(array $input): array
{
    crm_require('mail.signature_batch_apply');
    $name = trim((string)($input['template_name'] ?? '公司统一签名'));
    $html = (string)($input['signature_html'] ?? '');
    if (trim(strip_tags($html)) === '' && trim($html) === '') throw new RuntimeException('签名内容不能为空。');
    db()->prepare('UPDATE crm_mail_signature_templates SET is_default = 0 WHERE is_default = 1')->execute();
    db()->prepare('INSERT INTO crm_mail_signature_templates (template_name, template_html, is_default, created_by, updated_by, created_at, updated_at) VALUES (?, ?, 1, ?, ?, NOW(), NOW())')
        ->execute([$name, $html, (int)(current_user()['id'] ?? 0), (int)(current_user()['id'] ?? 0)]);
    $id = (int)db()->lastInsertId();
    crm_log_event('mail', 'signature_template_save', 'mail_signature_template', (string)$id, null, ['name' => $name]);
    return ['template_id' => $id, 'template_name' => $name];
}

function crm_mail_signature_apply_batch(array $input): array
{
    crm_require('mail.signature_batch_apply');
    $templateId = (int)($input['template_id'] ?? 0);
    if ($templateId) {
        $stmt = db()->prepare('SELECT * FROM crm_mail_signature_templates WHERE id = ? LIMIT 1');
        $stmt->execute([$templateId]);
    } else {
        $stmt = db()->query('SELECT * FROM crm_mail_signature_templates WHERE is_default = 1 ORDER BY id DESC LIMIT 1');
    }
    $template = $stmt->fetch();
    if (!$template) throw new RuntimeException('没有可应用的公司签名模板。');
    $update = db()->prepare('UPDATE crm_user_mail_accounts SET signature_html = ?, signature_id = ?, updated_at = NOW() WHERE deleted_at IS NULL');
    $update->execute([(string)$template['template_html'], (int)$template['id']]);
    $affected = $update->rowCount();
    crm_log_event('mail', 'signature_apply_batch', 'mail_signature_template', (string)$template['id'], null, ['affected' => $affected]);
    return ['template_id' => (int)$template['id'], 'affected' => $affected];
}

function crm_mail_socket_test(string $host, int $port, string $secure): array
{
    $target = strtolower($secure) === 'ssl' ? 'ssl://' . $host : $host;
    $errno = 0;
    $errstr = '';
    $start = microtime(true);
    $fp = @fsockopen($target, $port, $errno, $errstr, 8);
    $ms = (int)round((microtime(true) - $start) * 1000);
    if (!$fp) return ['ok' => false, 'message' => ($errstr ?: '连接失败') . ($errno ? " ({$errno})" : ''), 'latency_ms' => $ms];
    stream_set_timeout($fp, 3);
    $banner = @fgets($fp, 256);
    fclose($fp);
    return ['ok' => true, 'message' => trim((string)$banner) ?: '服务器可连接', 'latency_ms' => $ms];
}

function crm_mail_read_line($fp): string
{
    $line = fgets($fp, 8192);
    if ($line === false) throw new RuntimeException('邮箱服务器连接中断。');
    return rtrim($line, "\r\n");
}

function crm_mail_smtp_expect($fp, array $codes): string
{
    $response = '';
    do {
        $line = crm_mail_read_line($fp);
        $response .= $line . "\n";
        $code = substr($line, 0, 3);
        $more = isset($line[3]) && $line[3] === '-';
    } while ($more);
    if (!in_array($code, $codes, true)) {
        throw new RuntimeException('SMTP 返回异常：' . trim($response));
    }
    return trim($response);
}

function crm_mail_smtp_cmd($fp, string $command, array $codes): string
{
    fwrite($fp, $command . "\r\n");
    return crm_mail_smtp_expect($fp, $codes);
}

function crm_mail_smtp_connect(array $account)
{
    $host = (string)$account['smtp_host'];
    $port = (int)$account['smtp_port'];
    $secure = strtolower((string)$account['smtp_secure']);
    $target = $secure === 'ssl' ? 'ssl://' . $host : $host;
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($target, $port, $errno, $errstr, 15);
    if (!$fp) throw new RuntimeException('SMTP 连接失败：' . ($errstr ?: '无法连接服务器') . ($errno ? " ({$errno})" : ''));
    stream_set_timeout($fp, 20);
    crm_mail_smtp_expect($fp, ['220']);
    crm_mail_smtp_cmd($fp, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'artdon.local'), ['250']);
    if ($secure === 'tls') {
        crm_mail_smtp_cmd($fp, 'STARTTLS', ['220']);
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('SMTP STARTTLS 握手失败。');
        }
        crm_mail_smtp_cmd($fp, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'artdon.local'), ['250']);
    }
    crm_mail_smtp_cmd($fp, 'AUTH LOGIN', ['334']);
    crm_mail_smtp_cmd($fp, base64_encode((string)$account['email_username']), ['334']);
    crm_mail_smtp_cmd($fp, base64_encode((string)$account['mail_secret']), ['235']);
    return $fp;
}

function crm_mail_parse_addresses(string $value): array
{
    $value = preg_replace('/(@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*),([A-Za-z]{2,})(?=\s*(?:[;,]|$))/u', '$1.$2', $value) ?? $value;
    $parts = preg_split('/[;,]+/', $value) ?: [];
    $emails = [];
    foreach ($parts as $part) {
        $email = trim($part);
        if ($email === '') continue;
        if (preg_match('/<([^>]+)>/', $email, $m)) $email = trim($m[1]);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('邮箱地址格式不正确：' . $part);
        $emails[] = $email;
    }
    return array_values(array_unique($emails));
}

function crm_mail_header_encode(string $value): string
{
    if ($value === '') return '';
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function crm_mail_ascii_filename(string $name): string
{
    $name = crm_mail_safe_file_name($name);
    $ext = pathinfo($name, PATHINFO_EXTENSION);
    $fallback = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: 'attachment';
    $fallback = trim($fallback, '._-');
    if ($fallback !== '' && $ext !== '' && strcasecmp($fallback, $ext) === 0) {
        $fallback = 'attachment.' . preg_replace('/[^A-Za-z0-9]+/', '', $ext);
    }
    return $fallback !== '' ? $fallback : 'attachment';
}

function crm_mail_attachment_header_block(array $file): string
{
    $mime = trim((string)($file['type'] ?? '')) ?: 'application/octet-stream';
    $name = crm_mail_safe_file_name((string)($file['name'] ?? 'attachment'));
    $ascii = crm_mail_ascii_filename($name);
    $encoded = rawurlencode($name);
    $contentId = trim((string)($file['content_id'] ?? ''), '<> ');
    $disposition = !empty($file['is_inline']) ? 'inline' : 'attachment';
    $headers = "Content-Type: {$mime}; name=\"{$ascii}\"; name*=UTF-8''{$encoded}\r\n"
        . "Content-Transfer-Encoding: base64\r\n";
    if ($contentId !== '') $headers .= "Content-ID: <{$contentId}>\r\n";
    return $headers
        . "Content-Disposition: {$disposition}; filename=\"{$ascii}\"; filename*=UTF-8''{$encoded}";
}

function crm_mail_extract_inline_data_attachments(string $html): array
{
    $attachments = [];
    if (stripos($html, 'data:image/') === false) return ['html' => $html, 'attachments' => []];
    $updated = preg_replace_callback('/<img\b([^>]*?)\bsrc=(["\'])(data:image\/([a-z0-9.+-]+);base64,([^"\']+))\2([^>]*)>/i', static function ($m) use (&$attachments) {
        $ext = strtolower((string)$m[4]);
        $data = preg_replace('/\s+/', '', (string)$m[5]) ?? '';
        $binary = base64_decode($data, true);
        if ($binary === false || $binary === '') return $m[0];
        $safeExt = preg_replace('/[^a-z0-9]+/', '', $ext) ?: 'png';
        if ($safeExt === 'jpeg') $safeExt = 'jpg';
        $mime = 'image/' . ($ext ?: $safeExt);
        $cid = 'crm-inline-' . bin2hex(random_bytes(8)) . '@artdon';
        $name = 'inline-image-' . (count($attachments) + 1) . '.' . $safeExt;
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'crm_mail_attach_inline_' . bin2hex(random_bytes(8)) . '_' . $name;
        $tmpName = @file_put_contents($path, $binary) !== false && is_file($path) ? $path : '';
        $attachments[] = [
            'name' => $name,
            'tmp_name' => $tmpName,
            'content' => $binary,
            'size' => strlen($binary),
            'type' => $mime,
            'attachment_type' => 'inline',
            'content_id' => $cid,
            'is_inline' => 1,
        ];
        return '<img' . $m[1] . 'src=' . $m[2] . 'cid:' . $cid . $m[2] . $m[6] . '>';
    }, $html);
    return ['html' => is_string($updated) ? $updated : $html, 'attachments' => $attachments];
}

function crm_mail_extract_embedded_attachment_images(string $html, array $account): array
{
    $attachments = [];
    if ($html === '' || stripos($html, 'mail_attachment_download') === false) return ['html' => $html, 'attachments' => []];
    $userId = (int)($account['user_id'] ?? 0);
    if ($userId <= 0) return ['html' => $html, 'attachments' => []];
    $cache = [];
    $stmt = db()->prepare('SELECT a.id, a.file_name, a.file_path, a.file_size, a.mime_type FROM crm_mail_attachments a JOIN crm_mails m ON m.id = a.mail_id AND m.user_id = a.user_id WHERE a.id = ? AND a.user_id = ? AND (COALESCE(a.is_inline,0) = 1 OR COALESCE(a.is_signature_image,0) = 1 OR a.mime_type LIKE "image/%") LIMIT 1');
    $updated = preg_replace_callback('/<img\b([^>]*?)\bsrc=(["\'])([^"\']*mail_attachment_download[^"\']*)\2([^>]*)>/i', static function ($m) use (&$attachments, &$cache, $stmt, $userId) {
        $src = html_entity_decode((string)$m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $query = parse_url($src, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            $parts = parse_url('http://local/' . ltrim($src, '/'));
            $query = is_array($parts) ? (string)($parts['query'] ?? '') : '';
        }
        parse_str($query, $params);
        $attachmentId = (int)($params['attachment_id'] ?? 0);
        if ($attachmentId <= 0) return $m[0];
        if (!array_key_exists($attachmentId, $cache)) {
            $stmt->execute([$attachmentId, $userId]);
            $row = $stmt->fetch();
            $cache[$attachmentId] = ($row && is_file((string)$row['file_path'])) ? $row : null;
        }
        $row = $cache[$attachmentId];
        if (!$row) return $m[0];
        $cid = 'crm-forward-inline-' . $attachmentId . '-' . bin2hex(random_bytes(4)) . '@artdon';
        $attachments[] = [
            'name' => (string)($row['file_name'] ?: ('inline-image-' . $attachmentId)),
            'tmp_name' => (string)$row['file_path'],
            'size' => (int)($row['file_size'] ?: filesize((string)$row['file_path'])),
            'type' => (string)($row['mime_type'] ?: 'image/png'),
            'attachment_type' => 'inline',
            'content_id' => $cid,
            'is_inline' => true,
        ];
        return '<img' . $m[1] . 'src=' . $m[2] . 'cid:' . $cid . $m[2] . $m[4] . '>';
    }, $html);
    return ['html' => is_string($updated) ? $updated : $html, 'attachments' => $attachments];
}

function crm_mail_cleanup_generated_attachments(array $attachments): void
{
    foreach ($attachments as $attachment) {
        $path = (string)($attachment['tmp_name'] ?? ($attachment['path'] ?? ''));
        if ($path !== '' && crm_mail_is_generated_temp_file($path) && is_file($path)) @unlink($path);
    }
}

function crm_mail_materialize_inline_data_images(array $mail): array
{
    $mailId = (int)($mail['id'] ?? 0);
    $userId = (int)($mail['user_id'] ?? 0);
    $html = (string)($mail['body_html'] ?? '');
    if ($mailId <= 0 || $userId <= 0 || stripos($html, 'data:image/') === false) return $mail;
    $inlineResult = crm_mail_extract_inline_data_attachments($html);
    $inlineAttachments = $inlineResult['attachments'];
    if (!$inlineAttachments) return $mail;
    $newHtml = (string)$inlineResult['html'];
    crm_mail_store_attachment_files($userId, $mailId, $inlineAttachments);
    $countStmt = db()->prepare('SELECT COUNT(*) FROM crm_mail_attachments WHERE mail_id = ? AND user_id = ? AND COALESCE(is_inline,0) = 0 AND COALESCE(is_signature_image,0) = 0');
    $countStmt->execute([$mailId, $userId]);
    $attachmentCount = (int)$countStmt->fetchColumn();
    db()->prepare('UPDATE crm_mails SET body_html = ?, has_attachment = ?, attachment_count = ?, updated_at = NOW() WHERE id = ? AND user_id = ?')
        ->execute([$newHtml, $attachmentCount > 0 ? 1 : 0, $attachmentCount, $mailId, $userId]);
    $mail['body_html'] = $newHtml;
    $mail['has_attachment'] = $attachmentCount > 0 ? 1 : 0;
    $mail['attachment_count'] = $attachmentCount;
    return $mail;
}

function crm_mail_uploaded_files(array $files): array
{
    $source = $files['attachments'] ?? $files['mail_attachments'] ?? null;
    if (!$source || empty($source['name'])) return [];
    $items = [];
    $names = is_array($source['name']) ? $source['name'] : [$source['name']];
    $tmpNames = is_array($source['tmp_name']) ? $source['tmp_name'] : [$source['tmp_name']];
    $sizes = is_array($source['size']) ? $source['size'] : [$source['size']];
    $types = is_array($source['type']) ? $source['type'] : [$source['type']];
    $errors = is_array($source['error']) ? $source['error'] : [$source['error']];
    foreach ($names as $i => $name) {
        if (($errors[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
        $items[] = [
            'name' => basename((string)$name),
            'tmp_name' => (string)($tmpNames[$i] ?? ''),
            'size' => (int)($sizes[$i] ?? 0),
            'type' => (string)($types[$i] ?? 'application/octet-stream'),
        ];
    }
    return $items;
}

function crm_mail_storage_dir(int $userId, int $mailId): string
{
    $base = __DIR__ . '/storage';
    $root = $base . '/mail_attachments';
    $userDir = $root . '/' . $userId;
    $dir = $userDir . '/' . $mailId;
    foreach ([$base, $root, $userDir, $dir] as $path) {
        if (!is_dir($path)) @mkdir($path, 0775, true);
    }
    return $dir;
}

function crm_mail_raw_storage_dir(int $userId): string
{
    $base = __DIR__ . '/storage';
    $root = $base . '/mail_raw';
    $userDir = $root . '/' . $userId;
    foreach ([$base, $root, $userDir] as $path) {
        if (!is_dir($path)) @mkdir($path, 0775, true);
    }
    return $userDir;
}

function crm_mail_store_raw_eml(int $userId, string $folder, string $uid, string $raw): string
{
    if ($userId <= 0 || $raw === '') return '';
    $dir = crm_mail_raw_storage_dir($userId);
    if (!is_dir($dir) || !is_writable($dir)) return '';
    $safeFolder = preg_replace('/[^A-Za-z0-9_-]+/', '_', $folder) ?: 'mail';
    $safeUid = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $uid) ?: bin2hex(random_bytes(4));
    $path = $dir . '/' . $safeFolder . '_' . $safeUid . '_' . substr(sha1($raw), 0, 12) . '.eml';
    if (!is_file($path)) @file_put_contents($path, $raw);
    return is_file($path) ? $path : '';
}

function crm_mail_safe_file_name(string $name): string
{
    $name = trim($name) ?: 'attachment';
    $name = preg_replace('/[\\\\\/:*?"<>|]+/', '_', $name) ?: 'attachment';
    return function_exists('mb_substr') ? mb_substr($name, 0, 180) : substr($name, 0, 180);
}

function crm_mail_store_attachment_files(int $userId, int $mailId, array $attachments): array
{
    $result = ['stored' => 0, 'visible' => 0, 'inline' => 0, 'failed' => 0];
    if (!$attachments) return $result;
    $dir = crm_mail_storage_dir($userId, $mailId);
    if (!is_dir($dir) || !is_writable($dir)) {
        error_log('crm_mail_store_attachment_files: attachment directory is not writable: ' . $dir);
        $result['failed'] = count($attachments);
        return $result;
    }
    $stmt = db()->prepare('INSERT INTO crm_mail_attachments (user_id, mail_id, file_name, filename, original_filename, file_path, file_size, mime_type, attachment_type, message_id, content_id, is_inline, is_signature_image, preview_status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, NOW())');
    foreach ($attachments as $index => $attachment) {
        $originalName = crm_mail_decode_header_value((string)($attachment['original_name'] ?? $attachment['name'] ?? ('attachment-' . ($index + 1))));
        $name = crm_mail_safe_file_name($originalName);
        $target = $dir . '/' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '_' . $name;
        $content = $attachment['content'] ?? null;
        $source = (string)($attachment['tmp_name'] ?? ($attachment['path'] ?? ''));
        if (is_string($content)) {
            if (@file_put_contents($target, $content) === false) {
                $result['failed']++;
                continue;
            }
            if (crm_mail_is_generated_temp_file($source) && is_file($source)) @unlink($source);
        } elseif ($source !== '' && is_file($source)) {
            if (!@copy($source, $target)) {
                if (crm_mail_is_generated_temp_file($source)) @unlink($source);
                $result['failed']++;
                continue;
            }
            if (crm_mail_is_generated_temp_file($source)) @unlink($source);
        } else {
            $result['failed']++;
            continue;
        }
        if (!is_file($target)) {
            $result['failed']++;
            continue;
        }
        $size = filesize($target) ?: (int)($attachment['size'] ?? 0);
        $stmt->execute([
            $userId,
            $mailId,
            $name,
            $name,
            $originalName,
            $target,
            (int)$size,
            (string)($attachment['type'] ?? 'application/octet-stream'),
            (string)($attachment['attachment_type'] ?? 'normal'),
            (string)($attachment['message_id'] ?? ''),
            (string)($attachment['content_id'] ?? ''),
            !empty($attachment['is_inline']) ? 1 : 0,
            'pending',
        ]);
        $result['stored']++;
        if (!empty($attachment['is_inline']) || !empty($attachment['is_signature_image'])) {
            $result['inline']++;
        } else {
            $result['visible']++;
        }
    }
    return $result;
}

function crm_mail_update_inline_body_urls(int $userId, int $mailId, string $bodyHtml = ''): string
{
    if ($userId <= 0 || $mailId <= 0) return $bodyHtml;
    if ($bodyHtml === '') {
        $stmt = db()->prepare('SELECT body_html FROM crm_mails WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$mailId, $userId]);
        $bodyHtml = (string)($stmt->fetchColumn() ?: '');
    }
    if ($bodyHtml === '' || (stripos($bodyHtml, 'cid:') === false && stripos($bodyHtml, 'mail_attachment_download') === false)) return $bodyHtml;
    $attach = db()->prepare('SELECT id, content_id, file_path FROM crm_mail_attachments WHERE mail_id = ? AND user_id = ? AND content_id IS NOT NULL AND content_id <> ""');
    $attach->execute([$mailId, $userId]);
    $updated = crm_mail_inline_body_html($bodyHtml, $attach->fetchAll());
    if ($updated !== $bodyHtml) {
        db()->prepare('UPDATE crm_mails SET body_html = ?, updated_at = NOW() WHERE id = ? AND user_id = ?')->execute([$updated, $mailId, $userId]);
    }
    return $updated;
}

function crm_mail_repair_stale_inline_attachment_links(int $userId, int $mailId, string $bodyHtml): string
{
    if ($userId <= 0 || $mailId <= 0 || $bodyHtml === '' || stripos($bodyHtml, 'mail_attachment_download') === false) return $bodyHtml;

    $changed = false;
    $cache = [];
    $select = db()->prepare('SELECT id,user_id,mail_id,file_name,filename,original_filename,file_path,file_size,mime_type,attachment_type,message_id,content_id,is_inline,is_signature_image FROM crm_mail_attachments WHERE id = ? LIMIT 1');
    $existing = db()->prepare('SELECT id FROM crm_mail_attachments WHERE user_id = ? AND mail_id = ? AND content_id = ? AND content_id <> "" LIMIT 1');

    $updated = preg_replace_callback('/<img\b([^>]*?)\bsrc=(["\'])([^"\']*mail_attachment_download[^"\']*)\2([^>]*)>/i', function ($m) use ($userId, $mailId, $select, $existing, &$cache, &$changed) {
        $src = html_entity_decode((string)$m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $query = parse_url($src, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            $parts = parse_url('http://local/' . ltrim($src, '/'));
            $query = is_array($parts) ? (string)($parts['query'] ?? '') : '';
        }
        parse_str($query, $params);
        $oldId = (int)($params['attachment_id'] ?? 0);
        if ($oldId <= 0) return $m[0];
        if (isset($cache[$oldId])) {
            $newId = (int)$cache[$oldId];
            if ($newId <= 0 || $newId === $oldId) return $m[0];
            return '<img' . $m[1] . 'src=' . $m[2] . crm_mail_attachment_url($newId, true) . $m[2] . $m[4] . '>';
        }

        $select->execute([$oldId]);
        $row = $select->fetch();
        if (!$row) {
            $cache[$oldId] = 0;
            return $m[0];
        }
        if ((int)$row['user_id'] === $userId && (int)$row['mail_id'] === $mailId) {
            $cache[$oldId] = $oldId;
            return $m[0];
        }
        $mime = strtolower((string)($row['mime_type'] ?? ''));
        $isImage = strpos($mime, 'image/') === 0 || (int)($row['is_inline'] ?? 0) === 1 || (int)($row['is_signature_image'] ?? 0) === 1;
        $path = (string)($row['file_path'] ?? '');
        if (!$isImage || $path === '' || !is_file($path)) {
            $cache[$oldId] = 0;
            return $m[0];
        }

        $contentId = trim((string)($row['content_id'] ?? ''));
        if ($contentId === '') $contentId = 'crm-stale-inline-' . $oldId . '@artdon';
        $existing->execute([$userId, $mailId, $contentId]);
        $newId = (int)($existing->fetchColumn() ?: 0);
        if ($newId <= 0) {
            crm_mail_store_attachment_files($userId, $mailId, [[
                'name' => (string)($row['file_name'] ?: $row['filename'] ?: ('inline-image-' . $oldId)),
                'original_name' => (string)($row['original_filename'] ?: $row['file_name'] ?: $row['filename'] ?: ('inline-image-' . $oldId)),
                'tmp_name' => $path,
                'size' => (int)($row['file_size'] ?? 0),
                'type' => (string)($row['mime_type'] ?: 'image/png'),
                'attachment_type' => 'inline',
                'message_id' => (string)($row['message_id'] ?? ''),
                'content_id' => $contentId,
                'is_inline' => 1,
            ]]);
            $existing->execute([$userId, $mailId, $contentId]);
            $newId = (int)($existing->fetchColumn() ?: 0);
        }
        $cache[$oldId] = $newId;
        if ($newId <= 0) return $m[0];
        $changed = true;
        return '<img' . $m[1] . 'src=' . $m[2] . crm_mail_attachment_url($newId, true) . $m[2] . $m[4] . '>';
    }, $bodyHtml);

    $updated = is_string($updated) ? $updated : $bodyHtml;
    if ($changed && $updated !== $bodyHtml) {
        db()->prepare('UPDATE crm_mails SET body_html = ?, updated_at = NOW() WHERE id = ? AND user_id = ?')->execute([$updated, $mailId, $userId]);
        if (function_exists('crm_log_event')) {
            crm_log_event('mail', 'mail_repair_inline_links', 'mail', (string)$mailId, null, ['source' => 'stale_attachment_id']);
        }
    }
    return $updated;
}

function crm_mail_store_parsed_attachments_for_mail(array $account, int $mailId, array $parsedMail): array
{
    $userId = (int)($account['user_id'] ?? 0);
    $attachments = $parsedMail['attachments'] ?? [];
    $result = ['stored' => 0, 'visible' => 0, 'inline' => 0, 'failed' => 0, 'visible_count' => 0, 'total_count' => 0];
    if ($userId <= 0 || $mailId <= 0 || !$attachments) return $result;

    $existingStmt = db()->prepare('SELECT file_name, file_size, content_id, is_inline FROM crm_mail_attachments WHERE mail_id = ? AND user_id = ?');
    $existingStmt->execute([$mailId, $userId]);
    $existingKeys = [];
    foreach ($existingStmt->fetchAll() as $row) {
        $cid = trim((string)($row['content_id'] ?? ''));
        $key = $cid !== ''
            ? 'cid:' . strtolower($cid)
            : 'file:' . strtolower((string)($row['file_name'] ?? '')) . ':' . (int)($row['file_size'] ?? 0) . ':' . (int)($row['is_inline'] ?? 0);
        $existingKeys[$key] = true;
    }

    $missing = [];
    foreach ($attachments as $attachment) {
        $cid = trim((string)($attachment['content_id'] ?? ''));
        $name = crm_mail_safe_file_name((string)($attachment['name'] ?? 'attachment'));
        $key = $cid !== ''
            ? 'cid:' . strtolower($cid)
            : 'file:' . strtolower($name) . ':' . (int)($attachment['size'] ?? 0) . ':' . (!empty($attachment['is_inline']) ? 1 : 0);
        if (isset($existingKeys[$key])) continue;
        $existingKeys[$key] = true;
        $missing[] = $attachment;
    }

    if ($missing) {
        $stored = crm_mail_store_attachment_files($userId, $mailId, $missing);
        $result = array_merge($result, $stored);
        if ((int)($stored['inline'] ?? 0) > 0) {
            crm_mail_update_inline_body_urls($userId, $mailId, (string)($parsedMail['body_html'] ?? ''));
        }
    }

    $countStmt = db()->prepare('SELECT COUNT(*) FROM crm_mail_attachments WHERE mail_id = ? AND user_id = ? AND COALESCE(is_inline,0) = 0 AND COALESCE(is_signature_image,0) = 0');
    $countStmt->execute([$mailId, $userId]);
    $visibleCount = (int)$countStmt->fetchColumn();
    $totalStmt = db()->prepare('SELECT COUNT(*) FROM crm_mail_attachments WHERE mail_id = ? AND user_id = ?');
    $totalStmt->execute([$mailId, $userId]);
    $totalCount = (int)$totalStmt->fetchColumn();
    db()->prepare('UPDATE crm_mails SET has_attachment = ?, attachment_count = ?, updated_at = NOW() WHERE id = ? AND user_id = ?')
        ->execute([$visibleCount > 0 ? 1 : 0, $visibleCount, $mailId, $userId]);
    $result['visible_count'] = $visibleCount;
    $result['total_count'] = $totalCount;
    return $result;
}

function crm_mail_visible_attachment_count(array $attachments): int
{
    $count = 0;
    foreach ($attachments as $attachment) {
        if (!empty($attachment['is_inline']) || !empty($attachment['is_signature_image'])) continue;
        $count++;
    }
    return $count;
}

function crm_mail_input_ids($value): array
{
    if (is_array($value)) return array_values(array_unique(array_filter(array_map('intval', $value))));
    $value = trim((string)$value);
    if ($value === '') return [];
    $decoded = json_decode($value, true);
    if (is_array($decoded)) return crm_mail_input_ids($decoded);
    return array_values(array_unique(array_filter(array_map('intval', preg_split('/[,\s]+/', $value) ?: []))));
}

function crm_mail_original_attachments(array $account, array $input): array
{
    $ids = crm_mail_input_ids($input['forward_attachment_ids'] ?? '');
    if (!$ids) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge($ids, [(int)$account['user_id']]);
    $stmt = db()->prepare("SELECT id, file_name, file_path, file_size, mime_type FROM crm_mail_attachments WHERE id IN ({$placeholders}) AND user_id = ? AND COALESCE(is_inline,0) = 0 AND COALESCE(is_signature_image,0) = 0");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    if (count($rows) !== count($ids)) throw new RuntimeException('部分原附件不存在或无权转发。');
    $attachments = [];
    foreach ($rows as $row) {
        $path = (string)($row['file_path'] ?? '');
        if ($path === '' || !is_file($path)) throw new RuntimeException('原附件文件未保存，无法带附件转发：' . (string)$row['file_name']);
        $attachments[] = [
            'name' => (string)$row['file_name'],
            'tmp_name' => $path,
            'size' => (int)$row['file_size'],
            'type' => (string)($row['mime_type'] ?: 'application/octet-stream'),
            'path' => $path,
        ];
    }
    return $attachments;
}

function crm_mail_queue_dir(int $userId, string $jobId): string
{
    $safeJob = preg_replace('/[^A-Za-z0-9_-]+/', '_', $jobId) ?: ('send_' . time());
    $base = __DIR__ . '/storage';
    $root = $base . '/mail_send_queue';
    $userDir = $root . '/' . $userId;
    $dir = $userDir . '/' . $safeJob;
    foreach ([$base, $root, $userDir, $dir] as $path) {
        if (!is_dir($path)) @mkdir($path, 0775, true);
    }
    return $dir;
}

function crm_mail_queue_attachment_files(int $userId, string $jobId, array $attachments): array
{
    if (!$attachments) return [];
    $dir = crm_mail_queue_dir($userId, $jobId);
    if (!is_dir($dir) || !is_writable($dir)) throw new RuntimeException('延迟发送附件目录不可写。');
    $queued = [];
    foreach ($attachments as $index => $attachment) {
        $name = crm_mail_safe_file_name((string)($attachment['name'] ?? ('attachment-' . ($index + 1))));
        $target = $dir . '/' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '_' . $name;
        $content = $attachment['content'] ?? null;
        $source = (string)($attachment['tmp_name'] ?? ($attachment['path'] ?? ''));
        if (is_string($content)) {
            if (@file_put_contents($target, $content) === false) throw new RuntimeException('延迟发送附件保存失败：' . $name);
            if (crm_mail_is_generated_temp_file($source) && is_file($source)) @unlink($source);
        } elseif ($source !== '' && is_file($source)) {
            if (!@copy($source, $target)) throw new RuntimeException('延迟发送附件保存失败：' . $name);
            if (crm_mail_is_generated_temp_file($source)) @unlink($source);
        } else {
            throw new RuntimeException('延迟发送附件不存在：' . $name);
        }
        $queued[] = [
            'name' => $name,
            'tmp_name' => $target,
            'path' => $target,
            'size' => (int)(filesize($target) ?: ($attachment['size'] ?? 0)),
            'type' => (string)($attachment['type'] ?? 'application/octet-stream'),
            'attachment_type' => (string)($attachment['attachment_type'] ?? 'normal'),
            'content_id' => (string)($attachment['content_id'] ?? ''),
            'is_inline' => (int)($attachment['is_inline'] ?? 0),
        ];
    }
    return $queued;
}

function crm_mail_cleanup_queue_files(array $attachments): void
{
    foreach ($attachments as $attachment) {
        $path = (string)($attachment['path'] ?? $attachment['tmp_name'] ?? '');
        if ($path !== '' && strpos($path, __DIR__ . '/storage/mail_send_queue/') === 0 && is_file($path)) @unlink($path);
    }
}

function crm_mail_draft_attachment_files(array $account, array $input): array
{
    $raw = (string)($input['attachments_json'] ?? '');
    if ($raw === '') return [];
    $rows = json_decode($raw, true);
    if (!is_array($rows)) return [];
    $root = __DIR__ . '/storage/mail_send_queue/' . (int)$account['user_id'] . '/';
    $attachments = [];
    foreach ($rows as $index => $row) {
        if (!is_array($row)) continue;
        $path = (string)($row['path'] ?? $row['tmp_name'] ?? '');
        $real = $path !== '' ? realpath($path) : false;
        $realRoot = realpath($root);
        if (!$real || !$realRoot || strpos($real, $realRoot . DIRECTORY_SEPARATOR) !== 0 || !is_file($real)) {
            throw new RuntimeException('草稿附件不存在或无权读取：' . (string)($row['name'] ?? ('附件' . ($index + 1))));
        }
        $attachments[] = [
            'name' => crm_mail_safe_file_name((string)($row['name'] ?? basename($real))),
            'tmp_name' => $real,
            'path' => $real,
            'size' => (int)(filesize($real) ?: ($row['size'] ?? 0)),
            'type' => (string)($row['type'] ?? 'application/octet-stream'),
            'attachment_type' => (string)($row['attachment_type'] ?? 'normal'),
            'content_id' => (string)($row['content_id'] ?? ''),
            'is_inline' => (int)($row['is_inline'] ?? 0),
        ];
    }
    return $attachments;
}

function crm_mail_ensure_datasheet_runtime(): void
{
    require_once __DIR__ . '/datasheet_lib.php';
    ds_ensure_tables();
}

function crm_mail_datasheet_chrome_bin(): string
{
    static $bin = null;
    if ($bin !== null) return $bin;
    foreach (['/usr/bin/google-chrome', '/usr/bin/google-chrome-stable', '/usr/bin/chromium', '/usr/bin/chromium-browser'] as $path) {
        if (is_file($path) && is_executable($path)) {
            $bin = $path;
            return $bin;
        }
    }
    if (function_exists('shell_exec')) {
        $found = trim((string)@shell_exec('command -v google-chrome || command -v chromium || command -v chromium-browser 2>/dev/null'));
        $bin = $found !== '' ? $found : '';
        return $bin;
    }
    if (!function_exists('exec')) {
        $bin = '';
        return $bin;
    }
    $out = [];
    $code = 1;
    @exec('command -v google-chrome || command -v chromium || command -v chromium-browser', $out, $code);
    $bin = $code === 0 && !empty($out[0]) ? trim((string)$out[0]) : '';
    return $bin;
}

function crm_mail_datasheet_temp_dir(): string
{
    $root = __DIR__ . DIRECTORY_SEPARATOR . 'storage';
    if (!is_dir($root)) @mkdir($root, 0775, true);
    $dir = $root . DIRECTORY_SEPARATOR . 'mail_datasheet_tmp';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (!is_writable($dir)) {
        $fallback = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'crm_mail_attach_datasheet_' . get_current_user();
        if (!is_dir($fallback)) @mkdir($fallback, 0775, true);
        $dir = $fallback;
    }
    return $dir;
}

function crm_mail_is_generated_temp_file(string $path): bool
{
    if ($path === '') return false;
    $prefixes = [
        sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'crm_mail_attach_',
        sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'crm_mail_attach_inline_',
        sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'crm_mail_attach_datasheet_' . get_current_user() . DIRECTORY_SEPARATOR . 'crm_mail_attach_',
        __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'mail_datasheet_tmp' . DIRECTORY_SEPARATOR . 'crm_mail_attach_',
    ];
    foreach ($prefixes as $prefix) {
        if (strpos($path, $prefix) === 0) return true;
    }
    return false;
}

function crm_mail_public_base_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'novlight.com');
    $scriptDir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/artdon_erp/crm_api.php'))), '/');
    return $scheme . '://' . $host . ($scriptDir ? $scriptDir . '/' : '/');
}

function crm_mail_datasheet_search(array $input): array
{
    crm_require('mail.send');
    crm_mail_ensure_datasheet_runtime();
    $q = trim((string)($input['q'] ?? $input['model_no'] ?? ''));
    if ($q === '') throw new RuntimeException('请输入资料生成系统型号。');
    $result = ds_products(['kw' => $q, 'per_page' => 8]);
    $products = [];
    $canPdf = true;
    foreach (($result['rows'] ?? []) as $row) {
        $model = (string)($row['model_no'] ?? '');
        if ($model === '') continue;
        $attachments = [];
        $existingFiles = [];
        foreach (ds_files($model) as $file) {
            $name = (string)($file['original_name'] ?: ($file['file_title'] ?: ''));
            $path = (string)($file['file_path'] ?? '');
            if (!preg_match('/\.(pdf|xls|xlsx)$/i', $name . ' ' . $path)) continue;
            $ext = strtolower(pathinfo($name ?: $path, PATHINFO_EXTENSION));
            $existingFiles[] = [
                'source' => 'file',
                'format' => $ext ?: 'file',
                'id' => (int)$file['id'],
                'model_no' => $model,
                'label' => '资料系统 ' . strtoupper($ext ?: 'FILE'),
            ];
        }
        usort($existingFiles, static function ($a, $b) {
            $rank = ['pdf' => 0, 'xlsx' => 1, 'xls' => 2];
            return ($rank[$a['format']] ?? 9) <=> ($rank[$b['format']] ?? 9);
        });
        $attachments[] = [
            'source' => 'generated',
            'format' => 'pdf',
            'id' => (int)($row['id'] ?? 0),
            'model_no' => $model,
            'label' => '快照 PDF',
            'disabled' => 0,
        ];
        foreach ($existingFiles as $fileRef) $attachments[] = $fileRef;
        $attachments[] = [
            'source' => 'generated',
            'format' => 'excel',
            'id' => (int)($row['id'] ?? 0),
            'model_no' => $model,
            'label' => '生成 Excel',
        ];
        $products[] = [
            'id' => (int)($row['id'] ?? 0),
            'model_no' => $model,
            'title' => (string)($row['title'] ?? $row['series'] ?? ''),
            'category' => (string)($row['category'] ?? ''),
            'attachments' => $attachments,
        ];
    }
    return ['products' => $products, 'total' => (int)($result['total'] ?? count($products)), 'pdf_available' => $canPdf ? 1 : 0];
}

function crm_mail_decode_datasheet_refs($value): array
{
    if (is_array($value)) $rows = $value;
    else {
        $raw = trim((string)$value);
        if ($raw === '') return [];
        $rows = json_decode($raw, true);
    }
    if (!is_array($rows)) return [];
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $source = (string)($row['source'] ?? '');
        $format = strtolower((string)($row['format'] ?? ''));
        $model = trim((string)($row['model_no'] ?? ''));
        $id = (int)($row['id'] ?? 0);
        if ($model === '' || !in_array($source, ['generated', 'file'], true)) continue;
        if ($source === 'generated' && !in_array($format, ['pdf', 'excel'], true)) continue;
        $out[] = ['source' => $source, 'format' => $format, 'model_no' => $model, 'id' => $id];
    }
    return $out;
}

function crm_mail_datasheet_excel_file(array $product): array
{
    $model = (string)$product['model_no'];
    $params = (array)($product['params'] ?? []);
    $files = array_map(static function ($f) {
        return (string)(($f['file_type'] ?? '') . ' - ' . (($f['file_title'] ?? '') ?: ($f['original_name'] ?? '')));
    }, (array)($product['files'] ?? []));
    $html = "\xEF\xBB\xBF" . '<html><head><meta charset="utf-8"><style>table{border-collapse:collapse}td,th{border:1px solid #999;padding:6px;font-family:Arial,"Microsoft YaHei";font-size:12px}th{background:#f1f5f9}</style></head><body>';
    $html .= '<h2>Artdon Lighting Limited - Product Datasheet</h2><table><tbody>';
    $rows = [
        'Model' => $model,
        'Title' => (string)($product['title'] ?? ''),
        'Series' => (string)($product['series'] ?? ''),
        'Category' => (string)($product['category'] ?? ''),
        'Type' => (string)($product['type_name'] ?? ''),
        'Dimensions' => (string)($product['dimension_text'] ?? ''),
    ];
    foreach ($params as $label => $value) $rows[(string)$label] = (string)$value;
    $rows['Files'] = implode("\n", array_filter($files));
    foreach ($rows as $label => $value) $html .= '<tr><th>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</th><td>' . nl2br(htmlspecialchars($value, ENT_QUOTES, 'UTF-8')) . '</td></tr>';
    $html .= '</tbody></table></body></html>';
    $path = crm_mail_datasheet_temp_dir() . DIRECTORY_SEPARATOR . 'crm_mail_attach_' . bin2hex(random_bytes(6)) . '_' . crm_mail_safe_file_name($model . '_Datasheet.xls');
    file_put_contents($path, $html);
    return ['name' => $model . '_Datasheet.xls', 'tmp_name' => $path, 'size' => filesize($path) ?: strlen($html), 'type' => 'application/vnd.ms-excel', 'attachment_type' => 'datasheet'];
}

function crm_mail_datasheet_snapshot_product(array $product): array
{
    $model = (string)($product['model_no'] ?? '');
    if ($model === '') return $product;
    try {
        if (function_exists('ds_naming_row') && function_exists('ds_website_cache_get') && function_exists('ds_refresh_website_cache_for_product')) {
            $row = ds_naming_row((int)($product['id'] ?? 0), $model);
            if (is_array($row) && !empty($row)) {
                $cache = ds_website_cache_get($row);
                $needsWebsiteSnapshot = empty($cache['params']) || empty($cache['photometric_images']) || empty($cache['accessory_items']);
                if ($needsWebsiteSnapshot) {
                    try {
                        ds_refresh_website_cache_for_product($row);
                    } catch (Throwable $refreshError) {
                        error_log('crm_mail_datasheet website cache refresh failed: ' . $refreshError->getMessage());
                    }
                }
            }
        }
        $snapshotId = ds_create_snapshot($model, (int)($product['id'] ?? 0), '', '', 'CRM mail attachment PDF', 'mail_attachment_pdf');
        $snapshot = ds_snapshot_detail($snapshotId);
        $payload = is_array($snapshot['snapshot'] ?? null) ? $snapshot['snapshot'] : [];
        $snapProduct = is_array($payload['product'] ?? null) ? $payload['product'] : $product;
        foreach (['params','files','photometric_images','manual_accessories','highres_images','accessories','website_accessories','website_photometric_images','configs'] as $key) {
            if (array_key_exists($key, $payload)) $snapProduct[$key] = $payload[$key];
        }
        $snapProduct['snapshot_id'] = $snapshotId;
        $snapProduct['snapshot_created_at'] = (string)($payload['created_at'] ?? ($snapshot['created_at'] ?? ''));
        return $snapProduct;
    } catch (Throwable $e) {
        error_log('crm_mail_datasheet_snapshot_product failed: ' . $e->getMessage());
        return $product;
    }
}

function crm_mail_datasheet_pdf_html(array $product): string
{
    $snapshotProduct = crm_mail_datasheet_snapshot_product($product);
    $snapshotId = (int)($snapshotProduct['snapshot_id'] ?? 0);
    if ($snapshotId <= 0) {
        throw new RuntimeException('资料快照生成失败，无法输出正式 PDF。');
    }
    $oldGet = $_GET;
    $_GET = ['snapshot_id' => $snapshotId, 'logo' => '0'];
    if (!defined('DS_PDF_EMBED')) define('DS_PDF_EMBED', true);
    if (!defined('DS_PDF_BASE_URL')) define('DS_PDF_BASE_URL', crm_mail_public_base_url());
    ob_start();
    try {
        include __DIR__ . '/datasheet_pdf.php';
        $html = (string)ob_get_clean();
    } catch (Throwable $e) {
        ob_end_clean();
        $_GET = $oldGet;
        throw $e;
    }
    $_GET = $oldGet;
    return $html;
}

function crm_mail_pdf_escape_text(string $value): string
{
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($converted) && $converted !== '') $value = $converted;
    }
    $value = preg_replace('/[^\x20-\x7E]/', '', $value) ?? '';
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
}

function crm_mail_pdf_wrap_text(string $text, int $limit = 78): array
{
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
    if ($text === '') return [''];
    $words = preg_split('/\s+/', $text) ?: [];
    $lines = [];
    $line = '';
    foreach ($words as $word) {
        if ($line === '') {
            $line = $word;
            continue;
        }
        if (strlen($line . ' ' . $word) > $limit) {
            $lines[] = $line;
            $line = $word;
        } else {
            $line .= ' ' . $word;
        }
    }
    if ($line !== '') $lines[] = $line;
    return $lines ?: [''];
}

function crm_mail_pdf_build(array $lines): string
{
    $objects = [];
    $content = "BT\n/F1 12 Tf\n50 790 Td\n14 TL\n";
    foreach ($lines as $line) {
        $content .= '(' . crm_mail_pdf_escape_text($line) . ") Tj\nT*\n";
    }
    $content .= "ET\n";
    $objects[] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objects[] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
    $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>";
    $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
    $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>";
    $objects[] = "<< /Length " . strlen($content) . " >>\nstream\n{$content}endstream";
    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [0];
    foreach ($objects as $i => $obj) {
        $offsets[] = strlen($pdf);
        $num = $i + 1;
        $pdf .= "{$num} 0 obj\n{$obj}\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    return $pdf;
}

function crm_mail_datasheet_pdf_file_pure(array $product): array
{
    $model = (string)($product['model_no'] ?? '');
    if ($model === '') throw new RuntimeException('缺少资料型号，无法生成 PDF。');
    $title = (string)($product['title'] ?? $model);
    $params = array_filter((array)($product['params'] ?? []), static function ($value) {
        return trim((string)$value) !== '';
    });
    $lines = [
        'Artdon Lighting Limited',
        'Product Datasheet',
        '',
        'Model: ' . $model,
        'Title: ' . $title,
        'Series: ' . (string)($product['series'] ?? ''),
        'Category: ' . (string)($product['category'] ?? ''),
        'Type: ' . (string)($product['type_name'] ?? ''),
        'Dimensions: ' . (string)($product['dimension_text'] ?? ''),
        '',
        'Specifications',
    ];
    foreach ($params as $label => $value) {
        foreach (crm_mail_pdf_wrap_text((string)$label . ': ' . (string)$value, 74) as $line) {
            $lines[] = $line;
            if (count($lines) >= 52) break 2;
        }
    }
    $lines[] = '';
    $lines[] = 'Generated from Artdon Datasheet Center.';
    $pdf = crm_mail_pdf_build($lines);
    $path = crm_mail_datasheet_temp_dir() . DIRECTORY_SEPARATOR . 'crm_mail_attach_' . bin2hex(random_bytes(6)) . '_' . crm_mail_safe_file_name($model . '_Datasheet.pdf');
    file_put_contents($path, $pdf);
    return ['name' => $model . '_Datasheet.pdf', 'tmp_name' => $path, 'size' => filesize($path) ?: strlen($pdf), 'type' => 'application/pdf', 'attachment_type' => 'datasheet'];
}

function crm_mail_datasheet_pdf_file(array $product): array
{
    $chrome = crm_mail_datasheet_chrome_bin();
    if ($chrome === '' || (!function_exists('exec') && !function_exists('shell_exec'))) return crm_mail_datasheet_pdf_file_pure($product);
    $model = (string)($product['model_no'] ?? '');
    if ($model === '') throw new RuntimeException('缺少资料型号，无法生成 PDF。');
    $html = crm_mail_datasheet_pdf_html($product);
    $dir = crm_mail_datasheet_temp_dir();
    $htmlPath = $dir . DIRECTORY_SEPARATOR . 'crm_mail_attach_' . bin2hex(random_bytes(6)) . '.html';
    $pdfPath = $dir . DIRECTORY_SEPARATOR . 'crm_mail_attach_' . bin2hex(random_bytes(6)) . '_' . crm_mail_safe_file_name($model . '_Datasheet.pdf');
    file_put_contents($htmlPath, $html);
    $cmd = escapeshellarg($chrome) . ' --headless --no-sandbox --disable-gpu --disable-dev-shm-usage --print-to-pdf=' . escapeshellarg($pdfPath) . ' ' . escapeshellarg('file://' . $htmlPath) . ' 2>&1';
    $out = [];
    $code = 1;
    if (function_exists('exec')) {
        @exec($cmd, $out, $code);
    } else {
        $output = (string)@shell_exec($cmd);
        $out = $output !== '' ? [$output] : [];
        $code = is_file($pdfPath) && filesize($pdfPath) >= 1000 ? 0 : 1;
    }
    @unlink($htmlPath);
    if ($code !== 0 || !is_file($pdfPath) || filesize($pdfPath) < 1000) {
        @unlink($pdfPath);
        throw new RuntimeException('PDF 生成失败：' . trim(implode(' ', $out)));
    }
    return ['name' => $model . '_Datasheet.pdf', 'tmp_name' => $pdfPath, 'size' => filesize($pdfPath) ?: 0, 'type' => 'application/pdf', 'attachment_type' => 'datasheet'];
}

function crm_mail_datasheet_attachments(array $input): array
{
    $refs = crm_mail_decode_datasheet_refs($input['datasheet_attachment_refs'] ?? '');
    if (!$refs) return [];
    crm_mail_ensure_datasheet_runtime();
    $attachments = [];
    foreach ($refs as $ref) {
        if ($ref['source'] === 'file') {
            $stmt = ds_db()->prepare('SELECT * FROM datasheet_files WHERE id=? AND model_no=? AND is_deleted=0 LIMIT 1');
            $stmt->execute([(int)$ref['id'], (string)$ref['model_no']]);
            $file = $stmt->fetch();
            if (!$file) throw new RuntimeException('资料附件不存在或已删除：' . $ref['model_no']);
            $path = ds_local_path((string)$file['file_path']);
            if ($path === '' || !is_file($path)) throw new RuntimeException('资料附件文件不存在：' . (string)($file['original_name'] ?? $ref['model_no']));
            $attachments[] = ['name' => (string)($file['original_name'] ?: basename($path)), 'tmp_name' => $path, 'size' => (int)($file['size_bytes'] ?: filesize($path)), 'type' => (string)($file['mime_type'] ?: 'application/octet-stream'), 'attachment_type' => 'datasheet'];
            continue;
        }
        $product = ds_product_detail_fast((int)$ref['id'], (string)$ref['model_no'], true);
        if ($ref['format'] === 'excel') {
            $attachments[] = crm_mail_datasheet_excel_file($product);
        } elseif ($ref['format'] === 'pdf') {
            $attachments[] = crm_mail_datasheet_pdf_file($product);
        }
    }
    foreach ($attachments as $attachment) {
        $path = (string)($attachment['tmp_name'] ?? '');
        if ($path === '' || !is_file($path) || filesize($path) <= 0) {
            throw new RuntimeException('资料附件生成失败，请重新获取资料后再发送：' . (string)($attachment['name'] ?? '资料附件'));
        }
    }
    return $attachments;
}

function crm_mail_datasheet_preview_file(array $input): array
{
    crm_require('mail.send');
    $ref = $input['ref'] ?? '';
    if (is_string($ref)) {
        $decoded = json_decode($ref, true);
        $ref = is_array($decoded) ? $decoded : [];
    }
    $refs = crm_mail_decode_datasheet_refs([$ref]);
    if (!$refs) throw new RuntimeException('资料预览参数无效。');
    $ref = $refs[0];
    crm_mail_ensure_datasheet_runtime();
    if ($ref['source'] === 'file') {
        $stmt = ds_db()->prepare('SELECT * FROM datasheet_files WHERE id=? AND model_no=? AND is_deleted=0 LIMIT 1');
        $stmt->execute([(int)$ref['id'], (string)$ref['model_no']]);
        $file = $stmt->fetch();
        if (!$file) throw new RuntimeException('资料文件不存在或已删除。');
        $path = ds_local_path((string)$file['file_path']);
        if ($path === '' || !is_file($path)) throw new RuntimeException('资料文件不存在。');
        $mime = (string)($file['mime_type'] ?: 'application/octet-stream');
        $name = (string)($file['original_name'] ?: basename($path));
        $isPdf = stripos($mime, 'pdf') !== false || preg_match('/\.pdf$/i', $name);
        if (!$isPdf) throw new RuntimeException('当前资料不是 PDF，暂不支持在线预览，请作为附件发送或下载查看。');
        return ['path' => $path, 'name' => $name, 'mime' => 'application/pdf', 'temporary' => false];
    }
    if ($ref['format'] !== 'pdf') {
        throw new RuntimeException('当前格式暂不支持在线预览，请选择 PDF。');
    }
    $product = ds_product_detail_fast((int)$ref['id'], (string)$ref['model_no'], true);
    $file = crm_mail_datasheet_pdf_file($product);
    $path = (string)($file['tmp_name'] ?? '');
    if ($path === '' || !is_file($path) || filesize($path) <= 0) {
        throw new RuntimeException('PDF 预览生成失败。');
    }
    return ['path' => $path, 'name' => (string)($file['name'] ?? ($ref['model_no'] . '_Datasheet.pdf')), 'mime' => 'application/pdf', 'temporary' => true];
}

function crm_mail_build_message(array $account, array $input, array $to, array $cc = [], array $bcc = [], array $attachments = []): string
{
    $subject = trim((string)($input['subject'] ?? ''));
    $body = crm_mail_render_signature_variables((string)($input['body_html'] ?? ''), $account);
    $fromName = trim((string)($account['sender_name'] ?: $account['email_address']));
    $messageId = trim((string)($input['_message_id_header'] ?? ''));
    if ($messageId === '') $messageId = crm_mail_generate_message_id($account);
    $replyHeaders = [];
    $inReplyTo = trim((string)($input['_in_reply_to_header'] ?? ''));
    $references = trim((string)($input['_references_header'] ?? ''));
    if ($inReplyTo !== '') $replyHeaders[] = 'In-Reply-To: ' . $inReplyTo;
    if ($references !== '') $replyHeaders[] = 'References: ' . $references;
    if ($attachments) {
        $inlineAttachments = [];
        $normalAttachments = [];
        foreach ($attachments as $file) {
            if (!empty($file['is_inline'])) $inlineAttachments[] = $file;
            else $normalAttachments[] = $file;
        }
        $hasInline = !empty($inlineAttachments);
        $hasNormal = !empty($normalAttachments);
        $mixedBoundary = '=_artdon_mixed_' . bin2hex(random_bytes(8));
        $relatedBoundary = '=_artdon_related_' . bin2hex(random_bytes(8));
        $topBoundary = $hasNormal ? $mixedBoundary : ($hasInline ? $relatedBoundary : $mixedBoundary);
        $topType = $hasNormal ? 'multipart/mixed' : ($hasInline ? 'multipart/related' : 'multipart/mixed');
        $headers = [
            'From: ' . crm_mail_header_encode($fromName) . ' <' . $account['email_address'] . '>',
            'To: ' . implode(', ', $to),
            'Subject: ' . crm_mail_header_encode($subject),
            'Date: ' . date(DATE_RFC2822),
            'Message-ID: ' . $messageId,
            'MIME-Version: 1.0',
            'Content-Type: ' . $topType . '; boundary="' . $topBoundary . '"',
        ];
        if ($cc) $headers[] = 'Cc: ' . implode(', ', $cc);
        if ($replyHeaders) $headers = array_merge($headers, $replyHeaders);
        $parts = [($headers ? implode("\r\n", $headers) : '') . "\r\n"];

        $htmlPart = "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($body));
        if ($hasInline && $hasNormal) {
            $parts[] = '--' . $mixedBoundary . "\r\nContent-Type: multipart/related; boundary=\"" . $relatedBoundary . "\"\r\n";
            $parts[] = '--' . $relatedBoundary . "\r\n" . $htmlPart;
        } else {
            $parts[] = '--' . $topBoundary . "\r\n" . $htmlPart;
        }

        foreach ($inlineAttachments as $file) {
            $inlineContent = $file['content'] ?? null;
            $source = (string)($file['tmp_name'] ?? ($file['path'] ?? ''));
            $content = is_string($inlineContent) ? $inlineContent : (($source !== '' && is_file($source)) ? file_get_contents($source) : '');
            if ($content === false || $content === '') continue;
            $parts[] = '--' . ($hasNormal ? $relatedBoundary : $topBoundary) . "\r\n" . crm_mail_attachment_header_block($file) . "\r\n\r\n" . chunk_split(base64_encode($content));
        }

        if ($hasInline && $hasNormal) $parts[] = '--' . $relatedBoundary . '--';

        foreach ($normalAttachments as $file) {
            $inlineContent = $file['content'] ?? null;
            $source = (string)($file['tmp_name'] ?? ($file['path'] ?? ''));
            $content = is_string($inlineContent) ? $inlineContent : (($source !== '' && is_file($source)) ? file_get_contents($source) : '');
            if ($content === false || $content === '') continue;
            $parts[] = '--' . $mixedBoundary . "\r\n" . crm_mail_attachment_header_block($file) . "\r\n\r\n" . chunk_split(base64_encode($content));
        }
        $parts[] = '--' . $topBoundary . '--';
        return implode("\r\n", $parts);
    }
    $headers = [
        'From: ' . crm_mail_header_encode($fromName) . ' <' . $account['email_address'] . '>',
        'To: ' . implode(', ', $to),
        'Subject: ' . crm_mail_header_encode($subject),
        'Date: ' . date(DATE_RFC2822),
        'Message-ID: ' . $messageId,
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
    ];
    if ($cc) $headers[] = 'Cc: ' . implode(', ', $cc);
    if ($replyHeaders) $headers = array_merge($headers, $replyHeaders);
    $headers[] = '';
    $headers[] = chunk_split(base64_encode($body));
    return implode("\r\n", $headers);
}

function crm_mail_smtp_send(array $account, array $input, array $attachments = []): array
{
    $to = crm_mail_parse_addresses((string)($input['to_emails'] ?? ''));
    $cc = crm_mail_parse_addresses((string)($input['cc_emails'] ?? ''));
    $bcc = crm_mail_parse_addresses((string)($input['bcc_emails'] ?? ''));
    $recipients = array_values(array_unique(array_merge($to, $cc, $bcc)));
    if (!$recipients) throw new RuntimeException('收件人不能为空。');
    $messageId = trim((string)($input['_message_id_header'] ?? ''));
    if ($messageId === '') $messageId = crm_mail_generate_message_id($account);
    $input['_message_id_header'] = $messageId;
    $fp = crm_mail_smtp_connect($account);
    try {
        crm_mail_smtp_cmd($fp, 'MAIL FROM:<' . $account['email_address'] . '>', ['250']);
        foreach ($recipients as $recipient) {
            crm_mail_smtp_cmd($fp, 'RCPT TO:<' . $recipient . '>', ['250', '251']);
        }
        crm_mail_smtp_cmd($fp, 'DATA', ['354']);
        $message = crm_mail_build_message($account, $input, $to, $cc, $bcc, $attachments);
        fwrite($fp, str_replace("\n.", "\n..", $message) . "\r\n.\r\n");
        $response = crm_mail_smtp_expect($fp, ['250']);
        @crm_mail_smtp_cmd($fp, 'QUIT', ['221', '250']);
        fclose($fp);
        return ['recipients' => $recipients, 'response' => $response, 'attachment_count' => count($attachments), 'message_id_header' => $messageId];
    } catch (Throwable $e) {
        @fwrite($fp, "QUIT\r\n");
        @fclose($fp);
        throw $e;
    }
}

function crm_mail_imap_connect(array $account)
{
    $host = (string)$account['imap_host'];
    $port = (int)$account['imap_port'];
    $secure = strtolower((string)$account['imap_secure']);
    $target = $secure === 'ssl' ? 'ssl://' . $host : $host;
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($target, $port, $errno, $errstr, 15);
    if (!$fp) throw new RuntimeException('IMAP 连接失败：' . ($errstr ?: '无法连接服务器') . ($errno ? " ({$errno})" : ''));
    stream_set_timeout($fp, 25);
    crm_mail_read_line($fp);
    return $fp;
}

function crm_mail_imap_command($fp, string $tag, string $command): string
{
    fwrite($fp, $tag . ' ' . $command . "\r\n");
    $response = '';
    while (!feof($fp)) {
        $line = fgets($fp, 8192);
        if ($line === false) break;
        $response .= $line;
        if (preg_match('/^' . preg_quote($tag, '/') . ' (OK|NO|BAD)/', $line, $m)) {
            if ($m[1] !== 'OK') throw new RuntimeException('IMAP 返回异常：' . trim($line));
            return $response;
        }
    }
    throw new RuntimeException('IMAP 命令无响应：' . $command);
}

function crm_mail_imap_login(array $account)
{
    $fp = crm_mail_imap_connect($account);
    $user = addcslashes((string)$account['email_username'], "\\\"");
    $pass = addcslashes((string)$account['mail_secret'], "\\\"");
    crm_mail_imap_command($fp, 'A001', 'LOGIN "' . $user . '" "' . $pass . '"');
    return $fp;
}

function crm_mail_decode_header_value(string $value): string
{
    $value = trim($value);
    if ($value === '') return '';
    if (strpos($value, '=?') === false && (!function_exists('mb_check_encoding') || mb_check_encoding($value, 'UTF-8'))) {
        return crm_mail_repair_text($value);
    }
    if (function_exists('iconv_mime_decode')) {
        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        if (is_string($decoded) && $decoded !== '') return crm_mail_repair_text($decoded);
    }
    if (function_exists('mb_decode_mimeheader')) {
        $old = function_exists('mb_internal_encoding') ? mb_internal_encoding() : null;
        if (function_exists('mb_internal_encoding')) @mb_internal_encoding('UTF-8');
        $decoded = @mb_decode_mimeheader($value);
        if ($old && function_exists('mb_internal_encoding')) @mb_internal_encoding($old);
        if (is_string($decoded) && $decoded !== '') return crm_mail_repair_text($decoded);
    }
    $decoded = preg_replace_callback('/=\?([^?]+)\?([BQ])\?([^?]+)\?=/i', function ($m) {
        $raw = strtoupper($m[2]) === 'B' ? (base64_decode($m[3]) ?: '') : quoted_printable_decode(str_replace('_', ' ', $m[3]));
        return crm_mail_to_utf8($raw, $m[1]);
    }, $value) ?? $value;
    return crm_mail_repair_text($decoded);
}

function crm_mail_to_utf8(string $value, string $charset = ''): string
{
    $charset = trim($charset, "\"' \t\r\n");
    $aliases = [
        'gb2312' => 'GB18030',
        'gbk' => 'GB18030',
        'cp936' => 'GB18030',
        'ks_c_5601-1987' => 'CP949',
        'euc-kr' => 'CP949',
        'shift_jis' => 'SJIS-win',
        'iso-2022-jp' => 'ISO-2022-JP',
        'windows-1252' => 'Windows-1252',
        'cp1252' => 'Windows-1252',
        'latin1' => 'ISO-8859-1',
        'iso8859-1' => 'ISO-8859-1',
    ];
    $lookup = strtolower($charset);
    if (isset($aliases[$lookup])) $charset = $aliases[$lookup];
    if ($value === '') return '';
    if ($charset === '' || strcasecmp($charset, 'utf-8') === 0 || strcasecmp($charset, 'us-ascii') === 0) {
        if (!function_exists('mb_check_encoding') || mb_check_encoding($value, 'UTF-8')) return crm_mail_repair_text($value);
        if (function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($value, 'UTF-8', 'UTF-8,GB18030,GBK,BIG5,Windows-1252,ISO-8859-1');
            if (is_string($converted) && $converted !== '') return crm_mail_repair_text($converted);
        }
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
            if (is_string($converted)) return crm_mail_repair_text($converted);
        }
        return preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $value) ?? '';
    }
    if (function_exists('mb_convert_encoding')) {
        $converted = @mb_convert_encoding($value, 'UTF-8', $charset);
        if (is_string($converted) && $converted !== '') return crm_mail_repair_text($converted);
    }
    if (function_exists('iconv')) {
        $converted = @iconv($charset, 'UTF-8//IGNORE', $value);
        if (is_string($converted) && $converted !== '') return crm_mail_repair_text($converted);
    }
    return crm_mail_repair_text($value);
}

function crm_mail_detect_inline_charset(string $body): string
{
    if (preg_match('/<meta[^>]+charset=["\']?\s*([A-Za-z0-9_\-]+)\s*["\']?/i', $body, $m)) return $m[1];
    if (preg_match('/charset\s*=\s*["\']?\s*([A-Za-z0-9_\-]+)\s*["\']?/i', $body, $m)) return $m[1];
    return '';
}

function crm_mail_repair_text(string $value): string
{
    if ($value === '') return '';
    $value = str_replace("\0", '', $value);
    if (preg_match('/(Ã.|Â.|â€™|â€œ|â€|æ|è|å|ä|ç)/u', $value) && function_exists('mb_convert_encoding')) {
        $bytes = @iconv('UTF-8', 'Windows-1252//IGNORE', $value);
        if (!is_string($bytes) || $bytes === '') $bytes = @iconv('UTF-8', 'ISO-8859-1//IGNORE', $value);
        if (is_string($bytes) && $bytes !== '' && (!function_exists('mb_check_encoding') || mb_check_encoding($bytes, 'UTF-8'))) {
            $oldBad = preg_match_all('/�|Ã.|Â.|â€™|â€œ|â€|锟斤拷/u', $value);
            $newBad = preg_match_all('/�|Ã.|Â.|â€™|â€œ|â€|锟斤拷/u', $bytes);
            if ($newBad < $oldBad || preg_match('/[\x{4e00}-\x{9fff}]/u', $bytes)) $value = $bytes;
        }
        $fixed = @mb_convert_encoding($value, 'UTF-8', 'Windows-1252,ISO-8859-1');
        if (is_string($fixed) && $fixed !== '' && $fixed !== $value) {
            $oldBad = preg_match_all('/�|Ã.|Â.|â€™|â€œ|â€|锟斤拷/u', $value);
            $newBad = preg_match_all('/�|Ã.|Â.|â€™|â€œ|â€|锟斤拷/u', $fixed);
            if ($newBad < $oldBad || preg_match('/[\x{4e00}-\x{9fff}]/u', $fixed)) $value = $fixed;
        }
    }
    if (strpos($value, '=?') !== false) {
        $value = preg_replace_callback('/=\?([^?]+)\?([BQ])\?([^?]+)\?=/i', function ($m) {
            $raw = strtoupper($m[2]) === 'B' ? (base64_decode($m[3]) ?: '') : quoted_printable_decode(str_replace('_', ' ', $m[3]));
            return crm_mail_to_utf8($raw, $m[1]);
        }, $value) ?? $value;
    }
    return crm_mail_clean_utf8($value);
}

function crm_mail_clean_utf8(string $value): string
{
    if ($value === '') return '';
    $value = str_replace("\0", '', $value);
    if (function_exists('mb_check_encoding') && mb_check_encoding($value, 'UTF-8')) return $value;
    if (function_exists('iconv')) {
        $fixed = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
        if (is_string($fixed) && $fixed !== '') return $fixed;
    }
    if (function_exists('mb_convert_encoding')) {
        $fixed = @mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        if (is_string($fixed) && $fixed !== '') return $fixed;
    }
    return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x80-\xFF]/', '', $value) ?? '';
}

function crm_mail_extract_header(string $raw, string $name): string
{
    if (preg_match('/^' . preg_quote($name, '/') . ':\s*(.+(?:\r?\n[ \t].+)*)/mi', $raw, $m)) {
        return trim(preg_replace('/\r?\n[ \t]+/', ' ', $m[1]));
    }
    return '';
}

function crm_mail_extract_email(string $value): array
{
    $name = '';
    $email = trim($value);
    if (preg_match('/^(.*?)<([^>]+)>/', $value, $m)) {
        $name = trim(trim($m[1]), '"');
        $email = trim($m[2]);
    }
    return ['name' => crm_mail_decode_header_value($name), 'email' => strtolower($email)];
}

function crm_mail_parse_addresses_safe(string $value): array
{
    try {
        return crm_mail_parse_addresses($value);
    } catch (Throwable $e) {
        if (!preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $value, $matches)) return [];
        return array_values(array_unique(array_map(static fn($email) => strtolower(trim((string)$email)), $matches[0])));
    }
}

function crm_mail_addresses_from_mail(array $mail): array
{
    $emails = [];
    $from = strtolower(trim((string)($mail['from_email'] ?? '')));
    if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL)) $emails[] = $from;
    foreach (['to_emails', 'cc_emails', 'bcc_emails'] as $field) {
        foreach (crm_mail_parse_addresses_safe((string)($mail[$field] ?? '')) as $email) {
            $email = strtolower(trim((string)$email));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) $emails[] = $email;
        }
    }
    return array_values(array_unique($emails));
}

function crm_mail_imap_literal(string $response): string
{
    if (preg_match('/\{\d+\}\r?\n/', $response, $m, PREG_OFFSET_CAPTURE)) {
        $start = $m[0][1] + strlen($m[0][0]);
        $end = strrpos($response, "\r\n)\r\n");
        if ($end === false) $end = strrpos($response, "\n)\n");
        if ($end !== false && $end > $start) return substr($response, $start, $end - $start);
    }
    return $response;
}

function crm_mail_header_params(string $value): array
{
    $params = [];
    $segments = [];
    $value = preg_replace('/\r?\n[ \t]+/', ' ', $value) ?? $value;
    foreach (preg_split('/;\s*/', $value) ?: [] as $part) {
        if (strpos($part, '=') === false) continue;
        [$key, $val] = array_map('trim', explode('=', $part, 2));
        $key = strtolower($key);
        $val = trim($val, "\"'");
        if (preg_match('/^(.+)\*(\d+)(\*)?$/', $key, $m)) {
            $base = strtolower($m[1]);
            $segments[$base][(int)$m[2]] = ['value' => $val, 'encoded' => !empty($m[3])];
            continue;
        }
        if (substr($key, -1) === '*' && preg_match("/^([^']*)''(.+)$/", $val, $m)) {
            $val = rawurldecode($m[2]);
            $params[rtrim($key, '*')] = crm_mail_to_utf8($val, $m[1]);
            continue;
        }
        $params[$key] = $val;
    }
    foreach ($segments as $base => $parts) {
        ksort($parts);
        $joined = '';
        $charset = '';
        foreach ($parts as $index => $part) {
            $piece = (string)$part['value'];
            if ($index === 0 && !empty($part['encoded']) && preg_match("/^([^']*)''(.*)$/", $piece, $m)) {
                $charset = $m[1];
                $piece = $m[2];
            }
            $joined .= !empty($part['encoded']) ? rawurldecode($piece) : $piece;
        }
        $params[$base] = $charset !== '' ? crm_mail_to_utf8($joined, $charset) : crm_mail_decode_header_value($joined);
    }
    return $params;
}

function crm_mail_mime_chunks(string $body, string $boundary): iterable
{
    $boundary = trim($boundary, "\"' \t\r\n");
    if ($boundary === '') return;
    $pattern = '/(?:^|\r?\n)--' . preg_quote($boundary, '/') . '(--)?[ \t]*(?:\r?\n|$)/';
    if (!preg_match_all($pattern, $body, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
        $part = trim($body, "\r\n");
        if ($part !== '') yield $part;
        return;
    }
    $count = count($matches);
    for ($i = 0; $i < $count; $i++) {
        $match = $matches[$i];
        if (!empty($match[1][0])) break;
        $start = $match[0][1] + strlen($match[0][0]);
        $end = $i + 1 < $count ? $matches[$i + 1][0][1] : strlen($body);
        if ($end <= $start) continue;
        $part = trim(substr($body, $start, $end - $start), "\r\n");
        if ($part !== '' && $part !== '--') yield $part;
    }
}

function crm_mail_decode_body_content(string $body, string $encoding, string $charset): string
{
    $encoding = strtolower(trim($encoding));
    if ($encoding === 'base64') {
        $decoded = base64_decode(preg_replace('/\s+/', '', $body) ?? '', true);
        if ($decoded !== false) $body = $decoded;
    } elseif ($encoding === 'quoted-printable') {
        $body = quoted_printable_decode($body);
    }
    $inlineCharset = crm_mail_detect_inline_charset($body);
    if ($inlineCharset !== '') $charset = $inlineCharset;
    return crm_mail_repair_text(crm_mail_to_utf8($body, $charset));
}

function crm_mail_maybe_decode_base64_text(string $value, string $charset = 'UTF-8'): string
{
    $trimmed = trim($value);
    if ($trimmed === '') return '';
    if (stripos($trimmed, 'data:image/') !== false || crm_mail_looks_like_html_fragment($trimmed)) return $value;
    $clean = preg_replace('/[^A-Za-z0-9+\/=]/', '', $trimmed) ?? '';
    if (strlen($clean) < 80) return $value;
    $visible = preg_replace('/\s+/', '', $trimmed) ?? $trimmed;
    if ($visible !== '' && strlen($clean) / max(1, strlen($visible)) < 0.9) return $value;
    $decoded = base64_decode($clean, true);
    if ($decoded === false || $decoded === '') $decoded = base64_decode($clean, false);
    if ($decoded === false || $decoded === '') return $value;
    $asText = crm_mail_to_utf8($decoded, $charset);
    if (preg_match('/<(html|body|div|span|font|table|p|br|sign)\b/i', $asText) || preg_match('/[\x{4e00}-\x{9fff}]/u', $asText)) {
        return $asText;
    }
    if (crm_mail_plain_text_score($asText) >= 0.92 && preg_match('/\b(the|and|for|you|please|hello|dear|regards|thanks|reminder|picture|reflector)\b/i', $asText)) {
        return $asText;
    }
    return $value;
}

function crm_mail_plain_text_score(string $value): float
{
    $value = trim($value);
    if ($value === '') return 0.0;
    $length = strlen($value);
    if ($length <= 0) return 0.0;
    $printable = preg_match_all('/[\x09\x0A\x0D\x20-\x7E]/', $value);
    $bad = preg_match_all('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]|�/', $value);
    if ($bad > 0) $printable = max(0, $printable - ($bad * 4));
    return max(0.0, min(1.0, $printable / $length));
}

function crm_mail_decode_transfer_content(string $body, string $encoding): string
{
    $encoding = strtolower(trim($encoding));
    if ($encoding === 'base64') {
        $decoded = base64_decode(preg_replace('/\s+/', '', $body) ?? '', true);
        if ($decoded !== false) return $decoded;
    }
    if ($encoding === 'quoted-printable') return quoted_printable_decode($body);
    return $body;
}

function crm_mail_decode_transfer_to_temp(string $body, string $encoding): ?array
{
    $tmp = tempnam(sys_get_temp_dir(), 'crm_mail_attach_');
    if (!$tmp) return null;
    $handle = fopen($tmp, 'wb');
    if (!$handle) {
        @unlink($tmp);
        return null;
    }
    $encoding = strtolower(trim($encoding));
    if ($encoding === 'base64') {
        $carry = '';
        $length = strlen($body);
        for ($offset = 0; $offset < $length; $offset += 8192) {
            $chunk = preg_replace('/\s+/', '', substr($body, $offset, 8192)) ?? '';
            if ($chunk === '') continue;
            $chunk = $carry . $chunk;
            $usable = strlen($chunk) - (strlen($chunk) % 4);
            if ($usable > 0) {
                $decoded = base64_decode(substr($chunk, 0, $usable), false);
                if (is_string($decoded) && $decoded !== '') fwrite($handle, $decoded);
            }
            $carry = substr($chunk, $usable);
        }
        if ($carry !== '') {
            $decoded = base64_decode($carry, false);
            if (is_string($decoded) && $decoded !== '') fwrite($handle, $decoded);
        }
    } elseif ($encoding === 'quoted-printable') {
        fwrite($handle, quoted_printable_decode($body));
    } else {
        fwrite($handle, $body);
    }
    fclose($handle);
    return ['tmp_name' => $tmp, 'size' => filesize($tmp) ?: 0];
}

function crm_mail_parse_mime_part(string $raw, array &$result): void
{
    $parts = preg_split("/\r?\n\r?\n/", $raw, 2);
    $header = $parts[0] ?? '';
    $body = $parts[1] ?? '';
    $contentType = crm_mail_extract_header($header, 'Content-Type') ?: 'text/plain; charset=UTF-8';
    $contentDisposition = strtolower(crm_mail_extract_header($header, 'Content-Disposition'));
    $rawDisposition = crm_mail_extract_header($header, 'Content-Disposition');
    $contentId = trim(crm_mail_extract_header($header, 'Content-ID'), '<>');
    $encoding = crm_mail_extract_header($header, 'Content-Transfer-Encoding');
    $params = crm_mail_header_params($contentType);
    $dispositionParams = crm_mail_header_params($rawDisposition);
    $charset = $params['charset'] ?? 'UTF-8';
    $lowerType = strtolower($contentType);

    $typeOnlyForDecision = strtolower(trim(explode(';', $contentType, 2)[0]) ?: 'text/plain');
    $isTextPart = strpos($typeOnlyForDecision, 'text/') === 0;
    $hasAttachmentDisposition = strpos($contentDisposition, 'attachment') !== false;
    $hasInlineDisposition = strpos($contentDisposition, 'inline') !== false;
    $isFilePart = $hasAttachmentDisposition
        || ($hasInlineDisposition && (!$isTextPart || $contentId !== '' || isset($params['name']) || isset($dispositionParams['filename'])))
        || isset($params['name'])
        || isset($dispositionParams['filename'])
        || ($contentId !== '' && preg_match('/^(image|application)\//i', trim(explode(';', $contentType, 2)[0])));
    if ($isFilePart) {
        $typeOnly = trim(explode(';', $contentType, 2)[0]) ?: 'application/octet-stream';
        $isInline = (!$hasAttachmentDisposition && ($hasInlineDisposition || ($contentId !== '' && stripos($typeOnly, 'image/') === 0))) ? 1 : 0;
        $name = crm_mail_decode_header_value((string)($dispositionParams['filename'] ?? ($params['name'] ?? ($contentId !== '' ? $contentId : ('attachment-' . ((int)$result['attachment_count'] + 1))))));
        $name = $name !== '' ? $name : ($contentId !== '' ? $contentId : ('attachment-' . ((int)$result['attachment_count'] + 1)));
        $decodedFile = crm_mail_decode_transfer_to_temp($body, $encoding);
        if (!$isInline) {
            $result['has_attachment'] = 1;
            $result['attachment_count']++;
        }
        if ($decodedFile) {
            $result['attachments'][] = [
                'name' => $name,
                'original_name' => $name,
                'tmp_name' => $decodedFile['tmp_name'],
                'size' => (int)$decodedFile['size'],
                'type' => $typeOnly,
                'attachment_type' => $isInline ? 'inline' : 'normal',
                'message_id' => (string)($result['message_id'] ?? ''),
                'content_id' => $contentId,
                'is_inline' => $isInline,
            ];
        }
        return;
    }

    if (strpos($lowerType, 'multipart/') !== false && !empty($params['boundary'])) {
        foreach (crm_mail_mime_chunks($body, $params['boundary']) as $chunk) {
            crm_mail_parse_mime_part($chunk, $result);
        }
        return;
    }

    if (strpos($lowerType, 'text/html') !== false) {
        $decoded = trim(crm_mail_decode_body_content($body, $encoding, $charset));
        $decoded = trim(crm_mail_maybe_decode_base64_text($decoded, $charset));
        if ($decoded !== '' && $result['html'] === '') $result['html'] = $decoded;
        if ($decoded !== '' && $result['text'] === '') $result['text'] = trim(strip_tags($decoded));
        return;
    }

    if (strpos($lowerType, 'text/plain') !== false) {
        $decoded = trim(crm_mail_decode_body_content($body, $encoding, $charset));
        $decoded = trim(crm_mail_maybe_decode_base64_text($decoded, $charset));
        if ($decoded !== '' && $result['text'] === '') $result['text'] = $decoded;
        return;
    }
}

function crm_mail_parse_raw_message(string $raw, string $uid, array $account, string $folder = 'inbox'): array
{
    $raw = crm_mail_imap_literal($raw);
    $rawPath = crm_mail_store_raw_eml((int)($account['user_id'] ?? 0), $folder, $uid, $raw);
    $parts = preg_split("/\r?\n\r?\n/", $raw, 2);
    $header = $parts[0] ?? '';
    $subject = crm_mail_decode_header_value(crm_mail_extract_header($header, 'Subject'));
    $from = crm_mail_extract_email(crm_mail_extract_header($header, 'From'));
    $to = crm_mail_extract_header($header, 'To');
    $cc = crm_mail_extract_header($header, 'Cc');
    $date = crm_mail_extract_header($header, 'Date');
    $receivedAt = $date ? date('Y-m-d H:i:s', strtotime($date) ?: time()) : date('Y-m-d H:i:s');
    $messageId = crm_mail_extract_header($header, 'Message-ID') ?: ('uid-' . $uid);
    $parsed = ['html' => '', 'text' => '', 'has_attachment' => 0, 'attachment_count' => 0, 'attachments' => [], 'message_id' => $messageId];
    $parseStatus = 'parsed';
    $parseError = '';
    try {
        crm_mail_parse_mime_part($raw, $parsed);
    } catch (Throwable $e) {
        $parseStatus = 'parse_error';
        $parseError = $e->getMessage();
        error_log('crm_mail_parse_raw_message failed uid=' . $uid . ': ' . $parseError);
    }
    $hasAttachment = (int)$parsed['has_attachment'];
    $attachmentCount = (int)$parsed['attachment_count'];
    $bodyText = trim((string)$parsed['text']);
    $bodyHtml = trim((string)$parsed['html']);
    $hasBody = trim(strip_tags($bodyHtml . $bodyText)) !== '';
    if (!$hasBody && $hasAttachment) {
        $bodyText = '';
        $bodyHtml = '<div class="mail-no-body"><strong>此邮件没有正文，但包含附件。</strong><span>附件已正常收取，可下载、预览或转发。</span></div>';
        if ($parseStatus !== 'parse_error') $parseStatus = 'no_body_with_attachments';
    } elseif ($bodyHtml === '') {
        $bodyHtml = crm_mail_looks_like_html_fragment($bodyText)
            ? crm_mail_normalize_html_fragment($bodyText)
            : nl2br(htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8'));
        if (!$hasBody && $parseStatus !== 'parse_error') $parseStatus = 'no_body';
    }
    $subject = crm_mail_clean_utf8($subject ?: '(无主题)');
    $from['name'] = crm_mail_clean_utf8($from['name'] ?: $from['email']);
    $bodyHtml = crm_mail_clean_utf8($bodyHtml);
    $bodyText = crm_mail_clean_utf8($bodyText);
    return [
        'message_uid' => $uid,
        'message_id_header' => $messageId,
        'folder' => $folder,
        'subject' => $subject,
        'from_email' => $from['email'],
        'from_name' => $from['name'],
        'to_emails' => crm_mail_clean_utf8(crm_mail_decode_header_value($to)),
        'cc_emails' => crm_mail_clean_utf8(crm_mail_decode_header_value($cc)),
        'received_at' => $receivedAt,
        'body_html' => $bodyHtml,
        'body_text' => $bodyText,
        'body_hash' => crm_mail_body_hash($bodyHtml, $bodyText),
        'body_status' => $hasBody ? 'normal' : ($hasAttachment ? 'no_body_with_attachments' : 'no_body'),
        'has_body' => $hasBody ? 1 : 0,
        'has_attachment' => $hasAttachment ? 1 : 0,
        'attachment_count' => (int)$attachmentCount,
        'attachments' => $parsed['attachments'],
        'raw_headers_json' => json_encode(['raw' => $header], JSON_UNESCAPED_UNICODE),
        'raw_headers' => $header,
        'raw_eml_path' => $rawPath,
        'parse_status' => $parseStatus,
        'parse_error' => $parseError,
    ];
}

function crm_mail_attachment_url(int $attachmentId, bool $inline = false): string
{
    return 'crm_api.php?action=mail_attachment_download&attachment_id=' . rawurlencode((string)$attachmentId) . ($inline ? '&inline=1' : '');
}

function crm_mail_public_storage_url(string $path): string
{
    $real = realpath($path);
    $root = realpath(__DIR__);
    if (!$real || !$root || strpos($real, $root . DIRECTORY_SEPARATOR) !== 0) return '';
    $relative = ltrim(str_replace('\\', '/', substr($real, strlen($root))), '/');
    if ($relative === '' || strpos($relative, 'storage/mail_attachments/') !== 0) return '';
    $segments = array_map('rawurlencode', explode('/', $relative));
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $scriptDir = trim((string)dirname($scriptName), '/.');
    if ($scriptDir === '') $scriptDir = basename(__DIR__);
    return '/' . trim($scriptDir, '/') . '/' . implode('/', $segments);
}

function crm_mail_inline_attachment_url(array $attachment): string
{
    $path = (string)($attachment['file_path'] ?? '');
    $publicUrl = $path !== '' ? crm_mail_public_storage_url($path) : '';
    return $publicUrl !== '' ? $publicUrl : crm_mail_attachment_url((int)($attachment['id'] ?? 0), true);
}

function crm_mail_inline_body_html(string $html, array $attachments): string
{
    if ($html === '') return '';
    $map = [];
    $idMap = [];
    foreach ($attachments as $attachment) {
        $attachmentId = (int)($attachment['id'] ?? 0);
        if ($attachmentId > 0) $idMap[$attachmentId] = crm_mail_inline_attachment_url($attachment);
        $cid = trim((string)($attachment['content_id'] ?? ''), '<> ');
        if ($cid === '') continue;
        $url = crm_mail_inline_attachment_url($attachment);
        $map[strtolower($cid)] = $url;
        $map[strtolower('<' . $cid . '>')] = $url;
    }
    $html = preg_replace_callback('/(["\'])cid:crm-forward-inline-(\d+)-[^"\']*\1/i', static function ($m) {
        return $m[1] . crm_mail_attachment_url((int)$m[2], true) . $m[1];
    }, $html) ?? $html;
    $html = preg_replace_callback('/(\bsrc\s*=\s*)cid:crm-forward-inline-(\d+)-[^\s>]+/i', static function ($m) {
        return $m[1] . '"' . crm_mail_attachment_url((int)$m[2], true) . '"';
    }, $html) ?? $html;
    if ($map) {
        $html = preg_replace_callback('/(["\'])cid:([^"\']+)\1/i', function ($m) use ($map) {
            $cid = strtolower(trim(rawurldecode($m[2]), '<> '));
            return $m[1] . ($map[$cid] ?? ('cid:' . $m[2])) . $m[1];
        }, $html) ?? $html;
        $html = preg_replace_callback('/(\bsrc\s*=\s*)cid:([^\s>]+)/i', function ($m) use ($map) {
            $cid = strtolower(trim(rawurldecode($m[2]), '<> "\''));
            return $m[1] . '"' . ($map[$cid] ?? ('cid:' . $m[2])) . '"';
        }, $html) ?? $html;
        $html = preg_replace_callback('/url\(\s*cid:([^)]+)\)/i', function ($m) use ($map) {
            $cid = strtolower(trim(rawurldecode(trim($m[1], "\"' \t\r\n")), '<> '));
            return 'url("' . ($map[$cid] ?? ('cid:' . $m[1])) . '")';
        }, $html) ?? $html;
    }
    if ($idMap) {
        $html = preg_replace_callback('/(["\'])([^"\']*crm_api\.php\?action=mail_attachment_download[^"\']*attachment_id=(\d+)[^"\']*)\1/i', function ($m) use ($idMap) {
            $attachmentId = (int)$m[3];
            return $m[1] . ($idMap[$attachmentId] ?? $m[2]) . $m[1];
        }, $html) ?? $html;
    }
    return $html;
}

function crm_mail_looks_like_html_fragment(string $value): bool
{
    if ($value === '') return false;
    return (bool)preg_match('/<\/?(html|body|table|thead|tbody|tr|td|th|div|span|p|br|font|blockquote|ul|ol|li|img|a)\b/i', $value)
        || (bool)preg_match('/&lt;\/?(html|body|table|thead|tbody|tr|td|th|div|span|p|br|font|blockquote|ul|ol|li|img|a)\b/i', $value);
}

function crm_mail_normalize_html_fragment(string $value): string
{
    $value = trim($value);
    if ($value === '') return '';
    if (preg_match('/&lt;\/?(html|body|table|thead|tbody|tr|td|th|div|span|p|br|font|blockquote|ul|ol|li|img|a)\b/i', $value)) {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    $value = crm_mail_clean_broken_table_html($value);
    if (preg_match('/^\s*<tr\b/i', $value) || (preg_match('/<tr\b/i', $value) && !preg_match('/<table\b/i', $value))) {
        $value = '<table class="mail-fragment-table" cellpadding="0" cellspacing="0">' . $value . '</table>';
    }
    return $value;
}

function crm_mail_clean_broken_table_html(string $value): string
{
    if ($value === '' || stripos($value, '<tr') === false) return $value;
    $br = '(?:<br\s*/?>\s*)+';
    $value = preg_replace('~(<tr\b[^>]*>)\s*' . $br . '~i', '$1', $value) ?? $value;
    $value = preg_replace('~' . $br . '\s*(</tr>)~i', '$1', $value) ?? $value;
    $value = preg_replace('~(</td>)\s*' . $br . '\s*(<td\b)~i', '$1$2', $value) ?? $value;
    $value = preg_replace('~(</th>)\s*' . $br . '\s*(<th\b)~i', '$1$2', $value) ?? $value;
    $value = preg_replace('~(</tr>)\s*' . $br . '\s*(<tr\b)~i', '$1$2', $value) ?? $value;
    return $value;
}

function crm_mail_no_body_attachment_html(): string
{
    return '<div class="mail-no-body"><strong>此邮件没有可解析正文，但包含附件。</strong><span>附件已正常收取，可在正文上方下载或预览。退信、系统通知、扫描件邮件经常只有附件或特殊格式正文。</span></div>';
}

function crm_mail_is_dbs_eadvice(array $mail): bool
{
    $from = strtolower(trim((string)($mail['from_email'] ?? '')));
    $subject = (string)($mail['subject'] ?? '');
    $html = (string)($mail['body_html'] ?? '');
    return $from === 'dbseadvice@dbs.com'
        || stripos($subject, 'DBS eAdvice') !== false
        || stripos($subject, 'DBS_eAdvice') !== false
        || stripos($html, 'DBS_eAdvice') !== false;
}

function crm_mail_repair_stored_body(array $mail): array
{
    if (crm_mail_is_dbs_eadvice($mail)) return $mail;
    $html = (string)($mail['body_html'] ?? '');
    $text = (string)($mail['body_text'] ?? '');
    $raw = trim($html) !== '' ? $html : $text;
    if ($raw !== '' && preg_match('/Content-(Type|Transfer-Encoding)|MIME-Version|boundary=/i', $raw)) {
        $parsed = ['html' => '', 'text' => '', 'has_attachment' => 0, 'attachment_count' => 0, 'attachments' => []];
        crm_mail_parse_mime_part($raw, $parsed);
        if (trim((string)$parsed['html']) !== '' || trim((string)$parsed['text']) !== '') {
            $html = trim((string)$parsed['html']);
            $text = trim((string)$parsed['text']);
        }
    }
    if ($html !== '') {
        if (preg_match('/=\r?\n|=[A-Fa-f0-9]{2}/', $html)) {
            $decoded = quoted_printable_decode($html);
            if (is_string($decoded) && trim($decoded) !== '') $html = $decoded;
        }
        $fixed = crm_mail_maybe_decode_base64_text($html, 'UTF-8');
        if ($fixed !== $html) $html = $fixed;
        $html = crm_mail_repair_text($html);
        if (stripos($html, '<html') === false && stripos($html, '<body') === false && crm_mail_looks_like_html_fragment($html)) {
            $html = crm_mail_normalize_html_fragment($html);
        }
    }
    if ($text !== '') {
        if (preg_match('/=\r?\n|=[A-Fa-f0-9]{2}/', $text)) {
            $decoded = quoted_printable_decode($text);
            if (is_string($decoded) && trim($decoded) !== '') $text = $decoded;
        }
        $fixed = crm_mail_maybe_decode_base64_text($text, 'UTF-8');
        if ($fixed !== $text) $text = $fixed;
        $text = crm_mail_repair_text($text);
    }
    if (trim($html) === '' && trim($text) !== '') {
        $html = crm_mail_looks_like_html_fragment($text)
            ? crm_mail_normalize_html_fragment($text)
            : nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
    }
    if (trim($text) === '' && trim(strip_tags($html)) !== '') $text = trim(strip_tags($html));
    $mail['body_html'] = crm_mail_clean_utf8($html);
    $mail['body_text'] = crm_mail_clean_utf8($text);
    return $mail;
}

function crm_mail_body_looks_garbled(string $html, string $text): bool
{
    if (stripos($html, 'data:image/') !== false && crm_mail_looks_like_html_fragment($html)) {
        $visible = trim(strip_tags((preg_replace('/<img\b[^>]*>/is', ' ', $html) ?? $html) . "\n" . $text));
        if ($visible !== '' && crm_mail_plain_text_score($visible) >= 0.9 && preg_match('/[A-Za-z\x{4e00}-\x{9fff}]/u', $visible)) {
            return false;
        }
    }
    $combined = trim(strip_tags($html . "\n" . $text));
    if ($combined === '') return true;
    if (preg_match('/Content-(Type|Transfer-Encoding|Disposition)|MIME-Version|boundary=|charset=|^--[A-Za-z0-9_=\\-]{8,}/im', $combined)) return true;
    $cleanBase64 = preg_replace('/[^A-Za-z0-9+\\/=]/', '', $combined) ?? '';
    if (strlen($cleanBase64) > 300 && strlen($cleanBase64) / max(1, strlen($combined)) > 0.82) return true;
    $bad = preg_match_all('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]|�/', $combined);
    return $bad > 20 || ($bad > 0 && $bad / max(1, strlen($combined)) > 0.08);
}

function crm_mail_body_needs_refetch(array $mail): bool
{
    if (crm_mail_is_dbs_eadvice($mail)) return false;
    $html = (string)($mail['body_html'] ?? '');
    $text = (string)($mail['body_text'] ?? '');
    if ((string)($mail['folder'] ?? '') !== 'inbox') return false;
    if (trim((string)($mail['message_uid'] ?? '')) === '') return false;
    if (crm_mail_body_looks_garbled($html, $text)) return true;
    if (stripos($html, 'mail-fragment-table') !== false) return true;
    if (preg_match('/&lt;\/?tr\b/i', $html)) return true;
    if (preg_match('/^\s*<tr\b/i', $text) || preg_match('/^\s*<tr\b/i', $html)) return true;
    if (preg_match('/<tr\b[^>]*>\s*<br\s*\/?>/i', $html)) return true;
    if (stripos($html, '<html') === false && stripos($text, '<html') === false && stripos($html . $text, 'DBS_eAdvice') !== false) return true;
    return false;
}

function crm_mail_refetch_original_body(array $account, array $mail): array
{
    if (!crm_mail_body_needs_refetch($mail)) return $mail;
    $uid = (int)($mail['message_uid'] ?? 0);
    if ($uid <= 0) return $mail;
    if (empty($account['mail_secret']) && !empty($account['email_password_encrypted'])) {
        $account['mail_secret'] = crm_mail_decrypt($account['email_password_encrypted']);
    }
    if (empty($account['mail_secret'])) return $mail;
    $fp = null;
    try {
        $fp = crm_mail_imap_login($account);
        crm_mail_imap_command($fp, 'R001', 'SELECT INBOX');
        $raw = crm_mail_imap_command($fp, 'R002', 'UID FETCH ' . $uid . ' BODY.PEEK[]');
        $parsed = crm_mail_parse_raw_message($raw, (string)$uid, $account);
        $newHtml = crm_mail_clean_utf8((string)($parsed['body_html'] ?? ''));
        $newText = crm_mail_clean_utf8((string)($parsed['body_text'] ?? ''));
        $oldScore = strlen(strip_tags((string)($mail['body_html'] ?? '') . (string)($mail['body_text'] ?? '')));
        $newScore = strlen(strip_tags($newHtml . $newText));
        $oldHtml = (string)($mail['body_html'] ?? '');
        $oldLooksPartial = stripos($oldHtml, '<html') === false || preg_match('/^\s*(?:<table[^>]*>)?\s*<tr\b/i', $oldHtml);
        $newLooksComplete = stripos($newHtml, '<html') !== false && stripos($newHtml, '<table') !== false;
        $oldLooksGarbled = crm_mail_body_looks_garbled((string)($mail['body_html'] ?? ''), (string)($mail['body_text'] ?? ''));
        $newLooksUsable = !crm_mail_body_looks_garbled($newHtml, $newText)
            && ($newLooksComplete || crm_mail_looks_like_html_fragment($newHtml) || trim(strip_tags($newHtml . $newText)) !== '');
        if ($newHtml !== '' && $newLooksUsable && ($oldLooksGarbled || $oldLooksPartial || $newScore > max(200, $oldScore + 200))) {
            db()->prepare('UPDATE crm_mails SET subject = ?, from_name = ?, to_emails = ?, cc_emails = ?, received_at = ?, body_html = ?, body_text = ?, body_status = ?, has_body = ?, raw_headers_json = ?, raw_headers = ?, raw_eml_path = COALESCE(NULLIF(raw_eml_path, ""), ?), parse_status = ?, parse_error = ?, updated_at = NOW() WHERE id = ? AND user_id = ?')
                ->execute([
                    $parsed['subject'],
                    $parsed['from_name'],
                    $parsed['to_emails'],
                    $parsed['cc_emails'],
                    $parsed['received_at'],
                    $newHtml,
                    $newText,
                    $parsed['body_status'],
                    $parsed['has_body'],
                    $parsed['raw_headers_json'],
                    $parsed['raw_headers'] ?? '',
                    $parsed['raw_eml_path'] ?? '',
                    $parsed['parse_status'] ?? 'parsed',
                    $parsed['parse_error'] ?? '',
                    (int)$mail['id'],
                    (int)$account['user_id'],
                ]);
            $mail = array_merge($mail, [
                'subject' => $parsed['subject'],
                'from_name' => $parsed['from_name'],
                'to_emails' => $parsed['to_emails'],
                'cc_emails' => $parsed['cc_emails'],
                'received_at' => $parsed['received_at'],
                'body_html' => $newHtml,
                'body_text' => $newText,
                'body_status' => $parsed['body_status'],
                'has_body' => $parsed['has_body'],
                'raw_headers_json' => $parsed['raw_headers_json'],
                'raw_headers' => $parsed['raw_headers'] ?? '',
                'raw_eml_path' => $parsed['raw_eml_path'] ?? '',
                'parse_status' => $parsed['parse_status'] ?? 'parsed',
                'parse_error' => $parsed['parse_error'] ?? '',
                '_refetched_original_body' => 1,
            ]);
        }
        @crm_mail_imap_command($fp, 'R999', 'LOGOUT');
        @fclose($fp);
    } catch (Throwable $e) {
        if (is_resource($fp)) {
            @fwrite($fp, "R999 LOGOUT\r\n");
            @fclose($fp);
        }
    }
    return $mail;
}

function crm_mail_refetch_missing_attachments(array $account, array $mail): array
{
    if ((string)($mail['folder'] ?? '') !== 'inbox') return $mail;
    $mailId = (int)($mail['id'] ?? 0);
    $uid = (int)($mail['message_uid'] ?? 0);
    if ($mailId <= 0 || $uid <= 0) return $mail;

    $existingStmt = db()->prepare('SELECT content_id FROM crm_mail_attachments WHERE mail_id = ? AND user_id = ?');
    $existingStmt->execute([$mailId, (int)$account['user_id']]);
    $existingCids = [];
    $existingTotal = 0;
    foreach ($existingStmt->fetchAll() as $row) {
        $existingTotal++;
        $cid = strtolower(trim((string)($row['content_id'] ?? ''), '<> '));
        if ($cid !== '') $existingCids[$cid] = true;
    }
    $bodyCids = [];
    if (preg_match_all('/cid:([^"\'>\s)]+)/i', (string)($mail['body_html'] ?? ''), $matches)) {
        foreach ($matches[1] as $cid) {
            $cid = strtolower(trim(rawurldecode((string)$cid), '<> '));
            if ($cid !== '') $bodyCids[$cid] = true;
        }
    }
    $missingBodyCid = false;
    foreach (array_keys($bodyCids) as $cid) {
        if (empty($existingCids[$cid])) {
            $missingBodyCid = true;
            break;
        }
    }
    if ($existingTotal > 0 && !$missingBodyCid) return $mail;

    if (empty($account['mail_secret']) && !empty($account['email_password_encrypted'])) {
        $account['mail_secret'] = crm_mail_decrypt($account['email_password_encrypted']);
    }
    if (empty($account['mail_secret'])) return $mail;

    $fp = null;
    try {
        $fp = crm_mail_imap_login($account);
        crm_mail_imap_command($fp, 'M001', 'SELECT INBOX');
        $raw = crm_mail_imap_command($fp, 'M002', 'UID FETCH ' . $uid . ' BODY.PEEK[]');
        $parsed = crm_mail_parse_raw_message($raw, (string)$uid, $account);
        $storeResult = crm_mail_store_parsed_attachments_for_mail($account, $mailId, $parsed);
        if ((int)($storeResult['total_count'] ?? 0) > 0) {
            $mail['has_attachment'] = (int)($storeResult['visible_count'] ?? 0) > 0 ? 1 : 0;
            $mail['attachment_count'] = (int)($storeResult['visible_count'] ?? 0);
            $mail['_refetched_missing_attachments'] = 1;
        }
        @crm_mail_imap_command($fp, 'M999', 'LOGOUT');
        @fclose($fp);
    } catch (Throwable $e) {
        if (is_resource($fp)) {
            @fwrite($fp, "M999 LOGOUT\r\n");
            @fclose($fp);
        }
        error_log('crm_mail_refetch_missing_attachments failed for mail #' . $mailId . ': ' . $e->getMessage());
    }
    return $mail;
}

function crm_mail_imap_fetch_recent(array $account, int $limit = 30, int $sinceDays = 0): array
{
    $fp = crm_mail_imap_login($account);
    try {
        crm_mail_imap_command($fp, 'A002', 'SELECT INBOX');
        $criteria = 'ALL';
        if ($sinceDays > 0) {
            $criteria = 'SINCE ' . gmdate('d-M-Y', strtotime('-' . max(1, min(30, $sinceDays)) . ' days'));
        }
        $search = crm_mail_imap_command($fp, 'A003', 'UID SEARCH ' . $criteria);
        preg_match('/\* SEARCH\s*(.*)\r?\n/i', $search, $m);
        $uids = array_values(array_filter(preg_split('/\s+/', trim($m[1] ?? '')) ?: []));
        $uids = array_slice($uids, -($sinceDays > 0 ? max($limit, 300) : $limit));
        $existingUids = [];
        if ($uids) {
            $placeholders = implode(',', array_fill(0, count($uids), '?'));
            $params = array_merge([(int)$account['id']], array_map('strval', $uids));
            $existingStmt = db()->prepare("SELECT m.id, m.user_id, m.message_uid, COUNT(a.id) AS stored_attachment_count FROM crm_mails m LEFT JOIN crm_mail_attachments a ON a.mail_id = m.id AND a.user_id = m.user_id WHERE m.mail_account_id = ? AND m.folder = \"inbox\" AND m.message_uid IN ({$placeholders}) GROUP BY m.id, m.user_id, m.message_uid");
            $existingStmt->execute($params);
            foreach ($existingStmt->fetchAll() as $row) $existingUids[(string)$row['message_uid']] = $row;
        }
        $new = 0;
        $duplicate = 0;
        $failed = 0;
        $attachments = 0;
        $newMailIds = [];
        $newMailSubjects = [];
        foreach (array_reverse($uids) as $uid) {
            if (isset($existingUids[(string)$uid])) {
                $existing = $existingUids[(string)$uid];
                if ((int)($existing['stored_attachment_count'] ?? 0) <= 0) {
                    try {
                        $raw = crm_mail_imap_command($fp, 'D' . preg_replace('/\D/', '', (string)$uid), 'UID FETCH ' . (int)$uid . ' BODY.PEEK[]');
                        $parsed = crm_mail_parse_raw_message($raw, (string)$uid, $account);
                        $storeResult = crm_mail_store_parsed_attachments_for_mail($account, (int)$existing['id'], $parsed);
                        $attachments += (int)($storeResult['visible_count'] ?? 0);
                    } catch (Throwable $e) {
                        $failed++;
                        error_log('crm_mail_imap_fetch_recent duplicate attachment repair failed for mail #' . (int)$existing['id'] . ': ' . $e->getMessage());
                    }
                }
                $duplicate++;
                continue;
            }
            try {
                $raw = crm_mail_imap_command($fp, 'F' . preg_replace('/\D/', '', (string)$uid), 'UID FETCH ' . (int)$uid . ' BODY.PEEK[]');
                $mail = crm_mail_parse_raw_message($raw, (string)$uid, $account, 'inbox');
                foreach (['subject', 'from_name', 'to_emails', 'cc_emails', 'body_html', 'body_text'] as $field) {
                    $mail[$field] = crm_mail_clean_utf8((string)($mail[$field] ?? ''));
                }
                db()->prepare('INSERT IGNORE INTO crm_mails (user_id, mail_account_id, message_uid, message_id_header, folder, subject, from_email, from_name, to_emails, cc_emails, received_at, body_html, body_text, body_status, has_body, has_attachment, attachment_count, is_read, is_unreplied, raw_headers_json, raw_headers, raw_eml_path, parse_status, parse_error, mail_source, source_flags_json, body_hash, imap_mailbox, tags_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 1, ?, ?, ?, ?, ?, "imap_inbox", ?, ?, "INBOX", ?, NOW(), NOW())')
                    ->execute([(int)$account['user_id'], (int)$account['id'], $mail['message_uid'], $mail['message_id_header'], 'inbox', $mail['subject'], $mail['from_email'], $mail['from_name'], $mail['to_emails'], $mail['cc_emails'], $mail['received_at'], $mail['body_html'], $mail['body_text'], $mail['body_status'], $mail['has_body'], $mail['has_attachment'], $mail['attachment_count'], $mail['raw_headers_json'], $mail['raw_headers'] ?? '', $mail['raw_eml_path'] ?? '', $mail['parse_status'] ?? 'parsed', $mail['parse_error'] ?? '', json_encode(['imap_inbox'], JSON_UNESCAPED_UNICODE), $mail['body_hash'] ?? crm_mail_body_hash((string)$mail['body_html'], (string)$mail['body_text']), json_encode($mail['has_attachment'] ? ['有附件'] : ['客户邮件'], JSON_UNESCAPED_UNICODE)]);
                $mailId = (int)db()->lastInsertId();
                if ($mailId > 0) {
                    $storeResult = crm_mail_store_attachment_files((int)$account['user_id'], $mailId, $mail['attachments'] ?? []);
                    if ((int)($storeResult['inline'] ?? 0) > 0) $mail['body_html'] = crm_mail_update_inline_body_urls((int)$account['user_id'], $mailId, (string)$mail['body_html']);
                    $countStmt = db()->prepare('SELECT COUNT(*) FROM crm_mail_attachments WHERE mail_id = ? AND user_id = ? AND COALESCE(is_inline,0) = 0 AND COALESCE(is_signature_image,0) = 0');
                    $countStmt->execute([$mailId, (int)$account['user_id']]);
                    $actualVisibleCount = (int)$countStmt->fetchColumn();
                    db()->prepare('UPDATE crm_mails SET has_attachment = ?, attachment_count = ?, updated_at = NOW() WHERE id = ? AND user_id = ?')
                        ->execute([$actualVisibleCount > 0 ? 1 : 0, $actualVisibleCount, $mailId, (int)$account['user_id']]);
                    if ((int)($mail['attachment_count'] ?? 0) > 0 && $actualVisibleCount <= 0) {
                        error_log('crm_mail_imap_fetch_recent: visible attachments were not persisted for inbox mail #' . $mailId . ', expected=' . (int)($mail['attachment_count'] ?? 0) . ', store=' . json_encode($storeResult));
                    }
                    $newMailIds[] = $mailId;
                    $newMailSubjects[] = (string)($mail['subject'] ?? '');
                    $new++;
                } else {
                    $duplicate++;
                }
                $attachments += isset($actualVisibleCount) ? (int)$actualVisibleCount : (int)$mail['attachment_count'];
            } catch (Throwable $e) {
                $failed++;
                error_log('crm_mail_imap_fetch_recent parse/store failed uid=' . (string)$uid . ': ' . $e->getMessage());
            }
        }
        @crm_mail_imap_command($fp, 'A999', 'LOGOUT');
        @fclose($fp);
        return ['new_count' => $new, 'duplicate_count' => $duplicate, 'attachment_count' => $attachments, 'failed_count' => $failed, 'total_count' => count($uids), 'new_mail_ids' => $newMailIds, 'new_mail_subjects' => $newMailSubjects];
    } catch (Throwable $e) {
        @fwrite($fp, "A999 LOGOUT\r\n");
        @fclose($fp);
        throw $e;
    }
}

function crm_mail_imap_quote_mailbox(string $mailbox): string
{
    return '"' . addcslashes($mailbox, "\\\"") . '"';
}

function crm_mail_imap_decode_mailbox_name(string $mailbox): string
{
    if ($mailbox === '') return '';
    if (function_exists('mb_convert_encoding')) {
        $decoded = @mb_convert_encoding($mailbox, 'UTF-8', 'UTF7-IMAP');
        if (is_string($decoded) && $decoded !== '') return $decoded;
    }
    return $mailbox;
}

function crm_mail_imap_sent_folder_candidates($fp): array
{
    $candidates = ['Sent', 'Sent Messages', '已发送', '已发送邮件', 'Sent Items', 'Sent Mail'];
    try {
        $list = crm_mail_imap_command($fp, 'L001', 'LIST "" "*"');
        foreach (preg_split('/\r?\n/', $list) ?: [] as $line) {
            if (stripos($line, '* LIST') !== 0) continue;
            if (preg_match('/"([^"]+)"\s*$/', trim($line), $m)) {
                $name = stripcslashes($m[1]);
                $display = crm_mail_imap_decode_mailbox_name($name);
                if ($name !== '' && (stripos($display, 'sent') !== false || strpos($display, '已发送') !== false)) $candidates[] = $name;
            }
        }
    } catch (Throwable $e) {
        // Candidate probing below is enough when LIST is not supported.
    }
    return array_values(array_unique($candidates));
}

function crm_mail_imap_select_first_sent_folder($fp): ?string
{
    foreach (crm_mail_imap_sent_folder_candidates($fp) as $mailbox) {
        try {
            crm_mail_imap_command($fp, 'S' . substr(md5($mailbox), 0, 4), 'SELECT ' . crm_mail_imap_quote_mailbox($mailbox));
            return $mailbox;
        } catch (Throwable $e) {
            continue;
        }
    }
    return null;
}

function crm_mail_find_existing_sent_duplicate(array $account, array $mail, string $sentAt): ?array
{
    $accountId = (int)$account['id'];
    $userId = (int)$account['user_id'];
    $messageId = crm_mail_normalize_message_id((string)($mail['message_id_header'] ?? ''));
    if ($messageId !== '') {
        $stmt = db()->prepare("SELECT id, message_uid, message_id_header, crm_send_id, mail_source, source_flags_json, body_hash FROM crm_mails WHERE user_id = ? AND mail_account_id = ? AND folder = 'sent' AND is_deleted = 0 AND message_id_header IS NOT NULL AND message_id_header <> ''");
        $stmt->execute([$userId, $accountId]);
        $messageVariants = crm_mail_message_id_variants((string)($mail['message_id_header'] ?? ''));
        foreach ($stmt->fetchAll() as $row) {
            $rowVariants = crm_mail_message_id_variants((string)$row['message_id_header']);
            foreach ($rowVariants as $rowMessageId) {
                if (in_array($rowMessageId, $messageVariants, true)) return $row;
                if (strlen($rowMessageId) > 20 && str_contains($messageId, $rowMessageId)) return $row;
            }
            foreach ($messageVariants as $variant) {
                $rowMessageId = crm_mail_normalize_message_id((string)$row['message_id_header']);
                if (strlen($variant) > 20 && str_contains($rowMessageId, $variant)) return $row;
            }
        }
    }
    $subject = trim((string)($mail['subject'] ?? ''));
    $to = trim((string)($mail['to_emails'] ?? ''));
    if ($subject === '') return null;
    $bodyHash = (string)($mail['body_hash'] ?? crm_mail_body_hash((string)($mail['body_html'] ?? ''), (string)($mail['body_text'] ?? '')));
    if ($bodyHash !== '') {
        $stmt = db()->prepare("SELECT id, message_uid, message_id_header, crm_send_id, mail_source, source_flags_json, body_hash FROM crm_mails WHERE user_id = ? AND mail_account_id = ? AND folder = 'sent' AND is_deleted = 0 AND subject = ? AND body_hash = ? AND ABS(TIMESTAMPDIFF(MINUTE, COALESCE(sent_at, created_at), ?)) <= 10 AND (crm_send_id IS NOT NULL OR message_uid LIKE 'send\\_%' OR mail_source = 'crm_sent') ORDER BY CASE WHEN crm_send_id IS NOT NULL OR message_uid LIKE 'send\\_%' THEN 0 ELSE 1 END, id DESC LIMIT 1");
        $stmt->execute([$userId, $accountId, $subject, $bodyHash, $sentAt]);
        $row = $stmt->fetch();
        if ($row) return $row;
    }
    if ($to === '') return null;
    $stmt = db()->prepare("SELECT id, message_uid, message_id_header, crm_send_id, mail_source, source_flags_json, body_hash FROM crm_mails WHERE user_id = ? AND mail_account_id = ? AND folder = 'sent' AND is_deleted = 0 AND subject = ? AND to_emails = ? AND ABS(TIMESTAMPDIFF(MINUTE, COALESCE(sent_at, created_at), ?)) <= 10 AND (crm_send_id IS NOT NULL OR message_uid LIKE 'send\\_%' OR (body_hash IS NOT NULL AND body_hash <> '' AND body_hash = ?)) ORDER BY CASE WHEN crm_send_id IS NOT NULL OR message_uid LIKE 'send\\_%' THEN 0 ELSE 1 END, id DESC LIMIT 1");
    $stmt->execute([$userId, $accountId, $subject, $to, $sentAt, $bodyHash]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function crm_mail_imap_fetch_sent_recent(array $account, int $limit = 30, int $sinceDays = 0): array
{
    $fp = crm_mail_imap_login($account);
    try {
        $mailbox = crm_mail_imap_select_first_sent_folder($fp);
        if ($mailbox === null) {
            @crm_mail_imap_command($fp, 'S999', 'LOGOUT');
            @fclose($fp);
            return ['new_count' => 0, 'duplicate_count' => 0, 'attachment_count' => 0, 'failed_count' => 0, 'total_count' => 0, 'folder_found' => false, 'folder_name' => ''];
        }
        $criteria = 'ALL';
        if ($sinceDays > 0) {
            $criteria = 'SINCE ' . gmdate('d-M-Y', strtotime('-' . max(1, min(30, $sinceDays)) . ' days'));
        }
        $search = crm_mail_imap_command($fp, 'S003', 'UID SEARCH ' . $criteria);
        preg_match('/\* SEARCH\s*(.*)\r?\n/i', $search, $m);
        $uids = array_values(array_filter(preg_split('/\s+/', trim($m[1] ?? '')) ?: []));
        $uids = array_slice($uids, -($sinceDays > 0 ? max($limit, 300) : $limit));
        $existingUids = [];
        if ($uids) {
            $placeholders = implode(',', array_fill(0, count($uids), '?'));
            $params = array_merge([(int)$account['id']], array_map('strval', $uids));
            $existingStmt = db()->prepare("SELECT id, message_uid FROM crm_mails WHERE mail_account_id = ? AND folder = 'sent' AND message_uid IN ({$placeholders})");
            $existingStmt->execute($params);
            foreach ($existingStmt->fetchAll() as $row) $existingUids[(string)$row['message_uid']] = (int)$row['id'];
        }
        $new = 0;
        $duplicate = 0;
        $failed = 0;
        $attachments = 0;
        foreach (array_reverse($uids) as $uid) {
            if (isset($existingUids[(string)$uid])) {
                db()->prepare("UPDATE crm_mails SET mail_source = CASE WHEN mail_source = 'crm_sent' THEN 'crm_sent' ELSE 'imap_sent' END, source_flags_json = CASE WHEN mail_source = 'crm_sent' OR crm_send_id IS NOT NULL THEN ? ELSE ? END, send_status = COALESCE(send_status, 'sent'), updated_at = NOW() WHERE id = ? AND user_id = ?")
                    ->execute([json_encode(['crm_sent', 'imap_sent', 'imap_synced'], JSON_UNESCAPED_UNICODE), json_encode(['imap_sent', 'imap_synced'], JSON_UNESCAPED_UNICODE), (int)$existingUids[(string)$uid], (int)$account['user_id']]);
                $duplicate++;
                continue;
            }
            try {
                $raw = crm_mail_imap_command($fp, 'SF' . preg_replace('/\D/', '', (string)$uid), 'UID FETCH ' . (int)$uid . ' BODY.PEEK[]');
                $mail = crm_mail_parse_raw_message($raw, (string)$uid, $account, 'sent');
                foreach (['subject', 'from_name', 'to_emails', 'cc_emails', 'body_html', 'body_text'] as $field) {
                    $mail[$field] = crm_mail_clean_utf8((string)($mail[$field] ?? ''));
                }
                $sentAt = $mail['received_at'] ?: date('Y-m-d H:i:s');
                $bodyHash = (string)($mail['body_hash'] ?? crm_mail_body_hash((string)($mail['body_html'] ?? ''), (string)($mail['body_text'] ?? '')));
                $existingSent = crm_mail_find_existing_sent_duplicate($account, $mail, $sentAt);
                if ($existingSent) {
                    $oldFlags = json_decode((string)($existingSent['source_flags_json'] ?? '[]'), true);
                    if (!is_array($oldFlags)) $oldFlags = [];
                    $flags = crm_mail_source_flags(array_merge($oldFlags, ['crm_sent', 'imap_sent', 'imap_synced']));
                    $tags = crm_mail_source_tags('crm_sent', $flags, ['已发送']);
                    db()->prepare("UPDATE crm_mails SET message_uid = ?, message_id_header = COALESCE(NULLIF(message_id_header,''), ?), mail_source = ?, source_flags_json = ?, crm_send_id = COALESCE(NULLIF(crm_send_id,''), NULLIF(?,'')), body_hash = COALESCE(NULLIF(body_hash,''), NULLIF(?,'')), imap_mailbox = ?, send_status = 'sent', raw_headers_json = COALESCE(raw_headers_json, ?), raw_headers = COALESCE(raw_headers, ?), raw_eml_path = COALESCE(NULLIF(raw_eml_path,''), ?), parse_status = ?, parse_error = ?, tags_json = ?, updated_at = NOW() WHERE id = ? AND user_id = ?")
                        ->execute([(string)$uid, (string)($mail['message_id_header'] ?? ''), in_array('crm_sent', $oldFlags, true) || str_starts_with((string)($existingSent['message_uid'] ?? ''), 'send_') ? 'crm_sent' : 'imap_sent', json_encode($flags, JSON_UNESCAPED_UNICODE), (string)($existingSent['crm_send_id'] ?? ''), $bodyHash, $mailbox, (string)($mail['raw_headers_json'] ?? json_encode([], JSON_UNESCAPED_UNICODE)), (string)($mail['raw_headers'] ?? ''), (string)($mail['raw_eml_path'] ?? ''), (string)($mail['parse_status'] ?? 'parsed'), (string)($mail['parse_error'] ?? ''), json_encode($tags, JSON_UNESCAPED_UNICODE), (int)$existingSent['id'], (int)$account['user_id']]);
                    crm_mail_store_parsed_attachments_for_mail($account, (int)$existingSent['id'], $mail);
                    $existingUids[(string)$uid] = true;
                    $duplicate++;
                    continue;
                }
                db()->prepare('INSERT IGNORE INTO crm_mails (user_id, mail_account_id, message_uid, message_id_header, folder, subject, from_email, from_name, to_emails, cc_emails, sent_at, body_html, body_text, body_status, has_body, has_attachment, attachment_count, is_read, is_unreplied, raw_headers_json, raw_headers, raw_eml_path, parse_status, parse_error, mail_source, source_flags_json, body_hash, imap_mailbox, send_status, tags_json, created_at, updated_at) VALUES (?, ?, ?, ?, "sent", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0, ?, ?, ?, ?, ?, "imap_sent", ?, ?, ?, "sent", ?, NOW(), NOW())')
                    ->execute([(int)$account['user_id'], (int)$account['id'], $mail['message_uid'], $mail['message_id_header'], $mail['subject'], $mail['from_email'] ?: (string)$account['email_address'], $mail['from_name'] ?: ((string)($account['sender_name'] ?? '') ?: (string)$account['email_address']), $mail['to_emails'], $mail['cc_emails'], $sentAt, $mail['body_html'], $mail['body_text'], $mail['body_status'], $mail['has_body'], $mail['has_attachment'], $mail['attachment_count'], $mail['raw_headers_json'], $mail['raw_headers'] ?? '', $mail['raw_eml_path'] ?? '', $mail['parse_status'] ?? 'parsed', $mail['parse_error'] ?? '', json_encode(['imap_sent'], JSON_UNESCAPED_UNICODE), $bodyHash, $mailbox, json_encode(['已发送', '腾讯同步'], JSON_UNESCAPED_UNICODE)]);
                $mailId = (int)db()->lastInsertId();
                if ($mailId > 0) {
                    $storeResult = crm_mail_store_attachment_files((int)$account['user_id'], $mailId, $mail['attachments'] ?? []);
                    if ((int)($storeResult['inline'] ?? 0) > 0) $mail['body_html'] = crm_mail_update_inline_body_urls((int)$account['user_id'], $mailId, (string)$mail['body_html']);
                    $countStmt = db()->prepare('SELECT COUNT(*) FROM crm_mail_attachments WHERE mail_id = ? AND user_id = ? AND COALESCE(is_inline,0) = 0 AND COALESCE(is_signature_image,0) = 0');
                    $countStmt->execute([$mailId, (int)$account['user_id']]);
                    $actualVisibleCount = (int)$countStmt->fetchColumn();
                    db()->prepare('UPDATE crm_mails SET has_attachment = ?, attachment_count = ?, updated_at = NOW() WHERE id = ? AND user_id = ?')
                        ->execute([$actualVisibleCount > 0 ? 1 : 0, $actualVisibleCount, $mailId, (int)$account['user_id']]);
                    $attachments += $actualVisibleCount;
                    $new++;
                } else {
                    $duplicate++;
                }
            } catch (Throwable $e) {
                $failed++;
                error_log('crm_mail_imap_fetch_sent_recent parse/store failed uid=' . (string)$uid . ': ' . $e->getMessage());
            }
        }
        @crm_mail_imap_command($fp, 'S999', 'LOGOUT');
        @fclose($fp);
        return ['new_count' => $new, 'duplicate_count' => $duplicate, 'attachment_count' => $attachments, 'failed_count' => $failed, 'total_count' => count($uids), 'folder_found' => true, 'folder_name' => $mailbox];
    } catch (Throwable $e) {
        @fwrite($fp, "S999 LOGOUT\r\n");
        @fclose($fp);
        throw $e;
    }
}

function crm_mail_account_test(string $type, array $input = []): array
{
    $targetUserId = crm_mail_target_user_id($input);
    if ($targetUserId === (int)(current_user()['id'] ?? 0)) crm_require('mail.account_bind_own');
    else crm_require('mail.account_manage_all');
    $account = crm_mail_current_account(true, null, $targetUserId);
    if (!$account) throw new RuntimeException('请先绑定邮箱账号。');
    $start = microtime(true);
    try {
        if ($type === 'smtp') {
            $fp = crm_mail_smtp_connect($account);
            @crm_mail_smtp_cmd($fp, 'QUIT', ['221', '250']);
            @fclose($fp);
        } else {
            $fp = crm_mail_imap_login($account);
            @crm_mail_imap_command($fp, 'A999', 'LOGOUT');
            @fclose($fp);
        }
        $result = ['ok' => true, 'message' => strtoupper($type) . ' 登录认证成功', 'latency_ms' => (int)round((microtime(true) - $start) * 1000)];
    } catch (Throwable $e) {
        $result = ['ok' => false, 'message' => $e->getMessage(), 'latency_ms' => (int)round((microtime(true) - $start) * 1000)];
    }
    crm_log_event('mail', 'account_test_' . $type, 'mail_account', (string)$account['id'], null, $result, $result['ok'], $result['ok'] ? '' : (string)$result['message']);
    return $result;
}

function crm_mail_list(array $input): array
{
    crm_require('mail.view');
    $account = crm_mail_current_account(false);
    if (!$account) return ['bound' => false, 'rows' => [], 'total' => 0, 'account' => null, 'folder_counts' => []];
    $folder = trim((string)($input['folder'] ?? 'inbox'));
    if ($folder === 'scheduled') return crm_mail_scheduled_list($account, $input);
    if ($folder === 'drafts') return crm_mail_draft_list($account, $input);
    if ($folder === 'unreplied' || (string)($input['quick'] ?? '') === 'unreplied') {
        crm_mail_refresh_unreplied_flags($account, (int)($input['unreplied_days'] ?? 3));
    }
    $params = [(int)$account['user_id'], (int)$account['id']];
    $where = ['m.user_id = ?', 'm.mail_account_id = ?'];
    if ($folder === 'unread') $where[] = 'm.is_read = 0';
    elseif ($folder === 'starred') $where[] = 'm.is_starred = 1';
    elseif ($folder === 'unreplied') $where[] = 'm.is_unreplied = 1';
    elseif ($folder === 'attachments') $where[] = 'EXISTS (SELECT 1 FROM crm_mail_attachments a WHERE a.mail_id = m.id AND a.user_id = m.user_id AND COALESCE(a.is_inline,0) = 0 AND COALESCE(a.is_signature_image,0) = 0)';
    elseif ($folder === 'linked') $where[] = 'm.linked_customer_id IS NOT NULL';
    elseif ($folder === 'unlinked') $where[] = 'm.linked_customer_id IS NULL';
    elseif ($folder === 'deleted') $where[] = 'm.is_deleted = 1';
    elseif ($folder !== 'all') { $where[] = 'm.folder = ?'; $params[] = $folder; }
    if ($folder === 'sent') {
        $where[] = "NOT EXISTS (
            SELECT 1 FROM crm_mails m2
            WHERE m2.user_id = m.user_id
              AND m2.mail_account_id = m.mail_account_id
              AND m2.folder = 'sent'
              AND m2.is_deleted = 0
              AND m2.id <> m.id
              AND (
                (m.message_id_header IS NOT NULL AND m.message_id_header <> '' AND m2.message_id_header IS NOT NULL AND m2.message_id_header <> '' AND LOWER(REPLACE(REPLACE(TRIM(m2.message_id_header), '<', ''), '>', '')) = LOWER(REPLACE(REPLACE(TRIM(m.message_id_header), '<', ''), '>', '')) AND m2.id < m.id)
                OR
                (m.message_uid NOT LIKE 'send\\_%' AND (m2.message_uid LIKE 'send\\_%' OR m2.crm_send_id IS NOT NULL OR m2.mail_source = 'crm_sent') AND m2.subject = m.subject AND m2.to_emails = m.to_emails AND ABS(TIMESTAMPDIFF(MINUTE, COALESCE(m2.sent_at, m2.created_at), COALESCE(m.sent_at, m.created_at))) <= 10)
                OR
                (m.message_uid NOT LIKE 'send\\_%' AND (m2.message_uid LIKE 'send\\_%' OR m2.crm_send_id IS NOT NULL OR m2.mail_source = 'crm_sent') AND m2.subject = m.subject AND m.body_hash IS NOT NULL AND m.body_hash <> '' AND m2.body_hash = m.body_hash AND ABS(TIMESTAMPDIFF(MINUTE, COALESCE(m2.sent_at, m2.created_at), COALESCE(m.sent_at, m.created_at))) <= 10)
                OR
                (m.message_uid NOT LIKE 'send\\_%' AND (m2.message_uid LIKE 'send\\_%' OR m2.crm_send_id IS NOT NULL OR m2.mail_source = 'crm_sent') AND m.message_id_header IS NOT NULL AND m.message_id_header <> '' AND m2.message_id_header IS NOT NULL AND m2.message_id_header <> '' AND LOCATE(LOWER(REPLACE(REPLACE(TRIM(m2.message_id_header), '<', ''), '>', '')), LOWER(REPLACE(REPLACE(TRIM(m.message_id_header), '<', ''), '>', ''))) > 0)
              )
        )";
    }
    if ($folder !== 'deleted') $where[] = 'm.is_deleted = 0';
    $q = trim((string)($input['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(m.subject LIKE ? OR m.from_email LIKE ? OR m.from_name LIKE ? OR m.to_emails LIKE ? OR m.body_text LIKE ? OR EXISTS (SELECT 1 FROM crm_mail_attachments a WHERE a.mail_id = m.id AND a.file_name LIKE ?))';
        for ($i = 0; $i < 6; $i++) $params[] = '%' . $q . '%';
    }
    $quick = trim((string)($input['quick'] ?? ''));
    if ($quick === 'no_body_attach') $where[] = "m.body_status = 'no_body_with_attachments'";
    if ($quick === 'unread') $where[] = 'm.is_read = 0';
    if ($quick === 'unreplied') $where[] = 'm.is_unreplied = 1';
    if ($quick === 'attachments') $where[] = 'EXISTS (SELECT 1 FROM crm_mail_attachments a WHERE a.mail_id = m.id AND a.user_id = m.user_id AND COALESCE(a.is_inline,0) = 0 AND COALESCE(a.is_signature_image,0) = 0)';
    if ($quick === 'linked') $where[] = 'm.linked_customer_id IS NOT NULL';
    if ($quick === 'unlinked') $where[] = 'm.linked_customer_id IS NULL';
    if ($quick === 'important') $where[] = 'm.is_starred = 1';
    if ($quick === 'today') $where[] = 'DATE(COALESCE(m.received_at, m.sent_at, m.created_at)) = CURDATE()';
    if ($quick === '7d') $where[] = 'COALESCE(m.received_at, m.sent_at, m.created_at) >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
    $readState = trim((string)($input['read_state'] ?? ''));
    if ($readState === 'read') $where[] = 'm.is_read = 1';
    if ($readState === 'unread') $where[] = 'm.is_read = 0';
    $attachmentState = trim((string)($input['attachment_state'] ?? ''));
    if ($attachmentState === 'has') $where[] = 'EXISTS (SELECT 1 FROM crm_mail_attachments a WHERE a.mail_id = m.id AND a.user_id = m.user_id AND COALESCE(a.is_inline,0) = 0 AND COALESCE(a.is_signature_image,0) = 0)';
    if ($attachmentState === 'none') $where[] = 'NOT EXISTS (SELECT 1 FROM crm_mail_attachments a WHERE a.mail_id = m.id AND a.user_id = m.user_id AND COALESCE(a.is_inline,0) = 0 AND COALESCE(a.is_signature_image,0) = 0)';
    if ($attachmentState === 'no_body_attach') $where[] = "m.body_status = 'no_body_with_attachments'";
    $linkState = trim((string)($input['link_state'] ?? ''));
    if ($linkState === 'linked') $where[] = 'm.linked_customer_id IS NOT NULL';
    if ($linkState === 'unlinked') $where[] = 'm.linked_customer_id IS NULL';
    $dateRange = trim((string)($input['date_range'] ?? ''));
    if ($dateRange === 'today') $where[] = 'DATE(COALESCE(m.received_at, m.sent_at, m.created_at)) = CURDATE()';
    if ($dateRange === '7d') $where[] = 'COALESCE(m.received_at, m.sent_at, m.created_at) >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
    if ($dateRange === 'month') $where[] = 'COALESCE(m.received_at, m.sent_at, m.created_at) >= DATE_FORMAT(CURDATE(), "%Y-%m-01")';
    $pageSize = max(1, min(200, (int)($input['page_size'] ?? 50)));
    $page = max(1, (int)($input['page'] ?? 1));
    $sqlWhere = implode(' AND ', $where);
    $count = db()->prepare("SELECT COUNT(*) FROM crm_mails m WHERE {$sqlWhere}");
    $count->execute($params);
    $offset = ($page - 1) * $pageSize;
    $stmt = db()->prepare("SELECT m.id, m.folder, m.message_uid, m.subject, m.from_email, m.from_name, m.to_emails, m.received_at, m.sent_at, LEFT(m.body_text, 240) AS body_text, m.body_status, m.has_body, m.has_attachment, m.attachment_count, 0 AS visible_attachment_count, m.is_read, m.is_replied, m.is_starred, m.is_unreplied, m.linked_customer_id, m.mail_source, m.source_flags_json, m.crm_send_id, m.send_status, m.tags_json, c.customer_name AS linked_customer_name FROM crm_mails m LEFT JOIN crm_customers c ON c.id = m.linked_customer_id WHERE {$sqlWhere} ORDER BY COALESCE(m.received_at, m.sent_at, m.created_at) DESC, m.id DESC LIMIT {$pageSize} OFFSET {$offset}");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $visibleCounts = crm_mail_visible_attachment_counts_for_rows($rows);
    foreach ($rows as &$row) {
        $row['source_flags'] = json_decode((string)($row['source_flags_json'] ?? '[]'), true) ?: [];
        $source = (string)($row['mail_source'] ?? '');
        if ((string)($row['folder'] ?? '') === 'sent' && ($source === '' || $source === 'imap_inbox')) {
            $source = str_starts_with((string)($row['message_uid'] ?? ''), 'send_') ? 'crm_sent' : 'imap_sent';
        }
        $row['source_label'] = crm_mail_source_label($source, $row['source_flags']);
        $row['tags'] = crm_mail_source_tags($source, $row['source_flags'], json_decode((string)($row['tags_json'] ?? '[]'), true) ?: []);
        $category = crm_mail_category_detect($row);
        $row['mail_category'] = $category;
        $row['category_label'] = crm_mail_category_label($category);
        if ($category !== 'normal') $row['tags'][] = $row['category_label'];
        foreach (['subject', 'from_name', 'to_emails', 'body_text'] as $field) {
            if (isset($row[$field])) $row[$field] = crm_mail_repair_text((string)$row[$field]);
        }
        $visibleAttachmentCount = (int)($visibleCounts[(int)$row['id']] ?? 0);
        $row['has_attachment'] = $visibleAttachmentCount > 0 ? 1 : 0;
        $row['attachment_count'] = $visibleAttachmentCount;
        $plain = trim(strip_tags((string)$row['body_text']));
        $row['summary'] = function_exists('mb_substr') ? mb_substr($plain, 0, 120) : substr($plain, 0, 120);
        if (!$row['summary'] && (int)$row['has_attachment'] === 1) $row['summary'] = '无正文 · 有附件';
        unset($row['tags_json'], $row['source_flags_json']);
    }
    $includeCounts = (string)($input['include_counts'] ?? '1') !== '0';
    return ['bound' => true, 'account' => crm_mail_account_payload($account)['account'], 'rows' => $rows, 'total' => (int)$count->fetchColumn(), 'page' => $page, 'page_size' => $pageSize, 'folder_counts' => $includeCounts ? crm_mail_folder_counts($account) : null];
}

function crm_mail_scheduled_list(array $account, array $input): array
{
    $pageSize = max(1, min(200, (int)($input['page_size'] ?? 50)));
    $page = max(1, (int)($input['page'] ?? 1));
    $params = [(int)$account['user_id'], (int)$account['id']];
    $where = ["user_id = ?", "mail_account_id = ?", "status IN ('scheduled','running')"];
    $q = trim((string)($input['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(subject LIKE ? OR to_emails LIKE ?)';
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
    }
    $sqlWhere = implode(' AND ', $where);
    $count = db()->prepare("SELECT COUNT(*) FROM crm_mail_send_jobs WHERE {$sqlWhere}");
    $count->execute($params);
    $offset = ($page - 1) * $pageSize;
    $stmt = db()->prepare("SELECT id, job_id, to_emails, subject, status, stage, percent, scheduled_at, error_message, created_at, updated_at FROM crm_mail_send_jobs WHERE {$sqlWhere} ORDER BY COALESCE(scheduled_at, created_at) ASC, id ASC LIMIT {$pageSize} OFFSET {$offset}");
    $stmt->execute($params);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $scheduledAt = (string)($row['scheduled_at'] ?? '');
        $remaining = $scheduledAt !== '' ? max(0, strtotime($scheduledAt) - time()) : 0;
        $status = (string)($row['status'] ?? '');
        $summary = $status === 'running'
            ? '正在发送...'
            : ('计划发送：' . ($scheduledAt ?: '-') . ($remaining > 0 ? ' · 剩余约 ' . ceil($remaining / 60) . ' 分钟' : ' · 等待服务器发送'));
        $rows[] = [
            'id' => (int)$row['id'],
            'job_id' => (string)$row['job_id'],
            'folder' => 'scheduled',
            'subject' => (string)($row['subject'] ?: '(无主题)'),
            'from_email' => (string)$account['email_address'],
            'from_name' => (string)($account['sender_name'] ?: $account['email_address']),
            'to_emails' => (string)($row['to_emails'] ?? ''),
            'received_at' => null,
            'sent_at' => $scheduledAt ?: (string)($row['created_at'] ?? ''),
            'summary' => $summary,
            'body_text' => $summary,
            'body_status' => 'scheduled',
            'has_body' => 1,
            'has_attachment' => 0,
            'attachment_count' => 0,
            'is_read' => 1,
            'is_replied' => 0,
            'is_starred' => 0,
            'is_unreplied' => 0,
            'linked_customer_id' => null,
            'linked_customer_name' => '',
            'tags' => [$status === 'running' ? '发送中' : '待发送'],
        ];
    }
    $includeCounts = (string)($input['include_counts'] ?? '1') !== '0';
    return ['bound' => true, 'account' => crm_mail_account_payload($account)['account'], 'rows' => $rows, 'total' => (int)$count->fetchColumn(), 'page' => $page, 'page_size' => $pageSize, 'folder_counts' => $includeCounts ? crm_mail_folder_counts($account) : null];
}

function crm_mail_visible_attachment_counts_for_rows(array $rows): array
{
    $ids = [];
    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id > 0) $ids[] = $id;
    }
    $ids = array_values(array_unique($ids));
    if (!$ids) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT mail_id, COUNT(*) AS cnt FROM crm_mail_attachments WHERE mail_id IN ({$placeholders}) AND COALESCE(is_inline,0) = 0 AND COALESCE(is_signature_image,0) = 0 GROUP BY mail_id");
    $stmt->execute($ids);
    $counts = [];
    foreach ($stmt->fetchAll() as $row) $counts[(int)$row['mail_id']] = (int)$row['cnt'];
    return $counts;
}

function crm_mail_draft_list(array $account, array $input): array
{
    $pageSize = max(1, min(200, (int)($input['page_size'] ?? 50)));
    $page = max(1, (int)($input['page'] ?? 1));
    $params = [(int)$account['user_id'], (int)$account['id']];
    $where = ['user_id = ?', 'mail_account_id = ?'];
    $q = trim((string)($input['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(subject LIKE ? OR to_emails LIKE ? OR cc_emails LIKE ? OR body_html LIKE ?)';
        for ($i = 0; $i < 4; $i++) $params[] = '%' . $q . '%';
    }
    $sqlWhere = implode(' AND ', $where);
    $count = db()->prepare("SELECT COUNT(*) FROM crm_mail_drafts WHERE {$sqlWhere}");
    $count->execute($params);
    $offset = ($page - 1) * $pageSize;
    $stmt = db()->prepare("SELECT id, mode, to_emails, cc_emails, bcc_emails, subject, body_html, updated_at, created_at FROM crm_mail_drafts WHERE {$sqlWhere} ORDER BY updated_at DESC, id DESC LIMIT {$pageSize} OFFSET {$offset}");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $bodyHtml = crm_mail_repair_text((string)($row['body_html'] ?? ''));
        $plain = crm_mail_draft_summary_text($bodyHtml);
        $hasImage = stripos($bodyHtml, '<img') !== false || stripos($bodyHtml, 'data:image/') !== false;
        $summary = $plain !== '' ? (function_exists('mb_substr') ? mb_substr($plain, 0, 120) : substr($plain, 0, 120)) : ($hasImage ? '含图片草稿' : '草稿未填写正文');
        $subject = trim(crm_mail_repair_text((string)($row['subject'] ?? '')));
        $row = [
            'id' => (int)$row['id'],
            'draft_id' => (int)$row['id'],
            'folder' => 'drafts',
            'subject' => $subject !== '' ? $subject : '(无主题草稿 #' . (int)$row['id'] . ')',
            'from_email' => (string)($account['email_address'] ?? ''),
            'from_name' => (string)($account['sender_name'] ?: ($account['email_address'] ?? '')),
            'to_emails' => crm_mail_repair_text((string)($row['to_emails'] ?? '')),
            'received_at' => null,
            'sent_at' => (string)($row['updated_at'] ?? $row['created_at'] ?? ''),
            'body_text' => $plain,
            'summary' => $summary,
            'body_status' => 'draft',
            'has_body' => $plain !== '' ? 1 : 0,
            'has_attachment' => 0,
            'attachment_count' => 0,
            'is_read' => 1,
            'is_replied' => 0,
            'is_starred' => 0,
            'is_unreplied' => 0,
            'linked_customer_id' => null,
            'linked_customer_name' => '',
            'tags' => ['草稿'],
        ];
    }
    return ['bound' => true, 'account' => crm_mail_account_payload($account)['account'], 'rows' => $rows, 'total' => (int)$count->fetchColumn(), 'page' => $page, 'page_size' => $pageSize, 'folder_counts' => crm_mail_folder_counts($account)];
}

function crm_mail_draft_summary_text(string $html): string
{
    $html = preg_replace('/<img\b[^>]*>/is', ' ', $html) ?? $html;
    $html = preg_replace('/<(br|\/p|\/div|\/li|\/tr|\/h[1-6])\b[^>]*>/i', ' ', $html) ?? $html;
    $text = trim(strip_tags($html));
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    $text = trim(crm_mail_repair_text($text));
    if ($text === '') return '';
    if (preg_match('/^Best\s*Regards\b/iu', $text) && !preg_match('/^(?!Best\s*Regards\b).{1,80}/iu', $text)) {
        return '';
    }
    return $text;
}

function crm_mail_apply_action(array $input, string $mailAction): array
{
    if ($mailAction === 'delete') crm_require('mail.delete');
    else crm_require('mail.view');
    $account = crm_mail_current_account(false);
    if (!$account) throw new RuntimeException('请先绑定邮箱。');
    $ids = [];
    $rawIds = $input['mail_ids'] ?? null;
    if (is_string($rawIds) && $rawIds !== '') {
        $decoded = json_decode($rawIds, true);
        if (is_array($decoded)) $ids = $decoded;
        else $ids = preg_split('/[,\s]+/', $rawIds) ?: [];
    } elseif (is_array($rawIds)) {
        $ids = $rawIds;
    }
    if (!$ids) $ids = [(int)($input['mail_id'] ?? 0)];
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0)));
    if (!$ids) throw new RuntimeException('请选择邮件。');

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $selectParams = array_merge($ids, [(int)$account['user_id'], (int)$account['id']]);
    $stmt = db()->prepare("SELECT id, subject, folder, is_read, is_deleted, is_archived FROM crm_mails WHERE id IN ({$placeholders}) AND user_id = ? AND mail_account_id = ?");
    $stmt->execute($selectParams);
    $beforeRows = $stmt->fetchAll();
    if (!$beforeRows) throw new RuntimeException('邮件不存在或无权操作。');
    $beforeById = [];
    foreach ($beforeRows as $row) $beforeById[(int)$row['id']] = $row;
    $validIds = array_keys($beforeById);
    $validPlaceholders = implode(',', array_fill(0, count($validIds), '?'));
    $updateParams = array_merge($validIds, [(int)$account['user_id'], (int)$account['id']]);

    if ($mailAction === 'read') {
        db()->prepare("UPDATE crm_mails SET is_read = 1, updated_at = NOW() WHERE id IN ({$validPlaceholders}) AND user_id = ? AND mail_account_id = ?")
            ->execute($updateParams);
        $message = '邮件已标记为已读';
    } elseif ($mailAction === 'unread') {
        db()->prepare("UPDATE crm_mails SET is_read = 0, updated_at = NOW() WHERE id IN ({$validPlaceholders}) AND user_id = ? AND mail_account_id = ?")
            ->execute($updateParams);
        $message = '邮件已标记为未读';
    } elseif ($mailAction === 'archive') {
        db()->prepare("UPDATE crm_mails SET folder = \"archive\", is_archived = 1, updated_at = NOW() WHERE id IN ({$validPlaceholders}) AND user_id = ? AND mail_account_id = ?")
            ->execute($updateParams);
        $message = '邮件已归档';
    } elseif ($mailAction === 'delete') {
        db()->prepare("UPDATE crm_mails SET folder = \"deleted\", is_deleted = 1, updated_at = NOW() WHERE id IN ({$validPlaceholders}) AND user_id = ? AND mail_account_id = ?")
            ->execute($updateParams);
        $message = '邮件已删除';
    } else {
        throw new RuntimeException('不支持的邮件操作。');
    }

    $afterStmt = db()->prepare("SELECT id, subject, folder, is_read, is_deleted, is_archived FROM crm_mails WHERE id IN ({$validPlaceholders}) AND user_id = ? AND mail_account_id = ?");
    $afterStmt->execute($updateParams);
    $afterRows = $afterStmt->fetchAll();
    $afterById = [];
    foreach ($afterRows as $row) $afterById[(int)$row['id']] = $row;
    foreach ($validIds as $id) {
        crm_log_event('mail', 'mail_' . $mailAction, 'mail', (string)$id, $beforeById[$id] ?? null, $afterById[$id] ?? null);
    }
    $count = count($validIds);
    return ['mail_id' => $validIds[0], 'mail_ids' => $validIds, 'count' => $count, 'action' => $mailAction, 'message' => $message . ($count > 1 ? '：' . $count . ' 封' : ''), 'mail' => $afterById[$validIds[0]] ?? null];
}

function crm_mail_perf_ms(float $start): int
{
    return (int)round((microtime(true) - $start) * 1000);
}

function crm_mail_crm_context(int $mailId): array
{
    $totalStart = microtime(true);
    $timing = ['total_ms' => 0, 'body_read_ms' => 0, 'mime_parse_ms' => 0, 'attachment_read_ms' => 0, 'crm_link_ms' => 0];
    crm_require('mail.view');
    $account = crm_mail_current_account(false);
    if (!$account) throw new RuntimeException('请先绑定邮箱。');
    $bodyStart = microtime(true);
    $stmt = db()->prepare('SELECT m.*, c.customer_name AS linked_customer_name FROM crm_mails m LEFT JOIN crm_customers c ON c.id = m.linked_customer_id WHERE m.id = ? AND m.user_id = ? AND m.mail_account_id = ? LIMIT 1');
    $stmt->execute([$mailId, (int)$account['user_id'], (int)$account['id']]);
    $mail = $stmt->fetch();
    $timing['body_read_ms'] = crm_mail_perf_ms($bodyStart);
    if (!$mail) throw new RuntimeException('邮件不存在或无权查看。');

    $crmStart = microtime(true);
    $crm = crm_mail_detect_customer_for_mail($mail);
    $crm['related_mails'] = crm_mail_customer_related_mails($mail, $account, $crm);
    $timing['crm_link_ms'] = crm_mail_perf_ms($crmStart);
    $timing['total_ms'] = crm_mail_perf_ms($totalStart);
    $crm['perf'] = $timing;
    error_log('crm_mail_open_perf ' . json_encode(['mail_id' => $mailId, 'stage' => 'crm_context'] + $timing, JSON_UNESCAPED_UNICODE));
    return ['crm' => $crm, 'perf' => $timing];
}

function crm_mail_get(int $mailId, bool $includeCrm = true): array
{
    $totalStart = microtime(true);
    $timing = ['total_ms' => 0, 'body_read_ms' => 0, 'mime_parse_ms' => 0, 'attachment_read_ms' => 0, 'crm_link_ms' => 0];
    crm_require('mail.view');
    $account = crm_mail_current_account(false);
    if (!$account) throw new RuntimeException('请先绑定邮箱。');
    $bodyStart = microtime(true);
    $stmt = db()->prepare('SELECT m.*, c.customer_name AS linked_customer_name FROM crm_mails m LEFT JOIN crm_customers c ON c.id = m.linked_customer_id WHERE m.id = ? AND m.user_id = ? AND m.mail_account_id = ? LIMIT 1');
    $stmt->execute([$mailId, (int)$account['user_id'], (int)$account['id']]);
    $mail = $stmt->fetch();
    $timing['body_read_ms'] = crm_mail_perf_ms($bodyStart);
    if (!$mail) throw new RuntimeException('邮件不存在或无权查看。');
    $mimeStart = microtime(true);
    $mail = crm_mail_repair_stored_body($mail);
    $mail = crm_mail_refetch_original_body($account, $mail);
    if ($includeCrm) $mail = crm_mail_refetch_missing_attachments($account, $mail);
    $timing['mime_parse_ms'] = crm_mail_perf_ms($mimeStart);
    $attachmentStart = microtime(true);
    $visibleCountStmt = db()->prepare('SELECT COUNT(*) FROM crm_mail_attachments WHERE mail_id = ? AND user_id = ? AND COALESCE(is_inline,0) = 0 AND COALESCE(is_signature_image,0) = 0');
    $visibleCountStmt->execute([$mailId, (int)$account['user_id']]);
    $visibleAttachmentCount = (int)$visibleCountStmt->fetchColumn();
    $forceNoBodyAttachment = ($visibleAttachmentCount > 0)
        && ((int)($mail['has_body'] ?? 1) === 0
            || (string)($mail['body_status'] ?? '') === 'no_body_with_attachments');
    if ($forceNoBodyAttachment) {
        $mail['body_html'] = crm_mail_no_body_attachment_html();
        $mail['body_text'] = '此邮件没有可解析正文，但包含附件。';
        $mail['body_status'] = 'no_body_with_attachments';
        $mail['has_body'] = 0;
    }
    $mail['source_flags'] = json_decode((string)($mail['source_flags_json'] ?? '[]'), true) ?: [];
    $source = (string)($mail['mail_source'] ?? '');
    if ((string)($mail['folder'] ?? '') === 'sent' && ($source === '' || $source === 'imap_inbox')) {
        $source = str_starts_with((string)($mail['message_uid'] ?? ''), 'send_') ? 'crm_sent' : 'imap_sent';
    }
    $mail['source_label'] = crm_mail_source_label($source, $mail['source_flags']);
    $mail['tags'] = crm_mail_source_tags($source, $mail['source_flags'], json_decode((string)($mail['tags_json'] ?? '[]'), true) ?: []);
    $mail['raw_headers'] = json_decode((string)($mail['raw_headers_json'] ?? '{}'), true) ?: [];
    if (trim((string)($mail['body_html'] ?? '')) === '' && trim((string)($mail['body_text'] ?? '')) !== '') {
        $mail['body_html'] = nl2br(htmlspecialchars((string)$mail['body_text'], ENT_QUOTES, 'UTF-8'));
    }
    $mail['body_html'] = crm_mail_clean_utf8((string)($mail['body_html'] ?? ''));
    $mail['body_text'] = crm_mail_clean_utf8((string)($mail['body_text'] ?? ''));
    if (trim(strip_tags((string)($mail['body_html'] ?? ''))) === '' && (string)($mail['body_html'] ?? '') !== '') {
        $mail['body_text'] = trim((string)($mail['body_text'] ?? '')) ?: '此邮件包含图片、表格或特殊格式正文。';
    }
    $mail = crm_mail_materialize_inline_data_images($mail);
    $mail['body_html'] = crm_mail_repair_stale_inline_attachment_links((int)$account['user_id'], $mailId, (string)($mail['body_html'] ?? ''));
    $attach = db()->prepare('SELECT id, file_name, filename, original_filename, file_path, file_size, mime_type, attachment_type, message_id, content_id, is_inline, is_signature_image, preview_status, CASE WHEN COALESCE(is_inline,0) = 0 AND COALESCE(is_signature_image,0) = 0 THEN 1 ELSE 0 END AS is_visible_attachment, CASE WHEN file_path IS NOT NULL AND file_path <> "" AND COALESCE(is_inline,0) = 0 AND COALESCE(is_signature_image,0) = 0 THEN 1 ELSE 0 END AS can_forward_file FROM crm_mail_attachments WHERE mail_id = ? AND user_id = ? ORDER BY id');
    $attach->execute([$mailId, (int)$account['user_id']]);
    $mail['attachments'] = $attach->fetchAll();
    $visibleAttachmentCount = 0;
    foreach ($mail['attachments'] as $attachment) {
        if ((int)($attachment['is_visible_attachment'] ?? 0) === 1) $visibleAttachmentCount++;
    }
    $mail['has_attachment'] = $visibleAttachmentCount > 0 ? 1 : 0;
    $mail['attachment_count'] = $visibleAttachmentCount;
    $mail['body_html'] = crm_mail_inline_body_html((string)($mail['body_html'] ?? ''), $mail['attachments']);
    $timing['attachment_read_ms'] = crm_mail_perf_ms($attachmentStart);
    db()->prepare('UPDATE crm_mails SET is_read = 1, updated_at = NOW() WHERE id = ?')->execute([$mailId]);
    crm_log_event('mail', 'mail_view', 'mail', (string)$mailId);
    $crm = ['matched' => false, 'reason' => 'deferred', 'candidates' => [], 'related_mails' => ['customer' => null, 'rows' => [], 'reason' => 'deferred']];
    if ($includeCrm) {
        $crmStart = microtime(true);
        $crm = crm_mail_detect_customer_for_mail($mail);
        $crm['related_mails'] = crm_mail_customer_related_mails($mail, $account, $crm);
        $timing['crm_link_ms'] = crm_mail_perf_ms($crmStart);
    }
    $timing['total_ms'] = crm_mail_perf_ms($totalStart);
    error_log('crm_mail_open_perf ' . json_encode(['mail_id' => $mailId, 'stage' => $includeCrm ? 'full' : 'body_only'] + $timing, JSON_UNESCAPED_UNICODE));
    return ['mail' => $mail, 'crm' => $crm, 'perf' => $timing];
}

function crm_mail_attachment_for_download(int $attachmentId, bool $inline = false): array
{
    crm_require('mail.view');
    $account = crm_mail_current_account(false);
    $row = null;
    if ($account) {
        $stmt = db()->prepare('SELECT a.*, m.subject FROM crm_mail_attachments a JOIN crm_mails m ON m.id = a.mail_id AND m.user_id = a.user_id WHERE a.id = ? AND a.user_id = ? AND m.mail_account_id = ? LIMIT 1');
        $stmt->execute([$attachmentId, (int)$account['user_id'], (int)$account['id']]);
        $row = $stmt->fetch();
    }
    if (!$row && $inline) {
        $currentUserId = (int)(current_user()['id'] ?? 0);
        $stmt = db()->prepare('SELECT a.*, m.subject FROM crm_mail_attachments a JOIN crm_mails m ON m.id = a.mail_id AND m.user_id = a.user_id WHERE a.id = ? AND a.user_id = ? LIMIT 1');
        $stmt->execute([$attachmentId, $currentUserId]);
        $row = $stmt->fetch();
    }
    if (!$row && $inline) {
        $currentUserId = (int)(current_user()['id'] ?? 0);
        $directRef = '%attachment_id=' . $attachmentId . '%';
        $encodedRef = '%attachment_id%3D' . $attachmentId . '%';
        $forwardCidRef = '%cid:crm-forward-inline-' . $attachmentId . '-%';
        $stmt = db()->prepare('SELECT a.*, m.subject
            FROM crm_mail_attachments a
            JOIN crm_mails m ON m.id = a.mail_id AND m.user_id = a.user_id
            WHERE a.id = ?
              AND (COALESCE(a.is_inline,0)=1 OR COALESCE(a.is_signature_image,0)=1 OR a.mime_type LIKE "image/%")
              AND EXISTS (
                SELECT 1 FROM crm_mails viewer
                WHERE viewer.user_id = ?
                  AND (viewer.body_html LIKE ? OR viewer.body_html LIKE ? OR viewer.body_html LIKE ?)
              )
            LIMIT 1');
        $stmt->execute([$attachmentId, $currentUserId, $directRef, $encodedRef, $forwardCidRef]);
        $row = $stmt->fetch();
    }
    if (!$row && $inline && (crm_can('mail.account_manage_all') || (function_exists('is_super_admin') && is_super_admin()))) {
        $stmt = db()->prepare('SELECT a.*, m.subject FROM crm_mail_attachments a JOIN crm_mails m ON m.id = a.mail_id AND m.user_id = a.user_id WHERE a.id = ? LIMIT 1');
        $stmt->execute([$attachmentId]);
        $row = $stmt->fetch();
    }
    if (!$row && !$account) throw new RuntimeException('请先绑定邮箱。');
    if (!$row) throw new RuntimeException('附件不存在或无权下载。');
    $mime = strtolower((string)($row['mime_type'] ?? ''));
    $isBodyImage = (int)($row['is_inline'] ?? 0) === 1 || (int)($row['is_signature_image'] ?? 0) === 1 || strpos($mime, 'image/') === 0;
    if (!$inline || !$isBodyImage) crm_require('mail.attachment_download');
    $path = (string)($row['file_path'] ?? '');
    if ($path === '' || !is_file($path)) throw new RuntimeException('附件文件不存在，可能是旧邮件未落地附件。请重新收取该邮件后再下载。');
    crm_log_event('mail', 'attachment_download', 'mail_attachment', (string)$attachmentId, null, ['mail_id' => (int)$row['mail_id'], 'file_name' => (string)$row['file_name']]);
    return [
        'path' => $path,
        'file_name' => (string)$row['file_name'],
        'mime_type' => (string)($row['mime_type'] ?: 'application/octet-stream'),
        'file_size' => (int)(filesize($path) ?: ($row['file_size'] ?? 0)),
    ];
}

function crm_mail_attachment_preview(int $attachmentId): array
{
    $attachment = crm_mail_attachment_for_download($attachmentId, true);
    return crm_mail_preview_file($attachment, 'mail_attachment_' . $attachmentId);
}

function crm_mail_preview_file(array $attachment, string $cachePrefix): array
{
    $mime = strtolower((string)$attachment['mime_type']);
    $name = (string)$attachment['file_name'];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (strpos($mime, 'image/') === 0 || strpos($mime, 'text/') === 0 || in_array($ext, ['txt','csv','log'], true) || $mime === 'application/pdf' || $ext === 'pdf') {
        return ['path' => $attachment['path'], 'name' => $name, 'mime_type' => $mime ?: 'application/octet-stream', 'file_size' => $attachment['file_size']];
    }
    $officeExts = ['doc','docx','xls','xlsx','ppt','pptx'];
    $officeMimes = [
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];
    if (!in_array($ext, $officeExts, true) && !in_array($mime, $officeMimes, true)) {
        throw new RuntimeException('该附件类型暂不支持在线预览，请下载查看。');
    }
    $converter = crm_mail_office_converter_binary();
    if ($converter === '') throw new RuntimeException('服务器未安装 LibreOffice，Word/Excel 暂不能在线预览，请下载查看。');
    $cacheDir = __DIR__ . '/storage/mail_previews';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
    if (!is_dir($cacheDir) || !is_writable($cacheDir)) throw new RuntimeException('预览缓存目录不可写，请下载查看。');
    $safePrefix = preg_replace('/[^A-Za-z0-9_-]+/', '_', $cachePrefix) ?: 'mail_preview';
    $cachePath = $cacheDir . '/' . $safePrefix . '_' . md5($attachment['path'] . '|' . filemtime($attachment['path'])) . '.pdf';
    if (!is_file($cachePath) || filesize($cachePath) <= 0) {
        crm_mail_convert_office_to_pdf($converter, $attachment['path'], $cacheDir);
        $generated = $cacheDir . '/' . pathinfo($attachment['path'], PATHINFO_FILENAME) . '.pdf';
        if (!is_file($generated) || filesize($generated) <= 0) throw new RuntimeException('附件转 PDF 失败，请下载查看。');
        if ($generated !== $cachePath) @rename($generated, $cachePath);
    }
    return ['path' => $cachePath, 'name' => preg_replace('/\.[^.]+$/', '', $name) . '.pdf', 'mime_type' => 'application/pdf', 'file_size' => (int)(filesize($cachePath) ?: 0)];
}

function crm_mail_local_attachment_preview(array $files): array
{
    crm_require('mail.send');
    $account = crm_mail_current_account(false);
    if (!$account) throw new RuntimeException('请先绑定邮箱。');
    $source = $files['attachment'] ?? null;
    if (!$source || empty($source['name'])) throw new RuntimeException('请选择要预览的附件。');
    if (($source['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('附件上传失败，无法预览。');
    $base = __DIR__ . '/storage';
    $root = $base . '/mail_local_previews';
    $userDir = $root . '/' . (int)$account['user_id'];
    foreach ([$base, $root, $userDir] as $dir) {
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
    }
    if (!is_dir($userDir) || !is_writable($userDir)) throw new RuntimeException('本地附件预览目录不可写。');
    foreach (glob($userDir . '/*') ?: [] as $old) {
        if (is_file($old) && filemtime($old) < time() - 86400) @unlink($old);
    }
    $name = crm_mail_safe_file_name(basename((string)$source['name']));
    $target = $userDir . '/' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '_' . $name;
    if (!@move_uploaded_file((string)$source['tmp_name'], $target)) throw new RuntimeException('附件临时保存失败，无法预览。');
    $mime = (string)($source['type'] ?? '');
    if ($mime === '' || $mime === 'application/octet-stream') {
        $detected = function_exists('mime_content_type') ? @mime_content_type($target) : '';
        if (is_string($detected) && $detected !== '') $mime = $detected;
    }
    return crm_mail_preview_file([
        'path' => $target,
        'file_name' => $name,
        'mime_type' => $mime ?: 'application/octet-stream',
        'file_size' => (int)(filesize($target) ?: 0),
    ], 'local_attachment_' . (int)$account['user_id']);
}

function crm_mail_office_converter_binary(): string
{
    foreach (['/usr/bin/libreoffice', '/usr/local/bin/libreoffice', '/usr/bin/soffice', '/usr/local/bin/soffice'] as $path) {
        if (is_executable($path)) return $path;
    }
    foreach (['libreoffice', 'soffice'] as $cmd) {
        $found = trim((string)@shell_exec('command -v ' . escapeshellarg($cmd) . ' 2>/dev/null'));
        if ($found !== '' && is_executable($found)) return $found;
    }
    return '';
}

function crm_mail_convert_office_to_pdf(string $converter, string $sourcePath, string $outDir): void
{
    [$code, $output] = crm_mail_run_office_converter($converter, $sourcePath, $outDir);
    if ($code !== 0) throw new RuntimeException('附件转 PDF 失败：' . trim(implode("\n", array_slice(preg_split('/\R/', $output), -3))));
}

function crm_mail_run_office_converter(string $converter, string $sourcePath, string $outDir): array
{
    $args = ['--headless', '--nologo', '--nofirststartwizard', '--convert-to', 'pdf', '--outdir', $outDir, $sourcePath];
    $env = [
        'HOME' => $outDir,
        'TMPDIR' => $outDir,
        'XDG_CACHE_HOME' => $outDir . '/.cache',
        'XDG_CONFIG_HOME' => $outDir . '/.config',
    ];
    @mkdir($env['XDG_CACHE_HOME'], 0775, true);
    @mkdir($env['XDG_CONFIG_HOME'], 0775, true);

    $cmd = 'HOME=' . escapeshellarg($env['HOME']) .
        ' TMPDIR=' . escapeshellarg($env['TMPDIR']) .
        ' XDG_CACHE_HOME=' . escapeshellarg($env['XDG_CACHE_HOME']) .
        ' XDG_CONFIG_HOME=' . escapeshellarg($env['XDG_CONFIG_HOME']) .
        ' ' . escapeshellarg($converter) . ' ' .
        implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';

    if (function_exists('shell_exec')) {
        $output = (string)@shell_exec($cmd);
        return [0, trim($output)];
    }
    if (function_exists('proc_open') && function_exists('proc_close')) {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open(array_merge([$converter], $args), $descriptors, $pipes, null, $env, ['bypass_shell' => true]);
        if (is_resource($process)) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            return [(int)proc_close($process), trim($stdout . "\n" . $stderr)];
        }
    }
    if (function_exists('exec')) {
        $output = [];
        $code = 0;
        @exec($cmd, $output, $code);
        return [(int)$code, trim(implode("\n", $output))];
    }
    if (function_exists('system')) {
        ob_start();
        $code = 0;
        @system($cmd, $code);
        return [(int)$code, trim((string)ob_get_clean())];
    }
    throw new RuntimeException('服务器禁用了 Office 转换所需的 PHP 执行函数，请下载查看。');
}

function crm_mail_remote_image_proxy(string $url): array
{
    crm_require('mail.view');
    $url = trim($url);
    if ($url === '' || strlen($url) > 2048) throw new RuntimeException('图片地址无效。');
    $parts = parse_url($url);
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = strtolower((string)($parts['host'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true) || $host === '') throw new RuntimeException('只支持远程图片地址。');
    if ($host === 'localhost' || substr($host, -6) === '.local') throw new RuntimeException('图片地址不可访问。');
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new RuntimeException('图片地址不可访问。');
        }
    } else {
        $ips = gethostbynamel($host) ?: [];
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException('图片地址不可访问。');
            }
        }
    }

    $content = false;
    $mime = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; ArtdonCRM/1.0)',
            CURLOPT_HTTPHEADER => ['Accept: image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8'],
        ]);
        $content = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $mime = strtolower(trim((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE)));
        curl_close($ch);
        if ($status < 200 || $status >= 300) $content = false;
    } else {
        $context = stream_context_create([
            'http' => [
                'timeout' => 15,
                'follow_location' => 1,
                'max_redirects' => 3,
                'header' => "Accept: image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8\r\nUser-Agent: Mozilla/5.0 (compatible; ArtdonCRM/1.0)\r\n",
            ],
        ]);
        $content = @file_get_contents($url, false, $context);
    }
    if ($content === false || $content === '') throw new RuntimeException('远程图片无法加载。');
    if (strlen($content) > 8 * 1024 * 1024) throw new RuntimeException('远程图片过大。');
    if ($mime === '' || strpos($mime, 'image/') !== 0) {
        $info = function_exists('finfo_buffer') ? new finfo(FILEINFO_MIME_TYPE) : null;
        $mime = $info ? (string)$info->buffer($content) : '';
    }
    if (strpos($mime, 'image/') !== 0) throw new RuntimeException('远程地址不是图片。');
    $mime = preg_replace('/\s*;.*/', '', $mime) ?: 'image/png';
    return ['content' => $content, 'mime_type' => $mime, 'file_size' => strlen($content)];
}

function crm_mail_detect_customer_for_mail(array $mail): array
{
    $linkedCustomerId = (int)($mail['linked_customer_id'] ?? 0);
    if ($linkedCustomerId > 0) {
        $stmt = db()->prepare('SELECT c.id, c.customer_name, c.country, u.username AS owner_name FROM crm_customers c LEFT JOIN crm_users u ON u.id = c.owner_user_id WHERE c.id = ? AND c.deleted_at IS NULL LIMIT 1');
        $stmt->execute([$linkedCustomerId]);
        $row = $stmt->fetch();
        if ($row) return ['matched' => true, 'reason' => 'linked_customer', 'candidates' => [$row]];
    }

    $emails = crm_mail_addresses_from_mail($mail);
    if ($emails) {
        $placeholders = implode(',', array_fill(0, count($emails), '?'));
        $stmt = db()->prepare("SELECT DISTINCT c.id, c.customer_name, c.country, u.username AS owner_name
            FROM crm_customers c
            LEFT JOIN crm_contacts ct ON ct.customer_id = c.id AND ct.deleted_at IS NULL
            LEFT JOIN crm_users u ON u.id = c.owner_user_id
            WHERE c.deleted_at IS NULL
              AND (LOWER(c.email) IN ({$placeholders}) OR LOWER(ct.email) IN ({$placeholders}))
            ORDER BY c.updated_at DESC
            LIMIT 5");
        $stmt->execute(array_merge($emails, $emails));
        $rows = $stmt->fetchAll();
        if ($rows) return ['matched' => true, 'reason' => 'mail_email_match', 'candidates' => $rows];
    }
    return ['matched' => false, 'reason' => 'no_match', 'candidates' => []];
}

function crm_mail_customer_related_mails(array $mail, array $account, array $crm = []): array
{
    $customerId = (int)($mail['linked_customer_id'] ?? 0);
    if ($customerId <= 0 && !empty($crm['candidates'][0]['id'])) $customerId = (int)$crm['candidates'][0]['id'];
    if ($customerId <= 0) return ['customer' => null, 'rows' => [], 'reason' => 'no_customer'];

    $customerStmt = db()->prepare('SELECT c.id, c.customer_name, c.customer_code, c.country, c.email, u.username AS owner_name FROM crm_customers c LEFT JOIN crm_users u ON u.id = c.owner_user_id WHERE c.id = ? AND c.deleted_at IS NULL LIMIT 1');
    $customerStmt->execute([$customerId]);
    $customer = $customerStmt->fetch();
    if (!$customer) return ['customer' => null, 'rows' => [], 'reason' => 'customer_not_found'];

    $emails = [];
    $customerEmail = strtolower(trim((string)($customer['email'] ?? '')));
    if ($customerEmail !== '' && filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) $emails[] = $customerEmail;
    $contactStmt = db()->prepare('SELECT email FROM crm_contacts WHERE customer_id = ? AND deleted_at IS NULL AND email IS NOT NULL AND email <> ""');
    $contactStmt->execute([$customerId]);
    foreach ($contactStmt->fetchAll(PDO::FETCH_COLUMN) as $email) {
        $email = strtolower(trim((string)$email));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) $emails[] = $email;
    }
    $emails = array_values(array_unique($emails));

    $or = ['m.linked_customer_id = ?'];
    $params = [(int)$account['user_id'], (int)$account['id'], $customerId];
    foreach ($emails as $email) {
        $or[] = 'LOWER(m.from_email) = ?';
        $params[] = $email;
        foreach (['to_emails', 'cc_emails', 'bcc_emails'] as $field) {
            $or[] = 'LOWER(m.`' . $field . '`) LIKE ?';
            $params[] = '%' . $email . '%';
        }
    }

    $sql = 'SELECT m.id, m.folder, m.subject, m.from_email, m.from_name, m.to_emails, m.received_at, m.sent_at, m.has_attachment, m.attachment_count, m.is_read, m.linked_customer_id
        FROM crm_mails m
        WHERE m.user_id = ? AND m.mail_account_id = ? AND m.is_deleted = 0
          AND m.folder IN ("inbox", "sent")
          AND (' . implode(' OR ', $or) . ')
        ORDER BY COALESCE(m.received_at, m.sent_at, m.created_at) DESC, m.id DESC
        LIMIT 20';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return ['customer' => $customer, 'rows' => $stmt->fetchAll(), 'reason' => $emails ? 'customer_emails' : 'linked_customer'];
}

function crm_mail_current_related_mails(int $mailId): array
{
    crm_require('mail.view');
    $account = crm_mail_current_account(false);
    if (!$account) throw new RuntimeException('请先绑定邮箱。');

    $stmt = db()->prepare('SELECT m.*, c.customer_name AS linked_customer_name FROM crm_mails m LEFT JOIN crm_customers c ON c.id = m.linked_customer_id WHERE m.id = ? AND m.user_id = ? AND m.mail_account_id = ? LIMIT 1');
    $stmt->execute([$mailId, (int)$account['user_id'], (int)$account['id']]);
    $mail = $stmt->fetch();
    if (!$mail) throw new RuntimeException('邮件不存在或无权查看。');

    $crm = crm_mail_detect_customer_for_mail($mail);
    $customerRelated = crm_mail_customer_related_mails($mail, $account, $crm);
    $customer = $customerRelated['customer'] ?? null;

    $ownEmails = [];
    foreach ([(string)($account['email_address'] ?? ''), (string)($account['email_username'] ?? '')] as $email) {
        $email = strtolower(trim($email));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) $ownEmails[] = $email;
    }
    $ownEmails = array_values(array_unique($ownEmails));

    $allEmails = crm_mail_addresses_from_mail($mail);
    $lookupEmails = array_values(array_filter($allEmails, static function ($email) use ($ownEmails) {
        return !in_array(strtolower((string)$email), $ownEmails, true);
    }));
    if (!$lookupEmails) $lookupEmails = $allEmails;

    $or = [];
    $params = [(int)$account['user_id'], (int)$account['id']];
    if (!empty($customer['id'])) {
        $or[] = 'm.linked_customer_id = ?';
        $params[] = (int)$customer['id'];
    }
    foreach ($lookupEmails as $email) {
        $email = strtolower(trim((string)$email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
        $or[] = 'LOWER(m.from_email) = ?';
        $params[] = $email;
        foreach (['to_emails', 'cc_emails', 'bcc_emails'] as $field) {
            $or[] = 'LOWER(m.`' . $field . '`) LIKE ?';
            $params[] = '%' . $email . '%';
        }
    }
    if (!$or) return ['customer' => $customer, 'emails' => [], 'rows' => [], 'reason' => 'no_related_email'];

    $sql = 'SELECT m.id, m.folder, m.subject, m.from_email, m.from_name, m.to_emails, m.cc_emails, m.received_at, m.sent_at, m.has_attachment, m.attachment_count, m.is_read, m.linked_customer_id, c.customer_name AS linked_customer_name
        FROM crm_mails m
        LEFT JOIN crm_customers c ON c.id = m.linked_customer_id
        WHERE m.user_id = ? AND m.mail_account_id = ? AND m.is_deleted = 0
          AND m.folder IN ("inbox", "sent")
          AND (' . implode(' OR ', $or) . ')
        ORDER BY COALESCE(m.received_at, m.sent_at, m.created_at) DESC, m.id DESC
        LIMIT 60';
    $relatedStmt = db()->prepare($sql);
    $relatedStmt->execute($params);
    $rows = $relatedStmt->fetchAll();

    crm_log_event('mail', 'mail_current_related_view', 'mail', (string)$mailId, null, [
        'emails' => $lookupEmails,
        'customer_id' => (int)($customer['id'] ?? 0),
        'count' => count($rows),
    ]);

    return [
        'customer' => $customer,
        'emails' => $lookupEmails,
        'rows' => $rows,
        'reason' => !empty($customer['id']) ? 'customer_and_current_email' : 'current_mail_email',
    ];
}

function crm_mail_sync_start(array $input = []): array
{
    crm_require('mail.sync');
    $account = crm_mail_current_account(true);
    if (!$account) throw new RuntimeException('请先绑定邮箱。');
    $days = max(0, min(30, (int)($input['sync_days'] ?? 0)));
    $isAuto = !empty($input['auto_sync']);
    return crm_mail_sync_account($account, $days > 0 ? 300 : 30, $isAuto ? 'auto' : ($days > 0 ? 'manual_' . $days . 'd' : 'manual'), $days);
}

function crm_mail_sync_source_uses_backoff(string $source): bool
{
    return in_array($source, ['auto', 'cron'], true);
}

function crm_mail_sync_backoff_minutes(string $message, int $failCount): int
{
    $message = strtolower($message);
    $steps = (strpos($message, 'frequency') !== false
        || strpos($message, 'login fail') !== false
        || strpos($message, 'password') !== false
        || strpos($message, 'account is abnormal') !== false
        || strpos($message, 'service is not open') !== false)
        ? [5, 10, 30, 60]
        : [2, 5, 10, 20];
    $index = max(0, min(count($steps) - 1, $failCount - 1));
    return $steps[$index];
}

function crm_mail_sync_skip_if_backoff(array $account, string $source): ?array
{
    if (!crm_mail_sync_source_uses_backoff($source)) return null;
    $accountId = (int)($account['id'] ?? 0);
    $userId = (int)($account['user_id'] ?? 0);
    if ($accountId <= 0 || $userId <= 0) return null;
    $stmt = db()->prepare('SELECT sync_status, sync_error, sync_fail_count, sync_backoff_until, updated_at FROM crm_user_mail_accounts WHERE id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$accountId, $userId]);
    $row = $stmt->fetch();
    if (!$row) return null;
    if ((string)($row['sync_status'] ?? '') === 'syncing' && strtotime((string)($row['updated_at'] ?? '')) >= time() - 600) {
        return ['status' => 'skipped', 'stage' => 'locked', 'percent' => 100, 'message' => '该邮箱正在同步，自动收信已跳过本次。'];
    }
    $until = (string)($row['sync_backoff_until'] ?? '');
    if ($until !== '' && strtotime($until) > time()) {
        return [
            'status' => 'skipped',
            'stage' => 'backoff',
            'percent' => 100,
            'message' => '自动收信暂停至 ' . $until . '，避免腾讯邮箱频率限制。',
            'backoff_until' => $until,
            'sync_error' => (string)($row['sync_error'] ?? ''),
            'sync_fail_count' => (int)($row['sync_fail_count'] ?? 0),
        ];
    }
    return null;
}

function crm_mail_notify_new_messages(array $account, array $result, string $syncId, string $source): void
{
    $newCount = (int)($result['new_count'] ?? 0);
    if ($newCount <= 0 || !function_exists('create_system_notification')) {
        return;
    }
    $email = (string)($account['email_address'] ?? '');
    $mailIds = array_values(array_filter(array_map('intval', $result['new_mail_ids'] ?? [])));
    $subjects = [];
    foreach (($result['new_mail_subjects'] ?? []) as $subject) {
        $subject = crm_mail_notification_subject_snippet((string)$subject, 80);
        if ($subject !== '') $subjects[] = $subject;
    }
    if (!$subjects && $mailIds) {
        $subjects = crm_mail_notification_subjects_by_ids($mailIds);
    }
    $firstSubject = $subjects[0] ?? '';
    if ($firstSubject === '') $firstSubject = '无主题邮件';
    $mailIdsHash = hash('sha256', implode(',', $mailIds));
    $title = '收件箱 · ' . $firstSubject;
    $content = '共 ' . $newCount . ' 封新邮件';
    if ($subjects) {
        $content .= '：' . implode('；', array_slice($subjects, 0, 3));
        if ($newCount > 3) $content .= ' 等';
    }
    if ($email !== '') $content .= '。邮箱：' . $email;
    create_system_notification((int)$account['user_id'], 'mail_new', $title, $content, [
        'module' => 'mail',
        'category' => 'mail',
        'account_id' => (int)$account['id'],
        'mailbox_account' => $email,
        'email_address' => $email,
        'sync_id' => $syncId,
        'sync_batch_id' => $syncId,
        'new_count' => $newCount,
        'mail_ids' => $mailIds,
        'mail_ids_hash' => $mailIdsHash,
        'mail_subjects' => array_slice($subjects, 0, 5),
        'subject_preview' => $firstSubject,
        'related_mail_id' => $mailIds[0] ?? 0,
        'source_module' => 'mail',
        'target_module' => 'mail',
        'target_id' => (string)($mailIds[0] ?? ''),
        'source' => $source,
    ]);
}

function crm_mail_notification_subject_snippet(string $subject, int $limit = 80): string
{
    $subject = html_entity_decode($subject, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $subject = trim(preg_replace('/\s+/u', ' ', $subject) ?: '');
    if ($subject === '') return '';
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($subject, 'UTF-8') > $limit ? mb_substr($subject, 0, $limit, 'UTF-8') . '...' : $subject;
    }
    return strlen($subject) > $limit ? substr($subject, 0, $limit) . '...' : $subject;
}

function crm_mail_notification_subjects_by_ids(array $mailIds): array
{
    $mailIds = array_values(array_filter(array_map('intval', $mailIds)));
    if (!$mailIds || !db_table_exists('crm_mails')) return [];
    $placeholders = implode(',', array_fill(0, count($mailIds), '?'));
    $stmt = db()->prepare("SELECT id, subject FROM crm_mails WHERE id IN ({$placeholders}) ORDER BY FIELD(id, {$placeholders})");
    $params = array_merge($mailIds, $mailIds);
    $stmt->execute($params);
    $subjects = [];
    foreach ($stmt->fetchAll() as $row) {
        $subject = crm_mail_notification_subject_snippet((string)($row['subject'] ?? ''), 80);
        if ($subject !== '') $subjects[] = $subject;
    }
    return $subjects;
}

function crm_mail_sync_account(array $account, int $limit = 30, string $source = 'manual', int $sinceDays = 0): array
{
    $skip = crm_mail_sync_skip_if_backoff($account, $source);
    if ($skip) {
        return array_merge([
            'sync_id' => '',
            'account_id' => (int)$account['id'],
            'user_id' => (int)$account['user_id'],
            'email_address' => (string)$account['email_address'],
            'source' => $source,
            'new_count' => 0,
            'duplicate_count' => 0,
            'attachment_count' => 0,
            'failed_count' => 0,
            'sent_new_count' => 0,
            'sent_folder_found' => 0,
            'sent_folder_name' => '',
        ], $skip);
    }
    $syncId = 'sync_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
    db()->prepare('INSERT INTO crm_mail_sync_logs (user_id, mail_account_id, sync_id, stage, percent, status, message, created_at) VALUES (?, ?, ?, "connect", 15, "running", "连接邮箱服务器", NOW())')
        ->execute([(int)$account['user_id'], (int)$account['id'], $syncId]);
    db()->prepare('UPDATE crm_user_mail_accounts SET sync_status = "syncing", sync_error = NULL, updated_at = NOW() WHERE id = ?')->execute([(int)$account['id']]);
    try {
        $result = crm_mail_imap_fetch_recent($account, $limit, $sinceDays);
        $sentResult = crm_mail_imap_fetch_sent_recent($account, $limit, $sinceDays);
        $result['sent_new_count'] = (int)($sentResult['new_count'] ?? 0);
        $result['sent_duplicate_count'] = (int)($sentResult['duplicate_count'] ?? 0);
        $result['sent_attachment_count'] = (int)($sentResult['attachment_count'] ?? 0);
        $result['sent_failed_count'] = (int)($sentResult['failed_count'] ?? 0);
        $result['sent_total_count'] = (int)($sentResult['total_count'] ?? 0);
        $result['sent_folder_found'] = !empty($sentResult['folder_found']) ? 1 : 0;
        $result['sent_folder_name'] = (string)($sentResult['folder_name'] ?? '');
        $foldersJson = [
            'inbox' => [
                'folder' => 'INBOX',
                'read_count' => (int)$result['total_count'],
                'new_count' => (int)$result['new_count'],
                'merged_count' => 0,
                'skipped_count' => (int)$result['duplicate_count'],
                'failed_count' => (int)$result['failed_count'],
                'attachment_count' => (int)$result['attachment_count'],
            ],
            'sent' => [
                'folder' => (string)$result['sent_folder_name'],
                'folder_found' => (int)$result['sent_folder_found'],
                'read_count' => (int)$result['sent_total_count'],
                'new_count' => (int)$result['sent_new_count'],
                'merged_count' => (int)$result['sent_duplicate_count'],
                'skipped_count' => 0,
                'failed_count' => (int)$result['sent_failed_count'],
                'attachment_count' => (int)$result['sent_attachment_count'],
            ],
        ];
        $message = '同步完成：收件箱读取 ' . (int)$result['total_count'] . '，新增 ' . (int)$result['new_count'] . '；已发送文件夹 ' . ((int)$result['sent_folder_found'] ? (string)$result['sent_folder_name'] : '未识别') . '，读取 ' . (int)$result['sent_total_count'] . '，新增 ' . (int)$result['sent_new_count'] . '，合并/重复 ' . (int)$result['sent_duplicate_count'] . '，失败 ' . ((int)$result['failed_count'] + (int)$result['sent_failed_count']);
        if (!(int)$result['sent_folder_found']) $message .= '。请在邮箱设置中选择已发送文件夹。';
        db()->prepare('UPDATE crm_mail_sync_logs SET stage = "done", percent = 100, status = "success", current_count = ?, total_count = ?, new_count = ?, duplicate_count = ?, attachment_count = ?, skipped_count = ?, failed_count = ?, folders_json = ?, finished_at = NOW(), message = ? WHERE sync_id = ? AND user_id = ?')
            ->execute([(int)$result['total_count'] + (int)$result['sent_total_count'], (int)$result['total_count'] + (int)$result['sent_total_count'], (int)$result['new_count'] + (int)$result['sent_new_count'], (int)$result['duplicate_count'] + (int)$result['sent_duplicate_count'], (int)$result['attachment_count'] + (int)$result['sent_attachment_count'], (int)$result['duplicate_count'], (int)$result['failed_count'] + (int)$result['sent_failed_count'], json_encode($foldersJson, JSON_UNESCAPED_UNICODE), $message, $syncId, (int)$account['user_id']]);
        db()->prepare('UPDATE crm_user_mail_accounts SET sync_status = "success", sync_error = NULL, sync_fail_count = 0, sync_backoff_until = NULL, last_sync_time = NOW(), updated_at = NOW() WHERE id = ?')->execute([(int)$account['id']]);
        crm_mail_notify_new_messages($account, $result, $syncId, $source);
        crm_log_event('mail', $source === 'cron' ? 'sync_cron_success' : 'sync_success', 'mail_account', (string)$account['id'], null, $result);
        return ['sync_id' => $syncId, 'account_id' => (int)$account['id'], 'user_id' => (int)$account['user_id'], 'email_address' => (string)$account['email_address'], 'source' => $source, 'status' => 'success', 'stage' => 'done', 'percent' => 100, 'message' => $message, 'new_count' => (int)$result['new_count'] + (int)$result['sent_new_count'], 'duplicate_count' => (int)$result['duplicate_count'] + (int)$result['sent_duplicate_count'], 'attachment_count' => (int)$result['attachment_count'] + (int)$result['sent_attachment_count'], 'skipped_count' => (int)$result['duplicate_count'], 'failed_count' => (int)$result['failed_count'] + (int)$result['sent_failed_count'], 'sent_new_count' => (int)$result['sent_new_count'], 'sent_folder_found' => (int)$result['sent_folder_found'], 'sent_folder_name' => (string)$result['sent_folder_name'], 'folders' => $foldersJson];
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $failCount = (int)($account['sync_fail_count'] ?? 0) + 1;
        $backoffMinutes = crm_mail_sync_backoff_minutes($message, $failCount);
        $backoffUntil = date('Y-m-d H:i:s', time() + $backoffMinutes * 60);
        db()->prepare('UPDATE crm_mail_sync_logs SET stage = "failed", percent = 100, status = "failed", failed_count = 1, error_message = ?, finished_at = NOW(), message = "同步失败" WHERE sync_id = ? AND user_id = ?')
            ->execute([$message, $syncId, (int)$account['user_id']]);
        db()->prepare('UPDATE crm_user_mail_accounts SET sync_status = "failed", sync_error = ?, sync_fail_count = ?, sync_backoff_until = ?, updated_at = NOW() WHERE id = ?')->execute([$message, $failCount, $backoffUntil, (int)$account['id']]);
        if (function_exists('create_system_notification')) {
            create_system_notification((int)$account['user_id'], 'api_error', '邮件同步失败', '邮箱 ' . (string)($account['email_address'] ?? '') . ' 同步失败：' . $message . '。自动收信暂停至 ' . $backoffUntil, [
                'module' => 'mail',
                'category' => 'error',
                'source_module' => 'mail',
                'target_module' => 'mail',
                'account_id' => (int)$account['id'],
                'mailbox_account' => (string)($account['email_address'] ?? ''),
                'sync_batch_id' => $syncId,
                'severity' => 'danger',
                'dedupe_key' => 'mail_sync_error:' . (int)$account['user_id'] . ':' . (int)$account['id'] . ':' . $syncId,
            ]);
        }
        crm_log_event('mail', $source === 'cron' ? 'sync_cron_failed' : 'sync_failed', 'mail_account', (string)$account['id'], null, ['error' => $message, 'backoff_until' => $backoffUntil, 'fail_count' => $failCount], false, $message);
        throw new RuntimeException('收信失败：' . $message);
    }
}

function crm_mail_cron_sync_due_accounts(int $intervalMinutes = 3, int $limit = 30): array
{
    crm_mail_ensure_tables();
    $intervalMinutes = max(1, min(60, $intervalMinutes));
    $limit = max(5, min(100, $limit));
    $stmt = db()->query("SELECT * FROM crm_user_mail_accounts
        WHERE is_enabled = 1
          AND deleted_at IS NULL
          AND email_password_encrypted IS NOT NULL
          AND email_password_encrypted <> ''
          AND (sync_status IS NULL OR sync_status <> 'syncing' OR updated_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE))
          AND (sync_backoff_until IS NULL OR sync_backoff_until <= NOW())
          AND (last_sync_time IS NULL OR last_sync_time <= DATE_SUB(NOW(), INTERVAL {$intervalMinutes} MINUTE))
        ORDER BY COALESCE(last_sync_time, '1970-01-01') ASC, id ASC
        LIMIT 20");
    $accounts = $stmt->fetchAll();
    $results = [];
    foreach ($accounts as $account) {
        $account['mail_secret'] = crm_mail_decrypt($account['email_password_encrypted'] ?? '');
        unset($account['email_password_encrypted']);
        if ((string)$account['mail_secret'] === '') {
            $results[] = ['account_id' => (int)$account['id'], 'email_address' => (string)$account['email_address'], 'status' => 'skipped', 'message' => '邮箱授权码为空'];
            continue;
        }
        try {
            $needsBackfill = empty($account['last_sync_time']);
            $results[] = crm_mail_sync_account($account, $needsBackfill ? max($limit, 300) : $limit, 'cron', $needsBackfill ? 3 : 0);
        } catch (Throwable $e) {
            $results[] = ['account_id' => (int)$account['id'], 'user_id' => (int)$account['user_id'], 'email_address' => (string)$account['email_address'], 'status' => 'failed', 'message' => $e->getMessage()];
        }
    }
    return [
        'interval_minutes' => $intervalMinutes,
        'limit' => $limit,
        'checked_at' => date('Y-m-d H:i:s'),
        'due_count' => count($accounts),
        'results' => $results,
    ];
}

function crm_mail_sync_progress(string $syncId): array
{
    crm_require('mail.sync');
    $account = crm_mail_current_account(false);
    if (!$account) throw new RuntimeException('请先绑定邮箱。');
    $stmt = db()->prepare('SELECT * FROM crm_mail_sync_logs WHERE sync_id = ? AND user_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$syncId, (int)$account['user_id']]);
    $row = $stmt->fetch();
    if (!$row) throw new RuntimeException('同步任务不存在。');
    return $row;
}

function crm_mail_recipient_search(array $input): array
{
    crm_require('mail.send');
    $q = trim((string)($input['q'] ?? ''));
    if ($q === '' || mb_strlen($q, 'UTF-8') < 2) return ['rows' => []];
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
    $domain = strtolower($q);
    if (strpos($domain, '@') !== false) $domain = substr($domain, strpos($domain, '@') + 1);
    $domain = preg_replace('/^https?:\/\//i', '', $domain);
    $domain = preg_replace('/^www\./i', '', $domain);
    $domain = preg_replace('/[\/\?#].*$/', '', $domain);
    $domainLike = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $domain) . '%';
    $params = array_fill(0, 18, $like);
    $sql = "
        SELECT * FROM (
            SELECT
                c.id AS customer_id,
                NULL AS contact_id,
                c.customer_code,
                c.customer_name,
                c.customer_name_en,
                c.email AS email,
                '' AS contact_name,
                '' AS contact_name_en,
                '' AS contact_position,
                '' AS contact_department,
                '' AS whatsapp,
                c.country,
                c.website,
                u.username AS owner_name,
                c.do_not_contact,
                0 AS is_primary,
                0 AS is_left,
                0 AS unsubscribe_email,
                0 AS invalid_email,
                1 AS source_rank
            FROM crm_customers c
            LEFT JOIN crm_users u ON u.id = c.owner_user_id
            WHERE c.deleted_at IS NULL
              AND c.email IS NOT NULL AND c.email <> ''
              AND (
                c.email COLLATE utf8mb4_unicode_ci LIKE ? OR
                c.customer_name COLLATE utf8mb4_unicode_ci LIKE ? OR
                c.customer_name_en COLLATE utf8mb4_unicode_ci LIKE ? OR
                c.customer_code COLLATE utf8mb4_unicode_ci LIKE ? OR
                c.country COLLATE utf8mb4_unicode_ci LIKE ? OR
                c.website COLLATE utf8mb4_unicode_ci LIKE ? OR
                c.whatsapp COLLATE utf8mb4_unicode_ci LIKE ?
              )
            UNION ALL
            SELECT
                c.id AS customer_id,
                ct.id AS contact_id,
                c.customer_code,
                c.customer_name,
                c.customer_name_en,
                ct.email AS email,
                ct.name AS contact_name,
                ct.name_en AS contact_name_en,
                ct.position AS contact_position,
                ct.department AS contact_department,
                ct.whatsapp AS whatsapp,
                c.country,
                c.website,
                u.username AS owner_name,
                GREATEST(COALESCE(c.do_not_contact, 0), COALESCE(ct.do_not_contact, 0)) AS do_not_contact,
                COALESCE(ct.is_primary, 0) AS is_primary,
                COALESCE(ct.is_left, 0) AS is_left,
                COALESCE(ct.unsubscribe_email, 0) AS unsubscribe_email,
                0 AS invalid_email,
                2 AS source_rank
            FROM crm_contacts ct
            JOIN crm_customers c ON c.id = ct.customer_id
            LEFT JOIN crm_users u ON u.id = c.owner_user_id
            WHERE c.deleted_at IS NULL
              AND ct.deleted_at IS NULL
              AND ct.email IS NOT NULL AND ct.email <> ''
              AND (
                ct.email COLLATE utf8mb4_unicode_ci LIKE ? OR
                ct.name COLLATE utf8mb4_unicode_ci LIKE ? OR
                ct.name_en COLLATE utf8mb4_unicode_ci LIKE ? OR
                ct.position COLLATE utf8mb4_unicode_ci LIKE ? OR
                ct.department COLLATE utf8mb4_unicode_ci LIKE ? OR
                ct.whatsapp COLLATE utf8mb4_unicode_ci LIKE ? OR
                c.customer_name COLLATE utf8mb4_unicode_ci LIKE ? OR
                c.customer_name_en COLLATE utf8mb4_unicode_ci LIKE ? OR
                c.customer_code COLLATE utf8mb4_unicode_ci LIKE ? OR
                c.country COLLATE utf8mb4_unicode_ci LIKE ? OR
                c.website COLLATE utf8mb4_unicode_ci LIKE ?
              )
        ) x
        ORDER BY
          CASE
            WHEN LOWER(x.email) = LOWER(?) THEN 0
            WHEN LOWER(SUBSTRING_INDEX(x.email, '@', -1)) = LOWER(?) THEN 1
            WHEN LOWER(SUBSTRING_INDEX(x.email, '@', -1)) LIKE LOWER(?) THEN 2
            WHEN x.customer_name COLLATE utf8mb4_unicode_ci LIKE ? OR x.customer_name_en COLLATE utf8mb4_unicode_ci LIKE ? OR x.customer_code COLLATE utf8mb4_unicode_ci LIKE ? THEN 3
            WHEN x.contact_name COLLATE utf8mb4_unicode_ci LIKE ? OR x.contact_name_en COLLATE utf8mb4_unicode_ci LIKE ? THEN 4
            WHEN x.country COLLATE utf8mb4_unicode_ci LIKE ? THEN 5
            ELSE 6
          END,
          x.customer_name,
          x.is_primary DESC,
          x.source_rank
        LIMIT 50";
    $stmt = db()->prepare($sql);
    $stmt->execute(array_merge($params, [strtolower($q), $domain, $domainLike, $like, $like, $like, $like, $like, $like]));
    $seen = [];
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $email = trim((string)$row['email']);
        $invalid = filter_var($email, FILTER_VALIDATE_EMAIL) ? 0 : 1;
        $key = strtolower($email);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $statuses = [];
        if ((int)($row['is_primary'] ?? 0) === 1) $statuses[] = '主联系人';
        if ((int)($row['do_not_contact'] ?? 0) === 1 || (int)($row['unsubscribe_email'] ?? 0) === 1) $statuses[] = '不推广';
        if ((int)($row['do_not_contact'] ?? 0) === 1) $statuses[] = '黑名单';
        if ((int)($row['is_left'] ?? 0) === 1) $statuses[] = '离职';
        if ($invalid) $statuses[] = '无效邮箱';
        if (!$statuses) $statuses[] = '正常';
        $rows[] = [
            'customer_id' => (int)$row['customer_id'],
            'contact_id' => (int)($row['contact_id'] ?? 0),
            'customer_code' => (string)($row['customer_code'] ?? ''),
            'customer_name' => (string)($row['customer_name'] ?: $row['customer_name_en'] ?: ''),
            'customer_name_en' => (string)($row['customer_name_en'] ?? ''),
            'contact_name' => (string)($row['contact_name'] ?? ''),
            'contact_name_en' => (string)($row['contact_name_en'] ?? ''),
            'contact_position' => (string)($row['contact_position'] ?? ''),
            'contact_role' => (string)($row['contact_position'] ?: $row['contact_department'] ?: ''),
            'email' => $email,
            'email_domain' => strpos($email, '@') !== false ? substr($email, strpos($email, '@') + 1) : '',
            'country' => (string)($row['country'] ?? ''),
            'owner_name' => (string)($row['owner_name'] ?? ''),
            'do_not_contact' => (int)($row['do_not_contact'] ?? 0),
            'unsubscribe_email' => (int)($row['unsubscribe_email'] ?? 0),
            'is_primary' => (int)($row['is_primary'] ?? 0),
            'is_left' => (int)($row['is_left'] ?? 0),
            'blacklisted' => (int)($row['do_not_contact'] ?? 0),
            'invalid_email' => $invalid,
            'can_promote' => ((int)($row['do_not_contact'] ?? 0) === 0 && (int)($row['unsubscribe_email'] ?? 0) === 0 && (int)($row['is_left'] ?? 0) === 0 && !$invalid) ? 1 : 0,
            'statuses' => array_values(array_unique($statuses)),
        ];
    }
    return ['rows' => $rows];
}

function crm_mail_execute_send_job(array $account, array $input, array $attachments, string $jobId, string $bodyOriginal = ''): array
{
    $to = trim((string)($input['to_emails'] ?? ''));
    $subject = trim((string)($input['subject'] ?? ''));
    $body = (string)($input['body_html'] ?? '');
    $visibleAttachmentCount = crm_mail_visible_attachment_count($attachments);
    if (empty($input['_message_id_header'])) $input['_message_id_header'] = crm_mail_generate_message_id($account);
    $sendResult = crm_mail_smtp_send($account, $input, $attachments);
    $messageId = (string)($sendResult['message_id_header'] ?? $input['_message_id_header'] ?? '');
    $linkedCustomerId = (int)($input['customer_id'] ?? 0) ?: null;
    $replyTo = (int)($input['reply_to_mail_id'] ?? 0);
    if (!$linkedCustomerId && $replyTo > 0) {
        $linkedStmt = db()->prepare('SELECT linked_customer_id FROM crm_mails WHERE id = ? AND user_id = ? AND mail_account_id = ? LIMIT 1');
        $linkedStmt->execute([$replyTo, (int)$account['user_id'], (int)$account['id']]);
        $replyLinkedCustomerId = (int)$linkedStmt->fetchColumn();
        if ($replyLinkedCustomerId > 0) $linkedCustomerId = $replyLinkedCustomerId;
    }
    $bodyText = strip_tags($body);
    db()->prepare('INSERT INTO crm_mails (user_id, mail_account_id, message_uid, message_id_header, folder, subject, from_email, from_name, to_emails, cc_emails, bcc_emails, sent_at, body_html, body_text, body_status, has_body, has_attachment, attachment_count, is_read, linked_customer_id, mail_source, source_flags_json, crm_send_id, body_hash, send_status, tags_json, raw_headers_json, created_at, updated_at) VALUES (?, ?, ?, ?, "sent", ?, ?, ?, ?, ?, ?, NOW(), ?, ?, "normal", 1, ?, ?, 1, ?, "crm_sent", ?, ?, ?, "sent", ?, ?, NOW(), NOW())')
        ->execute([(int)$account['user_id'], (int)$account['id'], $jobId, $messageId, $subject, $account['email_address'], $account['sender_name'] ?: $account['email_address'], $to, (string)($input['cc_emails'] ?? ''), (string)($input['bcc_emails'] ?? ''), $body, $bodyText, $visibleAttachmentCount > 0 ? 1 : 0, $visibleAttachmentCount, $linkedCustomerId, json_encode(['crm_sent'], JSON_UNESCAPED_UNICODE), $jobId, crm_mail_body_hash($body, $bodyText), json_encode(['已发送', 'CRM发送'], JSON_UNESCAPED_UNICODE), json_encode(['smtp_response' => $sendResult['response'], 'message_id_header' => $messageId], JSON_UNESCAPED_UNICODE)]);
    $sentMailId = (int)db()->lastInsertId();
    $storeResult = ['stored' => 0, 'visible' => 0, 'inline' => 0, 'failed' => 0];
    $actualVisibleCount = 0;
    if ($sentMailId > 0) {
        $storeResult = crm_mail_store_attachment_files((int)$account['user_id'], $sentMailId, $attachments);
        $countStmt = db()->prepare('SELECT COUNT(*) FROM crm_mail_attachments WHERE mail_id = ? AND user_id = ? AND COALESCE(is_inline,0) = 0 AND COALESCE(is_signature_image,0) = 0');
        $countStmt->execute([$sentMailId, (int)$account['user_id']]);
        $actualVisibleCount = (int)$countStmt->fetchColumn();
        $storedBody = $body;
        if ((int)($storeResult['inline'] ?? 0) > 0) {
            $storedBody = crm_mail_update_inline_body_urls((int)$account['user_id'], $sentMailId, $body);
        }
        if ($bodyOriginal !== '' && (int)($storeResult['inline'] ?? 0) <= 0 && stripos($bodyOriginal, 'data:image/') !== false) {
            db()->prepare('UPDATE crm_mails SET body_html = ?, body_text = ?, has_attachment = ?, attachment_count = ?, updated_at = NOW() WHERE id = ? AND user_id = ?')
                ->execute([$bodyOriginal, strip_tags($bodyOriginal), $actualVisibleCount > 0 ? 1 : 0, $actualVisibleCount, $sentMailId, (int)$account['user_id']]);
        } else {
            db()->prepare('UPDATE crm_mails SET body_html = ?, body_text = ?, has_attachment = ?, attachment_count = ?, updated_at = NOW() WHERE id = ? AND user_id = ?')
                ->execute([$storedBody, strip_tags($storedBody), $actualVisibleCount > 0 ? 1 : 0, $actualVisibleCount, $sentMailId, (int)$account['user_id']]);
        }
        if ($visibleAttachmentCount > 0 && $actualVisibleCount <= 0) {
            error_log('crm_mail_execute_send_job: visible attachments were not persisted for sent mail #' . $sentMailId . ', expected=' . $visibleAttachmentCount . ', store=' . json_encode($storeResult));
        }
        $sentMail = [
            'id' => $sentMailId,
            'linked_customer_id' => $linkedCustomerId,
            'subject' => $subject,
        ];
        if ($linkedCustomerId) crm_mail_add_customer_timeline_for_mail($sentMail, 'mail_sent', '发送邮件给客户', '收件人：' . $to);
        if ($replyTo > 0) {
            db()->prepare('UPDATE crm_mails SET is_replied = 1, followup_status = "replied", updated_at = NOW() WHERE id = ? AND user_id = ? AND mail_account_id = ?')
                ->execute([$replyTo, (int)$account['user_id'], (int)$account['id']]);
            $mode = (string)($input['mode'] ?? 'reply');
            if ($linkedCustomerId) {
                crm_customer_timeline_add(
                    (int)$linkedCustomerId,
                    $mode === 'reply_all' ? 'mail_reply_all' : 'mail_reply',
                    $mode === 'reply_all' ? '回复全部客户邮件' : '回复客户邮件',
                    '主题：' . ($subject ?: '-') . ' · 收件人：' . $to,
                    'mail',
                    (string)$sentMailId
                );
            }
        }
        $draftId = (int)($input['draft_id'] ?? 0);
        if ($draftId > 0) {
            db()->prepare('DELETE FROM crm_mail_drafts WHERE id = ? AND user_id = ? AND mail_account_id = ?')->execute([$draftId, (int)$account['user_id'], (int)$account['id']]);
        }
    }
    return ['sent_mail_id' => $sentMailId, 'smtp_response' => $sendResult['response'], 'store_result' => $storeResult, 'visible_attachment_count' => $visibleAttachmentCount, 'actual_visible_count' => $actualVisibleCount];
}

function crm_mail_send_start(array $input, array $files = []): array
{
    $mode = (string)($input['mode'] ?? 'compose');
    crm_require(in_array($mode, ['reply', 'reply_all'], true) ? 'mail.view' : 'mail.send');
    $account = crm_mail_current_account(true);
    if (!$account) throw new RuntimeException('请先绑定邮箱。');
    $to = trim((string)($input['to_emails'] ?? ''));
    $subject = trim((string)($input['subject'] ?? ''));
    $body = trim(crm_mail_render_signature_variables((string)($input['body_html'] ?? ''), $account));
    $bodyOriginal = $body;
    if ($to === '') throw new RuntimeException('收件人不能为空。');
    if ($subject === '') throw new RuntimeException('主题不能为空。');
    if ($body === '') throw new RuntimeException('正文不能为空。');
    $replyToMailId = (int)($input['reply_to_mail_id'] ?? 0);
    if ($replyToMailId > 0) {
        $replyStmt = db()->prepare('SELECT message_id_header, raw_headers FROM crm_mails WHERE id = ? AND user_id = ? AND mail_account_id = ? LIMIT 1');
        $replyStmt->execute([$replyToMailId, (int)$account['user_id'], (int)$account['id']]);
        $replyMail = $replyStmt->fetch() ?: [];
        $originalMessageId = crm_mail_normalize_message_id((string)($replyMail['message_id_header'] ?? ''));
        if ($originalMessageId !== '') {
            $input['_in_reply_to_header'] = $originalMessageId;
            $rawHeaders = (string)($replyMail['raw_headers'] ?? '');
            $oldReferences = trim(crm_mail_extract_header($rawHeaders, 'References'));
            $oldReferences = preg_replace('/\s+/', ' ', $oldReferences ?: '');
            $references = trim(($oldReferences !== '' ? $oldReferences . ' ' : '') . $originalMessageId);
            if (mb_strlen($references, 'UTF-8') > 1900) {
                $parts = preg_split('/\s+/', $references, -1, PREG_SPLIT_NO_EMPTY);
                $tail = [];
                while ($parts && mb_strlen(implode(' ', $tail), 'UTF-8') < 1600) array_unshift($tail, array_pop($parts));
                $references = implode(' ', $tail);
            }
            $input['_references_header'] = $references;
        }
    }
    $embeddedInlineResult = crm_mail_extract_embedded_attachment_images($body, $account);
    $body = (string)$embeddedInlineResult['html'];
    $inlineResult = crm_mail_extract_inline_data_attachments($body);
    $body = (string)$inlineResult['html'];
    $attachments = array_merge(crm_mail_uploaded_files($files), crm_mail_draft_attachment_files($account, $input), crm_mail_original_attachments($account, $input), crm_mail_datasheet_attachments($input), $embeddedInlineResult['attachments'], $inlineResult['attachments']);
    $jobId = 'send_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
    $sendInput = $input;
    $sendInput['body_html'] = $body;
    $delayMinutes = max(0, min(10, (int)($account['delay_send_minutes'] ?? 0)));
    if ($delayMinutes > 0) {
        $queuedAttachments = crm_mail_queue_attachment_files((int)$account['user_id'], $jobId, $attachments);
        $scheduledAt = date('Y-m-d H:i:s', time() + $delayMinutes * 60);
        db()->prepare('INSERT INTO crm_mail_send_jobs (user_id, mail_account_id, job_id, to_emails, subject, status, stage, percent, scheduled_at, payload_json, attachments_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, "scheduled", "waiting", 5, ?, ?, ?, NOW(), NOW())')
            ->execute([(int)$account['user_id'], (int)$account['id'], $jobId, $to, $subject, $scheduledAt, json_encode(['input' => $sendInput, 'body_original' => $bodyOriginal], JSON_UNESCAPED_UNICODE), json_encode($queuedAttachments, JSON_UNESCAPED_UNICODE)]);
        crm_log_event('mail', 'send_scheduled', 'mail', $jobId, null, ['to' => $to, 'subject' => $subject, 'scheduled_at' => $scheduledAt, 'delay_minutes' => $delayMinutes, 'attachments' => count($queuedAttachments)]);
        return ['job_id' => $jobId, 'status' => 'scheduled', 'stage' => 'waiting', 'percent' => 5, 'scheduled_at' => $scheduledAt, 'message' => '邮件已加入延迟发送队列，将在 ' . $scheduledAt . ' 发送。'];
    }
    db()->prepare('INSERT INTO crm_mail_send_jobs (user_id, mail_account_id, job_id, to_emails, subject, status, stage, percent, created_at, updated_at) VALUES (?, ?, ?, ?, ?, "running", "check", 20, NOW(), NOW())')
        ->execute([(int)$account['user_id'], (int)$account['id'], $jobId, $to, $subject]);
    try {
        db()->prepare('UPDATE crm_mail_send_jobs SET stage = "smtp", percent = 55, updated_at = NOW() WHERE job_id = ? AND user_id = ?')->execute([$jobId, (int)$account['user_id']]);
        $result = crm_mail_execute_send_job($account, $sendInput, $attachments, $jobId, $bodyOriginal);
        db()->prepare('UPDATE crm_mail_send_jobs SET status = "success", stage = "done", percent = 100, sent_mail_id = ?, finished_at = NOW(), updated_at = NOW() WHERE job_id = ? AND user_id = ?')->execute([(int)$result['sent_mail_id'], $jobId, (int)$account['user_id']]);
        crm_mail_cleanup_queue_files($attachments);
        crm_log_event('mail', 'send_success', 'mail', $jobId, null, ['to' => $to, 'subject' => $subject, 'attachments' => count($attachments), 'stored_attachments' => $result['store_result'], 'response' => $result['smtp_response']]);
        $message = '邮件已通过 SMTP 成功发送。';
        if ((int)$result['visible_attachment_count'] > 0 && (int)($result['store_result']['visible'] ?? 0) <= 0) $message = '邮件已发送，但发件箱附件保存异常，请不要关闭页面并联系管理员检查。';
        return ['job_id' => $jobId, 'status' => 'success', 'stage' => 'done', 'percent' => 100, 'message' => $message];
    } catch (Throwable $e) {
        crm_mail_cleanup_generated_attachments($attachments);
        db()->prepare('UPDATE crm_mail_send_jobs SET status = "failed", stage = "failed", percent = 100, error_message = ?, updated_at = NOW() WHERE job_id = ? AND user_id = ?')->execute([$e->getMessage(), $jobId, (int)$account['user_id']]);
        crm_log_event('mail', 'send_failed', 'mail', $jobId, null, ['to' => $to, 'subject' => $subject, 'error' => $e->getMessage()], false, $e->getMessage());
        throw new RuntimeException('发送失败：' . $e->getMessage());
    }
}

function crm_mail_send_progress(string $jobId): array
{
    crm_require('mail.view');
    $account = crm_mail_current_account(false);
    if (!$account) throw new RuntimeException('请先绑定邮箱。');
    $stmt = db()->prepare('SELECT * FROM crm_mail_send_jobs WHERE job_id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$jobId, (int)$account['user_id']]);
    $row = $stmt->fetch();
    if (!$row) throw new RuntimeException('发送任务不存在。');
    if (($row['status'] ?? '') === 'scheduled' && !empty($row['scheduled_at']) && strtotime((string)$row['scheduled_at']) <= time()) {
        crm_mail_send_due_jobs(5);
        $stmt->execute([$jobId, (int)$account['user_id']]);
        $row = $stmt->fetch();
        if (!$row) throw new RuntimeException('发送任务不存在。');
    }
    if (($row['status'] ?? '') === 'scheduled' && !empty($row['scheduled_at'])) {
        $remaining = max(0, strtotime((string)$row['scheduled_at']) - time());
        $row['remaining_seconds'] = $remaining;
        $row['message'] = '等待发送，剩余约 ' . ceil($remaining / 60) . ' 分钟';
    }
    return $row;
}

function crm_mail_send_due_jobs(int $limit = 20): array
{
    crm_mail_ensure_tables();
    $limit = max(1, min(100, $limit));
    $stmt = db()->query("SELECT * FROM crm_mail_send_jobs WHERE status = 'scheduled' AND scheduled_at <= NOW() ORDER BY scheduled_at ASC, id ASC LIMIT {$limit}");
    $jobs = $stmt->fetchAll();
    $sent = 0;
    $failed = 0;
    $rows = [];
    foreach ($jobs as $job) {
        $lock = db()->prepare("UPDATE crm_mail_send_jobs SET status = 'running', stage = 'smtp', percent = 55, updated_at = NOW() WHERE id = ? AND status = 'scheduled'");
        $lock->execute([(int)$job['id']]);
        if ($lock->rowCount() <= 0) continue;
        try {
            $accountStmt = db()->prepare('SELECT * FROM crm_user_mail_accounts WHERE id = ? AND user_id = ? AND deleted_at IS NULL LIMIT 1');
            $accountStmt->execute([(int)$job['mail_account_id'], (int)$job['user_id']]);
            $account = $accountStmt->fetch();
            if (!$account) throw new RuntimeException('邮箱账号不存在或已删除。');
            $account['mail_secret'] = crm_mail_decrypt($account['email_password_encrypted'] ?? '');
            unset($account['email_password_encrypted']);
            if ((string)$account['mail_secret'] === '') throw new RuntimeException('邮箱授权码为空。');
            $payload = json_decode((string)($job['payload_json'] ?? '{}'), true) ?: [];
            $input = is_array($payload['input'] ?? null) ? $payload['input'] : [];
            $bodyOriginal = (string)($payload['body_original'] ?? '');
            $attachments = json_decode((string)($job['attachments_json'] ?? '[]'), true) ?: [];
            $result = crm_mail_execute_send_job($account, $input, $attachments, (string)$job['job_id'], $bodyOriginal);
            db()->prepare("UPDATE crm_mail_send_jobs SET status = 'success', stage = 'done', percent = 100, sent_mail_id = ?, error_message = NULL, finished_at = NOW(), updated_at = NOW() WHERE id = ?")
                ->execute([(int)$result['sent_mail_id'], (int)$job['id']]);
            crm_mail_cleanup_queue_files($attachments);
            crm_log_event('mail', 'send_scheduled_success', 'mail', (string)$job['job_id'], null, ['sent_mail_id' => (int)$result['sent_mail_id']]);
            $sent++;
            $rows[] = ['job_id' => (string)$job['job_id'], 'status' => 'success'];
        } catch (Throwable $e) {
            db()->prepare("UPDATE crm_mail_send_jobs SET status = 'failed', stage = 'failed', percent = 100, error_message = ?, finished_at = NOW(), updated_at = NOW() WHERE id = ?")
                ->execute([$e->getMessage(), (int)$job['id']]);
            crm_log_event('mail', 'send_scheduled_failed', 'mail', (string)$job['job_id'], null, ['error' => $e->getMessage()], false, $e->getMessage());
            $failed++;
            $rows[] = ['job_id' => (string)$job['job_id'], 'status' => 'failed', 'message' => $e->getMessage()];
        }
    }
    return ['checked_at' => date('Y-m-d H:i:s'), 'due_count' => count($jobs), 'sent' => $sent, 'failed' => $failed, 'rows' => $rows];
}

function crm_mail_send_cancel(string $jobId): array
{
    crm_require('mail.send');
    $jobId = trim($jobId);
    if ($jobId === '') throw new RuntimeException('发送任务不存在。');
    $account = crm_mail_current_account(false);
    if (!$account) throw new RuntimeException('请先绑定邮箱。');
    $stmt = db()->prepare('SELECT * FROM crm_mail_send_jobs WHERE job_id = ? AND user_id = ? AND mail_account_id = ? LIMIT 1');
    $stmt->execute([$jobId, (int)$account['user_id'], (int)$account['id']]);
    $job = $stmt->fetch();
    if (!$job) throw new RuntimeException('发送任务不存在或无权操作。');
    $status = (string)($job['status'] ?? '');
    if ($status !== 'scheduled') {
        if ($status === 'running') throw new RuntimeException('邮件正在发送，不能取消。');
        if ($status === 'success') throw new RuntimeException('邮件已经发出，不能从待发送撤回。');
        if ($status === 'cancelled') throw new RuntimeException('该邮件已取消发送。');
        throw new RuntimeException('当前状态不能取消发送。');
    }
    $payload = json_decode((string)($job['payload_json'] ?? '{}'), true) ?: [];
    $input = is_array($payload['input'] ?? null) ? $payload['input'] : [];
    $attachments = json_decode((string)($job['attachments_json'] ?? '[]'), true) ?: [];
    $body = (string)($payload['body_original'] ?? '');
    if ($body === '') $body = (string)($input['body_html'] ?? '');
    $draftId = 0;
    db()->beginTransaction();
    try {
        $lock = db()->prepare("UPDATE crm_mail_send_jobs SET status = 'cancelled', stage = 'cancelled', percent = 100, error_message = NULL, finished_at = NOW(), updated_at = NOW() WHERE id = ? AND status = 'scheduled'");
        $lock->execute([(int)$job['id']]);
        if ($lock->rowCount() <= 0) throw new RuntimeException('发送任务状态已变化，请刷新后查看。');
        db()->prepare('INSERT INTO crm_mail_drafts (user_id, mail_account_id, mode, to_emails, cc_emails, bcc_emails, subject, body_html, attachments_json, linked_customer_id, linked_contact_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())')
            ->execute([
                (int)$account['user_id'],
                (int)$account['id'],
                (string)($input['mode'] ?? 'compose'),
                (string)($input['to_emails'] ?? ($job['to_emails'] ?? '')),
                (string)($input['cc_emails'] ?? ''),
                (string)($input['bcc_emails'] ?? ''),
                (string)($input['subject'] ?? ($job['subject'] ?? '')),
                $body,
                json_encode($attachments, JSON_UNESCAPED_UNICODE),
                (int)($input['customer_id'] ?? 0) ?: null,
                (int)($input['contact_id'] ?? 0) ?: null,
            ]);
        $draftId = (int)db()->lastInsertId();
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }
    crm_log_event('mail', 'send_cancelled_to_draft', 'mail', $jobId, ['status' => $status], ['status' => 'cancelled', 'draft_id' => $draftId]);
    return ['job_id' => $jobId, 'draft_id' => $draftId, 'status' => 'cancelled', 'message' => '已取消发送，邮件已回到草稿箱。'];
}

function crm_mail_recall_sent_mail(int $mailId): array
{
    crm_require('mail.send');
    $account = crm_mail_current_account(true);
    if (!$account) throw new RuntimeException('请先绑定邮箱。');
    if ($mailId <= 0) throw new RuntimeException('邮件不存在。');
    $stmt = db()->prepare('SELECT * FROM crm_mails WHERE id = ? AND user_id = ? AND mail_account_id = ? AND folder = "sent" LIMIT 1');
    $stmt->execute([$mailId, (int)$account['user_id'], (int)$account['id']]);
    $mail = $stmt->fetch();
    if (!$mail) throw new RuntimeException('只能撤回当前邮箱发件箱里的邮件。');
    $tags = json_decode((string)($mail['tags_json'] ?? '[]'), true);
    if (!is_array($tags)) $tags = [];
    if (in_array('已撤回', $tags, true)) throw new RuntimeException('该邮件已标记撤回，请勿重复操作。');

    $recipients = array_values(array_unique(array_merge(
        crm_mail_parse_addresses((string)($mail['to_emails'] ?? '')),
        crm_mail_parse_addresses((string)($mail['cc_emails'] ?? '')),
        crm_mail_parse_addresses((string)($mail['bcc_emails'] ?? ''))
    )));
    if (!$recipients) throw new RuntimeException('原邮件没有可用收件人，无法发送撤回通知。');

    $subject = (string)($mail['subject'] ?: '(无主题)');
    $sentAt = (string)($mail['sent_at'] ?: $mail['created_at'] ?: '');
    $body = '<p>您好，</p>' .
        '<p>发件人请求撤回此前发送的一封邮件。请以此撤回通知为准，不再处理原邮件内容。</p>' .
        '<p><strong>原邮件主题：</strong>' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '<br>' .
        '<strong>原发送时间：</strong>' . htmlspecialchars($sentAt ?: '-', ENT_QUOTES, 'UTF-8') . '</p>' .
        '<p>说明：由于标准 SMTP/IMAP 协议不能强制删除对方邮箱中的邮件，本通知用于告知收件人该邮件已由发件人撤回。</p>';
    $input = [
        'to_emails' => implode(', ', crm_mail_parse_addresses((string)($mail['to_emails'] ?? ''))),
        'cc_emails' => implode(', ', crm_mail_parse_addresses((string)($mail['cc_emails'] ?? ''))),
        'bcc_emails' => implode(', ', crm_mail_parse_addresses((string)($mail['bcc_emails'] ?? ''))),
        'subject' => '撤回邮件：' . $subject,
        'body_html' => $body,
    ];
    crm_mail_smtp_send($account, $input, []);

    $tags[] = '已撤回';
    $tags = array_values(array_unique(array_filter(array_map('strval', $tags))));
    db()->prepare('UPDATE crm_mails SET tags_json = ?, updated_at = NOW() WHERE id = ? AND user_id = ? AND mail_account_id = ?')
        ->execute([json_encode($tags, JSON_UNESCAPED_UNICODE), $mailId, (int)$account['user_id'], (int)$account['id']]);
    crm_log_event('mail', 'mail_recall', 'mail', (string)$mailId, ['tags' => json_decode((string)($mail['tags_json'] ?? '[]'), true) ?: []], ['tags' => $tags, 'recipients' => $recipients]);
    return ['mail_id' => $mailId, 'status' => 'recalled', 'recipients' => $recipients, 'message' => '已发送撤回通知，并将原邮件标记为已撤回。'];
}

function crm_mail_save_draft(array $input, array $files = []): array
{
    $mode = (string)($input['mode'] ?? 'compose');
    crm_require(in_array($mode, ['reply', 'reply_all'], true) ? 'mail.view' : 'mail.send');
    $account = crm_mail_current_account(false);
    if (!$account) throw new RuntimeException('请先绑定邮箱。');
    $body = crm_mail_render_signature_variables((string)($input['body_html'] ?? ''), $account);
    $draftId = (int)($input['draft_id'] ?? 0);
    $autoSaved = !empty($input['auto_saved']) ? 1 : 0;
    $draftMetaRaw = (string)($input['draft_meta_json'] ?? '');
    $draftMeta = $draftMetaRaw !== '' ? json_decode($draftMetaRaw, true) : [];
    if (!is_array($draftMeta)) $draftMeta = [];
    if (isset($input['datasheet_attachment_refs'])) {
        $decodedRefs = is_string($input['datasheet_attachment_refs']) ? json_decode((string)$input['datasheet_attachment_refs'], true) : $input['datasheet_attachment_refs'];
        $draftMeta['datasheet_attachments'] = is_array($decodedRefs) ? $decodedRefs : [];
    }
    $draftMetaJson = json_encode($draftMeta, JSON_UNESCAPED_UNICODE);
    $attachmentsJson = (string)($input['attachments_json'] ?? '[]');
    $payload = [
        (string)($input['mode'] ?? 'compose'),
        (string)($input['to_emails'] ?? ''),
        (string)($input['cc_emails'] ?? ''),
        (string)($input['bcc_emails'] ?? ''),
        (string)($input['subject'] ?? ''),
        $body,
        $attachmentsJson,
        $draftMetaJson,
        (int)($input['customer_id'] ?? 0) ?: null,
        (int)($input['contact_id'] ?? 0) ?: null,
        $autoSaved,
    ];
    if ($draftId > 0) {
        $stmt = db()->prepare('UPDATE crm_mail_drafts SET mode=?, to_emails=?, cc_emails=?, bcc_emails=?, subject=?, body_html=?, attachments_json=?, draft_meta_json=?, linked_customer_id=?, linked_contact_id=?, auto_saved=?, updated_at=NOW() WHERE id=? AND user_id=? AND mail_account_id=?');
        $stmt->execute(array_merge($payload, [$draftId, (int)$account['user_id'], (int)$account['id']]));
        if ($stmt->rowCount() <= 0) {
            $exists = db()->prepare('SELECT COUNT(*) FROM crm_mail_drafts WHERE id=? AND user_id=? AND mail_account_id=?');
            $exists->execute([$draftId, (int)$account['user_id'], (int)$account['id']]);
            if ((int)$exists->fetchColumn() <= 0) $draftId = 0;
        }
    }
    if ($draftId <= 0) {
        db()->prepare('INSERT INTO crm_mail_drafts (user_id, mail_account_id, reply_to_mail_id, mode, to_emails, cc_emails, bcc_emails, subject, body_html, attachments_json, draft_meta_json, linked_customer_id, linked_contact_id, auto_saved, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())')
            ->execute([(int)$account['user_id'], (int)$account['id'], (int)($input['reply_to_mail_id'] ?? 0) ?: null, $payload[0], $payload[1], $payload[2], $payload[3], $payload[4], $payload[5], $payload[6], $payload[7], $payload[8], $payload[9], $payload[10]]);
        $draftId = (int)db()->lastInsertId();
    }
    $uploaded = crm_mail_uploaded_files($files);
    if ($uploaded) {
        $existing = json_decode($attachmentsJson, true);
        if (!is_array($existing)) $existing = [];
        $queued = crm_mail_queue_attachment_files((int)$account['user_id'], 'draft_' . $draftId, $uploaded);
        $merged = array_merge($existing, $queued);
        $attachmentsJson = json_encode($merged, JSON_UNESCAPED_UNICODE);
        db()->prepare('UPDATE crm_mail_drafts SET attachments_json=?, updated_at=NOW() WHERE id=? AND user_id=? AND mail_account_id=?')
            ->execute([$attachmentsJson, $draftId, (int)$account['user_id'], (int)$account['id']]);
    }
    crm_log_event('mail', $autoSaved ? 'draft_auto_save' : 'draft_save', 'mail_draft', (string)$draftId);
    return ['draft_id' => $draftId, 'auto_saved' => $autoSaved, 'attachments_json' => $attachmentsJson];
}

function crm_mail_draft_get(int $draftId): array
{
    crm_require('mail.view');
    $account = crm_mail_current_account(false);
    if (!$account) throw new RuntimeException('请先绑定邮箱。');
    if ($draftId <= 0) throw new RuntimeException('草稿不存在。');
    $stmt = db()->prepare('SELECT * FROM crm_mail_drafts WHERE id = ? AND user_id = ? AND mail_account_id = ? LIMIT 1');
    $stmt->execute([$draftId, (int)$account['user_id'], (int)$account['id']]);
    $draft = $stmt->fetch();
    if (!$draft) throw new RuntimeException('草稿不存在或无权查看。');
    $draft['id'] = (int)$draft['id'];
    $draft['mail_account_id'] = (int)$draft['mail_account_id'];
    $draft['reply_to_mail_id'] = (int)($draft['reply_to_mail_id'] ?? 0);
    $draft['linked_customer_id'] = (int)($draft['linked_customer_id'] ?? 0);
    $draft['linked_contact_id'] = (int)($draft['linked_contact_id'] ?? 0);
    return ['draft' => $draft];
}

function crm_mail_draft_delete(array $input): array
{
    crm_require('mail.delete');
    $account = crm_mail_current_account(false);
    if (!$account) throw new RuntimeException('请先绑定邮箱。');
    $ids = [];
    $rawIds = $input['draft_ids'] ?? null;
    if (is_string($rawIds) && $rawIds !== '') {
        $decoded = json_decode($rawIds, true);
        if (is_array($decoded)) $ids = $decoded;
        else $ids = preg_split('/[,\s]+/', $rawIds) ?: [];
    } elseif (is_array($rawIds)) {
        $ids = $rawIds;
    }
    if (!$ids) $ids = [(int)($input['draft_id'] ?? 0)];
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0)));
    if (!$ids) throw new RuntimeException('请选择草稿。');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge($ids, [(int)$account['user_id'], (int)$account['id']]);
    $stmt = db()->prepare("SELECT id, subject, to_emails, cc_emails, bcc_emails, updated_at FROM crm_mail_drafts WHERE id IN ({$placeholders}) AND user_id = ? AND mail_account_id = ?");
    $stmt->execute($params);
    $beforeRows = $stmt->fetchAll();
    if (!$beforeRows) throw new RuntimeException('草稿不存在或无权删除。');
    $validIds = array_map(static fn($row) => (int)$row['id'], $beforeRows);
    $validPlaceholders = implode(',', array_fill(0, count($validIds), '?'));
    $deleteParams = array_merge($validIds, [(int)$account['user_id'], (int)$account['id']]);
    db()->prepare("DELETE FROM crm_mail_drafts WHERE id IN ({$validPlaceholders}) AND user_id = ? AND mail_account_id = ?")->execute($deleteParams);
    foreach ($beforeRows as $row) {
        crm_log_event('mail', 'draft_delete', 'mail_draft', (string)(int)$row['id'], $row, null);
    }
    $count = count($validIds);
    return ['draft_id' => $validIds[0], 'draft_ids' => $validIds, 'count' => $count, 'message' => '草稿已删除' . ($count > 1 ? '：' . $count . ' 封' : '')];
}

function crm_mail_link_customer(int $mailId, int $customerId): array
{
    crm_require('mail.link_customer');
    $account = crm_mail_current_account(false);
    if (!$account) throw new RuntimeException('请先绑定邮箱。');
    crm_customer_get($customerId);
    db()->prepare('UPDATE crm_mails SET linked_customer_id = ?, updated_at = NOW() WHERE id = ? AND user_id = ? AND mail_account_id = ?')
        ->execute([$customerId, $mailId, (int)$account['user_id'], (int)$account['id']]);
    $mailStmt = db()->prepare('SELECT * FROM crm_mails WHERE id = ? AND user_id = ? AND mail_account_id = ? LIMIT 1');
    $mailStmt->execute([$mailId, (int)$account['user_id'], (int)$account['id']]);
    $mail = $mailStmt->fetch();
    if ($mail) {
        $mail['linked_customer_id'] = $customerId;
        crm_mail_add_customer_timeline_for_mail($mail, (string)$mail['folder'] === 'sent' ? 'mail_sent_linked' : 'mail_received', (string)$mail['folder'] === 'sent' ? '关联已发送邮件' : '收到客户邮件', '发件人：' . ((string)($mail['from_email'] ?? '-') ?: '-'));
    }
    crm_log_event('mail', 'link_customer', 'mail', (string)$mailId, null, ['customer_id' => $customerId]);
    return crm_mail_get($mailId);
}

function crm_mail_save_attachment_to_customer(array $input): array
{
    crm_require('mail.attachment_download');
    $account = crm_mail_current_account(false);
    if (!$account) throw new RuntimeException('请先绑定邮箱。');
    $attachmentId = (int)($input['attachment_id'] ?? 0);
    $mailId = (int)($input['mail_id'] ?? 0);
    $customerId = (int)($input['customer_id'] ?? 0);
    if ($attachmentId <= 0) throw new RuntimeException('请选择附件。');
    $stmt = db()->prepare('SELECT a.*, m.id AS mail_id, m.subject, m.linked_customer_id FROM crm_mail_attachments a JOIN crm_mails m ON m.id = a.mail_id AND m.user_id = a.user_id WHERE a.id = ? AND a.user_id = ? AND m.mail_account_id = ? LIMIT 1');
    $stmt->execute([$attachmentId, (int)$account['user_id'], (int)$account['id']]);
    $row = $stmt->fetch();
    if (!$row) throw new RuntimeException('附件不存在或无权操作。');
    if ($mailId <= 0) $mailId = (int)$row['mail_id'];
    if ($customerId <= 0) $customerId = (int)($row['linked_customer_id'] ?? 0);
    if ($customerId <= 0) throw new RuntimeException('请先关联客户，再保存附件。');
    crm_customer_get($customerId);
    $sourcePath = (string)($row['file_path'] ?? '');
    if ($sourcePath === '' || !is_file($sourcePath)) throw new RuntimeException('附件文件不存在，无法保存到客户。');
    $dir = __DIR__ . '/storage/customer_files/' . $customerId;
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (!is_dir($dir) || !is_writable($dir)) throw new RuntimeException('客户文件目录不可写。');
    $name = crm_mail_safe_file_name((string)($row['original_filename'] ?: $row['filename'] ?: $row['file_name'] ?: 'attachment'));
    $target = $dir . '/' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '_' . $name;
    if (!@copy($sourcePath, $target)) throw new RuntimeException('附件保存到客户失败。');
    db()->prepare('INSERT INTO crm_customer_files (customer_id, file_name, file_path, file_type, file_size, category, remark, uploaded_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())')
        ->execute([$customerId, $name, $target, (string)($row['mime_type'] ?? ''), (int)(filesize($target) ?: ($row['file_size'] ?? 0)), trim((string)($input['category'] ?? 'mail_attachment')) ?: 'mail_attachment', '来源邮件 #' . $mailId . '：' . (string)($row['subject'] ?? ''), (int)(current_user()['id'] ?? 0)]);
    $fileId = (int)db()->lastInsertId();
    crm_customer_timeline_add($customerId, 'mail_attachment_save', '保存邮件附件到客户', $name . ' · 邮件：' . (string)($row['subject'] ?? ''), 'customer_file', (string)$fileId);
    crm_log_event('mail', 'mail_save_attachment_to_customer', 'mail_attachment', (string)$attachmentId, null, ['customer_id' => $customerId, 'file_id' => $fileId]);
    return ['file_id' => $fileId, 'customer_id' => $customerId, 'mail_id' => $mailId, 'message' => '附件已保存到客户文件'];
}

function crm_mail_followup_action(array $input): array
{
    crm_require('mail.view');
    $account = crm_mail_current_account(false);
    if (!$account) throw new RuntimeException('请先绑定邮箱。');
    $mailId = (int)($input['mail_id'] ?? 0);
    if ($mailId <= 0) throw new RuntimeException('请选择邮件。');
    $status = trim((string)($input['followup_status'] ?? ''));
    if (!in_array($status, ['open','replied','no_follow','done'], true)) throw new RuntimeException('未回复状态无效。');
    $next = trim((string)($input['next_followup_at'] ?? '')) ?: null;
    db()->prepare('UPDATE crm_mails SET followup_status = ?, next_followup_at = ?, is_unreplied = IF(? IN ("replied","no_follow","done"), 0, is_unreplied), updated_at = NOW() WHERE id = ? AND user_id = ? AND mail_account_id = ?')
        ->execute([$status, $next, $status, $mailId, (int)$account['user_id'], (int)$account['id']]);
    crm_log_event('mail', 'mail_followup_status', 'mail', (string)$mailId, null, ['followup_status' => $status, 'next_followup_at' => $next]);
    return ['mail_id' => $mailId, 'followup_status' => $status, 'next_followup_at' => $next];
}

function crm_mail_diagnostics(): array
{
    crm_require('mail.view');
    $account = crm_mail_current_account(true);
    if (!$account) throw new RuntimeException('请先绑定邮箱。');
    $imap = crm_mail_account_test('imap', ['target_user_id' => (int)$account['user_id']]);
    $smtp = crm_mail_account_test('smtp', ['target_user_id' => (int)$account['user_id']]);
    $logStmt = db()->prepare('SELECT * FROM crm_mail_sync_logs WHERE user_id = ? AND mail_account_id = ? ORDER BY id DESC LIMIT 1');
    $logStmt->execute([(int)$account['user_id'], (int)$account['id']]);
    $lastLog = $logStmt->fetch() ?: [];
    $parseStmt = db()->prepare("SELECT
        SUM(CASE WHEN parse_status = 'parse_error' THEN 1 ELSE 0 END) AS parse_failed,
        SUM(CASE WHEN body_html LIKE '%mail_attachment_download%inline=1%' THEN 1 ELSE 0 END) AS inline_body_count
        FROM crm_mails WHERE user_id = ? AND mail_account_id = ?");
    $parseStmt->execute([(int)$account['user_id'], (int)$account['id']]);
    $parse = $parseStmt->fetch() ?: [];
    $attachFail = 0;
    if (db_table_exists('crm_mail_attachments')) {
        $attachStmt = db()->prepare("SELECT COUNT(*) FROM crm_mail_attachments WHERE user_id = ? AND (file_path IS NULL OR file_path = '' OR preview_status = 'failed')");
        $attachStmt->execute([(int)$account['user_id']]);
        $attachFail = (int)$attachStmt->fetchColumn();
    }
    $errors = db()->prepare("SELECT stage,status,message,error_message,created_at,finished_at FROM crm_mail_sync_logs WHERE user_id = ? AND mail_account_id = ? AND (status = 'failed' OR error_message IS NOT NULL) ORDER BY id DESC LIMIT 8");
    $errors->execute([(int)$account['user_id'], (int)$account['id']]);
    return [
        'imap' => $imap,
        'smtp' => $smtp,
        'last_sync_time' => (string)($account['last_sync_time'] ?? ''),
        'last_sync' => $lastLog,
        'last_new_count' => (int)($lastLog['new_count'] ?? 0),
        'last_duplicate_count' => (int)($lastLog['duplicate_count'] ?? 0),
        'last_skipped_count' => (int)($lastLog['skipped_count'] ?? 0),
        'body_parse_failed_count' => (int)($parse['parse_failed'] ?? 0),
        'attachment_parse_failed_count' => $attachFail,
        'inline_image_count' => (int)($parse['inline_body_count'] ?? 0),
        'recent_errors' => $errors->fetchAll(),
    ];
}

function crm_mail_reserved_action(string $action, array $input): array
{
    crm_require('mail.view');
    crm_log_event('mail', $action, 'mail', (string)($input['mail_id'] ?? ''), null, ['reserved' => true]);
    return ['status' => 'reserved', 'message' => '接口已预留，等待对应业务模块正式接入。'];
}

function crm_mail_name_from_email(string $email): string
{
    $local = preg_replace('/@.*/', '', trim($email));
    $local = preg_replace('/[._-]+/', ' ', (string)$local);
    return trim(ucwords($local ?: '客户联系人'));
}

function crm_mail_domain_from_email(string $email): string
{
    $email = strtolower(trim($email));
    if (!str_contains($email, '@')) return '';
    $domain = substr($email, strpos($email, '@') + 1);
    return preg_replace('/^(www|mail)\./', '', $domain);
}

function crm_mail_customer_name_from_mail(array $mail): string
{
    $fromName = trim((string)($mail['from_name'] ?? ''));
    if ($fromName !== '' && !filter_var($fromName, FILTER_VALIDATE_EMAIL)) return $fromName;
    $domain = crm_mail_domain_from_email((string)($mail['from_email'] ?? ''));
    if ($domain !== '') {
        $base = preg_replace('/\.(com|net|org|cn|hk|co|io|de|uk|us|ae|sa|sg|my|au|ca|fr|it|es|nl|in|tr|br|mx)$/i', '', $domain);
        return strtoupper(str_replace(['-', '.'], ' ', $base));
    }
    return '邮件客户 #' . (int)($mail['id'] ?? 0);
}

function crm_mail_customer_payload(array $mail, array $input = []): array
{
    $email = trim((string)($mail['from_email'] ?? ''));
    $contactName = trim((string)($mail['from_name'] ?? ''));
    if ($contactName === '' || filter_var($contactName, FILTER_VALIDATE_EMAIL)) $contactName = crm_mail_name_from_email($email);
    $country = trim((string)($input['country'] ?? ''));
    $customerName = trim((string)($input['customer_name'] ?? ''));
    if ($customerName === '') $customerName = crm_mail_customer_name_from_mail($mail);
    $website = trim((string)($input['website'] ?? ''));
    $domain = crm_mail_domain_from_email($email);
    if ($website === '' && $domain !== '') $website = 'https://' . $domain;
    $subject = trim((string)($mail['subject'] ?? ''));
    $bodyText = trim(strip_tags((string)($mail['body_text'] ?? '')));
    if ($bodyText === '') $bodyText = trim(strip_tags((string)($mail['body_html'] ?? '')));
    if (mb_strlen($bodyText, 'UTF-8') > 900) $bodyText = mb_substr($bodyText, 0, 900, 'UTF-8') . '...';
    $remark = trim((string)($input['remark'] ?? ''));
    if ($remark === '') {
        $remark = "来源：CRM 邮件\n邮件主题：" . ($subject ?: '-') . "\n发件人：" . ($contactName ?: '-') . ' <' . ($email ?: '-') . ">\n收信时间：" . ((string)($mail['received_at'] ?? $mail['sent_at'] ?? '-') ?: '-') . "\n\n邮件摘要：\n" . ($bodyText ?: '-');
    }
    $contact = [
        'name' => trim((string)($input['contact_name'] ?? $contactName)),
        'email' => trim((string)($input['contact_email'] ?? $email)),
        'phone' => trim((string)($input['contact_phone'] ?? '')),
        'position' => trim((string)($input['contact_position'] ?? '')),
        'source' => 'mail',
        'is_primary' => 1,
        'remark' => '由 CRM 邮件联动创建',
    ];
    return [
        'customer_name' => $customerName,
        'country' => $country,
        'website' => $website,
        'email' => trim((string)($input['email'] ?? $email)),
        'phone' => trim((string)($input['phone'] ?? '')),
        'source' => 'mail',
        'level' => trim((string)($input['level'] ?? '潜在')),
        'status' => 'active',
        'lifecycle_key' => 'lead',
        'remark' => $remark,
        'contacts_json' => json_encode([$contact], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'mail_id' => (int)($mail['id'] ?? 0),
    ];
}

function crm_mail_customer_prefill(int $mailId): array
{
    crm_require('mail.view');
    $detail = crm_mail_get($mailId);
    $mail = $detail['mail'] ?? [];
    $payload = crm_mail_customer_payload($mail);
    $payload['country_required'] = true;
    $payload['crm'] = $detail['crm'] ?? [];
    return ['mail' => $mail, 'prefill' => $payload];
}

function crm_mail_create_customer_from_mail(array $input): array
{
    crm_require('customer.create');
    $mailId = (int)($input['mail_id'] ?? 0);
    if ($mailId <= 0) throw new RuntimeException('请先打开一封邮件。');
    $detail = crm_mail_get($mailId);
    $mail = $detail['mail'] ?? [];
    $payload = array_replace(crm_mail_customer_payload($mail, $input), $input);
    $payload['entry_mode'] = 'force';
    $payload['duplicate_risk_confirmed'] = '1';
    if (trim((string)($payload['country'] ?? '')) === '') throw new RuntimeException('国家不能为空。');
    $created = crm_customer_create_confirmed($payload);
    $customerId = (int)(($created['customer']['id'] ?? 0) ?: ($created['id'] ?? 0));
    if ($customerId > 0) {
        db()->prepare('UPDATE crm_mails SET linked_customer_id = ?, updated_at = NOW() WHERE id = ?')->execute([$customerId, $mailId]);
        crm_log_event('mail', 'mail_create_customer', 'mail', (string)$mailId, null, ['customer_id' => $customerId]);
    }
    return ['customer' => $created['customer'] ?? $created, 'detail' => $created, 'customer_id' => $customerId, 'mail' => crm_mail_get($mailId)];
}

function crm_mail_create_lead_from_mail(array $input): array
{
    crm_require('customer.create');
    $mailId = (int)($input['mail_id'] ?? 0);
    if ($mailId <= 0) throw new RuntimeException('请先打开一封邮件。');
    $detail = crm_mail_get($mailId);
    $mail = $detail['mail'] ?? [];
    $payload = array_replace(crm_mail_customer_payload($mail, $input), $input);
    if (trim((string)($payload['country'] ?? '')) === '') throw new RuntimeException('国家不能为空。');
    $lead = crm_lead_pool_create($payload);
    crm_log_event('mail', 'mail_create_lead_pool', 'mail', (string)$mailId, null, ['lead_id' => (int)($lead['lead']['id'] ?? 0)]);
    return $lead;
}

function crm_mail_create_followup_from_mail(array $input): array
{
    crm_require('follow.create');
    $mailId = (int)($input['mail_id'] ?? 0);
    if ($mailId <= 0) throw new RuntimeException('请先打开一封邮件。');
    $detail = crm_mail_get($mailId);
    $mail = $detail['mail'] ?? [];
    $customerId = (int)($input['customer_id'] ?? ($mail['linked_customer_id'] ?? 0));
    if ($customerId <= 0) throw new RuntimeException('请先关联客户，再新建跟进。');
    crm_customer_get($customerId);
    $subject = trim((string)($mail['subject'] ?? ''));
    $content = trim((string)($input['content'] ?? ''));
    if ($content === '') {
        $bodyText = trim(strip_tags((string)($mail['body_text'] ?? '')));
        if ($bodyText === '') $bodyText = trim(strip_tags((string)($mail['body_html'] ?? '')));
        if (mb_strlen($bodyText, 'UTF-8') > 900) $bodyText = mb_substr($bodyText, 0, 900, 'UTF-8') . '...';
        $content = "邮件跟进：" . ($subject ?: '无主题') . "\n发件人：" . ((string)($mail['from_email'] ?? '-') ?: '-') . "\n\n" . ($bodyText ?: '-');
    }
    db()->prepare('INSERT INTO crm_customer_followups (customer_id, contact_id, followup_time, followup_type, content, next_plan, next_remind_time, status, related_email_id, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())')
        ->execute([
            $customerId,
            (int)($input['contact_id'] ?? 0) ?: null,
            trim((string)($input['followup_time'] ?? '')) ?: date('Y-m-d H:i:s'),
            trim((string)($input['followup_type'] ?? '邮件')) ?: '邮件',
            $content,
            trim((string)($input['next_plan'] ?? '')),
            trim((string)($input['next_remind_time'] ?? '')) ?: null,
            trim((string)($input['status'] ?? 'open')) ?: 'open',
            $mailId,
            current_user()['id'],
            current_user()['id'],
        ]);
    $id = (int)db()->lastInsertId();
    crm_customer_log('followup_create', 'followup', $id, $customerId, null, $input, '邮件联动新建跟进');
    crm_customer_timeline_add($customerId, 'followup_create', '邮件联动跟进', $content, 'followup', (string)$id);
    $followupPayload = [
        'id' => $id,
        'customer_id' => $customerId,
        'contact_id' => (int)($input['contact_id'] ?? 0) ?: null,
        'followup_time' => trim((string)($input['followup_time'] ?? '')) ?: date('Y-m-d H:i:s'),
        'followup_type' => trim((string)($input['followup_type'] ?? '邮件')) ?: '邮件',
        'content' => $content,
        'next_plan' => trim((string)($input['next_plan'] ?? '')),
        'next_remind_time' => trim((string)($input['next_remind_time'] ?? '')) ?: null,
        'status' => trim((string)($input['status'] ?? 'open')) ?: 'open',
        'created_by' => current_user()['id'] ?? 0,
    ];
    if (function_exists('crm_task_upsert_from_followup')) {
        crm_task_upsert_from_followup($followupPayload);
    } elseif (function_exists('notification_create_followup_reminder')) {
        notification_create_followup_reminder($followupPayload);
    }
    crm_log_event('mail', 'mail_create_followup', 'mail', (string)$mailId, null, ['customer_id' => $customerId, 'followup_id' => $id]);
    return ['followup_id' => $id, 'customer' => crm_customer_get($customerId)];
}

function crm_mail_dispatch_options(): array
{
    crm_require('mail.view');
    $stmt = db()->query("SELECT u.id, u.username, COALESCE(NULLIF(u.real_name,''), u.username) AS name, COALESCE(d.name,'') AS department, COALESCE(r.role_name,'') AS role_name
        FROM crm_users u
        LEFT JOIN crm_departments d ON d.id = u.department_id
        LEFT JOIN crm_roles r ON r.id = u.role_id
        WHERE u.status = 'active'
        ORDER BY d.sort_order, d.name, u.real_name, u.username");
    return ['users' => $stmt->fetchAll()];
}

function crm_mail_dispatch_task_no(PDO $pdo, string $prefix): string
{
    do {
        $no = $prefix . date('ymdHis') . mt_rand(100, 999);
        $st = $pdo->prepare('SELECT COUNT(*) FROM dispatch_next_tasks WHERE task_no = ?');
        $st->execute([$no]);
    } while ((int)$st->fetchColumn() > 0);
    return $no;
}

function crm_mail_dispatch_group_no(PDO $pdo): string
{
    do {
        $no = 'DM' . date('ymdHis') . mt_rand(100, 999);
        $st = $pdo->prepare('SELECT COUNT(*) FROM dispatch_next_groups WHERE group_no = ?');
        $st->execute([$no]);
    } while ((int)$st->fetchColumn() > 0);
    return $no;
}

function crm_mail_create_dispatch(array $input): array
{
    crm_require('mail.view');
    $user = current_user();
    $uid = (int)($user['id'] ?? 0);
    if ($uid <= 0) throw new RuntimeException('登录状态无效。');

    require_once __DIR__ . '/dispatch_next_schema.php';
    dispatch_next_init_schema();

    $mailId = (int)($input['mail_id'] ?? 0);
    if ($mailId <= 0) throw new RuntimeException('请先打开一封邮件。');
    $detail = crm_mail_get($mailId);
    $mail = $detail['mail'] ?? [];
    if (!$mail) throw new RuntimeException('邮件不存在或无权查看。');

    $assigneeIds = $input['assignee_ids'] ?? [];
    if (!is_array($assigneeIds)) $assigneeIds = preg_split('/[,，\s]+/', (string)$assigneeIds, -1, PREG_SPLIT_NO_EMPTY);
    $assigneeIds = array_values(array_unique(array_filter(array_map('intval', $assigneeIds), fn($v) => $v > 0)));
    if (!$assigneeIds) throw new RuntimeException('请选择执行人。');

    $placeholders = implode(',', array_fill(0, count($assigneeIds), '?'));
    $userStmt = db()->prepare("SELECT id FROM crm_users WHERE status='active' AND id IN ({$placeholders})");
    $userStmt->execute($assigneeIds);
    $validAssignees = array_map('intval', array_column($userStmt->fetchAll(), 'id'));
    $assigneeIds = array_values(array_intersect($assigneeIds, $validAssignees));
    if (!$assigneeIds) throw new RuntimeException('选择的执行人不存在或已停用。');

    $subject = trim((string)($mail['subject'] ?? ''));
    $title = trim((string)($input['title'] ?? ''));
    if ($title === '') $title = 'CRM 邮件联动';
    if (mb_strlen($title, 'UTF-8') > 240) $title = mb_substr($title, 0, 240, 'UTF-8');
    $project = trim((string)($input['project'] ?? ''));
    if ($project === '') $project = '邮件处理：' . ($subject !== '' ? $subject : ('邮件 #' . $mailId));
    if (mb_strlen($project, 'UTF-8') > 180) $project = mb_substr($project, 0, 180, 'UTF-8');
    $priority = in_array(($input['priority'] ?? 'normal'), ['normal','important','urgent','today'], true) ? (string)$input['priority'] : 'normal';
    $dueAt = trim((string)($input['due_at'] ?? ''));
    $dueAt = $dueAt !== '' && strtotime($dueAt) ? date('Y-m-d H:i:s', strtotime($dueAt)) : null;
    $taskDate = date('Y-m-d');
    $bodyText = trim(strip_tags((string)($mail['body_text'] ?? '')));
    if ($bodyText === '') $bodyText = trim(strip_tags((string)($mail['body_html'] ?? '')));
    if (mb_strlen($bodyText, 'UTF-8') > 1200) $bodyText = mb_substr($bodyText, 0, 1200, 'UTF-8') . '...';
    $description = trim((string)($input['description'] ?? ''));
    if ($description === '') {
        $description = "来源：CRM 邮件\n主题：" . ($subject ?: '-') . "\n发件人：" . ((string)($mail['from_name'] ?? '') ?: '-') . ' <' . ((string)($mail['from_email'] ?? '') ?: '-') . ">\n收件人：" . ((string)($mail['to_emails'] ?? '-') ?: '-') . "\n收信时间：" . ((string)($mail['received_at'] ?? $mail['sent_at'] ?? '-') ?: '-') . "\n\n邮件摘要：\n" . ($bodyText ?: '-');
    }
    if (mb_strlen($description, 'UTF-8') > 8000) $description = mb_substr($description, 0, 8000, 'UTF-8');

    $linked = [
        'mail_id' => $mailId,
        'subject' => $subject,
        'from_email' => (string)($mail['from_email'] ?? ''),
        'from_name' => (string)($mail['from_name'] ?? ''),
        'to_emails' => (string)($mail['to_emails'] ?? ''),
        'received_at' => (string)($mail['received_at'] ?? ''),
        'linked_customer_id' => (int)($mail['linked_customer_id'] ?? 0),
        'linked_customer_name' => (string)($mail['linked_customer_name'] ?? ''),
    ];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $taskIds = [];
        $groupId = null;
        if (count($assigneeIds) > 1) {
            $groupNo = crm_mail_dispatch_group_no($pdo);
            $pdo->prepare("INSERT INTO dispatch_next_groups(group_no,group_type,title,project,description,created_by,assignee_ids_json,total_count,task_date,due_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,NOW(),NOW())")
                ->execute([$groupNo, 'multi', $title, $project, $description, $uid, json_encode($assigneeIds, JSON_UNESCAPED_UNICODE), count($assigneeIds), $taskDate, $dueAt]);
            $groupId = (int)$pdo->lastInsertId();
        }
        $insert = $pdo->prepare("INSERT INTO dispatch_next_tasks(task_no,task_type,dispatch_mode,parent_group_id,title,project,description,priority,status,created_by,assigned_to,helper_ids_json,task_date,due_at,progress,is_read,linked_system,linked_table,linked_id,linked_title,linked_json,extra_json,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())");
        foreach ($assigneeIds as $aid) {
            $mode = count($assigneeIds) > 1 ? 'multi' : 'single';
            $status = $aid === $uid ? 'in_progress' : 'pending_accept';
            $insert->execute([
                crm_mail_dispatch_task_no($pdo, $mode === 'multi' ? 'MM' : 'MD'),
                'dispatch',
                $mode,
                $groupId,
                $title,
                $project,
                $description,
                $priority,
                $status,
                $uid,
                $aid,
                json_encode([], JSON_UNESCAPED_UNICODE),
                $taskDate,
                $dueAt,
                0,
                $aid === $uid ? 1 : 0,
                'mail',
                'crm_mails',
                (string)$mailId,
                $subject,
                json_encode($linked, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode(['source' => 'crm_mail_create_dispatch'], JSON_UNESCAPED_UNICODE),
            ]);
            $taskId = (int)$pdo->lastInsertId();
            $taskIds[] = $taskId;
            $pdo->prepare("INSERT INTO dispatch_next_notifications(recipient_id,sender_id,task_id,type,title,message,created_at) VALUES(?,?,?,?,?,?,NOW())")
                ->execute([$aid, $uid, $taskId, 'new_dispatch', '邮件转派工待接收', $title]);
            $pdo->prepare("INSERT INTO dispatch_next_logs(task_id,user_id,action_type,field_name,old_value,new_value,note,ip,user_agent,created_at) VALUES(?,?,?,?,?,?,?,?,?,NOW())")
                ->execute([$taskId, $uid, 'mail_create_dispatch', 'linked_id', '', (string)$mailId, 'CRM 邮件转派工', $_SERVER['REMOTE_ADDR'] ?? '', substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]);
        }
        if ($groupId) {
            $pdo->prepare("UPDATE dispatch_next_groups SET done_count=(SELECT COUNT(*) FROM dispatch_next_tasks WHERE parent_group_id=? AND status='done' AND is_deleted=0), status='active', updated_at=NOW() WHERE id=?")
                ->execute([$groupId, $groupId]);
        }
        crm_log_event('mail', 'mail_create_dispatch', 'mail', (string)$mailId, null, ['task_ids' => $taskIds, 'group_id' => $groupId, 'assignee_ids' => $assigneeIds]);
        $pdo->commit();
        return ['task_ids' => $taskIds, 'group_id' => $groupId, 'assignee_count' => count($assigneeIds), 'message' => count($assigneeIds) > 1 ? '已创建多人派工' : '已创建派工'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function crm_mail_create_task_from_mail(array $input): array
{
    crm_require('mail.view');
    if (!function_exists('crm_task_save')) {
        require_once __DIR__ . '/crm_task_center.php';
    }

    $mailId = (int)($input['mail_id'] ?? 0);
    if ($mailId <= 0) throw new RuntimeException('请先打开一封邮件。');

    $detail = crm_mail_get($mailId);
    $mail = $detail['mail'] ?? [];
    if (!$mail) throw new RuntimeException('邮件不存在或无权查看。');

    $subject = trim((string)($mail['subject'] ?? ''));
    $title = trim((string)($input['title'] ?? ''));
    if ($title === '') $title = '邮件任务：' . ($subject !== '' ? $subject : ('邮件 #' . $mailId));
    if (mb_strlen($title, 'UTF-8') > 240) $title = mb_substr($title, 0, 240, 'UTF-8');

    $bodyText = trim(strip_tags((string)($mail['body_text'] ?? '')));
    if ($bodyText === '') $bodyText = trim(strip_tags((string)($mail['body_html'] ?? '')));
    if (mb_strlen($bodyText, 'UTF-8') > 1200) $bodyText = mb_substr($bodyText, 0, 1200, 'UTF-8') . '...';

    $description = trim((string)($input['description'] ?? ''));
    if ($description === '') {
        $description = "来源：CRM 邮件\n主题：" . ($subject ?: '-') .
            "\n发件人：" . ((string)($mail['from_name'] ?? '') ?: '-') . ' <' . ((string)($mail['from_email'] ?? '') ?: '-') . '>' .
            "\n收件人：" . ((string)($mail['to_emails'] ?? '-') ?: '-') .
            "\n时间：" . ((string)($mail['received_at'] ?? $mail['sent_at'] ?? '-') ?: '-') .
            "\n\n邮件摘要：\n" . ($bodyText ?: '-');
    }
    if (mb_strlen($description, 'UTF-8') > 8000) $description = mb_substr($description, 0, 8000, 'UTF-8');

    $customerId = (int)($input['customer_id'] ?? 0);
    if ($customerId <= 0) $customerId = (int)($mail['linked_customer_id'] ?? 0);

    $task = crm_task_save([
        'task_type' => preg_replace('/[^a-z0-9_]/i', '', (string)($input['task_type'] ?? 'customer_followup')) ?: 'customer_followup',
        'title' => $title,
        'description' => $description,
        'source_type' => 'mail',
        'source_id' => (string)$mailId,
        'customer_id' => $customerId ?: null,
        'assigned_user_id' => (int)($input['assigned_user_id'] ?? ((current_user() ?: [])['id'] ?? 0)),
        'priority' => in_array(($input['priority'] ?? 'normal'), ['urgent','important','normal','low'], true) ? (string)$input['priority'] : 'normal',
        'status' => 'pending',
        'due_at' => trim((string)($input['due_at'] ?? '')),
        'reminder_at' => trim((string)($input['reminder_at'] ?? '')),
    ]);

    $taskId = (int)($task['task']['id'] ?? 0);
    crm_log_event('mail', 'mail_create_task', 'mail', (string)$mailId, null, [
        'task_id' => $taskId,
        'customer_id' => $customerId,
        'assigned_user_id' => (int)($input['assigned_user_id'] ?? 0),
    ]);
    if ($customerId > 0 && $taskId > 0) {
        crm_customer_timeline_add($customerId, 'mail_create_task', '邮件转任务：' . $title, '来源邮件：' . ($subject ?: ('#' . $mailId)), 'task', (string)$taskId);
    }

    return $task + ['message' => '邮件任务已创建'];
}
