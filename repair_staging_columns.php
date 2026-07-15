<?php
declare(strict_types=1);

$cfg = require __DIR__ . '/website_bridge_config.php';
$db = $cfg['db'] ?? [];
$pdo = new PDO(
    'mysql:host=' . ($db['host'] ?? '127.0.0.1') . ';port=' . ($db['port'] ?? '3306') . ';dbname=' . ($db['name'] ?? 'artdon_erp') . ';charset=' . ($db['charset'] ?? 'utf8mb4'),
    (string)($db['user'] ?? ''),
    (string)($db['pass'] ?? ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$exists = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='website_inquiry_staging'")->fetchColumn();
if ($exists === 0) {
    echo "website_inquiry_staging table does not exist yet.\n";
    exit(0);
}

$cols = $pdo->query('SHOW COLUMNS FROM website_inquiry_staging')->fetchAll(PDO::FETCH_COLUMN);
foreach ($cols as $col) {
    echo '[' . $col . "]\n";
}

if (in_array('whatsapp ', $cols, true) && !in_array('whatsapp', $cols, true)) {
    $pdo->exec("ALTER TABLE website_inquiry_staging CHANGE `whatsapp ` `whatsapp` VARCHAR(120) NOT NULL DEFAULT ''");
    echo "fixed whatsapp-space column\n";
} elseif (in_array('whatsapp ', $cols, true) && in_array('whatsapp', $cols, true)) {
    $pdo->exec("ALTER TABLE website_inquiry_staging DROP COLUMN `whatsapp `");
    echo "dropped duplicate whatsapp-space column\n";
} else {
    echo "whatsapp column is ok\n";
}
