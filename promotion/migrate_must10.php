<?php
/**
 * Promotion must-haves 1–10 schema migration (CLI).
 * Does NOT touch crm_marketing*.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
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

// Expand campaign status enum
try {
    $pdo->exec("ALTER TABLE promo_campaigns MODIFY COLUMN status ENUM('draft','sending','paused','stopped','done','cancelled') NOT NULL DEFAULT 'draft'");
    echo "status enum updated\n";
} catch (Throwable $e) {
    echo "status enum: " . $e->getMessage() . "\n";
}

$alters = [
    ['promo_campaigns', 'audience_snapshot', "ADD COLUMN audience_snapshot MEDIUMTEXT NULL"],
    ['promo_campaigns', 'has_test_send', "ADD COLUMN has_test_send TINYINT(1) NOT NULL DEFAULT 0"],
    ['promo_campaigns', 'test_send_at', "ADD COLUMN test_send_at DATETIME NULL"],
    ['promo_campaigns', 'test_send_to', "ADD COLUMN test_send_to VARCHAR(190) NULL"],
    ['promo_campaigns', 'paused_at', "ADD COLUMN paused_at DATETIME NULL"],
    ['promo_campaigns', 'stopped_at', "ADD COLUMN stopped_at DATETIME NULL"],
    ['promo_campaigns', 'body_plain', "ADD COLUMN body_plain MEDIUMTEXT NULL"],
    ['promo_recipients', 'reply_at', "ADD COLUMN reply_at DATETIME NULL"],
    ['promo_offline_tasks', 'screenshot_path', "ADD COLUMN screenshot_path VARCHAR(500) NULL"],
    ['promo_offline_tasks', 'screenshot_name', "ADD COLUMN screenshot_name VARCHAR(255) NULL"],
];
foreach ($alters as [$t, $c, $sql]) {
    if (!colExists($pdo, $t, $c)) {
        $pdo->exec("ALTER TABLE $t $sql");
        echo "added $t.$c\n";
    } else {
        echo "column $t.$c exists\n";
    }
}

if (!tableExists($pdo, 'promo_dns_auth_cache')) {
    $pdo->exec("CREATE TABLE promo_dns_auth_cache (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      domain VARCHAR(190) NOT NULL,
      spf_ok TINYINT(1) NOT NULL DEFAULT 0,
      dkim_ok TINYINT(1) NOT NULL DEFAULT 0,
      dmarc_ok TINYINT(1) NOT NULL DEFAULT 0,
      spf_raw TEXT NULL,
      dkim_raw TEXT NULL,
      dmarc_raw TEXT NULL,
      error_text VARCHAR(500) NULL,
      checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uk_domain (domain)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "created promo_dns_auth_cache\n";
} else {
    echo "table promo_dns_auth_cache exists\n";
}

if (!tableExists($pdo, 'promo_mail_account_settings')) {
    $pdo->exec("CREATE TABLE promo_mail_account_settings (
      mail_account_id INT UNSIGNED NOT NULL PRIMARY KEY,
      daily_limit INT UNSIGNED NOT NULL DEFAULT 200,
      updated_by INT UNSIGNED NOT NULL DEFAULT 0,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "created promo_mail_account_settings\n";
} else {
    echo "table promo_mail_account_settings exists\n";
}

if (!tableExists($pdo, 'promo_settings')) {
    $pdo->exec("CREATE TABLE promo_settings (
      setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
      setting_value TEXT NULL,
      updated_by INT UNSIGNED NOT NULL DEFAULT 0,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "created promo_settings\n";
} else {
    echo "table promo_settings exists\n";
}

// seed defaults
$defaults = [
    'require_approval' => '1',
    'require_test_send' => '1',
    'frequency_days' => '14',
    'default_daily_limit' => '200',
    'block_bulk_on_dns_fail' => '1',
];
$ins = $pdo->prepare("INSERT IGNORE INTO promo_settings (setting_key, setting_value, updated_by) VALUES (?, ?, 0)");
foreach ($defaults as $k => $v) $ins->execute([$k, $v]);

echo "MIGRATION MUST10 DONE\n";
