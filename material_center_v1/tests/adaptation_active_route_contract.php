<?php
declare(strict_types=1);
// Read-only contracts for the active V2 route; never bootstrap either application.
function adaptation_active_contract(string $area): void
{
    $root = dirname(__DIR__);
    $legacy = file_get_contents($root . '/adaptation/index.php');
    $redirect = strstr($legacy, 'exit;', true);
    foreach (['adaptation_v2/index.php?', 'http_build_query($v2Params)', "'product_id' => \$legacyProductId", "'view' => 'templates'"] as $marker) {
        if ($redirect === false || !str_contains($redirect, $marker)) throw new RuntimeException('Legacy context redirect missing: ' . $marker);
    }
    $page = file_get_contents($root . '/adaptation_v2/index.php');
    $api = file_get_contents($root . '/adaptation_v2/api/index.php');
    $service = file_get_contents($root . '/adaptation_v2/lib/foundation.php');
    $contracts = [
        'rules' => [
            'page' => ['打开规则编辑器', 'action=group_save', 'action=group_behavior_save', '$canManageRule', 'pa2_detect_rule_cycles($rules)'],
            'api' => ["\$action === 'rule_save'", 'pa2_upsert_rule(pa2_request_data())'],
            'service' => ["pa2_require_any(['adaptation_v2.manage_rule'", 'function pa2_detect_rule_cycles', 'function pa2_upsert_rule', 'material_filter_json'],
        ],
        'templates' => [
            'page' => ['action=template_save', 'action=template_group_save', '$canManageTemplate', '$canPublishTemplate', 'pa2_template_effective_groups'],
            'api' => ["\$action === 'template_publish'", 'pa2_publish_template', "\$action === 'template_preview'"],
            'service' => ["pa2_require_any(['adaptation_v2.publish_template'", "if (!\$preview['groups']) throw", 'INSERT INTO mc_pa2_template_versions', 'snapshot_json', 'function pa2_template_ancestry'],
        ],
        'workspace' => [
            'page' => ["'products'", "'workspace'", 'pa2_workspace_detail($workspaceProductId)', '$canConfigureProduct', '$canApproveProduct', '$canPublishProduct'],
            'api' => ["\$action === 'workspace'", 'if ($productId <= 0) throw', "\$action === 'product_version_approve'", "\$action === 'product_version_publish'"],
            'service' => ['function pa2_workspace_detail', 'function pa2_evaluate_material_candidate_for_group', 'function pa2_product_version_approve', 'function pa2_product_version_publish'],
        ],
    ];
    if (!isset($contracts[$area])) throw new RuntimeException('Unknown contract area');
    foreach ($contracts[$area] as $file => $markers) {
        $source = ${$file};
        foreach ($markers as $marker) {
            $verify = static function (string $candidate) use ($marker): bool { return str_contains($candidate, $marker); };
            if (!$verify($source)) throw new RuntimeException("Active V2 {$area}/{$file} missing: {$marker}");
            if ($verify(str_replace($marker, '', $source))) throw new RuntimeException('Negative contract failed');
        }
    }
}
