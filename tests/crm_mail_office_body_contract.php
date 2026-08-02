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
require_contract(str_contains($js, 'function mailPlainReadableBody(mail, note)'), 'missing generic readable full-html fallback');
require_contract(str_contains($js, 'var readableBody = isOfficeMail ? officeMailReadableBody(mail) : (!isDbsAdvice && isFullHtml ? mailPlainReadableBody(mail, \'已使用邮件可读正文\') : \'\');'), 'mail detail must use readable Office fallback');
require_contract(str_contains($js, 'var readableBody = isOfficeMail && typeof officeMailReadableBody === \'function\''), 'customer preview must use readable Office fallback');
require_contract(str_contains($js, 'isFullHtml ? mailPlainReadableBody(mail, \'已使用邮件可读正文\')'), 'mail detail must use generic readable full-html fallback');
require_contract(str_contains($js, 'useFrame ? mailPlainReadableBody(mail, \'已使用邮件可读正文\')'), 'customer preview must use generic readable full-html fallback');
require_contract(str_contains($js, 'isOutlookOfficeMail: function (mail)'), 'mail module must expose Office detection');
require_contract(str_contains($css, '.mail-office-readable'), 'missing readable Office mail styles');

echo "PASS: Office / Outlook mail body readability contract\n";
