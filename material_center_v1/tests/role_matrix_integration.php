<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/bootstrap.php';

use Artdon\MaterialCenter\Security\MaterialCenterUserContext;
use Artdon\MaterialCenter\Security\PermissionService;

$db = db();
$tag = 'mc_unified_test_'.bin2hex(random_bytes(4));
$testUser = 900000000 + random_int(1, 999999);
$roles = [
    'material' => ['material_center.view', 'material_center.material.edit', 'material_center.material.batch'],
    'engineering' => ['material_center.view', 'material_center.adaptation.manage'],
    'purchasing' => ['material_center.view', 'material_center.supplier.manage', 'material_center.purchase_price.view'],
    'business_readonly' => ['material_center.view'],
];

try {
    $insertRole = $db->prepare("INSERT INTO crm_roles(role_key,role_name,description,is_system,status,created_at,updated_at) VALUES(?,?,?,0,'active',NOW(),NOW())");
    $insertGrant = $db->prepare('INSERT INTO crm_role_permissions(role_id,permission_key) VALUES(?,?)');
    foreach ($roles as $role => $permissions) {
        $roleKey = $tag.'_'.$role;
        $insertRole->execute([$roleKey, $roleKey, 'temporary unified material permission test']);
        $roleId = (int) $db->lastInsertId();
        foreach ($permissions as $permission) $insertGrant->execute([$roleId, $permission]);
    }

    $service = new PermissionService($db);
    $admin = new MaterialCenterUserContext(1, 'admin', 'Admin', 'admin', true);
    if (!$service->allows($admin, 'material_center.permissions.manage')) throw new RuntimeException('administrator permission failed');

    foreach ($roles as $role => $permissions) {
        $context = new MaterialCenterUserContext($testUser, 'test', 'Test', $tag.'_'.$role, false);
        foreach ($permissions as $permission) {
            if (!$service->allows($context, $permission)) throw new RuntimeException("$role missing $permission");
        }
        if ($role === 'business_readonly' && $service->allows($context, 'material_center.material.edit')) throw new RuntimeException('business readonly can edit');
        if ($role === 'engineering' && $service->allows($context, 'material_center.purchase_price.view')) throw new RuntimeException('engineering can view price');
        if ($role === 'purchasing' && $service->allows($context, 'material_center.permissions.manage')) throw new RuntimeException('purchasing can manage permissions');
    }

    $none = new MaterialCenterUserContext($testUser + 1, 'none', 'None', $tag.'_none', false);
    if ($service->allows($none, 'material_center.view')) throw new RuntimeException('no-access role can view');
    echo "unified administrator/material/engineering/purchasing/readonly/no-access roles: OK\n";
} finally {
    $roleIds = $db->prepare('SELECT id FROM crm_roles WHERE role_key LIKE ?');
    $roleIds->execute([$tag.'%']);
    $ids = array_map('intval', $roleIds->fetchAll(PDO::FETCH_COLUMN));
    if ($ids) {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $db->prepare("DELETE FROM crm_role_permissions WHERE role_id IN ($marks)")->execute($ids);
        $db->prepare("DELETE FROM crm_roles WHERE id IN ($marks)")->execute($ids);
    }
}
