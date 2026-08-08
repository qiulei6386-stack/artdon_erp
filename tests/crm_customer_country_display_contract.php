<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$crmJs = file_get_contents($root . '/assets/crm/crm.js');
$customer = file_get_contents($root . '/crm_customer.php');

if ($crmJs === false || $customer === false) {
    fwrite(STDERR, "Cannot read CRM customer country display files\n");
    exit(1);
}

$required = [
    '国家中文显示函数' => 'function crm_customer_country_display_name',
    '客户列表返回国家原值' => "\$row['country_raw']",
    '客户列表返回国家中文显示值' => "\$row['country_display']",
    '国家显示读取字典' => "crm_dictionary_items('country_region', false)",
    '国家显示兼容别名' => 'crm_customer_country_aliases()',
    '前端国家标签支持原值' => 'function countryLabel(value, rawValue)',
    '前端国家列优先显示中文字段' => 'var displayCountry = row.country_display || row.country ||',
    '前端国家旗帜继续使用原值' => 'countryLabel(displayCountry, rawCountry)',
    '阿联酋代码兼容' => "'AE' => ['AE', 'UAE', 'United Arab Emirates'",
    '卡塔尔代码兼容' => "'QA' => ['QA', 'Qatar', '卡塔尔']",
    '阿曼代码兼容' => "'OM' => ['OM', 'Oman', '阿曼']",
    '印度代码兼容' => "'IN' => ['IN', 'India', '印度']",
];

$haystack = $customer . "\n" . $crmJs;
foreach ($required as $label => $needle) {
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, "Missing marker: {$label}\n");
        exit(1);
    }
}

echo "crm customer country display contract ok\n";
