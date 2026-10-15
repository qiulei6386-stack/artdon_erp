<?php
declare(strict_types=1);

/** 旧的读信地址：转到收件箱三栏里打开这封邮件（通知、客户详情等处的链接都可以用这个地址）。 */

require_once __DIR__ . '/bootstrap.php';

use Artdon\Centers\Base\Auth;
use Artdon\Centers\Base\Http;
use Artdon\Centers\Base\Url;

Auth::requirePage();
$id = (int) ($_GET['id'] ?? 0);
$owner = (int) ($_GET['user_id'] ?? 0);
$folder = is_string($_GET['folder'] ?? null) ? (string) $_GET['folder'] : '';
Http::redirect(Url::center('mail', 'inbox.php') . '?' . http_build_query(array_filter(['user_id' => $owner, 'folder' => preg_match('/^[a-z]{1,20}$/', $folder) ? $folder : '', 'id' => $id])));
