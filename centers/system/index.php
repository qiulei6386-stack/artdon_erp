<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Artdon\Centers\Base\Auth;
use Artdon\Centers\Base\Http;
use Artdon\Centers\Base\Url;

Auth::requirePage();
Http::redirect(Url::center('system', 'profile.php'), 302);
