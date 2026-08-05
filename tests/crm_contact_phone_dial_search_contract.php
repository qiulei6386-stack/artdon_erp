<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$js = file_get_contents($root . '/assets/crm/crm.js');
$css = file_get_contents($root . '/assets/crm/crm.css');
if ($js === false || $css === false) {
    throw new RuntimeException('CRM phone dial search sources are not readable');
}

$requiredJs = [
    'data-phone-dial-search',
    'placeholder="搜索 +86 / CN / 中国"',
    'phoneDialSearchTerm: function',
    'rememberPhoneDialOptions: function',
    'filterPhoneDialSelect: function',
    'bindPhoneDialSearch: function',
    'this.bindPhoneDialSearch(body);',
    'this.bindPhoneDialSearch(box);',
    'label.indexOf(term) >= 0',
    'numeric.indexOf(numericTerm) >= 0',
    "event.key === 'Escape'",
];
foreach ($requiredJs as $marker) {
    if (!str_contains($js, $marker)) {
        throw new RuntimeException("CRM phone dial search JS marker missing: {$marker}");
    }
}

if (substr_count($js, 'data-phone-dial-search') < 2) {
    throw new RuntimeException('phone and WhatsApp dial controls must both render searchable dial inputs');
}
if (!str_contains($js, 'this.phoneLine(\'☎\', \'电话\'')) {
    throw new RuntimeException('contact phone line must still use the shared phone composite control');
}
if (!str_contains($js, "this.phoneLine('W', 'WhatsApp'")) {
    throw new RuntimeException('contact WhatsApp line must still use the shared phone composite control');
}
if (str_contains($js, '<select data-phone-dial aria-label="') && !str_contains($js, 'data-phone-dial-picker')) {
    throw new RuntimeException('phone dial select must be wrapped by the searchable picker');
}

$requiredCss = [
    '.phone-dial-picker',
    'grid-template-columns: minmax(230px, 280px) minmax(0, 1fr);',
    'input[data-phone-dial-search]',
    '.contact-mobile-dialog .phone-dial-picker',
];
foreach ($requiredCss as $marker) {
    if (!str_contains($css, $marker)) {
        throw new RuntimeException("CRM phone dial search CSS marker missing: {$marker}");
    }
}

echo "CRM contact phone dial search contract passed\n";
