<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$api = file_get_contents($root . '/miniapp/api/index.php');
$lib = file_get_contents($root . '/includes/crm_visit_followup_log.php');
$visit = file_get_contents($root . '/crm_visit.php');
$mini = file_get_contents($root . '/includes/crm_visit_miniapp.php');
$migrate = file_get_contents($root . '/miniapp/migrate.php');
if (in_array(false, [$api, $lib, $visit, $mini, $migrate], true)) {
    throw new RuntimeException('visit child followup 源文件不可读');
}

if (strpos($lib, 'crm_visit_row(') !== false) {
    throw new RuntimeException('契约失败：跟进子模块不得调用 crm_visit_row（防详情递归）');
}
if (strpos($lib, 'crm_visit_followup_log_parent_context') === false) {
    throw new RuntimeException('契约失败：轻量父单校验 crm_visit_followup_log_parent_context');
}

foreach ([
    [$lib, 'visit_followup_logs', '跟进子表'],
    [$lib, 'parent_visit_id', '父拜访 id'],
    [$lib, 'followup_log_id', '附件关联列'],
    [$lib, 'crm_visit_supports_child_followup_logs', '仅拜访/来访可挂子跟进'],
    [$lib, 'crm_visit_files_exclude_followup_logs', '父单附件排除子跟进图'],
    [$visit, 'followup_records', '详情返回 followup_records'],
    [$visit, 'crm_visit_followup_log.php', 'CRM 加载跟进子模块'],
    [$api, "case 'visit_followup_list'", '列表 action'],
    [$api, "case 'visit_followup_save'", '保存 action'],
    [$api, "case 'visit_followup_delete'", '删除 action'],
    [$api, "case 'visit_followup_attachment_upload'", '图片上传 action'],
    [$api, 'followup_records', 'visit 详情 followup_records'],
    [$api, 'visit_followup', 'visit_followup 附件分类'],
    [$mini, "'visit_followup'", 'photo_category visit_followup'],
    [$migrate, 'visit_followup_logs', 'migrate 建表'],
] as [$src, $needle, $label]) {
    if (strpos($src, $needle) === false) {
        throw new RuntimeException('契约失败：' . $label);
    }
}

require_once $root . '/includes/crm_visit_miniapp.php';
if (crm_visit_photo_category_to_file_role('visit_followup') !== 'visit_followup') {
    throw new RuntimeException('visit_followup photo_category 映射不对');
}
if (crm_visit_file_role_to_photo_category('visit_followup') !== 'visit_followup') {
    throw new RuntimeException('visit_followup file_role 映射不对');
}

echo "miniapp_visit_child_followup_contract: OK\n";
