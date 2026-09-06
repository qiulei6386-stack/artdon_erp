<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$php = file_get_contents($root . '/crm_marketing.php');
$js = file_get_contents($root . '/assets/crm/crm.js');
if ($php === false || $js === false) {
    throw new RuntimeException('CRM marketing wizard source files are not readable');
}

$requiredJs = [
    "return ['基础信息', '客户/分组', '联系人', '推广渠道', '内容编辑', '发送/执行规则', '时间计划', '失败处理', '预览确认'];",
    'refreshWizardAudience: function ()',
    "field === 'group_mode'",
    "field === 'group_key'",
    "post('marketing_target_preview'",
    'group_mode: groupMode',
    'group_key: draft.group_key',
    'group_keys: JSON.stringify(this.wizardGroupKeys(draft)),',
    'group_keys: this.wizardGroupKeys(draft)',
    'audience_customer_ids',
    'audience_customer_count',
    'resolveWizardAudienceCustomers',
    "if (step === 1)",
    "if (step === 6",
    "if (step === 7",
    'for (var validationStep = 0; validationStep < this.wizardSteps().length; validationStep++)',
    "targetStatus === 'scheduled'",
    'customer_ids: JSON.stringify(this.resolveWizardCustomerIds(draft))',
    'pool_filters: this.poolFilterPayload()',
    "['客户总数', audienceCount]",
    "['可执行客户', (plan.items || []).length]",
];
foreach ($requiredJs as $marker) {
    if (!str_contains($js, $marker)) {
        throw new RuntimeException("CRM marketing wizard 1-9 marker missing: {$marker}");
    }
}

$requiredPhp = [
    'function crm_marketing_resolve_audience_customers',
    "in_array(\$mode, ['selected', 'all_pool', 'group', 'country'], true)",
    "'group_keys' => \$audienceConfig['group_keys'] ?? []",
    "'audience_customer_ids' => array_values(\$customerIds)",
    'function crm_marketing_apply_audience_policy',
    "\$blacklistPolicy === 'block_task'",
    'crm_marketing_resolve_audience_customers($audienceInput, $requestedCustomerIds)',
    "\$requestedStatus === 'scheduled' || in_array(\$scheduleType, ['scheduled', 'auto'], true)",
    'crm_ensure_tables();',
    'if ($ownsTransaction) $pdo->beginTransaction();',
    'if ($ownsTransaction) $pdo->commit();',
    'if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();',
    'grouped_customers.group_id',
    'grouped_contacts.group_id',
    'promotable_contacts.group_id',
];
foreach ($requiredPhp as $marker) {
    if (!str_contains($php, $marker)) {
        throw new RuntimeException("CRM marketing wizard server marker missing: {$marker}");
    }
}

