<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$bootstrap = file_get_contents($root.'/bootstrap.php');
$permissionService = file_get_contents($root.'/app/Security/PermissionService.php');
$migration = file_get_contents($root.'/database/migrations/20260726_013_unified_permission_center.php');
$settings = file_get_contents($root.'/settings/index.php');
$legacyApi = file_get_contents($root.'/api/v1/permissions.php');

foreach ([
    "PHP_SAPI !== 'cli'",
    "has_permission('material_center.view')",
    "'AUTH_REQUIRED'",
    "'PERMISSION_DENIED'",
    "/login.php",
] as $marker) {
    if (!str_contains($bootstrap, $marker)) throw new RuntimeException("unified entry guard missing: $marker");
}

foreach (['crm_user_permissions', 'crm_role_permissions', 'current_user', 'has_permission'] as $marker) {
    if (!str_contains($permissionService, $marker)) throw new RuntimeException("unified permission source missing: $marker");
}
foreach (['mc_permission_grants', "'bom.view'", "'bom.edit'"] as $forbidden) {
    if (str_contains($permissionService, $forbidden)) throw new RuntimeException("legacy permission fallback remains: $forbidden");
}

$requiredPermissions = [
    'material_center.view',
    'material_center.material.view',
    'material_center.material.create',
    'material_center.material.edit',
    'material_center.material.batch',
    'material_center.purchase_price.view',
    'material_center.purchase_price.edit',
    'material_center.permissions.manage',
];
foreach ($requiredPermissions as $permission) {
    if (!str_contains($migration, $permission)) throw new RuntimeException("unified permission migration missing: $permission");
}
if (str_contains($settings, 'new PermissionAdminService')) throw new RuntimeException('independent permission admin remains active');
if (!str_contains($legacyApi, 'USE_UNIFIED_PERMISSION_CENTER')) throw new RuntimeException('legacy permission API is not retired');

echo "Material Center unified account and permission contract passed.\n";
