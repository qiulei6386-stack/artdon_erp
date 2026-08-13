<?php
declare(strict_types=1);

$page = file_get_contents(dirname(__DIR__) . '/dispatch_next.php');
if ($page === false) {
    fwrite(STDERR, "Cannot read dispatch page\n");
    exit(1);
}

$markers = [
    '按稳定来源识别官网询盘' => "function isWebsiteInquiryTask(r){return String(r?.linked_system||'')==='website_inquiry'}",
    '桌面任务行增加官网询盘类' => "isWebsiteInquiryTask(r)?'websiteInquiry':''",
    '手机任务卡增加官网询盘类' => "['mobileTaskCard',dueCls,doneCls,hl,isWebsiteInquiryTask(r)?'websiteInquiry':'',converted?'convertedPersonal':'']",
    '桌面标题红色' => '.tbl tr.websiteInquiry:not(.done):not(.cancelled) td[data-field="title"] .cell-edit',
    '桌面询盘内容红色' => '.tbl tr.websiteInquiry:not(.done):not(.cancelled) td[data-field="project"] .cell-edit',
    '手机标题红色' => '.mobileTaskCard.websiteInquiry:not(.done):not(.cancelled) .mobileTaskTitle',
    '手机询盘内容红色' => '.mobileTaskCard.websiteInquiry:not(.done):not(.cancelled) .mobileTaskProject',
    '亮色红色值' => 'color:#dc2626!important',
    '深色红色值' => 'color:#f87171!important',
];

foreach ($markers as $label => $needle) {
    if (strpos($page, $needle) === false) {
        fwrite(STDERR, "Missing website inquiry red marker: {$label}\n");
        exit(1);
    }
}

if (strpos($page, ".websiteInquiry td{color:") !== false) {
    fwrite(STDERR, "Website inquiry color must not override every task cell\n");
    exit(1);
}

echo "website inquiry dispatch red contract ok\n";
