<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$permission = file_get_contents($root . '/includes/permission.php');
$sso = file_get_contents($root . '/includes/artdon_sso_core.php');
$plm = file_get_contents($root . '/plm.php');

$required = [
    $permission => [
        "'plm.delete_file' => ['plm.delete']",
    ],
    $sso => [
        "if (\$module === 'plm')",
        'artdon_plm_ensure_permissions();',
        "'delete_file' => \$delete",
        "'delete_test' => \$delete",
        "'delete_issue' => \$delete",
    ],
    $plm => [
        "artdon_perm_module_has_explicit(\$centralId,'plm')",
        'artdon_perm_effective_feature_map($centralId,\'plm\'',
        "plm_v85_require_perm('delete_file','删除文件')",
    ],
];

foreach ($required as $source => $markers) {
    foreach ($markers as $marker) {
        if (!str_contains($source, $marker)) {
            throw new RuntimeException("PLM unified permission contract missing marker: {$marker}");
        }
    }
}

echo "PLM unified permission contract passed.\n";
