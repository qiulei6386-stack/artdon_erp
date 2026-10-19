<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

function yf_require_bom_user(): array
{
    require_once dirname(__DIR__) . '/includes/bootstrap.php';
    if (!is_logged_in()) {
        redirect(yf_site_login_url(
            (string)($_SERVER['SCRIPT_NAME'] ?? ''),
            (string)($_SERVER['REQUEST_URI'] ?? '')
        ));
    }
    require_once dirname(__DIR__) . '/includes/artdon_sso_core.php';
    return artdon_sso_require_page('bom');
}
