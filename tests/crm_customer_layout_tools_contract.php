<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$crmPage = file_get_contents($root . '/crm.php');
$crmCss = file_get_contents($root . '/assets/crm/crm.css');
$crmJs = file_get_contents($root . '/assets/crm/crm.js');

if ($crmPage === false || $crmCss === false || $crmJs === false) {
    fwrite(STDERR, "Cannot read CRM customer layout tool files\n");
    exit(1);
}

$required = [
    '客户中心常驻布局工具条' => 'customer-center-layout-tools',
    '列表全屏按钮' => 'data-customer-layout="list"',
    '属性全屏按钮' => 'data-customer-layout="detail"',
    '还原布局按钮' => 'data-customer-layout="default"',
    '列表全屏文案' => '列表全屏',
    '属性全屏文案' => '属性全屏',
    '还原文案' => '还原',
    '布局按钮事件绑定' => "querySelectorAll('[data-customer-layout]')",
    '列表全屏布局类' => "split.classList.toggle('is-list-full', mode === 'list')",
    '属性全屏布局类' => "split.classList.toggle('is-detail-full', mode === 'detail')",
    '初始化应用已保存布局' => 'this.applyLayoutMode(this.layoutMode, false)',
    '工具条内联靠右显示' => '.customer-search-tools .customer-center-layout-tools { flex: 0 0 auto; align-self: center; margin-left: auto;',
];

$haystack = $crmPage . "\n" . $crmCss . "\n" . $crmJs;
foreach ($required as $label => $needle) {
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, "Missing marker: {$label}\n");
        exit(1);
    }
}

echo "crm customer layout tools contract ok\n";
