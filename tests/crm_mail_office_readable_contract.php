<?php
declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/assets/crm/crm.js');
if ($source === false) throw new RuntimeException('crm.js is not readable');

foreach ([
    'function officeMailTextHasHtmlMarkup(text)',
    "<\\/?(?:html|head|body|div|span|font|p|br|table|tr|td|style|meta|link|img|h[1-6]|ul|ol|li|blockquote|section|article|o:[a-z]+|v:[a-z]+|w:[a-z]+)\\b",
    '邮件正文里常见 <name@example.com>、<https://...> 这种地址包裹；它们不是',
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
