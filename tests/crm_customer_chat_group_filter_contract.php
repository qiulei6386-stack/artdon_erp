<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$crmPage = file_get_contents($root . '/crm.php');
$crmJs = file_get_contents($root . '/assets/crm/crm.js');
$customer = file_get_contents($root . '/crm_customer.php');

if ($crmPage === false || $crmJs === false || $customer === false) {
    fwrite(STDERR, "Cannot read CRM customer files\n");
    exit(1);
}

$required = [
    '客户中心微信群快捷筛选按钮' => "'has_wechat_group' => '微信群'",
    '客户中心 WhatsApp 群快捷筛选按钮' => "'has_whatsapp_group' => 'WhatsApp群'",
    '前端微信群筛选文案' => "has_wechat_group: '微信群'",
    '前端 WhatsApp 群筛选文案' => "has_whatsapp_group: 'WhatsApp群'",
    '后端微信群 quick_filter' => "\$quick === 'has_wechat_group' || \$quick === '微信群'",
    '后端 WhatsApp 群 quick_filter' => "\$quick === 'has_whatsapp_group' || \$quick === 'WhatsApp群'",
    '微信群平台条件' => "cg.group_platform = 'wechat_group'",
    'WhatsApp群平台条件' => "cg.group_platform = 'whatsapp_group'",
    '客户群未删除条件' => 'cg.deleted_at IS NULL',
    '客户群启用条件' => "cg.status = 'active'",
];

$haystack = $crmPage . "\n" . $crmJs . "\n" . $customer;
foreach ($required as $label => $needle) {
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, "Missing marker: {$label}\n");
        exit(1);
    }
}

echo "crm customer chat group filter contract ok\n";
