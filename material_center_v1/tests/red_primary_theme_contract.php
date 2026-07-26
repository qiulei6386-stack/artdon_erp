<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$tokens = strtolower(file_get_contents($root.'/ui/tokens.css'));
$legacy = strtolower(file_get_contents($root.'/assets/css/app.css'));
$helper = strtolower(file_get_contents($root.'/app/Support/helpers.php'));
$migration = strtolower(file_get_contents($root.'/database/migrations/20260726_014_red_primary_theme.php'));

foreach ([$tokens, $legacy, $helper, $migration] as $source) {
    if (!str_contains($source, '#d60000')) throw new RuntimeException('global red primary token missing');
}
foreach (['--ui-primary: #d60000','--ui-primary-hover: #b00000','--ui-primary-soft: #fdecec'] as $marker) {
    if (!str_contains($tokens, $marker)) throw new RuntimeException("new UI red palette missing: $marker");
}
foreach (['--primary:#d60000','--primary-hover:#b00000','--selection:#fdecec'] as $marker) {
    if (!str_contains($legacy, $marker)) throw new RuntimeException("legacy UI red palette missing: $marker");
}
$oldPrimary = '#'.'0f8f9d';
$oldFallback = '#'.'087f8c';
if (str_contains($tokens, $oldPrimary) || str_contains($legacy, $oldPrimary) || str_contains($helper, $oldFallback)) {
    throw new RuntimeException('teal primary fallback remains active');
}
foreach (['mc_ui_settings','mc_ui_setting_scopes','mc_ui_themes'] as $table) {
    if (!str_contains($migration, $table)) throw new RuntimeException("production theme migration missing: $table");
}

echo "Material Center global red primary theme contract passed.\n";
