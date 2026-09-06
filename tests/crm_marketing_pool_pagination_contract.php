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
if (!str_contains($loadPoolSource, 'skip_count: 1') || str_contains($loadPoolSource, 'exact_count: 1')) {
    throw new RuntimeException('ordinary customer pool refresh must retain the default lightweight count policy');
}

$poolStart = strpos($php, 'function crm_marketing_pool(array $input = [])');
$poolEnd = strpos($php, 'function crm_marketing_contacts', $poolStart === false ? 0 : $poolStart);
if ($poolStart === false || $poolEnd === false) {
    throw new RuntimeException('crm_marketing_pool function boundaries are missing');
}
$poolSource = substr($php, $poolStart, $poolEnd - $poolStart);
foreach ([
    '$page = max(1, (int)($input[\'page\'] ?? 1));',
    'if ($pageSize < 20) $pageSize = 20;',
    'if ($pageSize > 200) $pageSize = 200;',
    '$offset = ($page - 1) * $pageSize;',
    '$skipCount = !empty($input[\'skip_count\']);',
    'if (!$skipCount) {',
    'SELECT COUNT(*)',
    '$queryLimit = $pageSize;',
    'LIMIT {$queryLimit} OFFSET {$offset}',
    'if ($skipCount) {',
    '$nextOffset = $offset + $pageSize;',
    'LIMIT 1 OFFSET {$nextOffset}',
    '$hasMore = $moreStmt->fetchColumn() !== false;',
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
$viewEnd = strpos($php, 'function crm_marketing_audience_filter_input', $viewStart === false ? 0 : $viewStart);
if ($viewStart === false || $viewEnd === false) {
    throw new RuntimeException('crm_marketing_pool_view function boundaries are missing');
}
$viewSource = substr($php, $viewStart, $viewEnd - $viewStart);
function crm_pool_assert_count_policy(string $source): void
{
    // Exact totals are an explicit option used by the group-member/picker UI.
    // Both paths must remain lightweight when that option is absent or false.
    foreach (['groupInput', 'allInput'] as $inputName) {
        $assignment = '$' . $inputName . "['skip_count'] = empty(\$input['exact_count']) ? 1 : 0;";
        if (!str_contains($source, $assignment)
            || !str_contains($source, 'crm_marketing_pool($' . $inputName . ')')) {
            throw new RuntimeException('grouped and ungrouped paths must pass the explicit exact-count policy to bounded pagination');
        }
    }
}
crm_pool_assert_count_policy($viewSource);
// Ensure the revised assertion still rejects unconditionally expensive counts
// and the opposite regression (silently ignoring an explicit exact-count request).
foreach (['groupInput', 'allInput'] as $inputName) {
    $assignment = '$' . $inputName . "['skip_count'] = empty(\$input['exact_count']) ? 1 : 0;";
    foreach ([0, 1] as $forcedValue) {
        $broken = str_replace($assignment, '$' . $inputName . "['skip_count'] = " . $forcedValue . ';', $viewSource);
        $rejected = false;
        try { crm_pool_assert_count_policy($broken); }
        catch (RuntimeException $e) { $rejected = true; }
        if (!$rejected) throw new RuntimeException('pool count policy contract accepted a forced-count mutation');
    }
}
if (str_contains($viewSource, 'crm_marketing_contacts(')) {
    throw new RuntimeException('customer pool endpoint must not preload the separate contact strategy list');
}
foreach (['loadGroupMembers: function', 'openGroupCustomerPickerDialog: function'] as $method) {
    $start = strpos($js, $method);
    $end = $start === false ? false : strpos($js, "\n    },", $start);
    if ($start === false || $end === false) throw new RuntimeException('exact-count consumer boundaries are missing');
    $consumer = substr($js, $start, $end - $start);
    if (!str_contains($consumer, "post('marketing_pool_view'") || !str_contains($consumer, 'exact_count: 1')) {
        throw new RuntimeException('group-member and customer-picker requests must explicitly request their exact totals');
    }
}

if (!str_contains($php, "if (\$view === 'customer_pool') \$poolInput['skip_count'] = 1;")) {
    throw new RuntimeException('initial customer pool bootstrap must use bounded pagination');
}
if (!preg_match(
    "/else if \(view === 'customer_pool'\) \{.{0,500}safeRender\('groups', this\.renderGroups, '\[data-promo-groups\]'\);/s",
    $js
)) {
    throw new RuntimeException('initial customer pool render must replace the promotion group loading placeholder');
}
if (str_contains($php, 'data-promo-group-home hidden')) {
    throw new RuntimeException('customer pool promotion group panel must be visible on first render');
}
if (!str_contains($css, '.promo-pool-select-all')) {
    throw new RuntimeException('customer pool select-all control styles are missing');
}

echo "CRM marketing pool pagination contract: OK\n";
