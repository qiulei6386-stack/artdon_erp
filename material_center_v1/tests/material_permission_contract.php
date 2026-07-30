<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$repo = dirname($root);
$read = static fn(string $path): string => file_get_contents($path);

$permissionService = $read($root.'/app/Security/PermissionService.php');
$materialMasterApi = $read($root.'/api/v1/material-master.php');
$materialsApi = $read($root.'/api/v1/materials.php');
$sourceApi = $read($root.'/api/v1/source-material.php');
$sourceService = $read($root.'/app/Services/SourceMaterialOrganizerService.php');
$categoryFieldsApi = $read($root.'/api/v1/category-fields.php');
$powerService = $read($root.'/app/Services/PowerEditorService.php');
$powerJs = $read($root.'/assets/js/power-editor.js');
$migration = $read($root.'/database/migrations/20260726_013_unified_permission_center.php');
$permissionCenter = $read($repo.'/permissions.php');

foreach ([
    'function allowsAny',
    'function requireAny',
    'function canFormalize',
    'function materialTransitionPermissions',
    'function requireMaterialTransition',
    'material_center.material.formalize',
    'material_center.approve',
    'material_center.power.confirm',
    'material_center.material.lifecycle',
] as $marker) {
    if (!str_contains($permissionService, $marker)) {
        throw new RuntimeException("unified material permission service missing marker: {$marker}");
    }
}

foreach ([
    'approve' => 'material_center.material.formalize',
    'disable' => 'material_center.material.disable',
    'archive' => 'material_center.material.archive',
    'delete_draft' => 'material_center.material.delete_draft',
] as $transition => $permission) {
    if (!preg_match('/'.preg_quote("'{$transition}'", '/').'.*?'.preg_quote($permission, '/').'/s', $permissionService)) {
        throw new RuntimeException("material transition {$transition} is not bound to {$permission}");
    }
}

foreach ([
    $materialMasterApi => ['requireMaterialTransition($user,$action)', "requireMaterialTransition($user,'delete_draft')", 'material_center.material.copy'],
    $materialsApi => ['requireMaterialTransition($user,(string)$_POST[\'transition\'])'],
    $sourceApi => ["requireMaterialTransition($user, 'approve')", "requireMaterialTransition($user, 'submit')"],
    $sourceService => ['canFormalize($user)'],
    $categoryFieldsApi => ['canFormalize($user)'],
    $powerService => ['canFormalize($user)'],
    $powerJs => ["activeRecord?.status === 'pending_review'", "lifecycleRequest(activeRecord.material_id || form.elements.material_id.value, 'approve')"],
] as $content => $markers) {
    foreach ($markers as $marker) {
        if (!str_contains($content, $marker)) {
            throw new RuntimeException("material permission linkage missing: {$marker}");
        }
    }
}

preg_match_all('/material_center\.[a-z0-9_.]+/', implode("\n", [
    $permissionService,
    $materialMasterApi,
    $materialsApi,
    $sourceApi,
    $sourceService,
    $categoryFieldsApi,
    $powerService,
    $read($root.'/api/v1/adaptation.php'),
    $read($root.'/api/v1/chip-specifications.php'),
    $read($root.'/api/v1/documents.php'),
    $read($root.'/api/v1/imports.php'),
    $read($root.'/api/v1/power-editor.php'),
    $read($root.'/api/v1/power-standardization.php'),
    $read($root.'/api/v1/product-power-rules.php'),
    $read($root.'/api/v1/settings.php'),
    $read($root.'/api/v1/source-sync.php'),
    $read($root.'/api/v1/substitutions.php'),
    $read($root.'/api/v1/suppliers.php'),
]), $matches);
$used = array_values(array_unique($matches[0]));
sort($used);
foreach ($used as $permission) {
    if (!str_contains($migration, $permission)) {
        throw new RuntimeException("material permission used by code but missing unified migration: {$permission}");
    }
}

foreach ([
    "'material_center'",
    "'label' => '物料中心'",
    "'prefixes' => ['material_center.']",
    "'domains' => ['material_center']",
    'permission_system_keys($systemKey)',
    'permission_preset_keys($systemKey, $presetKey, $perms)',
] as $marker) {
    if (!str_contains($permissionCenter, $marker)) {
        throw new RuntimeException("permission center material binding missing: {$marker}");
    }
}

echo "Material Center permission linkage contract: OK\n";
