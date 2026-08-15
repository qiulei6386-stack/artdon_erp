<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$mail = file_get_contents($root . '/crm_mail.php');
if ($mail === false) {
    fwrite(STDERR, "Cannot read crm_mail.php\n");
    exit(1);
}

$markers = [
    ['crm_mail_normalize_file_name_text', '附件文件名规范化函数'],
    ['\\x{00A0}\\x{2007}\\x{202F}', 'NBSP 等异常空格会被规范化'],
    ['crm_mail_attachment_should_hide_inline', 'inline 附件可见性判断函数'],
    ['strpos($type, \'image/\') !== 0) return false', '非图片附件不能被隐藏为 inline'],
    ['$isInline = (!$hasAttachmentDisposition && crm_mail_attachment_should_hide_inline($typeOnly, $contentId, $hasInlineDisposition)) ? 1 : 0', 'IMAP MIME 解析使用 inline 可见性判断'],
    ['$isInline = !empty($attachment[\'is_inline\']) && crm_mail_attachment_should_hide_inline($mimeType, $contentId, true)', '附件入库前二次规范 inline 状态'],
    ['crm_mail_repair_non_image_inline_attachments', '历史非图片 inline 附件修复函数'],
    ['LOWER(mime_type) NOT LIKE "image/%"', '历史修复仅把非图片 inline 转回普通附件'],
    ['crm_mail_repair_attachment_filenames', '历史附件文件名规范化函数'],
    ['crm_mail_repair_non_image_inline_attachments((int)$account[\'user_id\'], $mailId)', '打开邮件时修复历史误标 inline 附件'],
    ['crm_mail_repair_attachment_filenames((int)$account[\'user_id\'], $mailId)', '打开邮件时修复历史异常文件名'],
];

foreach ($markers as [$needle, $label]) {
    if (strpos($mail, $needle) === false) {
        fwrite(STDERR, "Missing CRM mail attachment visibility marker: {$label}\n");
        exit(1);
    }
}

echo "crm_mail_attachment_inline_visibility_contract ok\n";

