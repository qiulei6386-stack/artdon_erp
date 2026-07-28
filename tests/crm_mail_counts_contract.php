<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$mail = (string)file_get_contents($root . '/crm_mail.php');
$page = (string)file_get_contents($root . '/crm.php');

function mail_count_contract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

mail_count_contract(substr_count($mail, "folder = 'sent' AND is_unreplied = 1 AND is_deleted = 0") >= 2, 'unreplied summaries must count sent mail only');
mail_count_contract(str_contains($mail, "folder <> 'sent' AND is_unreplied = 1"), 'stale inbox unreplied flags must be repaired');
mail_count_contract(str_contains($mail, "m.folder = 'sent' AND m.is_unreplied = 1"), 'unreplied list must show sent mail only');
mail_count_contract(!str_contains($mail, '?, ?, ?, ?, ?, ?, ?, 0, 1, ?, ?, ?, ?, ?, "imap_inbox"'), 'incoming mail must not be inserted as unreplied');
mail_count_contract(str_contains($page, '已发未回复'), 'mail UI must explain the unreplied scope');

echo "PASS: CRM mail count semantics contract\n";
