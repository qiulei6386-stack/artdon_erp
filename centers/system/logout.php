<?php
declare(strict_types=1);

/** 退出登录。只接受带 CSRF 令牌的 POST，避免被外站链接强制退出。 */

require_once __DIR__ . '/bootstrap.php';

use Artdon\Centers\Base\Auth;
use Artdon\Centers\Base\Flash;
use Artdon\Centers\Base\Http;
use Artdon\Centers\Base\Url;

if (!Auth::user()) {
    Http::redirect(Url::center('system', 'login.php'), 302);
}
if (!Http::isPost()) {
    Http::redirect(Url::center('system', 'profile.php'), 302);
}
if (!Http::csrfValid()) {
    Flash::set('error', '页面已过期，请再点一次“退出登录”。');
    Http::redirect(Url::center('system', 'profile.php'));
}

logout_user();
Http::redirect(Url::center('system', 'login.php') . '?logged_out=1');
