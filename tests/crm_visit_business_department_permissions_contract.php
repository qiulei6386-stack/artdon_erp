<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$visit = file_get_contents($root . '/crm_visit.php');

if ($visit === false) {
    throw new RuntimeException('crm_visit.php is not readable');
}

foreach ([
    ['crm_visit_business_department_permission_keys', '集中定义业务部拜访/来访权限清单'],
    ["'visit.view_department'", '业务部默认拥有本部门拜访/来访查看范围'],
    ["'visit.edit'", '业务部默认可以编辑拜访/来访'],
    ["'visit.result'", '业务部默认可以填写拜访/来访结果'],
    ["'visit.file_upload'", '业务部默认可以上传拜访/来访资料'],
    ["r.role_key IN ('sales','staff')", 'sales/staff 角色继承业务部拜访权限'],
    ['INSERT IGNORE INTO crm_user_permissions', '业务部部门成员自动补个人 allow 权限且不覆盖 deny'],
    ["d.name = '业务部' OR d.code = 'sales'", '只按业务部部门名称或 sales 部门代码自动授权'],
    ['vc.id = {$alias}.created_by', '本部门范围包含本部门创建的拜访/来访记录'],
    ['vu.id = {$alias}.owner_user_id', '本部门范围包含本部门负责的拜访/来访记录'],
] as [$needle, $label]) {
    if (!str_contains($visit, $needle)) {
        throw new RuntimeException('缺少：' . $label);
    }
}

echo "crm_visit_business_department_permissions_contract: OK\n";
