<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$css = file_get_contents($root . '/assets/crm/crm.css');
$js = file_get_contents($root . '/assets/crm/crm.js');
if ($css === false || $js === false) {
    fwrite(STDERR, "Cannot read CRM customer profile sources\n");
    exit(1);
}

$markers = [
    [$js, 'customer-detail-shell customer-profile-workbench', '客户属性使用 profile workbench 容器'],
    [$css, ".customer-detail-panel { display: block; overflow: auto;", '右侧客户属性面板为唯一滚动容器'],
    [$css, ".customer-profile-workbench {\n  height: auto;", '客户档案容器不固定高度'],
    [$css, "overflow: visible;\n}\n.customer-profile-head", '客户档案头不截断内部滚动'],
    [$css, ".customer-profile-body {\n  min-height: 0;", '客户档案内容区存在'],
    [$css, "display: block;\n}\n.customer-profile-side", '客户主内容不再分出内部滚动行'],
    [$css, ".customer-main-content {\n  min-height: 0;\n  overflow: visible;", '客户内容随属性面板整体滚动'],
];

foreach ($markers as [$source, $needle, $label]) {
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, "Missing customer profile scroll marker: {$label}\n");
        exit(1);
    }
}

$profileWorkbenchStart = strpos($css, '.customer-profile-workbench {');
$profileHeadStart = strpos($css, '.customer-profile-head {');
$profileBlock = ($profileWorkbenchStart !== false && $profileHeadStart !== false && $profileHeadStart > $profileWorkbenchStart)
    ? substr($css, $profileWorkbenchStart, $profileHeadStart - $profileWorkbenchStart)
    : '';
if (strpos($profileBlock, 'overflow: hidden;') !== false) {
    fwrite(STDERR, "Customer profile workbench must not hide overflow\n");
    exit(1);
}

echo "crm_customer_profile_scroll_contract ok\n";
