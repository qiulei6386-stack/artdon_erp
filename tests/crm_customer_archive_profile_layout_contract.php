<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$crmJs = file_get_contents($root . '/assets/crm/crm.js');
$crmCss = file_get_contents($root . '/assets/crm/crm.css');

if ($crmJs === false || $crmCss === false) {
    fwrite(STDERR, "Cannot read CRM customer archive profile files\n");
    exit(1);
}

$required = [
    '档案页使用新头像档案头' => 'archive-crm-hero',
    '档案页 KPI 保留联系人' => '<span>联系人</span><strong>\' + esc(contacts.length)',
    '档案页 KPI 保留客户群' => '<span>客户群</span><strong>\' + esc(chatGroups.length)',
    '档案页 KPI 保留报价跳转' => 'data-summary-jump="quote"',
    '档案页 KPI 保留跟进跳转' => 'data-summary-jump="followups"',
    '保留编辑档案按钮事件' => 'data-archive-edit',
    '保留保存档案按钮事件' => 'data-archive-save',
    '保留取消修改按钮事件' => 'data-archive-cancel',
    '保留补全缺失按钮事件' => 'data-archive-missing',
    '联系人卡片' => 'archive-crm-contacts',
    '联系人新增复用旧弹窗' => 'data-archive-contact-create',
    '地址卡片' => 'archive-crm-address',
    '地址新增复用旧弹窗' => 'data-archive-address-create',
    '客户群卡片' => 'archive-crm-groups',
    '客户群新增仍用原按钮' => 'data-chat-group-create',
    '标签卡片' => 'archive-crm-tags-card',
    '标签新增复用旧弹窗' => 'data-archive-tag-create',
    '资料完整度卡片' => 'archive-crm-health',
    '名片图片移入档案页' => 'renderBusinessCardGallery(businessCards)',
    '客户属性面板不再额外重复追加名片图库' => "customer_attribute: '<section class=\"customer-tab-panel\" data-detail-panel=\"customer_attribute\">' + this.renderArchiveAttributePanel(data) + '</section>'",
    '新布局网格样式' => '.archive-crm-grid',
    '完整度圆环样式' => '.archive-crm-health-ring',
    '新增联系人绑定' => "bindAll('[data-archive-contact-create]'",
    '新增地址绑定' => "bindAll('[data-archive-address-create]'",
    '新增标签绑定' => "bindAll('[data-archive-tag-create]'",
];

$haystack = $crmJs . "\n" . $crmCss;
foreach ($required as $label => $needle) {
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, "Missing marker: {$label}\n");
        exit(1);
    }
}

echo "crm customer archive profile layout contract ok\n";
