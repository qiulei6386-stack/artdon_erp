<?php
declare(strict_types=1);

/** 邮件正文（只在读信页的沙箱 iframe 里显示）。不允许脚本、表单；读信日志在读信页记。 */

require_once __DIR__ . '/bootstrap.php';

use Artdon\Centers\Base\Auth;
use Artdon\Centers\Base\NotFoundException;
use Artdon\Centers\Base\PermissionException;
use Artdon\Centers\Mail\MailBody;
use Artdon\Centers\Mail\Mailbox;
use Artdon\Centers\Mail\MailView;

$user = Auth::requirePage();
header('Content-Type: text/html; charset=utf-8');
header('Content-Security-Policy: ' . MailBody::csp());
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: private, no-store');
try {
    $box = new Mailbox($user, (int) ($_GET['user_id'] ?? 0));
    $id = (int) ($_GET['id'] ?? 0);
    $mail = $box->row($id);
    $owner = $box->ownerId();
    $inner = MailBody::html($mail, $box->bodyAttachments($id), static function (int $attachmentId) use ($owner, $box): string {
        return MailView::attachmentUrl($attachmentId, $box->isSelf() ? 0 : $owner, true);
    });
} catch (PermissionException | NotFoundException $e) {
    http_response_code($e instanceof PermissionException ? 403 : 404);
    $inner = '<p style="color:#64748b">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
}
echo MailBody::page($inner);
