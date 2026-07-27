<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$php = file_get_contents($root . '/crm_marketing.php');
$js = file_get_contents($root . '/assets/crm/crm.js');
$css = file_get_contents($root . '/assets/crm/crm.css');
if ($php === false || $js === false || $css === false) {
    throw new RuntimeException('CRM marketing pool source files are not readable');
}

$requiredJs = [
    "if (self.currentView === 'customer_pool')",
    'self.loadPoolView();',
    'data-promo-customer-check-all',
    '全选本页',
    'var currentPageIds = rows.map',
    'selectAll.indeterminate',
    'skip_count: 1',
    "{ loaded_view: 'customer_pool' }",
    '本页 ',
    '还有下一页',
];
foreach ($requiredJs as $marker) {
    if (!str_contains($js, $marker)) {
        throw new RuntimeException("customer pool current-page UI marker missing: {$marker}");
    }
}

$loadPoolStart = strpos($js, 'loadPoolView: function');
$loadPoolEnd = strpos($js, 'loadContactStrategy: function', $loadPoolStart === false ? 0 : $loadPoolStart);
if ($loadPoolStart === false || $loadPoolEnd === false) {
    throw new RuntimeException('loadPoolView function boundaries are missing');
}
$loadPoolSource = substr($js, $loadPoolStart, $loadPoolEnd - $loadPoolStart);
if (str_contains($loadPoolSource, 'self.renderContacts();')) {
    throw new RuntimeException('customer pool refresh must not render or reload the contact strategy list');
}

$poolStart = strpos($php, 'function crm_marketing_pool(array $input = [])');
$poolEnd = strpos($php, 'function crm_marketing_contacts', $poolStart === false ? 0 : $poolStart);
if ($poolStart === false || $poolEnd === false) {
    throw new RuntimeException('crm_marketing_pool function boundaries are missing');
}
$poolSource = substr($php, $poolStart, $poolEnd - $poolStart);
foreach ([
    '$queryLimit = $pageSize;',
    'LIMIT 1 OFFSET {$nextOffset}',
    "'total_is_exact' => \$skipCount ? 0 : 1",
    "'shown_count' => count(\$rows)",
] as $marker) {
    if (!str_contains($poolSource, $marker)) {
        throw new RuntimeException("customer pool bounded-page query marker missing: {$marker}");
    }
}
if (str_contains($poolSource, '$pageSize + 1')) {
    throw new RuntimeException('customer pool must not hydrate an extra full customer row to detect the next page');
}

$viewStart = strpos($php, 'function crm_marketing_pool_view');
$viewEnd = strpos($php, 'function crm_marketing_target_preview', $viewStart === false ? 0 : $viewStart);
if ($viewStart === false || $viewEnd === false) {
    throw new RuntimeException('crm_marketing_pool_view function boundaries are missing');
}
$viewSource = substr($php, $viewStart, $viewEnd - $viewStart);
if (!str_contains($viewSource, "\$groupInput['skip_count'] = 1;")
    || !str_contains($viewSource, "\$allInput['skip_count'] = 1;")) {
    throw new RuntimeException('both grouped and ungrouped customer pool paths must use bounded pagination');
}
if (str_contains($viewSource, 'crm_marketing_contacts(')) {
    throw new RuntimeException('customer pool endpoint must not preload the separate contact strategy list');
}

if (!str_contains($php, "if (\$view === 'customer_pool') \$poolInput['skip_count'] = 1;")) {
    throw new RuntimeException('initial customer pool bootstrap must use bounded pagination');
}
if (!str_contains($css, '.promo-pool-select-all')) {
    throw new RuntimeException('customer pool select-all control styles are missing');
}

echo "CRM marketing pool pagination contract: OK\n";
