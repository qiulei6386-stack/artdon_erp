<?php
declare(strict_types=1);

/** 显示一张名片图（跟着客户的查看权限）。 */

require_once __DIR__ . '/bootstrap.php';

use Artdon\Centers\Base\Auth;
use Artdon\Centers\Base\NotFoundException;
use Artdon\Centers\Customer\BusinessCard;

Auth::requirePage();
Auth::requirePermissionPage('customer.view');
try {
    $file = (new BusinessCard(Auth::user()))->file((int) ($_GET['id'] ?? 0));
} catch (NotFoundException $e) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo '名片图片不存在或无权查看';
    exit;
}
header('Content-Type: image/jpeg');
header('Content-Length: ' . (string) filesize($file['real_path']));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($file['real_path']);