$audienceStart = strpos($php, 'function crm_marketing_resolve_audience_customers(');
$audienceEnd = strpos($php, 'function crm_marketing_apply_audience_policy(', $audienceStart === false ? 0 : $audienceStart);
if ($audienceStart === false || $audienceEnd === false || $audienceEnd <= $audienceStart) {
    throw new RuntimeException('wizard audience resolver boundaries are missing');
}
$audienceSource = substr($php, $audienceStart, $audienceEnd - $audienceStart);
function crm_wizard_assert_group_resolution(string $source): void
{
    // Preserve the old single-group contract while covering its multi-group
    // extension: validate all groups and union customer/contact membership.
    foreach ([
        "\$groupIds = crm_mail_input_ids(\$input['group_keys'] ?? []);",
        "if (!\$groupIds && \$groupKey !== '') \$groupIds = crm_mail_input_ids(\$groupKey);",
        'SELECT id FROM crm_marketing_groups WHERE group_name = ? AND deleted_at IS NULL LIMIT 1',
        '$groupIds = array_values(array_unique(array_filter(array_map(\'intval\', $groupIds))));',
        "if (!\$groupIds) return ['rows' => [], 'customer_ids' => [], 'total' => 0, 'mode' => \$mode];",
        'SELECT id FROM crm_marketing_groups WHERE id IN ({$placeholders}) AND deleted_at IS NULL',
        '$exists->execute($groupIds);',
        "if (count(\$foundGroupIds) !== count(\$groupIds)) throw new RuntimeException('所选推广分组不存在或已删除。');",
        'if (count($groupIds) === 1) {',
        "\$poolInput['group_id'] = \$groupIds[0];",
        'SELECT DISTINCT customer_id FROM (',
        'FROM crm_marketing_group_customers rg',
        'FROM crm_marketing_group_contacts rg',
        'JOIN crm_contacts ct ON ct.id = rg.contact_id AND ct.deleted_at IS NULL',
        'JOIN crm_customers c ON c.id = ct.customer_id AND c.deleted_at IS NULL',
        'WHERE rg.group_id IN ({$placeholders})',
        'UNION',
        '$memberStmt->execute(array_merge($groupIds, $groupIds));',
        "if (!\$customerIds) return ['rows' => [], 'customer_ids' => [], 'total' => 0, 'mode' => \$mode];",
        "\$poolInput['customer_ids'] = json_encode(\$customerIds);",
        '$pageSize = 200;',
        '$first = crm_marketing_pool(array_merge($poolInput, [',
        '$maxAudience = 5000;',
        'if ($total > $maxAudience) {',
        'for ($page = 2; $page <= $pageCount; $page++) {',
        '$next = crm_marketing_pool(array_merge($poolInput, [',
        "'page' => \$page,",
        "'skip_count' => 1,",
        'foreach (($next[\'rows\'] ?? []) as $row) $rows[] = $row;',
        'if ($id > 0) $byId[$id] = $row;',
        "'customer_ids' => array_map('intval', array_keys(\$byId))",
    ] as $marker) {
        if (!str_contains($source, $marker)) {
            throw new RuntimeException('wizard single/multi-group resolution or complete paged audience guard missing: ' . $marker);
        }
    }
    if (substr_count($source, 'WHERE rg.group_id IN ({$placeholders})') !== 2) {
        throw new RuntimeException('both customer and contact membership queries must be scoped to the selected groups');
    }
}
crm_wizard_assert_group_resolution($audienceSource);
foreach ([
    "\$poolInput['group_id'] = \$groupIds[0];",
    'FROM crm_marketing_group_contacts rg',
    'for ($page = 2; $page <= $pageCount; $page++) {',
] as $guard) {
    $broken = str_replace($guard, 'REMOVED_GROUP_RESOLUTION_GUARD', $audienceSource);
    $rejected = false;
    try { crm_wizard_assert_group_resolution($broken); }
    catch (RuntimeException $e) { $rejected = true; }
    if (!$rejected) throw new RuntimeException('wizard group contract accepted a missing scope/member/pagination guard');
}

$poolStart = strpos($php, 'function crm_marketing_pool(');
$poolEnd = strpos($php, 'function crm_marketing_contacts(', $poolStart === false ? 0 : $poolStart);
if ($poolStart === false || $poolEnd === false || $poolEnd <= $poolStart) {
    throw new RuntimeException('wizard audience pool boundaries are missing');
}
$poolSource = substr($php, $poolStart, $poolEnd - $poolStart);
foreach ([
    '$scope = crm_customer_scope_sql($params);',
    '$where = [\'c.deleted_at IS NULL\', $scope];',
    "\$groupId = (int)(\$input['group_id'] ?? 0);",
    'mgc.group_id = ?',
    'mgct.group_id = ?',
    "\$filterCustomerIds = crm_mail_input_ids(\$input['customer_ids'] ?? '');",
    "\$where[] = 'c.id IN ('",
] as $marker) {
    if (!str_contains($poolSource, $marker)) {
        throw new RuntimeException('wizard audience pool must enforce customer visibility and selected group/customer IDs: ' . $marker);
    }
}

if (str_contains($js, "if (!customerIds.length && !contactIds.length) return Promise.resolve();")) {
    throw new RuntimeException('wizard target preview must not skip group, country, or filtered-pool audiences');
}

echo "CRM marketing wizard 1-9 flow contract: OK\n";
