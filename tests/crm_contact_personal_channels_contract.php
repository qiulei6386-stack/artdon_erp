<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$customer = file_get_contents($root . '/crm_customer.php');
$settings = file_get_contents($root . '/crm_settings_config.php');
$crmPage = file_get_contents($root . '/crm.php');
$crmJs = file_get_contents($root . '/assets/crm/crm.js');

if ($customer === false || $settings === false || $crmPage === false || $crmJs === false) {
    fwrite(STDERR, "Cannot read CRM contact personal channel files\n");
    exit(1);
}

$haystack = $customer . "\n" . $settings . "\n" . $crmPage . "\n" . $crmJs;
$checks = [
    '保留微信群渠道枚举' => "wechat_group','whatsapp_group",
    '保留微信群快捷筛选' => "'has_wechat_group' => '微信群'",
    '保留 WhatsApp 群快捷筛选' => "'has_whatsapp_group' => 'WhatsApp群'",
    '新增微信个人快捷筛选' => "'has_wechat_personal' => '微信个人'",
    '新增 WhatsApp 个人快捷筛选' => "'has_whatsapp_personal' => 'WhatsApp个人'",
    '微信个人筛选读取联系人微信字段' => "ct.wechat IS NOT NULL AND ct.wechat <> ''",
    'WhatsApp 个人筛选读取联系人 WhatsApp 字段' => "ct.whatsapp IS NOT NULL AND ct.whatsapp <> ''",
    '联系人新增只接受个人字段' => "prefer_personal_contact TINYINT(1) NOT NULL DEFAULT 0",
    '联系人新增不喜欢群字段' => "avoid_group_contact TINYINT(1) NOT NULL DEFAULT 0",
    '联系人保存只接受个人字段' => "'prefer_personal_contact' => !empty(\$input['prefer_personal_contact']) ? 1 : 0",
    '联系人保存不喜欢群字段' => "'avoid_group_contact' => !empty(\$input['avoid_group_contact']) ? 1 : 0",
    '新增联系人 SQL 写入个人偏好' => 'prefer_personal_contact, avoid_group_contact',
    '编辑联系人 SQL 更新个人偏好' => 'prefer_personal_contact=?, avoid_group_contact=?',
    '前端 WhatsApp 个人标签' => 'WhatsApp个人',
    '前端微信个人标签' => '微信个人',
    '前端只接受个人联系勾选' => 'data-contact-prefer-personal',
    '前端不喜欢群勾选' => 'data-contact-avoid-group',
    '渠道字典同步个人标签' => 'crm_sync_promotion_channel_personal_labels',
];

$failed = [];
foreach ($checks as $label => $needle) {
    if (strpos($haystack, $needle) === false) $failed[] = $label;
}

if ($failed) {
    fwrite(STDERR, "crm contact personal channels contract failed:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "crm contact personal channels contract passed\n";
