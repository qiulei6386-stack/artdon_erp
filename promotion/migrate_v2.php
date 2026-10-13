<?php
// 推广模块 v2 迁移脚本（CLI 运行）
require __DIR__ . '/../includes/bootstrap.php';
$pdo = db();

function colExists(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $st->execute([$table, $col]);
    return (bool)$st->fetchColumn();
}
function tableExists(PDO $pdo, string $table): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
}

$tables = [
    'promo_opens' => "CREATE TABLE promo_opens (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      recipient_id INT UNSIGNED NOT NULL, campaign_id INT UNSIGNED NOT NULL,
      opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      ip VARCHAR(45) DEFAULT '', user_agent VARCHAR(255) DEFAULT '',
      KEY idx_recipient (recipient_id), KEY idx_campaign (campaign_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'promo_clicks' => "CREATE TABLE promo_clicks (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      recipient_id INT UNSIGNED NOT NULL, campaign_id INT UNSIGNED NOT NULL,
      url TEXT NOT NULL, clicked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      ip VARCHAR(45) DEFAULT '',
      KEY idx_recipient (recipient_id), KEY idx_campaign (campaign_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'promo_unsubscribes' => "CREATE TABLE promo_unsubscribes (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      email VARCHAR(255) NOT NULL, campaign_id INT UNSIGNED DEFAULT 0,
      recipient_id INT UNSIGNED DEFAULT 0, reason VARCHAR(255) DEFAULT '',
      unsubscribed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uk_email (email), KEY idx_campaign (campaign_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'promo_approvals' => "CREATE TABLE promo_approvals (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      campaign_id INT UNSIGNED NOT NULL, requested_by INT UNSIGNED NOT NULL,
      approver_id INT UNSIGNED DEFAULT 0, status VARCHAR(20) NOT NULL DEFAULT 'pending',
      note VARCHAR(500) DEFAULT '',
      requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, decided_at DATETIME NULL,
      KEY idx_campaign (campaign_id), KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'promo_send_log' => "CREATE TABLE promo_send_log (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      customer_id INT UNSIGNED DEFAULT 0, email VARCHAR(255) NOT NULL,
      campaign_id INT UNSIGNED NOT NULL, sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_email_time (email, sent_at), KEY idx_customer_time (customer_id, sent_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'promo_mail_quotas' => "CREATE TABLE promo_mail_quotas (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      mail_account_id INT UNSIGNED NOT NULL, quota_date DATE NOT NULL,
      sent_count INT UNSIGNED NOT NULL DEFAULT 0, daily_limit INT UNSIGNED NOT NULL DEFAULT 200,
      UNIQUE KEY uk_account_date (mail_account_id, quota_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'promo_audit_log' => "CREATE TABLE promo_audit_log (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL, action VARCHAR(50) NOT NULL,
      target_type VARCHAR(50) DEFAULT '', target_id INT UNSIGNED DEFAULT 0,
      detail TEXT, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_user (user_id), KEY idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'promo_segments' => "CREATE TABLE promo_segments (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(100) NOT NULL, conditions TEXT NOT NULL,
      created_by INT UNSIGNED NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      deleted_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];
foreach ($tables as $t => $sql) {
    if (!tableExists($pdo, $t)) { $pdo->exec($sql); echo "created table $t\n"; }
    else echo "table $t exists\n";
}

$alters = [
    ['promo_campaigns', 'approval_status', "ADD COLUMN approval_status VARCHAR(20) NOT NULL DEFAULT 'none'"],
    ['promo_campaigns', 'batch_size', "ADD COLUMN batch_size INT UNSIGNED NOT NULL DEFAULT 50"],
    ['promo_campaigns', 'frequency_days', "ADD COLUMN frequency_days INT UNSIGNED NOT NULL DEFAULT 14"],
    ['promo_campaigns', 'track_opens', "ADD COLUMN track_opens TINYINT(1) NOT NULL DEFAULT 1"],
    ['promo_campaigns', 'track_clicks', "ADD COLUMN track_clicks TINYINT(1) NOT NULL DEFAULT 1"],
    ['promo_campaigns', 'require_approval', "ADD COLUMN require_approval TINYINT(1) NOT NULL DEFAULT 0"],
    ['promo_recipients', 'open_count', "ADD COLUMN open_count INT UNSIGNED NOT NULL DEFAULT 0"],
    ['promo_recipients', 'first_opened_at', "ADD COLUMN first_opened_at DATETIME NULL"],
    ['promo_recipients', 'click_count', "ADD COLUMN click_count INT UNSIGNED NOT NULL DEFAULT 0"],
    ['promo_recipients', 'first_clicked_at', "ADD COLUMN first_clicked_at DATETIME NULL"],
    ['promo_recipients', 'unsubscribed', "ADD COLUMN unsubscribed TINYINT(1) NOT NULL DEFAULT 0"],
    ['promo_recipients', 'mail_account_id', "ADD COLUMN mail_account_id INT UNSIGNED DEFAULT 0"],
    ['promo_templates', 'category', "ADD COLUMN category VARCHAR(50) NOT NULL DEFAULT 'general'"],
    ['promo_templates', 'is_shared', "ADD COLUMN is_shared TINYINT(1) NOT NULL DEFAULT 0"],
    ['promo_templates', 'is_favorite', "ADD COLUMN is_favorite TINYINT(1) NOT NULL DEFAULT 0"],
];
foreach ($alters as [$t, $c, $sql]) {
    if (!colExists($pdo, $t, $c)) { $pdo->exec("ALTER TABLE $t $sql"); echo "added $t.$c\n"; }
    else echo "column $t.$c exists\n";
}
echo "MIGRATION DONE\n";
