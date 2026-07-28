<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$service = file_get_contents($root.'/material_center_v1/app/Services/AdaptationService.php');
$api = file_get_contents($root.'/material_center_v1/api/v1/adaptation.php');
$page = file_get_contents($root.'/material_center_v1/adaptation/index.php');
$js = file_get_contents($root.'/material_center_v1/assets/js/adaptation-shell.js');
$migration = file_get_contents($root.'/material_center_v1/database/migrations/20260728_019_adaptation_reuse_templates.php');

foreach ([$service, $api, $page, $js, $migration] as $content) {
    if ($content === false) throw new RuntimeException('Reusable adaptation template file is unreadable.');
}

foreach ([
    'mc_adaptation_reuse_templates',
    'source_group_ids_json JSON NOT NULL',
    'include_power_rule TINYINT(1) NOT NULL DEFAULT 0',
] as $marker) {
    if (!str_contains($migration, $marker)) throw new RuntimeException("Migration marker missing: {$marker}");
}

foreach ([
    'public function reuseTemplates()',
    'public function saveReuseTemplate(array $data, int $userId): array',
    'public function previewReuseTemplate(int $templateId, array $targetProductIds, string $mode): array',
    'public function applyReuseTemplate(int $templateId, array $targetProductIds, string $mode, int $userId): array',
    'public function disableReuseTemplate(int $templateId, int $userId): void',
    'selectedSourceGroups((int) $template[\'source_product_id\'], $template[\'source_group_ids\'])',
] as $marker) {
    if (!str_contains($service, $marker)) throw new RuntimeException("Service marker missing: {$marker}");
}

foreach (['reuse_templates', 'save_reuse_template', 'preview_reuse_template', 'apply_reuse_template', 'disable_reuse_template'] as $marker) {
    if (!str_contains($api, "'{$marker}'")) throw new RuntimeException("API action missing: {$marker}");
}

foreach (['data-reuse-template-open', 'data-selected-reuse-template', 'data-reuse-template-form'] as $marker) {
    if (!str_contains($page, $marker)) throw new RuntimeException("Template page marker missing: {$marker}");
}

foreach (['data-reuse-template-open', 'data-selected-reuse-template', 'data-reuse-template-form', 'data-use-reuse-template', 'data-disable-reuse-template', 'batchReuseTemplate'] as $marker) {
    if (!str_contains($js, $marker)) throw new RuntimeException("Template client marker missing: {$marker}");
}

foreach (['batchReuseTemplate', "'preview_reuse_template'", "'apply_reuse_template'", 'source_group_ids: groupIds'] as $marker) {
    if (!str_contains($js, $marker)) throw new RuntimeException("Template mapping behavior missing: {$marker}");
}

echo "Adaptation reusable template contract: OK\n";
