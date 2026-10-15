<?php
declare(strict_types=1);

/** 邮件附件下载；inline=1 时在页面里显示图片（正文内嵌图片）。 */

require_once __DIR__ . '/bootstrap.php';

use Artdon\Centers\Base\Auth;
use Artdon\Centers\Base\Layout;
use Artdon\Centers\Base\NotFoundException;
use Artdon\Centers\Base\PermissionException;
use Artdon\Centers\Mail\Mailbox;

$user = Auth::requirePage();
try {
    $box = new Mailbox($user, (int) ($_GET['user_id'] ?? 0));
    $file = $box->attachmentFile((int) ($_GET['id'] ?? 0), !empty($_GET['inline']));
} catch (PermissionException $e) {
    Layout::errorPage(403, '没有权限', $e->getMessage());
    return;
} catch (NotFoundException $e) {
    Layout::errorPage(404, '附件不存在', $e->getMessage());
    return;
}
$name = str_replace(["\r", "\n", '"', '\\'], '', $file['name']);
$ascii = preg_replace('/[^A-Za-z0-9._ -]/', '_', $name) ?: 'attachment';
$mime = preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#', $file['mime']) ? $file['mime'] : 'application/octet-stream';
// 内嵌显示只允许常见图片格式；svg 等一律下载
$inline = $file['inline'] && in_array($mime, ['image/png', 'image/jpeg', 'image/jpg', 'image/gif', 'image/webp', 'image/bmp'], true);
header('Content-Type: ' . ($inline ? $mime : 'application/octet-stream'));
header('Content-Length: ' . $file['size']);
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: sandbox; default-src \'none\'');
header('Cache-Control: private, max-age=' . ($inline ? 3600 : 0));
readfile($file['path']);
