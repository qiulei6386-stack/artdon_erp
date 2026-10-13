<?php
// 小程序后端迁移脚本（CLI 运行）
require __DIR__ . '/../artdon_erp/includes/bootstrap.php';
$pdo = db();

function tableExists(PDO $pdo, string $table): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
}

$tables = [
    'miniapp_tokens' => "CREATE TABLE miniapp_tokens (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      token VARCHAR(64) NOT NULL,
      openid VARCHAR(64) DEFAULT '',
      appid VARCHAR(32) DEFAULT '',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      expires_at DATETIME NOT NULL,
      UNIQUE KEY uk_token (token),
      KEY idx_user (user_id),
      KEY idx_openid (openid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'miniapp_wx_bind' => "CREATE TABLE miniapp_wx_bind (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      appid VARCHAR(32) NOT NULL,
      openid VARCHAR(64) NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uk_appid_openid (appid, openid),
      KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'miniapp_checkins' => "CREATE TABLE miniapp_checkins (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      visit_id INT UNSIGNED NOT NULL,
      user_id INT UNSIGNED NOT NULL,
      lat DECIMAL(10,7) NOT NULL,
      lng DECIMAL(10,7) NOT NULL,
      address VARCHAR(255) DEFAULT '',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_visit (visit_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];
foreach ($tables as $t => $sql) {
    if (!tableExists($pdo, $t)) { $pdo->exec($sql); echo "created table $t\n"; }
    else echo "table $t exists\n";
}
echo "MIGRATION DONE\n";
