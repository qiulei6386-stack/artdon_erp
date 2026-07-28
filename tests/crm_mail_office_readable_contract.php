<?php
declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/assets/crm/crm.js');
if ($source === false) throw new RuntimeException('crm.js is not readable');

foreach ([
    'function officeMailTextHasHtmlMarkup(text)',
    'function officeMailToReadableText(source)',
    "new DOMParser().parseFromString(source, 'text/html')",
    "doc.querySelectorAll('script,style,head,meta,link,iframe,object,embed,template,noscript,svg')",
    'Some Office clients put a readable reply first',
    'if (officeMailTextHasHtmlMarkup(text)) text = officeMailToReadableText(text);',
    "if (!text) text = officeMailToReadableText((mail && mail.body_html) || '');",
] as $needle) {
    if (!str_contains($source, $needle)) throw new RuntimeException('Office 邮件可读正文修复缺少标记：' . $needle);
}

echo "crm_mail_office_readable_contract: OK\n";
