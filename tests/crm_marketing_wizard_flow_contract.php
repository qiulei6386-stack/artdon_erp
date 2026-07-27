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
    "['可执行客户', targets.customers.length]",
];
foreach ($requiredJs as $marker) {
    if (!str_contains($js, $marker)) {
        throw new RuntimeException("CRM marketing wizard 1-9 marker missing: {$marker}");
    }
}

$requiredPhp = [
    'function crm_marketing_resolve_audience_customers',
    "in_array(\$mode, ['selected', 'all_pool', 'group', 'country'], true)",
    "\$poolInput['group_id'] = \$groupId;",
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

if (str_contains($js, "if (!customerIds.length && !contactIds.length) return Promise.resolve();")) {
    throw new RuntimeException('wizard target preview must not skip group, country, or filtered-pool audiences');
}

echo "CRM marketing wizard 1-9 flow contract: OK\n";
