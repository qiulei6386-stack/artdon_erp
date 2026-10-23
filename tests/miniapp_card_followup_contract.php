<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$visit = file_get_contents($root . '/crm_visit.php');
$customer = file_get_contents($root . '/crm_customer.php');
$api = file_get_contents($root . '/miniapp/api/index.php');
$lib = file_get_contents($root . '/includes/crm_visit_miniapp.php');
if (in_array(false, [$visit, $customer, $api, $lib], true)) {
    throw new RuntimeException('小程序名片/跟进扩展源文件不可读');
}

foreach ([
    [$lib, 'function crm_visit_type_codes', 'visit type 列表'],
    [$lib, "'video_meeting'", '视频会议类型'],
    [$lib, "'daily_followup'", '平时跟进类型'],
    [$lib, 'communication_content', '沟通内容字段'],
    [$lib, 'followup_progress', '跟进进度字段'],
    [$visit, 'crm_visit_normalize_type', '拜访保存归一化类型'],
    [$visit, 'background_check_note', '背调备注列'],
    [$visit, 'file_role', '拜访附件角色列'],
    [$customer, 'business_card_front', '联系人名片正面 category'],
    [$customer, 'business_card_back', '联系人名片背面 category'],
    [$customer, 'crm_customer_attach_contact_business_cards', '联系人名片挂载'],
    [$customer, 'contact_id', '客户文件 contact_id 列'],
    [$api, 'customer_business_card_upload', '背面免 OCR 上传'],
    [$api, 'card_side', '名片正反面 card_side'],
    [$api, 'progress_update', '跟进进度 progress_update'],
    [$api, 'participants_text', '参会人 participants_text'],
    [$api, "'progress'", '进度附件 photo_category'],
    [$api, 'visit_attachment_upload', '沟通/进度/背调附件'],
    [$api, 'contact_save', '联系人保存'],
    [$api, 'business_card_back_token', '背面名片 token'],
    [$api, 'communication_content', 'API 沟通内容'],
    [$api, 'visit_types', '拜访类型元数据'],
    [$api, 'mx_normalize_visit_type_input', '小程序 visit_type 归一化'],
] as [$src, $needle, $label]) {
    if (strpos($src, $needle) === false) {
        throw new RuntimeException('契约失败：' . $label);
    }
}

require_once $root . '/includes/crm_visit_miniapp.php';
$codes = crm_visit_type_codes();
if ($codes !== ['customer_visit', 'customer_arrival', 'video_meeting', 'daily_followup']) {
    throw new RuntimeException('visit type codes 顺序或内容不对');
}
if (crm_visit_normalize_type('invalid') !== 'customer_visit') {
    throw new RuntimeException('非法 visit_type 应回退 customer_visit');
}
if (crm_visit_normalize_type('video_meeting') !== 'video_meeting') {
    throw new RuntimeException('video_meeting 应保留');
}
$pub = crm_visit_miniapp_meta_public([
    'communication_content' => '沟通正文',
    'followup_progress' => '进度 50%',
    'participant_names' => '张三,李四',
    'participant_count' => 2,
], ['background_check_note' => '背调说明']);
if ($pub['communication_content'] !== '沟通正文' || $pub['text_record'] !== '沟通正文') {
    throw new RuntimeException('沟通内容/别名映射不对');
}
if ($pub['progress_update'] !== '进度 50%' || $pub['followup_progress'] !== '进度 50%') {
    throw new RuntimeException('progress_update 映射不对');
}
if ($pub['background_check_notes'] !== '背调说明' || $pub['background_check_note'] !== '背调说明') {
    throw new RuntimeException('背调字段不对');
}
if ($pub['participants_text'] !== '张三,李四' || $pub['participant_count'] !== 2) {
    throw new RuntimeException('参会人字段不对');
}

echo "miniapp card/followup contract OK\n";
