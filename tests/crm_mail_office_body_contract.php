<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$js = (string)file_get_contents($root . '/assets/crm/crm.js');
$css = (string)file_get_contents($root . '/assets/crm/crm.css');

function require_contract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

require_contract(str_contains($js, 'function isOutlookOfficeMailHtml(html)'), 'missing Office / Outlook HTML detector');
require_contract(str_contains($js, 'function officeMailReadableBody(mail)'), 'missing readable Office mail fallback');
require_contract(substr_count($js, 'isOfficeMail && mail.body_text ? officeMailReadableBody(mail)') >= 2, 'mail and customer preview must both use readable Office fallback');
require_contract(str_contains($js, 'isOutlookOfficeMail: function (mail)'), 'mail module must expose Office detection');
require_contract(str_contains($css, '.mail-office-readable'), 'missing readable Office mail styles');

echo "PASS: Office / Outlook mail body readability contract\n";
